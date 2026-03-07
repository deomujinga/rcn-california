<?php
/**
 * Weekly Zero Attainment Cron Job
 * 
 * Runs weekly to create 0% attainment records for disciples who missed submissions.
 * This keeps cumulative attainment accurate over time.
 * 
 * WP Crontrol compatible - schedules a weekly event.
 */

if (!defined('ABSPATH')) exit;

/* =========================================
 * CONFIGURATION
 * ========================================= */

/**
 * Get zero attainment cron configuration.
 */
function rcn_get_zero_attainment_config() {
    $defaults = [
        'enabled'     => true,
        'run_day'     => 1,     // 1 = Monday (0=Sun, 1=Mon, ..., 6=Sat)
        'run_hour'    => 6,     // 6 AM - run early Monday to score the previous week
    ];
    
    $config = get_option('rcn_zero_attainment_config', []);
    return wp_parse_args($config, $defaults);
}

/* =========================================
 * WORDPRESS CRON SETUP
 * ========================================= */

/**
 * Schedule the weekly cron event
 */
add_action('init', 'rcn_schedule_zero_attainment_cron');
function rcn_schedule_zero_attainment_cron() {
    if (!wp_next_scheduled('rcn_weekly_zero_attainment_check')) {
        // Schedule to run every hour (checks if it's the right day/time)
        wp_schedule_event(time(), 'hourly', 'rcn_weekly_zero_attainment_check');
    }
}

/**
 * Main cron handler - runs hourly, checks if it's time to process
 */
add_action('rcn_weekly_zero_attainment_check', 'rcn_process_zero_attainment_cron');
function rcn_process_zero_attainment_cron() {
    $config = rcn_get_zero_attainment_config();
    
    if (empty($config['enabled'])) {
        return;
    }
    
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $current_day = (int) $now->format('w');
    $current_hour = (int) $now->format('G');
    
    // Check if we already ran this week
    $last_run = get_option('rcn_zero_attainment_last_run', '');
    $this_week = $now->format('Y-W'); // Year-Week format
    
    // Only run on configured day and hour
    if ($current_day !== (int) $config['run_day'] || 
        $current_hour < (int) $config['run_hour']) {
        return;
    }
    
    // Don't run twice in the same week
    if ($last_run === $this_week) {
        return;
    }
    
    error_log('[RCN Zero Attainment] Running weekly zero attainment check');
    
    $result = rcn_run_zero_attainment_for_missed_weeks();
    
    update_option('rcn_zero_attainment_last_run', $this_week);
    
    error_log('[RCN Zero Attainment] Completed. Processed: ' . ($result['processed'] ?? 0));
}

/* =========================================
 * MAIN LOGIC: SCORE ZEROS FOR MISSED WEEKS
 * ========================================= */

/**
 * Find all active disciples who have missed weeks and create 0% records.
 * This backfills ALL missed weeks between last submission and the latest complete week.
 * A week is "complete" (eligible for 0%) if at least 7 days have passed since that Sunday.
 */
function rcn_run_zero_attainment_for_missed_weeks() {
    global $wpdb;
    
    $log = [
        'started_at'    => current_time('mysql'),
        'processed'     => 0,
        'zeros_created' => 0,
        'disciples'     => [],
        'weeks_filled'  => [],
    ];
    
    $participants_table = $wpdb->prefix . 'discipleship_participants';
    $commitments_table  = $wpdb->prefix . 'discipleship_commitments';
    $summary_table      = $wpdb->prefix . 'discipleship_attainment_summary';
    $pauses_table       = $wpdb->prefix . 'discipleship_pauses';
    
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    
    // Calculate the latest "complete" week (at least 7 days since that Sunday)
    // If today is Sunday, the latest complete week is 2 Sundays ago
    // If today is Monday-Saturday, the latest complete week is last Sunday
    $days_since_sunday = (int) $now->format('w'); // 0=Sun, 1=Mon, etc.
    
    if ($days_since_sunday === 0) {
        // Today is Sunday - last complete week ended yesterday (last Sunday was 7 days ago)
        $latest_complete_sunday = (clone $now)->modify('-7 days');
    } else {
        // Today is Mon-Sat - last Sunday is within 7 days, so we go back to the Sunday before
        $latest_complete_sunday = (clone $now)->modify("-{$days_since_sunday} days");
        
        // Check if 7 days have passed since this Sunday
        $days_elapsed = $days_since_sunday;
        if ($days_elapsed < 7) {
            // This week isn't complete yet, go back one more week
            $latest_complete_sunday->modify('-7 days');
        }
    }
    
    // Actually, simpler logic: a week starting on Sunday X is complete if today >= Sunday X + 7 days
    // So the latest complete week_start is: most recent Sunday that is at least 7 days ago
    $seven_days_ago = (clone $now)->modify('-7 days');
    $days_since_sunday_7ago = (int) $seven_days_ago->format('w');
    $latest_complete_sunday = (clone $seven_days_ago)->modify("-{$days_since_sunday_7ago} days");
    
    $latest_complete_week = $latest_complete_sunday->format('Y-m-d');
    
    $log['latest_complete_week'] = $latest_complete_week;
    error_log("[RCN Zero Attainment] Latest complete week: {$latest_complete_week}");
    
    // Get all active disciples
    $active_disciples = $wpdb->get_results("
        SELECT p.user_id, p.program_id, p.current_level_id, p.level_start_date, 
               u.user_email, u.display_name
        FROM {$participants_table} p
        INNER JOIN {$wpdb->users} u ON p.user_id = u.ID
        WHERE p.status = 'active'
        ORDER BY u.display_name
    ");
    
    $log['total_active'] = count($active_disciples);
    
    foreach ($active_disciples as $disciple) {
        $user_id    = (int) $disciple->user_id;
        $program_id = (int) $disciple->program_id;
        $level_id   = (int) $disciple->current_level_id;
        $email      = strtolower(trim($disciple->user_email));
        
        // Determine starting point: level_start_date normalized to Sunday
        $level_start = $disciple->level_start_date;
        $level_start_dt = new DateTime($level_start, $tz);
        $level_start_dow = (int) $level_start_dt->format('w');
        $level_start_sunday = (clone $level_start_dt)->modify("-{$level_start_dow} days")->format('Y-m-d');
        
        // Check ALL weeks from level start to latest complete week
        // This ensures we catch gaps BETWEEN submissions, not just at the end
        $start_checking_from = $level_start_sunday;
        
        // Generate all weeks from level start to latest_complete_week
        $weeks_to_check = rcn_generate_week_sundays($start_checking_from, $latest_complete_week, $tz);
        
        if (empty($weeks_to_check)) {
            continue; // No weeks to process
        }
        
        $disciple_zeros = 0;
        $disciple_missed_weeks = [];
        
        foreach ($weeks_to_check as $week_start) {
            // Check if paused during this week
            $is_paused = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$pauses_table}
                WHERE participant_id = %d
                  AND paused_at <= %s
                  AND (resumed_at IS NULL OR resumed_at >= %s)
            ", $user_id, $week_start, $week_start));
            
            if ($is_paused) {
                continue; // Skip paused weeks
            }
            
            // Check if they have a submission for this week
            $has_submission = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$commitments_table}
                WHERE participant_id = %s
                  AND program_id = %d
                  AND level_id = %d
                  AND week_start = %s
            ", $email, $program_id, $level_id, $week_start));
            
            if ($has_submission > 0) {
                continue; // They submitted
            }
            
            // Check if summary already exists
            $has_summary = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$summary_table}
                WHERE participant_id = %d
                  AND program_id = %d
                  AND level_id = %d
                  AND week_start = %s
            ", $user_id, $program_id, $level_id, $week_start));
            
            if ($has_summary > 0) {
                continue; // Already has a record
            }
            
            // === CREATE ZERO COMMITMENT RECORDS ===
            // Insert 0-value records into commitments table for all practices
            $practices_to_insert = [
                ['practice' => 'Bible Reading',                'unit_type' => 'daily',  'days' => 7],
                ['practice' => 'Morning Intimacy',             'unit_type' => 'daily',  'days' => 7],
                ['practice' => 'Fasting',                      'unit_type' => 'weekly', 'days' => 1],
                ['practice' => 'Scripture Memorization',       'unit_type' => 'weekly', 'days' => 1],
                ['practice' => 'Bible Study & Meditation',     'unit_type' => 'weekly', 'days' => 1],
                ['practice' => 'Midnight Intercessory Prayer', 'unit_type' => 'weekly', 'days' => 1],
                ['practice' => 'Corporate Prayers',            'unit_type' => 'weekly', 'days' => 1],
            ];
            
            $commitment_inserted = false;
            foreach ($practices_to_insert as $practice_info) {
                // For daily practices, insert one record per day with 0 value
                // For weekly practices, insert one record with 0 value
                if ($practice_info['unit_type'] === 'daily') {
                    // Insert 7 daily records (one per day of the week)
                    for ($day = 0; $day < 7; $day++) {
                        $date = (new DateTime($week_start, $tz))->modify("+{$day} days")->format('Y-m-d');
                        $wpdb->insert(
                            $commitments_table,
                            [
                                'participant_id' => $email,
                                'program_id'     => $program_id,
                                'level_id'       => $level_id,
                                'week_start'     => $week_start,
                                'date'           => $date,
                                'practice'       => $practice_info['practice'],
                                'unit_type'      => $practice_info['unit_type'],
                                'value'          => 0.00,
                            ],
                            ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%f']
                        );
                    }
                } else {
                    // Weekly practice - one record
                    $wpdb->insert(
                        $commitments_table,
                        [
                            'participant_id' => $email,
                            'program_id'     => $program_id,
                            'level_id'       => $level_id,
                            'week_start'     => $week_start,
                            'date'           => $week_start,
                            'practice'       => $practice_info['practice'],
                            'unit_type'      => $practice_info['unit_type'],
                            'value'          => 0.00,
                        ],
                        ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%f']
                    );
                }
                $commitment_inserted = true;
            }
            
            // === CREATE ZERO ATTAINMENT SUMMARY RECORD ===
            $inserted = $wpdb->insert(
                $summary_table,
                [
                    'participant_id'         => $user_id,
                    'program_id'             => $program_id,
                    'level_id'               => $level_id,
                    'week_start'             => $week_start,
                    'overall_attainment'     => 0.00,
                    'br_attainment'          => 0.00,
                    'fasting_attainment'     => 0.00,
                    'memorization_attainment'=> 0.00,
                    'bible_study_attainment' => 0.00,
                    'mp_attainment'          => 0.00,
                    'cp_attainment'          => 0.00,
                    'mi_attainment'          => 0.00,
                    'strongest_practice'     => '',
                    'weakest_practice'       => '',
                    'computed_at'            => current_time('mysql'),
                ],
                ['%d','%d','%d','%s','%f','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s']
            );
            
            if ($inserted) {
                $log['zeros_created']++;
                $disciple_zeros++;
                $disciple_missed_weeks[] = $week_start;
                
                if (!isset($log['weeks_filled'][$week_start])) {
                    $log['weeks_filled'][$week_start] = 0;
                }
                $log['weeks_filled'][$week_start]++;
                
                error_log("[RCN Zero Attainment] Created 0% for {$disciple->display_name} (week: {$week_start}) - commitments and summary");
            }
        }
        
        // Recalculate cumulative attainment and notify disciple if we added any zeros
        if ($disciple_zeros > 0) {
            $new_attainment = rcn_recalculate_cumulative_attainment($user_id, $program_id, $level_id);
            $log['disciples'][] = "{$disciple->display_name} ({$disciple_zeros} weeks)";
            
            // Notify the disciple about their missed weeks
            if (function_exists('rcn_trigger_zero_attainment_notification')) {
                rcn_trigger_zero_attainment_notification($user_id, $disciple_missed_weeks, $new_attainment);
            }
        }
        
        $log['processed']++;
    }
    
    $log['completed_at'] = current_time('mysql');
    
    return $log;
}

/**
 * Generate array of Sunday dates between two dates (inclusive)
 */
function rcn_generate_week_sundays($start_date, $end_date, $tz) {
    $weeks = [];
    
    $start = new DateTime($start_date, $tz);
    $end = new DateTime($end_date, $tz);
    
    // Normalize start to Sunday
    $start_dow = (int) $start->format('w');
    if ($start_dow !== 0) {
        $start->modify("-{$start_dow} days");
    }
    
    // If start is after end, return empty
    if ($start > $end) {
        return $weeks;
    }
    
    $current = clone $start;
    while ($current <= $end) {
        $weeks[] = $current->format('Y-m-d');
        $current->modify('+7 days');
    }
    
    return $weeks;
}

/**
 * Recalculate cumulative attainment for a participant
 */
function rcn_recalculate_cumulative_attainment($user_id, $program_id, $level_id) {
    global $wpdb;
    
    $summary_table      = $wpdb->prefix . 'discipleship_attainment_summary';
    $participants_table = $wpdb->prefix . 'discipleship_participants';
    
    // Calculate average of all weekly attainments for this level
    $cumulative = $wpdb->get_var($wpdb->prepare("
        SELECT AVG(overall_attainment)
        FROM {$summary_table}
        WHERE participant_id = %d
          AND program_id = %d
          AND level_id = %d
    ", $user_id, $program_id, $level_id));
    
    $cumulative = $cumulative ? round($cumulative, 2) : 0;
    
    // Update participant record
    $wpdb->update(
        $participants_table,
        [
            'attainment'           => $cumulative,
            'last_attainment_calc' => current_time('mysql'),
            'updated_at'           => current_time('mysql'),
        ],
        ['user_id' => $user_id],
        ['%f', '%s', '%s'],
        ['%d']
    );
    
    error_log("[RCN Zero Attainment] Updated cumulative attainment for user {$user_id}: {$cumulative}%");
    
    return $cumulative;
}


/* =========================================
 * MANUAL TRIGGER (for testing via AJAX)
 * ========================================= */

add_action('wp_ajax_rcn_test_zero_attainment', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $result = rcn_run_zero_attainment_for_missed_weeks();
    wp_send_json_success($result);
});

/**
 * AJAX: Preview what would be processed (dry run)
 * Now shows backfill data - all missed weeks per disciple
 */
add_action('wp_ajax_rcn_preview_zero_attainment', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $preview = rcn_preview_zero_attainment_backfill();
    wp_send_json_success($preview);
});

/**
 * Preview function - calculates what would be backfilled without making changes
 */
function rcn_preview_zero_attainment_backfill() {
    global $wpdb;
    
    $participants_table = $wpdb->prefix . 'discipleship_participants';
    $commitments_table  = $wpdb->prefix . 'discipleship_commitments';
    $summary_table      = $wpdb->prefix . 'discipleship_attainment_summary';
    $pauses_table       = $wpdb->prefix . 'discipleship_pauses';
    
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    
    // Calculate latest complete week (7+ days since that Sunday)
    $seven_days_ago = (clone $now)->modify('-7 days');
    $days_since_sunday_7ago = (int) $seven_days_ago->format('w');
    $latest_complete_sunday = (clone $seven_days_ago)->modify("-{$days_since_sunday_7ago} days");
    $latest_complete_week = $latest_complete_sunday->format('Y-m-d');
    
    $preview = [
        'latest_complete_week' => $latest_complete_week,
        'total_zeros' => 0,
        'disciples' => [],
    ];
    
    // Get all active disciples
    $active_disciples = $wpdb->get_results("
        SELECT p.user_id, p.program_id, p.current_level_id, p.level_start_date, p.attainment,
               u.user_email, u.display_name
        FROM {$participants_table} p
        INNER JOIN {$wpdb->users} u ON p.user_id = u.ID
        WHERE p.status = 'active'
        ORDER BY u.display_name
    ");
    
    foreach ($active_disciples as $disciple) {
        $user_id    = (int) $disciple->user_id;
        $program_id = (int) $disciple->program_id;
        $level_id   = (int) $disciple->current_level_id;
        $email      = strtolower(trim($disciple->user_email));
        
        // Determine start point: level_start_date normalized to Sunday
        $level_start_dt = new DateTime($disciple->level_start_date, $tz);
        $level_start_dow = (int) $level_start_dt->format('w');
        $level_start_sunday = (clone $level_start_dt)->modify("-{$level_start_dow} days")->format('Y-m-d');
        
        // Check ALL weeks from level start to latest complete week
        // This ensures we catch gaps BETWEEN submissions
        $start_from = $level_start_sunday;
        
        // Generate weeks to check
        $weeks_to_check = rcn_generate_week_sundays($start_from, $latest_complete_week, $tz);
        
        // Find last submission for display purposes
        $last_submission = $wpdb->get_var($wpdb->prepare("
            SELECT MAX(week_start) FROM {$commitments_table}
            WHERE participant_id = %s AND program_id = %d AND level_id = %d
        ", $email, $program_id, $level_id));
        $last_record = $last_submission;
        
        $missing_weeks = [];
        
        foreach ($weeks_to_check as $week_start) {
            // Check pause
            $is_paused = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$pauses_table}
                WHERE participant_id = %d AND paused_at <= %s
                  AND (resumed_at IS NULL OR resumed_at >= %s)
            ", $user_id, $week_start, $week_start));
            
            if ($is_paused) continue;
            
            // Check submission
            $has_submission = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$commitments_table}
                WHERE participant_id = %s AND program_id = %d AND level_id = %d AND week_start = %s
            ", $email, $program_id, $level_id, $week_start));
            
            if ($has_submission > 0) continue;
            
            // Check summary
            $has_summary = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$summary_table}
                WHERE participant_id = %d AND program_id = %d AND level_id = %d AND week_start = %s
            ", $user_id, $program_id, $level_id, $week_start));
            
            if ($has_summary > 0) continue;
            
            $missing_weeks[] = $week_start;
        }
        
        if (!empty($missing_weeks)) {
            $preview['disciples'][] = [
                'name' => $disciple->display_name,
                'email' => $disciple->user_email,
                'current_attainment' => $disciple->attainment,
                'last_record' => $last_record ?: 'Never',
                'missing_weeks' => $missing_weeks,
                'weeks_count' => count($missing_weeks),
            ];
            $preview['total_zeros'] += count($missing_weeks);
        }
    }
    
    return $preview;
}

/* =========================================
 * ADMIN SETTINGS PAGE
 * ========================================= */

/**
 * Add admin menu page
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Zero Attainment Settings',
        'Zero Attainment',
        'manage_options',
        'rcn-zero-attainment',
        'rcn_zero_attainment_settings_page'
    );
});

/**
 * Handle form submissions
 */
add_action('admin_init', function() {
    if (!current_user_can('manage_options')) return;
    
    // Save settings
    if (isset($_POST['rcn_save_zero_attainment_settings']) && 
        check_admin_referer('rcn_zero_attainment_settings')) {
        
        $config = [
            'enabled'  => !empty($_POST['enabled']),
            'run_day'  => (int) $_POST['run_day'],
            'run_hour' => (int) $_POST['run_hour'],
        ];
        
        update_option('rcn_zero_attainment_config', $config);
        
        add_settings_error('rcn_zero_attainment', 'settings_saved', 'Settings saved successfully.', 'success');
    }
    
    // Manual run
    if (isset($_POST['rcn_run_zero_attainment_now']) && 
        check_admin_referer('rcn_zero_attainment_settings')) {
        
        $result = rcn_run_zero_attainment_for_missed_weeks();
        
        $msg = sprintf(
            'Cron executed. Processed %d disciples, created %d zero records for week %s.',
            $result['processed'],
            $result['zeros_created'],
            $result['week_start']
        );
        
        add_settings_error('rcn_zero_attainment', 'cron_ran', $msg, 'success');
    }
    
    // Reset last run (to allow re-running this week)
    if (isset($_POST['rcn_reset_last_run']) && 
        check_admin_referer('rcn_zero_attainment_settings')) {
        
        delete_option('rcn_zero_attainment_last_run');
        add_settings_error('rcn_zero_attainment', 'reset', 'Last run timestamp cleared. Cron can run again this week.', 'success');
    }
});

/**
 * Render the settings page
 */
function rcn_zero_attainment_settings_page() {
    $config = rcn_get_zero_attainment_config();
    $last_run = get_option('rcn_zero_attainment_last_run', '');
    
    $days = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];
    
    // Get preview data using the backfill preview function
    $preview = rcn_preview_zero_attainment_backfill();
    
    // Next scheduled run
    $next_run = wp_next_scheduled('rcn_weekly_zero_attainment_check');
    
    settings_errors('rcn_zero_attainment');
    ?>
    <div class="wrap">
        <h1>⚙️ Zero Attainment Cron Settings</h1>
        
        <p>This cron job automatically creates <strong>0% attainment</strong> records for disciples who miss their weekly submissions. It <strong>backfills all missed weeks</strong> between their last submission and the latest complete week.</p>
        
        <form method="post">
            <?php wp_nonce_field('rcn_zero_attainment_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Enable Cron</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($config['enabled']); ?>>
                            Automatically score 0% for missed weeks
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Run Day</th>
                    <td>
                        <select name="run_day">
                            <?php foreach ($days as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php selected($config['run_day'], $num); ?>>
                                    <?php echo $name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">The day of the week to run the check.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Run Hour</th>
                    <td>
                        <select name="run_hour">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <option value="<?php echo $h; ?>" <?php selected($config['run_hour'], $h); ?>>
                                    <?php echo sprintf('%02d:00', $h); ?> (<?php echo date('g A', strtotime("$h:00")); ?>)
                                </option>
                            <?php endfor; ?>
                        </select>
                        <p class="description">The hour (server time) when the cron should run.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="rcn_save_zero_attainment_settings" class="button-primary" value="Save Settings">
            </p>
        </form>
        
        <hr>
        
        <h2>📊 Status</h2>
        
        <table class="widefat" style="max-width: 700px;">
            <tr>
                <th style="width: 200px;">Last Run</th>
                <td><?php echo $last_run ? "Week {$last_run}" : '<em>Never</em>'; ?></td>
            </tr>
            <tr>
                <th>Next Scheduled Check</th>
                <td><?php echo $next_run ? date('Y-m-d H:i:s', $next_run) : '<em>Not scheduled</em>'; ?></td>
            </tr>
            <tr>
                <th>Latest Complete Week</th>
                <td><?php echo esc_html($preview['latest_complete_week']); ?> <em>(7+ days elapsed)</em></td>
            </tr>
            <tr>
                <th>Disciples with Missing Weeks</th>
                <td><strong><?php echo count($preview['disciples']); ?></strong> disciple(s)</td>
            </tr>
            <tr>
                <th>Total Zero Records to Create</th>
                <td><strong><?php echo (int)$preview['total_zeros']; ?></strong> week(s) across all disciples</td>
            </tr>
        </table>
        
        <?php if (!empty($preview['disciples'])): 
            $per_page = 15;
            $total_disciples = count($preview['disciples']);
            $total_pages = ceil($total_disciples / $per_page);
        ?>
        <h3 style="margin-top: 20px;">📋 Preview: Disciples with Missing Weeks</h3>
        
        <!-- Pagination Controls -->
        <div id="rcn-pagination-top" style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
            <button type="button" class="button" id="rcn-prev-btn" onclick="rcnChangePage(-1)" disabled>← Previous</button>
            <span>Page <strong id="rcn-current-page">1</strong> of <strong><?php echo $total_pages; ?></strong> 
                  (<span id="rcn-showing-count"><?php echo min($per_page, $total_disciples); ?></span> of <?php echo $total_disciples; ?> disciples)</span>
            <button type="button" class="button" id="rcn-next-btn" onclick="rcnChangePage(1)" <?php echo $total_pages <= 1 ? 'disabled' : ''; ?>>Next →</button>
        </div>
        
        <table class="widefat" style="max-width: 900px;" id="rcn-disciples-table">
            <thead>
                <tr>
                    <th>Disciple</th>
                    <th>Current Attainment</th>
                    <th>Last Record</th>
                    <th>Missing Weeks</th>
                </tr>
            </thead>
            <tbody id="rcn-disciples-tbody">
                <!-- Populated by JavaScript -->
            </tbody>
        </table>
        
        <!-- Pagination Controls Bottom -->
        <div id="rcn-pagination-bottom" style="margin-top: 10px; display: flex; align-items: center; gap: 10px;">
            <button type="button" class="button" id="rcn-prev-btn-2" onclick="rcnChangePage(-1)" disabled>← Previous</button>
            <span>Page <strong id="rcn-current-page-2">1</strong> of <strong><?php echo $total_pages; ?></strong></span>
            <button type="button" class="button" id="rcn-next-btn-2" onclick="rcnChangePage(1)" <?php echo $total_pages <= 1 ? 'disabled' : ''; ?>>Next →</button>
        </div>
        
        <script>
        (function() {
            const disciples = <?php echo json_encode($preview['disciples']); ?>;
            const perPage = <?php echo $per_page; ?>;
            const totalPages = <?php echo $total_pages; ?>;
            let currentPage = 1;
            
            function renderPage() {
                const tbody = document.getElementById('rcn-disciples-tbody');
                const start = (currentPage - 1) * perPage;
                const end = Math.min(start + perPage, disciples.length);
                const pageData = disciples.slice(start, end);
                
                let html = '';
                pageData.forEach(function(d) {
                    const attainment = d.current_attainment !== null ? parseFloat(d.current_attainment).toFixed(1) + '%' : '—';
                    const weeksDisplay = d.missing_weeks.slice(0, 5).join(', ') + (d.weeks_count > 5 ? '...' : '');
                    
                    html += '<tr>';
                    html += '<td><strong>' + escapeHtml(d.name) + '</strong><br><small>' + escapeHtml(d.email) + '</small></td>';
                    html += '<td>' + attainment + '</td>';
                    html += '<td>' + escapeHtml(d.last_record) + '</td>';
                    html += '<td><strong>' + d.weeks_count + ' week(s)</strong><br><small>' + escapeHtml(weeksDisplay) + '</small></td>';
                    html += '</tr>';
                });
                
                tbody.innerHTML = html;
                
                // Update page indicators
                document.getElementById('rcn-current-page').textContent = currentPage;
                document.getElementById('rcn-current-page-2').textContent = currentPage;
                document.getElementById('rcn-showing-count').textContent = end - start;
                
                // Update button states
                document.getElementById('rcn-prev-btn').disabled = currentPage <= 1;
                document.getElementById('rcn-prev-btn-2').disabled = currentPage <= 1;
                document.getElementById('rcn-next-btn').disabled = currentPage >= totalPages;
                document.getElementById('rcn-next-btn-2').disabled = currentPage >= totalPages;
            }
            
            function escapeHtml(text) {
                if (text === null || text === undefined) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            window.rcnChangePage = function(delta) {
                const newPage = currentPage + delta;
                if (newPage >= 1 && newPage <= totalPages) {
                    currentPage = newPage;
                    renderPage();
                }
            };
            
            // Initial render
            renderPage();
        })();
        </script>
        <?php endif; ?>
        
        <hr>
        
        <h2>🔧 Manual Actions</h2>
        
        <form method="post" style="display: inline-block; margin-right: 10px;">
            <?php wp_nonce_field('rcn_zero_attainment_settings'); ?>
            <input type="submit" name="rcn_run_zero_attainment_now" class="button button-secondary" 
                   value="▶️ Run Now" 
                   onclick="return confirm('This will create <?php echo (int)$preview['total_zeros']; ?> zero record(s) for <?php echo count($preview['disciples']); ?> disciple(s). Continue?');">
            <p class="description">Immediately process and backfill all missed weeks.</p>
        </form>
        
        <form method="post" style="display: inline-block;">
            <?php wp_nonce_field('rcn_zero_attainment_settings'); ?>
            <input type="submit" name="rcn_reset_last_run" class="button" value="🔄 Reset Last Run">
            <p class="description">Allows the cron to run again this week (useful for testing).</p>
        </form>
        
        <hr>
        
        <h2>📖 How It Works</h2>
        
        <ol>
            <li><strong>Every week</strong> on the configured day/hour, the cron checks all active disciples.</li>
            <li>For each disciple, it finds their <strong>last submission date</strong>.</li>
            <li>It <strong>backfills ALL weeks</strong> between the last submission and the latest complete week with 0% attainment.</li>
            <li>A week is "complete" when <strong>7 days have passed</strong> since that Sunday.</li>
            <li>Their <strong>cumulative attainment</strong> is recalculated (averaged across all weeks).</li>
            <li><strong>Skipped:</strong> Disciples who are paused, or weeks before they started.</li>
        </ol>
        
        <h3>Example: Backfill in Action</h3>
        <p>If today is <strong>March 6, 2026</strong> and a disciple last submitted for <strong>Feb 1, 2026</strong>:</p>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th>Week Start</th>
                    <th>Status</th>
                    <th>Attainment</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Feb 1, 2026</td><td>✓ Submitted</td><td>80%</td></tr>
                <tr style="background: #fff3cd;"><td>Feb 8, 2026</td><td>✗ Missed → Backfilled</td><td>0%</td></tr>
                <tr style="background: #fff3cd;"><td>Feb 15, 2026</td><td>✗ Missed → Backfilled</td><td>0%</td></tr>
                <tr style="background: #fff3cd;"><td>Feb 22, 2026</td><td>✗ Missed → Backfilled</td><td>0%</td></tr>
                <tr><td>Mar 1, 2026</td><td>⏳ Not complete yet</td><td>—</td></tr>
                <tr style="font-weight: bold; background: #f8f9fa;">
                    <td colspan="2">Cumulative Attainment</td>
                    <td>20% (was 80%)</td>
                </tr>
            </tbody>
        </table>
        <p><small>* Mar 1 week is not filled because only 5 days have passed (need 7+ days).</small></p>
    </div>
    <?php
}

/* =========================================
 * CLEANUP ON DEACTIVATION
 * ========================================= */

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('rcn_weekly_zero_attainment_check');
});
