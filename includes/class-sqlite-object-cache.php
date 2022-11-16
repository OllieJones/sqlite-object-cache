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
	 * Constructor funtion.
	 *
	 * @param string $file File constructor.
	 * @param string $version Plugin version.
	 */
	public function __construct( $file = '', $version = '1.0.0' ) {
		$this->_version = $version;
		$this->_token   = 'sqlite_object_cache';

		// Load plugin environment variables.
		$this->file       = $file;
		$this->dir        = dirname( $this->file );
		$this->assets_dir = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

		$this->script_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		register_activation_hook( $this->file, [ $this, 'install' ] );

		// Load frontend JS & CSS.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ], 10 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ], 10 );

		// Load API for generic admin functions.
		if ( is_admin() ) {
			$this->admin = new SQLite_Object_Cache_Admin_API();
		}

		// Handle localisation.
		$this->load_plugin_textdomain();
		add_action( 'init', [ $this, 'load_localization' ], 0 );
		$option = get_option( $this->_token . '_settings', [] );
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
	 * Load frontend CSS.
	 *
	 * @access  public
	 * @return void
	 * @since   1.0.0
	 */
	public function enqueue_styles() {
		if ( false ) {
			// TODO put this back if we need front end css
			wp_register_style( $this->_token . '-frontend', esc_url( $this->assets_url ) . 'css/frontend.css', [], $this->_version );
			wp_enqueue_style( $this->_token . '-frontend' );
		}
	} // End admin_enqueue_styles ()

	/**
	 * Load frontend Javascript.
	 *
	 * @access  public
	 * @return  void
	 * @since   1.0.0
	 */
	public function enqueue_scripts() {
		if ( false ) {
			// TODO put this back if we need front end javascript
			wp_register_script( $this->_token . '-frontend', esc_url( $this->assets_url ) . 'js/frontend' . $this->script_suffix . '.js', [ 'jquery' ], $this->_version, true );
			wp_enqueue_script( $this->_token . '-frontend' );
		}
	} // End enqueue_scripts ()

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

	public function do_capture() {
		$option = get_option( $this->_token . '_settings' );
		if ( ! is_array( $option )
		    || ! array_key_exists( 'frequency', $option )
		    || ! array_key_exists( 'retainmeasurements', $option ) ) {
			/* Something's wrong with the option. Just ignore it. */
			return;
		}
		$frequency       = $option['frequency'];
		$frequency       = $frequency ?: 1;
		$headway         = intval( HOUR_IN_SECONDS / $frequency );
		$now             = time();
		$previouscapture = array_key_exists( 'previouscapture', $option ) ? $option['previouscapture'] : 0;
		$sincelastcapture = $previouscapture > 0 ? $now - $previouscapture : $headway;
		if ( $sincelastcapture >= $headway ) {
			/* Time for a capture! */
			global $wp_object_cache;
			if ( method_exists( $wp_object_cache, 'set_sqlite_monitoring_options' ) ) {
				$monitoring_options = [ 'capture' => true,
				                        'resolution' => $headway,
				                        'lifetime' => $option['retainmeasurements'] * HOUR_IN_SECONDS,
				                        'verbose' => true ];
				$wp_object_cache->set_sqlite_monitoring_options($monitoring_options);
			}
			$previouscapture = $now;
			$option['previouscapture'] = $previouscapture;
			update_option($this->_token . '_settings', $option );
		}
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cloning of SQLite_Object_Cache is forbidden' ) ), esc_attr( $this->_version ) );
	} // End __clone ()

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Unserializing instances of SQLite_Object_Cache is forbidden' ) ), esc_attr( $this->_version ) );
	} // End __wakeup ()

	/**
	 * Installation. Runs on activation.
	 *
	 * @access  public
	 * @return  void
	 * @since   1.0.0
	 */
	public function install() {
		$this->_log_version_number();
	} // End install ()

	/**
	 * Log the plugin version number.
	 *
	 * @access  public
	 * @return  void
	 * @since   1.0.0
	 */
	private function _log_version_number() { //phpcs:ignore
		update_option( $this->_token . '_version', $this->_version, false );
	} // End _log_version_number ()

}
