add_action('das_async_send_emails', function($user_id){
  // duplicate guard (mirrors the call-site transient)
  if (get_transient('das_email_sent_'.$user_id)) return;
  set_transient('das_email_sent_'.$user_id, 1, 15 * MINUTE_IN_SECONDS);

  if (function_exists('das_send_disciple_welcome')) {
    try { das_send_disciple_welcome($user_id); } catch (\Throwable $e) { error_log('[Discipleship] welcome error: '.$e->getMessage()); }
  }
  if (function_exists('das_notify_leadership_new')) {
    try { das_notify_leadership_new($user_id); } catch (\Throwable $e) { error_log('[Discipleship] leader notify error: '.$e->getMessage()); }
  }
}, 10, 1);
