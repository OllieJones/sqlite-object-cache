<?php /** @noinspection ALL */
/** @noinspection PhpUnused */
/** @noinspection PhpUndefinedFunctionInspection */
/** @noinspection PhpUndefinedNamespaceInspection */
/** @noinspection PhpUndefinedClassInspection */

/**
 * SQLite Object Cache plugin
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : The display format. table, csv, json, yaml.
 *
 * [--blogid=<blogid>]
 * : The blog id for a multisite network. --url also selects the blog if you prefer.
 *
 */
class SQLite_Object_Cache_CLI extends WP_CLI_Command {

  public $db;
  public $assoc_args;
  public $cmd = 'wp index-mysql';
  public $allSwitch = false;
  public $rekeying;
  public $errorMessages = [];
  public $dryrun;
  private $commentPrefix;

  /**
   * Show the version of the plugin.
   *
   */
  function version( $args, $assoc_args ) {
    $this->setupCliEnvironment( $args, $assoc_args );

    global $wp_object_cache;
    $igbinary = function_exists( 'igbinary_serialize' )
      ? phpversion( 'igbinary' )
      : __( 'unavailable', 'sqlite-object-cache' );
    $force_serialize = defined( 'WP_SQLITE_OBJECT_CACHE_SERIALIZE' ) && WP_SQLITE_OBJECT_CACHE_SERIALIZE;
    $igbinary        .= $force_serialize ? esc_html__( '(disabled by WP_SQLITE_OBJECT_CACHE_SERIALIZE)', 'sqlite-object-cache' ) : '';

    if ( method_exists( $wp_object_cache, 'sqlite_get_version' ) ) {
      $msg = sprintf(
      /* translators: 1: version for sqlite  2: version for plugin  3: igbinary  --- for WP-CLI */
        __( 'Versions: Plugin: %2$s  SQLite: %1$s  igbinary: %3$s.', 'sqlite-object-cache' ),
        $wp_object_cache->sqlite_get_version(),
        '1.5.1',
        $igbinary );
    }

    WP_CLI::log( $this->commentPrefix . $msg );
  }

  /** @noinspection PhpUnusedParameterInspection */
  private function setupCliEnvironment( $args, $assoc_args ) {
    $this->assoc_args = $assoc_args;
    if ( is_multisite() ) {
      $restoreBlogId = get_current_blog_id();
      if ( ! empty( $assoc_args['blogid'] ) ) {
        $this->cmd .= ' --blogid=' . $assoc_args['blogid'];
        switch_to_blog( $assoc_args['blogid'] );
      } else {
        switch_to_blog( $restoreBlogId );
      }
    }
  }

  /**
   * Show status.
   *
   * @param $args
   * @param $assoc_args
   */
  function status( $args, $assoc_args ) {
    $this->setupCliEnvironment( $args, $assoc_args );
    global $wp_object_cache;
    if ( method_exists( $wp_object_cache, 'sqlite_sizes' ) ) {

      $sizes = $wp_object_cache->sqlite_sizes();
      $msgs  = array();

      /* translators: 1: size of .sqlite database file in MiB. --- for WP-CLI  */
      $msgs[] = sprintf( __( 'SQLite File Size Total: %sMiB', 'sqlite-object-cache' ), number_format_i18n( $sizes['page_size'] * $sizes['total_pages'] / ( 1024.0 * 1024.0 ), 3 ) );
      /* translators: 1: size of .sqlite database file free space in MiB. --- for WP-CLI  */
      $msgs[]   = sprintf( __( 'Free: %sMiB', 'sqlite-object-cache' ), number_format_i18n( $sizes['page_size'] * $sizes['free_pages'] / ( 1024.0 * 1024.0 ), 3 ) );
      $mmapsize = $sizes['mmap_size'];
      if ( $mmapsize > 0 ) {
        /* translators: 1: size of memory mapped segment  in MiB. --- for WP-CLI  */
        $msgs[] = sprintf( __( 'Memory Mapped Segment Size: %sMiB', 'sqlite-object-cache' ), number_format_i18n( $mmapsize / ( 1024.0 * 1024.0 ) ) );
      }

      $length   = 0;
      $count    = 0;
      $earliest = PHP_INT_MAX;
      $latest   = PHP_INT_MIN;
      try {
        foreach ( $wp_object_cache->sqlite_load_usages( true ) as $item ) {
          $length += $item->length;
          $count ++;
          $ts       = $item->expires;
          $earliest = min( $earliest, $ts );
          $latest   = max( $latest, $ts );
        }
      } catch ( Exception $ex ) {
        $length   = 0;
        $count    = 0;
        $earliest = PHP_INT_MAX;
        $latest   = PHP_INT_MIN;
      }
      if ( $length > 0 ) {
        /* translators: 1: size of cached items in MiB. --- for WP-CLI */
        $msgs[] = sprintf( __( 'Cached Data Size %sMiB', 'sqlite-object-cache' ), number_format_i18n( $length / ( 1024.0 * 1024.0 ), 3 ) );
      }
      if ( $count > 0 ) {
        /* translators: 1: number of cached items. --- for WP-CLI */
        $msgs[] = sprintf( __( 'Item Count: %s', 'sqlite-object-cache' ), number_format_i18n( $count ) );
      }

      if ( $earliest <= $latest ) {
        /* translators:  1 start time   2 end time both in localized format. --- for WP-CLI  */
        $msgs[] = sprintf( __( 'Expirations: %1$s to %2$s.', 'sqlite-object-cache' ),
          $this->format_datestamp( $earliest ), $this->format_datestamp( $latest ) );
      }

      WP_CLI::log( $this->commentPrefix . implode( '  ', $msgs ) );

    }
  }

  /**
   * Set or get the target size of the SQLite database file holding the object cache.
   * ## OPTIONS
   *
   *   [<size>]
   *   : The size to set in MiB. Omit to get the current size.
   */
  function size( $args, $assoc_args ) {

    $targetAction = 1;
    $this->setupCliEnvironment( $args, $assoc_args );
    if ( count( $args ) > 1 ) {
      WP_CLI::error( $this->commentPrefix . __( 'Too many args. "sqlite-object-cache size 64" sets it to 64MiB.', 'sqlite-object-cache' ) );
      return;
    }
    list( $options, $old_target_size ) = $this->get_one_option( 'target_size' );

    if ( 1 === count( $args ) ) {
      $new_target_size = strval( $args[0] );
      if ( $new_target_size === $old_target_size ) {
        /* translators: 1: new target cache size  --- for WP-CLI */
        $msg = __( 'Target cache size unchanged at %1$sMiB', 'sqlite_object_cache' );
        $msg = sprintf( $msg, $new_target_size );
        WP_CLI::log( $this->commentPrefix . $msg );
      } else {
        /* translators: 1: new target cache size  2:former target cache size */
        $msg                    = __( 'Target cache size changed from %2$sMiB to %1$sMiB', 'sqlite_object_cache' );
        $msg                    = sprintf( $msg, $new_target_size, $old_target_size );
        $options['target_size'] = strval( $new_target_size );
        update_option( 'sqlite_object_cache_settings', $options, true );
        WP_CLI::success( $this->commentPrefix . $msg );
      }
    } else {
      /* translators: 1:  target cache size */
      $msg = __( 'Target cache size is %1$sMiB', 'sqlite_object_cache' );
      $msg = sprintf( $msg, $old_target_size );
      WP_CLI::log( $this->commentPrefix . $msg );
    }

  }

  /**
   * Set or get the performance measurement sample rate percentage. 0 disables measurement.
   *  ## OPTIONS
   *
   *  [<samplerate>]
   *  : The sample rate to set. A number 0 - 100. Omit to get the current rate.
   */
  function samplerate( $args, $assoc_args ) {

    $this->setupCliEnvironment( $args, $assoc_args );
    if ( count( $args ) > 1 ) {
      WP_CLI::error( $this->commentPrefix . __( 'Too many args. "sqlite-object-cache samplerate 10" samples ten percent.', 'sqlite-object-cache' ) );
      return;
    }
    list( $options, $old_samplerate ) = $this->get_one_option( 'samplerate' );
    list( $options, $old_capture ) = $this->get_one_option( 'capture' );
    $old_samplerate = strval( ( $old_samplerate < 0 ) ? 0 : $old_samplerate );
    $old_samplerate = strval( ( $old_samplerate > 100 ) ? 100 : $old_samplerate );
    $old_capture    = ( 'on' !== $old_capture ) ? 'off' : 'on';
    $old_samplerate = strval( ( 'on' !== $old_capture ) ? 0 : $old_samplerate );
    $old_capture    = ( '0' === $old_samplerate ) ? 'off' : 'on';

    if ( 1 === count( $args ) ) {
      $new_samplerate = strval( $args[0] );
      $new_samplerate = strval( ( $new_samplerate < 0 ) ? 0 : $new_samplerate );
      $new_samplerate = strval( ( $new_samplerate > 100 ) ? 100 : $new_samplerate );
      $new_capture    = ( '0' === $new_samplerate ) ? 'off' : 'on';
      if ( $old_samplerate === $new_samplerate ) {
        /* translators: 1: new sample rate 0-100   --- for WP-CLI */
        $msg = __( 'Performance measurement sample rate unchanged at %1$s%%', 'sqlite_object_cache' );
        $msg = sprintf( $msg, $new_samplerate );
        WP_CLI::log( $this->commentPrefix . $msg );
      } else {
        /* translators: 1: new rate   2:former rate --- for WP-CLI */
        $msg                   = __( 'Performance measurement sample rate changed from %2$s%% to %1$s%%', 'sqlite_object_cache' );
        $msg                   = sprintf( $msg, $new_samplerate, $old_samplerate );
        $options['samplerate'] = strval( $new_samplerate );
        $options['capture']    = $new_capture;

        update_option( 'sqlite_object_cache_settings', $options, true );
        WP_CLI::success( $this->commentPrefix . $msg );

      }
    } else {
      /* translators: 1:  sample rate --- for WP-CLI */
      $msg = __( 'Performance measurement sample rate is %1$s%%', 'sqlite_object_cache' );
      $msg = sprintf( $msg, $old_samplerate );
      WP_CLI::log( $this->commentPrefix . $msg );
    }

  }

  /**
   * Set or get how long to retain performance measurements.
   *  ## OPTIONS
   *
   *  [<time>]
   *  : The time in hours to retain measurements. Omit to get the current retention time.
   */
  function retain( $args, $assoc_args ) {

    $this->setupCliEnvironment( $args, $assoc_args );
    if ( count( $args ) > 1 ) {
      WP_CLI::error( $this->commentPrefix . __( 'Too many args. "sqlite-object-cache retain 4" retains measuremewnts for 4 hours.', 'sqlite-object-cache' ) );
      return;
    }
    list( $options, $old_samplerate ) = $this->get_one_option( 'retainmeasurements' );
    $old_samplerate = strval( ( $old_samplerate < 1 ) ? 1 : $old_samplerate );

    if ( 1 === count( $args ) ) {
      $new_samplerate = strval( $args[0] );
      $new_samplerate = strval( ( $new_samplerate < 1 ) ? 1 : $new_samplerate );
      if ( $old_samplerate === $new_samplerate ) {
        /* translators: 1: retention time  --- for WP-CLI  */
        $msg = __( 'Performance measurement retention unchanged at %1$shr', 'sqlite_object_cache' );
        $msg = sprintf( $msg, $new_samplerate );
        WP_CLI::log( $this->commentPrefix . $msg );
      } else {
        /* translators: 1: new retention time   2:former time  --- for WP-CLI */
        $msg                           = __( 'Performance measurement retention changed from %2$shr to %1$shr', 'sqlite_object_cache' );
        $msg                           = sprintf( $msg, $new_samplerate, $old_samplerate );
        $options['retainmeasurements'] = strval( $new_samplerate );

        update_option( 'sqlite_object_cache_settings', $options, true );
        WP_CLI::success( $this->commentPrefix . $msg );

      }
    } else {
      /* translators: 1: retention time  --- for WP-CLI  */
      $msg = __( 'Performance measurement retention is %1$shr', 'sqlite_object_cache' );
      $msg = sprintf( $msg, $old_samplerate );
      WP_CLI::log( $this->commentPrefix . $msg );
    }

  }

  /**
   * Flush the cache (delete all its entries). This briefly puts your site into maintenance mode.
   */
  function flush( $args, $assoc_args ) {
    global $wp_object_cache;
    if ( method_exists( $wp_object_cache, 'flush' ) ) {
      try {
        $this->enter_maintenance_mode();
        $wp_object_cache->flush( true );
      } finally {
        $this->exit_maintenance_mode();
      }
    }
  }

  /**
   * Vacuum (defragment) the cache. This briefly puts your site into maintenance mode.
   */
  function vacuum( $args, $assoc_args ) {
    global $wp_object_cache;
    if ( method_exists( $wp_object_cache, 'vacuum' ) ) {
      try {
        $this->enter_maintenance_mode();
        $wp_object_cache->vacuum();
      } finally {
        $this->exit_maintenance_mode();
      }
    }
  }

  /**
   * Clean up (delete expired entries from) the cache.
   */
  function cleanup( $args, $assoc_args ) {
    list( $options, $target_size ) = $this->get_one_option( 'target_size' );

    $target_size    *= ( 1024 * 1024 );
    $threshold_size = (int) ( $target_size );

    global $wp_object_cache;
    if ( ! method_exists( $wp_object_cache, 'sqlite_get_size' ) ) {
      return;
    }

    /* Always clean up old statistics. */
    list( $options, $retention ) = $this->get_one_option( 'retainmeasurements' );
    $wp_object_cache->sqlite_reset_statistics( $retention * HOUR_IN_SECONDS );

    $original_size = $wp_object_cache->sqlite_get_size();
    $current_size  = $original_size;
    /* Skip this if the current size is small enough. */
    if ( $current_size <= $threshold_size ) {
      /* translators: 1: size of cache --- For WP-CLI */
      $msg = __( 'Cache contains %1$01.1fMiB, no cleanup performed', 'sqlite_object_cache' );
      $msg = sprintf( $msg, $original_size / ( 1024.0 * 1024.0 ) );
      WP_CLI::log( $this->commentPrefix . $msg );

      return;
    }

    /* Remove expired items (transients mostly).   */
    if ( $wp_object_cache->sqlite_remove_expired() ) {
      /* If anything was removed, get the size again.   */
      $current_size = $wp_object_cache->sqlite_get_size();

      if ( $current_size < $original_size ) {
        /* translators: 1: size removed  2: size remaining */
        $msg = __( '%1$01.1fMiB of expired items removed from cache, leaving %2$01.1fMiB', 'sqlite_object_cache' );
        $msg = sprintf( $msg, ( $original_size - $current_size ) / ( 1024.0 * 1024.0 ), ( $current_size ) / ( 1024.0 * 1024.0 ) );
        WP_CLI::success( $this->commentPrefix . $msg );
      }
    }

    /* Skip this if the size is small enough, after removing expired items. */
    if ( $current_size <= $threshold_size ) {
      return;
    }

    /* Delete the least-recently-updated items to get to the target size. */
    $original_size = $current_size;
    $wp_object_cache->sqlite_delete_old( $target_size, $current_size );
    $current_size = $wp_object_cache->sqlite_get_size();
    if ( $current_size < $original_size ) {
      /* translators: 1: size removed  2: size remaining  --- For WP-CLI */
      $msg = __( '%1$01.1fMiB of least recently updated items removed from cache, leaving %2$01.1fMiB', 'sqlite_object_cache' );
      $msg = sprintf( $msg, ( $original_size - $current_size ) / ( 1024.0 * 1024.0 ), ( $current_size ) / ( 1024.0 * 1024.0 ) );
      WP_CLI::success( $this->commentPrefix . $msg );
    }
  }

  private function get_one_option(
    $name
  ): array {
#a:5:{s:11:"target_size";s:2:"16";s:7:"capture";s:2:"on";s:10:"samplerate";s:3:"100";s:18:"retainmeasurements";s:1:"2";s:15:"previouscapture";i:0;}
    $default = array(
      'target_size'        => '16',
      'capture'            => 'off',
      'samplerate'         => '1',
      'retainmeasurements' => '2'
    );
    $options = get_option( 'sqlite_object_cache_settings', $default );
    $val     = strval( $options[ $name ] );
    return array( $options, $val );
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

  /**
   * Format a UNIX timestamp using WP settings.
   *
   * @param $stamp
   *
   * @return false|string
   */
  private function format_datestamp(
    $stamp
  ) {
    $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

    return wp_date( $date_format, (int) $stamp );
  }

}

WP_CLI::add_command( 'sqlite-object-cache', 'SQLite_Object_Cache_CLI' );
