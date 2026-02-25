/**
 * Discipleship Slider — Inline Registration (Success Panel) + Early Login + Terms Modal
 * Shortcodes:
 *   [discipleship_auth_slider]
 *   [disciple_pending_notice]
 *   [disciple_reject_reason]
 */

if (!defined('ABSPATH')) exit;

/* =====================================================
   ROLE BOOTSTRAP
   ===================================================== */
add_action('init', function () {
    if (!get_role('disciple')) {
        add_role('disciple', 'Disciple', [
            'read' => true,
            'access_discipleship' => true,
        ]);
    }
});

/* =====================================================
   BASE URL HELPER
   ===================================================== */
function das_base_url() {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    $url    = $scheme . $host . $uri;
    $strip  = [
        'das_action',
        'das_login_nonce',
        'das_register_nonce',
        'panel',
        'das_ok',
        'das_err',
        'login',
        'reason',
        'reg',
        'msg'
    ];
    return remove_query_arg($strip, $url);
}

/* =====================================================
   STYLES (Inline + Modal + Responsive)
   ===================================================== */
add_action('wp_head', function () {
    ?>
    <style>
        /* ======================
           Core Container
           ====================== */
        .das-container {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .06);
            position: relative;
            overflow: hidden;
            width: 980px;
            max-width: 100%;
            min-height: 720px;
            margin: 32px auto;
            border: 1px solid #e5e7eb;
        }

        /* ======================
           Toast (Auto-Fade)
           ====================== */
        .das-toast {
            position: absolute;
            top: 12px;
            left: 50%;
            transform: translateX(-50%);
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 14px;
            z-index: 200;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s ease, transform .25s ease;
        }

        .das-toast.das-ok {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .das-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .das-toast.das-err {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* ======================
           Panels / Forms
           ====================== */
        .form-container {
            position: absolute;
            top: 0;
            height: 100%;
            transition: all .6s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50%;
        }

        .sign-in-container {
            left: 0;
            z-index: 2;
        }

        .sign-up-container {
            right: 0;
            opacity: 0;
            z-index: 1;
            transform: translateX(100%);
        }

        .das-container.right-panel-active .sign-in-container {
            transform: translateX(-100%);
        }

        .das-container.right-panel-active .sign-up-container {
            transform: translateX(0);
            opacity: 1;
            z-index: 5;
        }

        /* ======================
           Overlay (Desktop)
           ====================== */
        .overlay-container {
            position: absolute;
            top: 0;
            left: 50%;
            width: 50%;
            height: 100%;
            overflow: hidden;
            transition: transform .6s ease-in-out;
            z-index: 100;
        }

        .overlay {
            background: linear-gradient(135deg, #b91c1c, #fbbf24);
            color: #fff;
            position: relative;
            left: -100%;
            height: 100%;
            width: 200%;
            transform: translateX(0);
            transition: transform .6s ease-in-out;
        }

        .overlay-panel {
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 0 40px;
            text-align: center;
            top: 0;
            height: 100%;
            width: 50%;
        }

        .overlay-left {
            left: 0;
            transform: translateX(-20%);
        }

        .overlay-right {
            right: 0;
            transform: translateX(0);
        }

        .das-container.right-panel-active .overlay-container {
            transform: translateX(-100%);
        }

        .das-container.right-panel-active .overlay {
            transform: translateX(50%);
        }

        .das-container.right-panel-active .overlay-left {
            transform: translateX(0);
        }

        .das-container.right-panel-active .overlay-right {
            transform: translateX(20%);
        }

        .ghost {
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid #fff;
            background: transparent;
            color: #fff;
            cursor: pointer;
            transition: all .25s ease;
        }

        .ghost:hover {
            background: #fff;
            color: #b91c1c;
            border-color: #fff;
        }

        /* ======================
           Form Internals
           ====================== */
        form {
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: stretch;
            justify-content: center;
            padding: 0 40px;
            width: 100%;
            max-width: 520px;
            color: #111;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="tel"],
        textarea,
        select {
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            width: 100%;
        }

        .das-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .das-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            font-size: 13px;
        }

        .das-label {
            display: block;
            margin-top: 16px;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #111;
        }

        .das-small {
            font-size: 13px;
            text-align: center;
        }

        .das-remember {
            font-size: 13px;
            color: #374151;
        }

        /* ======================
           Buttons & Links
           ====================== */
        .das-btn {
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #111;
            background: #111;
            color: #fff;
            cursor: pointer;
            transition: all .25s ease;
        }

        .das-btn:hover {
            background: #b91c1c;
            border-color: #b91c1c;
            color: #fff;
        }

        .das-secondary {
            background: #fff;
            color: #111;
            border: 1px solid #111;
        }

        a,
        .das-small a {
            color: #b91c1c;
            transition: color .25s ease;
        }

        a:hover,
        .das-small a:hover {
            color: #111;
        }

        /* ======================
           Focus Ring
           ====================== */
        .das-btn:focus,
        .ghost:focus,
        .das-mobile-tab:focus,
        form input:focus,
        form textarea:focus,
        form button:focus {
            outline: none;
        }

        .das-btn:focus-visible,
        .ghost:focus-visible,
        .das-mobile-tab:focus-visible {
            outline: 2px solid #b91c1c;
            outline-offset: 2px;
        }

        form input:focus-visible,
        form textarea:focus-visible {
            outline: 2px solid #b91c1c;
            outline-offset: 2px;
            box-shadow: 0 0 0 3px rgba(185, 28, 28, .12);
            border-color: #b91c1c;
        }

        * {
            -webkit-tap-highlight-color: rgba(0, 0, 0, 0);
        }

        /* ======================
           Success Panel
           ====================== */
        .das-success {
            max-width: 520px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-left: 6px solid #111;
            border-radius: 14px;
            padding: 18px 18px 16px;
            text-align: center;
            color: #111;
        }

        .das-success h3 {
            margin: .25rem 0 .5rem;
            font-size: 1.25rem;
        }

        .das-success p {
            margin: 0 0 .75rem;
            color: #374151;
        }

        .das-success .das-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: .25rem;
        }

        /* ======================
           Mobile Tabs
           ====================== */
        .das-mobile-tabs {
            display: none;
        }

        @media (max-width: 900px) {
            .overlay-container {
                display: none !important;
            }

            .das-container {
                width: 100%;
                min-height: unset;
                padding: 20px 0;
                border-radius: 20px;
            }

            .form-container {
                position: relative;
                width: 100%;
                height: auto;
                transform: none !important;
            }

            .sign-in-container,
            .sign-up-container {
                left: auto;
                right: auto;
                opacity: 0;
                z-index: 1;
                display: none;
            }

            .das-container.mobile-login .sign-in-container {
                display: flex;
                opacity: 1;
                z-index: 2;
                animation: dasFade .25s ease;
            }

            .das-container.mobile-register .sign-up-container {
                display: flex;
                opacity: 1;
                z-index: 2;
                animation: dasFade .25s ease;
            }

            .das-mobile-tabs {
                display: flex;
                gap: 8px;
                justify-content: center;
                margin: 8px 0 10px;
            }

            .das-mobile-tab {
                padding: 8px 14px;
                border-radius: 999px;
                border: 1px solid #e5e7eb;
                background: #fff;
                cursor: pointer;
                font-size: 14px;
            }

            .das-mobile-tab.active {
                background: #111;
                color: #fff;
                border-color: #111;
            }
        }

        @keyframes dasFade {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ======================
           Terms Modal
           ====================== */
        .das-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, .55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: saturate(120%) blur(2px);
        }

        .das-modal-overlay.show {
            display: flex;
            animation: dasModalFade .18s ease;
        }

        @keyframes dasModalFade {
            from {
                opacity: .0;
                transform: scale(.98);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .das-modal {
            width: min(920px, 92vw);
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .18);
            overflow: hidden;
        }

        .das-modal header {
            padding: 18px 22px;
            background: linear-gradient(135deg, #b91c1c, #fbbf24);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .das-modal header h3 {
            margin: 0;
            font-size: 18px;
        }

        .das-modal header button {
            background: transparent;
            color: #fff;
            border: 0;
            font-size: 20px;
            cursor: pointer;
            line-height: 1;
        }

        .das-modal .das-modal-body {
            padding: 18px 22px;
            max-height: min(65vh, 560px);
            overflow: auto;
            color: #111;
        }

        .das-modal .das-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding: 16px 22px;
            border-top: 1px solid #f3f4f6;
        }

        .das-btn-ghost {
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #111;
            background: #fff;
            color: #111;
            cursor: pointer;
            transition: all .25s ease;
        }

        .das-btn-ghost:hover {
            background: #111;
            color: #fff;
        }

        .das-terms h4 {
            margin: 0 0 .4rem;
            color: #b91c1c;
        }

        .das-terms h5 {
            margin: 1rem 0 .35rem;
            color: #111;
        }

        .das-terms blockquote {
            border-left: 4px solid #b91c1c;
            padding-left: 12px;
            color: #374151;
            margin: 8px 0;
        }

        .das-terms ul,
        .das-terms ol {
            margin: .25rem 0 1rem 1.25rem;
            line-height: 1.6;
        }

        .das-terms p {
            margin: .5rem 0;
        }

        /* ======================
           Progress Bar
           ====================== */
        .das-progress-minimal {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 12px 0 24px;
        }

        .das-progress-minimal .dash {
            width: 40px;
            height: 5px;
            border-radius: 3px;
            background: #e5e7eb;
            transition: all .25s ease;
        }

        .das-progress-minimal .dash.active {
            background: linear-gradient(135deg, #b91c1c, #fbbf24);
        }

        .das-step {
            display: none;
            animation: fadeIn .25s ease;
        }

        .das-step.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .das-step-title {
            text-align: center;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: #111;
        }

        .das-nav {
            display: flex;
            justify-content: space-between;
            margin-top: 22px;
        }
										
		/* === Reset Password Panel (slides in from the left) === */
		.reset-password-container {
		  position: absolute;
		  top: 0;
		  left: 0;
		  height: 100%;
		  width: 50%;
		  transition: all 0.6s ease-in-out;
		  opacity: 0;
		  z-index: 1;
		  /* Start just to the LEFT of the sign-in card */
		  transform: translateX(-100%);
		  background:#fff;
		  display:flex;
		  align-items:center;
		  justify-content:center;
		  flex-direction:column;
		  padding:0 50px;
		  text-align:center;
		}

		/* When reset is active: move the sign-in card left, reveal reset card from left */
		#das-container.right-panel-reset .sign-in-container {
		  transform: translateX(-100%);
		}

		#das-container.right-panel-reset .reset-password-container {
		  transform: translateX(0);
		  opacity: 1;
		  z-index: 5;
		}

		/* Links row under login */
		.das-login-links {
		  margin-top: 10px;
		  font-size: 14px;
		}

		.das-login-links a {
		  /* Same color as your "Create account" links elsewhere */
		  color: #b91c1c;
		  text-decoration: none;
		  margin-right: 10px;
		}

		.das-login-links a:hover {
		  color:#111;
		  text-decoration: underline;
		}

		/* Back link: black as requested */
		.das-back-link {
		  margin-top: 16px;
		  display: inline-block;
		  color: #111;
		  text-decoration: none;
		  font-weight: 500;
		}
		.das-back-link:hover {
		  text-decoration: underline;
		}
										
		.das-login-links {
		  display: flex;
		  flex-direction: column;       /* stack vertically */
		  align-items: center;          /* center horizontally */
		  justify-content: center;      /* optional vertical centering if container has height */
		  margin-top: 14px;
		  gap: 6px;                     /* space between the two lines */
		  font-size: 14px;
		  text-align: center;
		}

		.das-login-links a {
		  color: #b91c1c;               /* match your Create account color */
		  text-decoration: none;
		  font-weight: 500;
		}

		.das-login-links a:hover {
		  color: #111;
		  text-decoration: underline;
		}
										
		/* === Responsive Alignment + Fade-in Fixes === */
		.das-container {
		  display: flex;
		  align-items: center;
		  justify-content: center;
		  flex-direction: column;
		}

		/* For mobile & tablet: disable slide, use fade */
		@media (max-width: 900px) {
		  .overlay-container {
			display: none !important;
		  }

		  .das-container {
			width: 100%;
			min-height: unset;
			padding: 30px 0;
			border-radius: 20px;
		  }

		  .form-container {
			position: relative;
			width: 100%;
			height: auto;
			transform: none !important;
			display: none;
			opacity: 0;
			transition: opacity 0.3s ease-in-out;
		  }

		  .das-container.mobile-login .sign-in-container,
		  .das-container.mobile-register .sign-up-container,
		  .das-container.mobile-reset .reset-password-container {
			display: flex;
			opacity: 1;
			animation: fadeIn 0.3s ease-in-out forwards;
		  }

		  @keyframes fadeIn {
			from { opacity: 0; transform: translateY(6px); }
			to { opacity: 1; transform: none; }
		  }
		}

    </style>
    <?php
});

/* =====================================================
   EARLY LOGIN HANDLER
   ===================================================== */
add_action('template_redirect', function () {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (($_POST['das_action'] ?? '') !== 'das_login') return;

    $base = das_base_url();

    if (!wp_verify_nonce($_POST['das_login_nonce'] ?? '', 'das_login')) {
        wp_safe_redirect(add_query_arg([
            'panel' => 'login',
            'das_err' => rawurlencode('Security check failed. Try again.')
        ], $base));
        exit;
    }

    $id       = sanitize_text_field($_POST['das_email'] ?? '');
    $pw       = $_POST['das_password'] ?? '';
    $remember = !empty($_POST['das_remember']);

    if ($id === '' || $pw === '') {
        wp_safe_redirect(add_query_arg([
            'panel' => 'login',
            'login' => 'failed',
            'reason' => 'empty'
        ], $base));
        exit;
    }

    if (is_email($id)) {
        $u = get_user_by('email', $id);
        if ($u) $id = $u->user_login;
    }

    $user = wp_signon([
        'user_login'    => $id,
        'user_password' => $pw,
        'remember'      => $remember
    ], is_ssl());

    if (is_wp_error($user)) {
        wp_safe_redirect(add_query_arg([
            'panel' => 'login',
            'login' => 'failed'
        ], $base));
        exit;
    }

    $target = apply_filters('login_redirect', home_url('/'), '', $user);
    wp_safe_redirect($target);
    exit;
});
			
/* =====================================================
   EMAIL DISPATCH HANDLER (AJAX)
===================================================== */

			/* ===== EMAIL DISPATCH (ASYNC) ===== */
			if (!function_exists('das_async_email_token')) {
				function das_async_email_token($user_id)
				{
					return hash_hmac('sha256', 'das_async_email|' . (int)$user_id, wp_salt('auth'));
				}
			}

			/* ===== AJAX HANDLER: Send emails to user + leaders ===== */
			add_action('wp_ajax_nopriv_das_async_email_now', 'das_async_email_now');
			add_action('wp_ajax_das_async_email_now', 'das_async_email_now');

			function das_async_email_now() {

				global $wpdb; 

				$uid   = isset($_POST['uid']) ? (int) $_POST['uid'] : 0;
				$token = sanitize_text_field($_POST['token'] ?? '');

				if (!$uid || !$token) {
					wp_die('missing params');
				}

				// Verify token to ensure this request is valid
				$expected = hash_hmac('sha256', 'das_async_email|' . (int)$uid, wp_salt('auth'));
				if (!hash_equals($expected, $token)) {
					wp_die('bad token');
				}

				// Get user info
				$user = get_userdata($uid);
				if (!$user) {
					wp_die('no user');
				}

				$user_email = $user->user_email;
				$user_name  = $user->display_name ?: $user->user_login;

				/*// === Send email to the new user ===
				$subject_leader = 'New Disciple Registered';
				$message_leader = "A new disciple has registered.\n\nName: {$user_name}\nEmail: {$user_email}\n\nYou may log in to review their profile.";

				$leaders = get_users([
					'role'   => 'leader',
					'fields' => ['ID', 'user_email', 'display_name'],
				]);

				foreach ($leaders as $leader) {
					wp_mail($leader->user_email, $subject_leader, $message_leader);
					error_log('[DAS] Sent to leader ' . $leader->user_email);
				}*/
				
				if (function_exists('das_send_disciple_welcome')) {
					
					das_send_disciple_welcome($uid);
					error_log('[DAS] Sent disciple welcome email for user ' . $uid);
					
				} else {
					
					error_log('[DAS] Missing helper: das_send_disciple_welcome');
				}

				if (function_exists('das_notify_leadership_new')) {
					
					das_notify_leadership_new($uid);
					error_log('[DAS] Sent leader notification email for user ' . $uid);
					
				} else {
					
					error_log('[DAS] Missing helper: das_notify_leadership_new');
					
				}

				wp_die('ok');
			}			

/* =====================================================
   SHORTCODE: [discipleship_auth_slider]
   ===================================================== */
add_shortcode('discipleship_auth_slider', function () {
    $panel       = isset($_GET['panel']) ? sanitize_key($_GET['panel']) : 'login';
    $toast       = '';
    $toast_type  = '';
    $reg_success = false;

    // Login messages
    $login_q  = isset($_GET['login']) ? sanitize_text_field(wp_unslash($_GET['login'])) : '';
    $reason_q = isset($_GET['reason']) ? sanitize_text_field(wp_unslash($_GET['reason'])) : '';

    if ($login_q === 'failed') {
        $toast = ($reason_q === 'empty')
            ? 'Please enter your username and password.'
            : 'Invalid username or password.';
        $toast_type = 'das-err';
        $panel      = 'login';
    }

    if (isset($_GET['das_err'])) {
        $toast = sanitize_text_field(wp_unslash($_GET['das_err']));
        $toast_type = 'das-err';
    }

    if (isset($_GET['das_ok'])) {
        $toast = sanitize_text_field(wp_unslash($_GET['das_ok']));
        $toast_type = 'das-ok';
    }

    /* =====================================================
       REGISTRATION HANDLER
       ===================================================== */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['das_action'] ?? '') === 'das_register') {
        if (!wp_verify_nonce($_POST['das_register_nonce'] ?? '', 'das_register')) {
            $toast       = 'Security check failed. Please reload and try again.';
            $toast_type  = 'das-err';
            $panel       = 'register';
        } else {
            $email    = sanitize_email($_POST['das_reg_email'] ?? '');
            $pw1      = $_POST['das_reg_password'] ?? '';
            $pw2      = $_POST['das_reg_confirm'] ?? '';
            $fn       = sanitize_text_field($_POST['das_reg_fname'] ?? '');
            $ln       = sanitize_text_field($_POST['das_reg_lname'] ?? '');
            $saved    = sanitize_text_field($_POST['das_reg_saved'] ?? 'unsure');
            $baptized = sanitize_text_field($_POST['das_reg_baptized'] ?? 'no');
            $mentor   = sanitize_text_field($_POST['das_reg_mentor'] ?? '');
            $why_join = wp_kses_post($_POST['das_reg_why'] ?? '');
            $consent  = !empty($_POST['das_reg_consent']);

            if (!$email || !is_email($email)) {
                $toast = 'Enter a valid email.';
                $toast_type = 'das-err';
                $panel = 'register';
            } elseif (email_exists($email)) {
                $toast = 'Email already registered.';
                $toast_type = 'das-err';
                $panel = 'register';
            } elseif (strlen($pw1) < 8) {
                $toast = 'Password must be at least 8 characters.';
                $toast_type = 'das-err';
                $panel = 'register';
            } elseif ($pw1 !== $pw2) {
                $toast = 'Passwords do not match.';
                $toast_type = 'das-err';
                $panel = 'register';
            } elseif (!$consent) {
                $toast = 'Please agree to the discipleship commitment.';
                $toast_type = 'das-err';
                $panel = 'register';
            } else {
                // Username logic
                $base = sanitize_user(strtolower($fn), true);
                if (!$base)
                    $base = sanitize_user(current(explode('@', $email)), true) ?: 'disciple';

                $username = $base;
                $i = 1;
                while (username_exists($username)) {
                    $username = $base . $i++;
                }
				
                $uid = wp_create_user($username, $pw1, $email);
				error_log('[DAS] Created user ID: ' . print_r($uid, true));

                if (is_wp_error($uid)) {
					error_log('[DAS] ERROR during user creation: ' . $uid->get_error_message());

                    $toast = $uid->get_error_message();
                    $toast_type = 'das-err';
                    $panel = 'register';
                } else {
					
					error_log('[DAS] Updating user ID: ' . print_r($uid, true));
					
                    // Update meta
                    wp_update_user([
                        'ID' => $uid,
                        'first_name' => $fn,
                        'last_name' => $ln,
                        'display_name' => trim("$fn $ln") ?: $username
                    ]);

                    $u = new WP_User($uid);
					
                    $u->set_role('disciple');
					
					error_log('[DAS] >>> Entering meta update section <<<');
					
					update_user_meta($uid, 'disciple_saved', $saved);
					update_user_meta($uid, 'disciple_baptized', $baptized);
					//update_user_meta($uid, 'disciple_mentor', $mentor);
					//update_user_meta($uid, 'disciple_why_join', $why_join);
					//update_user_meta($uid, 'disciple_consent', $consent ? 'yes' : 'no');
					update_user_meta($uid, 'disciple_status', 'inactive');                    
					update_user_meta($uid, 'disciple_level', '1');
					update_user_meta($uid, 'disciple_registered_at', current_time('mysql'));
					update_user_meta($uid, 'disciple_phone', sanitize_text_field($_POST['das_reg_phone'] ?? ''));
					update_user_meta($uid, 'disciple_born_again', sanitize_text_field($_POST['das_born_again'] ?? ''));
					update_user_meta($uid, 'disciple_born_date', sanitize_text_field($_POST['das_born_date'] ?? ''));
					update_user_meta($uid, 'disciple_spiritual_covering', sanitize_text_field($_POST['das_spiritual_covering'] ?? ''));
					update_user_meta($uid, 'disciple_bible_reading', sanitize_text_field($_POST['das_bible_reading'] ?? ''));
					update_user_meta($uid, 'disciple_fasting', sanitize_text_field($_POST['das_fasting'] ?? ''));
					update_user_meta($uid, 'disciple_memorization', sanitize_text_field($_POST['das_memorization'] ?? ''));
					update_user_meta($uid, 'disciple_morning_prayer', sanitize_text_field($_POST['das_morning_prayer'] ?? ''));
					update_user_meta($uid, 'disciple_midnight_prayer', sanitize_text_field($_POST['das_midnight_prayer'] ?? ''));
					update_user_meta($uid, 'disciple_bible_study', sanitize_text_field($_POST['das_bible_study'] ?? ''));
					update_user_meta($uid, 'disciple_bible_study_other', sanitize_text_field($_POST['das_bible_study_other'] ?? ''));
					update_user_meta($uid, 'disciple_commitment_duration', sanitize_text_field($_POST['das_commitment_duration'] ?? ''));
					update_user_meta($uid, 'disciple_commitment_duration_other', sanitize_text_field($_POST['das_commitment_duration_other'] ?? ''));
					update_user_meta($uid, 'disciple_agree_commitment', sanitize_text_field($_POST['das_agree_commitment'] ?? ''));
					update_user_meta($uid, 'disciple_agree_commitment_other', sanitize_text_field($_POST['das_agree_commitment_other'] ?? ''));

					error_log('[DAS] Finished updating user meta for ' . $uid);
					
					/* ===== EMAIL DISPATCH (ASYNC) ===== */
					if (!get_transient('das_email_queued_' . $uid)) {
						set_transient('das_email_queued_' . $uid, 1, 2 * MINUTE_IN_SECONDS);

						$ping_url = admin_url('admin-ajax.php');
						$token = das_async_email_token($uid);

						wp_remote_post($ping_url, [
							'timeout' => 0.01,
							'blocking' => false,
							'sslverify' => apply_filters('https_local_ssl_verify', false),
							'body' => [
								'action' => 'das_async_email_now',
								'uid'    => (int)$uid,
								'token'  => $token,
							],
						]);
					}

                    /* ===== SUCCESS FEEDBACK ===== */
                    $reg_success = true;
                    $panel       = 'register';
                    $toast_type  = 'das-ok';
                    //$toast       = 'Registration complete! Your discipleship application is pending approval.';
                }
            }
        }
    }

    ob_start();
    ?>
    <div class="das-container <?php echo $panel === 'register' ? 'right-panel-active' : ''; ?>" id="das-container">
        <?php if ($toast): ?>
            <div class="das-toast <?php echo esc_attr($toast_type); ?>" id="das-toast">
                <?php echo esc_html($toast); ?>
            </div>
        <?php endif; ?>

        <!-- =========================
             Mobile Tabs (hidden on desktop)
             ========================= -->
        <div class="das-mobile-tabs" id="das-mobile-tabs">
            <button class="das-mobile-tab" data-target="login">Sign In</button>
            <button class="das-mobile-tab" data-target="register">Register</button>
        </div>

        <!-- =========================
             Register / Success Container
             ========================= -->
        <div class="form-container sign-up-container">
            <?php if (!$reg_success): ?>
                <form id="das-register-wizard" method="post" novalidate>
                    <?php wp_nonce_field('das_register', 'das_register_nonce'); ?>
                    <input type="hidden" name="das_action" value="das_register">

                    <!-- Progress -->
                    <div class="das-progress-minimal">
                        <span class="dash active"></span>
                        <span class="dash"></span>
                        <span class="dash"></span>
                        <span class="dash"></span>
                        <span class="dash"></span>
                    </div>
				                    <!-- === STEP 1 === -->
                    <div class="das-step active" data-step="1">
                        <h3 class="das-step-title">Member Details</h3>
                        <div class="das-row">
                            <input type="text" name="das_reg_fname" placeholder="First name" required>
                            <input type="text" name="das_reg_lname" placeholder="Last name" required>
                        </div>
                        <input type="email" name="das_reg_email" placeholder="Email" required>
                        <input type="tel" name="das_reg_phone" placeholder="Phone number" required>
                        <div class="das-row">
                            <input type="password" name="das_reg_password" placeholder="Password (8+)" minlength="8" required>
                            <input type="password" name="das_reg_confirm" placeholder="Confirm password" minlength="8" required>
                        </div>
                        <div class="das-nav">
                            <button type="button" class="das-btn next">Next</button>
                        </div>
                    </div>

                    <!-- === STEP 2 === -->
                    <div class="das-step" data-step="2">
                        <h3 class="das-step-title">Your Spiritual Journey</h3>

                        <label class="das-label">Are you born again?</label>
                        <div class="das-inline">
                            <label><input type="radio" name="das_born_again" value="yes" required> Yes</label>
                            <label><input type="radio" name="das_born_again" value="no"> No</label>
                            <label><input type="radio" name="das_born_again" value="unsure"> Unsure</label>
                        </div>

                        <div id="born-date-wrap" style="display:none;">
                            <label class="das-label">If yes, what date?</label>
                            <input type="date" name="das_born_date">
                        </div>

                        <label class="das-label">Do you believe in spiritual covering?</label>
                        <div class="das-inline">
                            <label><input type="radio" name="das_spiritual_covering" value="yes" required> Yes</label>
                            <label><input type="radio" name="das_spiritual_covering" value="no"> No</label>
                        </div>

                        <div class="das-nav">
                            <button type="button" class="das-btn das-secondary prev">Back</button>
                            <button type="button" class="das-btn next">Next</button>
                        </div>
                    </div>

                    <!-- === STEP 3 === -->
                    <div class="das-step" data-step="3">
                        <h3 class="das-step-title">Daily Devotion Habits</h3>

                        <label class="das-label">Bible Reading Per Day</label>
                        <select name="das_bible_reading" required>
                            <option value="">Select</option>
                            <option>7 Chapters +</option>
                            <option>7 Chapters</option>
                            <option>6 Chapters</option>
                            <option>5 Chapters</option>
                            <option>4 Chapters</option>
                            <option>3 Chapters</option>
                            <option>2 Chapters</option>
                            <option>1 Chapter</option>
                        </select>

                        <label class="das-label">Fasting Per Week</label>
                        <select name="das_fasting" required>
                            <option value="">Select</option>
                            <option>3 days</option>
                            <option>2 days</option>
                            <option>1 day</option>
                            <option>Not Now (Pregnant or Sick)</option>
                        </select>

                        <label class="das-label">Scripture Memorization</label>
                        <select name="das_memorization" required>
                            <option value="">Select</option>
                            <option>5 verses per week</option>
                            <option>4 verses per week</option>
                            <option>3 verses per week</option>
                            <option>2 verses per week</option>
                            <option>1 verse per week</option>
                        </select>

                        <div class="das-nav">
                            <button type="button" class="das-btn das-secondary prev">Back</button>
                            <button type="button" class="das-btn next">Next</button>
                        </div>
                    </div>

                    <!-- === STEP 4 === -->
                    <div class="das-step" data-step="4">
                        <h3 class="das-step-title">Prayer & Study Life</h3>

                        <label class="das-label">Morning Intimacy Prayers</label>
                        <select name="das_morning_prayer" required>
                            <option value="">Select</option>
                            <option>120 mins +</option>
                            <option>120 mins</option>
                            <option>60 mins</option>
                            <option>45 mins</option>
                            <option>30 mins</option>
                            <option>15 mins</option>
                        </select>

                        <label class="das-label">Midnight Intercessory Prayers</label>
                        <select name="das_midnight_prayer" required>
                            <option value="">Select</option>
                            <option>120 mins +</option>
                            <option>120 mins</option>
                            <option>60 mins</option>
                            <option>45 mins</option>
                            <option>30 mins</option>
                            <option>15 mins</option>
                        </select>

                        <label class="das-label">Bible Study &amp; Meditations</label>
                        <select name="das_bible_study" id="das_bible_study" required>
                            <option value="">Select</option>
                            <option>5 hours + / Week</option>
                            <option>5 hours / Week</option>
                            <option>4 hours / Week</option>
                            <option>3 hours / Week</option>
                            <option>2 hours / Week</option>
                            <option value="Other">Other</option>
                        </select>
                        <input type="text" name="das_bible_study_other" id="das_bible_study_other" placeholder="Please specify" style="display:none;">

                        <div class="das-nav">
                            <button type="button" class="das-btn das-secondary prev">Back</button>
                            <button type="button" class="das-btn next">Next</button>
                        </div>
                    </div>

                    <!-- === STEP 5 === -->
                    <div class="das-step" data-step="5">
                        <h3 class="das-step-title">Commitment Before God</h3>

                        <label class="das-label">Duration of this Commitment</label>
                        <select name="das_commitment_duration" id="das_commitment_duration" required>
                            <option value="">Select</option>
                            <option>12 Months</option>
                            <option>9 Months</option>
                            <option>6 Months</option>
                            <option>3 Months</option>
                            <option>Until I’ve graduated to Level 2</option>
                            <option value="Other">Other</option>
                        </select>
                        <input type="text" name="das_commitment_duration_other" id="das_commitment_duration_other" placeholder="Please specify" style="display:none;">

                        <label class="das-label">I understand that this spiritual regimen is a commitment between God and I</label>
                        <div class="das-inline">
                            <label><input type="radio" name="das_agree_commitment" value="1" required> Yes</label>
                            <label><input type="radio" name="das_agree_commitment" value="0"> No</label>
                            <label><input type="radio" name="das_agree_commitment" value="other"> Other</label>
                            <input type="text" name="das_agree_commitment_other" id="das_agree_commitment_other" placeholder="Please explain..." style="display:none;">
                        </div>

                        <div class="das-inline" style="margin-top:12px;">
                            <input type="checkbox" name="das_reg_consent" value="1" required>
                            <label>I commit to the discipleship process and agree to uphold the community guidelines.</label>
                        </div>

                        <div class="das-nav">
                            <button type="button" class="das-btn das-secondary prev">Back</button>
                            <button type="submit" class="das-btn">Register</button>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <div class="das-success">
                    <h3>Welcome to the Journey</h3>
                    <p>
                        Thank you for joining the <strong>RCN Discipleship Program</strong>.<br>
                        Your application has been received and is now <strong>pending review</strong>.<br>
                        You’ll receive an update once approved by our team.
                    </p>
                    <div class="das-actions">
                        <a class="das-btn das-secondary" href="<?php echo esc_url(home_url('/')); ?>">Back to site</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
				
		<!-- =========================
		     Reset Password Container
		     ========================= -->
				
			<div class="form-container reset-password-container">
			  <form id="das-reset-form" method="post">
				<input type="hidden" name="das_action" value="das_reset_password">
				<h2>Reset Password</h2>
				<!--<p class="das-small">Enter your email address and we’ll send you a reset link.</p>-->
				  <input type="email" name="user_login" placeholder="Email" required>
				<div class="das-nav">
				  <button type="submit" class="das-btn">Send Reset Link</button>
				</div>
				<p class="das-small">
				  <a href="#" id="backToLogin" class="das-back-link">Back to Sign In</a>
				</p>
			  </form>
			</div>

        <!-- =========================
             Sign In
             ========================= -->
        <div class="form-container sign-in-container">
            <form method="post" novalidate>
                <?php wp_nonce_field('das_login', 'das_login_nonce'); ?>
                <input type="hidden" name="das_action" value="das_login">

                <h2>Sign In</h2>
                <input type="text" name="das_email" placeholder="Email or Username" required autocomplete="username">
                <input type="password" name="das_password" placeholder="Password" required autocomplete="current-password">

                <label class="das-remember">
                    <input type="checkbox" name="das_remember" value="1"> Remember me
                </label>

                <button class="das-btn" type="submit">Sign In</button>

                <div class="das-login-links">
				  <span><a href="#" id="forgotPasswordLink">Forgot password?</a></span>
				  <!--<span>New here? <a href="#" id="signUp">Create account</a></span>-->
				</div>
            </form>
        </div>

        <!-- =========================
             Overlay (Desktop)
             ========================= -->
        <div class="overlay-container" aria-hidden="true">
            <div class="overlay">
                <div class="overlay-panel overlay-left">
                    <h3>Welcome back</h3>
                    <button class="ghost" id="das-signIn">Sign In</button>
                </div>
                <div class="overlay-panel overlay-right">
                    <h3>Join the Discipleship Program</h3>
                    <p>Create your account to begin.</p>
                    <button class="ghost" id="das-signUp">Register</button>
                </div>
            </div>
        </div>

        <!-- =========================
             Terms Modal (inline content)
             ========================= -->
        <div class="das-modal-overlay" id="das-terms">
            <div class="das-modal" role="dialog" aria-modal="true" aria-labelledby="das-terms-title">
                <header>
                    <h3 id="das-terms-title">Before we begin</h3>
                    <button type="button" id="das-terms-close" aria-label="Close">×</button>
                </header>

                <div class="das-modal-body">
                    <div class="das-terms">
                        <h4>Remnant Christian Network California (RCNCA) Discipleship Program Level 1</h4>
                        <p>
                            Welcome to the <strong>RCNCA Level 1 Discipleship Program</strong>, a journey of spiritual discipline,
                            consistency, and accountability. This program is designed to help believers build a regimented spiritual
                            lifestyle rooted in the Apostolic culture of the early Church (<em>Acts 2:46; Acts 6:4</em>).
                        </p>

                        <h5>Program Objective</h5>
                        <blockquote>
                            “To help a believer build and sustain a consistent, regimented spiritual lifestyle by holding them
                            accountable to daily, weekly, and monthly spiritual exercises.”
                        </blockquote>

                        <p><strong>Core Pillars:</strong></p>
                        <ul>
                            <li>Discipline</li>
                            <li>Consistency</li>
                            <li>Accountability</li>
                        </ul>

                        <p>This is not a religious drill. You commit at your pace, based on grace, and grow in intimacy with God.</p>

                        <h5>Spiritual Exercises</h5>
                        <ol>
                            <li><strong>Bible Reading:</strong> Audible reading of Scripture to saturate your spirit. 
								<ul><li><strong>Goal:</strong> Familiarity with God’s voice and impartation of His life.</li></ul>
								<ul><li><strong>Commitment:</strong> 1–7+ chapters/day.</li></ul>
                                <ul><li><strong>Memory Verses:</strong> 1–3 verses/week; Recommended Version: KJV (NKJV/AMP/NLT for comparison).</li></ul>
                            </li>
                            <li><strong>Fasting:</strong> Willful abstaining from natural pleasures for spiritual purpose. 
								<ul><li><strong>Purpose:</strong> Humble the soul, subdue the flesh, amplify spiritual sensitivity.</li>
									<li><strong>Commitment:</strong> 1–3 times/week.</li>
								</ul>
                            </li>
                            <li><strong>Morning Intimacy Prayers:</strong> Making God the dominant thought and
                                priority of your day.
								<ul><li><strong>Scripture:</strong> Psalm 63:1–2.</li>
									<li><strong>Commitment:</strong> 15–120 minutes daily.</li>
								</ul>
                            </li>
                            <li><strong>Midnight Intercessory Prayers:</strong> Prayers after midnight.
								<ul><li><strong>Scripture:</strong> Psalm 119:62; Acts 16:25–26.</li> 
									<li><strong>Commitment:</strong> At least twice a week 15–120 minutes per session.</li>
								</ul>
                            </li>
                            <li><strong>Bible Study &amp; Meditation:</strong> Deep dive into
                                Scripture for doctrinal clarity and personal revelation. 
									<ul><li><strong>Scripture:</strong> 2 Timothy 2:15; 1 Timothy 4:13, 15–16.</li>
										<li><strong>Commitment:</strong> 2–5 hours/week.</li>
									</ul>
                            </li>
                            <li><strong>Corporate Gathering / Prayers:</strong> Fellowship and prayer with
                                other believers.
								<ul><li><strong>Scripture:</strong> Acts 2:42; Acts 4:23–24.</li>
									<li><strong>Commitment:</strong> 1–3 times/week or bi-weekly/monthly.</li>
								</ul>
                            </li>
                        </ol>

                        <h5>Duration of Commitment</h5>
                        <p><em>“Offer unto God thanksgiving; and pay thy vows unto the most High…”: Psalm 50:14–15</em></p>
							<strong>Options:</strong>
								<ul>
									<li>3 Months</li>
									<li>Until Graduation</li>
									<li>Indefinitely</li>
								</ul>
                        <h5>Your Responsibility</h5>
                        <ul>
                            <li>Willingness to be disciplined and consistent</li>
                            <li>Honest reporting and communication</li>
                            <li>Eliminate distractions</li>
                            <li>Challenge the flesh</li>
                            <li>Execute on commitments</li>
                            <li>Attend foundational teachings and prayer sessions</li>
                        </ul>

                        <p>
                            <strong>Scripture References &amp; Resources:</strong><br>
                            Acts 2:46 | Acts 6:4 | Acts 3:1 | Luke 4:16 | Matthew 4:4 | Psalm 119:11 | Psalm 63:1–2 |
                            Galatians 6:7–9 | 1 Corinthians 9:24–27 | 2 Timothy 2:15 | 1 Timothy 4:13, 15–16 |
                            Psalm 50:14–15 | Acts 18:18
                        </p>
                    </div>
                </div>

                <div class="das-modal-actions">
                    <button type="button" class="das-btn-ghost" id="das-terms-decline">Back to Sign In</button>
                    <button type="button" class="das-btn" id="das-terms-accept">Join the Program</button>
                </div>
            </div>
        </div>
        <!-- End Terms Modal -->
    </div>

    <!-- =========================
         JS (IIFE)
         ========================= -->
    <script>
        (function () {
            const c        = document.getElementById('das-container');
            const up       = document.getElementById('das-signUp');
            const inb      = document.getElementById('das-signIn');
            const tabsWrap = document.getElementById('das-mobile-tabs');
            const toast    = document.getElementById('das-toast');
            const swaps    = c.querySelectorAll('.swap');

            /* Toast notification */
            function showToast() {
                if (!toast) return;
                requestAnimationFrame(() => {
                    toast.classList.add('show');
                });
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 5500);
            }
            showToast();

            function setTabsActive(panel) {
                if (!tabsWrap) return;
                const tabs = tabsWrap.querySelectorAll('.das-mobile-tab');
                tabs.forEach(t => t.classList.toggle('active', t.dataset.target === panel));
            }

            function go(panel) {
                const mobile = window.matchMedia('(max-width: 900px)').matches;
                setTabsActive(panel);

                if (mobile) {
                    c.classList.remove('right-panel-active');
                    c.classList.toggle('mobile-login', panel === 'login');
                    c.classList.toggle('mobile-register', panel === 'register');
                    history.replaceState(null, '', '?panel=' + panel);
                    return;
                }

                if (panel === 'register') {
                    c.classList.add('right-panel-active');
                    history.replaceState(null, '', '?panel=register');
                } else {
                    c.classList.remove('right-panel-active');
                    history.replaceState(null, '', '?panel=login');
                }
            }

            function syncMode() {
                const mobile   = window.matchMedia('(max-width: 900px)').matches;
                const urlPanel = new URLSearchParams(location.search).get('panel') || 'login';

                setTabsActive(urlPanel);

                if (mobile) {
                    c.classList.remove('right-panel-active');
                    c.classList.toggle('mobile-login', urlPanel === 'login');
                    c.classList.toggle('mobile-register', urlPanel === 'register');
                } else {
                    c.classList.toggle('right-panel-active', urlPanel === 'register');
                    c.classList.remove('mobile-login', 'mobile-register');
                }
            }

            window.addEventListener('resize', syncMode);
            syncMode();

            if (up)  up.addEventListener('click', () => go('register'));
            if (inb) inb.addEventListener('click', () => go('login'));

            if (tabsWrap) {
                tabsWrap.addEventListener('click', (e) => {
                    const btn = e.target.closest('.das-mobile-tab');
                    if (!btn) return;
                    go(btn.dataset.target);
                });
            }

            swaps.forEach(s => s.addEventListener('click', e => {
                e.preventDefault();
                go(s.dataset.target);
            }));

            /* ===== Terms gating (inline) ===== */
            const terms       = document.getElementById('das-terms');
            const termsAccept = document.getElementById('das-terms-accept');
            const termsDecline= document.getElementById('das-terms-decline');
            const termsClose  = document.getElementById('das-terms-close');

            function openTerms() {
                if (!terms) return;
                terms.classList.add('show');
                document.body.style.overflow = 'hidden';
            }

            function closeTerms() {
                if (!terms) return;
                terms.classList.remove('show');
                document.body.style.overflow = '';
            }

            function hasAcceptedTerms() { return false; }
            function markAcceptedTerms() { /* no persistence */ }

            // Override go() so Register always opens modal if not accepted
            const _go = go;
            go = function (panel) {
                _go(panel);
                if (panel === 'register' && !hasAcceptedTerms()) {
                    setTimeout(openTerms, 220);
                }
            };

            if (termsAccept) termsAccept.addEventListener('click', function () {
                const hidden = document.querySelector('input[name="das_terms_accepted"]');
                if (hidden) hidden.value = '1';
                closeTerms();
            });

            if (termsDecline) termsDecline.addEventListener('click', function () {
                closeTerms();
                go('login');
            });

            if (termsClose) termsClose.addEventListener('click', function () {
			  closeTerms();

			  const c = document.getElementById('das-container');
			  const mobile = window.matchMedia('(max-width: 900px)').matches;

			  // Always return to the last visible panel
			  if (mobile) {
				c.classList.remove('mobile-register', 'mobile-reset', 'mobile-login');
				c.classList.add('mobile-login'); // fade back to Sign In panel
			  } else {
				c.classList.remove('right-panel-active');
			  }
			});

            /* ===== Registration Wizard Logic ===== */
            const form = document.getElementById('das-register-wizard');
            if (!form) return;

            const steps  = [...form.querySelectorAll('.das-step')];
            const dashes = [...form.querySelectorAll('.das-progress-minimal .dash')];
            let current  = 0;

            function showStep(i) {
                steps.forEach((s, idx) => s.classList.toggle('active', idx === i));
                dashes.forEach((d, idx) => d.classList.toggle('active', idx <= i));
            }

            function validateStep(i) {
                const inputs = steps[i].querySelectorAll('input,select');
                for (const el of inputs) {
                    if (el.type !== 'hidden' && !el.checkValidity()) {
                        el.reportValidity();
                        return false;
                    }
                    if (el.name === 'das_reg_confirmpassword') {
                        const pw = form.querySelector('[name="das_reg_password"]').value;
                        if (el.value !== pw) {
                            alert('Passwords do not match');
                            return false;
                        }
                    }
                }
                return true;
            }

            form.addEventListener('click', e => {
                if (e.target.classList.contains('next')) {
                    if (!validateStep(current)) return;
                    current = Math.min(current + 1, steps.length - 1);
                    showStep(current);
                }
                if (e.target.classList.contains('prev')) {
                    current = Math.max(current - 1, 0);
                    showStep(current);
                }
            });

            // Conditional visibility
            form.querySelectorAll('input[name="das_born_again"]').forEach(r => {
                r.addEventListener('change', () => {
                    document.getElementById('born-date-wrap').style.display = (r.value === 'yes') ? 'block' : 'none';
                });
            });

            const duration      = form.querySelector('#das_commitment_duration');
            const durationOther = form.querySelector('#das_commitment_duration_other');
            if (duration && durationOther) {
                duration.addEventListener('change', () => {
                    durationOther.style.display = (duration.value === 'Other') ? 'block' : 'none';
                });
            }

            const agreeRadios = form.querySelectorAll('input[name="das_agree_commitment"]');
            const agreeOther  = form.querySelector('#das_agree_commitment_other');
            if (agreeRadios && agreeOther) {
                agreeRadios.forEach(r => {
                    r.addEventListener('change', () => {
                        const isOther = r.value === 'other';
                        agreeOther.style.display = isOther ? 'block' : 'none';
                        if (isOther) agreeOther.setAttribute('required', 'required');
                        else {
                            agreeOther.removeAttribute('required');
                            agreeOther.value = '';
                        }
                    });
                });
            }

            showStep(current);

            /* ===== Soft-block submit if "Agree" unchecked ===== */
            form.addEventListener('submit', function (e) {
                const consent = form.querySelector('[name="das_reg_consent"]');
                if (!consent || !consent.checked) {
                    e.preventDefault();
                    if (!document.getElementById('das-consent-warning')) {
                        const warn = document.createElement('div');
                        warn.id = 'das-consent-warning';
                        warn.textContent = 'Please agree to the terms and conditions before continuing.';
                        warn.style.cssText = 'margin-top:12px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:8px 10px;border-radius:8px;font-size:13px;text-align:center;';
                        const consentRow = consent.closest('.das-inline') || form.querySelector('.das-nav:last-of-type');
                        if (consentRow) consentRow.after(warn);
                        setTimeout(() => warn.remove(), 4000);
                    }
                }
            });
			
			// Forgot password and back to login toggle
			// === Panel Navigation (Improved for mobile/tablet) ===
			(function() {
			  const c = document.getElementById('das-container');
			  const forgot = document.getElementById('forgotPasswordLink');
			  const back = document.getElementById('backToLogin');
			  const signUp = document.getElementById('das-signUp');
			  const signIn = document.getElementById('das-signIn');

			  function go(panel) {
				const mobile = window.matchMedia('(max-width: 900px)').matches;
				c.classList.remove(
				  'right-panel-active', 'right-panel-reset',
				  'mobile-login', 'mobile-register', 'mobile-reset'
				);

				if (mobile) {
				  if (panel === 'register') c.classList.add('mobile-register');
				  else if (panel === 'reset') c.classList.add('mobile-reset');
				  else c.classList.add('mobile-login');
				} else {
				  if (panel === 'register') c.classList.add('right-panel-active');
				  else if (panel === 'reset') c.classList.add('right-panel-reset');
				}
			  }

			  if (forgot) forgot.addEventListener('click', e => { e.preventDefault(); go('reset'); });
			  if (back) back.addEventListener('click', e => { e.preventDefault(); go('login'); });
			  if (signUp) signUp.addEventListener('click', e => { e.preventDefault(); go('register'); });
			  if (signIn) signIn.addEventListener('click', e => { e.preventDefault(); go('login'); });
			})();

        })();
    </script>
    <?php
    return ob_get_clean();
});

 /* =====================================================
   VIDEO OVERLAY (shown only once per visitor)
   ===================================================== */
add_action('wp_footer', function () {
    // Only show on the Discipleship page
    if (!is_page('discipleship')) return; // <-- adjust slug if needed
	$video_id = attachment_url_to_postid( content_url('/uploads/2025/11/RCNCA-Discipleship-Intro.mp4') );	
    ?>
<div id="intro-video-overlay" style="display:none;">
        <div class="video-container">
            <video id="intro-video" preload="metadata">
                <source src="<?php echo esc_url(wp_get_attachment_url($video_id)); ?>" type="video/mp4">
                Your browser does not support the video tag.
            </video>
            <button id="close-video">✕ Close</button>
		
		<div class="video-play-overlay" id="custom-play-overlay">
		  <svg viewBox="0 0 100 100" class="play-icon">
			<polygon points="35,25 80,50 35,75" fill="white" />
		  </svg>
		</div>
        </div>
    </div>

    <style>
    #intro-video-overlay {
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, rgba(255,255,255,0.65), rgba(245,245,245,0.35));
        backdrop-filter: blur(14px) saturate(140%);
        z-index: 9999;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.45s ease;
    }
    #intro-video-overlay.show { opacity: 1; pointer-events: all; }
    .video-container {
        position: relative;
        width: min(80vw, 1000px);
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 12px 45px rgba(0,0,0,0.15);
        background: #fff;
        animation: fadeInUp 0.45s ease forwards;
    }
    #intro-video {
        display: block;
        width: 100%;
        height: auto;
        border-radius: 18px;
        background: #fff;
    }
    #close-video {
        position: absolute;
        top: 14px;
        right: 14px;
        background: rgba(255, 255, 255, 0.7);
        border: 1px solid rgba(0, 0, 0, 0.05);
        border-radius: 50%;
        color: #111;
        width: 38px;
        height: 38px;
        font-size: 20px;
        cursor: pointer;
        transition: all 0.25s ease;
        backdrop-filter: blur(6px);
    }
    #close-video:hover {
        background: #b91c1c;
        color: #fff;
        transform: scale(1.08);
    }
    @keyframes fadeInUp {
        from { transform: translateY(20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    .video-play-overlay {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        cursor: pointer;
        z-index: 5;
        transition: opacity 0.3s ease;
    }
    .video-play-overlay.hide { opacity: 0; pointer-events: none; }
    .play-icon {
        width: 90px;
        height: 90px;
        filter: drop-shadow(0 4px 8px rgba(0,0,0,0.25));
        transition: transform 0.3s ease;
    }
    .video-play-overlay:hover .play-icon { transform: scale(1.1); }
    #intro-video::-webkit-media-controls, #intro-video::-moz-media-controls {
        display: none !important;
    }		   
	.play-icon {
	  width: 100px; /* bigger overall size */
	  height: 100px;
	  filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.35));
	  transition: transform 0.3s ease, opacity 0.3s ease;
	}

	.video-play-overlay:hover .play-icon {
	  transform: scale(1.12);
	  opacity: 0.95;
	}
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
	  const overlay = document.getElementById('intro-video-overlay');
	  const video = document.getElementById('intro-video');
	  const closeBtn = document.getElementById('close-video');
	  const playOverlay = document.getElementById('custom-play-overlay');
	  const hasWatched = localStorage.getItem('introVideoWatched');

	  // Show overlay only once per visitor
	  if (!hasWatched) {
		overlay.style.display = 'flex';
		requestAnimationFrame(() => overlay.classList.add('show'));
	  }

	  const markWatched = () => {
		localStorage.setItem('introVideoWatched', 'true');
		video.pause();
		video.currentTime = 0; // stop audio playback
		overlay.classList.remove('show');
		setTimeout(() => overlay.style.display = 'none', 300);
	  };

	  video.addEventListener('ended', markWatched);
	  closeBtn.addEventListener('click', markWatched);

	  // Hover: show/hide native controls
	  video.addEventListener('mouseenter', () => video.setAttribute('controls', 'controls'));
	  video.addEventListener('mouseleave', () => video.removeAttribute('controls'));

	  // Custom play overlay
	  if (playOverlay) {
		playOverlay.addEventListener('click', async () => {
		  try {
			await video.play();
			playOverlay.classList.add('hide');
		  } catch (err) {
			console.warn('Playback blocked:', err);
		  }
		});

		video.addEventListener('pause', () => {
		  if (video.currentTime < video.duration) {
			playOverlay.classList.remove('hide');
		  }
		});

		video.addEventListener('play', () => {
		  playOverlay.classList.add('hide');
		});
	  }
	});
    </script>
    <?php
});		
			
/* =====================================================
   LOGIN REDIRECT (status-aware)
   ===================================================== */
add_filter('login_redirect', function ($r, $q, $u) {
    if ($u instanceof WP_User) {
        if ($u->has_cap('access_leadership')) {
            return site_url('/leadership-dashboard/');
        }
        if ($u->has_cap('access_discipleship')) {
            $status = get_user_meta($u->ID, 'disciple_status', true) ?: 'inactive';
            switch ($status) {
                case 'active':
                    return site_url('/disciple-dashboard/');
                case 'rejected':
                    return site_url('/discipleship-rejected/');
                default:
                    return site_url('/discipleship-pending/');
            }
        }
    }
    return home_url('/');
}, 10, 3);

/* =====================================================
   SHORTCODES: Pending / Rejected
   ===================================================== */
add_shortcode('disciple_pending_notice', function () {
    if (!is_user_logged_in()) return '';
    $u = wp_get_current_user();
    if (!$u->has_cap('access_discipleship')) return '';
    $st = get_user_meta($u->ID, 'disciple_status', true) ?: 'inactive';
    if ($st !== 'inactive') return '';
    $name = esc_html($u->display_name);

    return '
    <div style="border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:12px;padding:12px 14px;margin:12px 0;">
        <strong>Hi ' . $name . ', your application is pending.</strong><br>
        We will review your registration soon. You’ll gain access once approved.
    </div>';
});

add_shortcode('disciple_reject_reason', function () {
    if (!is_user_logged_in()) return '';
    $u = wp_get_current_user();
    if (!$u->has_cap('access_discipleship')) return '';
    $st = get_user_meta($u->ID, 'disciple_status', true);
    if ($st !== 'rejected') return '';
    $reason = get_user_meta($u->ID, 'disciple_reject_reason', true);
    if (!$reason) return '';

    return '
    <div style="border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:12px;padding:12px 14px;margin:12px 0;">
        <strong>Reason from leadership:</strong><br>' . nl2br(esc_html($reason)) . '
    </div>';
});

/* =====================================================
   TEMP: TRACE ALL EMAILS (debug)
   ===================================================== */
add_filter('wp_mail', function ($args) {
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
    $where = array_map(function ($f) {
        return (isset($f['function']) ? $f['function'] : 'unknown') .
            (isset($f['file']) ? ' @ ' . basename($f['file']) : '');
    }, $bt);

    error_log(
        '[MAIL TRACE] To=' .
        (is_array($args['to']) ? implode(',', $args['to']) : $args['to']) .
        ' | Subject=' . $args['subject'] .
        ' | via ' . implode(' <- ', $where)
    );

    return $args;
});

			
/* =====================================================
   AUTH: INLINE PASSWORD RESET HANDLER (Discipleship Slider)
   ===================================================== */
add_action('template_redirect', function () {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
  if (($_POST['das_action'] ?? '') !== 'das_reset_password') return;

  $email = sanitize_text_field($_POST['user_login'] ?? '');
  if (!$email) {
    wp_safe_redirect(add_query_arg('das_err', 'Enter your email address.', das_base_url()));
    exit;
  }

  $user = get_user_by('email', $email);
  if (!$user) {
    // Generic success message (avoid enumeration)
    wp_safe_redirect(add_query_arg('das_ok', rawurlencode('If an account exists, a reset link has been emailed.'), das_base_url()));
    exit;
  }

  $result = retrieve_password($user->user_login);
  if (is_wp_error($result)) {
    error_log('retrieve_password error: ' . $result->get_error_message());
    wp_safe_redirect(add_query_arg('das_err', rawurlencode('Something went wrong sending the reset email.'), das_base_url()));
  } else {
    wp_safe_redirect(add_query_arg('das_ok', rawurlencode('Check your email for the reset link.'), das_base_url()));
  }
  exit;
});
