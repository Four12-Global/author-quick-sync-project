<?php
define('WP_CACHE', true);
//Begin Really Simple SSL key
define('RSSSL_KEY', 'hRmULGhhatsoBxx9KOCLvtCKs4Q8Ne0D9HxH10ERPEs61rdE8ApzKFfPkJ1dPtpD');
//END Really Simple SSL key
//Begin Really Simple SSL session cookie settings
@ini_set('session.cookie_httponly', true);
@ini_set('session.cookie_secure', true);
@ini_set('session.use_only_cookies', true);
//END Really Simple SSL cookie settings
/**
 * The base configurations of the WordPress.
 *
 * This file has the following configurations: MySQL settings, Table Prefix,
 * Secret Keys, WordPress Language, and ABSPATH. You can find more information
 * by visiting {@link http://codex.wordpress.org/Editing_wp-config.php Editing
 * wp-config.php} Codex page. You can get the MySQL settings from your web host.
 *
 * This file is used by the wp-config.php creation script during the
 * installation. You don't have to use the web site, you can just copy this file
 * to "wp-config.php" and fill in the values.
 *
 * @package WordPress
 */
// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'vqnxseugmr');
/** MySQL database username */
define('DB_USER', 'vqnxseugmr');
/** MySQL database password */
define('DB_PASSWORD', 'dx26vD2fEy');
/** MySQL hostname */
define('DB_HOST', 'localhost');
/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8mb4');
/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');
/**#@+
 * Authentication Unique Keys and Salts.
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 */
require('wp-salt.php');
/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each a unique
 * prefix. Only numbers, letters, and underscores please!
 */
/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each a unique
 * prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';
/**
 * WordPress Localized Language, defaults to English.
 *
 * Change this to localize WordPress. A corresponding MO file for the chosen
 * language must be installed to wp-content/languages. For example, install
 * de_DE.mo to wp-content/languages and set WPLANG to 'de_DE' to enable German
 * language support.
 */
define('FS_METHOD', 'direct');
define('WPLANG', '');
define('FS_CHMOD_DIR', (0775 & ~umask()));
define('FS_CHMOD_FILE', (0664 & ~umask()));
/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 */
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );   // keep the front-end clean
define('WP_REDIS_CONFIG', [
   'token' => "e279430effe043b8c17d3f3c751c4c0846bc70c97f0eaaea766b4079001c",
   'host' => '127.0.0.1',
   'port' => 6379,
   'database' => "4464", 
   'timeout' => 2.5,
   'read_timeout' => 2.5,
   'split_alloptions' => true,
   'async_flush' => true,
   'client' => 'phpredis', 
   'compression' => 'zstd', 
   'serializer' => 'igbinary', 
   'prefetch' => true, 
   'debug' => false,
   'save_commands' => false,
   'prefix' => DB_NAME,  
   ]);
define('WP_REDIS_DISABLED', false);
define('WP_ALLOW_MULTISITE', false);
define('AUTOSAVE_INTERVAL', 120); // Autosave every 120 seconds(default is 60)
define('WP_POST_REVISIONS', 10); // Save maximum 10 revisions per post
define('CDN_SITE_ID', '4257948');
define('CDN_SITE_TOKEN', 'c116a1e42200b3206d2b2f77e440c086');
define('DISABLE_WP_CRON', false);
define('WP_ALLOW_REPAIR', false);
/* That's all, stop editing! Happy blogging. */
/** Absolute path to the WordPress directory. */
if (!defined('ABSPATH'))
        define('ABSPATH', dirname(__FILE__) . '/');
/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');
