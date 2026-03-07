<?php
/**
 * One-Time Migration: Backfill Zero Commitment Records
 * 
 * This script finds weeks where:
 * - A 0% attainment_summary record exists
 * - But NO commitment records exist
 * 
 * It then creates the missing 0-value commitment records.
 * 
 * Run this ONCE via the admin UI, then deactivate/delete this file.
 */

if (!defined('ABSPATH')) exit;

/* =========================================
 * ADMIN PAGE
 * ========================================= */

add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Backfill Zero Commitments',
        'Backfill Commitments',
        'manage_options',
        'rcn-backfill-commitments',
        'rcn_backfill_commitments_page'
    );
});

/**
 * Handle form submissions
 */
add_action('admin_init', function() {
    if (!current_user_can('manage_options')) return;
    
    // Run migration
    if (isset($_POST['rcn_run_backfill']) && 
        check_admin_referer('rcn_backfill_commitments')) {
        
        $result = rcn_run_backfill_zero_commitments(false); // dry_run = false
        
        $msg = sprintf(
            'Migration complete! Processed %d summary records, created %d commitment records.',
            $result['summaries_processed'],
            $result['commitments_created']
        );
        
        add_settings_error('rcn_backfill', 'migration_done', $msg, 'success');
    }
});

/**
 * Render the admin page
 */
function rcn_backfill_commitments_page() {
    // Get preview data
    $preview = rcn_run_backfill_zero_commitments(true); // dry_run = true
    
    settings_errors('rcn_backfill');
    ?>
    <div class="wrap">
        <h1>🔧 Backfill Zero Commitment Records</h1>
        
        <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <strong>⚠️ One-Time Migration Script</strong><br>
            This script finds weeks where a 0% attainment summary exists but no commitment records exist,
            and creates the missing 0-value commitment records.<br><br>
            <strong>Run this once, then remove or deactivate this file.</strong>
        </div>
        
        <hr>
        
        <h2>📊 Preview</h2>
        
        <table class="widefat" style="max-width: 600px;">
            <tr>
                <th style="width: 250px;">Zero Summary Records Found</th>
                <td><strong><?php echo $preview['total_zero_summaries']; ?></strong></td>
            </tr>
            <tr>
                <th>Missing Commitment Records</th>
                <td><strong><?php echo $preview['summaries_needing_backfill']; ?></strong> weeks need backfill</td>
            </tr>
            <tr>
                <th>Commitment Records to Create</th>
                <td><strong><?php echo $preview['commitments_to_create']; ?></strong> records (19 per week)</td>
            </tr>
            <tr>
                <th>Disciples Affected</th>
                <td><strong><?php echo count($preview['affected_disciples']); ?></strong> disciple(s)</td>
            </tr>
        </table>
        
        <?php if (!empty($preview['affected_disciples'])): ?>
        <h3 style="margin-top: 20px;">📋 Affected Disciples</h3>
        <table class="widefat" style="max-width: 800px;">
            <thead>
                <tr>
                    <th>Disciple</th>
                    <th>User ID</th>
                    <th>Weeks to Backfill</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($preview['affected_disciples'], 0, 30) as $d): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($d['name']); ?></strong><br>
                        <small><?php echo esc_html($d['email']); ?></small>
                    </td>
                    <td><?php echo $d['user_id']; ?></td>
                    <td>
                        <strong><?php echo $d['weeks_count']; ?></strong> week(s)<br>
                        <small><?php echo esc_html(implode(', ', array_slice($d['weeks'], 0, 5))); ?><?php echo $d['weeks_count'] > 5 ? '...' : ''; ?></small>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($preview['affected_disciples']) > 30): ?>
                <tr>
                    <td colspan="3"><em>... and <?php echo count($preview['affected_disciples']) - 30; ?> more</em></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <hr>
        
        <h2>🚀 Run Migration</h2>
        
        <?php if ($preview['summaries_needing_backfill'] > 0): ?>
        <form method="post">
            <?php wp_nonce_field('rcn_backfill_commitments'); ?>
            <p>
                <input type="submit" name="rcn_run_backfill" class="button button-primary" 
                       value="▶️ Run Backfill Now" 
                       onclick="return confirm('This will create <?php echo $preview['commitments_to_create']; ?> commitment records. This action cannot be undone. Continue?');">
            </p>
            <p class="description">
                This will create 0-value commitment records for all weeks that are missing them.
            </p>
        </form>
        <?php else: ?>
        <p style="color: green; font-weight: bold;">✅ No backfill needed! All zero summary records already have commitment records.</p>
        <?php endif; ?>
        
        <hr>
        
        <h2>📖 What This Does</h2>
        
        <p>For each week with a 0% attainment summary but no commitments, it creates:</p>
        
        <table class="widefat" style="max-width: 500px;">
            <thead>
                <tr>
                    <th>Practice</th>
                    <th>Records</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Bible Reading</td><td>7 (daily)</td><td>0.00</td></tr>
                <tr><td>Morning Intimacy</td><td>7 (daily)</td><td>0.00</td></tr>
                <tr><td>Fasting</td><td>1 (weekly)</td><td>0.00</td></tr>
                <tr><td>Scripture Memorization</td><td>1 (weekly)</td><td>0.00</td></tr>
                <tr><td>Bible Study & Meditation</td><td>1 (weekly)</td><td>0.00</td></tr>
                <tr><td>Midnight Intercessory Prayer</td><td>1 (weekly)</td><td>0.00</td></tr>
                <tr><td>Corporate Prayers</td><td>1 (weekly)</td><td>0.00</td></tr>
                <tr style="font-weight: bold; background: #f8f9fa;">
                    <td>Total per week</td>
                    <td colspan="2">19 records</td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}

/* =========================================
 * MIGRATION LOGIC
 * ========================================= */

/**
 * Find and backfill missing commitment records
 * 
 * @param bool $dry_run If true, just return preview data without making changes
 * @return array Results/preview data
 */
function rcn_run_backfill_zero_commitments($dry_run = true) {
    global $wpdb;
    
    $summary_table      = $wpdb->prefix . 'discipleship_attainment_summary';
    $commitments_table  = $wpdb->prefix . 'discipleship_commitments';
    $participants_table = $wpdb->prefix . 'discipleship_participants';
    
    $result = [
        'total_zero_summaries'      => 0,
        'summaries_needing_backfill'=> 0,
        'commitments_to_create'     => 0,
        'summaries_processed'       => 0,
        'commitments_created'       => 0,
        'affected_disciples'        => [],
    ];
    
    // Find all 0% attainment summary records
    $zero_summaries = $wpdb->get_results("
        SELECT s.participant_id, s.program_id, s.level_id, s.week_start,
               p.user_id, u.user_email, u.display_name
        FROM {$summary_table} s
        INNER JOIN {$participants_table} p ON s.participant_id = p.user_id 
            AND s.program_id = p.program_id 
            AND s.level_id = p.current_level_id
        INNER JOIN {$wpdb->users} u ON p.user_id = u.ID
        WHERE s.overall_attainment = 0
        ORDER BY u.display_name, s.week_start
    ");
    
    $result['total_zero_summaries'] = count($zero_summaries);
    
    // Group by disciple for reporting
    $disciples_data = [];
    
    $tz = wp_timezone();
    
    foreach ($zero_summaries as $summary) {
        $user_id    = (int) $summary->user_id;
        $program_id = (int) $summary->program_id;
        $level_id   = (int) $summary->level_id;
        $week_start = $summary->week_start;
        $email      = strtolower(trim($summary->user_email));
        
        // Check if commitment records exist for this week
        $has_commitments = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$commitments_table}
            WHERE participant_id = %s
              AND program_id = %d
              AND level_id = %d
              AND week_start = %s
        ", $email, $program_id, $level_id, $week_start));
        
        if ($has_commitments > 0) {
            continue; // Already has commitments, skip
        }
        
        $result['summaries_needing_backfill']++;
        $result['commitments_to_create'] += 19; // 7+7+1+1+1+1+1 = 19 records per week
        
        // Track affected disciples
        if (!isset($disciples_data[$user_id])) {
            $disciples_data[$user_id] = [
                'user_id' => $user_id,
                'name'    => $summary->display_name,
                'email'   => $summary->user_email,
                'weeks'   => [],
            ];
        }
        $disciples_data[$user_id]['weeks'][] = $week_start;
        
        // If not dry run, create the commitment records
        if (!$dry_run) {
            $practices_to_insert = [
                ['practice' => 'Bible Reading',                'unit_type' => 'daily',  'days' => 7],
                ['practice' => 'Morning Intimacy',             'unit_type' => 'daily',  'days' => 7],
                ['practice' => 'Fasting',                      'unit_type' => 'weekly', 'days' => 1],
                ['practice' => 'Scripture Memorization',       'unit_type' => 'weekly', 'days' => 1],
                ['practice' => 'Bible Study & Meditation',     'unit_type' => 'weekly', 'days' => 1],
                ['practice' => 'Midnight Intercessory Prayer', 'unit_type' => 'weekly', 'days' => 1],
                ['practice' => 'Corporate Prayers',            'unit_type' => 'weekly', 'days' => 1],
            ];
            
            foreach ($practices_to_insert as $practice_info) {
                if ($practice_info['unit_type'] === 'daily') {
                    // Insert 7 daily records
                    for ($day = 0; $day < 7; $day++) {
                        $date = (new DateTime($week_start, $tz))->modify("+{$day} days")->format('Y-m-d');
                        $inserted = $wpdb->insert(
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
                        if ($inserted) $result['commitments_created']++;
                    }
                } else {
                    // Weekly practice - one record
                    $inserted = $wpdb->insert(
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
                    if ($inserted) $result['commitments_created']++;
                }
            }
            
            $result['summaries_processed']++;
            
            error_log("[Backfill] Created 19 commitment records for user {$user_id}, week {$week_start}");
        }
    }
    
    // Format disciples data for display
    foreach ($disciples_data as $user_id => $data) {
        $data['weeks_count'] = count($data['weeks']);
        $result['affected_disciples'][] = $data;
    }
    
    // Sort by weeks_count descending
    usort($result['affected_disciples'], function($a, $b) {
        return $b['weeks_count'] - $a['weeks_count'];
    });
    
    return $result;
}

/* =========================================
 * AJAX ENDPOINT (optional)
 * ========================================= */

add_action('wp_ajax_rcn_backfill_preview', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $preview = rcn_run_backfill_zero_commitments(true);
    wp_send_json_success($preview);
});

add_action('wp_ajax_rcn_backfill_run', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $result = rcn_run_backfill_zero_commitments(false);
    wp_send_json_success($result);
});
