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
 * Main plugin class.
 *
 * Only make one instance of this, please.
 */
class SQLite_Object_Cache {
	const CLEAN_EVENT_HOOK = 'sqlite_object_cache_clean';

	/**
	 * Local instance of SQLite_Object_Cache_Admin_API
	 *
	 * @var SQLite_Object_Cache_Admin_API|null
	 */
	public $admin;

	/**
	 * Settings class object
	 *
	 * @var     object
	 * @access  public
	 * @since   1.0.0
	 */
	public $settings;

	/**
	 * The version number.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $_version;

	/**
	 * The token.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $_token;

	/**
	 * The main plugin file.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $file;

	/**
	 * The main plugin directory.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $dir;

	/**
	 * The plugin assets directory.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $assets_dir;

	/**
	 * The plugin assets URL.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $assets_url;

	/**
	 * Suffix for JavaScripts.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $script_suffix;

	/**
	 * Path for the drop-in when it is working.
	 * @var string
	 */
	public $dropinfiledest;

	/**
	 * Path for the drop-in in the plugin tree.
	 * @var string
	 */
	public $dropinfilesource;

	/**
	 * Minimum required sqlite version.
	 *
	 * @var string
	 */
	public $minimum_sqlite_version = '3.7.0';

	/**
	 * Constructor funtion.
	 *
	 * @param string $file File constructor.
	 * @param string $version Plugin version.
	 */
	public function __construct( $file = '', $version = '1.3.8' ) {
		$this->_version = $version;
		$this->_token   = 'sqlite_object_cache';

		// Load plugin environment variables.
		$this->file             = $file;
		$this->dir              =
			trailingslashit( dirname( WP_CONTENT_DIR . '/plugins/' . plugin_basename( $this->file ) ) );
		$this->assets_dir       = trailingslashit( $this->dir . 'assets' );
		$this->dropinfilesource = $this->assets_dir . 'drop-in/object-cache.php';
		$this->dropinfiledest   = trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';

		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

		$this->script_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		register_activation_hook( $this->file, array( $this, 'on_activation' ) );
		register_deactivation_hook( $this->file, array( $this, 'on_deactivation' ) );

		if ( is_admin() ) {
			// Load API for generic admin functions.
			$this->admin = new SQLite_Object_Cache_Admin_API();
			// Suppress backups
			new SQLite_Backup_Exclusion();
		}

		// Handle localization.
		$this->load_plugin_textdomain();
		add_action( 'admin-init', array( $this, 'load_localization' ), 0 );
		add_action( 'admin_init', array( $this, 'maybe_update_dropin' ) );

		/* handle cron cache cleanup */
		add_action( self::CLEAN_EVENT_HOOK, array( $this, 'clean_job' ), 10, 0 );
		if ( ! wp_next_scheduled( self::CLEAN_EVENT_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEAN_EVENT_HOOK );
		}
		/* Handle probabilistic non-cron cleanup, one request in 2000. */
		if ( 1 === rand( 1, 2000 ) ) {
			add_action( 'shutdown', function () {
				$this->clean_job( 1.25 );
			}, 999, 0 );
		}
	}

	/**
	 * Load plugin textdomain
	 *
	 * @access  public
	 * @return  void
	 * @since   1.0.0
	 */
	public function load_plugin_textdomain() {
		$domain = 'sqlite-object-cache';

		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );

		load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $domain, false, dirname( plugin_basename( $this->file ) ) . '/languages/' );
	}

	/**
	 * WP_Cron task to clean cache entries.
	 *
	 * @param float $grace_factor Default 1.0
	 *
	 * @return void
	 */
	public function clean_job( $grace_factor = 1.0 ) {
		$option         = get_option( $this->_token . '_settings', array() );
		$target_size    = empty ( $option['target_size'] ) ? 16 : $option['target_size'];
		$target_size    *= ( 1024 * 1024 );
		$threshold_size = (int) ( $target_size * $grace_factor );

		global $wp_object_cache;
		if ( ! method_exists( $wp_object_cache, 'sqlite_get_size' ) ) {
			return;
		}
		$current_size = $wp_object_cache->sqlite_get_size();
		/* Skip this if the current size is small enough. */
		if ( $current_size <= $threshold_size ) {
			return;
		}

		/* Remove expired items (transients mostly). */
		if ( $wp_object_cache->sqlite_remove_expired( ) ) {
			/* If anything was removed, get the size again. */
			$current_size = $wp_object_cache->sqlite_get_size();
		}

		/* Skip this if the size is small enough, after removing expired items. */
		if ( $current_size <= $threshold_size ) {
			return;
		}

		/* Clean up old statistics. */
		$retention    = empty ( $option['retention'] ) ? 24 : $option['retention'];
		$wp_object_cache->sqlite_reset_statistics( $retention * HOUR_IN_SECONDS );

		/* Delete the least-recently-updated items to get to the target size. */
		$wp_object_cache->sqlite_delete_old( $target_size, $current_size );
	}

	/**
	 * Plugin activation hook
	 *
	 * @return void
	 */
	public
	function on_activation() {
		/* make sure the autoloaded option is set when activating; avoid an extra dbms or cache hit to fetch it */
		$option = get_option( $this->_token . '_settings', 'default' );
		if ( 'default' === $option ) {
			update_option( $this->_token . '_settings', array(), true );
		}
		if ( true === $this->has_sqlite() ) {
			add_action( 'shutdown', array( $this, 'update_dropin' ) );
		}
	}

	/**
	 * Determine whether we can use SQLite3.
	 *
	 * @return bool|string true, or an error message.
	 */
	public
	function has_sqlite() {
		if ( ! class_exists( 'SQLite3' ) || ! extension_loaded( 'sqlite3' ) ) {
			return __( 'You cannot use the SQLite Object Cache plugin. Your server does not have php\'s SQLite3 extension installed.', 'sqlite-object-cache' );
		}

		$sqlite_version = $this->sqlite_get_version();
		if ( version_compare( $sqlite_version, $this->minimum_sqlite_version ) < 0 ) {
			return sprintf(
			/* translators: 1 actual SQLite version. 2 required SQLite version) */
				__( 'You cannot use the SQLite Object Cache plugin. Your server only offers SQLite3 version %1$s, but at least %2$s is required.', 'sqlite-object-cache' ),
				$sqlite_version, $this->minimum_sqlite_version );
		}

		return true;
	}

	/**
	 * Get the version number for the SQLite extension.
	 *
	 * @return string|false  SQLite's version number.
	 */
	public
	function sqlite_get_version() {

		if ( class_exists( 'SQLite3' ) ) {
			$version = SQLite3::version();

			return $version['versionString'];
		}

		return false;
	}

	/**
	 * Load plugin localization
	 *
	 * @access  public
	 * @return  void
	 * @since   1.0.0
	 */
	public
	function load_localization() {
		load_plugin_textdomain( 'sqlite-object-cache', false, dirname( plugin_basename( $this->file ) ) . '/languages/' );
	}

	/**
	 * Test if we can write in the WP_CONTENT_DIR and modify the `object-cache.php` drop-in
	 *
	 * @return true|WP_Error
	 * @author Till Krüss
	 *
	 */
	public
	function test_filesystem_writing() {
		global $wp_filesystem;

		if ( ! $this->initialize_filesystem( '', true ) ) {
			return new WP_Error( 'fs', __( 'Could not initialize filesystem.', 'sqlite-object-cache' ) );
		}

		$testfiledest = WP_CONTENT_DIR . '/sqlite-write-test.tmp';

		if ( ! $wp_filesystem->exists( $this->dropinfilesource ) ) {
			return new WP_Error( 'exists', __( 'Object cache drop-in file doesn’t exist.', 'sqlite-object-cache' ) );
		}

		if ( $wp_filesystem->exists( $testfiledest ) && ! $wp_filesystem->delete( $testfiledest ) ) {
			return new WP_Error( 'delete', __( 'Test file exists, but couldn’t be deleted.', 'sqlite-object-cache' ) );
		}

		if ( ! $wp_filesystem->is_writable( WP_CONTENT_DIR ) ) {
			return new WP_Error( 'copy', __( 'Content directory is not writable.', 'sqlite-object-cache' ) );
		}

		if ( ! $wp_filesystem->copy( $this->dropinfilesource, $testfiledest, true, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'copy', __( 'Failed to copy test file.', 'sqlite-object-cache' ) );
		}

		if ( ! $wp_filesystem->exists( $testfiledest ) ) {
			return new WP_Error( 'exists', __( 'Copied test file doesn’t exist.', 'sqlite-object-cache' ) );
		}

		$meta = get_file_data( $testfiledest, array( 'Version' => 'Version' ) );

		if ( $meta['Version'] !== $this->_version ) {
			return new WP_Error( 'version', __( 'Couldn’t verify test file contents.', 'sqlite-object-cache' ) );
		}

		if ( ! $wp_filesystem->delete( $testfiledest ) ) {
			return new WP_Error( 'delete', __( 'Copied test file couldn’t be deleted.', 'sqlite-object-cache' ) );
		}

		return true;
	}

	/**
	 * Initializes the WP filesystem API to be ready for use
	 *
	 * @param string $url The URL to post the form to.
	 * @param bool   $silent Whether to ask the user for credentials if necessary or not.
	 *
	 * @return bool
	 * @author Till Krüss
	 *
	 */
	public
	function initialize_filesystem(
		$url, $silent = false
	) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( $silent ) {
			ob_start();
		}

		$credentials = request_filesystem_credentials( $url );

		if ( false === $credentials ) {
			if ( $silent ) {
				ob_end_clean();
			}

			return false;
		}

		if ( ! WP_Filesystem( $credentials ) ) {
			request_filesystem_credentials( $url );

			if ( $silent ) {
				ob_end_clean();
			}

			return false;
		}

		return true;
	}

	/**
	 * Calls the drop-in update method if necessary
	 *
	 * @return void
	 * @author Till Krüss
	 */
	public
	function maybe_update_dropin() {
		if ( defined( 'WP_SQLITE_OBJECT_CACHE_DISABLE_DROPIN_AUTOUPDATE' ) && WP_SQLITE_OBJECT_CACHE_DISABLE_DROPIN_AUTOUPDATE ) {
			return;
		}

		$has = $this->has_sqlite();
		if ( ( true === $has ) && $this->object_cache_dropin_needs_updating() ) {
			add_action( 'shutdown', array( $this, 'update_dropin' ) );
		}
	}

	/**
	 * Checks if the `object-cache.php` drop-in is outdated
	 * @return bool
	 * @author Till Krüss
	 *
	 */
	public
	function object_cache_dropin_needs_updating() {
		if ( ! $this->object_cache_dropin_exists() ) {
			return true;
		}

		$dropin = get_plugin_data( $this->dropinfiledest );
		$plugin = get_plugin_data( $this->dropinfilesource );

		if ( $dropin['PluginURI'] === $plugin['PluginURI'] ) {
			return version_compare( $dropin['Version'], $plugin['Version'], '<' );
		}

		return false;
	}

	/**
	 * Checks if the `object-cache.php` drop-in exists
	 *
	 * @return bool
	 * @author Till Krüss
	 */
	public
	function object_cache_dropin_exists() {
		return @file_exists( $this->dropinfiledest );
	}

	/**
	 * Updates the `object-cache.php` drop-in
	 *
	 * @return void
	 * @author Till Krüss
	 */
	public
	function update_dropin() {
		global $wp_filesystem;
		$has = $this->has_sqlite();
		if ( true === $has && $this->initialize_filesystem( '', true ) ) {

			$this->delete_sqlite_files();
			$result = $wp_filesystem->copy( $this->dropinfilesource, $this->dropinfiledest, true, FS_CHMOD_FILE );
			/**
			 * Fires on cache enable event
			 *
			 * @param bool $result Whether the filesystem event (copy of the `object-cache.php` file) was successful.
			 *
			 * @since 0.1.0
			 */
			do_action( 'sqlite_object_cache_update_dropin', $result );
		}
	}

	/**
	 * Validates the `object-cache.php` drop-in
	 *
	 * @return bool
	 * @author Till Krüss
	 */
	public function validate_object_cache_dropin() {
		if ( ! $this->object_cache_dropin_exists() ) {
			return false;
		}

		$dropin = get_plugin_data( $this->dropinfiledest );
		$plugin = get_plugin_data( $this->dropinfilesource );

		/**
		 * Filters the drop-in validation state
		 *
		 * @param bool   $state The validation state of the drop-in.
		 * @param string $dropin The `PluginURI` of the drop-in.
		 * @param string $plugin The `PluginURI` of the plugin.
		 *
		 * @since 2.0.16
		 */
		return apply_filters(
			'sqlite_object_cache_validate_dropin',
			$dropin['PluginURI'] === $plugin['PluginURI'],
			$dropin['PluginURI'],
			$plugin['PluginURI']
		);
	}

	/**
	 * Plugin deactivation hook
	 *
	 * @param string $plugin Plugin basename.
	 *
	 * @return void
	 * @author Till Krüss
	 */
	public
	function on_deactivation( $plugin ) {
		wp_cache_flush();

		wp_unschedule_hook( self::CLEAN_EVENT_HOOK );
		$this->delete_sqlite_files();
		$this->delete_dropin();
	}

	private function delete_sqlite_files() {
		global $wp_filesystem;
		global $wp_object_cache;

		ob_start();

		if ( method_exists( $wp_object_cache, 'sqlite_files' ) ) {
			if ( $this->validate_object_cache_dropin() && $this->initialize_filesystem( '', true ) ) {
				foreach ( $wp_object_cache->sqlite_files() as $file ) {
					$wp_filesystem->delete( $file );
				}
			}
		}

		ob_end_clean();
	}

	private
	function delete_dropin() {
		global $wp_filesystem;
		ob_start();

		if ( $this->validate_object_cache_dropin() && $this->initialize_filesystem( '', true ) ) {
			$wp_filesystem->delete( $this->dropinfiledest );
		}

		ob_end_clean();
	}
}
