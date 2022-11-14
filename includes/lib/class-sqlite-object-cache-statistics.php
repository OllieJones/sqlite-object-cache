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
class SQLite_Object_Cache_Statistics extends SQLite3 {

	private $has_igbinary;
	private $raw;

	/**
	 * Sets up object properties; PHP 5 style constructor.
	 *
	 * @param string $directory Name of the directory for the db file.
	 * @param string $file Name of the db file.
	 * @param int    $timeout Milliseconds before timeout. Do not set to zero.
	 *
	 * @throws Exception Database failure.
	 * @since 0.1.0
	 */
	public function __construct( $directory = WP_CONTENT_DIR, $file = 'sqlite-object-cache.db', $timeout = 500 ) {

		$filepath = $directory . '/' . $file;
		parent::__construct( $filepath );
		$this->busyTimeout( $timeout );
		$this->has_igbinary = function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' );
	}

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	public function init() {
		$this->raw = [];
		$first     = PHP_INT_MAX;
		$last      = PHP_INT_MIN;

		$opens   = [];
		$updates = [];
		$selects = [];
		$inserts = [];
		$deletes = [];
		$ratios  = [];
		$hits    = 0;
		$misses  = 0;

		foreach ( $this->load() as $data ) {
			$ratio      = $data->hits / ( $data->hits + $data->misses );
			$ratios[]   = $ratio;
			$hits       += $data->hits;
			$misses     += $data->misses;
			$first      = min( $data->time, $first );
			$last       = max( $data->time, $last );
			$opens []   = $data->open;
			$updates [] = $data->update;
			$selects [] = $data->selects;
			$inserts [] = $data->inserts;
			$deletes[]  = $data->deletes;
		}
		$duration = $last - $first;
		if ( $duration > 0 ) {
			$open   = $this->descriptive_stats( $opens );
			$update = $this->descriptive_stats( $updates );
			$ratio  = $this->descriptive_stats( $ratios );
			$select = $this->descriptive_stats( array_merge( ...$selects ) );
			$insert = $this->descriptive_stats( array_merge( ...$inserts ) );
			$delete = $this->descriptive_stats( array_merge( ...$deletes ) );
			$a      = '';
		}
	}

	/**
	 * Read rows from the stored statistics.
	 *
	 * @return array|Generator
	 */
	private function load() {
		$result    = [];
		$sql       = "SELECT name, value FROM object_cache WHERE name LIKE 'sqlite_object_cache.mon.%' ORDER BY name;";
		$stmt      = $this->prepare( $sql );
		$resultset = $stmt->execute();
		while ( true ) {
			$row = $resultset->fetchArray( SQLITE3_NUM );
			if ( ! $row ) {
				break;
			}
			$splits = explode( '.', $row[0], 3 );
			$time   = $splits[2];
			$value  = $this->has_igbinary ? igbinary_unserialize( $row[1] ) : unserialize( $row[1] );
			yield (object) $value;
		}
		$resultset->finalize();

		return $result;
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
			'mean'   => $this->mean( $a ),
			'median' => $this->percentile( $a, 0.5 ),
			'p95'    => $this->percentile( $a, 0.95 ),
			'mad'    => $this->mad( $a ),
			'stdev'  => $this->stdev( $a ),
			'min'    => $this->minimum( $a ),
			'max'    => $this->maximum( $a ),
		];
		$result['range'] = $result['max'] - $result['min'];

		return $result;
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

	/** Percentile.
	 *
	 * @param array  $a dataset.
	 * @param number $p percentile as fraction 0-1.
	 *
	 * @return float
	 */
	public function percentile( array $a, $p ) {
		$n = count( $a );
		sort( $a );
		$i = floor( $n * $p );
		if ( $i >= $n ) {
			$i = $n - 1;
		}

		return $a[ $i ];
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

	public function render() {
		return '';
	}

}
