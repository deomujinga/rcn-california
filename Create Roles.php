/* == ROLES & CAPS (run once; then you can keep it active safely) == */
add_action('init', function () {
  // Create roles if not present
  if (!get_role('disciple')) add_role('disciple', 'Disciple', ['read' => true]);
  if (!get_role('leader'))   add_role('leader',   'Leader',   ['read' => true]);

  // Capabilities
  $caps = [
    'access_discipleship', // Disciple dashboard/page
    'access_leadership',   // Leadership dashboard/page
  ];

  // Assign caps
  foreach (['disciple','leader','administrator'] as $role_key) {
    if ($role = get_role($role_key)) {
      // Disciples: only discipleship
      if ($role_key === 'disciple') {
        $role->add_cap('access_discipleship');
      }
      // Leaders: both
      if ($role_key === 'leader' || $role_key === 'administrator') {
        $role->add_cap('access_discipleship');
        $role->add_cap('access_leadership');
      }
    }
  }
}, 12);
