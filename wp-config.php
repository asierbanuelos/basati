<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'u351898102_u351898102_cSb' );

/** Database username */
define( 'DB_USER', 'u351898102_u351898102_cSb' );

/** Database password */
define( 'DB_PASSWORD', '+:!G$OezyY/5' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          'DXc0$_K2&O9`vT7Nh30~W14}R6T.)V/8Q?}j2$Y7PGqmVjpsE--1Hr|jQO2]1qsI' );
define( 'SECURE_AUTH_KEY',   '6*aG]qTH^+LB1bZ7c~<M{%H^Nq8F6+fbm8s]I_%&ZPHwgRZx?:$GW8]uh%_1:I%{' );
define( 'LOGGED_IN_KEY',     '+(*U0RD-M-upckqxMEe/Q~o-iXK>Cwox]#ILv()3Va9Xd8x} ,mpxI@,/Or.d{0=' );
define( 'NONCE_KEY',         './ijvwHMG|:i^]Z%?s-_o`w#uxM#,2/eD>G$9x|%E&j1_W-6>^UBBNwsLrS@VI5n' );
define( 'AUTH_SALT',         'mSqOdx9|[u7H6b4UG3gI}##MP#4o DkTlgft.UWs<l=`dQ${tbrM_S5Lc[$D9?~F' );
define( 'SECURE_AUTH_SALT',  'x]]z3MD7q{&?aBz#BYMxd=+K6IOyLg[{9cw1cB6WwPZL0q(VL*lcyMrl[r6!{gST' );
define( 'LOGGED_IN_SALT',    '3MNXEbLVr.)6_o s.%9.:(IbC k[96O@?4}(iy_0E4-O#5Rcj2:>3m6IXfB(g|8L' );
define( 'NONCE_SALT',        '/2&;{0s/G/F1q;uwjqoN%{se!L~)V0;hckm4~J_hT((]>QhUF+{dt!:XaJd_8t|e' );
define( 'WP_CACHE_KEY_SALT', '+NL2r&BxD}i!JEFLB|R9r%46Z&%4szfq^YX]!`q9EHc`,`K6,{Xyku/4Yq{cJUC7' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'WP_ENVIRONMENT_TYPE', 'production' );
/* That's all, stop editing! Happy publishing. */
define('WP_HOME', 'https://basati.eus');
define('WP_SITEURL', 'https://basati.eus');
/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
