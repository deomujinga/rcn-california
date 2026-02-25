add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;

    if (!empty($_GET['run_rcn_migrate'])) {
        $result = rcn_migrate_attainment_history();
        wp_die("<pre>$result</pre>");
    }
});
