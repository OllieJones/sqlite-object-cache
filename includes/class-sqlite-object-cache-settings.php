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

  static $statistics_help_url = 'https://www.plumislandmedia.net/wordpress-plugins/sqlite-object-cache/statistics-from-sqlite-object-cache/';

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
   * @var string The plugin file name.
   */
  private $plugin_file;
  /**
   * @var mixed
   */
  private $caught_option_value = false;

  /**
   * Constructor function.
   *
   * @param object $parent Parent object.
   * @param string $plugin_file Plugin top-level file name.
   */
  public function __construct( $parent, $plugin_file ) {
    $this->parent      = $parent;
    $this->has         = $parent->has_sqlite();
    $this->base        = 'sqlite_object_cache_';
    $this->plugin_file = $plugin_file;

    // Register plugin settings.
    add_action( 'admin_init', array( $this, 'register_my_settings' ) );

    if ( is_main_site() ) {
      // Information for Site Health Info
      add_filter( 'debug_information', array( $this, 'debug_information' ) );

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
    }

    // For multisite, propagate the option value to all subsites.
    if ( is_multisite() ) {
      add_action( 'update_option_' . $this->parent->_token . '_settings', array( $this, 'catch_option' ), 20, 3 );
    }

    // Configure placement of plugin settings page. See readme for implementation.
    add_filter( $this->base . 'menu_settings', array( $this, 'configure_settings' ) );

    // Spoonsor link.
    add_filter( 'plugin_row_meta', array( $this, 'filter_plugin_row_meta' ), 10, 2 );

  }

  /**
   * Fires after the value of our option has been successfully updated.
   *
   * @param mixed $old_value The old option value.
   * @param mixed $value The new option value.
   * @param string $option Option name.
   */
  public function catch_option( $old_value, $value, $option ) {
    if ( false === $this->caught_option_value ) {
      add_action( 'shutdown', array( $this, 'propagate_option' ) );
    }
    $this->caught_option_value = $value;
  }

  /**
   * Shutdown action handler to copy our option to all subsites.
   *
   * We use this rather than a site option because this gets us autoloading.
   *
   * @return void
   */
  public function propagate_option() {
    remove_action( 'update_option_' . $this->parent->_token . '_settings', array( $this, 'catch_option' ), 20 );
    $current_site = get_current_blog_id();
    foreach (
      get_sites( array(
        'number'        => 0,
        'fields'        => 'ids',
        'no_found_rows' => true,
        'orderby'       => false
      ) ) as $site_id
    ) {
      if ( $site_id !== $current_site ) {
        try {
          switch_to_blog( $site_id );
          update_option( $this->parent->_token . '_settings', $this->caught_option_value, true );
        } catch ( Exception $ex ) {
          /* Empty, intentionally. Don't crash on asset cleanup. */
        } finally {
          restore_current_blog();
        }
      }
    }
  }

  /**
   * Filters the array of row meta for each plugin in the Plugins list table.
   *
   * @param array<int, string> $plugin_meta An array of the plugin's metadata.
   * @param string $plugin_file Path to the plugin file relative to the plugins directory.
   *
   * @return array<int, string> Updated array of the plugin's metadata.
   */
  public function filter_plugin_row_meta( array $plugin_meta, $plugin_file ) {
    if ( $this->plugin_file !== $plugin_file ) {
      return $plugin_meta;
    }

    if ( is_multisite() && ( is_network_admin() || ! is_main_site() ) ) {
      $plugin_meta[] =
        esc_html__( 'See the main site\'s dashboard for settings.', 'sqlite-object-cache' );
    }

    /** @noinspection HtmlUnknownTarget */
    $plugin_meta[] = sprintf(
      '<a href="%1$s"><span class="dashicons dashicons-star-filled" aria-hidden="true" style="font-size:14px;line-height:1.3"></span>%2$s</a>',
      'https://github.com/sponsors/OllieJones',
      esc_html_x( 'Sponsor', 'verb', 'sqlite-object-cache' )
    );

    return $plugin_meta;
  }

  /**
   * Build settings fields
   *
   * @return array Fields to be displayed on settings page
   */
  private function settings_fields() {

    $fields = array(
      array(
        'id'          => 'flush',
        'label'       => __( 'Flush now', 'sqlite-object-cache' ),
        'description' => __( 'Check to flush the cache (delete all its entries, including all transients) now.', 'sqlite-object-cache' ) . ' ' .
                         __( 'This briefly puts your site into maintenance mode.', 'sqlite-object-cache' ),
        'type'        => 'checkbox',
        'default'     => '',
        'reset'       => '',
      ),
      array(
        'id'          => 'vacuum',
        'label'       => __( 'Vacuum now', 'sqlite-object-cache' ),
        'description' => __( 'Check to vacuum (defragment) the cache now.', 'sqlite-object-cache' ) . ' ' .
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
        'description' => __( 'Check to enable cache performance measurement. ', 'sqlite-object-cache' ),
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
      array(
        'id'          => 'adminbarflush',
        'label'       => __( 'Show', 'sqlite-object-cache' ),
        'description' => __( 'Flush Object Cache button in the admin bar.', 'sqlite-object-cache' ),
        'type'        => 'checkbox',
        'default'     => '',
      )
    );

    if ( $this->parent->apcu_extension_is_enabled() ) {
      array_unshift( $fields, array(
        'id'          => 'use_apcu',
        'label'       => __( 'Use APCu', 'sqlite-object-cache' ),
        'description' => __( 'Check to enable the use of php\'s APCu cache to improve performance.', 'sqlite-object-cache' ),
        'type'        => 'checkbox',
        'default'     => '',
      ) );

    }

    $settings['standard'] = array(
      // Using core I18n.
      // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
      'title'                 => __( 'Settings' ),
      // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
      'submit'                => __( 'Save Changes' ),
      'description'           => '',
      'render_section_header' => array( $this, 'settings_section_header' ),
      'form_post_callback'    => array( $this, 'validate_settings' ),
      'fields'                => $fields,
    );

    $settings['stats'] = array(
      'title'                 => __( 'Statistics', 'sqlite-object-cache' ),
      'submit'                => __( 'Reset Statistics', 'sqlite-object-cache' ),
      'render_section_header' => array( $this, 'stats_section_header' ),
      'form_post_callback'    => array( $this, 'validate_reset_stats' ),
    );

    return apply_filters( 'sqlite_object_cache_settings_fields', $settings );
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
      /* Weird. not an option array */
      return $option;
    }
    global $wp_object_cache;
    $former_value = get_option( $name, array() );

    /* Opt in or opt out of the APCu. */
    if ( $this->parent->apcu_extension_is_enabled() ) {
      $apcu_choice = array_key_exists( 'use_apcu', $option ) && $option ['use_apcu'] === 'on';
      $apcu_former = array_key_exists( 'use_apcu', $former_value ) && $former_value ['use_apcu'] === 'on';
      if ( $apcu_choice !== $apcu_former ) {
        if ( method_exists( $wp_object_cache, 'apcu_clear_cache' ) ) {
          $wp_object_cache->apcu_clear_cache();
        }
        $this->update_wp_config_constant( 'WP_SQLITE_OBJECT_CACHE_APCU', $apcu_choice );
        $option['use_apcu_updated'] = true;
      }
      $option['use_apcu'] = $apcu_choice ? 'on' : 'off';
    }
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
    if ( array_key_exists( 'vacuum', $option ) && $option ['vacuum'] === 'on' ) {
      if ( method_exists( $wp_object_cache, 'vacuum' ) ) {
        try {
          $this->enter_maintenance_mode();
          $wp_object_cache->vacuum();
        } finally {
          $this->exit_maintenance_mode();
        }
      } else {
        wp_cache_flush();
      }
      unset ( $option['vacuum'] );
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
      'sqlite_object_cachemenu_settings',
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
    $this->parent->sync_apcu_global_to_option( false );
    $this->settings = $this->settings_fields();

    if ( is_array( $this->settings ) ) {

      /* get the tab chosen by the user ('standard' or 'stats') */
      // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
              array( $this, 'echo_field' ),
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
    $this->apcu_admonition();
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

  private function apcu_admonition() {
    echo '<p>';
    echo esc_html__( 'You can use WP-CLI to configure this plugin. Please type', 'sqlite-object-cache' ) . ' ';
    echo '<code>wp help sqlite-object-cache</code> ';
    echo esc_html__( 'into your shell for details', 'sqlite-object-cache' ) . '.';
    echo '</p>';
    $option       = get_option( $this->parent->_token . '_settings', array() );
    $apcu_active  = array_key_exists( 'use_apcu', $option ) && 'on' == $option['use_apcu'];
    $apcu_enabled = $this->parent->apcu_extension_is_enabled();
    if ( $apcu_enabled && ! $apcu_active ) {
      echo '<p>';
      echo esc_html__( 'On your site you can use php\'s', 'sqlite-object-cache' ) . ' ';
      echo '<a href="https://www.php.net/manual/en/book.apcu.php" target="_blank">' . esc_html__( 'APCu User Cache', 'sqlite-object-cache' ) . '</a>  ';
      echo esc_html__( 'to improve the performance of this SQLite Object Cache. ', 'sqlite-object-cache' );
      echo esc_html__( 'To enable APCu caching please check the "Use APCu" box.', 'sqlite-object-cache' ) . ' ';
      echo '</p>';
    }
    if ( $apcu_enabled && $apcu_active ) {
      echo '<p>';
      echo esc_html__( 'You are using php\'s', 'sqlite-object-cache' ) . ' ';
      echo '<a href="https://www.php.net/manual/en/book.apcu.php" target="_blank">' . esc_html__( 'APCu User Cache', 'sqlite-object-cache' ) . '</a>  ';
      echo esc_html__( 'to improve the performance of this SQLite Object Cache. ', 'sqlite-object-cache' ) . ' ';
      echo esc_html__( 'To disable APCu caching please uncheck the "Use APCu" box.', 'sqlite-object-cache' ) . ' ';
      echo '</p>';
    }
    if ( ! $apcu_enabled ) {
      echo '<p>';
      echo esc_html__( 'This object cache performs better when used with php\'s', 'sqlite-object-cache' ) . ' ';
      echo '<a href="https://www.php.net/manual/en/book.apcu.php" target="_blank">' . esc_html__( 'APCu User Cache', 'sqlite-object-cache' ) . '</a>  ';
      echo esc_html__( 'extension. ', 'sqlite-object-cache' ) . ' ';
      echo esc_html__( 'However, it is not available on your server.', 'sqlite-object-cache' ) . ' ';
      echo esc_html__( 'It may be possible for you or your hosting service to install it.', 'sqlite-object-cache' ) . ' ';
      echo '</p>';
    }
  }

  /**
   * Display versions.
   *
   * @return void
   * @throws Exception Announce database failure.
   */
  private function versions() {
    global $wp_object_cache;
    global $wp_version;
    $igbinary        = function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' )
      ? phpversion( 'igbinary' )
      : __( 'unavailable', 'sqlite-object-cache' );
    $force_serialize = defined( 'WP_SQLITE_OBJECT_CACHE_SERIALIZE' ) && WP_SQLITE_OBJECT_CACHE_SERIALIZE;
    $igbinary        .= $force_serialize ? esc_html__( '(disabled by WP_SQLITE_OBJECT_CACHE_SERIALIZE)', 'sqlite-object-cache' ) : '';

    $apcu_version = $this->parent->apcu_extension_is_enabled()
      ? phpversion( "apcu" )
      : __( 'unavailable', 'sqlite-object-cache' );

    if ( method_exists( $wp_object_cache, 'sqlite_get_version' ) ) {
      $server_software = 'unk';
      // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
      if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ) {
        $server_software = $_SERVER['SERVER_SOFTWARE'];
      }
      // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
      echo '<p>' . esc_html( sprintf(
        /* translators: 1: version for sqlite   2: version for php  3: webserver version 4: version for plugin  5: igbinary  6:APCu  7:WordPress */
          __( 'Versions: WordPress: %7$s  SQLite: %1$s  php: %2$s  Server: %3$s Plugin: %4$s  APCu: %6$s  igbinary: %5$s.', 'sqlite-object-cache' ),
          $wp_object_cache->sqlite_get_version(),
          PHP_VERSION,
          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
          $server_software,
          $this->parent->_version,
          $igbinary,
          $apcu_version,
          $wp_version ) ) . '</p>';
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

    $this->enqueue_assets();
    /* get the tab chosen by the user ('standard' by default or 'stats') */
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

      if ( 'stats' === $tab ) {
        echo '<a href="' . esc_url( self::$statistics_help_url ) . '" class="nav-tab" target="_blank">' . esc_html__( 'Help', 'sqlite-object-cache' ) . '</a>' . PHP_EOL;
      }

      echo '</h2>' . PHP_EOL;
    }

    /** @noinspection HtmlUnknownTarget */
    echo '<form method="post" action="options.php" enctype="multipart/form-data">' . PHP_EOL;

    // Get settings fields.
    settings_fields( 'sqlite_object_cache_settings' );
    do_settings_sections( 'sqlite_object_cache_settings' );

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
    wp_delete_file( $maintenanceFileName );
  }

  /**
   * Update a variable in the wp-config.php file.
   *
   * If enabling, check if the variable is defined, and if not, define it.
   * Vice versa for disabling.
   */
  private function update_wp_config_constant( $symbol, $enable ) {

    /**
     * Follow WP's logic to locate wp-config file
     * @see wp-load.php
     */
    $conf_file = ABSPATH . 'wp-config.php';
    if ( ! file_exists( $conf_file ) ) {
      $conf_file = dirname( ABSPATH ) . '/wp-config.php';
    }
    $config = new SQLite_Object_Cache_File( $conf_file );
    // Remove the line `define('WHATEVER', true/false);` first
    $removed  = $config->remove( $symbol );
    $inserted = false;
    if ( $enable ) {
      $cmd      = "define( '" . $symbol . "', true );";
      $inserted = $config->insert_before( $cmd );
    }
    if ( $removed !== $inserted ) {
      $config->save();
    }
    return true;
  }

  /**
   * Put troubleshooting information into Site Health - Info
   *
   * @param array $info
   *
   * @return array
   */
  public function debug_information( $info ) {
    global $wp_object_cache;

    $stanza = array(
      'label'  => 'SQLite Object Cache',
      'fields' => array()
    );

    try {
      $option = get_option( $this->parent->_token . '_settings', array() );

      $stanza['fields']['configuration'] = array(
        'label' => __( 'Configuration', 'sqlite-object-cache' ),
        'value' => $this->flatten( $option ),
      );

    } catch ( \Exception $e ) {
      /* empty, intentionally */
    }

    ob_start();
    try {
      $this->versions();
      $versions                     = wp_strip_all_tags( ob_get_clean() );
      $stanza['fields']['versions'] = array(
        'label' => __( 'Versions', 'sqlite-object-cache' ),
        'value' => $versions,
      );
    } catch ( \Exception $e ) {
      ob_get_clean();
    }

    try {
      $settings = array();
      if ( $this->parent->apcu_extension_is_enabled() ) {
        $settings = array_merge( $settings, ini_get_all( 'apcu' ) );
      }
      $settings = array_merge( $settings, ini_get_all( 'sqlite3' ) );
      $settings = array_merge( $settings, $this->inis_get( 'session.save_handler', 'session.auto_start', 'session.lifetime', 'session.gc_maxlifetime' ) );

      $stanza['fields']['settings'] = array(
        'label' => __( 'Settings', 'sqlite-object-cache' ),
        'value' => $this->flatten( $settings ),
      );
    } catch ( \Exception $e ) {
      /* empty, intentionally */
    }

    try {
      if ( $this->parent->apcu_extension_is_enabled() ) {

        $sizes      = array();
        $counts     = array();
        $totalsize  = 0;
        $totalcount = 0;
        foreach ( new APCUIterator( null, APC_ITER_KEY | APC_ITER_MEM_SIZE ) as $item ) {
          $salt   = '?';
          $splits = explode( '|', $item['key'], 2 );
          if ( count( $splits ) >= 2 ) {
            $salt = $splits[0];
          }
          if ( ! array_key_exists( $salt, $sizes ) ) {
            $sizes[ $salt ]  = 0;
            $counts[ $salt ] = 0;
          }
          $sizes[ $salt ]   += $item['mem_size'];
          $totalsize        += $item['mem_size'];
          $counts [ $salt ] += 1;
          $totalcount       += 1;
        }

        $apcusalt           = trim( property_exists( $wp_object_cache, 'apcusalt' ) && is_string( $wp_object_cache->apcusalt ) ? $wp_object_cache->apcusalt : '', '|' );
        $settings           = array();
        $settings ['salt']  = $apcusalt;
        $settings ['total'] = "$totalsize($totalcount)";
        $siteno             = 1;
        foreach ( $sizes as $salt => $val ) {
          switch ( $salt ) {
            case $apcusalt:
              $csalt = 'mine';
              break;
            case '?':
              $csalt = 'no salt';
              break;
            default:
              $csalt = 'site' . $siteno ++;
              break;
          }
          $settings[ $csalt ] = "$val($counts[$salt])";
        }


        $stanza['fields']['apcu_utilization'] = array(
          'label' => __( 'APCu utilization', 'sqlite-object-cache' ),
          'value' => $this->flatten( $settings ),
        );

        $apcu_cache_info = array();
        $apcu_cache_info = array_merge( $apcu_cache_info, apcu_cache_info( true ) );
        $apcu_cache_info = array_merge( $apcu_cache_info, apcu_sma_info( true ) );

        $stanza['fields']['apcu_cache_info'] = array(
          'label' => __( 'APCu info', 'sqlite-object-cache' ),
          'value' => $this->flatten( $apcu_cache_info ),
        );


      }
    } catch ( \Exception $e ) {
      /* empty, intentionally */
    }

    try {
      global $wp_object_cache;
      if ( method_exists( $wp_object_cache, 'sqlite_sizes' ) ) {
        $stanza['fields']['sqlite_sizes'] = array(
          'label' => __( 'SQLite utilization', 'sqlite-object-cache' ),
          'value' => $this->flatten( $wp_object_cache->sqlite_sizes() ),
        );
      }
    } catch ( \Exception $e ) {
      /* empty, intentionally */
    }


    $info['sqlite-object-cache'] = $stanza;
    return $info;
  }

  private function inis_get( ...$list ) {
    $result = array();
    foreach ( $list as $item ) {
      $val             = ini_get( $item );
      $result[ $item ] = $val ?: '-missing-';
    }
    return $result;

  }

  private function flatten( $a ) {
    $result = array();
    foreach ( $a as $k => $v ) {
      if ( is_array( $v ) ) {
        if ( array_key_exists( 'local_value', $v ) ) {
          $v = $v['local_value'];
        } else {
          // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
          $v = var_export( $v, true );
        }
      }
      $result [] = $k . ':' . $v;
    }
    return implode( '; ', $result );
  }

  /**
   * Generate HTML for displaying fields.
   *
   * @param mixed $idata Data array.
   * @param object $post Post object.
   */
  public function echo_field( $idata = array(), $post = null ) {

    // Get field info.
    if ( isset( $idata['field'] ) ) {
      $field = $idata['field'];
    } else {
      $field = $idata;
    }

    $option_name = '';
    if ( isset( $idata['option'] ) ) {
      $option_name = $idata['option'];
    }

    /* Get saved data. */
    $field_id = $field['id'];
    $data     = null;

    /* Data to display, if set */
    $option = get_option( $option_name, array() );
    if ( is_array( $option ) && array_key_exists( $field_id, $option ) ) {
      $data = $option[ $field_id ];
    }

    /* the reset element sets the value of the field, overriding whatever is in the option */
    if ( array_key_exists( 'reset', $field ) ) {
      $data = $field['reset'];
    }

    /* Show default data if no option saved and default is supplied. */
    if ( null === $data && isset( $field['default'] ) ) {
      $data = $field['default'];
    } elseif ( null === $data ) {
      $data = '';
    }

    /* CSS class array */
    $classes = array();
    if ( array_key_exists( 'cssclass', $field ) ) {
      $classes = is_array( $field['cssclass'] ) ? $field['cssclass'] : array( $field['cssclass'] );
    }

    echo '<input ';
    echo 'id="' . esc_attr( $field['id'] ) . '" ';
    $this->echo_classes( $classes ) . ' ';
    echo 'name="' . esc_attr( $option_name ) . '[' . esc_attr( $field_id ) . ']" ';
    if ( array_key_exists( 'placeholder', $field ) ) {
      echo 'placeholder="' . esc_attr( $field['placeholder'] ) . '" ';
    }
    switch ( $field['type'] ) {

      case 'text':
      case 'url':
      case 'email':
        echo 'type="text" ';
        echo 'value="' . esc_attr( $data ) . '" ';
        break;

      case 'number':
        echo 'type="number" ';
        echo 'value="' . esc_attr( $data ) . '" ';
        if ( isset( $field['min'] ) ) {
          echo 'min="' . esc_attr( $field['min'] ) . '" ';
        }
        if ( isset( $field['max'] ) ) {
          echo 'max="' . esc_attr( $field['max'] ) . '" ';
        }
        if ( isset( $field['step'] ) ) {
          echo 'step="' . esc_attr( $field['step'] ) . '" ';
        }
        break;

      case 'password':
      case 'hidden':
        echo 'type="' . esc_attr( $field['type'] ) . '" ';
        echo 'value="' . esc_attr( $data ) . '" ';
        break;

      case 'checkbox':
        echo 'type="checkbox" ';
        if ( 'on' === $data ) {
          echo 'checked="checked" ';
        }
        break;
    }
    echo '>' . PHP_EOL;

    if ( ! $post ) {
      echo '<label for="' . esc_attr( $field['id'] ) . '">' . PHP_EOL;
    }

    echo '<span class="description">' . esc_html( $field['description'] ) . '</span>' . PHP_EOL;

    if ( ! $post ) {
      echo '</label>' . PHP_EOL;
    }
  }

  /**
   * Given an array of CSS class names ['foo', 'bar'] echo class="foo bar".
   *
   * If the array is empty just echo a space.
   *
   * @param array $classes
   *
   * @return void
   */
  private function echo_classes( array $classes ) {
    if ( count( $classes ) > 0 ) {
      echo 'class="';
      echo esc_html( implode( ' ', array_map( 'esc_attr', $classes ) ) );
      echo '"';
    }
    echo ' ';
  }


}
