if (!defined('ABSPATH')) exit;

/**
 * Front-end shortcode: [rcn_send_notifications]
 * Leader-only UI to send notifications to a single disciple or all disciples.
 * Sends BOTH: in-app (DB) + email (optional).
 *
 * Requires existing functions:
 * - rcn_get_notification_templates()
 * - rcn_render_template($template, $vars)
 * - rcn_log_notification($user_id, $type, $message)
 * - rcn_send_email_notification($user_id, $subject, $body)
 */

/* -----------------------------------------
   SAFE DEFAULTS (won't fatal if undefined)
------------------------------------------ */
if (!defined('RCN_LEADER_CAP')) define('RCN_LEADER_CAP', 'access_leadership');

// If you already defined this elsewhere, keep it.
// If not, it defaults to 'disciple' (adjust if your role slug differs).
if (!defined('RCN_DISCIPLE_ROLE')) define('RCN_DISCIPLE_ROLE', 'disciple');

/* -----------------------------------------
   SHORTCODE UI - Unified with Slide Navigation
------------------------------------------ */
add_shortcode('rcn_send_notifications', function () {

    if (!is_user_logged_in()) return '<p>Please log in.</p>';

    $leader_cap = defined('RCN_LEADER_CAP') ? RCN_LEADER_CAP : 'access_leadership';
    if (!current_user_can('administrator') && !current_user_can($leader_cap)) {
        return '<p>Access denied.</p>';
    }

    $disciple_role = defined('RCN_DISCIPLE_ROLE') ? RCN_DISCIPLE_ROLE : 'disciple';
    $current_user_id = get_current_user_id();
    $is_admin = current_user_can('manage_options');

    // Fetch disciples
    $disciples = get_users([
        'role'    => $disciple_role,
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => ['ID', 'display_name', 'user_email'],
        'number'  => 5000,
    ]);

    $sent_msg = isset($_GET['rcn_sent']) ? sanitize_text_field(wp_unslash($_GET['rcn_sent'])) : '';
    $err_msg  = isset($_GET['rcn_err'])  ? sanitize_text_field(wp_unslash($_GET['rcn_err']))  : '';
    
    // Configuration data
    $opted_in = rcn_is_leader_opted_in_missed_alerts($current_user_id);
    $config = function_exists('rcn_get_reminder_config') ? rcn_get_reminder_config() : [
        'reminder_day' => 1, 'reminder_hour' => 18,
        'encouragement_day' => 5, 'encouragement_hour' => 18,
        'enabled' => true,
    ];
    $day_labels = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'
    ];
    
    // Handle config form submission
    $config_msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rcn_leader_settings_nonce'])) {
        if (wp_verify_nonce($_POST['rcn_leader_settings_nonce'], 'rcn_leader_settings')) {
            $new_opt_in = !empty($_POST['rcn_missed_alerts_optin']);
            rcn_set_leader_missed_alerts_optin($current_user_id, $new_opt_in);
            $opted_in = $new_opt_in;
            
            if ($is_admin && isset($_POST['rcn_schedule_config'])) {
                $new_config = [
                    'reminder_day'       => intval($_POST['reminder_day'] ?? 1),
                    'reminder_hour'      => intval($_POST['reminder_hour'] ?? 18),
                    'encouragement_day'  => intval($_POST['encouragement_day'] ?? 5),
                    'encouragement_hour' => intval($_POST['encouragement_hour'] ?? 18),
                    'enabled'            => !empty($_POST['reminders_enabled']),
                ];
                if (function_exists('rcn_save_reminder_config')) {
                    rcn_save_reminder_config($new_config);
                    $config = $new_config;
                }
            }
            $config_msg = 'Settings saved successfully.';
        }
    }

    ob_start(); ?>
<style>
/* === SLIDER CONTAINER === */
.rcn-notif-wrapper {
    max-width: 760px;
    margin: 0 auto;
}

/* === TAB NAVIGATION === */
.rcn-tab-nav {
    display: flex;
    background: #f1f5f9;
    border-radius: 14px;
    padding: 4px;
    margin-bottom: 20px;
    gap: 4px;
}
.rcn-tab-btn {
    flex: 1;
    padding: 14px 20px;
    border: none;
    background: transparent;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.rcn-tab-btn:hover {
    color: #334155;
    background: rgba(255,255,255,0.5);
}
.rcn-tab-btn.active {
    background: #fff;
    color: #1e293b;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.rcn-tab-btn .icon {
    font-size: 16px;
}

/* === SLIDER VIEWPORT === */
.rcn-slider-viewport {
    overflow: hidden;
    border-radius: 18px;
}
.rcn-slider-track {
    display: flex;
    transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    width: 200%;
}
.rcn-slider-track.show-config {
    transform: translateX(-50%);
}
.rcn-slide {
    width: 50%;
    flex-shrink: 0;
}

/* === CARD / PANEL === */
.rcn-panel {
    background: #fff;
    border-radius: 18px;
    padding: 32px 34px;
    border: 1px solid rgba(0,0,0,.04);
    box-shadow: 0 28px 70px rgba(0,0,0,.18);
}
.rcn-panel h3 {
    margin: 0 0 18px 0 !important;
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
}
.rcn-panel p.desc {
    margin: 0 0 20px;
    font-size: 14px;
    color: #6b7280;
    line-height: 1.5;
}

/* === FORM ELEMENTS === */
.rcn-panel label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #374151;
    margin-bottom: 8px;
}
.rcn-panel input[type="text"],
.rcn-panel input[type="email"],
.rcn-panel select,
.rcn-panel textarea {
    width: 100% !important;
    background: #f7f7f9;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 14px 16px;
    font-size: 15px;
    color: #111827;
    outline: none;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.65);
    transition: border-color 0.2s, box-shadow 0.2s;
}
.rcn-panel textarea {
    min-height: 140px;
    resize: vertical;
}
.rcn-panel input:focus,
.rcn-panel select:focus,
.rcn-panel textarea:focus {
    border-color: rgba(201,17,17,.55);
    box-shadow: 0 0 0 4px rgba(201,17,17,.12);
    background: #fff;
}
.rcn-panel input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin-right: 10px;
    cursor: pointer;
    vertical-align: middle;
}
.rcn-panel form > p {
    margin: 0 0 16px 0;
}

/* === BUTTONS === */
.rcn-btn-primary {
    width: 100%;
    padding: 16px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    background: linear-gradient(135deg, #c91111, #9a0e0e);
    color: #fff;
    box-shadow: 0 12px 26px rgba(201,17,17,.25);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.rcn-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 18px 34px rgba(201,17,17,.28);
}
.rcn-btn-secondary {
    padding: 14px 24px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #fff;
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.25);
    transition: transform 0.2s ease;
}
.rcn-btn-secondary:hover {
    transform: translateY(-1px);
}

/* === SETTING ROW === */
.rcn-setting-row {
    display: flex;
    align-items: center;
    padding: 16px;
    background: #f9fafb;
    border-radius: 12px;
    margin-bottom: 16px;
}
.rcn-setting-row label {
    margin: 0;
    font-size: 14px;
    cursor: pointer;
}

/* === SCHEDULE GRID === */
.rcn-schedule-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.rcn-schedule-item {
    background: #f9fafb;
    padding: 16px;
    border-radius: 12px;
}
.rcn-schedule-item label {
    font-size: 12px;
    margin-bottom: 8px;
}
.rcn-schedule-item select {
    padding: 10px 12px !important;
}

/* === BADGES === */
.rcn-admin-badge {
    display: inline-block;
    background: #fee2e2;
    color: #991b1b;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 4px;
    margin-left: 8px;
    text-transform: uppercase;
}

/* === MESSAGES === */
.rcn-msg-success {
    padding: 12px 16px;
    margin-bottom: 16px;
    border-radius: 10px;
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
    font-size: 14px;
}
.rcn-msg-error {
    padding: 12px 16px;
    margin-bottom: 16px;
    border-radius: 10px;
    background: #fef2f2;
    border: 1px solid #ef4444;
    color: #991b1b;
    font-size: 14px;
}

/* === DIVIDER === */
.rcn-divider {
    border-top: 1px solid #e5e7eb;
    margin: 24px 0;
    padding-top: 24px;
}

/* === TEST BUTTONS === */
.rcn-test-buttons {
    display: flex;
    gap: 12px;
    margin-top: 16px;
}
.rcn-btn-test {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: transform 0.2s ease;
}
.rcn-btn-test:hover {
    transform: translateY(-1px);
}
.rcn-btn-monday { background: #3b82f6; color: #fff; }
.rcn-btn-friday { background: #8b5cf6; color: #fff; }

/* === RESPONSIVE === */
@media (max-width: 600px) {
    .rcn-schedule-grid {
        grid-template-columns: 1fr;
    }
    .rcn-tab-btn {
        padding: 12px 16px;
        font-size: 13px;
    }
}
</style>

<div class="rcn-notif-wrapper">
    <!-- Tab Navigation -->
    <div class="rcn-tab-nav">
        <button type="button" class="rcn-tab-btn active" data-tab="notification">
            <span class="icon">📨</span> Send Notification
        </button>
        <button type="button" class="rcn-tab-btn" data-tab="config">
            <span class="icon">⚙️</span> Settings
        </button>
    </div>
    
    <!-- Slider Viewport -->
    <div class="rcn-slider-viewport">
        <div class="rcn-slider-track" id="rcn-slider-track">
            
            <!-- SLIDE 1: Send Notification -->
            <div class="rcn-slide">
                <div class="rcn-panel">
                    <h3>📨 Send Notification</h3>
                    
                    <?php if ($sent_msg): ?>
                        <div class="rcn-msg-success"><?php echo esc_html($sent_msg); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($err_msg): ?>
                        <div class="rcn-msg-error"><?php echo esc_html($err_msg); ?></div>
                    <?php endif; ?>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('rcn_send_notif_front', 'rcn_send_notif_front_nonce'); ?>
                        <input type="hidden" name="action" value="rcn_send_notifications_front" />
                        <input type="hidden" name="rcn_return" value="<?php echo esc_url(get_permalink()); ?>" />
                        
                        <p>
                            <label><b>Search disciple</b></label>
                            <input type="text" id="rcn-disciple-search" placeholder="Type a name..." />
                        </p>
                        
                        <p>
                            <label><b>Send to</b></label>
                            <select name="rcn_target">
                                <option value="all">All disciples</option>
                                <?php foreach ($disciples as $u): 
                                    $first = get_user_meta($u->ID, 'first_name', true);
                                    $last  = get_user_meta($u->ID, 'last_name', true);
                                    $name = trim($first . ' ' . $last) ?: $u->display_name;
                                ?>
                                    <option value="<?php echo (int)$u->ID; ?>"><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        
                        <p>
                            <label><b>Email subject (optional)</b></label>
                            <input type="text" name="rcn_subject" placeholder="Notification" />
                        </p>
                        
                        <p>
                            <label><b>Message</b></label>
                            <textarea name="rcn_message" rows="6" required placeholder="Type your message to disciples..."></textarea>
                        </p>
                        
                        <p>
                            <label><input type="checkbox" name="rcn_send_email" value="1" checked> Send email</label>
                            <label><input type="checkbox" name="rcn_log_in_app" value="1" checked> Log in-app notification</label>
                        </p>
                        
                        <p style="margin-top:16px;">
                            <button type="submit" class="rcn-btn-primary">Send Notification</button>
                        </p>
                    </form>
                </div>
            </div>
            
            <!-- SLIDE 2: Configuration -->
            <div class="rcn-slide">
                <div class="rcn-panel">
                    <h3>⚙️ Notification Settings</h3>
                    <p class="desc">
                        Configure your notification preferences and reminder schedules.
                    </p>
                    
                    <?php if ($config_msg): ?>
                        <div class="rcn-msg-success"><?php echo esc_html($config_msg); ?></div>
                    <?php endif; ?>
                    
                    <form method="post">
                        <?php wp_nonce_field('rcn_leader_settings', 'rcn_leader_settings_nonce'); ?>
                        
                        <!-- Leader Opt-In -->
                        <h4 style="margin:0 0 12px;font-size:15px;color:#374151;">📬 Your Preferences</h4>
                        <div class="rcn-setting-row">
                            <input type="checkbox" id="rcn_missed_alerts_optin" name="rcn_missed_alerts_optin" value="1" <?php checked($opted_in); ?> />
                            <label for="rcn_missed_alerts_optin">Receive email when disciples miss weekly submissions</label>
                        </div>
                        
                        <?php if ($is_admin): ?>
                        <!-- Admin-only: Schedule Configuration -->
                        <input type="hidden" name="rcn_schedule_config" value="1" />
                        
                        <div class="rcn-divider">
                            <h4 style="margin:0 0 12px;font-size:15px;color:#374151;">
                                ⏰ Reminder Schedule <span class="rcn-admin-badge">Admin</span>
                            </h4>
                            <p class="desc">Configure when automated reminders are sent to disciples.</p>
                            
                            <div class="rcn-setting-row">
                                <input type="checkbox" id="reminders_enabled" name="reminders_enabled" value="1" <?php checked(!empty($config['enabled'])); ?> />
                                <label for="reminders_enabled">Enable automatic missed submission reminders</label>
                            </div>
                            
                            <div class="rcn-schedule-grid">
                                <div class="rcn-schedule-item">
                                    <label>📅 First Reminder Day</label>
                                    <select name="reminder_day">
                                        <?php foreach ($day_labels as $num => $name): ?>
                                            <option value="<?php echo $num; ?>" <?php selected($config['reminder_day'], $num); ?>><?php echo $name; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="rcn-schedule-item">
                                    <label>🕐 Reminder Time</label>
                                    <select name="reminder_hour">
                                        <?php for ($h = 0; $h < 24; $h++): 
                                            $label = ($h === 0) ? "12:00 AM" : (($h < 12) ? "{$h}:00 AM" : (($h === 12) ? "12:00 PM" : ($h - 12) . ":00 PM"));
                                        ?>
                                            <option value="<?php echo $h; ?>" <?php selected($config['reminder_hour'], $h); ?>><?php echo $label; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="rcn-schedule-item">
                                    <label>📅 Follow-up Day</label>
                                    <select name="encouragement_day">
                                        <?php foreach ($day_labels as $num => $name): ?>
                                            <option value="<?php echo $num; ?>" <?php selected($config['encouragement_day'], $num); ?>><?php echo $name; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="rcn-schedule-item">
                                    <label>🕐 Follow-up Time</label>
                                    <select name="encouragement_hour">
                                        <?php for ($h = 0; $h < 24; $h++): 
                                            $label = ($h === 0) ? "12:00 AM" : (($h < 12) ? "{$h}:00 AM" : (($h === 12) ? "12:00 PM" : ($h - 12) . ":00 PM"));
                                        ?>
                                            <option value="<?php echo $h; ?>" <?php selected($config['encouragement_hour'], $h); ?>><?php echo $label; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <p style="font-size:12px;color:#9ca3af;margin-bottom:16px;">
                                Reminders are sent via WordPress cron (hourly check).
                            </p>
                            
                            <div class="rcn-test-buttons">
                                <button type="button" id="btn-test-monday" class="rcn-btn-test rcn-btn-monday">🧪 Test Reminder</button>
                                <button type="button" id="btn-test-friday" class="rcn-btn-test rcn-btn-friday">🧪 Test Follow-up</button>
                            </div>
                            <div id="test-results" style="margin-top:12px;display:none;">
                                <pre style="background:#1e293b;color:#e2e8f0;padding:12px;border-radius:8px;font-size:11px;overflow:auto;max-height:200px;"></pre>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <p style="margin-top:20px;">
                            <button type="submit" class="rcn-btn-secondary" style="width:100%;">Save Settings</button>
                        </p>
                    </form>
                </div>
            </div>
            
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    const tabs = document.querySelectorAll('.rcn-tab-btn');
    const track = document.getElementById('rcn-slider-track');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            if (this.dataset.tab === 'config') {
                track.classList.add('show-config');
            } else {
                track.classList.remove('show-config');
            }
        });
    });
    
    // Disciple search
    const search = document.getElementById('rcn-disciple-search');
    const select = document.querySelector('select[name="rcn_target"]');
    if (search && select) {
        search.addEventListener('input', function() {
            const q = this.value.toLowerCase();
            Array.from(select.options).forEach(opt => {
                if (opt.value === 'all') return;
                opt.style.display = opt.text.toLowerCase().includes(q) ? 'block' : 'none';
            });
        });
    }
    
    // Test buttons (admin only)
    const ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    
    async function runTest(action, btn) {
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Running...';
        
        const resultsDiv = document.getElementById('test-results');
        const resultsPre = resultsDiv?.querySelector('pre');
        if (resultsDiv) {
            resultsDiv.style.display = 'block';
            resultsPre.textContent = 'Running...';
        }
        
        try {
            const res = await fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=' + action
            });
            const json = await res.json();
            if (resultsPre) resultsPre.textContent = JSON.stringify(json.data || json, null, 2);
        } catch (err) {
            if (resultsPre) resultsPre.textContent = 'Error: ' + err.message;
        }
        
        btn.disabled = false;
        btn.textContent = originalText;
    }
    
    document.getElementById('btn-test-monday')?.addEventListener('click', function() {
        runTest('rcn_test_monday_cron', this);
    });
    document.getElementById('btn-test-friday')?.addEventListener('click', function() {
        runTest('rcn_test_friday_cron', this);
    });
});
</script>
    <?php
    return ob_get_clean();

});

/* -----------------------------------------
   HANDLER (safe redirects, no output)
------------------------------------------ */
add_action('admin_post_rcn_send_notifications_front', 'rcn_handle_send_notifications_front');

function rcn_handle_send_notifications_front() {

    if (!is_user_logged_in()) wp_die('Please log in.');

    $leader_cap = defined('RCN_LEADER_CAP') ? RCN_LEADER_CAP : 'access_leadership';
    if (!current_user_can('administrator') && !current_user_can($leader_cap)) {
        wp_die('Access denied.');
    }

    if (empty($_POST['rcn_send_notif_front_nonce']) ||
        !wp_verify_nonce($_POST['rcn_send_notif_front_nonce'], 'rcn_send_notif_front')
    ) {
        wp_die('Invalid request (nonce).');
    }

    $return = !empty($_POST['rcn_return']) ? esc_url_raw(wp_unslash($_POST['rcn_return'])) : wp_get_referer();
    if (!$return) $return = home_url('/');

    $target         = sanitize_text_field(wp_unslash($_POST['rcn_target'] ?? ''));
    $message        = trim(wp_unslash($_POST['rcn_message'] ?? ''));
    $custom_subject = trim(wp_unslash($_POST['rcn_subject'] ?? ''));

    $send_email = !empty($_POST['rcn_send_email']);
    $log_in_app = !empty($_POST['rcn_log_in_app']);

    if ($message === '') {
        wp_safe_redirect(add_query_arg('rcn_err', rawurlencode('Message cannot be empty.'), $return));
        exit;
    }

    // Ensure your template functions exist
    if (!function_exists('rcn_get_notification_templates') || !function_exists('rcn_render_template')) {
        wp_safe_redirect(add_query_arg('rcn_err', rawurlencode('Notification system is missing template functions.'), $return));
        exit;
    }

    // Optional functions: if missing, we still try not to fatal
    $can_log  = function_exists('rcn_log_notification');
    $can_mail = function_exists('rcn_send_email_notification');

    $disciple_role = defined('RCN_DISCIPLE_ROLE') ? RCN_DISCIPLE_ROLE : 'disciple';

    $templates = rcn_get_notification_templates();
    if (empty($templates['general'])) {
        wp_safe_redirect(add_query_arg('rcn_err', rawurlencode('General notification template not found.'), $return));
        exit;
    }

    $tpl = $templates['general'];

    $send_one = function(int $user_id) use (
        $message, $custom_subject, $send_email, $log_in_app,
        $tpl, $disciple_role, $can_log, $can_mail
    ) {
        $u = get_user_by('ID', $user_id);
        if (!$u) return false;

        // Only allow disciples
        if (!in_array($disciple_role, (array)$u->roles, true)) return false;

        $vars = [
            'name'    => $u->display_name,
            'message' => $message,
        ];

        $subject = rcn_render_template(($custom_subject !== '' ? $custom_subject : $tpl['subject']), $vars);
        $body    = rcn_render_template($tpl['body'], $vars);

        if ($log_in_app && $can_log) {
            rcn_log_notification($user_id, $tpl['type'], $body);
        }

        if ($send_email && $can_mail) {
            rcn_send_email_notification($user_id, $subject, $body);
        }

        return true;
    };

    $sent = 0;

    if ($target === 'all') {

        $all = get_users([
            'role'   => $disciple_role,
            'fields' => ['ID'],
            'number' => 50000,
        ]);

        foreach ($all as $u) {
            if ($send_one((int)$u->ID)) $sent++;
        }

        wp_safe_redirect(add_query_arg('rcn_sent', rawurlencode("Sent to {$sent} disciples."), $return));
        exit;
    }

    $uid = (int)$target;
    if ($uid <= 0 || !$send_one($uid)) {
        wp_safe_redirect(add_query_arg('rcn_err', rawurlencode('Invalid user selected (or user is not a disciple).'), $return));
        exit;
    }

    wp_safe_redirect(add_query_arg('rcn_sent', rawurlencode('Sent to 1 disciple.'), $return));
    exit;
}

/* =========================================
 * LEADER NOTIFICATION SETTINGS
 * =========================================
 * Allows leaders to opt-in to receive missed submission alerts.
 * Default is OPT-OUT (not receiving alerts).
 * 
 * Uses wp_options table: rcn_leader_missed_alerts = array of user_ids who opted in
 */

/**
 * Shortcode: [rcn_leader_notification_settings]
 * DEPRECATED: Now integrated into [rcn_send_notifications] with slide UI.
 * This shortcode is kept for backward compatibility - it redirects to the main shortcode.
 */
add_shortcode('rcn_leader_notification_settings', function() {
    // Redirect to the main unified shortcode
    return do_shortcode('[rcn_send_notifications]');
});

/**
 * Check if a leader has opted in to missed submission alerts.
 * Default is FALSE (opt-out by default).
 */
function rcn_is_leader_opted_in_missed_alerts($user_id) {
    $opted_in_users = get_option('rcn_leader_missed_alerts_optin', []);
    return in_array((int)$user_id, (array)$opted_in_users, true);
}

/**
 * Set leader opt-in status for missed submission alerts.
 */
function rcn_set_leader_missed_alerts_optin($user_id, $opt_in = true) {
    $opted_in_users = get_option('rcn_leader_missed_alerts_optin', []);
    if (!is_array($opted_in_users)) {
        $opted_in_users = [];
    }
    
    $user_id = (int)$user_id;
    
    if ($opt_in) {
        if (!in_array($user_id, $opted_in_users, true)) {
            $opted_in_users[] = $user_id;
        }
    } else {
        $opted_in_users = array_filter($opted_in_users, function($id) use ($user_id) {
            return (int)$id !== $user_id;
        });
    }
    
    update_option('rcn_leader_missed_alerts_optin', array_values($opted_in_users));
}

/**
 * Get all leaders who have opted in to missed submission alerts.
 */
function rcn_get_leaders_opted_in_missed_alerts() {
    $opted_in_ids = get_option('rcn_leader_missed_alerts_optin', []);
    if (empty($opted_in_ids)) {
        return [];
    }
    
    $leaders = [];
    foreach ($opted_in_ids as $user_id) {
        $user = get_user_by('ID', $user_id);
        if ($user) {
            // Verify they're still a leader
            $leader_cap = defined('RCN_LEADER_CAP') ? RCN_LEADER_CAP : 'access_leadership';
            if (user_can($user, 'administrator') || 
                user_can($user, $leader_cap) ||
                in_array('discipleship_leader', (array)$user->roles) ||
                in_array('leader', (array)$user->roles)) {
                $leaders[] = $user;
            }
        }
    }
    
    return $leaders;
}



