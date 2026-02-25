/* === PROTECT DISCIPLE PAGES BY STATUS (Gatekeeping) === */
add_action('template_redirect', function () {
  if (!is_user_logged_in()) return;

  $user = wp_get_current_user();
  if (!$user->has_cap('access_discipleship')) return;

  $status = get_user_meta($user->ID, 'disciple_status', true) ?: 'inactive';

  // Protect the main disciple dashboard (add more slugs if needed)
  if (is_page('disciple-dashboard')) {
    if ($status !== 'active') {
      if ($status === 'rejected') {
        wp_safe_redirect(site_url('/discipleship-rejected/')); exit;
      }
      wp_safe_redirect(site_url('/discipleship-pending/')); exit;
    }
  }
});
