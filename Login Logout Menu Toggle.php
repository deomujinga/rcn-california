// [login_logout_button]
function login_logout_button_shortcode() {
    if ( is_user_logged_in() ) {
        // Logout → redirect to homepage after
        return '<a class="btn btn-logout" href="' . esc_url( wp_logout_url( home_url('/') ) ) . '">Logout</a>';
    } else {
        // Login → point to your front-end page at /login
        return '<a class="btn btn-login" href="' . esc_url( home_url('/login/') ) . '">Login</a>';
    }
}
add_shortcode('login_logout_button', 'login_logout_button_shortcode');

// Allow shortcodes in menu labels
add_filter('walker_nav_menu_start_el', function($out){ 
    return do_shortcode($out); 
}, 10, 1);
