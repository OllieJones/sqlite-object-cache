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
     * Constructor function.
     *
     * @param string $file File constructor.
     * @param string $version Plugin version.
     */
    public function __construct( $file = '', $version = '1.6.3' ) {
        $this->_version = $version;
        $this->_token   = 'sqlite_object_cache';

        // Load plugin environment variables.
        $this->file             = $file;
        $this->dir              =
                trailingslashit( dirname( trailingslashit( WP_PLUGIN_DIR ) . plugin_basename( $this->file ) ) );
        $this->assets_dir       = trailingslashit( $this->dir . 'assets' );
        $this->dropinfilesource = $this->assets_dir . 'drop-in/object-cache.php';
        $this->dropinfiledest   = trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';

        $this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

        register_activation_hook( $this->file, array( $this, 'on_activation' ) );
        register_deactivation_hook( $this->file, array( $this, 'on_deactivation' ) );

        if ( is_admin() ) {
            /* Suppress backups of .sqlite files if possible. */
            new SQLite_Backup_Exclusion();

            /* Health check */
            new SQLite_Object_Cache_Opcache();

            add_action( 'admin_notices', function () {
                $flushed = get_site_transient( 'sqlite-object-cache-flush-on-update' );
                if ( $flushed ) {
                    delete_site_transient( 'sqlite-object-cache-flush-on-update' );
                    $message = __( 'Flushed the SQLite Object Cache successfully after a software installation or upgrade.', 'sqlite-object-cache' );
                    ?>
                    <div class="notice notice-success sqlite-object-cache is-dismissible">
                        <p><?php echo esc_html( $message ); ?></p>
                    </div>
                    <?php
                }
            } );
        }

        add_action( 'admin_init', array( $this, 'maybe_update_dropin' ) );

        /* Ensure nothing is cached after software upgrades. */
        add_action( 'upgrader_process_complete', function () {
            add_action( 'shutdown', function () {
                wp_cache_flush();
                set_site_transient( 'sqlite-object-cache-flush-on-update', true, HOUR_IN_SECONDS );
            }, 999 );
            wp_cache_flush();
        }, 0 );


        /* Handle cron cache cleanup */
        add_action( self::CLEAN_EVENT_HOOK, array( $this, 'clean_job' ), 10, 0 );
        if ( ! wp_next_scheduled( self::CLEAN_EVENT_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEAN_EVENT_HOOK );
        }
        /* Handle probabilistic non-cron cleanup, one request in 2500. */
        /* We don't need cryptographic-grade random numbering here. */
        // phpcs:ignore 	WordPress.WP.AlternativeFunctions.rand_rand
        if ( 1 === rand( 1, 2500 ) ) {
            add_action( 'shutdown', function () {
                $this->clean_job( 1.25 );
            }, 999, 0 );
        }

        /* Admin bar flush button - works on both frontend and backend. */
        $option = get_option( $this->_token . '_settings', array() );
        if ( array_key_exists ('adminbarflush', $option ) && 'on' === $option['adminbarflush'] ) {
            add_action( 'init', array( $this, 'handle_admin_bar_flush' ) );
            add_action ( 'init', function() {
                if ( ! ( is_multisite() && ! is_main_site() ) &&  current_user_can( 'manage_options') ) {
                    add_action( 'admin_bar_menu', array( $this, 'admin_bar_flush_button' ), 100 );
                    add_action( 'admin_notices', array( $this, 'maybe_show_flush_notice' ) );
                }
            });
        }
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
        /* Clean up old statistics. Do this even when the cache is not over size. */
        $retention = empty ( $option['retainmeasurements'] ) ? 24 : $option['retainmeasurements'];
        $wp_object_cache->sqlite_reset_statistics( $retention * HOUR_IN_SECONDS );

        $current_size = $wp_object_cache->sqlite_get_size();
        /* Skip this if the current size is small enough. */
        if ( $current_size <= $threshold_size ) {
            return;
        }

        /* Remove expired items (transients mostly). */
        if ( $wp_object_cache->sqlite_remove_expired() ) {
            /* If anything was removed, get the size again. */
            $current_size = $wp_object_cache->sqlite_get_size();
        }

        /* Skip this if the size is small enough, after removing expired items. */
        if ( $current_size <= $threshold_size ) {
            return;
        }

        /* Delete the least-recently-updated items to get to the target size. */
        $wp_object_cache->sqlite_delete_old( $target_size, $current_size );
    }

    /**
     * Plugin activation hook
     *
     * @return void
     */
    public function on_activation() {

        $this->sync_apcu_global_to_option( true );
        if ( true === $this->has_sqlite() ) {
            add_action( 'shutdown', array( $this, 'update_dropin' ) );
            add_action( 'shutdown', array( $this, 'delete_all_transients_from_db' ) );
        }
    }

    /**
     * Determine whether we can use SQLite3.
     *
     * @return bool|string true, or an error message.
     */
    public function has_sqlite() {
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
    public function sqlite_get_version() {

        if ( class_exists( 'SQLite3' ) ) {
            $version = SQLite3::version();

            return $version['versionString'];
        }

        return false;
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
     * @param bool $silent Whether to ask the user for credentials if necessary or not.
     *
     * @return bool
     * @author Till Krüss
     *
     */
    public function initialize_filesystem( $url, $silent = false ) {
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
    public function maybe_update_dropin() {
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
    public function object_cache_dropin_needs_updating() {
        global $wp_object_cache;
        if ( ! method_exists( $wp_object_cache, 'dropin_get_version' ) ) {
            return true;
        }
        return version_compare( $wp_object_cache->dropin_get_version(), $this->_version, '<' );
    }

    /**
     * Updates the `object-cache.php` drop-in
     *
     * @return void
     * @author Till Krüss
     */
    public function update_dropin() {
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
        global $wp_object_cache;

        if ( ! method_exists( $wp_object_cache, 'dropin_get_version' ) ) {
            return false;
        }

        $dropin = get_plugin_data( $this->dropinfiledest );
        $plugin = get_plugin_data( $this->dropinfilesource );

        /**
         * Filters the drop-in validation state
         *
         * @param bool $state The validation state of the drop-in.
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
    public function on_deactivation( $plugin ) {

        wp_cache_flush();

        wp_unschedule_hook( self::CLEAN_EVENT_HOOK );
        $this->delete_sqlite_files();
        $this->delete_dropin();
        $this->delete_all_transients_from_db();
    }

    private function delete_sqlite_files() {
        global $wp_filesystem;
        global $wp_object_cache;

        if ( method_exists( $wp_object_cache, 'sqlite_files' ) ) {
            if ( $this->initialize_filesystem( '', true ) ) {
                foreach ( $wp_object_cache->sqlite_files() as $file ) {
                    $wp_filesystem->delete( $file );
                }
            }
        }
    }

    private function delete_dropin() {
        global $wp_filesystem;
        ob_start();

        if ( $this->validate_object_cache_dropin() && $this->initialize_filesystem( '', true ) ) {
            $wp_filesystem->delete( $this->dropinfiledest );
        }

        ob_end_clean();
    }

    /**
     * Deletes all transients from the database.
     *
     * It is necessary to do this when deactivating a persistent object cache
     * because the transients are kept there, and the ones in the database are
     * possibly stale.
     *
     * This hammers performance. But deactivating a persistent object cache is
     * not a common operation.
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     */
    public function delete_all_transients_from_db() {
        global $wpdb;
        if ( ! is_multisite() ) {
            $this->clear_blog_transients();
            // Single site stores site transients in the options table.
            $this->clear_blog_transients( '_site_transient_' );
        } else {
            foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => false ) ) as $site_id ) {
                try {
                    switch_to_blog( $site_id );
                    $this->clear_blog_transients();
                } catch ( Exception $ex ) {
                    /* Empty, intentionally. Don't crash clearing transients. */
                } finally {
                    restore_current_blog();
                }
            }
            // Multisite stores site transients in the sitemeta table.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                    $wpdb->prepare(
                    "DELETE FROM {$wpdb->sitemeta} WHERE a.meta_key LIKE %s",
                            $wpdb->esc_like( '_site_transient_' ) . '%'
                    )
            );

        }
    }

    /**
     * DELETE transients from the current blog's options table.
     *
     * @param string $tag _site_transient_ or _transient_, the default.
     *
     * @return void
     */
    private function clear_blog_transients( $tag = '_transient_') {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
                $wpdb->prepare(
                        "DELETE FROM {$wpdb->options}	WHERE option_name LIKE %s",
                        $wpdb->esc_like( $tag ) . '%'
                )
        );
    }

    /**
     * @return bool True if APCu support is activated for this plugin.
     */
    public function apcu_is_activated() {
        return defined( 'WP_SQLITE_OBJECT_CACHE_APCU' ) && WP_SQLITE_OBJECT_CACHE_APCU
               && $this->apcu_extension_is_enabled();
    }

    /**
     * @return bool True if the APCu extension is loaded and enabled.
     */
    public function apcu_extension_is_enabled() {
        return function_exists( 'apcu_enabled' ) && apcu_enabled();
    }

    /**
     *  Make sure WP_SQLITE_OBJECT_CACHE_APCU and $option['use_apcu'] match.
     *
     * @param bool $unconditional true if we should always change the option to match WP_SQLITE_OBJECT_CACHE_APCU.
     *
     * @return string 'on' or 'off': the current activation state of epcu.
     */
    public function sync_apcu_global_to_option( $unconditional = true ) {
        global $wp_object_cache;
        $option       = get_option( $this->_token . '_settings', array() );
        $option_dirty = false;
        $updated_flag = array_key_exists( 'use_apcu_updated', $option );
        $use_apcu     = array_key_exists( 'use_apcu', $option ) && 'on' === $option['use_apcu'] ? 'on' : 'off';
        if ( $updated_flag ) {
            unset ( $option['use_apcu_updated'] );
            $option_dirty = true;
        }
        $target_use_apcu = $this->apcu_is_activated() ? 'on' : 'off';
        if ( ! $unconditional && $updated_flag ) {
            $target_use_apcu = $use_apcu;
        }
        if ( $target_use_apcu !== $use_apcu ) {
            $option['use_apcu'] = $target_use_apcu;
            $option_dirty       = true;
        }
        if ( $option_dirty ) {
            if ( method_exists( $wp_object_cache, 'apcu-clear_cache' ) ) {
                $wp_object_cache->apcu_clear_cache();
            }
            update_option( $this->_token . '_settings', $option, true );
        }

        return $target_use_apcu;
    }

    /**
     * Add a flush button to the admin bar.
     *
     * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
     *
     * @return void
     */
    public function admin_bar_flush_button( $wp_admin_bar ) {
        if ( ! is_admin_bar_showing() ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Only show on main site for multisite installations.
        if ( is_multisite() && ! is_main_site() ) {
            return;
        }

        $flush_url  = add_query_arg(
            array( 'sqlite_object_cache_action' => 'flush' ),
            remove_query_arg( 'sqlite_object_cache_action' )
        );
        $nonced_url = wp_nonce_url( $flush_url, 'sqlite_object_cache_flush' );

        $wp_admin_bar->add_node(
            array(
                'id'    => 'sqlite-object-cache-flush',
                'title' => __( 'Flush Object Cache', 'sqlite-object-cache' ),
                'href'  => $nonced_url,
                'meta'  => array(
                    'title' => __( 'Delete all object cache entries now, including all transients.', 'sqlite-object-cache' ),
                ),
            )
        );
    }

    /**
     * Handle the admin bar flush action.
     *
     * @return void
     */
    public function handle_admin_bar_flush() {
        if ( ! isset( $_GET['sqlite_object_cache_action'] ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_GET['sqlite_object_cache_action'] ) );

        if ( 'flush' !== $action ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to flush the object cache.', 'sqlite-object-cache' ) );
        }

        // Only allow flush on main site for multisite installations (matches button visibility).
        if ( is_multisite() && ! is_main_site() ) {
            wp_die( esc_html__( 'Cache flush is only available on the main site.', 'sqlite-object-cache' ) );
        }

        check_admin_referer( 'sqlite_object_cache_flush' );

        wp_cache_flush();

        // Use a user-specific transient so only the initiating user sees the flush notice (applies to both single-site and multisite).
        $transient_key = 'sqlite_object_cache_flushed_' . get_current_user_id();
        set_transient( $transient_key, true, 30 );

        $referer = wp_get_referer();
        if ( $referer ) {
            $redirect_url = $referer;
        } else {
            $redirect_url = is_admin() ? admin_url() : home_url();
        }
        // Remove action and nonce query parameters from URL.
        $redirect_url = remove_query_arg( array( 'sqlite_object_cache_action', '_wpnonce' ), $redirect_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Check for flush transient and show notice if set.
     *
     * @return void
     */
    public function maybe_show_flush_notice() {
        $transient_key = 'sqlite_object_cache_flushed_' . get_current_user_id();
        if ( get_transient( $transient_key ) ) {
            delete_transient( $transient_key );
            $this->show_flush_notice();
        }
    }

    /**
     * Display a notice when the object cache was flushed via admin bar.
     *
     * @return void
     */
    public function show_flush_notice() {
        ?>
        <div class="notice notice-success sqlite-object-cache is-dismissible">
            <p><?php esc_html_e( 'SQLite Object Cache flushed successfully.', 'sqlite-object-cache' ); ?></p>
        </div>
        <?php
    }

}
