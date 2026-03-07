<?php
/**
 * Missed Submission Reminder Cron Job
 * 
 * Uses WordPress native cron with daily schedule.
 * Checks if today is a configured reminder day and runs the appropriate check.
 * 
 * Configuration is done via the Notification Settings page.
 * 
 * WP Control plugin compatible - uses daily cron with day checking in code.
 */

if (!defined('ABSPATH')) exit;

/* =========================================
 * CONFIGURATION DEFAULTS
 * ========================================= */

/**
 * Get reminder schedule configuration.
 * Default: Monday for reminder, Friday for encouragement, both at 18:00 (6 PM)
 */
function rcn_get_reminder_config() {
    $defaults = [
        'reminder_day'       => 1,     // 1 = Monday (0=Sun, 1=Mon, ..., 6=Sat)
        'reminder_hour'      => 18,    // 6 PM
        'encouragement_day'  => 5,     // 5 = Friday
        'encouragement_hour' => 18,    // 6 PM
        'enabled'            => true,  // Master switch
    ];
    
    $config = get_option('rcn_reminder_cron_config', []);
    return wp_parse_args($config, $defaults);
}

/**
 * Save reminder schedule configuration.
 */
function rcn_save_reminder_config($config) {
    update_option('rcn_reminder_cron_config', $config);
}

/* =========================================
 * WORDPRESS CRON SETUP
 * ========================================= */

/**
 * Schedule the daily cron event on plugin/theme load
 */
add_action('init', 'rcn_schedule_reminder_cron');
function rcn_schedule_reminder_cron() {
    if (!wp_next_scheduled('rcn_daily_reminder_check')) {
        // Schedule to run every hour (to catch the right time)
        wp_schedule_event(time(), 'hourly', 'rcn_daily_reminder_check');
    }
}

/**
 * Main cron handler - runs hourly, checks if it's time to send reminders
 */
add_action('rcn_daily_reminder_check', 'rcn_process_reminder_cron');
function rcn_process_reminder_cron() {
    $config = rcn_get_reminder_config();
    
    // Check if reminders are enabled
    if (empty($config['enabled'])) {
        return;
    }
    
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $current_day = (int) $now->format('w');  // 0 = Sunday, 1 = Monday, etc.
    $current_hour = (int) $now->format('G'); // 0-23
    
    // Check if we already ran today (prevent duplicate runs)
    $last_run = get_option('rcn_reminder_last_run', []);
    $today = $now->format('Y-m-d');
    
    // Monday/Reminder check
    if ($current_day === (int) $config['reminder_day'] && 
        $current_hour >= (int) $config['reminder_hour']) {
        
        $run_key = 'reminder_' . $today;
        if (empty($last_run[$run_key])) {
            error_log('[RCN Cron] Running scheduled Monday reminder');
            rcn_run_monday_missed_check();
            $last_run[$run_key] = current_time('mysql');
            update_option('rcn_reminder_last_run', $last_run);
        }
    }
    
    // Friday/Encouragement check
    if ($current_day === (int) $config['encouragement_day'] && 
        $current_hour >= (int) $config['encouragement_hour']) {
        
        $run_key = 'encouragement_' . $today;
        if (empty($last_run[$run_key])) {
            error_log('[RCN Cron] Running scheduled Friday encouragement');
            rcn_run_friday_encouragement_check();
            $last_run[$run_key] = current_time('mysql');
            update_option('rcn_reminder_last_run', $last_run);
        }
    }
    
    // Clean up old run records (keep last 14 days)
    $cutoff = (new DateTime('now', $tz))->modify('-14 days')->format('Y-m-d');
    foreach ($last_run as $key => $timestamp) {
        $date_part = substr($key, strrpos($key, '_') + 1);
        if ($date_part < $cutoff) {
            unset($last_run[$key]);
        }
    }
    update_option('rcn_reminder_last_run', $last_run);
}

/* =========================================
 * MONDAY CHECK: MISSED SUBMISSION REMINDER
 * ========================================= */

function rcn_run_monday_missed_check() {
    global $wpdb;
    
    $log = ['type' => 'monday_reminder', 'started_at' => current_time('mysql')];
    error_log('[RCN Cron] Running Monday missed submission check');
    
    // Get the previous Sunday (the week_start we're checking for)
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    
    // Go back to the most recent Sunday
    $days_since_sunday = (int) $now->format('w'); // 0 = Sunday, 1 = Monday, etc.
    if ($days_since_sunday === 0) {
        $days_since_sunday = 7; // If today is Sunday, check last Sunday
    }
    $sunday = clone $now;
    $sunday->modify("-{$days_since_sunday} days");
    $week_start = $sunday->format('Y-m-d');
    
    $log['week_start'] = $week_start;
    error_log("[RCN Cron] Checking for submissions for week_start: {$week_start}");
    
    // Get active disciples who might be missing submissions
    $disciples = rcn_get_disciples_missing_submission($week_start);
    $log['disciples_missing'] = count($disciples);
    
    $sent_count = 0;
    $disciple_names = [];
    
    foreach ($disciples as $disciple) {
        // Send reminder using existing template from Trigger Notifications.php
        if (function_exists('rcn_send_notification_from_template')) {
            rcn_send_notification_from_template($disciple->user_id, 'missed_weeks', []);
            $sent_count++;
            $disciple_names[] = $disciple->display_name;
            error_log("[RCN Cron] Sent Monday reminder to user ID: {$disciple->user_id}");
        }
    }
    
    $log['notifications_sent'] = $sent_count;
    $log['disciples_notified'] = $disciple_names;
    
    error_log("[RCN Cron] Monday check complete. Sent {$sent_count} reminders.");
    
    $log['completed_at'] = current_time('mysql');
    
    return $log;
}

/* =========================================
 * FRIDAY CHECK: ENCOURAGEMENT
 * ========================================= */

function rcn_run_friday_encouragement_check() {
    global $wpdb;
    
    $log = ['type' => 'friday_encouragement', 'started_at' => current_time('mysql')];
    error_log('[RCN Cron] Running Friday encouragement check');
    
    // Get the Sunday of this week (the week_start we're checking for)
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    
    // Go back to the most recent Sunday (start of current week)
    $days_since_sunday = (int) $now->format('w'); // Friday = 5
    $sunday = clone $now;
    $sunday->modify("-{$days_since_sunday} days");
    $week_start = $sunday->format('Y-m-d');
    
    $log['week_start'] = $week_start;
    error_log("[RCN Cron] Checking for submissions for week_start: {$week_start}");
    
    // Get active disciples who are STILL missing submissions
    $disciples = rcn_get_disciples_missing_submission($week_start);
    $log['disciples_missing'] = count($disciples);
    
    // Get templates from Trigger Notifications.php
    if (!function_exists('rcn_get_notification_templates')) {
        $log['error'] = 'rcn_get_notification_templates not found';
        return $log;
    }
    
    $templates = rcn_get_notification_templates();
    
    $sent_count = 0;
    $disciple_names = [];
    
    foreach ($disciples as $disciple) {
        // Pick random Friday encouragement template
        $template_key = rcn_get_random_friday_encouragement();
        
        if (!isset($templates[$template_key])) {
            error_log("[RCN Cron] Template not found: {$template_key}");
            continue;
        }
        
        $tpl = $templates[$template_key];
        $user = get_user_by('ID', $disciple->user_id);
        
        if ($user) {
            $vars = ['name' => $user->display_name];
            
            // Render subject and body
            if (function_exists('rcn_render_template')) {
                $subject = rcn_render_template($tpl['subject'], $vars);
                $body = rcn_render_template($tpl['body'], $vars);
            } else {
                $subject = str_replace('{name}', $user->display_name, $tpl['subject']);
                $body = str_replace('{name}', $user->display_name, $tpl['body']);
            }
            
            // Log in-app notification
            if (function_exists('rcn_log_notification')) {
                rcn_log_notification($disciple->user_id, $tpl['type'], $body);
            }
            
            // Send email
            if (function_exists('rcn_send_email_notification')) {
                rcn_send_email_notification($disciple->user_id, $subject, $body);
            }
            
            $sent_count++;
            $disciple_names[] = $user->display_name;
            error_log("[RCN Cron] Sent Friday encouragement ({$template_key}) to user ID: {$disciple->user_id}");
        }
    }
    
    $log['notifications_sent'] = $sent_count;
    $log['disciples_notified'] = $disciple_names;
    
    error_log("[RCN Cron] Friday check complete. Sent {$sent_count} encouragements.");
    
    $log['completed_at'] = current_time('mysql');
    
    return $log;
}

/**
 * Get random Friday encouragement template key
 */
function rcn_get_random_friday_encouragement() {
    $options = [
        'friday_encouragement_1',
        'friday_encouragement_2',
        'friday_encouragement_3',
        'friday_encouragement_4',
    ];
    return $options[array_rand($options)];
}

/* =========================================
 * HELPER: GET DISCIPLES MISSING SUBMISSION
 * ========================================= */

function rcn_get_disciples_missing_submission($week_start) {
    global $wpdb;
    
    $participants_table = $wpdb->prefix . 'discipleship_participants';
    $commitments_table  = $wpdb->prefix . 'discipleship_commitments';
    $users_table        = $wpdb->users;
    
    // Get active disciples who:
    // 1. Are status = 'active'
    // 2. Were registered at least 7 days ago
    // 3. Do NOT have any commitments for this week_start
    
    $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
    
    $sql = $wpdb->prepare("
        SELECT p.user_id, p.program_id, p.current_level_id, u.user_email, u.display_name
        FROM $participants_table p
        INNER JOIN $users_table u ON p.user_id = u.ID
        WHERE p.status = 'active'
          AND u.user_registered <= %s
          AND NOT EXISTS (
              SELECT 1 
              FROM $commitments_table c 
              WHERE c.participant_id = u.user_email
                AND c.program_id = p.program_id
                AND c.level_id = p.current_level_id
                AND c.week_start = %s
          )
        ORDER BY u.display_name
    ", $seven_days_ago, $week_start);
    
    return $wpdb->get_results($sql);
}

/* =========================================
 * MANUAL TRIGGER (for testing via AJAX)
 * ========================================= */

add_action('wp_ajax_rcn_test_monday_cron', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $result = rcn_run_monday_missed_check();
    wp_send_json_success($result);
});

add_action('wp_ajax_rcn_test_friday_cron', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $result = rcn_run_friday_encouragement_check();
    wp_send_json_success($result);
});

/* =========================================
 * ADMIN SETTINGS PAGE
 * ========================================= */

/**
 * Add admin menu page
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Missed Submission Reminders',
        'Submission Reminders',
        'manage_options',
        'rcn-submission-reminders',
        'rcn_submission_reminders_settings_page'
    );
});

/**
 * Handle form submissions
 */
add_action('admin_init', function() {
    if (!current_user_can('manage_options')) return;
    
    // Save settings
    if (isset($_POST['rcn_save_reminder_settings']) && 
        check_admin_referer('rcn_reminder_settings')) {
        
        $config = [
            'enabled'            => !empty($_POST['enabled']),
            'reminder_day'       => (int) $_POST['reminder_day'],
            'reminder_hour'      => (int) $_POST['reminder_hour'],
            'encouragement_day'  => (int) $_POST['encouragement_day'],
            'encouragement_hour' => (int) $_POST['encouragement_hour'],
        ];
        
        rcn_save_reminder_config($config);
        
        add_settings_error('rcn_reminder', 'settings_saved', 'Settings saved successfully.', 'success');
    }
    
    // Manual run - Monday reminder
    if (isset($_POST['rcn_run_monday_now']) && 
        check_admin_referer('rcn_reminder_settings')) {
        
        $result = rcn_run_monday_missed_check();
        
        $msg = sprintf(
            'Monday reminder executed. Found %d disciples missing submission, sent %d notifications.',
            $result['disciples_missing'] ?? 0,
            $result['notifications_sent'] ?? 0
        );
        
        add_settings_error('rcn_reminder', 'monday_ran', $msg, 'success');
    }
    
    // Manual run - Friday encouragement
    if (isset($_POST['rcn_run_friday_now']) && 
        check_admin_referer('rcn_reminder_settings')) {
        
        $result = rcn_run_friday_encouragement_check();
        
        $msg = sprintf(
            'Friday encouragement executed. Found %d disciples missing submission, sent %d notifications.',
            $result['disciples_missing'] ?? 0,
            $result['notifications_sent'] ?? 0
        );
        
        add_settings_error('rcn_reminder', 'friday_ran', $msg, 'success');
    }
    
    // Reset last run
    if (isset($_POST['rcn_reset_reminder_last_run']) && 
        check_admin_referer('rcn_reminder_settings')) {
        
        delete_option('rcn_reminder_last_run');
        add_settings_error('rcn_reminder', 'reset', 'Last run timestamps cleared. Crons can run again today.', 'success');
    }
});

/**
 * Render the settings page
 */
function rcn_submission_reminders_settings_page() {
    $config = rcn_get_reminder_config();
    $last_run = get_option('rcn_reminder_last_run', []);
    
    $days = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];
    
    // Calculate current week info
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $days_since_sunday = (int) $now->format('w');
    $sunday = (clone $now)->modify("-{$days_since_sunday} days");
    $current_week_start = $sunday->format('Y-m-d');
    
    // Preview: Get disciples missing submission for current week
    $disciples_missing = rcn_get_disciples_missing_submission($current_week_start);
    $preview_count = count($disciples_missing);
    
    // Next scheduled run
    $next_run = wp_next_scheduled('rcn_daily_reminder_check');
    
    // Last run info
    $last_reminder = null;
    $last_encouragement = null;
    foreach ($last_run as $key => $timestamp) {
        if (strpos($key, 'reminder_') === 0) {
            $last_reminder = $timestamp;
        }
        if (strpos($key, 'encouragement_') === 0) {
            $last_encouragement = $timestamp;
        }
    }
    
    settings_errors('rcn_reminder');
    ?>
    <div class="wrap">
        <h1>📬 Missed Submission Reminder Settings</h1>
        
        <p>This cron sends <strong>reminder notifications</strong> to disciples who haven't submitted their weekly report. It runs twice per week:</p>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><strong>First reminder</strong> — Early in the week (default: Monday) to remind them to submit</li>
            <li><strong>Encouragement</strong> — Later in the week (default: Friday) with a gentle nudge if still missing</li>
        </ul>
        
        <form method="post">
            <?php wp_nonce_field('rcn_reminder_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Enable Reminders</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($config['enabled']); ?>>
                            Send reminder notifications to disciples
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row" colspan="2" style="padding-bottom: 0;">
                        <h3 style="margin: 0;">📅 First Reminder (e.g., Monday)</h3>
                    </th>
                </tr>
                <tr>
                    <th scope="row">Day</th>
                    <td>
                        <select name="reminder_day">
                            <?php foreach ($days as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php selected($config['reminder_day'], $num); ?>>
                                    <?php echo $name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Hour</th>
                    <td>
                        <select name="reminder_hour">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <option value="<?php echo $h; ?>" <?php selected($config['reminder_hour'], $h); ?>>
                                    <?php echo sprintf('%02d:00', $h); ?> (<?php echo date('g A', strtotime("$h:00")); ?>)
                                </option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row" colspan="2" style="padding-bottom: 0;">
                        <h3 style="margin: 0;">💬 Encouragement (e.g., Friday)</h3>
                    </th>
                </tr>
                <tr>
                    <th scope="row">Day</th>
                    <td>
                        <select name="encouragement_day">
                            <?php foreach ($days as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php selected($config['encouragement_day'], $num); ?>>
                                    <?php echo $name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Hour</th>
                    <td>
                        <select name="encouragement_hour">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <option value="<?php echo $h; ?>" <?php selected($config['encouragement_hour'], $h); ?>>
                                    <?php echo sprintf('%02d:00', $h); ?> (<?php echo date('g A', strtotime("$h:00")); ?>)
                                </option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="rcn_save_reminder_settings" class="button-primary" value="Save Settings">
            </p>
        </form>
        
        <hr>
        
        <h2>📊 Status</h2>
        
        <table class="widefat" style="max-width: 700px;">
            <tr>
                <th style="width: 200px;">Next Scheduled Check</th>
                <td><?php echo $next_run ? date('Y-m-d H:i:s', $next_run) : '<em>Not scheduled</em>'; ?></td>
            </tr>
            <tr>
                <th>Last Reminder Sent</th>
                <td><?php echo $last_reminder ? esc_html($last_reminder) : '<em>Never</em>'; ?></td>
            </tr>
            <tr>
                <th>Last Encouragement Sent</th>
                <td><?php echo $last_encouragement ? esc_html($last_encouragement) : '<em>Never</em>'; ?></td>
            </tr>
            <tr>
                <th>Current Week Start</th>
                <td><?php echo esc_html($current_week_start); ?></td>
            </tr>
            <tr>
                <th>Disciples Missing Submission</th>
                <td><strong><?php echo $preview_count; ?></strong> disciple(s) haven't submitted for this week</td>
            </tr>
        </table>
        
        <?php if (!empty($disciples_missing)): ?>
        <h3 style="margin-top: 20px;">📋 Preview: Disciples Missing Submission</h3>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th>Disciple</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($disciples_missing, 0, 15) as $d): ?>
                <tr>
                    <td><?php echo esc_html($d->display_name); ?></td>
                    <td><?php echo esc_html($d->user_email); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($preview_count > 15): ?>
                <tr>
                    <td colspan="2"><em>... and <?php echo $preview_count - 15; ?> more disciples</em></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <hr>
        
        <h2>🔧 Manual Actions</h2>
        
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <form method="post">
                <?php wp_nonce_field('rcn_reminder_settings'); ?>
                <input type="submit" name="rcn_run_monday_now" class="button button-secondary" 
                       value="▶️ Run Monday Reminder" 
                       onclick="return confirm('This will send reminder notifications to <?php echo $preview_count; ?> disciple(s). Continue?');">
                <p class="description">Send the "missed submission" reminder now.</p>
            </form>
            
            <form method="post">
                <?php wp_nonce_field('rcn_reminder_settings'); ?>
                <input type="submit" name="rcn_run_friday_now" class="button button-secondary" 
                       value="▶️ Run Friday Encouragement" 
                       onclick="return confirm('This will send encouragement notifications to <?php echo $preview_count; ?> disciple(s). Continue?');">
                <p class="description">Send a random encouragement message now.</p>
            </form>
            
            <form method="post">
                <?php wp_nonce_field('rcn_reminder_settings'); ?>
                <input type="submit" name="rcn_reset_reminder_last_run" class="button" value="🔄 Reset Last Run">
                <p class="description">Clear timestamps to allow re-running today.</p>
            </form>
        </div>
        
        <hr>
        
        <h2>📖 How It Works</h2>
        
        <ol>
            <li>The cron runs <strong>hourly</strong> and checks if it's the right day/hour.</li>
            <li>On the <strong>first reminder day</strong> (e.g., Monday), it:
                <ul>
                    <li>Finds disciples who haven't submitted for the previous week</li>
                    <li>Sends them the <code>missed_weeks</code> notification template</li>
                </ul>
            </li>
            <li>On the <strong>encouragement day</strong> (e.g., Friday), it:
                <ul>
                    <li>Finds disciples who <em>still</em> haven't submitted for the current week</li>
                    <li>Sends a randomly selected encouragement message</li>
                </ul>
            </li>
            <li>Each notification is logged in-app and sent via email.</li>
        </ol>
        
        <h3>Notification Templates Used</h3>
        <table class="widefat" style="max-width: 600px;">
            <tr>
                <th>Monday Reminder</th>
                <td><code>missed_weeks</code></td>
            </tr>
            <tr>
                <th>Friday Encouragement</th>
                <td><code>friday_encouragement_1</code> through <code>friday_encouragement_4</code> (random)</td>
            </tr>
        </table>
        <p><small>Edit these templates in <code>Trigger Notifications.php</code></small></p>
    </div>
    <?php
}

/* =========================================
 * CLEANUP ON DEACTIVATION
 * ========================================= */

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('rcn_daily_reminder_check');
});
