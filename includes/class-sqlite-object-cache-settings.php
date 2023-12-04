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
 * Only make one instance of this, please.
 *
 * Settings class.
 */
class SQLite_Object_Cache_Settings {

  /**
   * The main plugin object.
   *
   * @var     object
   */
  public $parent;

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
  public $settings = array();

  /**
   * SQLite3 is available.
   *
   * @var     string|bool  If it's true, we're good. If it's a string, an explanation.
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
    add_action( 'init', array( $this, 'init_settings' ), 11 );

    // Register plugin settings.
    add_action( 'admin_init', array( $this, 'register_my_settings' ) );

    // Add settings page to menu.
    add_action( 'admin_menu', array( $this, 'add_menu_item' ) );

    // Add settings link to plugins page.
    add_filter(
      'plugin_action_links_' . plugin_basename( $this->parent->file ),
      array(
        $this,
        'add_settings_link',
      )
    );

    // Configure placement of plugin settings page. See readme for implementation.
    add_filter( $this->base . 'menu_settings', array( $this, 'configure_settings' ) );

    // Load admin JS & CSS.
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 1 );
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

    $settings['standard'] = array(
      'title'                 => __( 'Settings', 'sqlite-object-cache' ),
      'submit'                => __( 'Save Settings', 'sqlite-object-cache' ),
      'description'           => '',
      'render_section_header' => array( $this, 'settings_section_header' ),
      'form_post_callback'    => array( $this, 'validate_settings' ),
      'fields'                => array(
        array(
          'id'          => 'flush',
          'label'       => __( 'Flush now', 'sqlite-object-cache' ),
          'description' => __( 'Check to flush the cache (delete all its entries) now.', 'sqlite-object-cache' ) . ' ' .
                           __( 'This briefly puts your site into maintenance mode.', 'sqlite-object-cache' ),
          'type'        => 'checkbox',
          'default'     => '',
          'reset'       => '',
        ),
        array(
          'id'          => 'target_size',
          'label'       => __( 'Cached data size', 'sqlite-object-cache' ),
          'description' => __( 'MiB. When data in the cache grows larger than this, hourly cleanup removes the oldest entries.', 'sqlite-object-cache' ),
          'type'        => 'number',
          'default'     => 16,
          'min'         => 1,
          'step'        => 'any',
          'cssclass'    => 'narrow',
          'placeholder' => __( 'MiB.', 'sqlite-object-cache' ),
        ),
        array(
          'id'          => 'cleanup',
          'label'       => __( 'Clean up now', 'sqlite-object-cache' ),
          'description' => __( 'Check to clean up the cache (delete old data) now.', 'sqlite-object-cache' ),
          'type'        => 'checkbox',
          'default'     => '',
          'reset'       => '',
        ),
        array(
          'id'          => 'capture',
          'label'       => __( 'Measure performance', 'sqlite-object-cache' ),
          'description' => __( 'Check to measure cache performance. ', 'sqlite-object-cache' ),
          'type'        => 'checkbox',
          'default'     => '',
        ),
        array(
          'id'          => 'samplerate',
          'label'       => __( 'Measure', 'sqlite-object-cache' ),
          'description' => __( 'percent of requests, randomly sampled.', 'sqlite-object-cache' ),
          'type'        => 'number',
          'default'     => 1,
          'max'         => 100,
          'min'         => 0,
          'step'        => 'any',
          'cssclass'    => 'narrow',
          'placeholder' => __( 'Sampling percentage.', 'sqlite-object-cache' ),
        ),
        array(
          'id'          => 'retainmeasurements',
          'label'       => __( 'Retain measurements for', 'sqlite-object-cache' ),
          'description' => __( 'hours.', 'sqlite-object-cache' ),
          'type'        => 'number',
          'default'     => 2,
          'min'         => 0.1,
          'step'        => 'any',
          'cssclass'    => 'narrow',
          'placeholder' => __( 'Hours to retain.', 'sqlite-object-cache' ),
        ),
      ),
    );

    $settings['stats'] = array(
      'title'                 => __( 'Statistics', 'sqlite-object-cache' ),
      'submit'                => __( 'Reset Statistics', 'sqlite-object-cache' ),
      'render_section_header' => array( $this, 'stats_section_header' ),
      'form_post_callback'    => array( $this, 'validate_reset_stats' ),
    );

    return apply_filters( $this->parent->_token . '_settings_fields', $settings );
  }

  /**
   * Validate the options presented to the Settings tab.
   *
   * This is an options update filter. It filters an option value following sanitization.
   *
   * @param mixed $option The sanitized option value.
   * @param string $name The option name.
   * @param string $original_value The original value passed to the function.
   *
   * @throws Exception Announce SQLite failure.
   * @since 2.3.0
   * @since 4.3.0 Added the `$original_value` parameter.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public function validate_settings( $option, $name, $original_value ) {
    if ( ! is_array( $option ) ) {
      /* weird. not an option array */
      return $option;
    }
    global $wp_object_cache;

    if ( array_key_exists( 'flush', $option ) && $option ['flush'] === 'on' ) {
      if ( method_exists( $wp_object_cache, 'flush' ) ) {
        try {
          $this->enter_maintenance_mode();
          $wp_object_cache->flush( true );
        } finally {
          $this->exit_maintenance_mode();
        }
      } else {
        wp_cache_flush();
      }
      unset ( $option['flush'] );
    }
    if ( array_key_exists( 'cleanup', $option ) && $option ['cleanup'] === 'on' ) {
      $this->parent->clean_job();
      unset ( $option['cleanup'] );
    }

    $this->numeric_option( $option, 'target_size', 4 );
    $this->numeric_option( $option, 'samplerate', 10 );
    $this->numeric_option( $option, 'retainmeasurements', 2 );

    if ( array_key_exists( 'capture', $option ) && $option['capture'] === 'on' ) {
      $option['previouscapture'] = 0;
    }

    return $option;
  }

  /**
   * Retrieve a numeric option, and set the default if it isn't present.
   *
   * @param array $option Option array value.
   * @param string $name Name of the element in the option array.
   * @param mixed $default Default value to use.
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
  public function validate_reset_stats( $option, $name, $original_value ) {

    global $wp_object_cache;
    if ( method_exists( $wp_object_cache, 'sqlite_reset_statistics' ) ) {
      $wp_object_cache->sqlite_reset_statistics();
    }

    /* for this post operation, we don't want to change any plugin options. */

    return get_option( $name );
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
      add_action( 'admin_print_styles-' . $page, array( $this, 'settings_assets' ) );
    }
  }

  /**
   * Prepare default settings page arguments
   *
   * @return mixed|void
   */
  private function menu_settings() {
    $this->complain_if_sqlite3_unavailable();

    return apply_filters(
      $this->base . 'menu_settings',
      array(
        'location'    => 'options', // Possible settings: options, menu, submenu.
        'parent_slug' => 'options-general.php',
        'page_title'  => __( 'SQLite Persistent Object Cache', 'sqlite-object-cache' ),
        'menu_title'  => __( 'Object Cache', 'sqlite-object-cache' ),
        'capability'  => 'manage_options',
        'menu_slug'   => $this->parent->_token . '_settings',
        'function'    => array( $this, 'settings_page' ),
        'icon_url'    => '',
        'position'    => null,
      )
    );
  }

  /**
   * Emit a message if SQLite3 is not available.
   *
   * @param bool $verbose False means emit a notice.  True means emit a longer explanation.
   *
   * @return void
   */
  private function complain_if_sqlite3_unavailable( $verbose = false ) {
    if ( true !== $this->has ) {
      if ( ! $verbose ) {
        echo '<div class="notice notice-error sql-object-cache">';
        echo esc_html( $this->has );
        echo '</div>';
      } else {
        echo '<div>';
        echo '<h3 style="color: #d63638">' . esc_html__( 'Server configuration problem', 'sqlite-object-cache' ) . '</h3>';
        echo '<p>';

        if ( ! class_exists( 'SQLite3' ) || ! extension_loaded( 'sqlite3' ) ) {
          echo esc_html__( 'The SQLite Persistent Object Cache plugin requires php\'s SQLite3 extension.', 'sqlite-object-cache' ) . ' ';
          echo esc_html__( 'That extension is not installed in your server, so the plugin cannot work.', 'sqlite-object-cache' ) . ' ';
        }

        $sqlite_version = $this->parent->sqlite_get_version();
        if ( version_compare( $sqlite_version, $this->parent->minimum_sqlite_version ) < 0 ) {
          echo esc_html( sprintf(
          /* translators: 1 actual SQLite version. 2 required SQLite version) */
            __( 'You cannot use the SQLite Object Cache plugin. Your server only offers SQLite3 version %1$s, but at least %2$s is required.', 'sqlite-object-cache' ),
            $sqlite_version, $this->parent->minimum_sqlite_version ) );
        }

        echo '</p></div>' . PHP_EOL;
      }
    }
  }

  /**
   * Container for settings page arguments
   *
   * @param array $settings Settings array.
   *
   * @return array
   */
  public function configure_settings( $settings = array() ) {
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
  public function register_my_settings() {
    if ( is_array( $this->settings ) ) {

      /* get the tab chosen by the user ('standard' or 'stats') */
      $tab = isset ( $_REQUEST['tab'] ) ? sanitize_key( $_REQUEST['tab'] ) : 'standard';

      $default_option = array();
      $option_name    = $this->base . 'settings';

      foreach ( $this->settings as $section => $data ) {

        if ( $tab && $tab !== $section ) {
          continue;
        }

        $setting_args = array(
          'description' => $data['title'],
          'default'     => $default_option,
        );

        // Validation callback for section.
        if ( isset( $data['form_post_callback'] ) ) {
          add_filter( "sanitize_option_$option_name", $data['form_post_callback'], 10, 3 );
        }

        // Add section to page.
        add_settings_section( $section, '',
          $data ['render_section_header'],
          $this->parent->_token . '_settings' );

        if ( array_key_exists( 'fields', $data ) && is_array( $data['fields'] ) ) {
          foreach ( $data['fields'] as $field ) {
            $default_option [ $field['id'] ] = $field['default'];
          }

          foreach ( $data['fields'] as $field ) {
            // Add field to page.
            add_settings_field(
              $field['id'],
              $field['label'],
              array( $this->parent->admin, 'echo_field' ),
              $this->parent->_token . '_settings',
              $section,
              array(
                'field'  => $field,
                'option' => $option_name,
              )
            );
          }
        }
        /* register the settings object */
        register_setting( $this->parent->_token . '_settings', $option_name, $setting_args );
      }
    }
  }

  /**
   * Settings section.
   *
   * @param array $section Array of section ids.
   *
   * @return void
   * @throws Exception Announce Database Failure.
   */
  public function settings_section_header( $section ) {

    $this->support_links();
    $this->versions();

    if ( array_key_exists( 'description', $this->settings[ $section['id'] ] ) ) {
      echo '<p> ' . esc_html( $this->settings[ $section['id'] ]['description'] ) . '</p>' . PHP_EOL;
    }
  }

  /**
   * Display support and rating links.
   *
   * @return void
   */
  private function support_links() {

    $supportUrl = "https://wordpress.org/support/plugin/sqlite-object-cache/";
    $reviewUrl  = "https://wordpress.org/support/plugin/sqlite-object-cache/reviews/";
    echo '<p>';
    echo esc_html__( 'For support please', 'sqlite-object-cache' ) . ' ';
    echo '<a href="' . esc_url( $supportUrl ) . '">' . esc_html__( 'click here', 'sqlite-object-cache' ) . '</a>. ';
    echo esc_html__( 'To rate this plugin please', 'sqlite-object-cache' ) . ' ';
    echo '<a href="' . esc_url( $reviewUrl ) . '">' . esc_html__( 'click here', 'sqlite-object-cache' ) . '</a>. ';
    echo esc_html__( 'Your feedback helps make it better, faster, and more useful', 'sqlite-object-cache' ) . '.';
    echo '</p>';
  }

  /**
   * Display versions.
   *
   * @return void
   * @throws Exception Announce database failure.
   */
  private function versions() {
    global $wp_object_cache;
    $igbinary = function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' )
      ? __( 'available', 'sqlite-object-cache' )
      : __( 'unavailable', 'sqlite-object-cache' );

    if ( method_exists( $wp_object_cache, 'sqlite_get_version' ) ) {
      echo '<p>' . esc_html( sprintf(
        /* translators: 1: version for sqlite   2: version for php  3: webserver version 4: version for plugin  5: status of igbinary */
          __( 'Versions: SQLite: %1$s  php: %2$s  Server: %3$s Plugin: %4$s  igbinary: %5$s.', 'sqlite-object-cache' ),
          $wp_object_cache->sqlite_get_version(),
          PHP_VERSION,
          $_SERVER['SERVER_SOFTWARE'],
          $this->parent->_version,
          $igbinary ) ) . '</p>';
    }
  }

  /**
   * Statistics tab section.
   *
   * @param array $section Array of section ids.
   *
   * @return void
   * @throws Exception Announce Database Failure.
   * @noinspection PhpUnusedParameterInspection
   */
  public function stats_section_header( $section ) {

    $this->support_links();
    $this->versions();

    $stats = new SQLite_Object_Cache_Statistics ( get_option( $this->parent->_token . '_settings', array() ) );
    $stats->init();
    $stats->render();
    $stats->render_usage();
  }

  /**
   * Load settings page content.
   *
   * @return void
   */
  public function settings_page() {

    /* get the tab chosen by the user ('standard' by default or 'stats') */
    $tab = isset ( $_REQUEST['tab'] ) ? sanitize_key( $_REQUEST['tab'] ) : 'standard';

    // Build page HTML.
    echo '<div class="wrap" id="' . esc_attr( $this->parent->_token . '_settings' ) . '">' . PHP_EOL;
    echo '<h2>' . esc_html__( 'SQLite Persistent Object Cache', 'sqlite-object-cache' ) . '</h2>' . PHP_EOL;

    $submit_caption = __( 'Save Settings', 'sqlite-object-cache' );
    $this->complain_if_sqlite3_unavailable( true );

    // Show page tabs.
    if ( is_array( $this->settings ) && 1 < count( $this->settings ) ) {

      echo '<h2 class="nav-tab-wrapper">' . PHP_EOL;

      foreach ( $this->settings as $section => $data ) {

        // Set tab class.
        $class = 'nav-tab';
        if ( $section === $tab ) {
          $class          .= ' nav-tab-active';
          $submit_caption = $data['submit'];
        }

        // Set tab link.
        $tab_link = add_query_arg( array( 'tab' => $section ) );
        $tab_link = remove_query_arg( 'settings-updated', $tab_link );

        // Output tab.
        echo '<a href="' . esc_url( $tab_link ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $data['title'] ) . '</a>' . PHP_EOL;
      }

      echo '</h2>' . PHP_EOL;
    }

    /** @noinspection HtmlUnknownTarget */
    echo '<form method="post" action="options.php" enctype="multipart/form-data">' . PHP_EOL;

    // Get settings fields.
    settings_fields( $this->parent->_token . '_settings' );
    do_settings_sections( $this->parent->_token . '_settings' );

    echo '<p class="submit">' . PHP_EOL;
    echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />' . PHP_EOL;
    echo '<input name="Submit" type="submit" class="button-primary" value="' . esc_attr( $submit_caption ) . '" />' . PHP_EOL;
    echo '</p></form></div>' . PHP_EOL;
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
      array(), $this->parent->_version );
    wp_enqueue_style( $this->parent->_token . '-admin' );
  }

  /**
   * Enters maintenance mode.
   *
   * @return void
   */
  private function enter_maintenance_mode() {
    $maintenanceFileName = ABSPATH . '.maintenance';
    $maintain            = array();
    array_push( $maintain,
      '<?php',
      '$upgrading = ' . time() . ';',
      '?>' );
    file_put_contents( $maintenanceFileName, implode( PHP_EOL, $maintain ) );
  }

  /**
   * Exits maintenance mode.
   *
   * @return void
   */
  private function exit_maintenance_mode() {
    $maintenanceFileName = ABSPATH . '.maintenance';
    unlink( $maintenanceFileName );
  }
}
