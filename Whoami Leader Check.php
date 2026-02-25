// Quick checker: are you a leader/admin?
add_action('wp_ajax_whoami_leader', function () {
  if ( ! is_user_logged_in() ) wp_send_json_error('Not logged in', 401);

  $u = wp_get_current_user();
  $is_leader =
      user_can($u, 'manage_options') ||
      user_can($u, 'edit_others_posts') ||
      user_can($u, 'access_leadership') ||
      in_array('administrator', (array) $u->roles, true);

  wp_send_json([
    'email'      => strtolower(trim($u->user_email)),
    'roles'      => array_values((array)$u->roles),
    'is_leader'  => $is_leader,
    'caps_probe' => [
      'manage_options'     => user_can($u,'manage_options'),
      'edit_others_posts'  => user_can($u,'edit_others_posts'),
      'access_leadership'  => user_can($u,'access_leadership'),
    ],
  ]);
});
