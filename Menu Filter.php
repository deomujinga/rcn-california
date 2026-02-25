/* == MENU VISIBILITY BY CAP (Leadership + BOTH Disciple pages)
      + Hide BOTH disciple slugs from Leaders/Admins == */
add_filter('wp_nav_menu_objects', function ($items) {
  if (is_admin()) return $items; // don't hide in the editor

  // Slugs we care about
  $disciple_slugs  = ['disciple-dashboard', 'discipleship-progress-update'];
  $leadership_slug = 'leadership-dashboard';

  // Are we a leader/admin?
  $is_leader = is_user_logged_in() && ( current_user_can('access_leadership') || current_user_can('manage_options') );

  // Helper: extract URL path and test if it ends with a given slug
  $ends_with_slug = function ($url, $slug) {
    $path = parse_url((string)$url, PHP_URL_PATH) ?: '';
    $path = untrailingslashit($path);
    return preg_match('#/' . preg_quote($slug, '#') . '$#', $path) === 1;
  };

  foreach ($items as $i => $item) {
    $url = $item->url ?? '';

    // Leadership page — show only to leaders
    if ($ends_with_slug($url, $leadership_slug)) {
      if (!is_user_logged_in() || !current_user_can('access_leadership')) {
        unset($items[$i]);
        continue;
      }
    }

    // Any Disciple page (dashboard OR progress update)
    foreach ($disciple_slugs as $slug) {
      if ($ends_with_slug($url, $slug)) {

        // Leaders/Admins should NOT see either disciple link
        if ($is_leader) {
          unset($items[$i]);
          break;
        }

        // Otherwise, only Disciples see these links
        if (!is_user_logged_in() || !current_user_can('access_discipleship')) {
          unset($items[$i]);
        }
        break; // matched a disciple slug; no need to check the others
      }
    }
  }

  // Reindex so WP doesn't get gaps
  return array_values($items);
}, 20);
