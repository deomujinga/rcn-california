/* == After a new user is created, set disciple meta == */
add_action('user_register', function($user_id) {
    $u = new WP_User($user_id);
    $u->set_role('disciple');

    // All disciple fields should already be stored by the registration form
    // But you can enforce defaults if needed:
    /*foreach ([
        'disciple_saved'    => 'unsure',
        'disciple_baptized' => 'no',
        'disciple_mentor'   => '',
        'disciple_why_join' => '',
        'disciple_status'   => 'inactive'
    ] as $key => $default) {
        if (!get_user_meta($user_id, $key, true)) {
            update_user_meta($user_id, $key, $default);
        }
    }*/

    update_user_meta($user_id, 'disciple_registered_at', current_time('mysql'));
});
