<?php
/**
 * Plugin Name: SQLite Object Cache
 * Version: 0.1.0
 * Plugin URI: http://www.hughlashbrooke.com/
 * Description: This is your starter template for your next WordPress plugin.
 * Author: Ollie Jones
 * Author URI: https://plumislandmedia.net/
 * Requires at least: 5.9
 * Requires PHP: 5.6
 * Tested up to: 6.1
 *
 * Text Domain: sqlite-object-cache
 * Domain Path: /languages/
 *
 * @package WordPress
 * @author Ollie Jones
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load plugin class files.
require_once 'includes/class-sqlite-object-cache.php';
require_once 'includes/class-sqlite-object-cache-settings.php';

// Load plugin libraries.
require_once 'includes/lib/class-sqlite-object-cache-admin-api.php';

/**
 * Returns the main instance of SQLite_Object_Cache to prevent the need to use globals.
 *
 * @return object SQLite_Object_Cache
 * @since  1.0.0
 */
function sqlite_object_cache() {
	$instance = SQLite_Object_Cache::instance( __FILE__, '0.1.0' );

	if ( is_null( $instance->settings ) ) {
		$instance->settings = SQLite_Object_Cache_Settings::instance( $instance );
	}

	return $instance;
}

sqlite_object_cache();
