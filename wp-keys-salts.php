<?php
/**
 * WordPress Authentication Keys and Salts
 * Generated: 2026-02-12
 * 
 * You can regenerate these at: https://api.wordpress.org/secret-key/1.1/salt/
 */

// Keys & salts (prefer env vars; otherwise use the generated values below)
define('AUTH_KEY',         getenv('WP_AUTH_KEY') ?: 'Kv9$xR2#mP7!nL4@wQ8^jF3&cY6*hB1+tZ5%dA0_sE2~gU7=');
define('SECURE_AUTH_KEY',  getenv('WP_SECURE_AUTH_KEY') ?: 'Xp3#qW8$mN6!vJ2@yK9^hL5&cT1*bR4+fZ7%dG0_sA3~eU6=');
define('LOGGED_IN_KEY',    getenv('WP_LOGGED_IN_KEY') ?: 'Ht7$rM3#pL9!nK2@wF8^jC5&vY1*bQ4+xZ6%dS0_gA7~eU9=');
define('NONCE_KEY',        getenv('WP_NONCE_KEY') ?: 'Jw2#kP8$mR4!nV6@yL9^hT3&cB1*fQ5+xZ7%dG0_sA2~eU4=');
define('AUTH_SALT',        getenv('WP_AUTH_SALT') ?: 'Qn5$vK1#jM7!pL3@wR8^hF2&cY6*bT4+xZ9%dS0_gA5~eU1=');
define('SECURE_AUTH_SALT', getenv('WP_SECURE_AUTH_SALT') ?: 'Fy8#tN4$kW2!mJ6@pR9^hL3&cV1*bQ5+xZ7%dG0_sA8~eU3=');
define('LOGGED_IN_SALT',   getenv('WP_LOGGED_IN_SALT') ?: 'Bm1$cR6#vK9!nP3@wL7^jT2&hY5*fQ8+xZ4%dS0_gA6~eU9=');
define('NONCE_SALT',       getenv('WP_NONCE_SALT') ?: 'Zx4#pW7$kN1!mJ5@vR8^hL2&cT6*bQ9+fY3%dG0_sA4~eU7=');
