<?php
/**
 * Main plugin class file.
 *
 * @package WordPress Plugin Template/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backup file exclusion filters.
 *
 * Only make one instance of this, please.
 */
class SQLite_Backup_Exclusion {
	public function __construct() {
		global $wp_object_cache;
		if ( method_exists( $wp_object_cache, 'sqlite_files' ) ) {
			add_filter( 'updraftplus_exclude_file', array( $this, 'updraftplus_exclude_file' ), 10, 2 );
			add_filter( 'backwpup_file_exclude', array( $this, 'backwpup_file_exclude' ), 10, 1 );
			add_filter( 'wpstg_clone_excluded_files', array( $this, 'wpstg_clone_excluded_files' ), 10, 1 );
			add_filter( 'wpstg_push_excluded_files', array( $this, 'wpstg_clone_excluded_files' ), 10, 1 );
		}
	}



	/**
	 * Filter for Updraft Plus backup exclusion
	 *
	 * @param $filter
	 * @param $file
	 *
	 * @return bool|mixed
	 */
	function updraftplus_exclude_file( $filter, $file ) {
		global $wp_object_cache;
		if ( method_exists( $wp_object_cache, 'sqlite_files' ) ) {
			foreach ( $wp_object_cache->sqlite_files() as $sqlite_file ) {
				if ( basename( $sqlite_file ) === basename( $file ) ) {
					return true;
				}
			}
		}

		return $filter;
	}

	/**
	 * Filter for BackWPUp backup exclusion
	 *
	 * @param string $list Comma-separated list of files to skip.
	 *
	 * @return string  Comma-separated list of files to skip.
	 */
	public function backwpup_file_exclude( $list ) {
		global $wp_object_cache;
		$files    = array();
		$files [] = $list;
		if ( method_exists( $wp_object_cache, 'sqlite_files' ) ) {
			foreach ( $wp_object_cache->sqlite_files() as $file ) {
				$files [] = basename( $file );
			}
		}

		return implode( ',', $files );
	}

	/**
	 * Filter for WP STAGING cloning and pushing exclusion
	 *
	 * @param array $files
	 *
	 * @return array
	 */
	public function wpstg_clone_excluded_files( $files ) {
		global $wp_object_cache;
		if ( method_exists( $wp_object_cache, 'sqlite_files' ) ) {
			foreach ( $wp_object_cache->sqlite_files() as $file ) {
				$files [] = basename( $file );
			}
		}

		return $files;
	}

}
