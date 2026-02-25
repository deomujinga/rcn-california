
/**
 * Plugin Name: WP Mail De-duplicator (Temp)
 * Description: Drops duplicate wp_mail sends within a short window by (to+subject).
 */

/*// 1) Kill core new-user emails (these are often the “mystery” extra)
add_filter('wp_send_new_user_notification_to_admin', '__return_false');
add_filter('wp_send_new_user_notification_to_user', '__return_false');

// 2) Hard de-dupe: if same (to,subject) was sent in last 3 minutes, skip sending
add_filter('pre_wp_mail', function($short_circuit, $atts) {
  $to      = isset($atts['to']) ? (array)$atts['to'] : [];
  $subject = isset($atts['subject']) ? (string)$atts['subject'] : '';
  if (!$to || $subject === '') return null; // let wp_mail proceed

  // Normalize recipients
  $flat_to = array_map('strtolower', array_map('trim', $to));
  sort($flat_to);
  $key = 'mail_dedupe_' . md5(implode(',', $flat_to) . '|' . $subject);

  if (get_transient($key)) {
    // Already sent very recently — treat as success and quietly skip
    error_log('[MAIL DEDUPE] Skipping duplicate: to=' . implode(',', $flat_to) . ' | subject=' . $subject);
    return true; // short-circuit wp_mail as if it succeeded
  }

  // Mark as sent for 3 minutes
  set_transient($key, 1, 3 * MINUTE_IN_SECONDS);
  return null; // continue to wp_mail
}, 10, 2);*/


add_filter('pre_wp_mail', function($short_circuit, $atts) {
  $to      = isset($atts['to']) ? (array)$atts['to'] : [];
  $subject = isset($atts['subject']) ? (string)$atts['subject'] : '';
  if (!$to || $subject === '') return null;

  // Only de-dupe Discipleship mails (adjust to your exact subjects)
  $whitelist = [
    'New Disciple Registration:',      // leadership notify
    'Welcome to the Discipleship Program', // disciple welcome (if enabled)
  ];
  $is_das = false;
  foreach ($whitelist as $needle) {
    if (stripos($subject, $needle) === 0) { $is_das = true; break; }
  }
  if (!$is_das) return null; // don't affect other emails

  $flat_to = array_map('strtolower', array_map('trim', $to));
  sort($flat_to);
  $key = 'mail_dedupe_' . md5(implode(',', $flat_to) . '|' . $subject);

  if (get_transient($key)) {
    error_log('[MAIL DEDUPE] Skipping duplicate: to=' . implode(',', $flat_to) . ' | subject=' . $subject);
    return true; // act as success, skip send
  }
  set_transient($key, 3, 3 * MINUTE_IN_SECONDS); // 3-min window
  return null;
}, 10, 2);
