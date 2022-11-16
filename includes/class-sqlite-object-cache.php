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
 */
class SQLite_Object_Cache {

	/**
	 * The single instance of SQLite_Object_Cache.
	 *
	 * @var     object
	 * @access  private
	 * @since   1.0.0
	 */
	private static $_instance = null; //phpcs:ignore

	/**
	 * Local instance of SQLite_Object_Cache_Admin_API
	 *
	 * @var SQLite_Object_Cache_Admin_API|null
	 */
	public $admin = null;

	/**
	 * Settings class object
	 *
	 * @var     object
	 * @access  public
	 * @since   1.0.0
	 */
	public $settings = null;

	/**
	 * The version number.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $_version; //phpcs:ignore

	/**
	 * The token.
	 *
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $_token; //phpcs:ignore

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
	 * Constructor funtion.
	 *
	 * @param string $file File constructor.
	 * @param string $version Plugin version.
	 */
	public function __construct( $file = '', $version = '1.0.0' ) {
		$this->_version = $version;
		$this->_token   = 'sqlite_object_cache';

		// Load plugin environment variables.
		$this->file             = $file;
		$this->dir              =
			trailingslashit( dirname( WP_CONTENT_DIR . '/plugins/' . plugin_basename( $this->file ) ) );
		$this->assets_dir       = trailingslashit( $this->dir . 'assets' );
		$this->dropinfilesource = $this->assets_dir . '/drop-in/object-cache.php';
		$this->dropinfiledest   = trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';

		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

		$this->script_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		register_activation_hook( $this->file, [ $this, 'on_activation' ] );
		register_deactivation_hook( $this->file, [ $this, 'on_deactivation' ] );

		// Load API for generic admin functions.
		if ( is_admin() ) {
			$this->admin = new SQLite_Object_Cache_Admin_API();
		}

		// Handle localization.
		$this->load_plugin_textdomain();
		add_action( 'admin-init', [ $this, 'load_localization' ], 0 );
		add_action( 'admin_init', [ $this, 'maybe_update_dropin' ] );

		/* Are we capturing SQLite Object Cache statistics? */
		$option = get_option( $this->_token . '_settings' );
		if ( array_key_exists( 'capture', $option ) && $option['capture'] === 'on' ) {
			add_action( 'init', [ $this, 'do_capture' ] );
		}
	} // End __construct ()

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
	} // End enqueue_styles ()

	/**
	 * Main SQLite_Object_Cache Instance
	 *
	 * Ensures only one instance of SQLite_Object_Cache is loaded or can be loaded.
	 *
	 * @param string $file File instance.
	 * @param string $version Version parameter.
	 *
	 * @return Object SQLite_Object_Cache instance
	 * @see SQLite_Object_Cache()
	 * @since 1.0.0
	 * @static
	 */
	public static function instance( $file = '', $version = '1.0.0' ) {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $file, $version );
		}

		return self::$_instance;
	} // End enqueue_scripts ()

	/**
	 * Plugin activation hook
	 *
	 * @return void
	 */
	public function on_activation() {
		$this->_log_version_number();
}

	/**
	 * Log the plugin version number.
	 *
	 * @access  public
	 * @return  void
	 * @since   1.0.0
	 */
	private function _log_version_number() { //phpcs:ignore
		update_option( $this->_token . '_version', $this->_version, false );
	}

	/**
	 * Load plugin localization
	 *
	 * @access  public
	 * @return  void
	 * @since   1.0.0
	 */
	public function load_localization() {
		load_plugin_textdomain( 'sqlite-object-cache', false, dirname( plugin_basename( $this->file ) ) . '/languages/' );
	}

	/**
	 * Init action to initiate statistics capture from this page view.
	 *
	 * @return void
	 */
	public function do_capture() {
		$option = get_option( $this->_token . '_settings' );
		if ( ! is_array( $option ) || ! array_key_exists( 'frequency', $option ) || ! array_key_exists( 'retainmeasurements', $option ) ) {
			/* Something's wrong with the option. Just ignore it. */
			return;
		}
		$frequency        = $option['frequency'];
		$frequency        = $frequency ?: 1;
		$headway          = intval( HOUR_IN_SECONDS / $frequency );
		$now              = time();
		$previouscapture  = array_key_exists( 'previouscapture', $option ) ? $option['previouscapture'] : 0;
		$sincelastcapture = $previouscapture > 0 ? $now - $previouscapture : $headway;
		if ( $sincelastcapture >= $headway ) {
			/* Time for a capture! */
			global $wp_object_cache;
			if ( method_exists( $wp_object_cache, 'set_sqlite_monitoring_options' ) ) {
				$monitoring_options = [
					'capture'    => true,
					'resolution' => $headway,
					'lifetime'   => $option['retainmeasurements'] * HOUR_IN_SECONDS,
					'verbose'    => true,
				];
				$wp_object_cache->set_sqlite_monitoring_options( $monitoring_options );
			}
			$previouscapture           = $now;
			$option['previouscapture'] = $previouscapture;
			update_option( $this->_token . '_settings', $option );
		}
	}

	/**
	 * Test if we can write in the WP_CONTENT_DIR and modify the `object-cache.php` drop-in
	 *
	 * @return true|WP_Error
	 * @author Till Krüss
	 *
	 */
	public function test_filesystem_writing() {
		global $wp_filesystem;

		if ( ! $this->initialize_filesystem( '', true ) ) {
			return new WP_Error( 'fs', __( 'Could not initialize filesystem.', 'sqlite-object-cache' ) );
		}

		$testfiledest = WP_CONTENT_DIR . '/.sqlite-write-test.tmp';

		if ( ! $wp_filesystem->exists( $this->dropinfilesource ) ) {
			return new WP_Error( 'exists', __( 'Object cache drop-in file doesn’t exist.', 'sqlite-object-cache' ) );
		}

		if ( $wp_filesystem->exists( $testfiledest ) ) {
			if ( ! $wp_filesystem->delete( $testfiledest ) ) {
				return new WP_Error( 'delete', __( 'Test file exists, but couldn’t be deleted.', 'sqlite-object-cache' ) );
			}
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

		$meta = get_file_data( $testfiledest, [ 'Version' => 'Version' ] );

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
	public function initialize_filesystem( $url, $silent = false ) {
		$req = trailingslashit( ABSPATH ) . 'wp-admin/includes/file.php';
		require_once $req;
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
	public function maybe_update_dropin() {
		if ( defined( 'WP_SQLITE_OBJECT_CACHE_DISABLE_DROPIN_AUTOUPDATE' ) && WP_SQLITE_OBJECT_CACHE_DISABLE_DROPIN_AUTOUPDATE ) {
			return;
		}

		if ( $this->object_cache_dropin_needs_updating() ) {
			add_action( 'shutdown', [ $this, 'update_dropin' ] );
		}
	}

	/**
	 * Checks if the `object-cache.php` drop-in is outdated
	 * @return bool
	 * @author Till Krüss
	 *
	 */
	public function object_cache_dropin_needs_updating() {
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
	public function object_cache_dropin_exists() {
		return @file_exists( $this->dropinfiledest );
	}

	/**
	 * Updates the `object-cache.php` drop-in
	 *
	 * @return void
	 * @author Till Krüss
	 */
	public function update_dropin() {
		global $wp_filesystem;

		if ( $this->initialize_filesystem( '', true ) ) {
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
	 * Plugin deactivation hook
	 *
	 * @param string $plugin Plugin basename.
	 *
	 * @return void
	 * @author Till Krüss
	 */
	public function on_deactivation( $plugin ) {
		global $wp_filesystem;

		$db_file = WP_CONTENT_DIR . '/sqlite-object-cache.sqlite';
		if ( defined( 'WP_SQLITE_OBJECT_CACHE_DB_FILE' ) ) {
			$db_file = WP_SQLITE_OBJECT_CACHE_DB_FILE;
		}

		$dont_delete_db_file =
			defined( 'WP_SQLITE_OBJECT_CACHE_DB_FILE_DISABLE_DELETE' ) && WP_SQLITE_OBJECT_CACHE_DB_FILE_DISABLE_DELETE;

		ob_start();

		if ( $plugin === $plugin ) {   //TODO compare to base name?

			wp_cache_flush();

			if ( $this->validate_object_cache_dropin() && $this->initialize_filesystem( '', true ) ) {
				$wp_filesystem->delete( $this->dropinfiledest );
				if ( ! $dont_delete_db_file ) {
					$wp_filesystem->delete( $db_file );
				}
			}
		}

		ob_end_clean();
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
	} // End __clone ()

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cloning of SQLite_Object_Cache is forbidden' ) ), esc_attr( $this->_version ) );
	} // End __wakeup ()

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Unserializing instances of SQLite_Object_Cache is forbidden' ) ), esc_attr( $this->_version ) );
	} // End _log_version_number ()

}
