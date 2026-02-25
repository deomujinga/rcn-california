// Send users from wp-login.php?action=rp/resetpass to the front-end page.
add_action('login_init', function () {
    if (
        isset($_GET['action']) &&
        in_array($_GET['action'], ['rp', 'resetpass'], true) &&
        isset($_GET['key'], $_GET['login'])
    ) {
        // IMPORTANT: do NOT re-encode; WP already provides URL-safe values.
        $dest = add_query_arg(
            [
                'key'   => $_GET['key'],
                'login' => $_GET['login'],
            ],
            site_url('/set-new-password/')
        );
        wp_safe_redirect($dest);
        exit;
    }
});
