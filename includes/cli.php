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
    WP_CLI::log( $this->commentPrefix . __( 'SQLite Object Cache', 'sqlite-object-cache' ) . ' ' . '1.3.8' );
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
    WP_CLI::log( $this->commentPrefix . 'TODO' );

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
        /* translators: 1: new target cache size */
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
        /* translators: 1: new sample rate 0-100   */
        $msg = __( 'Performance measurement sample rate unchanged at %1$s%%', 'sqlite_object_cache' );
        $msg = sprintf( $msg, $new_samplerate );
        WP_CLI::log( $this->commentPrefix . $msg );
      } else {
        /* translators: 1: new rate   2:former rate */
        $msg                   = __( 'Performance measurement sample rate changed from %2$s%% to %1$s%%', 'sqlite_object_cache' );
        $msg                   = sprintf( $msg, $new_samplerate, $old_samplerate );
        $options['samplerate'] = strval( $new_samplerate );
        $options['capture']    = $new_capture;

        update_option( 'sqlite_object_cache_settings', $options, true );
        WP_CLI::success( $this->commentPrefix . $msg );

      }
    } else {
      /* translators: 1:  sample rate */
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
    $old_retain = strval( ( $old_retain < 1 ) ? 1 : $old_retain );

    if ( 1 === count( $args ) ) {
      $new_retain = strval( $args[0] );
      $new_retain = strval( ( $new_retain < 1 ) ? 1 : $new_retain );
      if ( $old_retain === $new_retain ) {
        /* translators: 1: new sample rate 0-100   */
        $msg = __( 'Performance measurement retention unchanged at %1$shr', 'sqlite_object_cache' );
        $msg = sprintf( $msg, $new_retain );
        WP_CLI::log( $this->commentPrefix . $msg );
      } else {
        /* translators: 1: new rate   2:former rate */
        $msg                   = __( 'Performance measurement retention changed from %2$shr to %1$shr', 'sqlite_object_cache' );
        $msg                   = sprintf( $msg, $new_retain, $old_retain );
        $options['retainmeasurements'] = strval( $new_retain );

        update_option( 'sqlite_object_cache_settings', $options, true );
        WP_CLI::success( $this->commentPrefix . $msg );

      }
    } else {
      /* translators: 1:  sample rate */
      $msg = __( 'Performance measurement retention is %1$shr', 'sqlite_object_cache' );
      $msg = sprintf( $msg, $old_retain );
      WP_CLI::log( $this->commentPrefix . $msg );
    }

  }


  private function get_one_option( $name ): array {
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

}

WP_CLI::add_command( 'sqlite-object-cache', 'SQLite_Object_Cache_CLI' );
