<?php
/**
 * Audit Attainment Summary vs Commitments Table
 * 
 * This script:
 * 1. Compares calculated attainment from commitments table vs stored attainment summary
 * 2. Identifies discrepancies
 * 3. Recalculates attainment for users with mismatches
 * 4. Updates participant table with correct cumulative attainment
 * 
 * Run via: WP-CLI, admin AJAX, or shortcode UI
 * 
 * IMPORTANT: Test on staging first! Back up your database!
 */

if (!defined('ABSPATH')) {
    exit('Run this within WordPress context.');
}

/**
 * Audit attainment summary vs commitments and fix discrepancies
 */
function rcn_audit_attainment_summary($dry_run = true) {
    global $wpdb;

    $commitments_table = $wpdb->prefix . 'discipleship_commitments';
    $summary_table     = $wpdb->prefix . 'discipleship_attainment_summary';
    $participants_table = $wpdb->prefix . 'discipleship_participants';

    // Practice definitions (must match Calculate Attainment.php)
    $all_practices = [
        'Bible Reading'                => ['type' => 'daily',  'column' => 'br_attainment'],
        'Morning Intimacy'             => ['type' => 'daily',  'column' => 'mi_attainment'],
        'Fasting'                      => ['type' => 'weekly', 'column' => 'fasting_attainment'],
        'Scripture Memorization'       => ['type' => 'weekly', 'column' => 'memorization_attainment'],
        'Bible Study & Meditation'     => ['type' => 'weekly', 'column' => 'bible_study_attainment'],
        'Midnight Intercessory Prayer' => ['type' => 'weekly', 'column' => 'mp_attainment'],
        'Corporate Prayers'            => ['type' => 'weekly', 'column' => 'cp_attainment'],
    ];

    $log = [];
    $stats = [
        'summaries_checked' => 0,
        'discrepancies_found' => 0,
        'users_to_recalculate' => [],
        'details' => [],
    ];

    // Get all attainment summary rows
    $summaries = $wpdb->get_results("
        SELECT s.*, p.user_id, u.user_email
        FROM $summary_table s
        INNER JOIN $participants_table p ON s.participant_id = p.user_id
        INNER JOIN {$wpdb->users} u ON p.user_id = u.ID
        ORDER BY s.participant_id, s.week_start
    ");

    $log[] = "Found " . count($summaries) . " attainment summary rows to audit.";

    foreach ($summaries as $summary) {
        $stats['summaries_checked']++;

        $participant_email = strtolower(trim($summary->user_email));
        $user_id = (int) $summary->user_id;
        $program_id = (int) $summary->program_id;
        $level_id = (int) $summary->level_id;
        $week_start = $summary->week_start;

        // Load all commitments for this week
        $commitments = $wpdb->get_results($wpdb->prepare("
            SELECT practice, unit_type, value
            FROM $commitments_table
            WHERE participant_id = %s
              AND program_id = %d
              AND level_id = %d
              AND week_start = %s
        ", $participant_email, $program_id, $level_id, $week_start));

        // Calculate expected attainment from commitments
        $calculated = [];
        $earned_total = 0.0;
        $possible_total = 0.0;

        foreach ($all_practices as $practice => $info) {
            $type = $info['type'];
            $possible = ($type === 'daily') ? 7 : 1;
            
            // Sum up earned values for this practice
            $earned = 0.0;
            foreach ($commitments as $c) {
                if ($c->practice === $practice) {
                    $earned += (float) $c->value;
                }
            }
            
            // Cap at possible (prevent duplicates inflating score)
            $earned = min($earned, $possible);
            
            // Calculate percentage
            $calculated[$practice] = ($possible > 0) ? round(($earned / $possible) * 100, 2) : 0;
            
            $earned_total += $earned;
            $possible_total += $possible;
        }

        // Calculate overall
        $calculated_overall = ($possible_total > 0) 
            ? round(($earned_total / $possible_total) * 100, 2) 
            : 0;

        // Compare with stored values
        $stored = [
            'Bible Reading'                => (float) $summary->br_attainment,
            'Morning Intimacy'             => (float) $summary->mi_attainment,
            'Fasting'                      => (float) $summary->fasting_attainment,
            'Scripture Memorization'       => (float) $summary->memorization_attainment,
            'Bible Study & Meditation'     => (float) $summary->bible_study_attainment,
            'Midnight Intercessory Prayer' => (float) $summary->mp_attainment,
            'Corporate Prayers'            => (float) $summary->cp_attainment,
        ];
        $stored_overall = (float) $summary->overall_attainment;

        // Check for discrepancies (allow 0.1% tolerance for floating point)
        $has_discrepancy = false;
        $discrepancy_details = [];

        if (abs($calculated_overall - $stored_overall) > 0.1) {
            $has_discrepancy = true;
            $discrepancy_details[] = "overall: {$stored_overall} → {$calculated_overall}";
        }

        foreach ($all_practices as $practice => $info) {
            if (abs($calculated[$practice] - $stored[$practice]) > 0.1) {
                $has_discrepancy = true;
                $short_name = $info['column'];
                $discrepancy_details[] = "{$short_name}: {$stored[$practice]} → {$calculated[$practice]}";
            }
        }

        if ($has_discrepancy) {
            $stats['discrepancies_found']++;
            $stats['users_to_recalculate'][$user_id] = $participant_email;
            
            $detail = [
                'user_id' => $user_id,
                'email' => $participant_email,
                'week_start' => $week_start,
                'discrepancies' => $discrepancy_details,
            ];
            $stats['details'][] = $detail;

            $log[] = "🟡 Discrepancy: {$participant_email} | {$week_start} | " . implode(', ', $discrepancy_details);
        }
    }

    // Summary
    $log[] = "";
    $log[] = "=== AUDIT COMPLETE ===";
    $log[] = "Summaries checked: {$stats['summaries_checked']}";
    $log[] = "Discrepancies found: {$stats['discrepancies_found']}";
    $log[] = "Users needing recalculation: " . count($stats['users_to_recalculate']);

    return [
        'stats' => $stats,
        'log' => $log,
    ];
}

/**
 * Recalculate attainment for all users with discrepancies
 */
function rcn_fix_attainment_discrepancies($dry_run = true) {
    global $wpdb;

    // First, run the audit
    $audit = rcn_audit_attainment_summary(true);
    $users_to_fix = $audit['stats']['users_to_recalculate'];

    $log = $audit['log'];
    $log[] = "";
    $log[] = "=== RECALCULATION ===";

    $fixed = 0;
    $errors = 0;

    foreach ($users_to_fix as $user_id => $email) {
        if ($dry_run) {
            $log[] = "🔵 [DRY RUN] Would recalculate: {$email} (ID: {$user_id})";
            $fixed++;
        } else {
            // Call the attainment calculation function
            if (function_exists('rcn_calculate_attainment')) {
                $result = rcn_calculate_attainment($user_id);
                if ($result) {
                    $log[] = "✅ Recalculated: {$email} (ID: {$user_id})";
                    $fixed++;
                } else {
                    $log[] = "❌ Failed: {$email} (ID: {$user_id})";
                    $errors++;
                }
            } else {
                $log[] = "❌ rcn_calculate_attainment() function not found!";
                $errors++;
                break;
            }
        }
    }

    $log[] = "";
    $log[] = "=== SUMMARY ===";
    $log[] = "Mode: " . ($dry_run ? "DRY RUN" : "LIVE");
    $log[] = "Users " . ($dry_run ? "to fix" : "fixed") . ": {$fixed}";
    if (!$dry_run) {
        $log[] = "Errors: {$errors}";
    }

    return [
        'mode' => $dry_run ? 'DRY RUN' : 'LIVE',
        'audit' => $audit['stats'],
        'fixed' => $fixed,
        'errors' => $errors,
        'log' => $log,
    ];
}

/**
 * Recalculate attainment for ALL participants (full rebuild)
 */
function rcn_recalculate_all_attainment($dry_run = true) {
    global $wpdb;

    $participants_table = $wpdb->prefix . 'discipleship_participants';

    // Get all active participants
    $participants = $wpdb->get_results("
        SELECT p.user_id, u.user_email
        FROM $participants_table p
        INNER JOIN {$wpdb->users} u ON p.user_id = u.ID
        WHERE p.status = 'active'
        ORDER BY p.user_id
    ");

    $log = [];
    $log[] = "Found " . count($participants) . " active participants.";
    $log[] = "";

    $processed = 0;
    $errors = 0;

    foreach ($participants as $p) {
        $user_id = (int) $p->user_id;
        $email = $p->user_email;

        if ($dry_run) {
            $log[] = "🔵 [DRY RUN] Would recalculate: {$email} (ID: {$user_id})";
            $processed++;
        } else {
            if (function_exists('rcn_calculate_attainment')) {
                $result = rcn_calculate_attainment($user_id);
                if ($result) {
                    $log[] = "✅ Recalculated: {$email} (ID: {$user_id})";
                    $processed++;
                } else {
                    $log[] = "⚠️ No data or skipped: {$email} (ID: {$user_id})";
                }
            } else {
                $log[] = "❌ rcn_calculate_attainment() function not found!";
                $errors++;
                break;
            }
        }
    }

    $log[] = "";
    $log[] = "=== COMPLETE ===";
    $log[] = "Mode: " . ($dry_run ? "DRY RUN" : "LIVE");
    $log[] = "Processed: {$processed}";
    if (!$dry_run) {
        $log[] = "Errors: {$errors}";
    }

    return [
        'mode' => $dry_run ? 'DRY RUN' : 'LIVE',
        'total_participants' => count($participants),
        'processed' => $processed,
        'errors' => $errors,
        'log' => $log,
    ];
}

// ============================================
// ADMIN AJAX HANDLERS
// ============================================

add_action('wp_ajax_rcn_audit_attainment_dry', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rcn_audit_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    $result = rcn_audit_attainment_summary(true);
    wp_send_json_success($result);
});

add_action('wp_ajax_rcn_fix_discrepancies_dry', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rcn_audit_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    $result = rcn_fix_attainment_discrepancies(true);
    wp_send_json_success($result);
});

add_action('wp_ajax_rcn_fix_discrepancies_live', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rcn_audit_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    $result = rcn_fix_attainment_discrepancies(false);
    wp_send_json_success($result);
});

add_action('wp_ajax_rcn_recalculate_all_dry', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rcn_audit_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    $result = rcn_recalculate_all_attainment(true);
    wp_send_json_success($result);
});

add_action('wp_ajax_rcn_recalculate_all_live', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rcn_audit_nonce')) {
        wp_send_json_error('Invalid nonce');
    }

    $result = rcn_recalculate_all_attainment(false);
    wp_send_json_success($result);
});

// ============================================
// SHORTCODE UI
// ============================================
add_shortcode('rcn_audit_attainment_ui', function() {
    if (!current_user_can('manage_options')) {
        return '<p>Access denied.</p>';
    }

    ob_start();
    ?>
    <div id="audit-ui" style="max-width:1000px;margin:20px auto;font-family:system-ui;">
        <h2>Audit & Fix Attainment Summary</h2>
        
        <div style="background:#fef3c7;border:1px solid #f59e0b;padding:16px;border-radius:8px;margin-bottom:20px;">
            <strong>⚠️ Warning:</strong> Back up your database before running live fixes!
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
            <button id="btn-audit" style="padding:12px 24px;background:#6366f1;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                🔍 Audit Only
            </button>
            <button id="btn-fix-dry" style="padding:12px 24px;background:#3b82f6;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                📋 Fix Discrepancies (Dry Run)
            </button>
            <button id="btn-fix-live" style="padding:12px 24px;background:#dc2626;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                🚀 Fix Discrepancies (LIVE)
            </button>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;padding-top:12px;border-top:1px solid #e2e8f0;">
            <button id="btn-recalc-dry" style="padding:12px 24px;background:#059669;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                🔄 Recalculate ALL (Dry Run)
            </button>
            <button id="btn-recalc-live" style="padding:12px 24px;background:#b91c1c;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                ⚡ Recalculate ALL (LIVE)
            </button>
        </div>

        <div id="results" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:20px;display:none;">
            <h3 id="results-title">Results</h3>
            <pre id="results-summary" style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;overflow:auto;max-height:200px;"></pre>
            <h4>Log:</h4>
            <pre id="results-log" style="background:#fff;border:1px solid #e2e8f0;padding:16px;border-radius:8px;overflow:auto;max-height:500px;font-size:12px;"></pre>
        </div>
    </div>

    <script>
    const ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    const nonce = '<?php echo wp_create_nonce('rcn_audit_nonce'); ?>';

    async function runAction(action, title, btn) {
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Running...';

        const resultsDiv = document.getElementById('results');
        resultsDiv.style.display = 'block';
        document.getElementById('results-title').textContent = '⏳ ' + title + '...';
        document.getElementById('results-summary').textContent = 'Please wait...';
        document.getElementById('results-log').textContent = '';

        try {
            const res = await fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=' + action + '&_wpnonce=' + nonce
            });
            const json = await res.json();

            if (json.success) {
                document.getElementById('results-title').textContent = '✅ ' + title + ' Complete';
                const summary = json.data.stats || json.data.audit || json.data;
                document.getElementById('results-summary').textContent = JSON.stringify(summary, null, 2);
                document.getElementById('results-log').textContent = (json.data.log || []).join('\n');
            } else {
                document.getElementById('results-title').textContent = '❌ Error';
                document.getElementById('results-summary').textContent = JSON.stringify(json, null, 2);
            }
        } catch (err) {
            document.getElementById('results-title').textContent = '❌ Error';
            document.getElementById('results-summary').textContent = 'Request failed: ' + err.message;
        }

        btn.disabled = false;
        btn.textContent = originalText;
    }

    document.getElementById('btn-audit').addEventListener('click', function() {
        runAction('rcn_audit_attainment_dry', 'Audit', this);
    });

    document.getElementById('btn-fix-dry').addEventListener('click', function() {
        runAction('rcn_fix_discrepancies_dry', 'Fix Discrepancies (Dry Run)', this);
    });

    document.getElementById('btn-fix-live').addEventListener('click', function() {
        if (!confirm('This will recalculate attainment for users with discrepancies. Continue?')) return;
        runAction('rcn_fix_discrepancies_live', 'Fix Discrepancies', this);
    });

    document.getElementById('btn-recalc-dry').addEventListener('click', function() {
        runAction('rcn_recalculate_all_dry', 'Recalculate All (Dry Run)', this);
    });

    document.getElementById('btn-recalc-live').addEventListener('click', function() {
        if (!confirm('This will recalculate attainment for ALL active participants. This may take a while. Continue?')) return;
        runAction('rcn_recalculate_all_live', 'Recalculate All', this);
    });
    </script>
    <?php
    return ob_get_clean();
});
