<?php
/**
 * Settings class file.
 *
 * @package SQLite Object Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 */
class SQLite_Object_Cache_Settings {

	/**
	 * The single instance of SQLite_Object_Cache_Settings.
	 *
	 * @var     object
	 */
	private static $_instance = null; //phpcs:ignore

	/**
	 * The main plugin object.
	 *
	 * @var     object
	 */
	public $parent = null;

	/**
	 * Prefix for plugin settings.
	 *
	 * @var     string
	 */
	public $base = '';

	/**
	 * Available settings for plugin.
	 *
	 * @var     array
	 */
	public $settings = [];

	/**
	 * SQLite3 is available.
	 *
	 * @var     string|bool  If true, we're good. If string, an explanation.
	 */
	public $has = '';

	/**
	 * Constructor function.
	 *
	 * @param object $parent Parent object.
	 */
	public function __construct( $parent ) {
		$this->parent = $parent;
		$this->has    = $parent->has_sqlite();
		$this->base   = 'sqlite_object_cache_';

		// Initialise settings.
		add_action( 'init', [ $this, 'init_settings' ], 11 );

		// Register plugin settings.
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// Add settings page to menu.
		add_action( 'admin_menu', [ $this, 'add_menu_item' ] );

		// Add settings link to plugins page.
		add_filter(
			'plugin_action_links_' . plugin_basename( $this->parent->file ),
			[
				$this,
				'add_settings_link',
			]
		);

		// Configure placement of plugin settings page. See readme for implementation.
		add_filter( $this->base . 'menu_settings', [ $this, 'configure_settings' ] );

		// Load admin JS & CSS.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ], 10, 1 );
	}

	/**
	 * Main SQLite_Object_Cache_Settings Instance
	 *
	 * Ensures only one instance of SQLite_Object_Cache_Settings is loaded or can be loaded.
	 *
	 * @param object $parent Object instance.
	 *
	 * @return object SQLite_Object_Cache_Settings instance
	 * @static
	 * @see SQLite_Object_Cache()
	 */
	public static function instance( $parent ) {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $parent );
		}

		return self::$_instance;
	}

	/**
	 * Initialize settings
	 *
	 * @return void
	 */
	public function init_settings() {
		$this->settings = $this->settings_fields();
	}

	/**
	 * Build settings fields
	 *
	 * @return array Fields to be displayed on settings page
	 */
	private function settings_fields() {

		$settings['standard'] = [
			'title'       => __( 'SQLite Object Cache', 'sqlite-object-cache' ),
			'description' => '',
			'callback'    => [ $this, 'validate' ],
			'fields'      => [
				[
					'id'          => 'flush',
					'label'       => __( 'Flush now', 'sqlite-object-cache' ),
					'description' => __( 'Check to flush the cache (delete all its entries).', 'sqlite-object-cache' ),
					'type'        => 'checkbox',
					'default'     => '',
					'reset'       => '',
				],
				[
					'id'          => 'retention',
					'label'       => __( 'Cached data expires after', 'sqlite-object-cache' ),
					'description' => __( 'days.', 'sqlite-object-cache' ),
					'type'        => 'number',
					'default'     => 7,
					'max'         => 365,
					'min'         => 1,
					'step'        => 'any',
					'cssclass'    => 'narrow',
					'placeholder' => __( 'Days to retain.', 'sqlite-object-cache' ),
				],
				[
					'id'          => 'cleanup',
					'label'       => __( 'Clean up now', 'sqlite-object-cache' ),
					'description' => __( 'Check to clean up the cache (delete expired data).', 'sqlite-object-cache' ),
					'type'        => 'checkbox',
					'default'     => '',
					'reset'       => '',
				],
				[
					'id'          => 'capture',
					'label'       => __( 'Measure performance', 'sqlite-object-cache' ),
					'description' => __( 'Check to measure cache performance. ', 'sqlite-object-cache' ),
					'type'        => 'checkbox',
					'default'     => '',
				],
				[
					'id'          => 'frequency',
					'label'       => __( 'Measure', 'sqlite-object-cache' ),
					'description' => __( 'times per hour.', 'sqlite-object-cache' ),
					'type'        => 'number',
					'default'     => 10,
					'max'         => 36000,
					'min'         => 0.001,
					'step'        => 'any',
					'cssclass'    => 'narrow',
					'placeholder' => __( 'Times per hour to measure.', 'sqlite-object-cache' ),
				],
				[
					'id'          => 'retainmeasurements',
					'label'       => __( 'Retain measurements for', 'sqlite-object-cache' ),
					'description' => __( 'hours.', 'sqlite-object-cache' ),
					'type'        => 'number',
					'default'     => 2,
					'max'         => 31 * 24,
					'min'         => 0.1,
					'step'        => 'any',
					'cssclass'    => 'narrow',
					'placeholder' => __( 'Hours to retain.', 'sqlite-object-cache' ),
				],
			],
		];

		$settings['stats'] = [
			'title' => __( 'Statistics', 'sqlite-object-cache' ),
		];

		return apply_filters( $this->parent->_token . '_settings_fields', $settings );
	}

	/**
	 * Filters an option value following sanitization.
	 *
	 * @param string $option The sanitized option value.
	 * @param string $name The option name.
	 * @param string $original_value The original value passed to the function.
	 *
	 * @throws Exception Announce SQLite failure.
	 * @since 2.3.0
	 * @since 4.3.0 Added the `$original_value` parameter.
	 *
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function validate( $option, $name, $original_value ) {
		if ( ! is_array( $option ) ) {
			/* weird. not an option array */
			return $option;
		}
		global $wp_object_cache;

		if ( array_key_exists( 'flush', $option ) && $option ['flush'] === 'on' ) {
			if ( method_exists( $wp_object_cache, 'flush' ) ) {
				$wp_object_cache->flush( true, true );
			}
			unset ( $option['flush'] );
		}
		$retention = $this->numeric_option( $option, 'retention', 7 );
		if ( array_key_exists( 'cleanup', $option ) && $option ['cleanup'] === 'on' ) {

			if ( method_exists( $wp_object_cache, 'sqlite_clean_up_cache' ) ) {
				$wp_object_cache->sqlite_clean_up_cache( $retention * DAY_IN_SECONDS, true, true );
			}
			unset ( $option['cleanup'] );
		}

		$this->numeric_option( $option, 'frequency', 10 );
		$this->numeric_option( $option, 'retainmeasurements', 2 );

		if ( array_key_exists( 'capture', $option ) && $option['capture'] === 'on' ) {
			$option['previouscapture'] = 0;
		}

		return $option;
	}

	/**
	 * Retrieve a numeric option, and set the default if it isn't present.
	 *
	 * @param array  $option Option array value.
	 * @param string $name Name of the element in the option array.
	 * @param mixed  $default Default value to use.
	 *
	 * @return float|int|mixed|string
	 */
	private function numeric_option( &$option, $name, $default ) {
		$result = $default;
		if ( array_key_exists( $name, $option ) && is_numeric( $option[ $name ] ) ) {
			$result = $option[ $name ];
			$result = $result ?: $default;
		} else {
			$option[ $name ] = $default;
		}

		return $result;
	}

	/**
	 * Add settings page to admin menu
	 *
	 * @return void
	 */
	public function add_menu_item() {

		$args = $this->menu_settings();

		// Do nothing if wrong location key is set.
		if ( is_array( $args ) && isset( $args['location'] ) && function_exists( 'add_' . $args['location'] . '_page' ) ) {
			switch ( $args['location'] ) {
				case 'options':
				case 'submenu':
					$page =
						add_submenu_page( $args['parent_slug'], $args['page_title'], $args['menu_title'], $args['capability'], $args['menu_slug'], $args['function'] );
					break;
				case 'menu':
					$page =
						add_menu_page( $args['page_title'], $args['menu_title'], $args['capability'], $args['menu_slug'], $args['function'], $args['icon_url'], $args['position'] );
					break;
				default:
					return;
			}
			add_action( 'admin_print_styles-' . $page, [ $this, 'settings_assets' ] );
		}
	}

	/**
	 * Prepare default settings page arguments
	 *
	 * @return mixed|void
	 */
	private function menu_settings() {
		echo $this->complain_if_sqlite3_unavailable();

		return apply_filters(
			$this->base . 'menu_settings',
			[
				'location'    => 'options', // Possible settings: options, menu, submenu.
				'parent_slug' => 'options-general.php',
				'page_title'  => __( 'SQLite Persistent Object Cache Settings', 'sqlite-object-cache' ),
				'menu_title'  => __( 'Object Cache', 'sqlite-object-cache' ),
				'capability'  => 'manage_options',
				'menu_slug'   => $this->parent->_token . '_settings',
				'function'    => [ $this, 'settings_page' ],
				'icon_url'    => '',
				'position'    => null,
			]
		);
	}

	/**
	 * Emit a message if SQLite3 is not available.
	 *
	 * @param bool $verbose False means emit a notice.  True means emit a longer explanation.
	 *
	 * @return string
	 */
	private function complain_if_sqlite3_unavailable( $verbose = false ) {
		$html = '';
		if ( true !== $this->has ) {
			if ( ! $verbose ) {
				$html .= '<div class="notice notice-error sql-object-cache">';
				$html .= esc_html( $this->has );
				$html .= '</div>';
			} else {
				$html .= '<div>';
				$html .= '<h3 style="color: #d63638">' . esc_html__( 'Server configuration problem', 'sqlite-object-cache' ) . '</h3>';
				$html .= '<p>';

				if ( ! extension_loaded( 'sqlite3' ) || ! class_exists( 'SQLite3' ) ) {
					$html .= esc_html__( 'The SQLite Persistent Object Cache plugin requires php\'s SQLite3 extension.', 'sqlite-object-cache' ) . ' ';
					$html .= esc_html__( 'That extension is not installed in your server, so the plugin cannot work.', 'sqlite-object-cache' ) . ' ';
				}

				$sqlite_version = $this->parent->sqlite_version();
				if (version_compare($sqlite_version, $this->parent->minimum_sqlite_version) < 0) {
					$html .= sprintf (
					/* translators: 1 actual SQLite version. 2 required SQLite version) */
						__( 'You cannot use the SQLite Object Cache plugin. Your server only offers SQLite3 version %1$s, but at least %2$s is required.', 'sqlite-object-cache' ),
						$sqlite_version, $this->parent->minimum_sqlite_version );
				}

				$html .= '</p></div>';
			}
		}

		return $html;
	}

	/**
	 * Container for settings page arguments
	 *
	 * @param array $settings Settings array.
	 *
	 * @return array
	 */
	public function configure_settings( $settings = [] ) {
		return $settings;
	}

	/**
	 * Load settings JS & CSS
	 *
	 * @return void
	 */
	public function settings_assets() {

	}

	/**
	 * Add settings link to plugin list table
	 *
	 * @param array $links Existing links.
	 *
	 * @return array        Modified links.
	 */
	public function add_settings_link( $links ) {
		if ( true !== $this->has ) {
			$settings_link =
				'<a style="color: #d63638" href="options-general.php?page=' . $this->parent->_token . '_settings">' . __( 'Settings', 'sqlite-object-cache' ) . '</a>';
			array_unshift( $links, $settings_link );
		} else {
			$settings_link   =
				'<a href="options-general.php?page=' . $this->parent->_token . '_settings">' . __( 'Settings', 'sqlite-object-cache' ) . '</a>';
			$statistics_link =
				'<a href="options-general.php?page=' . $this->parent->_token . '_settings&tab=stats">' . __( 'Statistics', 'sqlite-object-cache' ) . '</a>';
			array_unshift( $links, $settings_link, $statistics_link );
		}

		return $links;
	}

	/**
	 * Register plugin settings
	 *
	 * @return void
	 */
	public function register_settings() {
		if ( is_array( $this->settings ) ) {

			// Check posted/selected tab.
			//phpcs:disable
			$current_section = '';
			if ( isset( $_POST['tab'] ) && $_POST['tab'] ) {
				$current_section = $_POST['tab'];
			} else {
				if ( isset( $_GET['tab'] ) && $_GET['tab'] ) {
					$current_section = $_GET['tab'];
				}
			}
			//phpcs:enable

			foreach ( $this->settings as $section => $data ) {

				if ( $current_section && $current_section !== $section ) {
					continue;
				}

				// Add section to page.
				add_settings_section( $section, $data['title'],
					[ $this, 'settings_section' ],
					$this->parent->_token . '_settings' );

				$default_option = [];

				if ( array_key_exists( 'fields', $data ) && is_array( $data['fields'] ) ) {
					foreach ( $data['fields'] as $field ) {
						$default_option [ $field['id'] ] = $field['default'];
					}

					$setting_args = [
						'description' => $data['title'],
						'default'     => $default_option,
					];

					// Validation callback for section.
					$option_name = $this->base . 'settings';
					if ( isset( $data['callback'] ) ) {
						add_filter( "sanitize_option_$option_name", $data['callback'], 10, 3 );
					}

					/* register the settings object */
					register_setting( $this->parent->_token . '_settings', $option_name, $setting_args );

					foreach ( $data['fields'] as $field ) {
						// Add field to page.
						add_settings_field(
							$field['id'],
							$field['label'],
							[ $this->parent->admin, 'display_field' ],
							$this->parent->_token . '_settings',
							$section,
							[
								'field'  => $field,
								'option' => $option_name,
							]
						);
					}

					if ( ! $current_section ) {
						break;
					}
				}
			}
		}
	}

	/**
	 * Settings section.
	 *
	 * @param array $section Array of section ids.
	 *
	 * @return void
	 */
	public function settings_section( $section ) {
		$html = '';

		/** @noinspection HtmlUnknownTarget */
		$hyperlink     = '<a href="%s" target="_blank">%s</a>';
		$supportUrl    = "https://wordpress.org/support/plugin/sqlite-object-cache/";
		$reviewUrl     = "https://wordpress.org/support/plugin/sqlite-object-cache/reviews/";
		$clickHere     = __( 'click here', 'sqlite-object-cache' );
		$support       = sprintf( $hyperlink, $supportUrl, $clickHere );
		$review        = sprintf( $hyperlink, $reviewUrl, $clickHere );
		$supportString =
			/* translators: 1: embeds "For help please ..."  2: hyperlink to review page on wp.org */
			'<p>' . __( 'For support please %1$s.  Please %2$s to rate this plugin. Your feedback helps make it better, faster, and more useful.', 'sqlite-object-cache' ) . '</p>';
		$supportString = sprintf( $supportString, $support, $review );

		$html .= $supportString;

		global $wp_object_cache;
		if ( method_exists( $wp_object_cache, 'sqlite_get_version' ) ) {
			$html .= '<p>' . sprintf(
				/* translators: 1: version for sqlite   2: version for php  3: version for plugin */
					esc_html__( 'Versions: SQLite: %1$s  php: %2$s  Plugin: %3$s.', 'sqlite-object-cache' ),
					esc_html( $wp_object_cache->sqlite_get_version() ),
					esc_html( phpversion() ),
					esc_html( $this->parent->_version ) ) . '</p>';
		}
		if ( array_key_exists( 'description', $this->settings[ $section['id'] ] ) ) {
			$html .= '<p> ' . $this->settings[ $section['id'] ]['description'] . '</p>' . "\n";
		}

		if ( 'stats' === $section['id'] ) {

			$stats = new SQLite_Object_Cache_Statistics ();
			$stats->init();

			$html .= $stats->render();
		}
		echo $html; //phpcs:ignore
	}

	/**
	 * Load settings page content.
	 *
	 * @return void
	 */
	public function settings_page() {

		// Build page HTML.
		$html = '<div class="wrap" id="' . $this->parent->_token . '_settings">' . "\n";
		$html .= '<h2>' . __( 'SQLite Persistent Object Cache Settings', 'sqlite-object-cache' ) . '</h2>' . "\n";

		$tab = '';
		//phpcs:disable
		if ( isset( $_GET['tab'] ) && $_GET['tab'] ) {
			$tab .= $_GET['tab'];
		}
		//phpcs:enable

		$html .= $this->complain_if_sqlite3_unavailable( true );

		// Show page tabs.
		if ( is_array( $this->settings ) && 1 < count( $this->settings ) ) {

			$html .= '<h2 class="nav-tab-wrapper">' . "\n";

			$c = 0;
			foreach ( $this->settings as $section => $data ) {

				// Set tab class.
				$class = 'nav-tab';
				if ( ! isset( $_GET['tab'] ) ) { //phpcs:ignore
					if ( 0 === $c ) {
						$class .= ' nav-tab-active';
					}
				} else {
					if ( $section == $_GET['tab'] ) { //phpcs:ignore
						$class .= ' nav-tab-active';
					}
				}

				// Set tab link.
				$tab_link = add_query_arg( [ 'tab' => $section ] );
				if ( isset( $_GET['settings-updated'] ) ) { //phpcs:ignore
					$tab_link = remove_query_arg( 'settings-updated', $tab_link );
				}

				// Output tab.
				$html .= '<a href="' . $tab_link . '" class="' . esc_attr( $class ) . '">' . esc_html( $data['title'] ) . '</a>' . "\n";

				++ $c;
			}

			$html .= '</h2>' . "\n";
		}

		/** @noinspection HtmlUnknownTarget */
		$html .= '<form method="post" action="options.php" enctype="multipart/form-data">' . "\n";

		// Get settings fields.
		ob_start();
		settings_fields( $this->parent->_token . '_settings' );
		do_settings_sections( $this->parent->_token . '_settings' );
		$html .= ob_get_clean();

		$html .= '<p class="submit">' . "\n";
		$html .= '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />' . "\n";
		$html .= '<input name="Submit" type="submit" class="button-primary" value="' . esc_attr( __( 'Save Settings', 'sqlite-object-cache' ) ) . '" />' . "\n";
		$html .= '</p>' . "\n";
		$html .= '</form>' . "\n";
		$html .= '</div>' . "\n";

		echo $html; //phpcs:ignore
	}

	/**
	 * Admin enqueue assets
	 *
	 * @param string $hook Hook parameter.
	 *
	 * @return void
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function enqueue_assets( $hook = '' ) {
		wp_register_style( $this->parent->_token . '-admin',
			esc_url( $this->parent->assets_url ) . 'css/admin.css',
			[], $this->parent->_version );
		wp_enqueue_style( $this->parent->_token . '-admin' );
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cloning of SQLite_Object_Cache_API is forbidden.' ) ), esc_attr( $this->parent->_version ) );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Unserializing instances of SQLite_Object_Cache_API is forbidden.' ) ), esc_attr( $this->parent->_version ) );
	}
}
