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
if ( is_multisite() ) {
foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => false ) ) as $site_id ) {
    try {
      switch_to_blog( $site_id );
      delete_option( 'sqlite_object_cache_settings' );
      delete_option( 'sqlite_object_cache_version' );
    } catch ( Exception $ex ) {
      /* Avoid crashes on transient clearing */
      error_log( 'SQLite Object Cache problem removing blog options. Blog ' . $site_id . ':' . $ex->getMessage() );
    } finally {
      restore_current_blog();
    }
  }
}
