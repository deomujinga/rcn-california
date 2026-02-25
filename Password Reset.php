add_action('wp_ajax_nopriv_custom_forgot_password', 'custom_forgot_password_handler');
add_action('wp_ajax_custom_forgot_password', 'custom_forgot_password_handler');

function custom_forgot_password_handler() {
    $user_login = sanitize_text_field($_POST['user_login']);

    if (empty($user_login)) {
        wp_send_json_error('Please enter your email address.');
    }

    $user = get_user_by('email', $user_login);
    if (!$user) {
        wp_send_json_error('No user found with that email.');
    }

    $result = retrieve_password($user_login);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success('Check your email for the reset link.');
    }
}
