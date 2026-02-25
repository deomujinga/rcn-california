add_action( 'wp_logout', 'redirect_after_logout' );
function redirect_after_logout() {
    wp_safe_redirect( home_url( '/discipleship/' ) );
    exit;
}
