<?php
/**
 * Backfill Discipleship Commitments from Fluent Form Submissions
 * 
 * This script:
 * 1. Reads raw form submissions from wp_fluentform_submissions (form_id = 3)
 * 2. Parses the JSON response to extract practices
 * 3. Inserts missing weekly practice records into discipleship_commitments
 * 4. Recalculates attainment summaries for affected weeks
 * 
 * Run via: WP-CLI, admin AJAX, or as a one-time script
 * 
 * IMPORTANT: Test on staging first! Back up your database!
 */

if (!defined('ABSPATH')) {
    // Allow running from command line with wp-load.php
    // require_once('/path/to/wp-load.php');
    exit('Run this within WordPress context.');
}

/**
 * Main backfill function
 */
function rcn_backfill_commitments_from_fluent_forms($dry_run = true) {
    global $wpdb;

    $form_id = 3;
    $start_date = '2025-09-28'; // Backfill from this date
    
    $fluent_table = $wpdb->prefix . 'fluentform_submissions';
    $commitments_table = $wpdb->prefix . 'discipleship_commitments';
    $participants_table = $wpdb->prefix . 'discipleship_participants';

    // Field mappings - support BOTH old and new field names
    // Old format: input_radio, input_radio_1, etc. (string values)
    // New format: Fasting, midnight_intercessory_prayer, etc. (array values)
    $weekly_practice_map = [
        // Old field names
        'input_radio'   => 'Fasting',
        'input_radio_1' => 'Midnight Intercessory Prayer',
        'input_radio_2' => 'Bible Study & Meditation',
        'input_radio_3' => 'Scripture Memorization',
        'input_radio_4' => 'Corporate Prayers',
        // New field names
        'Fasting'                              => 'Fasting',
        'midnight_intercessory_prayer'         => 'Midnight Intercessory Prayer',
        'bible_study_meditation'               => 'Bible Study & Meditation',
        'scripture_memorization'               => 'Scripture Memorization',
        'corporate_gathering_prayers_commitment' => 'Corporate Prayers',
    ];

    // Value mappings (adjust these based on your form options)
    $value_map = [
        // Variations of "fully complete"
        'fully complete'    => 1.0,
        'fully completed'   => 1.0,
        'complete'          => 1.0,
        'completed'         => 1.0,
        'yes'               => 1.0,
        '1'                 => 1.0,
        
        // Variations of "partial"
        'partially complete'   => 0.5,
        'partially completed'  => 0.5,
        'partial'              => 0.5,
        'half'                 => 0.5,
        '0.5'                  => 0.5,
        
        // Variations of "not complete"
        'not complete'      => 0.0,
        'not completed'     => 0.0,
        'not done'          => 0.0,
        'no'                => 0.0,
        'none'              => 0.0,
        ''                  => 0.0,
        '0'                 => 0.0,
    ];

    // Day name to date offset (Sunday = 0)
    $day_offsets = [
        'sunday'    => 0,
        'monday'    => 1,
        'tuesday'   => 2,
        'wednesday' => 3,
        'thursday'  => 4,
        'friday'    => 5,
        'saturday'  => 6,
    ];

    $log = [];
    $stats = [
        'submissions_processed' => 0,
        'records_inserted' => 0,
        'records_updated' => 0,
        'records_skipped' => 0,
        'errors' => 0,
        'users_affected' => [],
        'weeks_affected' => [],
    ];

    // Fetch all form submissions
    $submissions = $wpdb->get_results($wpdb->prepare("
        SELECT id, user_id, response, created_at
        FROM $fluent_table
        WHERE form_id = %d
          AND created_at >= %s
        ORDER BY created_at ASC
    ", $form_id, $start_date));

    $log[] = "Found " . count($submissions) . " submissions to process.";

    foreach ($submissions as $sub) {
        $stats['submissions_processed']++;

        // Get user email
        $user = get_user_by('ID', $sub->user_id);
        if (!$user) {
            $log[] = "⚠️ Submission #{$sub->id}: User ID {$sub->user_id} not found. Skipping.";
            $stats['errors']++;
            continue;
        }
        $participant_email = strtolower(trim($user->user_email));

        // Get participant row for program_id and level_id
        $participant = $wpdb->get_row($wpdb->prepare("
            SELECT program_id, current_level_id
            FROM $participants_table
            WHERE user_id = %d
            LIMIT 1
        ", $sub->user_id));

        if (!$participant) {
            $log[] = "⚠️ Submission #{$sub->id}: No participant record for user {$participant_email}. Skipping.";
            $stats['errors']++;
            continue;
        }

        $program_id = (int) $participant->program_id;
        $level_id = (int) $participant->current_level_id;

        // Parse JSON response
        $response = json_decode($sub->response, true);
        if (!$response) {
            $log[] = "⚠️ Submission #{$sub->id}: Invalid JSON response. Skipping.";
            $stats['errors']++;
            continue;
        }

        // Parse week_start_date (DD/MM/YYYY format)
        $week_start_raw = $response['week_start_date'] ?? '';
        if (!$week_start_raw) {
            $log[] = "⚠️ Submission #{$sub->id}: No week_start_date. Skipping.";
            $stats['errors']++;
            continue;
        }

        // Convert DD/MM/YYYY to YYYY-MM-DD
        $parts = explode('/', $week_start_raw);
        if (count($parts) !== 3) {
            $log[] = "⚠️ Submission #{$sub->id}: Invalid date format '{$week_start_raw}'. Skipping.";
            $stats['errors']++;
            continue;
        }
        $week_start = sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);

        $stats['users_affected'][$participant_email] = true;
        $stats['weeks_affected'][$week_start] = true;

        // =====================
        // PROCESS WEEKLY PRACTICES
        // =====================
        $processed_practices = []; // Track which practices we've already processed this submission
        
        foreach ($weekly_practice_map as $field => $practice_name) {
            // Skip if we already processed this practice (handles old+new field name overlap)
            if (isset($processed_practices[$practice_name])) {
                continue;
            }
            
            // Check if field exists in response
            if (!isset($response[$field])) {
                continue;
            }
            
            $raw_value = $response[$field];
            
            // Handle both string and array formats
            // Old format: "Fully Complete" (string)
            // New format: ["Fully Complete"] (array)
            if (is_array($raw_value)) {
                $raw_value = $raw_value[0] ?? '';
            }
            $raw_value_lower = strtolower(trim($raw_value));
            
            // Map to numeric value
            $value = $value_map[$raw_value_lower] ?? 0.0;
            
            // Mark this practice as processed
            $processed_practices[$practice_name] = true;

            // Check if record already exists
            $existing = $wpdb->get_row($wpdb->prepare("
                SELECT id, value FROM $commitments_table
                WHERE participant_id = %s
                  AND program_id = %d
                  AND level_id = %d
                  AND week_start = %s
                  AND practice = %s
                LIMIT 1
            ", $participant_email, $program_id, $level_id, $week_start, $practice_name));

            if ($existing) {
                // Check if values match
                $existing_value = (float) $existing->value;
                if (abs($existing_value - $value) < 0.001) {
                    // Values match, skip
                    $stats['records_skipped']++;
                    continue;
                }
                
                // Values don't match - UPDATE the record
                if ($dry_run) {
                    $log[] = "🟡 [DRY RUN] Would update: {$participant_email} | {$week_start} | {$practice_name} = {$existing_value} → {$value}";
                    $stats['records_updated']++;
                } else {
                    $result = $wpdb->update(
                        $commitments_table,
                        ['value' => $value],
                        ['id' => $existing->id],
                        ['%f'],
                        ['%d']
                    );
                    if ($result !== false) {
                        $log[] = "✅ Updated: {$participant_email} | {$week_start} | {$practice_name} = {$existing_value} → {$value}";
                        $stats['records_updated']++;
                    } else {
                        $log[] = "❌ Failed to update: {$participant_email} | {$week_start} | {$practice_name}";
                        $stats['errors']++;
                    }
                }
                continue;
            }

            // Insert missing record
            $insert_data = [
                'participant_id' => $participant_email,
                'program_id'     => $program_id,
                'level_id'       => $level_id,
                'week_start'     => $week_start,
                'date'           => $week_start, // For weekly practices, date = week_start
                'practice'       => $practice_name,
                'unit_type'      => 'weekly',
                'value'          => $value,
            ];

            if ($dry_run) {
                $log[] = "🔵 [DRY RUN] Would insert: {$participant_email} | {$week_start} | {$practice_name} = {$value}";
                $stats['records_inserted']++;
            } else {
                $result = $wpdb->insert($commitments_table, $insert_data);
                if ($result) {
                    $log[] = "✅ Inserted: {$participant_email} | {$week_start} | {$practice_name} = {$value}";
                    $stats['records_inserted']++;
                } else {
                    $log[] = "❌ Failed to insert: {$participant_email} | {$week_start} | {$practice_name}";
                    $stats['errors']++;
                }
            }
        }

        // =====================
        // CHECK DAILY PRACTICES (Bible Reading, Morning Intimacy)
        // =====================
        $daily_practices = [
            'bible_reading'    => 'Bible Reading',
            'morning_intimacy' => 'Morning Intimacy',
        ];

        foreach ($daily_practices as $field => $practice_name) {
            $days_array = $response[$field] ?? [];
            if (!is_array($days_array)) continue;

            foreach ($days_array as $day_name) {
                $day_lower = strtolower(trim($day_name));
                if (!isset($day_offsets[$day_lower])) continue;

                $offset = $day_offsets[$day_lower];
                $practice_date = date('Y-m-d', strtotime($week_start . " +{$offset} days"));
                $value = 1.0; // If day is checked, value = 1

                // Check if record already exists
                $existing = $wpdb->get_row($wpdb->prepare("
                    SELECT id, value FROM $commitments_table
                    WHERE participant_id = %s
                      AND program_id = %d
                      AND level_id = %d
                      AND date = %s
                      AND practice = %s
                    LIMIT 1
                ", $participant_email, $program_id, $level_id, $practice_date, $practice_name));

                if ($existing) {
                    // Check if values match
                    $existing_value = (float) $existing->value;
                    if (abs($existing_value - $value) < 0.001) {
                        // Values match, skip
                        $stats['records_skipped']++;
                        continue;
                    }
                    
                    // Values don't match - UPDATE the record
                    if ($dry_run) {
                        $log[] = "🟡 [DRY RUN] Would update: {$participant_email} | {$practice_date} | {$practice_name} = {$existing_value} → {$value}";
                        $stats['records_updated']++;
                    } else {
                        $result = $wpdb->update(
                            $commitments_table,
                            ['value' => $value],
                            ['id' => $existing->id],
                            ['%f'],
                            ['%d']
                        );
                        if ($result !== false) {
                            $log[] = "✅ Updated: {$participant_email} | {$practice_date} | {$practice_name} = {$existing_value} → {$value}";
                            $stats['records_updated']++;
                        } else {
                            $log[] = "❌ Failed to update: {$participant_email} | {$practice_date} | {$practice_name}";
                            $stats['errors']++;
                        }
                    }
                    continue;
                }

                // Insert missing daily record
                $insert_data = [
                    'participant_id' => $participant_email,
                    'program_id'     => $program_id,
                    'level_id'       => $level_id,
                    'week_start'     => $week_start,
                    'date'           => $practice_date,
                    'practice'       => $practice_name,
                    'unit_type'      => 'daily',
                    'value'          => $value,
                ];

                if ($dry_run) {
                    $log[] = "🔵 [DRY RUN] Would insert: {$participant_email} | {$practice_date} | {$practice_name} = {$value}";
                    $stats['records_inserted']++;
                } else {
                    $result = $wpdb->insert($commitments_table, $insert_data);
                    if ($result) {
                        $log[] = "✅ Inserted: {$participant_email} | {$practice_date} | {$practice_name} = {$value}";
                        $stats['records_inserted']++;
                    } else {
                        $log[] = "❌ Failed to insert: {$participant_email} | {$practice_date} | {$practice_name}";
                        $stats['errors']++;
                    }
                }
            }
        }
    }

    // Summary
    $summary = [
        'mode' => $dry_run ? 'DRY RUN' : 'LIVE',
        'submissions_processed' => $stats['submissions_processed'],
        'records_inserted' => $stats['records_inserted'],
        'records_skipped' => $stats['records_skipped'],
        'errors' => $stats['errors'],
        'unique_users' => count($stats['users_affected']),
        'unique_weeks' => count($stats['weeks_affected']),
        'users_list' => array_keys($stats['users_affected']),
        'weeks_list' => array_keys($stats['weeks_affected']),
    ];

    return [
        'summary' => $summary,
        'log' => $log,
    ];
}

/**
 * Step 2: Recalculate attainment summaries for affected users
 */
function rcn_recalculate_attainment_for_users($user_emails = [], $dry_run = true) {
    global $wpdb;

    $participants_table = $wpdb->prefix . 'discipleship_participants';
    $log = [];
    $count = 0;

    foreach ($user_emails as $email) {
        $email = strtolower(trim($email));
        
        // Get user ID from email
        $user = get_user_by('email', $email);
        if (!$user) {
            $log[] = "⚠️ User not found: {$email}";
            continue;
        }

        if ($dry_run) {
            $log[] = "🔵 [DRY RUN] Would recalculate attainment for: {$email} (ID: {$user->ID})";
        } else {
            // Call the existing attainment calculation function
            if (function_exists('rcn_calculate_attainment')) {
                $result = rcn_calculate_attainment($user->ID);
                if ($result) {
                    $log[] = "✅ Recalculated attainment for: {$email}";
                    $count++;
                } else {
                    $log[] = "❌ Failed to recalculate for: {$email}";
                }
            } else {
                $log[] = "❌ rcn_calculate_attainment() function not found!";
            }
        }
    }

    return [
        'recalculated' => $count,
        'log' => $log,
    ];
}

// ============================================
// ADMIN AJAX HANDLERS (for running from admin)
// ============================================

add_action('wp_ajax_rcn_backfill_dry_run', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized - must be admin');
    }
    
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rcn_backfill_nonce')) {
        wp_send_json_error('Invalid nonce - please refresh the page');
    }

    $result = rcn_backfill_commitments_from_fluent_forms(true); // Dry run
    wp_send_json_success($result);
});

add_action('wp_ajax_rcn_backfill_execute', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized - must be admin');
    }
    
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rcn_backfill_nonce')) {
        wp_send_json_error('Invalid nonce - please refresh the page');
    }

    // Step 1: Backfill commitments
    $backfill_result = rcn_backfill_commitments_from_fluent_forms(false); // Live run

    // Step 2: Recalculate attainment for affected users
    $users = $backfill_result['summary']['users_list'] ?? [];
    $recalc_result = rcn_recalculate_attainment_for_users($users, false);

    wp_send_json_success([
        'backfill' => $backfill_result,
        'recalculation' => $recalc_result,
    ]);
});

// ============================================
// SHORTCODE FOR ADMIN UI
// ============================================
add_shortcode('rcn_backfill_ui', function() {
    if (!current_user_can('manage_options')) {
        return '<p>Access denied.</p>';
    }

    ob_start();
    ?>
    <div id="backfill-ui" style="max-width:900px;margin:20px auto;font-family:system-ui;">
        <h2>Backfill Commitments from Fluent Forms</h2>
        
        <div style="background:#fef3c7;border:1px solid #f59e0b;padding:16px;border-radius:8px;margin-bottom:20px;">
            <strong>⚠️ Warning:</strong> Back up your database before running the live backfill!
        </div>

        <div style="display:flex;gap:12px;margin-bottom:20px;">
            <button id="btn-dry-run" style="padding:12px 24px;background:#3b82f6;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                🔍 Dry Run (Preview)
            </button>
            <button id="btn-execute" style="padding:12px 24px;background:#dc2626;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                🚀 Execute Backfill
            </button>
        </div>

        <div id="results" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:20px;display:none;">
            <h3 id="results-title">Results</h3>
            <pre id="results-summary" style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;overflow:auto;max-height:200px;"></pre>
            <h4>Log:</h4>
            <pre id="results-log" style="background:#fff;border:1px solid #e2e8f0;padding:16px;border-radius:8px;overflow:auto;max-height:400px;font-size:12px;"></pre>
        </div>
    </div>

    <script>
    const ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    const nonce = '<?php echo wp_create_nonce('rcn_backfill_nonce'); ?>';
    
    document.getElementById('btn-dry-run').addEventListener('click', async function() {
        this.disabled = true;
        this.textContent = '⏳ Running...';
        
        const resultsDiv = document.getElementById('results');
        resultsDiv.style.display = 'block';
        document.getElementById('results-title').textContent = '⏳ Running Dry Run...';
        document.getElementById('results-summary').textContent = 'Please wait...';
        document.getElementById('results-log').textContent = '';
        
        try {
            const res = await fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=rcn_backfill_dry_run&_wpnonce=' + nonce
            });
            const json = await res.json();
            
            if (json.success) {
                document.getElementById('results-title').textContent = '🔍 Dry Run Results';
                document.getElementById('results-summary').textContent = JSON.stringify(json.data?.summary || json, null, 2);
                document.getElementById('results-log').textContent = (json.data?.log || []).join('\n');
            } else {
                document.getElementById('results-title').textContent = '❌ Error';
                document.getElementById('results-summary').textContent = JSON.stringify(json, null, 2);
            }
        } catch (err) {
            document.getElementById('results-title').textContent = '❌ Error';
            document.getElementById('results-summary').textContent = 'Request failed: ' + err.message;
        }
        
        this.disabled = false;
        this.textContent = '🔍 Dry Run (Preview)';
    });

    document.getElementById('btn-execute').addEventListener('click', async function() {
        if (!confirm('Are you sure? This will modify the database!')) return;
        
        this.disabled = true;
        this.textContent = '⏳ Executing...';
        
        const resultsDiv = document.getElementById('results');
        resultsDiv.style.display = 'block';
        document.getElementById('results-title').textContent = '⏳ Executing Backfill...';
        document.getElementById('results-summary').textContent = 'Please wait... This may take a while.';
        document.getElementById('results-log').textContent = '';
        
        try {
            const res = await fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=rcn_backfill_execute&_wpnonce=' + nonce
            });
            const json = await res.json();
            
            if (json.success) {
                document.getElementById('results-title').textContent = '🚀 Execution Results';
                document.getElementById('results-summary').textContent = JSON.stringify(json.data, null, 2);
                
                const allLogs = [
                    ...(json.data?.backfill?.log || []),
                    '--- RECALCULATION ---',
                    ...(json.data?.recalculation?.log || [])
                ];
                document.getElementById('results-log').textContent = allLogs.join('\n');
            } else {
                document.getElementById('results-title').textContent = '❌ Error';
                document.getElementById('results-summary').textContent = JSON.stringify(json, null, 2);
            }
        } catch (err) {
            document.getElementById('results-title').textContent = '❌ Error';
            document.getElementById('results-summary').textContent = 'Request failed: ' + err.message;
        }
        
        this.disabled = false;
        this.textContent = '🚀 Execute Backfill';
    });
    </script>
    <?php
    return ob_get_clean();
});
