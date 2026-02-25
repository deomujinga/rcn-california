/* == ALWAYS-ON PAGE ACCESS (Leaders/Admins: all pages; Disciples: disciple pages only) == */
add_action('template_redirect', function () {
  // Only front-end HTML requests
  if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;

  // Define which slugs are disciple-only
  $disciple_slugs = [
    'disciple-dashboard',
    'discipleship-progress-update',
  ];

  // Define which slugs are leadership-only (optional; keeps intent explicit)
  $leadership_slugs = [
    'leadership-dashboard',
  ];

  // If current request is not one of our protected pages, do nothing.
  $is_disciple_page   = array_reduce($disciple_slugs, fn($m,$s) => $m || is_page($s), false);
  $is_leadership_page = array_reduce($leadership_slugs, fn($m,$s) => $m || is_page($s), false);
  if (!$is_disciple_page && !$is_leadership_page) return;

  // Require login (bounces back here after login)
  if (!is_user_logged_in()) {
    auth_redirect();
    exit;
  }

  // Short-circuits: Admins & leaders can access everything
  if (current_user_can('manage_options') || current_user_can('access_leadership')) {
    return; // allow
  }

  // Disciples can ONLY access the disciple slugs
  if ($is_disciple_page && current_user_can('access_discipleship')) {
    return; // allow
  }

  // Everyone else: deny
  // wp_redirect(home_url('/')); exit;  // Redirect style
  status_header(403);
  wp_die(__('You do not have permission to view this page.'), 403);
});
