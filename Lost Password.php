/**
 * Shortcode: [front_lostpassword_native]
 * Pretty front-end "Lost your password?" that triggers WP core reset email.
 */

/**
 * Shared front-end login/reset password styles.
 * Used by [front_lostpassword_native] and [front_setpassword_native].
 */
function front_login_native_styles() {
    ob_start(); ?>
    <style>
      .fl-viewport {
        display:flex;
        align-items:center;
        justify-content:center;
        min-height:100vh;
        background:#f9fafb;
        font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'Noto Sans',sans-serif;
      }
      .fl-card {
        background:white;
        border-radius:12px;
        padding:2rem 2.5rem;
        max-width:400px;
        width:90%;
        box-shadow:0 10px 25px rgba(0,0,0,0.05);
      }
      .fl-title {
        font-size:1.5rem;
        margin-bottom:1rem;
        text-align:center;
        color:#111827;
      }
      .fl-text {
        font-size:0.95rem;
        color:#374151;
        margin-bottom:1rem;
        text-align:center;
      }
      .fl-formwrap label {
        display:block;
        margin-bottom:0.25rem;
        color:#111827;
        font-weight:500;
      }
      .fl-formwrap input[type="text"],
      .fl-formwrap input[type="password"] {
        width:100%;
        padding:0.5rem;
        border:1px solid #d1d5db;
        border-radius:8px;
        margin-bottom:0.75rem;
      }
      .fl-links {
        text-align:center;
        margin-top:1rem;
      }
      .fl-links a {
        color:#2563eb;
        text-decoration:none;
      }
      .fl-links a:hover {
        text-decoration:underline;
      }
      .fl-sep {
        margin:0 0.5rem;
        color:#9ca3af;
      }
    </style>
    <?php
    return ob_get_clean();
}

function front_lostpassword_native_shortcode() {
    if ( is_user_logged_in() ) {
        return front_login_native_styles() .
               '<div class="fl-viewport"><div class="fl-card">'.
               '<h2 class="fl-title">You’re already logged in</h2>'.
               '<p class="fl-text">No need to reset your password.</p>'.
               '<p class="fl-links"><a href="'.esc_url( home_url('/') ).'">Go to Home</a></p>'.
               '</div></div>';
    }

    // Handle postback
    $notice = '';
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['fl_lp_nonce']) && wp_verify_nonce( $_POST['fl_lp_nonce'], 'fl_lost_password' ) ) {
        $user_login = trim( sanitize_text_field( wp_unslash( $_POST['user_login'] ?? '' ) ) );

        if ( empty( $user_login ) ) {
            $notice = '<p class="fl-text" role="alert">Please enter your username or email.</p>';
        } else {
            // Use WP core to generate reset key + send email
            $errors = retrieve_password( $user_login );
            if ( true === $errors ) {
                // Success — mirror WP’s message
                return front_login_native_styles() .
                    '<div class="fl-viewport"><div class="fl-card">'.
                    '<h2 class="fl-title">Check your email</h2>'.
                    '<p class="fl-text">If the account exists, we sent a password reset link.</p>'.
                    '<p class="fl-links"><a href="'.esc_url( home_url('/') ).'">Back to site</a><span class="fl-sep">•</span><a href="'.esc_url( wp_login_url() ).'">Return to login</a></p>'.
                    '</div></div>';
            } else {
                // $errors is a WP_Error
                $notice = '<p class="fl-text" role="alert">'. esc_html( $errors->get_error_message() ) .'</p>';
            }
        }
    }

    ob_start();
    echo front_login_native_styles(); ?>
    <div class="fl-viewport">
      <div class="fl-card" role="form" aria-labelledby="fl-lp-title">
        <h2 id="fl-lp-title" class="fl-title">Reset your password</h2>
        <p class="fl-text">Enter your username or email and we’ll email you a reset link.</p>
        <?php echo $notice; ?>
        <form method="post" class="fl-formwrap" action="">
          <label for="user_login"><strong>Username or Email Address</strong> <span style="color:#ef4444">*</span></label>
          <input type="text" id="user_login" name="user_login" required>
          <?php wp_nonce_field( 'fl_lost_password', 'fl_lp_nonce' ); ?>
          <div class="login-submit" style="margin-top:1rem">
            <input type="submit" class="button button-primary" value="Send reset link">
          </div>
        </form>
        <div class="fl-links">
          <a href="<?php echo esc_url( wp_login_url() ); ?>">Return to login</a>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('front_lostpassword_native', 'front_lostpassword_native_shortcode');
