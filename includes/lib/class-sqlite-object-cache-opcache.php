<?php
/**
 * Health checks for opcache exhaustion.
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Main plugin class.
 *
 * Only make one instance of this, please.
 */
class SQLite_Object_Cache_Opcache {

  private $cache_min = 256;
  private $strings_min = 24;

  private $enabled = false;

  public function __construct() {

    if ( function_exists( 'opcache_get_status' ) ) {
      $status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warning emitted in failure case.
      if ( $status && true === $status['opcache_enabled'] ) {
        $this->enabled = true;
      }
    }

    if ( $this->enabled ) {
      add_filter( 'site_status_tests', [ $this, 'site_status_tests' ] );
    }

  }

  /**
   * Filters which site status tests are run on a site.
   *
   * The site health is determined by a set of tests based on best practices from
   * both the WordPress Hosting Team and web standards in general.
   *
   * Some sites may not have the same requirements, for example the automatic update
   * checks may be handled by a host, and are therefore disabled in core.
   * Or maybe you want to introduce a new test, is caching enabled/disabled/stale for example.
   *
   * Tests may be added either as direct, or asynchronous ones. Any test that may require some time
   * to complete should run asynchronously, to avoid extended loading periods within wp-admin.
   *
   * @param array[] $tests {
   *     An associative array of direct and asynchronous tests.
   *
   * @type array[] $direct {
   *         An array of direct tests.
   *
   * @type array ...$identifier {
   *             `$identifier` should be a unique identifier for the test. Plugins and themes are encouraged to
   *             prefix test identifiers with their slug to avoid collisions between tests.
   *
   * @type string $label The friendly label to identify the test.
   * @type callable $test The callback function that runs the test and returns its result.
   * @type bool $skip_cron Whether to skip this test when running as cron.
   *         }
   *     }
   * @type array[] $async {
   *         An array of asynchronous tests.
   *
   * @type array ...$identifier {
   *             `$identifier` should be a unique identifier for the test. Plugins and themes are encouraged to
   *             prefix test identifiers with their slug to avoid collisions between tests.
   *
   * @type string $label The friendly label to identify the test.
   * @type string $test An admin-ajax.php action to be called to perform the test, or
   *                                               if `$has_rest` is true, a URL to a REST API endpoint to perform
   *                                               the test.
   * @type bool $has_rest Whether the `$test` property points to a REST API endpoint.
   * @type bool $skip_cron Whether to skip this test when running as cron.
   * @type callable $async_direct_test A manner of directly calling the test marked as asynchronous,
   *                                               as the scheduled event can not authenticate, and endpoints
   *                                               may require authentication.
   *         }
   *     }
   * }
   * @since 5.6.0 Added the `async_direct_test` array key for asynchronous tests.
   *              Added the `skip_cron` array key for all tests.
   *
   * @since 5.2.0
   */
  public function site_status_tests( $tests ) {

    $tests['direct']['opcode_cache_full'] = array(
      'label' => __( 'Your PHP opcode cache has sufficient RAM', 'sqlite-object-cache' ),
      'test'  => [ $this, 'test_opcache_full' ],
    );
    return $tests;
  }

  /**
   * Get the cleaned-up opcache status.
   *
   * This returns an array:
   * list( $mem_total, $frac_mem_used, $pct_mem_used, $mem_full, $mem_needed,
   *       $strings_total, $frac_strings_used,$pct_strings_used, $strings_full, $strings_needed ) = $this->get_status()
   *
   * @return array|false
   */
  public function get_status() {
    if ( function_exists( 'opcache_get_status' ) ) {
      $status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warning emitted in failure case.
    } else {
      return false;
    }

    try {
      $mem_total     = (float) $status['memory_usage']['used_memory'] + (float) $status['memory_usage']['free_memory'] + (float) $status['memory_usage']['wasted_memory'];
      $frac_mem_used = 1.0 - ( (float) $status['memory_usage']['free_memory'] / $mem_total );
      $mem_total     = (int) $mem_total / ( MB_IN_BYTES );
      $mem_needed    = floor( (int) ( $mem_total * 1.5 ) );
      $mem_needed    = $mem_needed < $this->cache_min ? $this->cache_min : $mem_needed;
      $pct_mem_used  = number_format( 100 * $frac_mem_used, 0 );
      $mem_full      = $pct_mem_used >= 95;;

      $strings_total     = (float) $status['interned_strings_usage']['buffer_size'];
      $frac_strings_used = (float) $status['interned_strings_usage']['used_memory'] / $strings_total;
      $strings_total     = (int) $strings_total / ( MB_IN_BYTES );
      $strings_needed    = floor( (int) ( $strings_total * 1.5 ) );
      $strings_needed    = $strings_needed < $this->strings_min ? $this->strings_min : $strings_needed;
      $pct_strings_used  = number_format( 100 * $frac_strings_used, 0 );
      $strings_full      = $pct_strings_used >= 95;

    } catch ( Exception $ex ) {
      return false;
    }

    return array(
      $mem_total,
      $frac_mem_used,
      $pct_mem_used,
      $mem_full,
      $mem_needed,
      $strings_total,
      $frac_strings_used,
      $pct_strings_used,
      $strings_full,
      $strings_needed,
    );

  }

  /**
   * Check whether the Opcode Cache has enough RAM.
   * @return array
   * @throws Exception
   *
   */
  public function test_opcache_full() {
    if ( ! function_exists( 'opcache_get_status' ) ) {
      return array(
        //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
        'label'       => __( 'Opcode cache is not enabled' ),
        'status'      => 'recommended',
        'badge'       => array(
          //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
          'label' => __( 'Performance' ),
          'color' => 'blue',
        ),
        //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
        'description' => '<p>' . __( 'Enabling this cache can significantly improve the performance of your site.' ) . '</p>',
        'test'        => 'opcode_cache_full',

      );
    }

    $result = $this->get_status();
    if ( false === $result ) {
      return array(
        'label'       => __( 'Your PHP opcode cache state seems to be corrupt', 'sqlite-object-cache' ),
        'status'      => 'recommended',
        'badge'       => array(
          //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
          'label' => __( 'Performance' ),
          'color' => 'blue',
        ),
        'description' => '<p>' . __( 'Something is wrong with your PHP opcode cache state', 'sqlite-object-cache' ) . '</p>',
        'test'        => 'opcode_cache_full',
      );
    }
    list( $mem_total,
      $frac_mem_used,
      $pct_mem_used,
      $mem_full,
      $mem_needed,
      $strings_total,
      $frac_strings_used,
      $pct_strings_used,
      $strings_full,
      $strings_needed ) = $result;

    $mem_url     = 'https://php.net/manual/en/opcache.configuration.php#ini.opcache.memory-consumption';
    $strings_url = 'https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.interned-strings-buffer';


    $description   = array();
    $action        = array();
    $message       = ( $frac_mem_used < 0.995 )
      ? sprintf(
      /* Translators: 1: number of megabytes. 2: percent full */
        __( '%1$d megabytes are allocated to PHP\'s opcode cache. It is %2$d%% full.', 'sqlite-object-cache' ),
        $mem_total, $pct_mem_used )
      : sprintf(
      /* Translators: 1: number of megabytes */
        __( '%1$d megabytes are allocated to PHP\'s opcode cache. It is full.', 'sqlite-object-cache' ),
        $mem_total );
    $description[] = $message . ' ' .
                     sprintf( '<a href="%1$s" target="_blank">%2$s<span class="screen-reader-text">%3$s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
                       esc_url( $mem_url ),
                       __( 'Learn how to configure it.', 'sqlite-object-cache' ),
                       //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
                       __( '(opens in a new tab)' )
                     );
    if ( $mem_full ) {

      $action[] = sprintf(
      /* translators: 1: a number of megabytes. Notice that this should not have any suffix, like MB, appended to it. */
        __( 'Ask your hosting provider to increase the opcache.memory_consumption directive in php.ini, setting it to at least %1$d.', 'sqlite-object-cache' ),
        $mem_needed );
    }
    $message = ( $frac_strings_used < 0.995 )
      ? sprintf(
      /* Translators: 1: number of megabytes. 2: percent full */
        __( '%1$d megabytes are allocated to the opcode cache\'s strings buffer. It is %2$d%% full.', 'sqlite-object-cache' ),
        $strings_total, $pct_strings_used )
      : sprintf(
      /* Translators: 1: number of megabytes */
        __( '%1$d megabytes are allocated to the opcode cache\'s strings buffer. It is full.', 'sqlite-object-cache' ),
        $strings_total );

    $description[] = $message . ' ' .
                     sprintf( '<a href="%1$s" target="_blank">%2$s<span class="screen-reader-text">%3$s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
                       esc_url( $strings_url ),
                       __( 'Learn how to configure it.', 'sqlite-object-cache' ),
                       //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
                       __( '(opens in a new tab)' )
                     );
    if ( $strings_full ) {

      $action[] = sprintf(
      /* translators: 1: a number of megabytes. Notice that this should not have any suffix, like MB, appended to it. */
        __( 'Ask your hosting provider to increase the opcache.interned_strings_buffer directive in php.ini, setting it to at least %1$d.', 'sqlite-object-cache' ),
        $strings_needed );
    }

    $description_string = '<p>' . implode( '</p><p>', $description ) . '</p>';
    $action_string      = '<p>' . implode( '</p><p>', $action ) . '</p>';

    if ( ! $mem_full && ! $strings_full ) {

      return array(
        'label'       => __( "Opcode cache has enough RAM", 'sqlite-object-cache' ),
        'status'      => 'good',
        'badge'       => array(
          //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
          'label' => __( 'Performance' ),
          'color' => 'blue',
        ),
        'description' => $description_string,
        'test'        => 'opcode_cache_full',
      );
    }

    if ( $mem_full && $strings_full ) {

      return array(
        'label'       => __( "Your PHP opcode cache and its interned strings cache need more RAM allocated to them.", 'sqlite-object-cache' ),
        'status'      => 'recommended',
        'badge'       => array(
          //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
          'label' => __( 'Performance' ),
          'color' => 'blue',
        ),
        'description' => $description_string,
        'actions'     => $action_string,
        'test'        => 'opcode_cache_full',
      );
    }

    if ( $strings_full ) {

      return array(
        'label'       => __( "Your PHP opcode cache's interned strings cache needs more RAM allocated to it.", 'sqlite-object-cache' ),
        'status'      => 'recommended',
        'badge'       => array(
          //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
          'label' => __( 'Performance' ),
          'color' => 'blue',
        ),
        'description' => $description_string,
        'actions'     => $action_string,
        'test'        => 'opcode_cache_full',
      );
    }


    return array(
      'label'       => __( "Your PHP opcode cache needs more RAM allocated to it.", 'sqlite-object-cache' ),
      'status'      => 'recommended',
      'badge'       => array(
        //phpcs:ignore WordPress.WP.I18n.MissingArgDomain
        'label' => __( 'Performance' ),
        'color' => 'blue',
      ),
      'description' => $description_string,
      'actions'     => $action_string,
      'test'        => 'opcode_cache_full',
    );
  }

}
