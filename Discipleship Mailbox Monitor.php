/*
Plugin Name: Discipleship Mailbox Monitor
Description: Admin dashboard to monitor mailbox activity via IMAP
Version: 1.0
Author: RCN Discipleship
*/

if (!defined('ABSPATH')) exit;

/* -------------------------
 * ENCRYPTION HELPERS
 * ------------------------- */
function dmm_secret_key() {
    return hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
}

function dmm_encrypt($plaintext) {
    if ($plaintext === '') return '';
    $key = dmm_secret_key();
    $iv  = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function dmm_decrypt($encoded) {
    if (!$encoded) return '';
    $raw = base64_decode($encoded);
    $iv  = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    return openssl_decrypt($cipher, 'AES-256-CBC', dmm_secret_key(), OPENSSL_RAW_DATA, $iv);
}

function dmm_table() {
    global $wpdb;
    return $wpdb->prefix . 'mailbox_monitor';
}

function dmm_handle_add_mailbox() {
    if (!current_user_can('view_mailbox_monitor')) return;

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['dmm_add_mailbox'])
    ) {
        check_admin_referer('dmm_add_mailbox');

        global $wpdb;

		$wpdb->insert(
			dmm_table(),
			[
				'email_address' => sanitize_email($_POST['email']),
				'imap_host'     => sanitize_text_field($_POST['host']),
				'imap_port'     => intval($_POST['port']),
				'imap_user'     => sanitize_text_field($_POST['user']),
				'imap_pass'     => dmm_encrypt($_POST['pass']),
				'status'        => 'unknown'
			],
			['%s','%s','%d','%s','%s','%s']
		);

		// ✅ REDIRECT after successful POST
		wp_redirect(add_query_arg('dmm_added', '1', wp_get_referer()));
		exit;
    }
}
add_action('init', 'dmm_handle_add_mailbox');

function dmm_handle_delete_mailbox() {
    if (!current_user_can('view_mailbox_monitor')) return;

    if (
        isset($_GET['dmm_delete']) &&
        isset($_GET['_wpnonce'])
    ) {
        $id = intval($_GET['dmm_delete']);
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'dmm_delete_' . $id)) {
            wp_die('Security check failed.');
        }

        global $wpdb;
        $wpdb->delete(dmm_table(), ['id' => $id], ['%d']);

        // Redirect back without the delete params
        $redirect_url = remove_query_arg(['dmm_delete', '_wpnonce']);
        $redirect_url = add_query_arg('dmm_deleted', '1', $redirect_url);
        wp_redirect($redirect_url);
        exit;
    }
}
add_action('init', 'dmm_handle_delete_mailbox');



/* -------------------------
 * ACTIVATE: CREATE DB TABLE
 * ------------------------- */
register_activation_hook(__FILE__, function () {
    global $wpdb;

    $table = $wpdb->prefix . 'mailbox_monitor';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        email_address VARCHAR(255) NOT NULL,
        imap_host VARCHAR(255) NOT NULL,
        imap_port INT NOT NULL DEFAULT 993,
        imap_user VARCHAR(255) NOT NULL,
        imap_pass LONGTEXT NOT NULL,
        unread_count INT NOT NULL DEFAULT 0,
        last_received_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'unknown',
        error_message LONGTEXT NULL,
        last_checked_at DATETIME NULL,
        PRIMARY KEY  (id)
    ) {$charset_collate};";

    dbDelta($sql);
});


/* -------------------------
 * CAPABILITIES
 * ------------------------- */
add_action('init', function () {
    // Grant to specific roles
    $roles = ['administrator', 'discipleship_leader', 'leader'];

    foreach ($roles as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            $role->add_cap('view_mailbox_monitor');
        }
    }
}, 15); // Priority 15 to run AFTER Create Roles.php (priority 12)

/**
 * Also grant view_mailbox_monitor to anyone with access_leadership capability
 * This ensures leaders can see it regardless of their specific role name
 */
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    // If checking for view_mailbox_monitor capability
    if (!in_array('view_mailbox_monitor', $caps, true)) {
        return $allcaps;
    }
    
    // If user already has it, skip
    if (!empty($allcaps['view_mailbox_monitor'])) {
        return $allcaps;
    }
    
    // Grant if user has access_leadership or is admin
    if (!empty($allcaps['access_leadership']) || !empty($allcaps['manage_options'])) {
        $allcaps['view_mailbox_monitor'] = true;
    }
    
    return $allcaps;
}, 10, 4);

/* -------------------------
 * ADMIN MENU
 * ------------------------- */
add_action('admin_menu', function () {
    if (!current_user_can('view_mailbox_monitor')) return;

    add_menu_page(
        'Mailbox Monitor',
        'Mailbox Monitor',
        'view_mailbox_monitor',
        'mailbox-monitor',
        'dmm_render_dashboard',
        'dashicons-email',
        56
    );
});

/* -------------------------
 * DASHBOARD VIEW (TEMP)
 * ------------------------- */
function dmm_render_dashboard() {
    if (!current_user_can('view_mailbox_monitor')) {
        return;
    }

    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM " . dmm_table() . " ORDER BY email_address");

	echo '<div class="dmm-container">';
	
    echo '<h2>Mailbox Monitor</h2>';

    /* ---- ADD MAILBOX FORM ---- */
	echo '<form method="post" class="dmm-form">';
	wp_nonce_field('dmm_add_mailbox');
	echo '
	<input type="hidden" name="dmm_add_mailbox" value="1">
	<input required type="email" name="email" placeholder="email@domain.com">
	<input required type="text" name="host" value="imap.hostinger.com">
	<input required type="number" name="port" value="993">
	<input required type="text" name="user" placeholder="IMAP username">
	<input required type="password" name="pass" placeholder="IMAP password">
	<button>Add Mailbox</button>
	</form>';
	
	if (isset($_GET['dmm_added'])) {
		echo '<div class="dmm-notice success">Mailbox added successfully.</div>';
	}
	if (isset($_GET['dmm_deleted'])) {
		echo '<div class="dmm-notice success">Mailbox removed successfully.</div>';
	}

    /* ---- MAILBOX TABLE ---- */
    if (!$rows) {
        echo '<p>No mailboxes added yet.</p>';
        return;
    }

    echo '<table class="dmm-table">
        <tr>
            <th>Email</th>
            <th>Status</th>
            <th>Unread</th>
            <th>Last Email</th>
            <th>Last Checked</th>
            <th>Error</th>
            <th>Actions</th>
        </tr>';

    foreach ($rows as $r) {
		
		$status_icon = match ($r->status) {
			'ok'    => '🟢 Active',
			'quiet' => '🟡 Quiet',
			'error' => '🔴 Error',
			default => '⚪ Unknown'
		};
		
		$error_display = '';
		if ($r->status === 'error' && !empty($r->error_message)) {
			$error_display = '<span class="dmm-error-msg" title="' . esc_attr($r->error_message) . '">' . esc_html(substr($r->error_message, 0, 50)) . (strlen($r->error_message) > 50 ? '...' : '') . '</span>';
		}
		
		$delete_url = wp_nonce_url(
			add_query_arg('dmm_delete', $r->id),
			'dmm_delete_' . $r->id
		);
		
	echo '<tr>
		<td>' . esc_html($r->email_address) . '</td>
		<td>' . $status_icon . '</td>
		<td>' . intval($r->unread_count) . '</td>
		<td>' . ($r->last_received_at ? esc_html($r->last_received_at) : '—') . '</td>
		<td>' . ($r->last_checked_at ? esc_html($r->last_checked_at) : '—') . '</td>
		<td>' . ($error_display ?: '—') . '</td>
		<td><a href="' . esc_url($delete_url) . '" class="dmm-delete-btn" onclick="return confirm(\'Are you sure you want to remove this mailbox?\');">🗑️ Remove</a></td>
	</tr>';

    }

    echo '</table>';
	
	echo '</div>';

}

add_action('admin_notices', function () {
    if (!function_exists('imap_open')) {
        echo '<div class="notice notice-error"><p><strong>PHP IMAP is NOT enabled.</strong> Mailbox Monitor will not work.</p></div>';
    }
});

function dmm_poll_mailboxes() {
    if (!current_user_can('view_mailbox_monitor')) return;

    global $wpdb;
    $table = dmm_table();

    $mailboxes = $wpdb->get_results("SELECT * FROM $table");

    if (!$mailboxes) return;

    foreach ($mailboxes as $box) {

        $status = 'ok';
        $error  = null;
        $unread = 0;
        $last_received = null;

        try {
            $password = dmm_decrypt($box->imap_pass);

            $mailbox_string = sprintf(
                '{%s:%d/imap/ssl}INBOX',
                $box->imap_host,
                $box->imap_port
            );

            $inbox = @imap_open(
                $mailbox_string,
                $box->imap_user,
                $password,
                OP_READONLY,
                1,
                ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
            );

            if (!$inbox) {
                throw new Exception(imap_last_error());
            }

			// Unread count (correct)
			$unseen = imap_search($inbox, 'UNSEEN');
			$unread = is_array($unseen) ? count($unseen) : 0;

			// Last received email (read OR unread)
			$emails = imap_search($inbox, 'ALL');

			if (is_array($emails) && !empty($emails)) {
				rsort($emails); // newest first
				$overview = imap_fetch_overview($inbox, $emails[0], 0);
				if (!empty($overview[0]->date)) {
					$last_received = date('Y-m-d H:i:s', strtotime($overview[0]->date));
				}
			}

            imap_close($inbox);

            // Status logic
            if ($last_received) {
                $hours = (time() - strtotime($last_received)) / 3600;
                if ($hours > 72) {
                    $status = 'quiet';
                }
            } else {
                $status = 'quiet';
            }

        } catch (Throwable $e) {
            $status = 'error';
            $error  = $e->getMessage();
        }

        // Update DB
        $wpdb->update(
            $table,
            [
                'unread_count'     => $unread,
                'last_received_at' => $last_received,
                'status'           => $status,
                'error_message'    => $error,
                'last_checked_at'  => current_time('mysql')
            ],
            ['id' => $box->id],
            ['%d','%s','%s','%s','%s'],
            ['%d']
        );
    }
}

add_action('wp_login', function ($user_login, $user) {
    if (user_can($user, 'view_mailbox_monitor')) {
        dmm_poll_mailboxes();
    }
}, 10, 2);

/* -------------------------
 * SHORTCODE: FRONTEND DASHBOARD
 * ------------------------- */

add_shortcode('dmm_mailbox_dashboard', function () {
    if (!is_user_logged_in() || !current_user_can('view_mailbox_monitor')) {
        return '';
    }

    // Poll inboxes once per page load
    dmm_poll_mailboxes();

    ob_start();
    dmm_render_dashboard();
    return ob_get_clean();
});

add_action('wp_head', function () {
    if (!current_user_can('view_mailbox_monitor')) return;
    ?>
    <style>
    /* --- Container --- */
    .dmm-container {
        max-width: 1000px;
        margin: 40px auto;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    }

    /* --- Headings --- */
    .dmm-container h2 {
        font-size: 26px;
        margin-bottom: 10px;
    }

    .dmm-container h3 {
        margin-top: 30px;
        margin-bottom: 10px;
    }

    /* --- Notice --- */
    .dmm-notice {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .dmm-notice.success {
        background: #e6f7ee;
        border: 1px solid #b7ebcd;
        color: #135200;
    }

    /* --- Form --- */
    .dmm-form {
        background: #fafafa;
        padding: 20px;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
    }

    .dmm-form input {
        padding: 10px;
        border-radius: 8px;
        border: 1px solid #ccc;
        font-size: 14px;
    }

	.dmm-form button {
		grid-column: 1 / -1;
		padding: 14px;
		border-radius: 14px;
		background: linear-gradient(135deg, #c91111, #9a0e0e);
		color: #ffffff;
		font-size: 15px;
		font-weight: 600;
		border: none;
		cursor: pointer;
		transition: all 0.18s ease;
		box-shadow: 0 10px 24px rgba(201, 17, 17, 0.38);
	}

	.dmm-form button:active {
		transform: translateY(0);
		box-shadow: 0 6px 14px rgba(201, 17, 17, 0.35);
	}

	 .dmm-form button:hover {
		background: linear-gradient(135deg, #b10f0f, #7f0b0b);
		box-shadow: 0 14px 32px rgba(177, 15, 15, 0.5);
		transform: translateY(-1px);
	}
									
	.dmm-form button:active {
	background: linear-gradient(135deg, #9a0e0e, #6e0a0a);
	box-shadow: 0 6px 16px rgba(154, 14, 14, 0.45);
	transform: translateY(0);
	}

    /* --- Table --- */
    .dmm-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 30px;
        background: white;
        border-radius: 12px;
        overflow: hidden;
    }

    .dmm-table th {
        background: #f3f4f6;
        text-align: left;
        padding: 12px;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .dmm-table td {
        padding: 14px 12px;
        border-top: 1px solid #eee;
        font-size: 14px;
    }

    .dmm-table tr:hover {
        background: #fafafa;
    }
    
    .dmm-error-msg {
        color: #b91c1c;
        font-size: 12px;
        cursor: help;
        display: inline-block;
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .dmm-error-msg:hover {
        white-space: normal;
        word-break: break-word;
    }
    
    .dmm-delete-btn {
        display: inline-block;
        padding: 6px 12px;
        background: #fee2e2;
        color: #991b1b;
        border-radius: 6px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.15s ease;
    }
    
    .dmm-delete-btn:hover {
        background: #fecaca;
        color: #7f1d1d;
    }

    </style>
    <?php
});

