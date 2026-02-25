add_action('init', function () {
  if ($r = get_role('administrator')) { $r->add_cap('access_leadership'); }
  if ($r = get_role('editor'))        { $r->add_cap('access_leadership'); } // optional
  // if you have a custom 'leader' role:
  // if ($r = get_role('leader'))     { $r->add_cap('access_leadership'); }
});
