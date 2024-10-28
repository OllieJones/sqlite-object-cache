<?php
/**
 * Plugin Name: SQLite Object Cache
 * Version: 1.3.8
 * Plugin URI: https://github.com/OllieJones/sqlite-object-cache
 * Description: A persistent object cache backend powered by SQLite3.
 * Author: Oliver Jones
 * Author URI: https://github.com/OllieJones/
 * Requires at least: 5.5
 * Requires PHP: 5.6
 * Tested up to: 6.7
 *
 * Text Domain: sqlite-object-cache
 * Domain Path: /languages/
 *
 * @package SQLiteObjectCache
 * @author Oliver Jones
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once 'includes/class-sqlite-object-cache.php';
if ( is_admin() ) {
	require_once 'includes/class-sqlite-object-cache-settings.php';
	require_once 'includes/lib/class-sqlite-object-cache-admin-api.php';
	require_once 'includes/lib/class-sqlite-object-cache-statistics.php';
	require_once 'includes/lib/class-sqlite-backup-exclusion.php';
}
/**
 * Returns the main instance of SQLite_Object_Cache to prevent the need to use globals.
 *
 * @return object SQLite_Object_Cache
 * @since  1.0.0
 */
function sqlite_object_cache() {
	$instance = new SQLite_Object_Cache( __FILE__, '1.3.8' );

	if ( is_admin() ) {
		$instance->settings = new SQLite_Object_Cache_Settings( $instance );
	}

	return $instance;
}

sqlite_object_cache();
