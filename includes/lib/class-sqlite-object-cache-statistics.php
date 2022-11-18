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
 * Settings class.
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
	 *  True if the igbinary serializer is available.
	 *
	 * @var bool
	 */
	private $has_igbinary;
	/**
	 * @var string
	 */
	private $start_time;
	/**
	 * @var string
	 */
	private $end_time;

	/**
	 * Sets up object properties; PHP 5 style constructor.
	 *
	 * @throws Exception Database failure.
	 * @since 0.1.0
	 */
	public function __construct() {

		$this->has_igbinary = function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' );
	}

	/**
	 * Initialize and load data.
	 *
	 * @return void
	 */
	public function init() {
		$first = PHP_INT_MAX;
		$last  = PHP_INT_MIN;

		$selected_names = [];
		$opens          = [];
		$updates        = [];
		$selects        = [];
		$inserts        = [];
		$deletes        = [];
		$RAMratios      = [];
		$RAMhits        = 0;
		$RAMmisses      = 0;
		$DISKratios     = [];
		$DISKhits       = 0;
		$DISKmisses     = 0;

		foreach ( $this->load_measurements() as $data ) {
			$first        = min( $data->time, $first );
			$last         = max( $data->time, $last );
			$RAMhits      = $RAMhits + $data->RAMhits;
			$RAMmisses    = $RAMmisses + $data->RAMmisses;
			$DISKhits     = $DISKhits + $data->RAMhits;
			$DISKmisses   = $DISKmisses + $data->RAMmisses;
			$RAMratio     = $data->RAMhits / ( $data->RAMhits + $data->RAMmisses );
			$RAMratios[]  = $RAMratio;
			$DISKratio    = $data->DISKhits / ( $data->DISKhits + $data->DISKmisses );
			$DISKratios[] = $DISKratio;
			$opens []     = $data->open;
			$updates []   = $data->update;
			$selects []   = $data->selects;
			$inserts []   = $data->inserts;
			$deletes[]    = $data->deletes;

			if ( is_array( $data->select_names ) ) {
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
				__( 'Start', 'sqlite-object-cache' )          => $this->descriptive_stats( $opens ),
				__( 'Save and Close', 'sqlite-object-cache' ) => $this->descriptive_stats( $updates ),
				__( 'RAM Hit Ratio', 'sqlite-object-cache' )  => $this->descriptive_stats( $RAMratios ),
				__( 'Disk Hit Ratio', 'sqlite-object-cache' ) => $this->descriptive_stats( $DISKratios ),
				__( 'Lookup', 'sqlite-object-cache' )         => $this->descriptive_stats( array_merge( ...$selects ) ),
				__( 'Save', 'sqlite-object-cache' )           => $this->descriptive_stats( array_merge( ...$inserts ) ),
				__( 'Delete', 'sqlite-object-cache' )         => $this->descriptive_stats( array_merge( ...$deletes ) ),
			];

			$this->descriptions   = $descriptions;
			$this->selected_names = $selected_names;
			$date_format          = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$this->start_time     = wp_date( $date_format, intval( $first ) );
			$this->end_time       = wp_date( $date_format, intval( $last ) );
		}
	}

	/**
	 * Read rows from the stored statistics.
	 *
	 * @return Generator
	 * @throws Exception Announce SQLite failure.
	 */
	private function load_measurements() {
		global $wp_object_cache;

		if ( method_exists( $wp_object_cache, 'prepare' ) ) {
			$sql       =
				"SELECT name, value FROM object_cache WHERE name LIKE 'sqlite_object_cache|mon|%' ORDER BY name;";
			$stmt      = $wp_object_cache->prepare( $sql );
			$resultset = $stmt->execute();
			while ( true ) {
				$row = $resultset->fetchArray( SQLITE3_NUM );
				if ( ! $row ) {
					break;
				}
				$splits = explode( '|', $row[0], 3 );
				$time   = $splits[2];
				$value  = $this->has_igbinary ? igbinary_unserialize( $row[1] ) : unserialize( $row[1] );
				yield (object) $value;
			}
			$resultset->finalize();
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
		$result          = [
			'n'      => count( $a ),
			'[min'   => $this->minimum( $a ),
			'median' => $this->percentile( $a, 0.5 ),
			'mean'   => $this->mean( $a ),
			'p95'    => $this->percentile( $a, 0.95 ),
			'max]'   => $this->maximum( $a ),
			'mad'    => $this->mad( $a ),
			'stdev'  => $this->stdev( $a ),
		];
		$result['range'] = $result['max]'] - $result['[min'];

		return $result;
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
		$i = intval( floor( $n * $p ) );
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
	 * Render the statistics display.
	 *
	 * @return void
	 */
	public function render() {

		echo '<h3>' . esc_html__( 'Cache performance statistics', 'sqlite-object-cache' ) . '</h3>';
		if ( is_array( $this->descriptions ) ) {
			echo '<p>' . esc_html( sprintf(
			                        /* translators:  1 start time   2 end time both in localized format */
				                    __( 'From %1$s to %2$s.', 'sqlite-object-cache' ),
				                    $this->start_time, $this->end_time ) . ' ' . __( 'Times in microseconds.', 'sqlite-object-cache' ) ) . '</p>';
			echo '<table class="sql-object-cache-stats">';
			$first = true;
			foreach ( $this->descriptions as $stat => $description ) {
				if ( $first ) {
					echo '<thead><tr>';
					echo '<th>' . esc_html__( 'Item', 'sqlite-object-cache' ) . '</th>';
					foreach ( $description as $item => $value ) {
						echo '<th>' . esc_html( $item ) . '</th>';
					}
					echo '</tr></thead><tbody>';
					$first = false;
				}
				echo '<tr>';
				$stat = esc_html( $stat );
				echo "<th scope='row'>$stat</th>";
				foreach ( $description as $item => $value ) {
					echo '<td>' . esc_html( round( $value, 2 ) ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tr></tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No cache statistics recorded yet.', 'sqlite-object-cache' ) . '</p>';
		}

		if ( is_array( $this->selected_names ) && count( $this->selected_names ) > 0 ) {
			echo '<h3>' . esc_html__( 'Most frequently looked up cache items' ) . '</h3>';

			echo '<table class="sql-object-cache-items">';
			$count_threshold = - 1;
			$first           = true;
			foreach ( $this->selected_names as $name => $count ) {
				if ( $first ) {
					echo '<thead><tr>';
					$group_name = esc_html__( "Cache Group", 'sqlite-object-cache' );
					$key_name   = esc_html__( "Cache Key", 'sqlite-object-cache' );
					$count_name = esc_html__( "Count", 'sqlite-object-cache' );
					echo "<th>$group_name</th><th>$key_name</th><th>$count_name</th>";
					echo '</tr></thead><tbody>';
					$count_threshold = intval( $count * 0.7 );
					$first           = false;
				}
				if ( $count < $count_threshold ) {
					break;
				}
				$splits = explode( '|', $name, 2 );
				$group  = esc_html( $splits[0] );
				$key    = esc_html( $splits[1] );
				$count  = esc_html( $count );
				echo "<tr><td>$group</td><td>$key</td><td>$count</td></tr>";
			}
		}
		echo '</tbody></table>';
	}
}
