<?php
/**
 * This file runs when the plugin in uninstalled (deleted).
 *
 * @package SQLite Object Cache.
 */

// If plugin is not being uninstalled, exit (do nothing).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sqlite_object_cache_settings' );
delete_option( 'sqlite_object_cache_version' );
