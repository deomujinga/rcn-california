// Add this to Code Snippets

// Shortcode to get current user email
add_shortcode('current_user_email', function () {
    if (!is_user_logged_in()) {
        return 'Please log in to view your dashboard.';
    }
    $current_user = wp_get_current_user();
    return esc_html($current_user->user_email);
});

// Shortcode to get current user ID
add_shortcode('current_user_id', function () {
    if (!is_user_logged_in()) {
        return '';
    }
    return get_current_user_id();
});

// Shortcode to get current user display name
add_shortcode('current_user_name', function () {
    if (!is_user_logged_in()) {
        return '';
    }
    $current_user = wp_get_current_user();
    return esc_html($current_user->display_name);
});

// Function to get discipleship data as JSON for JavaScript
add_action('wp_ajax_get_discipleship_data', 'get_discipleship_data_ajax');
add_action('wp_ajax_nopriv_get_discipleship_data', 'get_discipleship_data_ajax');

function get_discipleship_data_ajax() {
    if (!is_user_logged_in()) {
        wp_die('Unauthorized');
    }

    $user_email = sanitize_email($_GET['email'] ?? '');
    if (!$user_email) {
        $current_user = wp_get_current_user();
        $user_email = $current_user->user_email;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'discipleship_commitments';

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE participant_id = %s ORDER BY date ASC",
        $user_email
    ), ARRAY_A);

    header('Content-Type: application/json');
    echo json_encode($results);
    wp_die();
}

// Function to get all discipleship data for leadership dashboard
add_action('wp_ajax_get_all_discipleship_data', 'get_all_discipleship_data_ajax');

function get_all_discipleship_data_ajax() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'discipleship_commitments';

    $results = $wpdb->get_results(
        "SELECT * FROM $table_name ORDER BY participant_id, date ASC",
        ARRAY_A
    );

    header('Content-Type: application/json');
    echo json_encode($results);
    wp_die();
}
