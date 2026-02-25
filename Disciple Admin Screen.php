/* == Admin menu + Discipleship management == */
add_action('admin_menu', function () {
    add_menu_page('Discipleship', 'Discipleship', 'manage_options', 'dap_root', function () {
        echo '<div class="wrap"><h1>Discipleship Management</h1><p>View and approve registered disciples below.</p></div>';
    }, 'dashicons-groups', 59);

    add_submenu_page('dap_root', 'Applications', 'Applications', 'manage_options', 'dap_apps', 'dap_render_apps_page');
});

/* == Handle approval/rejection == */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['dap_action'], $_GET['app'], $_GET['_wpnonce'])) return;

    $action = sanitize_key($_GET['dap_action']);
    $app_id = (int) $_GET['app'];
    if (!wp_verify_nonce($_GET['_wpnonce'], 'dap_app_' . $app_id)) return;

    if ($action === 'approve') {
        update_user_meta($app_id, 'disciple_status', 'active');
        wp_redirect(remove_query_arg(['dap_action', 'app', '_wpnonce']));
        exit;
    }
});

/* == Reject form handler (POST) == */
add_action('admin_post_dap_reject', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $app_id = (int) ($_POST['app'] ?? 0);
    check_admin_referer('dap_reject_' . $app_id);
    $reason = sanitize_text_field($_POST['dap_reason'] ?? '');
    update_user_meta($app_id, 'disciple_status', 'rejected');
    update_user_meta($app_id, 'disciple_reject_reason', $reason);
    wp_redirect(remove_query_arg(['dap_action', 'app', '_wpnonce'], wp_get_referer()));
    exit;
});

/* == Render list == */
function dap_render_apps_page() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $users = get_users(['role' => 'disciple']);
    echo '<div class="wrap"><h1>Disciple Applications</h1>';

    if (!$users) {
        echo '<p>No disciples found.</p></div>';
        return;
    }

    echo '<table class="widefat fixed striped"><thead><tr>
        <th>Name</th><th>Email</th><th>Saved</th><th>Baptized</th><th>Mentor</th><th>Status</th><th>Actions</th>
    </tr></thead><tbody>';

    foreach ($users as $u) {
        $saved  = get_user_meta($u->ID, 'disciple_saved', true);
        $bapt   = get_user_meta($u->ID, 'disciple_baptized', true);
        $mentor = get_user_meta($u->ID, 'disciple_mentor', true);
        $status = get_user_meta($u->ID, 'disciple_status', true);
        $nonce  = wp_create_nonce('dap_app_' . $u->ID);

        echo '<tr>';
        echo '<td>' . esc_html($u->display_name) . '</td>';
        echo '<td><a href="mailto:' . esc_attr($u->user_email) . '">' . esc_html($u->user_email) . '</a></td>';
        echo '<td>' . esc_html($saved) . '</td>';
        echo '<td>' . esc_html($bapt) . '</td>';
        echo '<td>' . esc_html($mentor) . '</td>';
        echo '<td>' . esc_html($status ?: 'inactive') . '</td>';
        echo '<td>';

        if ($status === 'inactive') {
            $approve = esc_url(add_query_arg(['dap_action' => 'approve', 'app' => $u->ID, '_wpnonce' => $nonce]));
            echo "<a class='button button-primary' href='$approve'>Approve</a> ";

            // Inline reject form
            $reject_action = admin_url('admin-post.php');
            echo "<form method='post' action='$reject_action' style='display:inline-block;margin-left:6px;'>
                    " . wp_nonce_field('dap_reject_' . $u->ID, '_wpnonce', true, false) . "
                    <input type='hidden' name='action' value='dap_reject'>
                    <input type='hidden' name='app' value='" . (int) $u->ID . "'>
                    <input type='text' name='dap_reason' placeholder='Reason (optional)' style='width:200px'>
                    <button class='button'>Reject</button>
                </form>";
        } elseif ($status === 'active') {
            echo 'Approved';
        } elseif ($status === 'rejected') {
            $reason = get_user_meta($u->ID, 'disciple_reject_reason', true);
            echo 'Rejected' . ($reason ? ' — ' . esc_html($reason) : '');
        }

        echo '</td></tr>';
    }

    echo '</tbody></table></div>';
}
