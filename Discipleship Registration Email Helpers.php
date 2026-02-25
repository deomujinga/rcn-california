
/**
 * Discipleship — Emails (Async+Fallback) | Welcome (disciple) + Leadership Notice (leaders)
 * Paste into Code Snippets (run everywhere) or an MU plugin.
 */
if (!defined('ABSPATH')) exit;

/* ------------------------ Config ------------------------ */

// Async by default; will fall back to sync automatically if cron is off or scheduling fails.
if (!defined('DAS_EMAILS_ASYNC')) {
  define('DAS_EMAILS_ASYNC', true);
}

// Leadership dashboard URL
if (!defined('DAS_LEADERSHIP_DASH_URL')) {
  define('DAS_LEADERSHIP_DASH_URL', site_url('/leadership-dashboard/'));
}

/* ------------------------ Debug helper ------------------------ */

if (!function_exists('das_log')) {
  function das_log($msg){
    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log('[DiscipleshipEmails] ' . (is_scalar($msg) ? $msg : wp_json_encode($msg)));
    }
  }
}

/* ------------------------ Utilities ------------------------ */

if (!function_exists('das_email_headers')) {
  function das_email_headers(string $reply_to = '') : array {
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    if ($reply_to && is_email($reply_to)) $headers[] = 'Reply-To: ' . sanitize_email($reply_to);
    return $headers;
  }
}

if (!function_exists('das_email_wrap')) {
  function das_email_wrap(string $title, string $body_html, string $cta_text = '', string $cta_url = '', string $footer_html = '') : string {
    $btn = '';
    if ($cta_text && $cta_url) {
      $btn = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px auto 8px;">
        <tr><td align="center" bgcolor="#111111" style="border-radius:10px;">
          <a href="'.esc_url($cta_url).'" style="display:inline-block;padding:12px 18px;color:#ffffff;text-decoration:none;font-weight:700;border-radius:10px;border:1px solid #111111;">'
          . esc_html($cta_text) . '</a>
        </td></tr>
      </table>';
    }
    $footer = $footer_html ? '<div style="margin-top:18px;color:#6b7280;font-size:12px;">'.$footer_html.'</div>' : '';

    return '<!doctype html><html><body style="margin:0;background:#f7f7f7;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f7f7;padding:24px 12px;">
      <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:14px;border:1px solid #e5e7eb;overflow:hidden;">
          <tr><td style="padding:22px 22px 8px;text-align:center;">
            <h2 style="margin:0 0 6px;font:700 20px/1.3 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial;">'.esc_html($title).'</h2>
          </td></tr>
          <tr><td style="padding:0 22px 18px;color:#111111;font:400 14px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial;">
            '.$body_html.$btn.$footer.'
          </td></tr>
        </table>
        <div style="margin-top:10px;color:#9ca3af;font:12px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial;">'.esc_html( get_bloginfo('name') ).'</div>
      </td></tr>
    </table>
    </body></html>';
  }
}

if (!function_exists('das_send_disciple_approved')) {
  function das_send_disciple_approved(int $user_id) : bool {
    $u = get_userdata($user_id);
    if (!$u || !is_email($u->user_email)) { das_log('approved: invalid user'); return false; }

    $name = trim( ($u->first_name ?? '') . ' ' . ($u->last_name ?? '') );
    if (!$name) $name = $u->display_name ?: $u->user_login;

    // Your dashboard URL (adjust if different)
    $dashboard_url = site_url('/discipleship/');
    $title = 'You’ve Been Approved — Welcome!';

    $body = wpautop(
      'Hi ' . esc_html($name) . ',<br><br>' .
      'Good news, your discipleship application has been <strong>approved</strong>.<br><br>' .
      '<strong>Next steps</strong><br>' .
      '&#8226; Sign in to your disciple dashboard<br>' .
      '&#8226; Begin at your assigned level and follow the commitments<br><br>' .
      'If you have any questions, feel free to email us at discipleship@rcncalifornia.org.'
    );

    $footer = 'May God strengthen you as you grow in Christ.';

    $html    = das_email_wrap($title, $body, 'Open Dashboard', $dashboard_url, $footer);
    $headers = das_email_headers( get_option('admin_email') );

    $ok = wp_mail($u->user_email, $title, $html, $headers);
    das_log(['approved_sent' => $ok, 'to' => $u->user_email]);
    return $ok;
  }
}

if (!function_exists('das_user_field')) {
  function das_user_field($user_id, $key, $default='') {
    $val = get_user_meta($user_id, $key, true);
    return ($val !== '' && $val !== null) ? $val : $default;
  }
}

if (!function_exists('das_get_leader_emails')) {
  function das_get_leader_emails() : array {
    $emails = [];

    $leaders = get_users(['role'   => 'leader','fields' => ['ID', 'user_email', 'display_name'],]);
    foreach ($leaders as $u) if (!empty($u->user_email)) $emails[] = $u->user_email;
	
    $emails = array_values(array_unique(array_filter(array_map('sanitize_email', $emails))));
    return $emails;
  }
}

/* ------------------------ Outgoing emails ------------------------ */

if (!function_exists('das_send_disciple_welcome')) {
  function das_send_disciple_welcome(int $user_id) : bool {
    $u = get_userdata($user_id);
    if (!$u || !is_email($u->user_email)) { das_log('welcome: invalid user'); return false; }

    $name = trim( ($u->first_name ?? '') . ' ' . ($u->last_name ?? '') );
    if (!$name) $name = $u->display_name ?: $u->user_login;

    $signin_url  = site_url('/discipleship/?panel=login');
    $title       = 'Welcome to the Discipleship Program';

    $body  = wpautop(
      'Hi ' . esc_html($name) . ',<br><br>'
      . 'Thanks for registering! Your status is <strong>pending approval</strong>. '
      . 'We’ll review your application shortly. You can sign in anytime to check your status.<br><br>'
      . '<strong>Next steps</strong><br>'
      . '&#8226; Keep an eye on your email for updates<br>'
      . '&#8226; After approval, you’ll get access to your disciple dashboard'
    );
    $footer = 'If you didn’t create this account, you can ignore this email or contact us.';

    $html    = das_email_wrap($title, $body, 'Sign in', $signin_url, $footer);
    $headers = das_email_headers( get_option('admin_email') );

    $ok = wp_mail($u->user_email, $title, $html, $headers);
    das_log(['welcome_sent' => $ok, 'to' => $u->user_email]);
    return $ok;
  }
}

if (!function_exists('das_notify_leadership_new')) {
  function das_notify_leadership_new(int $user_id) : bool {
    $u = get_userdata($user_id);
    if (!$u) { das_log('leader_notice: invalid user'); return false; }

    $leaders = das_get_leader_emails();
    if (empty($leaders)) { das_log('leader_notice: no leader emails'); return false; }

    $name = trim( ($u->first_name ?? '') . ' ' . ($u->last_name ?? '') );
    if (!$name) $name = $u->display_name ?: $u->user_login;

    $users_admin_url = admin_url('users.php?s=' . rawurlencode($u->user_email));
    $edit_user_url   = admin_url('user-edit.php?user_id=' . $user_id);
  
	$saved    = das_user_field($user_id, 'disciple_saved', 'unsure');
	$baptized = das_user_field($user_id, 'disciple_baptized', 'no');
	$consent  = das_user_field($user_id, 'disciple_consent', '');
	$registered_at = das_user_field($user_id, 'disciple_registered_at', current_time('mysql'));
	$phone    = das_user_field($user_id, 'disciple_phone', '');
	$born_again = das_user_field($user_id, 'disciple_born_again', '');
	$born_date  = das_user_field($user_id, 'disciple_born_date', '');
	$spiritual_covering = das_user_field($user_id, 'disciple_spiritual_covering', '');
	$bible_reading = das_user_field($user_id, 'disciple_bible_reading', '');
	$fasting = das_user_field($user_id, 'disciple_fasting', '');
	$memorization = das_user_field($user_id, 'disciple_memorization', '');
	$morning_prayer = das_user_field($user_id, 'disciple_morning_prayer', '');
	$midnight_prayer = das_user_field($user_id, 'disciple_midnight_prayer', '');
	$bible_study = das_user_field($user_id, 'disciple_bible_study', '');
	$bible_study_other = das_user_field($user_id, 'disciple_bible_study_other', '');
	$commitment_duration = das_user_field($user_id, 'disciple_commitment_duration', '');
	$commitment_duration_other = das_user_field($user_id, 'disciple_commitment_duration_other', '');
	$agree_commitment = das_user_field($user_id, 'disciple_agree_commitment', '');
	$agree_commitment_other = das_user_field($user_id, 'disciple_agree_commitment_other', '');

    $title = 'New Discipleship Registration — ' . $name;

    ob_start(); ?>
      <div style="padding:10px 12px;margin:0 0 12px;border:1px dashed #e5e7eb;border-radius:10px;background:#fafafa;color:#374151;font-size:13px;">
        <strong>Leaders:</strong> A new disciple has registered. Review and take action below.
      </div>
	<table role="presentation" cellpadding="6" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:12px;width:100%;font-size:14px">
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Saved</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($saved); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Baptized</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($baptized); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Consent</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($consent ? 'Yes' : 'No'); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Status</strong></td><td style="border-bottom:1px solid #e5e7eb;">Inactive</td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Level</strong></td><td style="border-bottom:1px solid #e5e7eb;">1</td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Registered At</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($registered_at); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Phone</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($phone); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Born Again</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($born_again); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Born Date</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($born_date); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Spiritual Covering</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($spiritual_covering); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Bible Reading</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($bible_reading); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Fasting</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($fasting); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Memorization</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($memorization); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Morning Prayer</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($morning_prayer); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Midnight Prayer</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($midnight_prayer); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Bible Study</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($bible_study); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Bible Study (Other)</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($bible_study_other); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Commitment Duration</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($commitment_duration); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Commitment Duration (Other)</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($commitment_duration_other); ?></td></tr>
	  <tr><td style="border-bottom:1px solid #e5e7eb;"><strong>Agree Commitment</strong></td><td style="border-bottom:1px solid #e5e7eb;"><?php echo esc_html($agree_commitment); ?></td></tr>
	  <tr><td><strong>Agree Commitment (Other)</strong></td><td><?php echo esc_html($agree_commitment_other); ?></td></tr>
	</table>

	<p style="margin:14px 0 8px;">Quick action:</p>
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; text-align:center;">
	  <tr>
		<td style="padding:6px 4px;">
		  <a href="<?php echo esc_url(home_url('/discipleship-management/')); ?>" style="display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #111;background:#111;color:#fff;text-decoration:none;">
			Open Discipleship Management
		  </a>
		</td>
	  </tr>
	</table>	  
    <?php
    $body = ob_get_clean();

    //$html    = das_email_wrap($title, $body, 'Review in WordPress', $users_admin_url, '');
	$html = das_email_wrap($title, $body, '');
    $headers = das_email_headers( get_option('admin_email') );
    $subject = 'New Disciple: ' . $name . ' (' . $u->user_email . ')';

    $ok = wp_mail($leaders, $subject, $html, $headers);
    das_log(['leader_notice_sent' => $ok, 'to' => $leaders]);
    return $ok;
  }
}

/* ------------------------ Async orchestration ------------------------ */

// Cron worker
add_action('das_async_send_emails', function($user_id){
  das_log(['cron_fired_for' => $user_id]);
  try { das_send_disciple_welcome($user_id); } catch (\Throwable $e) { das_log('welcome exception: '.$e->getMessage()); }
  try { das_notify_leadership_new($user_id); } catch (\Throwable $e) { das_log('leader exception: '.$e->getMessage()); }
}, 10, 1);

// Admin manual trigger: /wp-admin/?das-test-email=<user_id>
add_action('admin_init', function(){
  if (!current_user_can('manage_options')) return;
  if (isset($_GET['das-test-email'])) {
    $uid = intval($_GET['das-test-email']);
    das_log(['manual_trigger' => $uid]);
    do_action('das_async_send_emails', $uid); // fire immediately
    wp_safe_redirect( remove_query_arg('das-test-email') );
    exit;
  }
});

/* Optional: admin warning if no leaders found */
add_action('admin_notices', function(){
  if (!current_user_can('manage_options')) return;
  $emails = das_get_leader_emails();
  if (empty($emails)) {
    echo '<div class="notice notice-warning"><p><strong>Discipleship:</strong> No leader/admin emails found. Check your "leader" role or the "access_leadership" capability.</p></div>';
  }
});

add_action('das_async_send_approved_email', function($user_id){
    try {
        das_send_disciple_approved($user_id);
        error_log('[DASDM] approved_email_sent user_id=' . (int)$user_id);
    } catch (\Throwable $e) {
        error_log('[DASDM] approved_email_exception user_id=' . (int)$user_id . ' msg=' . $e->getMessage());
    }
}, 10, 1);
