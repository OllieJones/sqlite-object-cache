<?php
/**
 * Plugin Name: SQLite Object Cache Drop-In
 * Version: 1.0.0
 * Note: This Version number must match the one in the ctor for SQLite_Object_Cache.
 * Plugin URI: https://wordpress.org/plugins/sqlite-object-cache/
 * Description: A persistent object cache backend powered by SQLite3.
 * Author:  Oliver Jones
 * Author URI: https://plumislandmedia.net
 * License: GPLv2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 5.6
 *
 * NOTE: This uses the file .../wp-content/.ht.object_cache.sqlite
 * and the associated files .../wp-content/.ht.object_cache.sqlite-shm
 * and .../wp-content/.ht.object_cache.sqlite-wal to hold cached data.
 * These start with .ht. for security: Web servers block requests
 * for files with that prefix. Use the UNIX ls -a command to
 * see these files from your command line.
 *
 * If you define WP_SQLITE_OBJECT_CACHE_DB_FILE
 * this plugin will use it as the name of the sqlite file
 * in place of .../wp-content/.ht.object_cache.sqlite .
 *
 *
 * Credit: Till Krüss's https://wordpress.org/plugins/redis-cache/ plugin. Thanks, Till!
 *
 * @package SQLiteCache
 */

defined( '\\ABSPATH' ) || exit;

// phpcs:disable Generic.WhiteSpace.ScopeIndent.IncorrectExact, Generic.WhiteSpace.ScopeIndent.Incorrect
if ( ! defined( 'WP_SQLITE_OBJECT_CACHE_DISABLED' ) || ! WP_SQLITE_OBJECT_CACHE_DISABLED ) :

	class SQLite_Object_Cache_Exception extends Exception {

		/**
		 * Custom exception for the SQLite Object Cache.
		 *
		 * @param string  $note Description of the operation causing the exception.
		 * @param SQLite3 $sqlite SQLite connection object.
		 * @param float   $start Start time in microseconds.
		 */
		public function __construct( $note, $sqlite, $start = null ) {
			if ( $start ) {
				$duration = round( 1000.0 * ( $this->time_usec() - $start ) );
				$note     .= ' (' . $duration . ')';
			}
			parent::__construct( $note . ': ' . $sqlite->lastErrorMsg(), $sqlite->lastErrorCode(), null );
		}

		/**
		 * Get the current time in microseconds.
		 *
		 * @return float Current time (relative to some arbitrary epoch, not UNIX's epoch.
		 */
		private function time_usec() {

			if ( function_exists( 'hrtime' ) ) {
				/** @noinspection PhpMethodParametersCountMismatchInspection */
				return hrtime( true ) * 0.001;
			}
			if ( function_exists( 'microtime' ) ) {
				return microtime( true );
			}

			return time() * 1000000.0;
		}

	}

	/**
	 * Object Cache API: WP_Object_Cache class, reworked for SQLite3 drop-in.
	 *
	 * @package WordPress
	 * @subpackage Cache
	 * @since 5.4.0
	 */

	/**
	 * Core class that implements an object cache.
	 *
	 * The WordPress Object Cache is used to save on trips to the database. The
	 * Object Cache stores cache data to memory and makes the cache
	 * contents available by using a key, which is used to name and later retrieve
	 * the cache contents.
	 *
	 * This module is a drop-in, placed in the WP_CONTENT folder, implementing
	 * the WordPress Object Cache class, while using SQLite3 for persistent storage.
	 *
	 * @since 0.1.0
	 */
	class WP_Object_Cache {
		const OBJECT_STATS_TABLE = 'object_stats';
		const OBJECT_CACHE_TABLE = 'object_cache';
		const NOEXPIRE_TIMESTAMP_OFFSET = 500000000000;
		const MAX_LIFETIME = DAY_IN_SECONDS * 2;
		const MYSQLITE_TIMEOUT = 500;
		/**
		 * The amount of times the cache data was already stored in the cache.
		 *
		 * @since 2.5.0
		 * @var int
		 */
		public $cache_hits = 0;
		/**
		 * Amount of times the cache did not have the request in cache.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public $cache_misses = 0;
		/**
		 * The amount of times the cache data was already stored in the persistent cache.
		 *
		 * @since 2.5.0
		 * @var int
		 */
		public $persistent_hits = 0;
		/**
		 * Amount of times the cache did not have the request in persistent cache.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public $persistent_misses = 0;
		/**
		 * The blog prefix to prepend to keys in non-global groups.
		 *
		 * @since 3.5.0
		 * @var string
		 */
		public $blog_prefix;
		/**
		 * List of groups that will not be flushed.
		 *
		 * @var array
		 */
		public $unflushable_groups = [];
		/**
		 * List of groups not saved to cache.
		 *
		 * @var array
		 */
		public $ignored_groups = [
			'counts',
			'plugins',
			'themes',
		];
		/**
		 * List of groups and their types.
		 *
		 * @var array
		 */
		public $group_type = [];
		/**
		 * Prefix used for global groups.
		 *
		 * @var string
		 */
		public $global_prefix = '';
		/**
		 * List of global groups.
		 *
		 * @var array
		 */
		protected $global_groups = [
			'blog-details',
			'blog-id-cache',
			'blog-lookup',
			'global-posts',
			'networks',
			'rss',
			'sites',
			'site-details',
			'site-lookup',
			'site-options',
			'site-transient',
			'users',
			'useremail',
			'userlogins',
			'usermeta',
			'user_meta',
			'userslugs',
		];
		/**
		 * Holds the cached objects.
		 *
		 * @since 2.0.0
		 * @var array
		 */
		private $cache = [];
		/**
		 * Holds the value of is_multisite().
		 *
		 * @since 3.5.0
		 * @var bool
		 */
		private $multisite;

		/**
		 * Prepared statement to get one cache element.
		 *
		 * @var SQLite3Stmt SELECT statement.
		 */
		private $getone;

		/**
		 * Prepared statement to delete one cache element.
		 *
		 * @var SQLite3Stmt DELETE statement.
		 */
		private $deleteone;

		/**
		 * Prepared statement to delete a group of cache elements.
		 *
		 * @var SQLite3Stmt
		 */
		private $deletegroup;

		/**
		 * Prepared statement to upsert one cache element.
		 *
		 * @var SQLite3Stmt
		 */
		private $upsertone;

		/**
		 * Prepared statement to insert one cache element.
		 *
		 * @var SQLite3Stmt
		 */
		private $insertone;

		/**
		 * Prepared statement to update one cache element.
		 *
		 * @var SQLite3Stmt
		 */
		private $updateone;

		/**
		 * Associative array of items we know ARE NOT in SQLite.
		 *
		 * @var array Keys are names, values don't matter.
		 */
		private $not_in_persistent_cache = [];
		/**
		 * Associative array of items we know ARE in SQLite.
		 *
		 * @var array Keys are names, values don't matter.
		 */
		private $in_persistent_cache = [];
		/**
		 * Cache table name.
		 *
		 * @var string  Usually 'object_cache'.
		 */
		private $cache_table_name;
		/**
		 * Flag for availability of igbinary serialization extension.
		 *
		 * @var bool true if it is available.
		 */
		private $has_igbinary;
		/**
		 * Flag.
		 *
		 * @var bool true if hrtime is available.
		 */
		private $has_hrtime;
		/**
		 * Flag.
		 *
		 * @var bool true if microtime is available.
		 */
		private $has_microtime;
		/**
		 * The expiration time of non-expiring cache entries has this added to the timestamp.
		 *
		 * This is a sentinel value, marking a non-expiring cache entry AND
		 * recording when it was inserted or updated.
		 * It allows a least-recently-changed cache-entry purging strategy.
		 *
		 * If we wanted a least-recently-used purge, we would need to
		 * update each cache item's row whenever we accessed it. That
		 * would cost more than it's worth.
		 *
		 * @var int a large number of seconds, much larger than 2**32
		 */
		private $noexpire_timestamp_offset;
		/**
		 * The maximum age of an entry before we get rid of it.
		 *
		 * @var int the maximum lifetime of a cache entry, often a week.
		 */
		private $max_lifetime;
		/**
		 * An array of elapsed times for each cache-retrieval operation.
		 *
		 * @var array[float]
		 */
		private $select_times = [];
		/**
		 * An array of elapsed times for each cache-insertion / update operation.
		 *
		 * @var array[float]
		 */
		private $insert_times = [];
		/**
		 * An array of item names for each cache-retrieval operation.
		 *
		 * @var array[string]
		 */
		private $select_names = [];
		/**
		 * An array of item names for each cache-insertion / update operation.
		 *
		 * @var array[float]
		 */
		private $insert_names = [];
		/**
		 * An array of elapsed times for each single-row cache deletion operation.
		 *
		 * @var array[float]
		 */
		private $delete_times = [];
		/**
		 * The time it took to open the db.
		 *
		 * @var float
		 */
		private $open_time;

		/**
		 * Monitoring options for the SQLite cache.
		 *
		 * Options in array [
		 *    'capture' => (bool)
		 *    'resolution' => how often in seconds (float)
		 *    'lifetime' => how long until entries expire in seconds (int)
		 *    'verbose'  => (bool) capture extra stuff.
		 *  ]
		 *
		 * @var array $options Option list.
		 */
		private $monitoring_options;

		/**
		 * Database object.
		 * @var SQLite3 instance.
		 */
		private $sqlite;

		/**
		 * Constructor for SQLite Object Cache.
		 *
		 * @param string $directory Name of the directory for the db file.
		 * @param string $file Name of the db file.
		 * @param int    $timeout Milliseconds before timeout.
		 *
		 * @since 2.0.8
		 */
		public function __construct( $directory = WP_CONTENT_DIR, $file = '.ht.object-cache.sqlite', $timeout = self::MYSQLITE_TIMEOUT ) {

			$this->cache_group_types();

			$db_file = $directory . '/' . $file;
			if ( defined( 'WP_SQLITE_OBJECT_CACHE_DB_FILE' ) ) {
				$db_file = WP_SQLITE_OBJECT_CACHE_DB_FILE;
			}
			$this->sqlite_path = $db_file;

			$this->multisite                 = is_multisite();
			$this->blog_prefix               = $this->multisite ? get_current_blog_id() . ':' : '';
			$this->sqlite_timeout            = $timeout ?: self::MYSQLITE_TIMEOUT;
			$this->cache_table_name          = self::OBJECT_CACHE_TABLE;
			$this->noexpire_timestamp_offset = self::NOEXPIRE_TIMESTAMP_OFFSET;
			$this->max_lifetime              = self::MAX_LIFETIME;

			$this->has_igbinary  =
				function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' );
			$this->has_hrtime    = function_exists( 'hrtime' );
			$this->has_microtime = function_exists( 'microtime' );
		}

		/**
		 * Open SQLite3 connection.
		 * @return void
		 */
		private function open_connection() {
			if ( $this->sqlite ) {
				return;
			}
			$max_retries = 3;
			$retries     = 0;
			while ( ++ $retries <= $max_retries ) {
				try {
					$this->actual_open_connection();

					return;
				} catch ( \Exception $ex ) {
					/* something went wrong opening */
					error_log( $ex );
					$this->delete_offending_files( $retries );
				}
			}
		}

		/**
		 * Open SQLite3 connection.
		 *
		 * @return void
		 * @throws SQLite_Object_Cache_Exception Announce SQLite failure.
		 */
		private function actual_open_connection() {
			$start        = $this->time_usec();
			$this->sqlite = new SQLite3( $this->sqlite_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE, null );
			$this->sqlite->enableExceptions( true );
			$this->sqlite->busyTimeout( $this->sqlite_timeout );

			/* set some initial pragma stuff */
			/* NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3 */

			/* Notice we use a journal mode that risks database corruption.
			 * That's OK, because it's MUCH faster, and because we have an error
			 * recovery procedure that deletes and recreates a corrupt database file.
			 */
			$this->exec( 'PRAGMA synchronous = OFF' );
			$this->exec( 'PRAGMA journal_mode = MEMORY' );
			$this->exec( "PRAGMA encoding = 'UTF-8'" );
			$this->exec( 'PRAGMA case_sensitive_like = true' );

			$this->create_object_cache_table();
			$this->prepare_statements( $this->cache_table_name );
			$this->preload( $this->cache_table_name );

			$this->open_time = $this->time_usec() - $start;
		}

		/**
		 * Get current time.
		 *
		 * @return float Current time in microseconds, from an arbitrary epoch.
		 */
		private function time_usec() {
			if ( $this->has_hrtime ) {
				/** @noinspection PhpMethodParametersCountMismatchInspection */
				/** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
				return hrtime( true ) * 0.001;
			}
			if ( $this->has_microtime ) {
				return microtime( true );
			}

			return time() * 1000000.0;
		}

		/**
		 * Set group type array
		 *
		 * @return void
		 */
		protected function cache_group_types() {
			foreach ( $this->global_groups as $group ) {
				$this->group_type[ $group ] = 'global';
			}

			foreach ( $this->unflushable_groups as $group ) {
				$this->group_type[ $group ] = 'unflushable';
			}

			foreach ( $this->ignored_groups as $group ) {
				$this->group_type[ $group ] = 'ignored';
			}
		}

		/**
		 * Wrapper for exec, to check for errors.
		 *
		 * @param string $query The SQL Statement.
		 *
		 * @return bool
		 * @throws SQLite_Object_Cache_Exception If something failed.
		 */
		public function exec( $query ) {
			$start  = $this->time_usec();
			$result = $this->sqlite->exec( $query );
			if ( ! $result ) {
				throw new SQLite_Object_Cache_Exception( 'exec', $this->sqlite, $start );
			}

			return true;
		}

		/**
		 * Do the necessary Data Definition Language work.
		 *
		 * @return void
		 * @throws SQLite_Object_Cache_Exception If something fails.
		 * @noinspection SqlResolve
		 */
		private function create_object_cache_table() {
			/* NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3 */
			$this->exec( 'BEGIN;' );
			/* does our table exist?  */
			$q = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND tbl_name = '$this->cache_table_name';";
			$r = $this->querySingle( $q );
			if ( 0 === $r ) {
				/* later versions of SQLite3 have clustered primary keys, "WITHOUT ROWID" */
				if ( version_compare( $this->sqlite_get_version(), '3.8.2' ) < 0 ) {
					/* @noinspection SqlIdentifier */
					$t = "
						CREATE TABLE IF NOT EXISTS $this->cache_table_name (
						   name TEXT NOT NULL COLLATE BINARY,
						   value BLOB,
						   expires INT
						);
						CREATE UNIQUE INDEX IF NOT EXISTS name ON $this->cache_table_name (name);
						CREATE INDEX IF NOT EXISTS expires ON $this->cache_table_name (expires);";
				} else {
					/* @noinspection SqlIdentifier */
					$t = "
						CREATE TABLE IF NOT EXISTS $this->cache_table_name (
						   name TEXT NOT NULL PRIMARY KEY COLLATE BINARY,
						   value BLOB,
						   expires INT
						) WITHOUT ROWID;
						CREATE INDEX IF NOT EXISTS expires ON $this->cache_table_name (expires);";
				}

				$this->exec( $t );
			}
			$this->exec( 'COMMIT;' );
		}

		/**
		 * Do the necessary Data Definition Language work.
		 *
		 * @param string $tbl The name of the table.
		 *
		 * @return void
		 * @throws SQLite_Object_Cache_Exception If something fails.
		 * @noinspection SqlResolve
		 */
		private function maybe_create_stats_table( $tbl ) {
			/* NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3 */
			$this->exec( 'BEGIN;' );
			/* does our table exist?  */
			$q = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND tbl_name = '$tbl';";
			$r = $this->querySingle( $q );
			if ( 0 === $r ) {
				/* @noinspection SqlIdentifier */
				$t = "
						CREATE TABLE IF NOT EXISTS $tbl (
						   value BLOB,
						   timestamp INT
						);
						CREATE INDEX IF NOT EXISTS expires ON $tbl (timestamp);";
				$this->exec( $t );
			}
			$this->exec( 'COMMIT;' );
		}

		/**
		 * Wrapper for querySingle, to detect if something failed.
		 *
		 * @param string $query The query.
		 * @param bool   $entire_row If false, give back the first column.
		 *
		 * @return mixed
		 * @throws SQLite_Object_Cache_Exception Announce database failure.
		 */
		public function querySingle( $query, $entire_row = false ) {
			$start  = $this->time_usec();
			$result = $this->sqlite->querySingle( $query, $entire_row );
			if ( false === $result ) {
				throw new SQLite_Object_Cache_Exception( 'querySingle', $this->sqlite, $start );
			}

			return $result;
		}

		/**
		 * Create the prepared statements to use.
		 *
		 * @param string $tbl Table name.
		 *
		 * @return void
		 * @throws SQLite_Object_Cache_Exception Announce failure.
		 * @noinspection SqlResolve
		 */
		private function prepare_statements( $tbl ) {
			/* NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3 */

			$now       = time();
			$updateone = "UPDATE $tbl SET value = :value, expires = $now + :expires WHERE name = :name;";
			$insertone = "INSERT INTO $tbl (name, value, expires) VALUES (:name, :value, $now + :expires);";
			/* @noinspection SqlResolve */
			$upsertone   = "
INSERT INTO $tbl (name, value, expires) 
VALUES (:name, :value, $now + :expires) 
ON CONFLICT(name) DO UPDATE
SET value=excluded.value, expires=excluded.expires;";
			$getone      = "SELECT value FROM $tbl WHERE name = :name AND expires >= $now;";
			$deleteone   = "DELETE FROM $tbl WHERE name = :name;";
			$deletegroup = "DELETE FROM $tbl WHERE name LIKE :group || '.%';";

			$this->getone      = $this->prepare( $getone );
			$this->deleteone   = $this->prepare( $deleteone );
			$this->deletegroup = $this->prepare( $deletegroup );
			/*
			 * Some versions of SQLite3 built into php predate the 3.38 advent of unixepoch() (2022-02-22).
			 * And, others predate the 3.24 advent of UPSERT (that is, ON CONFLICT) syntax.
			 * In that case we have to do attempt-update then insert to get updates to work. Sigh.
			 */
			$has_upsert = version_compare( $this->sqlite_get_version(), '3.24', 'ge' );
			if ( $has_upsert ) {
				$this->upsertone = $this->prepare( $upsertone );
			} else {
				$this->insertone = $this->prepare( $insertone );
				$this->updateone = $this->prepare( $updateone );
			}
		}

		/**
		 * Wrapper around prepare()
		 *
		 * @param string $query Statement to prepare.
		 *
		 * @return SQLite3Stmt
		 * @throws SQLite_Object_Cache_Exception Announce failure.
		 */
		public function prepare( $query ) {
			$start  = $this->time_usec();
			$result = $this->sqlite->prepare( $query );
			if ( false === $result ) {
				throw new SQLite_Object_Cache_Exception( 'prepare', $this->sqlite, $start );
			}

			return $result;
		}

		/**
		 * Preload frequently accessed items.
		 *
		 * @param string $tbl Cache table name.
		 *
		 * @return void
		 * @noinspection SqlResolve
		 */
		public function preload( $tbl ) {
			$list =
				[
					'options|%',
					'default|%',
					'posts|last_changed',
					'terms|last_changed',
					'site_options|%notoptions',
					'transient|doing_cron',
				];

			$sql     = '';
			$clauses = [];
			foreach ( $list as $item ) {
				/* NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3 */
				$clauses [] = "SELECT name, value FROM $tbl WHERE name LIKE '$item'";
			}
			$sql .= implode( ' UNION ALL ', $clauses ) . ';';

			$resultset = $this->sqlite->query( $sql );
			if ( ! $resultset ) {
				return;
			}
			while ( true ) {
				$row = $resultset->fetchArray( SQLITE3_NUM );
				if ( ! $row ) {
					break;
				}
				list( $group, $key ) = explode( '|', $row[0], 2 );
				$val = $this->maybe_unserialize( $row[1] );
				/* Put the preloaded value into the cache. */
				$this->cache[ $group ][ $key ] = $val;
			}
		}

		/**
		 * Serialize data for persistence if need be. Use igbinary if available.
		 *
		 * @param mixed $data To be unserialized.
		 *
		 * @return string|mixed Data ready for use.
		 */
		private function maybe_unserialize( $data ) {
			if ( $this->has_igbinary ) {
				return igbinary_unserialize( $data );
			}

			return maybe_unserialize( $data );
		}

		/**
		 * Determine whether we can use SQLite3.
		 *
		 * @param string $directory The directory to hold the .sqlite file. Default WP_CONTENT_DIR.
		 *
		 * @return bool|string true, or an error message.
		 */
		public static function has_sqlite( $directory = WP_CONTENT_DIR ) {
			if ( ! wp_is_writable( $directory ) ) {
				return sprintf( /* translators: 1: WP_CONTENT_DIR */ __( 'The SQLite Object Cache cannot be activated because the %s directory is not writable.', 'sqlite-object-cache' ), $directory );
			}

			if ( ! class_exists( 'SQLite3' ) || ! extension_loaded( 'sqlite3' ) ) {
				return __( 'The SQLite Object Cache cannot be activated because the SQLite3 extension is not loaded.', 'sqlite-object-cache' );
			}

			return true;
		}

		/**
		 * Set the monitoring options for the SQLite cache.
		 *
		 * Options in array [
		 *    'capture' => (bool)
		 *    'resolution' => how often in seconds (float)
		 *    'lifetime' => how long until entries expire in seconds (int)
		 *    'verbose'  => (bool) capture extra stuff.
		 *  ]
		 *
		 * @param array $options Option list.
		 *
		 * @return void
		 */
		public function set_sqlite_monitoring_options( $options ) {
			$this->monitoring_options = $options;
		}

		/**
		 * Capture statistics if need be, then close the connection.
		 *
		 * @return bool
		 */
		public function close() {
			$result = true;
			if ( $this->sqlite ) {
				try {
					if ( is_array( $this->monitoring_options ) ) {
						$this->capture( $this->monitoring_options );
					}
					$result       = $this->sqlite->close();
					$this->sqlite = null;
				} catch ( Exception $ex ) {
					error_log( $ex );
					$this->delete_offending_files();
				}
			}

			return $result;
		}

		/**
		 * Generate canonical name for cache item
		 *
		 * @param string $key The key name.
		 * @param string $group The group name.
		 *
		 * @return string The name.
		 */
		private function name_from_key_group( $key, $group ) {
			return $group . '|' . $key;
		}

		/**
		 * Serialize data for persistence if need be. Use igbinary if available.
		 *
		 * @param mixed $data To be serialized.
		 *
		 * @return string|mixed Data ready for dbms insertion.
		 */
		private function maybe_serialize( $data ) {
			if ( $this->has_igbinary ) {
				return igbinary_serialize( $data );
			}

			return maybe_serialize( $data );
		}

		/**
		 * Remove statistics entries from the cache
		 *
		 * @param int|null $age Number of seconds' worth to retain. Default: retain none.
		 *
		 * @return void
		 * @throws SQLite_Object_Cache_Exception Announce database error.
		 */
		public function sqlite_reset_statistics( $age = null ) {
			if ( ! $this->sqlite ) {
				$this->open_connection();
			}
			$object_stats = self::OBJECT_STATS_TABLE;
			$this->maybe_create_stats_table( $object_stats );
			/* NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3 */
			if ( ! is_numeric( $age ) ) {
				/* @noinspection SqlWithoutWhere */
				$sql = "DELETE FROM $object_stats;";
			} else {
				$expires = (int) ( time() - $age );
				/* @noinspection SqlResolve */
				$sql =
					"DELETE FROM $object_stats WHERE timestamp < $expires;";
			}
			$this->exec( $sql );
		}

		/**
		 * Remove old entries and VACUUM the database.
		 *
		 * @param mixed $retention How long, in seconds, to keep old entries. Default one week.
		 * @param bool  $use_transaction True if the cleanup should be inside BEGIN / COMMIT.
		 * @param bool  $vacuum VACUUM the db.
		 *
		 * @return void
		 * @noinspection SqlResolve
		 */
		public function sqlite_clean_up_cache( $retention = null, $use_transaction = true, $vacuum = false ) {
			/* NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3 */
			try {
				if ( ! $this->sqlite ) {
					$this->open_connection();
				}
				if ( $use_transaction ) {
					$this->exec( 'BEGIN;' );
				}
				/* Remove items with definite expirations, like transients */
				$sql  = "DELETE FROM $this->cache_table_name WHERE expires <= :now;";
				$stmt = $this->prepare( $sql );
				$stmt->bindValue( ':now', time(), SQLITE3_INTEGER );
				$result = $stmt->execute();
				if ( false !== $result ) {
					$result->finalize();
				}
				/* Remove old items. We use the most recent update time. Tracking use time is too expensive. */
				$retention = is_numeric( $retention ) ? $retention : $this->max_lifetime;
				$sql       = "DELETE FROM $this->cache_table_name WHERE expires BETWEEN :offset AND :end;";
				$stmt      = $this->prepare( $sql );
				$offset    = $this->noexpire_timestamp_offset;
				$end       = time() + $offset - $retention;
				$stmt->bindValue( ':offset', $offset, SQLITE3_INTEGER );
				$stmt->bindValue( ':end', $end, SQLITE3_INTEGER );
				$result = $stmt->execute();
				if ( false !== $result ) {
					$result->finalize();
					if ( $use_transaction ) {
						$this->exec( 'COMMIT;' );
					}
				}
				if ( $vacuum ) {
					$this->exec( 'VACUUM;' );
					$this->exec( 'PRAGMA analysis_limit=400;' );
					$this->exec( 'PRAGMA optimize;' );
				}
			} catch ( SQLite_Object_Cache_Exception $ex ) {
				error_log( $ex );
				$this->delete_offending_files( 0 );
			}
		}

		/**
		 * Read rows from the stored statistics.
		 *
		 * @return Generator
		 * @throws SQLite_Object_Cache_Exception Announce SQLite failure.
		 * @noinspection SqlResolve
		 */
		public function sqlite_load_statistics() {
			if ( ! $this->sqlite ) {
				$this->open_connection();
			}

			$object_stats = self::OBJECT_STATS_TABLE;
			$this->maybe_create_stats_table( $object_stats );
			$sql       = 'SELECT value FROM object_stats ORDER BY timestamp;';
			$stmt      = $this->prepare( $sql );
			$resultset = $stmt->execute();
			while ( true ) {
				$row = $resultset->fetchArray( SQLITE3_NUM );
				if ( ! $row ) {
					break;
				}
				$value = $this->maybe_unserialize( $row[0] );
				yield (object) $value;
			}
			$resultset->finalize();
		}

		/**
		 * Do the performance-capture operation.
		 *
		 * Put a row named sqlite_object_cache.mon.123456 into sqlite containing the raw data.
		 *
		 * @param array $options Contents of $this->monitoring_options.
		 *
		 * @return void
		 * @noinspection SqlResolve
		 */
		private function capture( $options ) {
			if ( ! array_key_exists( 'capture', $options ) || ! $options['capture'] ) {
				return;
			}
			$now    = microtime( true );
			$record = [
				'time'       => $now,
				'RAMhits'    => $this->cache_hits,
				'RAMmisses'  => $this->cache_misses,
				'DISKhits'   => $this->persistent_hits,
				'DISKmisses' => $this->persistent_misses,
				'open'       => $this->open_time,
				'selects'    => $this->select_times,
				'inserts'    => $this->insert_times,
				'deletes'    => $this->delete_times,
			];
			if ( $options['verbose'] ) {
				$record ['select_names'] = $this->select_names;
				$record ['delete_names'] = $this->insert_names;
			}

			$object_stats = self::OBJECT_STATS_TABLE;
			try {
				if ( ! $this->sqlite ) {
					$this->open_connection();
				}
				$this->maybe_create_stats_table( $object_stats );
				$sql  =
					"INSERT INTO $object_stats (value, timestamp) VALUES (:value, :timestamp);";
				$stmt = $this->prepare( $sql );
				$stmt->bindValue( ':value', $this->maybe_serialize( $record ), SQLITE3_BLOB );
				$stmt->bindValue( ':timestamp', time(), SQLITE3_INTEGER );
				$result = $stmt->execute();
				if ( $result ) {
					$result->finalize();
				}
			} catch ( \Exception $ex ) {
				error_log( $ex );
				$this->delete_offending_files( 0 );
			}
			unset( $record, $stmt );
		}

		/**
		 *  Get the version of SQLite in use.
		 *
		 * @return string
		 */
		public function sqlite_get_version() {
			$v = SQLite3::version();

			return $v['versionString'];
		}

		/**
		 * Sets the list of groups not to be cached by Redis.
		 *
		 * @param array $groups List of groups that are to be ignored.
		 */
		public function add_non_persistent_groups( $groups ) {
			/**
			 * Filters list of groups to be added to {@see self::$ignored_groups}
			 *
			 * @param string[] $groups List of groups to be ignored.
			 *
			 * @since 2.1.7
			 */
			$groups = apply_filters( 'sqlite_object_cache_add_non_persistent_groups', (array) $groups );

			$this->ignored_groups = array_unique( array_merge( $this->ignored_groups, $groups ) );
			$this->cache_group_types();
		}

		/**
		 * Makes private properties readable for backward compatibility.
		 *
		 * @param string $name Property to get.
		 *
		 * @return mixed Property.
		 * @since 4.0.0
		 */
		public function __get( $name ) {
			return $this->$name;
		}

		/**
		 * Makes private properties settable for backward compatibility.
		 *
		 * @param string $name Property to set.
		 * @param mixed  $value Property value.
		 *
		 * @return mixed Newly-set property.
		 * @since 4.0.0
		 */
		public function __set( $name, $value ) {
			return $this->$name = $value;
		}

		/**
		 * Makes private properties checkable for backward compatibility.
		 *
		 * @param string $name Property to check if set.
		 *
		 * @return bool Whether the property is set.
		 * @since 4.0.0
		 */
		public function __isset( $name ) {
			return isset( $this->$name );
		}

		/**
		 * Makes private properties un-settable for backward compatibility.
		 *
		 * @param string $name Property to unset.
		 *
		 * @since 4.0.0
		 */
		public function __unset( $name ) {
			unset( $this->$name );
		}

		/**
		 * Adds multiple values to the cache in one call.
		 *
		 * @param array  $data Array of keys and values to be added.
		 * @param string $group Optional. Where the cache contents are grouped. Default empty.
		 * @param int    $expire Optional. When to expire the cache contents, in seconds.
		 *                       Default 0 (no expiration).
		 *
		 * @return bool[] Array of return values, grouped by key. Each value is either
		 *                true on success, or false if cache key and group already exist.
		 * @throws SQLite_Object_Cache_Exception Announce database failure.
		 * @since 6.0.0
		 */
		public function add_multiple( array $data, $group = '', $expire = 0 ) {
			$values = [];

			foreach ( $data as $key => $value ) {
				$values[ $key ] = $this->add( $key, $value, $group, $expire );
			}

			return $values;
		}

		/**
		 * Adds data to the cache if it doesn't already exist.
		 *
		 * @param int|string $key What to call the contents in the cache.
		 * @param mixed      $data The contents to store in the cache.
		 * @param string     $group Optional. Where to group the cache contents. Default 'default'.
		 * @param int        $expire Optional. When to expire the cache contents, in seconds.
		 *                           Default 0 (no expiration).
		 *
		 * @return bool True on success, false if cache key and group already exist.
		 * @throws SQLite_Object_Cache_Exception Announce database failure.
		 * @since 2.0.0
		 *
		 * @uses WP_Object_Cache::cache_item_exists() Checks to see if the cache already has data.
		 * @uses WP_Object_Cache::set()     Sets the data after the checking the cache
		 *                                  contents existence.
		 */
		public function add( $key, $data, $group = 'default', $expire = 0 ) {
			if ( wp_suspend_cache_addition() ) {
				return false;
			}

			if ( ! $this->is_valid_key( $key ) ) {
				return false;
			}

			if ( empty( $group ) ) {
				$group = 'default';
			}

			$id = $key;
			if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
				$id = $this->blog_prefix . $key;
			}

			if ( $this->cache_item_exists( $id, $group ) ) {
				return false;
			}

			return $this->set( $key, $data, $group, (int) $expire );
		}

		/**
		 * Serves as a utility function to determine whether a key is valid.
		 *
		 * @param int|string $key Cache key to check for validity.
		 *
		 * @return bool Whether the key is valid.
		 * @since 6.1.0
		 */
		protected function is_valid_key( $key ) {
			if ( is_int( $key ) ) {
				return true;
			}

			if ( is_string( $key ) && trim( $key ) !== '' ) {
				return true;
			}

			$type = gettype( $key );

			if ( ! function_exists( '__' ) ) {
				wp_load_translations_early();
			}

			$message =
				is_string( $key ) ? __( 'Cache key must not be an empty string.' )
					/* translators: %s: The type of the given cache key. */
					: sprintf( __( 'Cache key must be integer or non-empty string, %s given.' ), $type );
			// phpcs:ignore
			_doing_it_wrong( sprintf( '%s::%s', __CLASS__, debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1]['function'] ), $message, '6.1.0' );

			return false;
		}

		/**
		 * Determine whether a key exists in the cache.
		 *
		 * @param int|string $key Cache key to check for existence.
		 * @param string     $group Cache group for the key existence check.
		 *
		 * @return bool Whether the key exists in the cache for the given group.
		 * @throws SQLite_Object_Cache_Exception Announce database failure.
		 * @since 3.4.0
		 */
		protected function cache_item_exists( $key, $group ) {
			$exists =
				isset( $this->cache[ $group ] ) && ( isset( $this->cache[ $group ][ $key ] ) || array_key_exists( $key, $this->cache[ $group ] ) );
			if ( ! $exists ) {
				$val = $this->getone( $key, $group );
				if ( null !== $val ) {
					if ( ! array_key_exists( $group, $this->cache ) ) {
						$this->cache [ $group ] = [];
					}
					$this->cache[ $group ][ $key ] = $val;
					$exists                        = true;
					$this->persistent_hits ++;
				} else {
					$this->persistent_misses ++;
				}
			}

			return $exists;
		}

		/**
		 * Get one item from external cache.
		 *
		 * @param string $key Cache key.
		 * @param string $group Group name.
		 *
		 * @return mixed|null Cached item, or null if not found. (Cached item can be false.)
		 * @throws SQLite_Object_Cache_Exception Announce database failure.
		 */
		private function getone( $key, $group ) {
			if ( ! $this->sqlite ) {
				$this->open_connection();
			}
			$start = $this->time_usec();
			$name  = $this->name_from_key_group( $key, $group );
			if ( array_key_exists( $name, $this->not_in_persistent_cache ) ) {
				return null;
			}
			$stmt = $this->getone;
			$stmt->bindValue( ':name', $name, SQLITE3_TEXT );
			$result = $stmt->execute();
			if ( false === $result ) {
				unset( $this->in_persistent_cache[ $name ] );
				$this->not_in_persistent_cache [ $name ] = true;
				throw new SQLite_Object_Cache_Exception( 'stmt->execute', $this->sqlite, $start );
			}
			$row  = $result->fetchArray( SQLITE3_NUM );
			$data = false !== $row && is_array( $row ) && 1 === count( $row ) ? $row[0] : null;
			if ( null !== $data ) {
				$data                                = $this->maybe_unserialize( $data );
				$this->in_persistent_cache [ $name ] = true;
			} else {
				$this->not_in_persistent_cache [ $name ] = true;
			}

			$this->select_times[] = $this->time_usec() - $start;
			$this->select_names[] = $name;

			return $data;
		}

		/**
		 * Sets the data contents into the cache.
		 *
		 * The cache contents are grouped by the $group parameter followed by the
		 * $key. This allows for duplicate IDs in unique groups. Therefore, naming of
		 * the group should be used with care and should follow normal function
		 * naming guidelines outside of core WordPress usage.
		 *
		 * The $expire parameter is not used, because the cache will automatically
		 * expire for each time a page is accessed and PHP finishes. The method is
		 * more for cache plugins which use files.
		 *
		 * @param int|string $key What to call the contents in the cache.
		 * @param mixed      $data The contents to store in the cache.
		 * @param string     $group Optional. Where to group the cache contents. Default 'default'.
		 * @param int        $expire Optional. Not used.
		 *
		 * @return bool True if contents were set, false if key is invalid.
		 * @since 6.1.0 Returns false if cache key is invalid.
		 *
		 * @since 2.0.0
		 */
		public function set( $key, $data, $group = 'default', $expire = 0 ) {
			if ( ! $this->is_valid_key( $key ) ) {
				return false;
			}

			if ( empty( $group ) ) {
				$group = 'default';
			}

			if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
				$key = $this->blog_prefix . $key;
			}

			if ( is_object( $data ) ) {
				$data = clone $data;
			}

			$this->cache[ $group ][ $key ] = $data;
			$this->handle_put( $key, $data, $group, $expire );

			return true;
		}

		/**
		 * Write to the persistent cache.
		 *
		 * @param int|string $key What to call the contents in the cache.
		 * @param mixed      $data The contents to store in the cache.
		 * @param string     $group Optional. Where to group the cache contents. Default 'default'.
		 * @param int        $expire Optional. Not used.
		 *
		 * @return void
		 * @throws SQLite_Object_Cache_Exception    Announce database failure.
		 */
		private function handle_put( $key, $data, $group, $expire ) {
			if ( ! $this->sqlite ) {
				$this->open_connection();
			}

			if ( $this->is_ignored_group( $group ) ) {
				return;
			}
			$name    = $this->name_from_key_group( $key, $group );
			$start   = $this->time_usec();
			$value   = $this->maybe_serialize( $data );
			$expires = $expire ?: $this->noexpire_timestamp_offset;
			if ( $this->upsertone ) {
				$stmt = $this->upsertone;
				$stmt->bindValue( ':name', $name, SQLITE3_TEXT );
				$stmt->bindValue( ':value', $value, SQLITE3_BLOB );
				$stmt->bindValue( ':expires', $expires, SQLITE3_INTEGER );
				$result = $stmt->execute();
				if ( false === $result ) {
					$this->delete_offending_files( 0 );
					throw new SQLite_Object_Cache_Exception( 'upsert: ' . $name, $this->sqlite, $start );
				}
				$result->finalize();
			} else {
				$stmt = $this->updateone;
				$stmt->bindValue( ':name', $name, SQLITE3_TEXT );
				$stmt->bindValue( ':value', $value, SQLITE3_BLOB );
				$stmt->bindValue( ':expires', $expires, SQLITE3_INTEGER );
				$result = $stmt->execute();
				if ( false === $result ) {
					$this->delete_offending_files( 0 );
					throw new SQLite_Object_Cache_Exception( 'update: ' . $name, $this->sqlite, $start );
				}
				$result->finalize();
				if ( 0 === $this->sqlite->changes() ) {
					/* Updated no rows:  need insert. */
					$start2 = $this->time_usec();
					$stmt   = $this->insertone;
					$stmt->bindValue( ':name', $name, SQLITE3_TEXT );
					$stmt->bindValue( ':value', $value, SQLITE3_BLOB );
					$stmt->bindValue( ':expires', $expires, SQLITE3_INTEGER );
					$result = $stmt->execute();
					if ( false === $result ) {
						$this->delete_offending_files( 0 );
						throw new SQLite_Object_Cache_Exception( 'insert: ' . $name, $this->sqlite, $start2 );
					}
					$result->finalize();
				}
			}
			unset( $this->not_in_persistent_cache[ $name ] );
			$this->in_persistent_cache[ $name ] = true;
			/* track how long it took. */
			$this->insert_times[] = $this->time_usec() - $start;
			$this->insert_names[] = $name;
		}

		/**
		 * Replaces the contents in the cache, if contents already exist.
		 *
		 * @param int|string $key What to call the contents in the cache.
		 * @param mixed      $data The contents to store in the cache.
		 * @param string     $group Optional. Where to group the cache contents. Default 'default'.
		 * @param int        $expire Optional. When to expire the cache contents, in seconds.
		 *                           Default 0 (no expiration).
		 *
		 * @return bool True if contents were replaced, false if original value does not exist.
		 * @see WP_Object_Cache::set()
		 *
		 * @since 2.0.0
		 *
		 */
		public function replace( $key, $data, $group = 'default', $expire = 0 ) {
			if ( ! $this->is_valid_key( $key ) ) {
				return false;
			}

			if ( empty( $group ) ) {
				$group = 'default';
			}

			$id = $key;
			if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
				$id = $this->blog_prefix . $key;
			}

			if ( ! $this->cache_item_exists( $id, $group ) ) {
				return false;
			}

			return $this->set( $key, $data, $group, (int) $expire );
		}

		/**
		 * Sets multiple values to the cache in one call.
		 *
		 * @param array  $data Array of key and value to be set.
		 * @param string $group Optional. Where the cache contents are grouped. Default empty.
		 * @param int    $expire Optional. When to expire the cache contents, in seconds.
		 *                       Default 0 (no expiration).
		 *
		 * @return bool[] Array of return values, grouped by key. Each value is always true.
		 * @since 6.0.0
		 */
		public function set_multiple( array $data, $group = '', $expire = 0 ) {
			$values = [];

			foreach ( $data as $key => $value ) {
				$values[ $key ] = $this->set( $key, $value, $group, $expire );
			}

			return $values;
		}

		/**
		 * Retrieves multiple values from the cache in one call.
		 *
		 * @param array  $keys Array of keys under which the cache contents are stored.
		 * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
		 * @param bool   $force Optional. Whether to force an update of the local cache
		 *                      from the persistent cache. Default false.
		 *
		 * @return array Array of return values, grouped by key. Each value is either
		 *               the cache contents on success, or false on failure.
		 * @throws SQLite_Object_Cache_Exception Announce database failure.
		 * @since 5.5.5
		 */
		public function get_multiple( $keys, $group = 'default', $force = false ) {
			$values = [];

			foreach ( $keys as $key ) {
				$values[ $key ] = $this->get( $key, $group, $force );
			}

			return $values;
		}

		/**
		 * Retrieves the cache contents, if it exists.
		 *
		 * The contents will be first attempted to be retrieved by searching by the
		 * key in the cache group. If the cache is hit (success) then the contents
		 * are returned.
		 *
		 * On failure, the number of cache misses will be incremented.
		 *
		 * @param int|string $key The key under which the cache contents are stored.
		 * @param string     $group Optional. Where the cache contents are grouped. Default 'default'.
		 * @param bool       $force Optional. Whether to force an update of the local cache
		 *                          from the persistent cache. Default false.
		 * @param bool       $found Optional. Whether the key was found in the cache (passed by reference).
		 *                          Disambiguates a return of false, a storable value. Default null.
		 *
		 * @return mixed|false The cache contents on success, false on failure to retrieve contents.
		 * @throws SQLite_Object_Cache_Exception Announce database failure.
		 * @since 2.0.0
		 */
		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			if ( ! $this->is_valid_key( $key ) ) {
				return false;
			}

			if ( empty( $group ) ) {
				$group = 'default';
			}

			if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
				$key = $this->blog_prefix . $key;
			}

			if ( $force ) {
				unset( $this->cache[ $group ][ $key ] );
			}

			if ( $this->cache_item_exists( $key, $group ) ) {
				$found = true;
				++ $this->cache_hits;
				if ( is_object( $this->cache[ $group ][ $key ] ) ) {
					return clone $this->cache[ $group ][ $key ];
				}

				return $this->cache[ $group ][ $key ];
			}

			$found = false;
			$this->cache_misses ++;

			return false;
		}

		/**
		 * Deletes multiple values from the cache in one call.
		 *
		 * @param array  $keys Array of keys to be deleted.
		 * @param string $group Optional. Where the cache contents are grouped. Default empty.
		 *
		 * @return bool[] Array of return values, grouped by key. Each value is either
		 *                true on success, or false if the contents were not deleted.
		 * @since 6.0.0
		 */
		public function delete_multiple( array $keys, $group = '' ) {
			$values = [];

			foreach ( $keys as $key ) {
				$values[ $key ] = $this->delete( $key, $group );
			}

			return $values;
		}

		/**
		 * Removes the contents of the cache key in the group.
		 *
		 * If the cache key does not exist in the group, then nothing will happen.
		 *
		 * @param int|string $key What the contents in the cache are called.
		 * @param string     $group Optional. Where the cache contents are grouped. Default 'default'.
		 * @param bool       $deprecated Optional. Unused. Default false.
		 *
		 * @return bool True on success, false if the contents were not deleted.
		 * @throws SQLite_Object_Cache_Exception Announce database failure
		 * @since 2.0.0
		 *
		 */
		public function delete( $key, $group = 'default', $deprecated = false ) {
			if ( ! $this->is_valid_key( $key ) ) {
				return false;
			}

			if ( empty( $group ) ) {
				$group = 'default';
			}

			if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
				$key = $this->blog_prefix . $key;
			}

			if ( ! $this->cache_item_exists( $key, $group ) ) {
				return false;
			}

			unset( $this->cache[ $group ][ $key ] );
			$this->handle_delete( $key, $group );

			return true;
		}

		/**
		 * Delete from the persistent cache.
		 *
		 * @param int|string $key What to call the contents in the cache.
		 * @param string     $group Optional. Where to group the cache contents. Default 'default'.
		 *
		 * @return void
		 * @throws SQLite_Object_Cache_Exception Announce database failure
		 */
		private function handle_delete( $key, $group ) {
			if ( ! $this->sqlite ) {
				$this->open_connection();
			}
			$name  = $this->name_from_key_group( $key, $group );
			$start = $this->time_usec();
			$stmt  = $this->deleteone;
			$stmt->bindValue( ':name', $name, SQLITE3_TEXT );
			$result = $stmt->execute();
			if ( false === $result ) {
				$this->delete_offending_files( 0 );
				throw new SQLite_Object_Cache_Exception( 'delete: ' . $name, $this->sqlite, $start );
			}
			$result->finalize();
			unset( $this->in_persistent_cache[ $name ] );
			$this->not_in_persistent_cache[ $name ] = true;
			/* track how long it took. */
			$this->delete_times[] = $this->time_usec() - $start;
		}

		/**
		 * Increments numeric cache item's value.
		 *
		 * @param int|string $key The cache key to increment.
		 * @param int        $offset Optional. The amount by which to increment the item's value.
		 *                           Default 1.
		 * @param string     $group Optional. The group the key is in. Default 'default'.
		 *
		 * @return int|false The item's new value on success, false on failure.
		 * @since 3.3.0
		 */
		public function incr( $key, $offset = 1, $group = 'default' ) {
			if ( ! $this->is_valid_key( $key ) ) {
				return false;
			}

			if ( empty( $group ) ) {
				$group = 'default';
			}

			if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
				$key = $this->blog_prefix . $key;
			}

			if ( ! $this->cache_item_exists( $key, $group ) ) {
				return false;
			}

			if ( ! is_numeric( $this->cache[ $group ][ $key ] ) ) {
				$this->cache[ $group ][ $key ] = 0;
			}

			$offset = (int) $offset;

			$this->cache[ $group ][ $key ] += $offset;

			if ( $this->cache[ $group ][ $key ] < 0 ) {
				$this->cache[ $group ][ $key ] = 0;
			}
			$this->handle_put( $key, $group, $this->cache[ $group ][ $key ], 0 );

			return $this->cache[ $group ][ $key ];
		}

		/**
		 * Decrements numeric cache item's value.
		 *
		 * @param int|string $key The cache key to decrement.
		 * @param int        $offset Optional. The amount by which to decrement the item's value.
		 *                           Default 1.
		 * @param string     $group Optional. The group the key is in. Default 'default'.
		 *
		 * @return int|false The item's new value on success, false on failure.
		 * @since 3.3.0
		 *
		 */
		public function decr( $key, $offset = 1, $group = 'default' ) {
			if ( ! $this->is_valid_key( $key ) ) {
				return false;
			}

			if ( empty( $group ) ) {
				$group = 'default';
			}

			if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
				$key = $this->blog_prefix . $key;
			}

			if ( ! $this->cache_item_exists( $key, $group ) ) {
				return false;
			}

			if ( ! is_numeric( $this->cache[ $group ][ $key ] ) ) {
				$this->cache[ $group ][ $key ] = 0;
			}

			$offset = (int) $offset;

			$this->cache[ $group ][ $key ] -= $offset;

			if ( $this->cache[ $group ][ $key ] < 0 ) {
				$this->cache[ $group ][ $key ] = 0;
			}

			$this->handle_put( $key, $group, $this->cache[ $group ][ $key ], 0 );

			return $this->cache[ $group ][ $key ];
		}

		/**
		 * Clears the object cache of all data.
		 *
		 * @param bool $keep_performance_data True to retain performance data.
		 * @param bool $vacuum True to do a VACUUM operation.
		 *
		 * @return bool Always returns true.
		 * @since 2.0.0
		 */
		public function flush( $vacuum = false ) {
			/* NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3 */
			try {
				if ( ! $this->sqlite ) {
					$this->open_connection();
				}

				$this->cache                   = [];
				$this->not_in_persistent_cache = [];

				$selective =
					defined( 'WP_SQLITE_OBJECT_CACHE_SELECTIVE_FLUSH' ) ? WP_SQLITE_OBJECT_CACHE_SELECTIVE_FLUSH : null;

				$clauses    = [];
				$clauses [] = "(name <> 'sqlite_object_cache|created')";
				if ( $selective && is_array( $this->unflushable_groups ) ) {
					foreach ( $this->unflushable_groups as $unflushable_group ) {
						$unflushable_group = sanitize_key( $unflushable_group );
						$clauses []        = "(name NOT LIKE '$unflushable_group|%')";
					}
				}
				/* @noinspection SqlConstantCondition, SqlConstantExpression */
				$sql =
					'DELETE FROM ' . $this->cache_table_name . ' WHERE 1=1 AND ' . implode( ' AND ', $clauses ) . ';';
				$this->exec( $sql );

				if ( $vacuum ) {
					$this->exec( 'VACUUM;' );
				}
			} catch ( Exception $ex ) {
				error_log( $ex );
				$this->delete_offending_files( 0 );
			}

			return true;
		}

		/**
		 * Removes all cache items in a group.
		 *
		 * @param string $group Name of group to remove from cache.
		 *
		 * @return true Always returns true.
		 * @since 6.1.0
		 */
		public function flush_group( $group ) {
			if ( ! $this->sqlite ) {
				$this->open_connection();
			}

			$start = $this->time_usec();
			unset( $this->cache[ $group ] );
			$stmt = $this->deletegroup;
			$stmt->bindValue( ':group', $group, SQLITE3_TEXT );
			$result = $stmt->execute();
			if ( false === $result ) {
				$this->delete_offending_files( 0 );
				error_log( new SQLite_Object_Cache_Exception( 'deletegroup', $this->sqlite, $start ) );
			}

			/* remove hints about what is in the persistent cache */
			$this->not_in_persistent_cache = [];
			$this->in_persistent_cache     = [];

			return true;
		}

		/**
		 * Sets the list of groups not to flushed cached.
		 *
		 * @param array $groups List of groups that are unflushable.
		 */
		public function add_unflushable_groups( $groups ) {
			$groups = (array) $groups;

			$this->unflushable_groups = array_unique( array_merge( $this->unflushable_groups, $groups ) );
			$this->cache_group_types();
		}

		/**
		 * Sets the list of global cache groups.
		 *
		 * @param string|string[] $groups List of groups that are global.
		 *
		 * @since 3.0.0
		 */
		public function add_global_groups( $groups ) {
			$groups = (array) $groups;

			$groups              = array_fill_keys( $groups, true );
			$this->global_groups = array_merge( $this->global_groups, $groups );

			$this->cache_group_types();
		}

		/**
		 * Switches the internal blog ID.
		 *
		 * This changes the blog ID used to create keys in blog specific groups.
		 *
		 * @param int $blog_id Blog ID.
		 *
		 * @since 3.5.0
		 *
		 */
		public function switch_to_blog( $blog_id ) {
			$blog_id           = (int) $blog_id;
			$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
		}

		/**
		 * Resets cache keys.
		 *
		 * @since 3.0.0
		 *
		 * @deprecated 3.5.0 Use WP_Object_Cache::switch_to_blog()
		 * @see switch_to_blog()
		 */
		public function reset() {
			_deprecated_function( __FUNCTION__, '3.5.0', 'WP_Object_Cache::switch_to_blog()' );

			// Clear out non-global caches since the blog ID has changed.
			foreach ( array_keys( $this->cache ) as $group ) {
				if ( ! isset( $this->global_groups[ $group ] ) ) {
					unset( $this->cache[ $group ] );
				}
			}
		}

		/**
		 * Echoes the stats of the caching.
		 *
		 * Gives the cache hits, and cache misses. Also prints every cached group,
		 * key and the data.
		 *
		 * @since 2.0.0
		 */
		public function stats() {
			echo '<p><strong>Cache Hits:</strong> ' . esc_html( $this->cache_hits ) . '<br />';
			echo '<strong>Cache Misses:</strong> ' . esc_html( $this->cache_misses ) . '<br /></p>' . PHP_EOL;
			echo '<ul>';
			foreach ( $this->cache as $group => $cache ) {
				$length = number_format( strlen( $this->maybe_serialize( $cache ) ) / KB_IN_BYTES, 1 );
				$item   = $group . ' - ( ' . $length . 'KiB )';
				echo '<li><strong>Group:</strong> ' . esc_html( $item ) . '</li>';
			}
			echo '</ul>';
		}

		/**
		 * Checks if the given group is part the ignored group array
		 *
		 * @param string $group Name of the group to check, pre-sanitized.
		 *
		 * @return bool
		 */
		protected function is_ignored_group( $group ) {
			return $this->is_group_of_type( $group, 'ignored' );
		}

		/**
		 * Checks the type of the given group
		 *
		 * @param string $group Name of the group to check, pre-sanitized.
		 * @param string $type Type of the group to check.
		 *
		 * @return bool
		 */
		private function is_group_of_type( $group, $type ) {
			return isset( $this->group_type[ $group ] ) && $this->group_type[ $group ] === $type;
		}

		/**
		 * Checks if the given group is part the global group array
		 *
		 * @param string $group Name of the group to check, pre-sanitized.
		 *
		 * @return bool
		 */
		protected function is_global_group( $group ) {
			return $this->is_group_of_type( $group, 'global' );
		}

		/**
		 * Delete sqlite files in hopes of recovering from trouble.
		 *
		 * @param int $retries
		 *
		 * @return void
		 */
		private function delete_offending_files( $retries = 0 ) {
			error_log( "sqlite_object_cache connection failure, deleting sqlite files to retry. $retries" );
			require_once ABSPATH . 'wp-admin/includes/file.php';
			ob_start();
			$credentials = request_filesystem_credentials( '' );
			WP_Filesystem( $credentials );
			global $wp_filesystem;
			foreach ( [ '', '-shm', '-wal' ] as $suffix ) {
				$wp_filesystem->delete( $this->sqlite_path . $suffix );
			}
			ob_end_clean();
		}
	}

	/**
	 * Object Cache API
	 *
	 * @link https://developer.wordpress.org/reference/classes/wp_object_cache/
	 *
	 * @package WordPress
	 * @subpackage Cache
	 */

	/**
	 * Sets up Object Cache Global and assigns it.
	 *
	 * @throws RuntimeException If we cannot write the db file into the specified directory.
	 * @since 2.0.0
	 *
	 * @global WP_Object_Cache $wp_object_cache
	 */
	function wp_cache_init() {
		$message = WP_Object_Cache::has_sqlite();
		if ( true === $message ) {
			// We need to override this WordPress global in order to inject our cache.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
		} else {
			throw new \RuntimeException( $message );
		}
	}

	/**
	 * Adds data to the cache, if the cache key doesn't already exist.
	 *
	 * @param int|string       $key The cache key to use for retrieval later.
	 * @param mixed            $data The data to add to the cache.
	 * @param string           $group Optional. The group to add the cache to. Enables the same key
	 *                           to be used across groups. Default empty.
	 * @param int              $expire Optional. When the cache data should expire, in seconds.
	 *                           Default 0 (no expiration).
	 *
	 * @return bool True on success, false if cache key and group already exist.
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 2.0.0
	 *
	 * @see WP_Object_Cache::add()
	 */
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->add( $key, $data, $group, (int) $expire );
	}

	/**
	 * Adds multiple values to the cache in one call.
	 *
	 * @param array            $data Array of keys and values to be set.
	 * @param string           $group Optional. Where the cache contents are grouped. Default empty.
	 * @param int              $expire Optional. When to expire the cache contents, in seconds.
	 *                       Default 0 (no expiration).
	 *
	 * @return bool[] Array of return values, grouped by key. Each value is either
	 *                true on success, or false if cache key and group already exist.
	 * @see WP_Object_Cache::add_multiple()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 6.0.0
	 */
	function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->add_multiple( $data, $group, $expire );
	}

	/**
	 * Replaces the contents of the cache with new data.
	 *
	 * @param int|string       $key The key for the cache data that should be replaced.
	 * @param mixed            $data The new data to store in the cache.
	 * @param string           $group Optional. The group for the cache data that should be replaced.
	 *                           Default empty.
	 * @param int              $expire Optional. When to expire the cache contents, in seconds.
	 *                           Default 0 (no expiration).
	 *
	 * @return bool True if contents were replaced, false if original value does not exist.
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 2.0.0
	 *
	 * @see WP_Object_Cache::replace()
	 */
	function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
	}

	/**
	 * Saves the data to the cache.
	 *
	 * Differs from wp_cache_add() and wp_cache_replace() in that it will always write data.
	 *
	 * @param int|string       $key The cache key to use for retrieval later.
	 * @param mixed            $data The contents to store in the cache.
	 * @param string           $group Optional. Where to group the cache contents. Enables the same key
	 *                           to be used across groups. Default empty.
	 * @param int              $expire Optional. When to expire the cache contents, in seconds.
	 *                           Default 0 (no expiration).
	 *
	 * @return bool True on success, false on failure.
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 2.0.0
	 *
	 * @see WP_Object_Cache::set()
	 */
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Sets multiple values to the cache in one call.
	 *
	 * @param array            $data Array of keys and values to be set.
	 * @param string           $group Optional. Where the cache contents are grouped. Default empty.
	 * @param int              $expire Optional. When to expire the cache contents, in seconds.
	 *                            Default 0 (no expiration).
	 *
	 * @return bool[] Array of return values, grouped by key. Each value is either
	 *                true on success, or false on failure.
	 * @see WP_Object_Cache::set_multiple()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 6.0.0
	 */
	function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;

		return $wp_object_cache->set_multiple( $data, $group, $expire );
	}

	/**
	 * Retrieves the cache contents from the cache by key and group.
	 *
	 * @param int|string       $key The key under which the cache contents are stored.
	 * @param string           $group Optional. Where the cache contents are grouped. Default empty.
	 * @param bool             $force Optional. Whether to force an update of the local cache
	 *                          from the persistent cache. Default false.
	 * @param bool             $found Optional. Whether the key was found in the cache (passed by reference).
	 *                          Disambiguates a return of false, a storable value. Default null.
	 *
	 * @return mixed|false The cache contents on success, false on failure to retrieve contents.
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 2.0.0
	 *
	 * @see WP_Object_Cache::get()
	 */
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		global $wp_object_cache;

		return $wp_object_cache->get( $key, $group, $force, $found );
	}

	/**
	 * Retrieves multiple values from the cache in one call.
	 *
	 * @param array            $keys Array of keys under which the cache contents are stored.
	 * @param string           $group Optional. Where the cache contents are grouped. Default empty.
	 * @param bool             $force Optional. Whether to force an update of the local cache
	 *                      from the persistent cache. Default false.
	 *
	 * @return array Array of return values, grouped by key. Each value is either
	 *               the cache contents on success, or false on failure.
	 * @see WP_Object_Cache::get_multiple()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 5.5.0
	 */
	function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
		global $wp_object_cache;

		return $wp_object_cache->get_multiple( $keys, $group, $force );
	}

	/**
	 * Removes the cache contents matching key and group.
	 *
	 * @param int|string       $key What the contents in the cache are called.
	 * @param string           $group Optional. Where the cache contents are grouped. Default empty.
	 *
	 * @return bool True on successful removal, false on failure.
	 * @since 2.0.0
	 *
	 * @see WP_Object_Cache::delete()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 */
	function wp_cache_delete( $key, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->delete( $key, $group );
	}

	/**
	 * Deletes multiple values from the cache in one call.
	 *
	 * @param array            $keys Array of keys for deletion.
	 * @param string           $group Optional. Where the cache contents are grouped. Default empty.
	 *
	 * @return bool[] Array of return values, grouped by key. Each value is either
	 *                true on success, or false if the contents were not deleted.
	 * @since 6.0.0
	 *
	 * @see WP_Object_Cache::delete_multiple()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 */
	function wp_cache_delete_multiple( array $keys, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->delete_multiple( $keys, $group );
	}

	/**
	 * Increments numeric cache item's value.
	 *
	 * @param int|string       $key The key for the cache contents that should be incremented.
	 * @param int              $offset Optional. The amount by which to increment the item's value.
	 *                           Default 1.
	 * @param string           $group Optional. The group the key is in. Default empty.
	 *
	 * @return int|false The item's new value on success, false on failure.
	 * @see WP_Object_Cache::incr()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 3.3.0
	 */
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->incr( $key, $offset, $group );
	}

	/**
	 * Decrements numeric cache item's value.
	 *
	 * @param int|string       $key The cache key to decrement.
	 * @param int              $offset Optional. The amount by which to decrement the item's value.
	 *                           Default 1.
	 * @param string           $group Optional. The group the key is in. Default empty.
	 *
	 * @return int|false The item's new value on success, false on failure.
	 * @see WP_Object_Cache::decr()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 3.3.0
	 */
	function wp_cache_decr( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->decr( $key, $offset, $group );
	}

	/**
	 * Removes all cache items.
	 *
	 * @return bool True on success, false on failure.
	 * @see WP_Object_Cache::flush()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 2.0.0
	 *
	 */
	function wp_cache_flush() {
		global $wp_object_cache;

		return $wp_object_cache->flush();
	}

	/**
	 * Removes all cache items from the in-memory runtime cache.
	 *
	 * @return bool True on success, false on failure.
	 * @see WP_Object_Cache::flush()
	 *
	 * @since 6.0.0
	 *
	 */
	function wp_cache_flush_runtime() {
		return wp_cache_flush();
	}

	/**
	 * Removes all cache items in a group, if the object cache implementation supports it.
	 *
	 * Before calling this function, always check for group flushing support using the
	 * `wp_cache_supports( 'flush_group' )` function.
	 *
	 * @param string           $group Name of group to remove from cache.
	 *
	 * @return bool True if group was flushed, false otherwise.
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 6.1.0
	 *
	 * @see WP_Object_Cache::flush_group()
	 */
	function wp_cache_flush_group( $group ) {
		global $wp_object_cache;

		return $wp_object_cache->flush_group( $group );
	}

	/**
	 * Determines whether the object cache implementation supports a particular feature.
	 *
	 * @param string $feature Name of the feature to check for. Possible values include:
	 *                        'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple',
	 *                        'flush_runtime', 'flush_group'.
	 *
	 * @return bool True if the feature is supported, false otherwise.
	 * @since 6.1.0
	 */
	function wp_cache_supports( $feature ) {
		switch ( $feature ) {
			case 'add_multiple':
			case 'set_multiple':
			case 'get_multiple':
			case 'delete_multiple':
			case 'flush_runtime':
			case 'flush_group':
				return true;

			default:
				return false;
		}
	}

	/**
	 * Closes the cache.
	 *
	 * This function has ceased to do anything since WordPress 2.5. The
	 * functionality was removed along with the rest of the persistent cache.
	 *
	 * This does not mean that plugins can't implement this function when they need
	 * to make sure that the cache is cleaned up after WordPress no longer needs it.
	 *
	 * @return true Always returns true.
	 * @since 2.0.0
	 */
	function wp_cache_close() {
		global $wp_object_cache;

		return $wp_object_cache->close();
	}

	/**
	 * Adds a group or set of groups to the list of global groups.
	 *
	 * @param string|string[]  $groups A group or an array of groups to add.
	 *
	 * @see WP_Object_Cache::add_global_groups()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 2.6.0
	 */
	function wp_cache_add_global_groups( $groups ) {
		global $wp_object_cache;

		$wp_object_cache->add_global_groups( $groups );
	}

	/**
	 * Adds a group or set of groups to the list of non-persistent groups.
	 *
	 * @param string|string[] $groups A group or an array of groups to add.
	 *
	 * @since 2.6.0
	 */
	function wp_cache_add_non_persistent_groups( $groups ) {

		global $wp_object_cache;

		$wp_object_cache->add_non_persistent_groups( $groups );
	}

	/**
	 * Switches the internal blog ID.
	 *
	 * This changes the blog id used to create keys in blog specific groups.
	 *
	 * @param int              $blog_id Site ID.
	 *
	 * @see WP_Object_Cache::switch_to_blog()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @since 3.5.0
	 */
	function wp_cache_switch_to_blog( $blog_id ) {
		global $wp_object_cache;

		$wp_object_cache->switch_to_blog( $blog_id );
	}

	/**
	 * Resets internal cache keys and structures.
	 *
	 * If the cache back end uses global blog or site IDs as part of its cache keys,
	 * this function instructs the back end to reset those keys and perform any cleanup
	 * since blog or site IDs have changed since cache init.
	 *
	 * This function is deprecated. Use wp_cache_switch_to_blog() instead of this
	 * function when preparing the cache for a blog switch. For clearing the cache
	 * during unit tests, consider using wp_cache_init(). wp_cache_init() is not
	 * recommended outside unit tests as the performance penalty for using it is high.
	 *
	 * @since 3.0.0
	 * @deprecated 3.5.0 Use wp_cache_switch_to_blog()
	 * @see WP_Object_Cache::reset()
	 *
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 */
	function wp_cache_reset() {
		_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' );

		global $wp_object_cache;

		$wp_object_cache->reset();
	}
endif;
// phpcs:enable Generic.WhiteSpace.ScopeIndent.IncorrectExact, Generic.WhiteSpace.ScopeIndent.Incorrect
