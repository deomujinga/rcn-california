/**
 * Top black sign-in strip (front-end only).
 * Shows "Sign in" (links to /login/) or "Sign out" (logs out to Home).
 */
function rcn_top_signin_bar_render() {
    // Hide on auth pages (adjust slugs if yours differ)
    if ( is_page(array('login','password-reset','set-new-password')) ) return;

    $login_url  = home_url('/login/');                  // your front-end login page
    $logout_url = wp_logout_url( home_url('/') );       // after sign out, go Home

    // Text + URL toggle
    if ( is_user_logged_in() ) {
        $cta_text = 'Sign out';
        $cta_href = $logout_url;
    } else {
		return;
        //$cta_text = 'Sign in';
        //$cta_href = $login_url;
    }

    // Styles + markup
    echo '<style>
      .rcn-topbar{position:fixed;left:0;right:0;top:0;z-index:9999;background:#0b0b0b;color:#fff;height:36px;display:flex;align-items:center}
      .rcn-topbar .rcn-bar-inner{width:100%;max-width:1200px;margin:0 auto;padding:0 16px;display:flex;justify-content:flex-end;gap:16px}
      .rcn-topbar a{color:#fff;text-decoration:none;font-weight:600;letter-spacing:.2px}
      .rcn-topbar a:hover{text-decoration:underline}
	  .rcn-bar-inner{ padding-right: 100px !important;  max-width: none !important}
      /* push page content down so bar doesn\'t overlap */
      body{--rcn-topbar-h:36px}
      body:not(.admin-bar){padding-top:var(--rcn-topbar-h)}
      /* account for WP admin bar */
      body.admin-bar{padding-top:calc(var(--rcn-topbar-h) + 32px)}
      @media (max-width:782px){ body.admin-bar{padding-top:calc(var(--rcn-topbar-h) + 46px)} }
    </style>';

    echo '<div class="rcn-topbar" role="region" aria-label="Account bar"><div class="rcn-bar-inner">
            <a class="rcn-cta" href="'.esc_url($cta_href).'">'.esc_html($cta_text).'</a>
          </div></div>';
}
// Prefer wp_body_open (best place). Fallback to wp_head if theme lacks it.
add_action('wp_body_open', 'rcn_top_signin_bar_render');
if ( ! has_action('wp_body_open', 'rcn_top_signin_bar_render') ) {
    add_action('wp_head', 'rcn_top_signin_bar_render');
}
