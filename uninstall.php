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
foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => false ) ) as $sqlite_object_cache_site_id ) {
    try {
      switch_to_blog( $sqlite_object_cache_site_id );
      delete_option( 'sqlite_object_cache_settings' );
      delete_option( 'sqlite_object_cache_version' );
    } catch ( Exception $ex ) {
      /* Empty, intentionally. Avoid crashes on asset clearing. */
    } finally {
      restore_current_blog();
    }
  }
}
unset ( $sqlite_object_cache_site_id );
