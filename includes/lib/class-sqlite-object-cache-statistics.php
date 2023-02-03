<?php
/**
 * Statistics class file.
 *
 * @package SQLite Object Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * statistics class.
 */
class SQLite_Object_Cache_Statistics {

	/**
	 * Associative array with selected cache items names most frequent first.
	 *
	 * @var array
	 */
	public $selected_names;

	/**
	 * Associative array with descriptive statistics.
	 *
	 * @var array
	 */
	public $descriptions;
	/**
	 * @var string
	 */
	private $start_time;
	/**
	 * @var string
	 */
	private $end_time;
	/**
	 * @var array
	 */
	private $options;

	public function __construct( $options ) {
		$this->options = $options;
	}

	/**
	 * Initialize and load data.
	 *
	 * @return void
	 * @throws Exception Announce database failure.
	 */
	public function init() {
		global $wp_object_cache;

		$first = PHP_INT_MAX;
		$last  = PHP_INT_MIN;

		$selected_names        = [];
		$opens                 = [];
		$selects               = [];
		$inserts               = [];
		$deletes               = [];
		$RAMratios             = [];
		$RAMhits               = 0;
		$RAMmisses             = 0;
		$DISKratios            = [];
		$DISKLookupsPerRequest = [];
		$SavesPerRequest       = [];
		$DBMSqueriesPerRequest = [];
		$DISKhits              = 0;
		$DISKmisses            = 0;

		if ( ! method_exists( $wp_object_cache, 'sqlite_load_statistics' ) ) {
			return;
		}

		if ( method_exists( $wp_object_cache, 'sqlite_clean_up_cache' ) ) {
			$wp_object_cache->sqlite_clean_up_cache();
		}

		foreach ( $wp_object_cache->sqlite_load_statistics() as $data ) {
			$first                   = min( $data->time, $first );
			$last                    = max( $data->time, $last );
			$RAMhits                 += $data->RAMhits;
			$RAMmisses               += $data->RAMmisses;
			$DISKhits                += $data->DISKhits;
			$DISKmisses              += $data->DISKmisses;
			$DISKLookupsPerRequest[] = $data->DISKhits + $data->DISKmisses;
			$RAMratio                = $data->RAMhits / ( $data->RAMhits + $data->RAMmisses );
			$RAMratios[]             = $RAMratio;
			$DISKratio               = $data->DISKhits / ( $data->DISKhits + $data->DISKmisses );
			$DISKratios[]            = $DISKratio;
			$opens []                = $data->open;
			$selects []              = $data->selects;
			$inserts []              = $data->inserts;
			$SavesPerRequest []      = count( $data->inserts );
			if ( property_exists( $data, 'DBMSqueries' ) && is_numeric( $data->DBMSqueries ) ) {
				$DBMSqueriesPerRequest [] = $data->DBMSqueries;
			}
			$deletes[] = $data->deletes;

			if ( property_exists( $data, 'select_names' ) && is_array( $data->select_names ) ) {
				foreach ( $data->select_names as $name ) {
					if ( ! array_key_exists( $name, $selected_names ) ) {
						$selected_names[ $name ] = 0;
					}
					$selected_names[ $name ] ++;
				}
			}
		}
		$duration = $last - $first;
		if ( $duration > 0 ) {
			arsort( $selected_names );
			$descriptions = [
				__( 'RAM hit ratio', 'sqlite-object-cache' )         => $this->descriptive_stats( $RAMratios ),
				__( 'Disk hit ratio', 'sqlite-object-cache' )        => $this->descriptive_stats( $DISKratios ),
				__( 'Disk lookups/request', 'sqlite-object-cache' )  => $this->descriptive_stats( $DISKLookupsPerRequest ),
				__( 'Disk saves/request', 'sqlite-object-cache' )    => $this->descriptive_stats( $SavesPerRequest ),
				__( 'MySQL queries/request', 'sqlite-object-cache' ) => $this->descriptive_stats( $DBMSqueriesPerRequest ),
				__( 'Initialization times', 'sqlite-object-cache' )  => $this->descriptive_stats( $opens ),
				__( 'Lookup times', 'sqlite-object-cache' )          => $this->descriptive_stats( array_merge( ...$selects ) ),
				__( 'Save times', 'sqlite-object-cache' )            => $this->descriptive_stats( array_merge( ...$inserts ) ),
				__( 'Delete times', 'sqlite-object-cache' )          => $this->descriptive_stats( array_merge( ...$deletes ) ),
			];

			$this->descriptions   = $descriptions;
			$this->selected_names = $selected_names;
			$this->start_time     = $this->format_datestamp( $first );
			$this->end_time       = $this->format_datestamp( $last );
		}
	}

	/**
	 * Descriptive statistics for an array of numbers.
	 *
	 * @param array $a The array.
	 *
	 * @return array
	 */
	public function descriptive_stats( array $a ) {
		$min = $this->minimum( $a );
		$max = $this->maximum( $a );

		return [
			'n'      => count( $a ),
			'[min'   => $min,
			'p1'     => $this->percentile( $a, 0.01 ),
			'p5'     => $this->percentile( $a, 0.05 ),
			'median' => $this->percentile( $a, 0.5 ),
			'mean'   => $this->mean( $a ),
			'p95'    => $this->percentile( $a, 0.95 ),
			'p99'    => $this->percentile( $a, 0.99 ),
			'max]'   => $max,
			'range'  => $max - $min,
			'mad'    => $this->mad( $a ),
			'stdev'  => $this->stdev( $a ),
		];
	}

	/**
	 * The smallest value in an array.
	 *
	 * @param array $a The array.
	 *
	 * @return mixed|null
	 */
	public function minimum( array $a ) {
		sort( $a );

		return count( $a ) > 0 ? $a[0] : null;
	}

	/**
	 * The largest value in an array.
	 *
	 * @param array $a The array.
	 *
	 * @return mixed|null
	 */
	public function maximum( array $a ) {
		sort( $a );

		return count( $a ) > 0 ? $a[ count( $a ) - 1 ] : null;
	}

	/** Percentile.
	 *
	 * @param array  $a dataset.
	 * @param number $p percentile as fraction 0-1.
	 *
	 * @return float
	 */
	public function percentile( array $a, $p ) {
		$n = count( $a );
		if ( ! $n ) {
			return null;
		}
		sort( $a );
		$i = (int) floor( $n * $p );
		if ( $i >= $n ) {
			$i = $n - 1;
		}

		return $a[ $i ];
	}

	/**
	 * Arithmetic mean.
	 *
	 * @param array $a dataset.
	 *
	 * @return number
	 */
	public function mean( array $a ) {
		$n = count( $a );
		if ( ! $n ) {
			return null;
		}
		if ( 1 === $n ) {
			return $a[0];
		}
		$acc = 0;
		foreach ( $a as $v ) {
			$acc += $v;
		}

		return $acc / $n;
	}

	/**
	 * Mean absolute deviation.
	 *
	 * @param array $a dataset.
	 *
	 * @return float|int|null
	 */
	public function mad( array $a ) {
		$n = count( $a );
		if ( ! $n ) {
			return null;
		}
		if ( 1 === $n ) {
			return 0.0;
		}
		$acc = 0;
		foreach ( $a as $v ) {
			$acc += $v;
		}
		$mean = $acc / $n;
		$acc  = 0;
		foreach ( $a as $v ) {
			$acc += abs( $v - $mean );
		}

		return $acc / $n;
	}

	/**
	 * Standard deviation.
	 *
	 * @param array $a dataset.
	 *
	 * @return float|null
	 */
	public function stdev( $a ) {
		$n = count( $a );
		if ( ! $n ) {
			return null;
		}
		if ( 1 === $n ) {
			return 0.0;
		}
		$sum   = 0.0;
		$sumsq = 0.0;
		foreach ( $a as $v ) {
			$sum   += $v;
			$sumsq += ( $v * $v );
		}
		$mean = $sum / $n;

		return sqrt( ( $sumsq / $n ) - ( $mean * $mean ) );
	}

	/**
	 * Format a UNIX timestamp using WP settings.
	 *
	 * @param $stamp
	 *
	 * @return false|string
	 */
	private function format_datestamp( $stamp ) {
		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return wp_date( $date_format, (int) $stamp );
	}

	/**
	 * Render the statistics display.
	 *
	 * @return void
	 */
	public function render() {

		echo '<h3>' . esc_html__( 'Cache performance', 'sqlite-object-cache' ) . '</h3>';
		if ( is_array( $this->descriptions ) ) {
			echo '<p>' . esc_html( sprintf(
			                       /* translators:  1 start time   2 end time both in localized format */
				                       __( 'From %1$s to %2$s.', 'sqlite-object-cache' ),
				                       $this->start_time, $this->end_time ) . ' ' . __( 'Times in microseconds.', 'sqlite-object-cache' ) ) . '</p>' . PHP_EOL;
			echo '<table class="sql-object-cache-stats">' . PHP_EOL;
			$first = true;
			foreach ( $this->descriptions as $stat => $description ) {
				if ( $first ) {
					echo '<thead><tr>';
					echo '<th scope="col"></th>';
					foreach ( $description as $item => $value ) {
						echo '<th scope="col" class="right">' . esc_html( $item ) . '</th>' . PHP_EOL;
					}
					echo '</tr></thead><tbody>' . PHP_EOL;
					$first = false;
				}
				echo '<tr>';
				echo '<th scope="row">' . esc_html( $stat ) . '</th>';
				foreach ( $description as $value ) {
					echo '<td>' . esc_html( round( $value, 2 ) ) . '</td>';
				}
				echo '</tr>' . PHP_EOL;
			}
			echo '</tr></tbody></table>' . PHP_EOL;
		} else {
			if ( is_array( $this->options ) && array_key_exists( 'capture', $this->options ) && 'on' === $this->options['capture'] ) {
				echo '<p>' . esc_html__( 'No cache performance statistics have been captured.', 'sqlite-object-cache' ) . ' ';
				$message    =
					/* translators: 1: a percentage */
					__( 'The plugin is capturing a random sample of %s%% of requests. It is possible no samples have yet been captured.', 'sqlite-object-cache' );
				$samplerate = is_numeric( $this->options['samplerate'] ) ? $this->options['samplerate'] : 1;
				echo esc_html( sprintf( $message, $samplerate ) ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Cache performance measurement is not enabled.', 'sqlite-object-cache' ) . ' ';
				echo esc_html__( 'You may enable it on the Settings tab.', 'sqlite-object-cache' ) . '</p>';
			}
		}

		if ( is_array( $this->selected_names ) && count( $this->selected_names ) > 0 ) {

			$names = $this->selected_names;
			uksort( $names, function ( $a, $b ) {
				/* Descending order of frequency */
				if ( $this->selected_names[ $a ] !== $this->selected_names[ $b ] ) {
					return $this->selected_names[ $a ] > $this->selected_names[ $b ] ? - 1 : 1;
				}
				$as = explode( '|', $a, 2 );
				$bs = explode( '|', $b, 2 );
				/* Ascending order by group name */
				if ( $as[0] !== $bs[0] ) {
					return strnatcmp( $as[0], $bs[0] );
				}
				/* Ascending order by key name, handling numeric keys correctly. */
				if ( is_numeric( $as[1] ) && is_numeric( $bs[1] ) ) {
					if ( (float) $as[1] === (float) $bs[1] ) {
						return 0;
					}

					return ( (float) $as[1] ) < ( (float) $bs[1] ) ? - 1 : 1;
				}

				return strnatcmp( $as[1], $bs[1] );
			} );

			echo '<h3>' . esc_html__( 'Most frequently looked up cache items', 'sqlite-object-cache' ) . '</h3>' . PHP_EOL;

			echo '<table class="sql-object-cache-items">' . PHP_EOL;
			$count_threshold = - 1;
			$first           = true;
			foreach ( $names as $name => $count ) {
				if ( $first ) {
					echo '<thead><tr>';
					echo '<th scope="col">' . esc_html__( "Cache Group", 'sqlite-object-cache' ) . '</th>';
					echo '<th scope="col">' . esc_html__( "Cache Key", 'sqlite-object-cache' ) . '</th>';
					echo '<th scope="col">' . esc_html__( "Count", 'sqlite-object-cache' ) . '</th>';
					echo '</tr></thead><tbody>' . PHP_EOL;
					$count_threshold = (int) ( $count * 0.7 );
					$first           = false;
				}
				if ( $count < $count_threshold ) {
					break;
				}
				$splits = explode( '|', $name, 2 );
				echo '<tr>';
				echo '<td>' . esc_html( $splits[0] ) . '</td>';
				echo '<td>' . esc_html( $splits[1] ) . '</td>';
				echo '<td>' . esc_html( $count ) . '</td>';
				echo '</tr>' . PHP_EOL;
			}
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the usage display.
	 *
	 * @return void
	 */
	public function render_usage() {

		global $wp_object_cache;
		if ( ! method_exists( $wp_object_cache, 'sqlite_load_usages' ) ) {
			return;
		}
		$filesize = null;
		try {
			if ( method_exists( $wp_object_cache, 'sqlite_files' ) ) {
				foreach ( $wp_object_cache->sqlite_files() as $file ) {
					ob_start();
					$filesize = filesize( $file );
					ob_end_clean();
					break;
				}
			}
		} catch (Exception $ex) {
			$filesize = null;
		}


		echo '<h3>' . esc_html__( 'Cache usage', 'sqlite-object-cache' ) . '</h3>';
		$grouplength = [];
		$groupcount  = [];
		$length      = 0;
		$count       = 0;
		$earliest    = PHP_INT_MAX;
		$latest      = PHP_INT_MIN;

		try {

			foreach ( $wp_object_cache->sqlite_load_usages( true ) as $item ) {
				$length += $item->length;
				$count ++;
				$ts       = $item->expires;
				$earliest = min( $earliest, $ts );
				$latest   = max( $latest, $ts );
				$splits   = explode( '|', $item->name, 2 );
				$group    = $splits[0];
				if ( ! array_key_exists( $group, $grouplength ) ) {
					$grouplength[ $group ] = 0;
					$groupcount[ $group ]  = 0;
				}
				$grouplength[ $group ] += $item->length;
				$groupcount[ $group ] ++;
			}
		} catch ( Exception $ex ) {
			echo '<p>' . esc_html__( 'Cannot load some or all cache items.', 'sqlite-object-cache' ) . '</p>';
		}

		if ( $count > 0 ) {
			$groups = array_keys( $grouplength );
			sort( $groups );

			echo '<p>' . esc_html( sprintf(
			                       /* translators:  1 start time   2 end time both in localized format */
				                       __( 'Expirations from %1$s to %2$s.', 'sqlite-object-cache' ),
				                       $this->format_datestamp( $earliest ), $this->format_datestamp( $latest ) ) . ' ' . __( 'Sizes in MiB.', 'sqlite-object-cache' ) ) . '</p>' . PHP_EOL;

			echo '<table class="sql-object-cache-stats">' . PHP_EOL;
			/* table headers */
			echo '<thead><tr>';
			echo '<th scope="col" class="right">' . esc_html__( 'Cache Group', 'sqlite-object-cache' ) . '</th>';
			echo '<th scope="col" class="right">' . esc_html__( 'Items', 'sqlite-object-cache' ) . '</th>';
			echo '<th scope="col" class="right">' . esc_html__( 'Size', 'sqlite-object-cache' ) . '</th>';
			echo '</tr></thead><tbody>' . PHP_EOL;

			/* filesize row */
			if ($filesize) {
				echo '<tr>';
				echo '<th scope="row" class="right">' . esc_html__( 'File Size', 'sqlite-object-cache' ) . '</th>';
				echo '<td class="right total"></td>';
				$sizemib = $length / (1024 * 1024);
				echo '<td class="right total">' . esc_html( number_format_i18n( $sizemib, 3 ) ) . '</td>';
				echo '</tr>' . PHP_EOL;
			}
			/* totals row */
			echo '<tr>';
			echo '<th scope="row" class="right">' . esc_html__( 'All Groups', 'sqlite-object-cache' ) . '</th>';
			echo '<td class="right total">' . esc_html( number_format_i18n( $count, 0 ) ) . '</td>';
			$sizemib = $length / (1024 * 1024);
			echo '<td class="right total">' . esc_html( number_format_i18n( $sizemib, 3 ) ) . '</td>';
			echo '</tr>' . PHP_EOL;
			foreach ( $groups as $group ) {
				/* detail row */
				echo '<tr>';
				echo '<th scope="row" class="right">' . esc_html( $group ) . '</th>';
				echo '<td class="right">' . esc_html( number_format_i18n( $groupcount[ $group ], 0 ) ) . '</td>';
				$sizemib = $grouplength[ $group ] / (1024 * 1024);
				echo '<td class="right">' . esc_html( number_format_i18n( $sizemib, 3 ) ) . '</td>';
				echo '</tr>' . PHP_EOL;
			}

			echo '</tbody></table>' . PHP_EOL;
		} else {
			echo '<p>' . esc_html__( 'No cache items found.', 'sqlite-object-cache' ) . '</p>';
		}
	}
}
