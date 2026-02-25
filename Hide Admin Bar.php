/* == HIDE ADMIN AREA / BAR FOR NON-ADMINS == */
add_action('admin_init', function () {
  if (!current_user_can('manage_options') && !wp_doing_ajax()) {
    wp_redirect(home_url('/')); exit;
  }
});

add_filter('show_admin_bar', function ($show) {
  return current_user_can('manage_options') ? $show : false;
});
