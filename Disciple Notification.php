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
   SHORTCODE UI (no sending logic here)
------------------------------------------ */
add_shortcode('rcn_send_notifications', function () {

    if (!is_user_logged_in()) return '<p>Please log in.</p>';

    $leader_cap = defined('RCN_LEADER_CAP') ? RCN_LEADER_CAP : 'access_leadership';
    if (!current_user_can('administrator') && !current_user_can($leader_cap)) {
        return '<p>Access denied.</p>';
    }

    $disciple_role = defined('RCN_DISCIPLE_ROLE') ? RCN_DISCIPLE_ROLE : 'disciple';

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

    ob_start(); ?>
<style>
/* === CARD / PANEL (like screenshot) === */
.rcn-send-notifications{
  max-width: 620px;           /* similar width to screenshot */
  margin: 0 auto;             /* centered */
  background: #fff;
  border-radius: 18px;
  padding: 36px 34px;
  border: 1px solid rgba(0,0,0,.04);
  box-shadow: 0 28px 70px rgba(0,0,0,.18);
}

/* Title spacing */
.rcn-send-notifications h3{
  margin: 0 0 18px 0 !important;
  font-size: 22px;
  font-weight: 800;
}

/* Space between field blocks */
.rcn-send-notifications form > p{
  margin: 0 0 16px 0;
}

/* Labels */
.rcn-send-notifications label{
  display:block;
  font-size: 13px;
  font-weight: 700;
  color: #374151;
  margin-bottom: 8px;
}

/* === INPUTS (light gray, rounded) === */
.rcn-send-notifications input[type="text"],
.rcn-send-notifications input[type="email"],
.rcn-send-notifications select,
.rcn-send-notifications textarea{
  width: 100% !important;
  background: #f7f7f9;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 16px 16px;
  font-size: 15px;
  color: #111827;
  outline: none;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.65);
}

/* Textarea height similar feel */
.rcn-send-notifications textarea{
  min-height: 140px;
  resize: vertical;
}

/* Focus state (soft ring like modern UI) */
.rcn-send-notifications input:focus,
.rcn-send-notifications select:focus,
.rcn-send-notifications textarea:focus{
  border-color: rgba(201,17,17,.55);
  box-shadow: 0 0 0 4px rgba(201,17,17,.12);
  background: #fff;
}

/* === CHECKBOX ROWS === */
.rcn-send-notifications input[type="checkbox"]{
  transform: translateY(1px);
  margin-right: 8px;
}
.rcn-send-notifications p label{
  font-weight: 600;
}

/* === BUTTON (full-width red gradient) === */
.rcn-send-notifications .rcn-btn-primary{
  width: 100% !important;
  padding: 18px !important;
  border-radius: 12px !important;
  font-size: 16px;
  font-weight: 700;
  letter-spacing: .3px;
  border: none !important;
  cursor: pointer;

  background: linear-gradient(135deg, #c91111, #9a0e0e) !important;
  color: #fff !important;

  box-shadow: 0 12px 26px rgba(201,17,17,.25);
  transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
}

.rcn-send-notifications .rcn-btn-primary:hover{
  transform: translateY(-1px);
  filter: brightness(1.04);
  box-shadow: 0 18px 34px rgba(201,17,17,.28);
}

.rcn-send-notifications .rcn-btn-primary:active{
  transform: translateY(0);
  box-shadow: inset 0 2px 5px rgba(0,0,0,.20);
}

.ff-btn-submit,
.rcn-send-notifications .rcn-btn-primary {
    width: 100%;
    padding: 18px;
    border-radius: 12px;
    font-size: 17px;
    font-weight: 600;
    background: linear-gradient(135deg, #c91111, #9a0e0e);
    color: #fff;
    cursor: pointer;
    transition: 0.25s ease;
}

</style>
								
    <div class="rcn-send-notifications" style="max-width: 760px;">
        <h3 style="margin-bottom:10px;">Send Notification</h3>

        <?php if ($sent_msg): ?>
            <div style="padding:10px;margin:10px 0;border:1px solid #46b450;background:#ecf7ed;">
                <?php echo esc_html($sent_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($err_msg): ?>
            <div style="padding:10px;margin:10px 0;border:1px solid #dc3232;background:#fde8e8;">
                <?php echo esc_html($err_msg); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('rcn_send_notif_front', 'rcn_send_notif_front_nonce'); ?>
            <input type="hidden" name="action" value="rcn_send_notifications_front" />
            <input type="hidden" name="rcn_return" value="<?php echo esc_url(get_permalink()); ?>" />

            <p>
				<p>
					<label><b>Search disciple</b></label><br/>
					<input type="text"
						   id="rcn-disciple-search"
						   placeholder="Type a name..."
						   style="width:100%;max-width:520px;" />
				</p>
                <label><b>Send to</b></label><br/>
                <select name="rcn_target" style="width:100%;max-width:520px;">
                    <option value="all">All disciples</option>
                    <?php foreach ($disciples as $u): ?>
                        <option value="<?php echo (int)$u->ID; ?>">
                            <?php
								$first = get_user_meta($u->ID, 'first_name', true);
								$last  = get_user_meta($u->ID, 'last_name', true);

								$name = trim($first . ' ' . $last);
								if ($name === '') {
									$name = $u->display_name; // fallback
								}

								echo esc_html($name);
							?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label><b>Email subject (optional)</b></label><br/>
                <input type="text" name="rcn_subject" style="width:100%;max-width:520px;" placeholder="Notification" />
            </p>

            <p>
                <label><b>Message</b></label><br/>
                <textarea name="rcn_message" rows="7" style="width:100%;" required
                    placeholder="Type your message to disciples..."></textarea>
            </p>

            <p style="margin-top:10px;">
                <label>
                    <input type="checkbox" name="rcn_send_email" value="1" checked>
                    Send email
                </label>
                <br/>
                <label>
                    <input type="checkbox" name="rcn_log_in_app" value="1" checked>
                    Log in-app notification
                </label>
            </p>

			<p style="margin-top:12px;">
				<button type="submit" class="rcn-btn-primary">Send</button>
			</p>
        </form>
    </div>
						<script>
document.addEventListener('DOMContentLoaded', function () {
    const search = document.getElementById('rcn-disciple-search');
    const select = document.querySelector('select[name="rcn_target"]');

    if (!search || !select) return;

    search.addEventListener('input', function () {
        const q = this.value.toLowerCase();

        Array.from(select.options).forEach(opt => {
            if (opt.value === 'all') return;

            opt.style.display = opt.text.toLowerCase().includes(q)
                ? 'block'
                : 'none';
        });
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



