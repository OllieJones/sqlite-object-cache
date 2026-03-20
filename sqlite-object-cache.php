<?php
/**
 * Plugin Name: SQLite Object Cache
 * Version: 1.6.3
 * Plugin URI: https://github.com/OllieJones/sqlite-object-cache
 * Description: A persistent object cache backend powered by SQLite3.
 * Author: Oliver Jones
 * Author URI: https://github.com/OllieJones/
 * Requires at least: 5.5
 * Requires PHP: 5.6
 * Tested up to: 7.0
 * Text Domain: sqlite-object-cache
 * Domain Path: /languages/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SQLiteObjectCache
 * @author Oliver Jones
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

require_once 'includes/class-sqlite-object-cache.php';
/* wp-cli interface activation */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
  require_once( plugin_dir_path( __FILE__ ) . 'includes/cli.php' );
}

if ( is_admin()  || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
  require_once 'includes/class-sqlite-object-cache-settings.php';
  require_once 'includes/lib/class-sqlite-object-cache-statistics.php';
  require_once 'includes/lib/class-sqlite-backup-exclusion.php';
  require_once 'includes/lib/class-file.php';
  require_once 'includes/lib/class-sqlite-object-cache-opcache.php';
}
/**
 * Returns the main instance of SQLite_Object_Cache to prevent the need to use globals.
 *
 * @return object SQLite_Object_Cache
 * @since  1.0.0
 */
function sqlite_object_cache() {
  $instance = new SQLite_Object_Cache( __FILE__, '1.6.3' );

  if ( is_admin() ) {
    $instance->settings = new SQLite_Object_Cache_Settings( $instance, plugin_basename( __FILE__ ));
  }

  return $instance;
}

sqlite_object_cache();
