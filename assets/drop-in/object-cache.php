<?php
/**
 * Plugin Name: SQLite Object Cache Drop-In
 * Plugin URI: https://wordpress.org/plugins/sqlite-object-cache/
 * Description: A persistent object cache backend powered by SQLite. Supports SQLite3 and WP-CLI.
 * Author:  Ollie Jones
 * Author URI: https://plumislandmedia.net
 * License: GPLv2+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 5.6
 *
 * Credit: Till Krüss's https://wordpress.org/plugins/redis-cache/ plugin. Thanks, Till!
 *
 * @package SQLiteCache
 */

defined( '\\ABSPATH' ) || exit;

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
 * Object Cache stores all of the cache data to memory and makes the cache
 * contents available by using a key, which is used to name and later retrieve
 * the cache contents.
 *
 * The Object Cache can be replaced by other caching mechanisms by placing files
 * in the wp-content folder which is looked at in wp-settings. If that file
 * exists, then this file will not be included.
 *
 * @since 2.0.0
 */
#[AllowDynamicProperties]
class WP_Object_Cache extends SQLite3 {

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
	 * List of global cache groups.
	 *
	 * @since 3.0.0
	 * @var string[]
	 */
	protected $global_groups = [];
	/**
	 * Holds the cached objects.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private $cache = [];
	/**
	 * The blog prefix to prepend to keys in non-global groups.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	private $blog_prefix;

	/**
	 * Holds the value of is_multisite().
	 *
	 * @since 3.5.0
	 * @var bool
	 */
	private $multisite;

	/**
	 * An associative array of statements.
	 *
	 * @var array Statements.
	 */
	private $statements;

	/**
	 * A queue of put operations
	 *
	 * @var array Put ops.
	 */
	private $put_queue = [];

	/**
	 * Associative array of items we know aren't in SQLite.
	 *
	 * @var array Keys are names, values don't matter.
	 */
	private $not_in_persistent_cache = [];

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
	 * Flag for availability of nanosecond resolution time.
	 *
	 * @var bool true if it is available.
	 */
	private $has_hrtime;

	/**
	 * The expiration time of non-expiring cache entries has this added to the timestamp.
	 *
	 * @var int a large number of seconds.
	 */
	private $noexpire_timestamp_offset;

	/**
	 * The maximum age of an entry before we get rid of it.
	 *
	 * @var int the maximum lifetime of a cache entry, often a week.
	 */
	private $max_lifetime;

	/**
	 * Sets up object properties; PHP 5 style constructor.
	 *
	 * @param string $directory Name of the directory for the db file.
	 * @param string $file Name of the db file.
	 * @param int    $timeout Milliseconds before timeout. Do not set to zero.
	 *
	 * @throws Exception Database failure.
	 * @since 2.0.8
	 */
	public function __construct( $directory = WP_CONTENT_DIR, $file = 'sqlite-object-cache.db', $timeout = 500 ) {
		$this->multisite   = is_multisite();
		$this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';
		$filepath          = $directory . '/' . $file;
		parent::__construct( $filepath );
		$this->busyTimeout( $timeout );
		$this->has_igbinary = function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' );
		$this->has_hrtime   = function_exists( 'hrtime' );

		$this->initialize_connection();
	}

	/** Initialize the SQLite database system.
	 *
	 * @param string $tbl The name of the cache table in the database.
	 * @param int    $noexpire_timestamp_offset The offset number to add to time() for non-expiring items.
	 *
	 * @return void
	 * @throws Exception Database failure.
	 */
	private function initialize_connection( $tbl = 'object_cache', $noexpire_timestamp_offset = 500000000000, $max_lifetime = WEEK_IN_SECONDS ) {
		$this->cache_table_name          = $tbl;
		$this->noexpire_timestamp_offset = $noexpire_timestamp_offset;
		$this->max_lifetime              = $max_lifetime;
		/* set some initial pragma stuff */
		$this->exec( 'PRAGMA synchronous = OFF' );
		$this->exec( "PRAGMA encoding = 'UTF-8'" );
		$this->exec( 'PRAGMA case_sensitive_like = true' );
		// TODO detect corruption and rebuild the cache db.
		$this->exec( 'PRAGMA journal_mode = MEMORY' );

		$this->do_ddl( $tbl, $noexpire_timestamp_offset );
		$this->do_statements( $tbl, $noexpire_timestamp_offset );
	}

	/**
	 * Wrapper for exec, to check for errors.
	 *
	 * @param string $query The SQL Statement.
	 *
	 * @return bool
	 * @throws Exception If something failed.
	 */
	public function exec( $query ) {
		$result = parent::exec( $query );
		if ( ! $result ) {
			throw new Exception( 'SQLite3 exec: ' . $this->lastErrorMsg(), $this->lastErrorCode() );
		}

		return true;
	}

	/**
	 * Do the necessary Data Definition Language work.
	 *
	 * @param string $tbl The name of the table.
	 * @param int    $noexpire_timestamp_offset Time offset for objects without an expiration.
	 *
	 * @return void
	 * @throws Exception If something fails.
	 */
	private function do_ddl( $tbl, $noexpire_timestamp_offset ) {
		$now = time();
		try {
			$this->exec( 'BEGIN;' );
			/* does our table exist?  */
			$q = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND tbl_name = '$tbl';";
			$r = $this->querySingle( $q );
			if ( 0 === $r ) {
				/* @noinspection SqlIdentifier */
				$t = "
CREATE TABLE IF NOT EXISTS $tbl (
   name TEXT NOT NULL COLLATE BINARY PRIMARY KEY,
   value BLOB,
   expires INT
) WITHOUT ROWID;
CREATE INDEX IF NOT EXISTS expires ON $tbl (expires);";
				$this->exec( $t );
				$this->exec( "INSERT INTO $tbl (name, value, expires) VALUES ('sqlite_object_cache.created', datetime(), $now + $noexpire_timestamp_offset);" );
			}
		} finally {
			$this->exec( 'COMMIT;' );
		}
	}

	/**
	 * Wrapper for querySingle, to detect if something failed.
	 *
	 * @param string $query The query.
	 * @param bool   $entire_row If false, give back the first column.
	 *
	 * @return mixed
	 * @throws Exception Announce database failure.
	 */
	public function querySingle( $query, $entire_row = false ) {
		$result = parent::querySingle( $query, $entire_row );
		if ( false === $result ) {
			throw new Exception( 'SQLite3 querySingle: ' . $this->lastErrorMsg(), $this->lastErrorCode() );
		}

		return $result;
	}

	/**
	 * Create the prepared statements to use.
	 *
	 * @param string $tbl Table name.
	 * @param int    $noexpire_timestamp_offset Time offset for objects without an expiration.
	 *
	 * @return void
	 * @throws Exception Announce failure.
	 */
	private function do_statements( $tbl, $noexpire_timestamp_offset ) {
		/* Some versions of SQLite3 built into php predate the 3.38 advent of unixepoch() (2022-02-22). */
		$now         = time();
		$putone      = "
INSERT INTO $tbl (name, value, expires) 
VALUES (:name, :value, $now + :expires)
ON CONFLICT(name)
DO UPDATE SET value=excluded.value, expires=excluded.expires";
		$getone      = "SELECT value FROM $tbl WHERE name = :name AND expires >= $now;";
		$deleteone   = "DELETE FROM $tbl WHERE name = :name;";
		$deletegroup = "DELETE FROM $tbl WHERE name LIKE :group || '.%';";

		/* prepare the statements for later use */
		$this->statements = [
			'putone'      => $this->prepare( $putone ),
			'getone'      => $this->prepare( $getone ),
			'deleteone'   => $this->prepare( $deleteone ),
			'deletegroup' => $this->prepare( $deletegroup ),
		];
	}

	/**
	 * Wrapper around prepare()
	 *
	 * @param string $query Statement to prepare.
	 *
	 * @return SQLite3Stmt
	 * @throws Exception Announce failure.
	 */
	public function prepare( $query ) {
		$result = parent::prepare( $query );
		if ( false === $result ) {
			throw new Exception( 'SQLite3 prepare: ' . $this->lastErrorMsg(), $this->lastErrorCode() );
		}

		return $result;
	}

	/**
	 * Determine whether we can use SQLite3.
	 *
	 * @param string $directory The directory to hold the .db file. Default WP_CONTENT_DIR.
	 *
	 * @return bool|string true, or an error message.
	 */
	public static function has_sqlite( $directory = WP_CONTENT_DIR ) {
		if ( ! wp_is_writable( $directory ) ) {
			return sprintf( /* translators: 1: WP_CONTENT_DIR */ __( 'The SQLite Object Cache cannot be activated because the %s directory is not writable.', 'sqlite-object-cache' ), $directory );
		}

		if ( ! extension_loaded( 'sqlite3' ) || ! class_exists( 'SQLite3' ) ) {
			return __( 'The SQLite Object Cache cannot be activated because the SQLite extension is not loaded.', 'sqlite-object-cache' );
		}

		return true;
	}

	/**
	 * Write out the accumulated puts in one transaction, then close the connection.
	 *
	 * @return bool
	 * @throws Exception Announce failure.
	 */
	public function close() {

		if ( count( $this->put_queue ) > 0 ) {
			try {
				$this->exec( 'BEGIN;' );
				foreach ( $this->put_queue as $put_item ) {
					switch ( $put_item[0] ) {
						case 'putone':
							$this->putone( $put_item[1] );
							break;

						case 'deleteone':
							$this->deleteone( $put_item[1] );
							break;

						default:
							throw new Exception( 'SQLite3 put_queue: unknown item ' . $put_item[0] );
					}
				}
				$this->maybe_clean_up_cache();
			} finally {
				$this->exec( 'COMMIT;' );
			}
		}

		return parent::close();
	}

	/**
	 * Remove old entries and VACUUM the database, one time in many.
	 *
	 * @param int $inverse_probability Do the job one time in this many.
	 *
	 * @return void
	 * @throws Exception Announce database failure.
	 */
	private function maybe_clean_up_cache( $inverse_probability = 1000 ) {
		$random = wp_rand( 1, $inverse_probability );
		if ( 1 !== $random ) {
			return;
		}
		$sql  = "DELETE FROM $this->cache_table_name WHERE expires <= :now;";
		$stmt = $this->prepare( $sql );
		$stmt->bindValue( ':now', time(), SQLITE3_INTEGER );
		$result = $stmt->execute();
		if ( false !== $result ) {
			$result->finalize();
		}
		$sql    = "DELETE FROM $this->cache_table_name WHERE expires BETWEEN :offset AND :end;";
		$stmt   = $this->prepare( $sql );
		$offset = $this->noexpire_timestamp_offset;
		$end    = time() + $offset - $this->max_lifetime;
		$stmt->bindValue( ':offset', $offset, SQLITE3_INTEGER );
		$stmt->bindValue( ':end', $end, SQLITE3_INTEGER );
		$result = $stmt->execute();
		if ( false !== $result ) {
			$result->finalize();
		}
		$this->exec( 'VACUUM' );
	}

	/** Put one item into the persistent cache.
	 *
	 * @param array $item The item, [$key, $data, $group, $expire].
	 *
	 * @return void
	 * @throws Exception Announce database failure.
	 */
	private function putone( $item ) {
		$name    = $this->getName( $item[0], $item[2] );
		$value   = $this->maybe_serialize( $item[1] );
		$expires = $item[3] ?: 500000000000;
		$stmt    = $this->statements['putone'];
		$stmt->bindValue( ':name', $name, SQLITE3_TEXT );
		$stmt->bindValue( ':value', $value, SQLITE3_BLOB );
		$stmt->bindValue( ':expires', $expires, SQLITE3_INTEGER );
		$result = $stmt->execute();
		if ( false === $result ) {
			throw new Exception( 'SQLite3 putone: ' . $this->lastErrorMsg(), $this->lastErrorCode() );
		}
		$result->finalize();
	}

	/**
	 * Generate canonical name for cache item
	 *
	 * @param string $key The key name.
	 * @param string $group The group name.
	 *
	 * @return string The name.
	 */
	private function getName( $key, $group ) {
		return str_replace( '.', '__', $group ) . '.' . str_replace( '.', '__', $key );
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

	/** Delete one item from the persistent cache.
	 *
	 * @param array $item The item, [$key, $group].
	 *
	 * @return void
	 * @throws Exception Announce database failure.
	 */
	private function deleteone( $item ) {
		$name = $this->getName( $item[0], $item[1] );
		$stmt = $this->statements['deleteone'];
		$stmt->bindValue( ':name', $name, SQLITE3_TEXT );
		$result = $stmt->execute();
		if ( false === $result ) {
			throw new Exception( 'SQLite3 putone: ' . $this->lastErrorMsg(), $this->lastErrorCode() );
		}
		$result->finalize();
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
	 * @throws Exception Announce database failure.
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
	 * @throws Exception Announce database failure.
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

		return $this->set( $id, $data, $group, (int) $expire );
	}

	/**
	 * Serves as a utility function to determine whether a key is valid.
	 *
	 * @param int|string $key Cache key to check for validity.
	 *
	 * @return bool Whether the key is valid.
	 * @since 6.1.0
	 *
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

		$message = is_string( $key ) ? __( 'Cache key must not be an empty string.' ) /* translators: %s: The type of the given cache key. */ : sprintf( __( 'Cache key must be integer or non-empty string, %s given.' ), $type );
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
	 * @throws Exception Announce database failure.
	 * @since 3.4.0
	 *
	 */
	protected function cache_item_exists( $key, $group ) {
		$exists = isset( $this->cache[ $group ] ) && ( isset( $this->cache[ $group ][ $key ] ) || array_key_exists( $key, $this->cache[ $group ] ) );
		if ( ! $exists ) {
			$val = $this->getone( $key, $group );
			if ( null !== $val ) {
				if ( ! array_key_exists( $group, $this->cache ) ) {
					$this->cache [ $group ] = [];
				}
				$this->cache[ $group ][ $key ] = $val;
				$exists                        = true;
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
	 * @throws Exception Announce database failure.
	 */
	private function getone( $key, $group ) {
		$name = $this->getName( $key, $group );
		if ( array_key_exists( $name, $this->not_in_persistent_cache ) ) {
			return null;
		}
		$stmt = $this->statements['getone'];
		$stmt->bindValue( ':name', $name, SQLITE3_TEXT );
		$result = $stmt->execute();
		if ( false === $result ) {
			$this->not_in_persistent_cache [ $name ] = true;
			throw new Exception( 'SQLite3 stmt->execute: ' . $this->lastErrorMsg(), $this->lastErrorCode() );
		}
		$row  = $result->fetchArray( SQLITE3_NUM );
		$data = false !== $row && is_array( $row ) && 1 === count( $row ) ? $row[0] : null;
		if ( null !== $data ) {
			$data = $this->maybe_unserialize( $data );
		} else {
			$this->not_in_persistent_cache [ $name ] = true;
		}

		return $data;
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

		$former_data = array_key_exists( $group, $this->cache ) && array_key_exists( $key, $this->cache[ $group ] ) ? $this->cache [ $group ][ $key ] : null;

		$this->cache[ $group ][ $key ] = $data;
		if ( $former_data !== $data ) {
			/* enqueue for output */
			$this->enqueue_put( $key, $data, $group, $expire );
		}

		return true;
	}

	/**
	 * Enqueues data to write to the persistent cache.
	 *
	 * @param int|string $key What to call the contents in the cache.
	 * @param mixed      $data The contents to store in the cache.
	 * @param string     $group Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. Not used.
	 *
	 * @return void
	 */
	private function enqueue_put( $key, $data, $group, $expire ) {
		$this->put_queue [] = [ 'putone', [ $key, $data, $group, $expire ] ];
		unset( $this->not_in_persistent_cache[ $group . '.' . $key ] );
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
	 *
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
	 * @since 5.5.0
	 *
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
	 * @param bool       $force Optional. Unused. Whether to force an update of the local cache
	 *                          from the persistent cache. Default false.
	 * @param bool       $found Optional. Whether the key was found in the cache (passed by reference).
	 *                          Disambiguates a return of false, a storable value. Default null.
	 *
	 * @return mixed|false The cache contents on success, false on failure to retrieve contents.
	 * @since 2.0.0
	 *
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

		if ( $this->cache_item_exists( $key, $group ) ) {
			$found            = true;
			$this->cache_hits += 1;
			if ( is_object( $this->cache[ $group ][ $key ] ) ) {
				return clone $this->cache[ $group ][ $key ];
			} else {
				return $this->cache[ $group ][ $key ];
			}
		}

		$found              = false;
		$this->cache_misses += 1;

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
	 *
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
		$this->enqueue_delete( $key, $group );

		return true;
	}

	/**
	 * Enqueues data to delete from the persistent cache.
	 *
	 * @param int|string $key What to call the contents in the cache.
	 * @param string     $group Optional. Where to group the cache contents. Default 'default'.
	 *
	 * @return void
	 */
	private function enqueue_delete( $key, $group ) {
		$this->put_queue []                                   = [ 'deleteone', [ $key, $group ] ];
		$this->not_in_persistent_cache[ $group . '.' . $key ] = true;
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
	 *
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
		$this->enqueue_put( $key, $group, $this->cache[ $group ][ $key ], 0 );

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

		$this->enqueue_put( $key, $group, $this->cache[ $group ][ $key ], 0 );

		return $this->cache[ $group ][ $key ];
	}

	/**
	 * Clears the object cache of all data.
	 *
	 * @return true Always returns true.
	 * @throws Exception Announce database failure.
	 * @since 2.0.0
	 *
	 */
	public function flush() {
		$this->cache = [];
		/** @noinspection SqlWithoutWhere */
		$this->exec( 'DELETE FROM ' . $this->cache_table_name . ';' );
		$this->not_in_persistent_cache = [];

		return true;
	}

	/**
	 * Removes all cache items in a group.
	 *
	 * @param string $group Name of group to remove from cache.
	 *
	 * @return true Always returns true.
	 * @throws Exception Announce database failure.
	 * @since 6.1.0
	 *
	 */
	public function flush_group( $group ) {
		unset( $this->cache[ $group ] );

		$stmt = $this->statements ['deletegroup'];
		$stmt->bindValue( ':group', $group, SQLITE3_TEXT );
		$result = $stmt->execute();
		if ( false === $result ) {
			throw new Exception( 'SQLite3 stmt->execute: ' . $this->lastErrorMsg(), $this->lastErrorCode() );
		}

		return true;
	}

	/**
	 * Sets the list of global cache groups.
	 *
	 * @param string|string[] $groups List of groups that are global.
	 *
	 * @since 3.0.0
	 *
	 */
	public function add_global_groups( $groups ) {
		$groups = (array) $groups;

		$groups              = array_fill_keys( $groups, true );
		$this->global_groups = array_merge( $this->global_groups, $groups );
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
		echo '<p>';
		echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
		echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
		echo '</p>';
		echo '<ul>';
		foreach ( $this->cache as $group => $cache ) {
			echo '<li><strong>Group:</strong> ' . esc_html( $group ) . ' - ( ' . number_format( strlen( serialize( $cache ) ) / KB_IN_BYTES, 2 ) . 'k )</li>';
		}
		echo '</ul>';
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
 * @throws Exception If we cannot write the db file into the specified directory.
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
		throw new Exception( $message );
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
 *
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
 *                       Default 0 (no expiration).
 *
 * @return bool[] Array of return values, grouped by key. Each value is either
 *                true on success, or false on failure.
 * @see WP_Object_Cache::set_multiple()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @since 6.0.0
 *
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
 *
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
 * @param array            $keys Array of keys under which the cache to deleted.
 * @param string           $group Optional. Where the cache contents are grouped. Default empty.
 *
 * @return bool[] Array of return values, grouped by key. Each value is either
 *                true on success, or false if the contents were not deleted.
 * @since 6.0.0
 *
 * @see WP_Object_Cache::delete_multiple()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
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
 *
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
 *
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
 *
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
 *
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
 *
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
 *
 */
function wp_cache_add_non_persistent_groups( $groups ) {
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
 *
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
 * recommended outside of unit tests as the performance penalty for using it is high.
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
