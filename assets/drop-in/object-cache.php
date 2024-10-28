<?php
/**
 * Plugin Name: SQLite Object Cache (Drop-in)
 * Version: 1.3.8
 * Note: This Version number must match the one in SQLite_Object_Cache::_construct.
 * Plugin URI: https://wordpress.org/plugins/sqlite-object-cache/
 * Description: A persistent object cache backend powered by SQLite3.
 * Author:  Oliver Jones
 * Author URI: https://plumislandmedia.net
 * License: GPLv2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 5.6
 * Tested up to: 6.7
 * Stable tag: 1.3.8
 *
 * NOTE: This uses the file .../wp-content/.ht.object_cache.sqlite
 * and the associated files .../wp-content/.ht.object_cache.sqlite-shm
 * and .../wp-content/.ht.object_cache.sqlite-wal to hold cached data.
 * These start with .ht. for security: Many web servers block requests
 * for files with that prefix. Use the UNIX ls -a command to
 * see these files from your command line.
 *
 * Some config settings control this.
 * WP_SQLITE_OBJECT_CACHE_DB_FILE, if defined, is the cache file path.
 *      /var/tmp/cache.sqlite puts the cache file outside the document root.
 * WP_CACHE_KEY_SALT is used as part of the cache file.
 * WP_SQLITE_OBJECT_CACHE_TIMEOUT is the SQLite timeout in place of 5000 milliseconds.
 * WP_SQLITE_OBJECT_CACHE_JOURNAL_MODE is the SQLite journal mode in place of 'WAL'.
 *   It can be DELETE | TRUNCATE | PERSIST | MEMORY | WAL. See https://www.sqlite.org/pragma.html#pragma_journal_mode.
 * WP_SQLITE_OBJECT_CACHE_INTKEY_LENGTH is the number of digits for optimizing consecutive integer cache keys, default 6.
 * WP_SQLITE_OBJECT_CACHE_INTKEY_ERODE_GAPS allows fewer SQL statements but can retrieve extra items, default 2.
 * WP_SQLITE_OBJECT_CACHE_MMAP_SIZE sets SQLite's mmap_size in MiB. Default 0: disabled.
 *
 * Credit: Till Krüss's https://wordpress.org/plugins/redis-cache/ plugin. Thanks, Till!
 *
 * @package SQLiteCache
 */

defined( '\\ABSPATH' ) || exit;

// phpcs:disable Generic.WhiteSpace.ScopeIndent.IncorrectExact, Generic.WhiteSpace.ScopeIndent.Incorrect
if ( ! defined( 'WP_SQLITE_OBJECT_CACHE_DISABLED' ) || ! WP_SQLITE_OBJECT_CACHE_DISABLED ) :

  /**
   * Object Cache API: WP_Object_Cache class, reworked for SQLite3 drop-in.
   *
   * NOTE WELL: SQL in this file is not for use with $wpdb, but for SQLite3.
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
    const INTKEY_LENGTH = 6;
    const MMAP_SIZE = 0.0;
    const INTKEY_ERODE_GAPS = 2;
    const INTKEY_SENTINEL = "\x1f"; /* Only one character allowed here. */
    const SQLITE_TIMEOUT = 5000;
    const SQLITE_FILENAME = '.ht.object-cache.sqlite';
    const JOURNAL_MODE = 'WAL';  /* or 'MEMORY' */
    const TRANSACTION_SIZE_LIMIT = 64;

    /**
     * @var bool True if a transaction is active.
     */
    private $transaction_active = false;
    /**
     * Path to SQLite file.
     *
     * @var string
     */
    public $sqlite_path;

    /**
     * @var string|null Version of SQLite3 software in use.
     */
    private $sqlite_version;

    /**
     * SQLite's journal mode.
     *
     * Avoid the OFF journal mode, especially in pre-3.24 versions of SQLite.
     *
     * @see https://www.sqlite.org/pragma.html#pragma_journal_mode
     *
     * @var string  MEMORY, WAL, DELETE, TRUNCATE, PERSIST, OFF
     */
    private $sqlite_journal_mode;
    /**
     * Timeout waiting for transaction completion.
     *
     * @var int
     */
    private $sqlite_timeout;
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
     * @var string For multisite, n:, For single site, empty.
     */
    public $blog_prefix;
    /**
     * List of groups that will not be flushed.
     *
     * @var array
     */
    public $unflushable_groups = array();
    /**
     * List of groups not saved to cache.
     *
     * @var array
     */
    public $ignored_groups = array(
      'counts',
      'plugins',
      'themes',
    );
    /**
     * List of groups and their types.
     *
     * @var array
     */
    public $group_type = array();
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
    protected $global_groups = array(
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
    );

    /**
     * @var array One-level associative array $name=>$value
     */
    private $cache = array();
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
     * Prepared statement to get a range of cache elements, for get_multiple.
     *
     * @var SQLite3Stmt SELECT statement.
     */
    private $getrange;

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
     * When a name is not in this array it means we don't know if it is in SQLite or not.
     *
     * @var array Keys are cached item names. Values are true.
     */
    private $not_in_persistent_cache = array();
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
     * An array of elapsed times for each cache-retrieval operation.
     *
     * @var array[float]
     */
    private $select_times = array();
    /**
     * An array of elapsed times for each cache-insertion / update operation.
     *
     * @var array[float]
     */
    private $insert_times = array();
    /**
     * An array of elapsed times for each single-row cache deletion operation.
     *
     * @var array[float]
     */
    private $delete_times = array();
    /**
     * The times for individual get_multiple operations.
     *
     * @var array[float]
     */
    /**
     * The times for individual checkpoint -- PRAGMA wal_checkpoint(RESTART) -- times
     * @var array
     */
    private $checkpoint_times = array();
    private $get_multiple_times = array();
    /**
     * The times for individual get_multiple operations.
     *
     * @var array[int]
     */
    private $get_multiple_keys = array();
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
     * Recursion count.
     *
     * @var int Recursion in the get command.
     */
    private $get_depth = 31;
    /**
     * Database object.
     * @var SQLite3 instance.
     */
    private $sqlite;
    /**
     * @var int The max number of digits in optimized integer cache keys.
     *
     * Longer integers than this are treated as text.
     */
    private $intkey_length;
    /**
     * @var int The maximum value of integer keys before we handle them as strings.
     *
     * Longer integers than this are treated as text.
     */
    private $intkey_max;
    /**
     * @var int Erode gaps in consecutive runs of integers by this amount.
     *
     * This makes for fewer SQL queries at the cost of some extra retrieved items.
     */
    private $erode_gaps;

    /**
     * @var int mmap_size setting for SQLite. Zero to disable.
     */
    private $mmap_size = 0;

    /**
     * Constructor for SQLite Object Cache.
     *
     * @since 2.0.8
     */
    public function __construct() {

      $this->cache_group_types();

      $this->has_hrtime   = function_exists( 'hrtime' );
      $this->has_igbinary = function_exists( 'igbinary_serialize' );

      $this->sqlite_path = $this->create_database_path();

      $this->sqlite_timeout = defined( 'WP_SQLITE_OBJECT_CACHE_TIMEOUT' )
        ? WP_SQLITE_OBJECT_CACHE_TIMEOUT
        : self::SQLITE_TIMEOUT;

      $this->sqlite_journal_mode = defined( 'WP_SQLITE_OBJECT_CACHE_JOURNAL_MODE' )
        ? WP_SQLITE_OBJECT_CACHE_JOURNAL_MODE
        : self::JOURNAL_MODE;

      $this->erode_gaps = defined( 'WP_SQLITE_OBJECT_CACHE_INTKEY_ERODE_GAPS' )
        ? (int) WP_SQLITE_OBJECT_CACHE_INTKEY_ERODE_GAPS
        : self::INTKEY_ERODE_GAPS;

      $this->intkey_length = defined( 'WP_SQLITE_OBJECT_CACHE_INTKEY_LENGTH' )
        ? (int) WP_SQLITE_OBJECT_CACHE_INTKEY_LENGTH
        : self::INTKEY_LENGTH;

      $this->intkey_max = - 1 + (int) str_pad( '1', 1 + $this->intkey_length, 0, STR_PAD_RIGHT );

      $this->mmap_size = defined( 'WP_SQLITE_OBJECT_CACHE_MMAP_SIZE' )
        ? (int) WP_SQLITE_OBJECT_CACHE_MMAP_SIZE
        : self::MMAP_SIZE;
      $this->mmap_size = (int) $this->mmap_size * 1024 * 1024;

      $this->multisite                 = is_multisite();
      $this->blog_prefix               = $this->multisite ? get_current_blog_id() . ':' : '';
      $this->cache_table_name          = self::OBJECT_CACHE_TABLE;
      $this->noexpire_timestamp_offset = self::NOEXPIRE_TIMESTAMP_OFFSET;
      $this->open_connection();
    }

    /**
     * Load translations early if necessary and possible.
     *
     * @return bool
     */
    private static function translationsLoaded() {
      $translations = function_exists( '__' );
      try {
        if ( ! $translations ) {
          wp_load_translations_early();
          $translations = function_exists( '__' );
        }
      } catch ( Exception $ex ) {
        $translations = false;
      }

      return $translations;
    }

    /**
     * Convert a list of integers into a list of runs: consecutive integers.
     *
     * Runs expand to include up to $erode_gaps extra integers, to make
     * fewer, longer runs. (Each run turns into a single database query,
     * so fewer of them is better.)
     *
     * @param int[] $intkeys List of integers. This can contain duplicate values.
     * @param int $erode_gaps Combine runs separated by this or fewer integers.
     *
     * @return array  Associative array with elements start => end
     */
    private function runs( &$intkeys, $erode_gaps = 2 ) {
      if ( 0 === count( $intkeys ) ) {
        return array();
      }
      sort( $intkeys, SORT_NUMERIC );
      $previous = $intkeys[0];
      $runstart = $previous;
      $runs     = array();
      foreach ( $intkeys as $intkey ) {
        if ( $intkey > $previous + 1 + $erode_gaps ) {
          $runs[ $runstart ] = $previous;
          $runstart          = $intkey;
        }
        $previous = $intkey;
      }
      if ( null !== $runstart ) {
        $runs[ $runstart ] = $previous;
      }

      return $runs;
    }

    /**
     * Create the pathname for the sqlite database.
     *
     * This is based on WP_SQLITE_OBJECT_CACHE_DB_FILE, WP_CACHE_KEY_SALT,
     * and whether igbinary is available.
     * It may have -wal and -shm appended to it by the SQLite engine.
     *
     * @return string Full filesystem pathname for SQLite database.
     */
    private function create_database_path() {

      $result = defined( 'WP_SQLITE_OBJECT_CACHE_DB_FILE' )
        ? WP_SQLITE_OBJECT_CACHE_DB_FILE
        : WP_CONTENT_DIR . '/' . self::SQLITE_FILENAME;

      $salt = defined( 'WP_CACHE_KEY_SALT' )
        ? preg_replace( '/[^-_A-Za-z0-9]/', '', WP_CACHE_KEY_SALT )
        : '';
      $salt .= $this->has_igbinary ? '' : '-a';

      if ( strlen( $salt ) > 0 ) {
        $splits = explode( '.', $result );
        if ( count( $splits ) >= 2 && 'sqlite' === $splits [ count( $splits ) - 1 ] ) {
          $splits[ count( $splits ) - 1 ] = $salt;
          $splits []                      = 'sqlite';
          $result                         = implode( '.', $splits );
        } else {
          $result .= '.' . $salt . '.sqlite';
        }
      }

      return $result;
    }

    /**
     * @param string|null $msg
     *
     * @return void
     */
    public static function drop_dead( $msg = null ) {
      if ( ! $msg ) {
        $translations = self::translationsLoaded();
        $msg          = $translations
          ? __( 'The SQLite Object Cache temporarily failed. Please try again now.', 'sqlite-object-cache' )
          : 'The SQLite Object Cache temporarily failed. Please try again now.';
      }
      wp_die( esc_html( $msg ) );
    }

    /**
     * Log an error.
     *
     * @param string $msg
     * @param Exception $exception
     *
     * @return void
     */
    private function error_log( $msg, $exception = null ) {
      $log_exception = ! ! $exception;
      $msgs          = array();
      $msgs []       = 'SQLite Object Cache:';
      $msgs []       = $this->sqlite_get_version();
      $msgs []       = $this->has_igbinary ? 'igbinary:' : 'no igbinary:';
      $msgs []       = 'php ' . PHP_VERSION . ':';
      $msgs []       = $_SERVER['SERVER_SOFTWARE'] . ':';
      $msgs []       = $msg;
      if ( $this->sqlite ) {
        if ( $this->sqlite->lastErrorMsg() ) {
          $msgs []       = $this->sqlite->lastErrorMsg();
          $msgs []       = '(' . $this->sqlite->lastErrorCode() . ')';
          $log_exception = $log_exception && $this->sqlite->lastErrorMsg() !== $exception->getMessage();
        }
      }
      if ( $log_exception ) {
        $msgs[]  = $exception->getMessage();
        $msgs [] = '(' . $exception->getCode() . ')';
        $msgs [] = $exception->getTraceAsString();
      }
      error_log( implode( ' ', $msgs ) );
    }

    /**
     * Open SQLite3 connection.
     * @return void
     */
    private function open_connection() {
      if ( $this->sqlite ) {
        return;
      }
      $retries = 3;
      while ( $retries -- > 0 ) {
        try {
          $this->actual_open_connection();

          return;
        } catch ( Exception $ex ) {
          /* something went wrong opening */
          $this->error_log( 'open_connection failure', $ex );
          $this->delete_offending_files( $retries );
        }
      }
    }

    /**
     * Open SQLite3 connection.
     *
     * @return void
     * @throws Exception Announce SQLite failure.
     */
    private function actual_open_connection() {
      $start        = $this->time_usec();
      $this->sqlite = new SQLite3( $this->sqlite_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE, '' );
      $this->sqlite->enableExceptions( true );
      $this->sqlite->busyTimeout( $this->sqlite_timeout );

      /* Set some initial pragma stuff.
       * Notice we sometimes use a journal mode (MEMORY) that risks database corruption.
       * That's OK, because it's faster, and because we have an error
       * recovery procedure that deletes and recreates a corrupt database file.
       */
      $this->sqlite->exec( 'PRAGMA page_size = 4096' );
      if ( $this->mmap_size ) {
        $this->sqlite->exec( 'PRAGMA mmap_size = ' . $this->mmap_size );
      }
      $this->sqlite->exec( 'PRAGMA synchronous = OFF' );
      $this->sqlite->exec( "PRAGMA journal_mode = $this->sqlite_journal_mode" );
      $this->sqlite->exec( "PRAGMA encoding = 'UTF-8'" );
      $this->sqlite->exec( 'PRAGMA case_sensitive_like = true' );
      $this->create_object_cache_table();
      $this->prepare_statements( $this->cache_table_name );

      $this->open_time = $this->time_usec() - $start;
    }

    /**
     * Get current time.
     *
     * @return float Current time in microseconds, from an arbitrary epoch.
     */
    private function time_usec() {
      if ( $this->has_hrtime ) {
        /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
        return hrtime( true ) * 0.001;
      }

      return microtime( true );
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
     * Do the necessary Data Definition Language work.
     *
     * We use a single name column comprising group|key in one text string.
     * Why?
     * In recent versions of SQLite, it can serve as a clustered-index simple primary key.
     * SQLite's ANALYZE facilty only builds query - planner stats for the first column of composite keys .
     *
     * "groups" are all text .
     *
     * "keys" are sometimes alphanumeric text and sometimes integers . So, they are all treated as text
     * in the name column of the database .
     *
     * Now, range scanning( BETWEEN ) is a hassle in get_multiple, especially when using
     * get_multiple to retrieve a range of keys from a group .
     *
     * @return void
     * @throws Exception If something fails .
     * @noinspection SqlResolve
     */
    private function create_object_cache_table() {
      $this->sqlite->exec( 'BEGIN' );
      /* does our table exist?  */
      $q = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND tbl_name = '$this->cache_table_name';";
      $r = $this->sqlite->querySingle( $q );
      if ( 0 === $r ) {
        /* later versions of SQLite3 have clustered primary keys, "WITHOUT ROWID" */
        $uses_rowid = version_compare( $this->sqlite_get_version(), '3.8.2' ) < 0;
        if ( $uses_rowid ) {
          /* @noinspection SqlIdentifier */
          $t = "
						CREATE TABLE IF NOT EXISTS $this->cache_table_name (
						   name TEXT NOT NULL COLLATE BINARY,
						   expires INT,
						   value BLOB
						);
						CREATE UNIQUE INDEX IF NOT EXISTS name ON $this->cache_table_name (name);
						CREATE INDEX IF NOT EXISTS expires ON $this->cache_table_name (expires);";
        } else {
          /* @noinspection SqlIdentifier */
          $t = "
						CREATE TABLE IF NOT EXISTS $this->cache_table_name (
						   name TEXT NOT NULL PRIMARY KEY COLLATE BINARY,
						   expires INT,
						   value BLOB
						) WITHOUT ROWID;
						CREATE INDEX IF NOT EXISTS expires ON $this->cache_table_name (expires);";
        }

        $this->sqlite->exec( $t );
      }
      $this->sqlite->exec( 'COMMIT' );
    }

    /**
     * Do the necessary Data Definition Language work.
     *
     * @param string $tbl The name of the table.
     *
     * @return void
     * @throws Exception If something fails.
     * @noinspection SqlResolve
     */
    private function maybe_create_stats_table( $tbl ) {
      $this->sqlite->exec( 'BEGIN' );
      /* Does our table exist?  */
      $q = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND tbl_name = '$tbl';";
      $r = $this->sqlite->querySingle( $q );
      if ( 0 === $r ) {
        /* @noinspection SqlIdentifier */
        $t = "
						CREATE TABLE IF NOT EXISTS $tbl (
						   value BLOB,
						   timestamp INT
						);
						CREATE INDEX IF NOT EXISTS expires ON $tbl (timestamp);";
        $this->sqlite->exec( $t );
      }
      $this->sqlite->exec( 'COMMIT' );
    }

    /**
     * Create the prepared statements to use.
     *
     * @param string $tbl Table name.
     *
     * @return void
     * @throws Exception Announce failure.
     * @noinspection SqlResolve
     */
    private function prepare_statements( $tbl ) {
      $now               = time();
      $this->getone      =
        $this->sqlite->prepare( "SELECT value FROM $tbl WHERE name = :name AND expires >= $now;" );
      $this->getrange    =
        $this->sqlite->prepare( "SELECT name, value FROM $tbl WHERE name BETWEEN :first AND :last AND expires >= $now;" );
      $this->deleteone   = $this->sqlite->prepare( "DELETE FROM $tbl WHERE name = :name;" );
      $this->deletegroup = $this->sqlite->prepare( "DELETE FROM $tbl WHERE name LIKE :group || '%';" );
      /*
       * Some versions of SQLite3 built into php predate the 3.38 advent of unixepoch() (2022-02-22).
       * And, others predate the 3.24 advent of UPSERT (that is, ON CONFLICT) syntax.
       * In that case we have to do attempt-update then insert to get updates to work. Sigh.
       */
      $has_upsert = version_compare( $this->sqlite_get_version(), '3.24', 'ge' );
      if ( $has_upsert ) {
        $this->upsertone =
          $this->sqlite->prepare( "INSERT INTO $tbl (name, value, expires) VALUES (:name, :value, $now + :expires) ON CONFLICT(name) DO UPDATE SET value=excluded.value, expires=excluded.expires;" );
      } else {
        $this->insertone =
          $this->sqlite->prepare( "INSERT INTO $tbl (name, value, expires) VALUES (:name, :value, $now + :expires);" );
        $this->updateone =
          $this->sqlite->prepare( "UPDATE $tbl SET value = :value, expires = $now + :expires WHERE name = :name;" );
      }
    }

    /**
     * Unserialize persistend data. Use igbinary if available.
     *
     * @param mixed $data To be unserialized.
     *
     * @return string|mixed Data ready for use.
     */
    private function maybe_unserialize( $data ) {
      return $this->has_igbinary
        ? igbinary_unserialize( $data )
        : maybe_unserialize( $data );
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
        $translations = self::translationsLoaded();

        return $translations
          ? sprintf( /* translators: 1: WP_CONTENT_DIR */ __( 'The SQLite Object Cache cannot be activated because the %s directory is not writable.', 'sqlite-object-cache' ), $directory )
          : sprintf( 'The SQLite Object Cache cannot be activated because the %s directory is not writable.', $directory );

      }

      if ( ! class_exists( 'SQLite3' ) || ! extension_loaded( 'sqlite3' ) ) {
        $translations = self::translationsLoaded();

        return $translations
          ? __( 'The SQLite Object Cache cannot be activated because the SQLite3 extension is not loaded.', 'sqlite-object-cache' )
          : 'The SQLite Object Cache cannot be activated because the SQLite3 extension is not loaded.';
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
     * Is recording this performance sample appropriate.
     *
     * We decide to take a performance sample based upon:
     *  -- the sqlite_object_cache_settings option existing.
     *  -- $option.capture having the 'on' value.
     *  -- $option.samplerate >= 100 or samplerate greater than a random number.
     *
     * @return bool True if this sample should be recorded.
     */
    private function is_sample() {
      $options = get_option( 'sqlite_object_cache_settings', 'missing_option' );
      if ( 'missing_option' === $options ) {
        /* set an absent option to the empty array, so we don't repeatedly hammer the cache looking for a missing option */
        update_option( 'sqlite_object_cache_settings', array(), true );

        return false;
      }
      if ( is_array( $options ) && array_key_exists( 'capture', $options ) && 'on' === $options['capture'] ) {
        if ( array_key_exists( 'samplerate', $options ) && is_numeric( $options['samplerate'] ) ) {
          /* samplerate is a percentage likelihood in the option setting */
          $samplerate = $options['samplerate'] * 0.01;
          if ( $samplerate > 0.0 ) {
            /* a random sample at $samplerate */
            if ( $samplerate >= 1.0 ) {
              return true;
            }

            return $samplerate >= lcg_value();
          }
        }
      }

      return false;
    }

    /**
     * Capture statistics if need be. Leave the connection open for late-arriving cache operations.
     *
     * @return bool
     */
    public function close() {
      if ( $this->sqlite ) {
        if ( $this->is_sample() ) {
          $this->capture( $this->monitoring_options );
        }
        /* Once in a while checkpoint the whole WAL log, so it doesn't grow without bound on a busy site. */
        if ( 1 === rand( 1, 5000 ) ) {
          $this->checkpoint();
        }
      }

      return true;
    }

    /**
     * Serialize data for persistence if need be. Use igbinary if available.
     *
     * @param mixed $data To be serialized.
     *
     * @return string|mixed Data ready for dbms insertion.
     */
    private function maybe_serialize( $data ) {
      return $this->has_igbinary
        ? igbinary_serialize( $data )
        : maybe_serialize( $data );
    }

    /**
     * Remove statistics entries from the cache
     *
     * @param int|null $age Number of seconds' worth to retain. Default: retain none.
     *
     * @return void
     */
    public function sqlite_reset_statistics( $age = null ) {
      try {
        $object_stats = self::OBJECT_STATS_TABLE;
        $this->maybe_create_stats_table( $object_stats );
        if ( ! is_numeric( $age ) ) {
          /* @noinspection SqlWithoutWhere */
          $sql = "DELETE FROM $object_stats;";
          $this->sqlite->exec( $sql );
        } else {
          $expires = (int) ( time() - $age );
          $limit   = self::TRANSACTION_SIZE_LIMIT;
          $hits    = $limit;
          while ( $hits >= $limit ) {
            /* @noinspection SqlResolve */

            $sql = "DELETE FROM $object_stats WHERE timestamp IN (SELECT timestamp FROM $object_stats WHERE timestamp < $expires LIMIT $limit);";
            $this->sqlite->exec( $sql );
            $hits = $this->sqlite->changes();
          }
        }
      } catch ( Exception $ex ) {
        $this->error_log( 'SQLite Object Cache exception resetting statistics. ', $ex );
      }
    }

    /**
     * Remove old entries.
     *
     * @return boolean True if any items were removed.
     * @noinspection SqlResolve
     */
    public function sqlite_remove_expired() {
      $items_removed = 0;
      try {
        $this->checkpoint();
        $limit = self::TRANSACTION_SIZE_LIMIT;
        $hit   = $limit;

        /* Remove items with definite expirations, like transients */
        $sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE name IN (SELECT name FROM ' . $this->cache_table_name . ' WHERE expires <= ' . time() . ' LIMIT ' . $limit . ')';

        while ( $hit >= $limit ) {
          $this->sqlite->exec( $sql );
          $hit           = $this->sqlite->changes();
          $items_removed += $hit;
        }
      } catch ( Exception $ex ) {
        $this->error_log( 'sqlite_remove_expired', $ex );
      }

      return $items_removed > 0;
    }

    /**
     * Get the size of the cache database.
     *
     * @return int Size of current cache database in bytes.
     */
    public function sqlite_get_size() {
      $object_cache = self::OBJECT_CACHE_TABLE;
      $sql          = "SELECT SUM(LENGTH(value) + LENGTH(name)) length FROM $object_cache";
      $stmt         = $this->sqlite->prepare( $sql );
      $resultset    = $stmt->execute();
      $row          = $resultset->fetchArray( SQLITE3_NUM );
      $result       = $row[0];
      $resultset->finalize();

      return (int) $result;
    }

    /**
     * Read object names, sizes, expirations from cache, ordered by expiration time oldest first.
     *
     * @param $timestamps true If the timestamps returned should be expirations, false means raw
     *
     * @return Generator of name/length/timestamp rows.
     * @throws Exception Announce SQLite failure.
     * @noinspection SqlResolve
     */
    public function sqlite_load_usages( $timestamps = true ) {
      $object_cache = self::OBJECT_CACHE_TABLE;
      $offset       = $this->noexpire_timestamp_offset;
      $sql          = "SELECT name, LENGTH(value) + LENGTH(name) length, expires FROM $object_cache";
      $stmt         = $this->sqlite->prepare( $sql );
      $resultset    = $stmt->execute();
      while ( true ) {
        $row = $resultset->fetchArray( SQLITE3_ASSOC );
        if ( ! $row ) {
          break;
        }
        $row = (object) $row;
        if ( $timestamps ) {
          $expires = $row->expires;
          if ( $expires >= self::NOEXPIRE_TIMESTAMP_OFFSET ) {
            $expires -= self::NOEXPIRE_TIMESTAMP_OFFSET;
          }
          $row->expires = $expires;
        }
        yield $row;
      }
      $resultset->finalize();
    }

    public function sqlite_sizes() {
      $object_stats = self::OBJECT_STATS_TABLE;

      $items = array(
        'page_size'   => 'PRAGMA page_size;',
        'free_pages'  => 'PRAGMA freelist_count;',
        'total_pages' => 'PRAGMA page_count;',
        'stats_items' => "SELECT COUNT(value) FROM $object_stats;",
        'stats_size'  => "SELECT SUM(LENGTH(value)+ 4) FROM $object_stats;",
        'mmap_size'   => "PRAGMA mmap_size;",
      );

      $result = array();
      foreach ( $items as $item => $query ) {
        $stmt      = $this->sqlite->prepare( $query );
        $resultset = $stmt->execute();
        $row       = $resultset->fetchArray( SQLITE3_NUM );
        $val       = (int) $row[0];
        $resultset->finalize();
        $result [ $item ] = $val;
      }

      return $result;
    }

    /**
     * Read timestamps and object sizes of non-expiring items, oldest first, in buckets of 16 seconds.
     *
     * Object sizes are the summed lengths of name, value, and timestamp, and ignore index overhead.
     *
     * @return SQLite3Result Resultset containing length/timestamp rows.
     * @throws Exception Announce SQLite failure.
     * @noinspection SqlResolve
     */
    private function sqlite_load_sizes() {
      $object_cache = self::OBJECT_CACHE_TABLE;
      $offset       = $this->noexpire_timestamp_offset;
      $sql          =
        "SELECT SUM(LENGTH(value) + LENGTH(name) + 6) length, (expires/16)*16 expires FROM $object_cache WHERE expires >= $offset GROUP BY (expires/16) ORDER BY 2";
      $stmt         = $this->sqlite->prepare( $sql );

      return $stmt->execute();
    }

    /**
     * Read rows from the stored statistics.
     *
     * @return Generator
     * @throws Exception Announce SQLite failure.
     * @noinspection SqlResolve
     */
    public function sqlite_load_statistics() {
      $object_stats = self::OBJECT_STATS_TABLE;
      $this->maybe_create_stats_table( $object_stats );
      $sql       = "SELECT value FROM $object_stats;";
      $stmt      = $this->sqlite->prepare( $sql );
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
      $now = microtime( true );
      global $wpdb;
      $record       = array(
        'time'              => $now,
        'RAMhits'           => $this->cache_hits,
        'RAMmisses'         => $this->cache_misses,
        'DISKhits'          => $this->persistent_hits,
        'DISKmisses'        => $this->persistent_misses,
        'open'              => $this->open_time,
        'selects'           => $this->select_times,
        'get_multiples'     => $this->get_multiple_times,
        'get_multiple_keys' => $this->get_multiple_keys,
        'inserts'           => $this->insert_times,
        'deletes'           => $this->delete_times,
        'checkpoints'       => $this->checkpoint_times,
        'DBMSqueries'       => $wpdb->num_queries,
        'RAM'               => memory_get_peak_usage( true ),
      );
      $object_stats = self::OBJECT_STATS_TABLE;
      try {
        $this->maybe_create_stats_table( $object_stats );
        $sql  =
          "INSERT INTO $object_stats (value, timestamp) VALUES (:value, :timestamp);";
        $stmt = $this->sqlite->prepare( $sql );
        $stmt->bindValue( ':value', $this->maybe_serialize( $record ), SQLITE3_BLOB );
        $stmt->bindValue( ':timestamp', time(), SQLITE3_INTEGER );
        $result = $stmt->execute();
        $result->finalize();
      } catch ( Exception $ex ) {
        $this->error_log( 'error capturing performance stats, skipping.', $ex );
      }
      unset( $record, $stmt );
    }

    /**
     *  Get the version of SQLite in use.
     *
     * @return string
     */
    public function sqlite_get_version() {
      if ( $this->sqlite_version ) {
        return $this->sqlite_version;
      }
      $v                    = SQLite3::version();
      $this->sqlite_version = $v['versionString'];

      return $this->sqlite_version;
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
     * @param mixed $value Property value.
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
     * @param array $data Array of keys and values to be added.
     * @param string $group Optional. Where the cache contents are grouped. Default empty.
     * @param int $expire Optional. When to expire the cache contents, in seconds.
     *                       Default 0 (no expiration).
     *
     * @return bool[] Array of return values, grouped by key. Each value is either
     *                true on success, or false if cache key and group already exist.
     * @since 6.0.0
     */
    public function add_multiple( array &$data, $group = '', $expire = 0 ) {
      if ( 0 === count( $data ) ) {
        return array();
      }
      $values = array();
      /* sort the array to reduce index page fragmentation */
      ksort( $data, SORT_NUMERIC );
      try {
        /* use a transaction to accelerate add_multiple */
        $this->transaction_active = true;
        $this->sqlite->exec( 'BEGIN' );
        $transaction_size = self::TRANSACTION_SIZE_LIMIT;
        foreach ( $data as $key => $value ) {
          $values[ $key ] = $this->add( $key, $value, $group, $expire );
          /* limit the size of the transaction, hopefully preventing timeouts in other clients */
          if ( -- $transaction_size <= 0 ) {
            $this->sqlite->exec( 'COMMIT' );
            $this->sqlite->exec( 'BEGIN' );
            $transaction_size = self::TRANSACTION_SIZE_LIMIT;
          }
        }
        $this->sqlite->exec( 'COMMIT' );
        $this->transaction_active = false;
      } catch ( Exception $ex ) {
        $this->error_log( 'add_multiple', $ex );
        $this->delete_offending_files();
        self::drop_dead();
      }

      return $values;
    }

    /**
     * Adds data to the cache if it doesn't already exist.
     *
     * @param int|string $key What to call the contents in the cache.
     * @param mixed $data The contents to store in the cache.
     * @param string $group Optional. Where to group the cache contents. Default 'default'.
     * @param int $expire Optional. When to expire the cache contents, in seconds.
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

      $name = $this->normalize_name( $key, $group );

      if ( $this->cache_item_not_exists( $name ) ) {
        return $this->set( $key, $data, $group, (int) $expire );
      }

      return false;
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
     * As a side-effect and optimization, copy the value from the SQLite store
     * to RAM if it exists in the SQLite store.
     *
     * @param int|string $name Cache key to check for existence.
     *
     * @return bool Whether the key exists in the cache for the given group.
     * @throws Exception Announce database failure.
     * @since 3.4.0
     */
    protected function cache_item_exists( $name ) {
      $exists = array_key_exists( $name, $this->cache );
      if ( ! $exists ) {
        if ( array_key_exists( $name, $this->not_in_persistent_cache ) ) {
          return false;
        }
        $val = $this->get_by_name( $name );
        if ( null !== $val ) {
          $this->cache[ $name ] = $val;
          $exists               = true;
          $this->persistent_hits ++;
          unset( $this->not_in_persistent_cache[ $name ] );
        } else {
          $this->persistent_misses ++;
          $this->not_in_persistent_cache[ $name ] = true;
        }
      }

      return $exists;
    }

    /**
     * Determine whether a key does not exist in the cache. either local or SQLite
     *
     * @param int|string $name Cache key to check for existence.
     *
     * @return bool Whether the key does not exists in the cache.
     * @throws Exception Announce database failure.
     * @since 3.4.0
     */
    protected function cache_item_not_exists( $name ) {

      if ( array_key_exists( $name, $this->cache ) ) {
        return false;
      }
      if ( array_key_exists( $name, $this->not_in_persistent_cache ) ) {
        return true;
      }

      return ! $this->cache_item_exists( $name );
    }

    /**
     * Get one item from external cache.
     *
     * @param string $name Cache key.
     *
     * @return mixed|null Cached item, or null if not found. (Cached item can be false.)
     * @throws Exception Announce database failure.
     */
    private function get_by_name( $name ) {
      $start = $this->time_usec();
      if ( array_key_exists( $name, $this->not_in_persistent_cache ) ) {
        return null;
      }
      $data = null;
      try {
        $stmt = $this->getone;
        $stmt->bindValue( ':name', $name, SQLITE3_TEXT );
        $result = $stmt->execute();
        $row    = $result->fetchArray( SQLITE3_NUM );
        $data   = false !== $row && is_array( $row ) && 1 === count( $row ) ? $row[0] : null;
        if ( null !== $data ) {
          $data = $this->maybe_unserialize( $data );
          unset ( $this->not_in_persistent_cache[ $name ] );
        } else {
          $this->not_in_persistent_cache [ $name ] = true;
        }
        $result->finalize();
      } catch ( Exception $ex ) {
        unset( $this->not_in_persistent_cache [ $name ] );
        $this->error_log( 'get_by_name', $ex );
        $this->delete_offending_files();
        self::drop_dead();
      }

      $this->select_times[] = $this->time_usec() - $start;

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
     * @param mixed $data The contents to store in the cache.
     * @param string $group Optional. Where to group the cache contents. Default 'default'.
     * @param int $expire Optional. Not used.
     *
     * @return bool True if contents were set, false if key is invalid.
     * @since 2.0.0
     * @since 6.1.0 Returns false if cache key is invalid.
     *
     */
    public function set( $key, $data, $group = 'default', $expire = 0 ) {
      if ( ! $this->is_valid_key( $key ) ) {
        return false;
      }

      $name = $this->normalize_name( $key, $group );

      if ( is_object( $data ) ) {
        $data = clone $data;
      }

      $this->cache[ $name ] = $data;

      if ( $this->is_ignored_group( $group ) ) {
        return false;
      }

      $this->put_by_name( $name, $data, $expire );

      return true;
    }

    /**
     * Write to the persistent cache, with timeout retry.
     *
     * @param string $name What to call the contents in the cache.
     * @param mixed $data The contents to store in the cache.
     * @param int $expire Optional. Not used.
     *
     * @return void
     */
    private function put_by_name( $name, $data, $expire ) {
      $exception = null;
      $start     = $this->time_usec();
      $value     = $this->maybe_serialize( $data );
      $expires   = $expire ?: $this->noexpire_timestamp_offset;
      $retries   = 3;
      while ( $retries -- > 0 ) {
        try {
          $this->actual_put_by_name( $name, $value, $expires );
          unset( $this->not_in_persistent_cache[ $name ] );
          $this->insert_times[] = $this->time_usec() - $start;

          return;
        } catch ( Exception $ex ) {
          $exception = $ex;
          if ( ! str_contains( $ex->getMessage(), 'database is locked' ) || 5 !== $this->sqlite->lastErrorCode() ) {
            break;
          }
        }
        sleep( 1 );
      }
      if ( $exception ) {
        $this->error_log( 'put_by_name', $exception );
        $this->delete_offending_files();
        self::drop_dead();
      }
    }

    /**
     * Actually write to the cache.
     *
     * @param string $name What to call the contents in the cache.
     * @param string $value The seriolized value.
     * @param int $expires Expiration time.
     *
     * @return void
     */
    private function actuaL_put_by_name( $name, $value, $expires ) {
      if ( $this->upsertone ) {
        $stmt = $this->upsertone;
        $stmt->bindValue( ':name', $name, SQLITE3_TEXT );
        $stmt->bindValue( ':value', $value, SQLITE3_BLOB );
        $stmt->bindValue( ':expires', $expires, SQLITE3_INTEGER );
        $result = $stmt->execute();
        $result->finalize();
      } else {
        /* Pre-upsert version (pre- 3.24) of SQLite,
         * Need to try update, then do insert if need be.
         * Race conditions are possible, hence BEGIN / COMMIT
         */
        if ( ! $this->transaction_active ) {
          $this->sqlite->exec( 'BEGIN' );
        }
        $stmt = $this->updateone;
        $stmt->bindValue( ':name', $name, SQLITE3_TEXT );
        $stmt->bindValue( ':value', $value, SQLITE3_BLOB );
        $stmt->bindValue( ':expires', $expires, SQLITE3_INTEGER );
        $result = $stmt->execute();
        $result->finalize();
        if ( 0 === $this->sqlite->changes() ) {
          /* Updated zero rows, so we need an insert. */
          $stmt = $this->insertone;
          $stmt->bindValue( ':name', $name, SQLITE3_TEXT );
          $stmt->bindValue( ':value', $value, SQLITE3_BLOB );
          $stmt->bindValue( ':expires', $expires, SQLITE3_INTEGER );
          $result = $stmt->execute();
          $result->finalize();
        }
        if ( ! $this->transaction_active ) {
          $this->sqlite->exec( 'COMMIT' );
        }
      }
    }

    /**
     * Replaces the contents in the cache, if contents already exist.
     *
     * @param int|string $key What to call the contents in the cache.
     * @param mixed $data The contents to store in the cache.
     * @param string $group Optional. Where to group the cache contents. Default 'default'.
     * @param int $expire Optional. When to expire the cache contents, in seconds.
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

      $name = $this->normalize_name( $key, $data );

      if ( $this->cache_item_not_exists( $name ) ) {
        return false;
      }

      return $this->set( $key, $data, $group, (int) $expire );
    }

    /**
     * Sets multiple values to the cache in one call.
     *
     * @param array $data Array of key and value to be set.
     * @param string $group Optional. Where the cache contents are grouped. Default empty.
     * @param int $expire Optional. When to expire the cache contents, in seconds.
     *                       Default 0 (no expiration).
     *
     * @return bool[] Array of return values, grouped by key. Each value is always true.
     * @since 6.0.0
     */
    public function set_multiple( array &$data, $group = '', $expire = 0 ) {
      if ( 0 === count( $data ) ) {
        return array();
      }
      $values = array();
      /* Sort the array to reduce index page fragmentation */
      ksort( $data, SORT_NUMERIC );
      try {
        /* use a transaction to accelerate set_multiple */
        $this->transaction_active = true;
        $this->sqlite->exec( 'BEGIN' );
        $transaction_size = self::TRANSACTION_SIZE_LIMIT;

        foreach ( $data as $key => $value ) {
          $values[ $key ] = $this->set( $key, $value, $group, $expire );
          /* limit the size of the transaction, hopefully preventing timeouts in other clients */
          if ( -- $transaction_size <= 0 ) {
            $this->sqlite->exec( 'COMMIT' );
            $this->sqlite->exec( 'BEGIN' );
            $transaction_size = self::TRANSACTION_SIZE_LIMIT;
          }
        }
        $this->sqlite->exec( 'COMMIT' );
        $this->transaction_active = false;
      } catch ( Exception $ex ) {
        $this->error_log( 'set_multiple', $ex );
        $this->delete_offending_files();
        self::drop_dead();
      }

      return $values;
    }

    /**
     * Retrieves multiple values from the cache in one call.
     *
     * @param string[]|int[] $input_keys
     * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
     * @param bool $force Optional. Whether to force an update of the local cache
     *                      from the persistent cache. Default false.
     *
     * @return array Array of return values, grouped by key. Each value is either
     *               the cache contents on success, or false on failure.
     * @since 5.5.5
     */
    public function get_multiple( &$input_keys, $group = 'default', $force = false ) {
      $values = array();
      if ( count( $input_keys ) <= 1 || $force ) {
        /* Send the degenerate get_multiple calls, and forced calls, to plain old get. That logic is simpler. */
        foreach ( $input_keys as $key ) {
          $values[ $key ] = $this->get( $key, $group, $force );
        }

        return $values;
      }
      $start = $this->time_usec();

      $normalized     = array();
      $keys_not_found = array();
      /* Find already-cached keys, pruning down the list of keys to fetch. */
      foreach ( $input_keys as $key ) {
        $name                = $this->normalize_name( $key, $group );
        $normalized [ $key ] = $name;
        if ( array_key_exists( $name, $this->cache ) ) {
          $values [ $key ] = is_object( $this->cache[ $name ] )
            ? clone $this->cache[ $name ]
            : $this->cache[ $name ];
          ++ $this->cache_hits;
        } else {
          $keys_not_found[ $key ] = $name;
        }
      }

      if ( count( $keys_not_found ) <= 1 ) {
        /* Degenerate case after fulfilment from RAM: handle as simple get */
        foreach ( $keys_not_found as $key => $name ) {
          $values[ $key ] = $this->get_by_normalized_name( $name );
        }

        return $values;
      }
      /* split into alpha and numeric keys */
      $alphakeys = array();
      $intkeys   = array();
      foreach ( $keys_not_found as $key => $name ) {
        if ( is_numeric( $key ) && (int) $key == $key && (int) $key > 0 && (int) $key <= $this->intkey_max ) {
          $intkeys [] = (int) $key;
        } else {
          $alphakeys [ $key ] = $name;
        }
      }
      try {
        /* Get the consecutive integer key runs */
        $runs = $this->runs( $intkeys, $this->erode_gaps );

        /* use a transaction to accelerate get_multiple */
        $this->transaction_active = true;
        $this->sqlite->exec( 'BEGIN' );
        $transaction_size = self::TRANSACTION_SIZE_LIMIT;

        /* Start by loading the consecutive runs of int keys */
        foreach ( $runs as $first => $last ) {
          $stmt = $this->getrange;
          $stmt->bindValue( ':first', $normalized[ $first ], SQLITE3_TEXT );
          $stmt->bindValue( ':last', $normalized[ $last ], SQLITE3_TEXT );
          $resultset = $stmt->execute();
          while ( true ) {
            $row = $resultset->fetchArray( SQLITE3_NUM );
            if ( ! $row ) {
              break;
            }
            ++ $this->persistent_hits;
            $name                 = $row[0];
            $this->cache[ $name ] = $this->maybe_unserialize( $row[1] );
            unset( $this->not_in_persistent_cache[ $name ] );
          }
          $resultset->finalize();
          /* limit the size of the transaction, hopefully preventing timeouts in other clients */
          if ( -- $transaction_size <= 0 ) {
            $this->sqlite->exec( 'COMMIT' );
            $this->sqlite->exec( 'BEGIN' );
            $transaction_size = self::TRANSACTION_SIZE_LIMIT;
          }
        }
        /* Do the alpha keys, if any */
        foreach ( $alphakeys as $key => $name ) {
          if ( ! array_key_exists( $key, $values ) ) {
            $values[ $key ] = $this->get_by_normalized_name( $name );
            /* limit the size of the transaction, hopefully preventing timeouts in other clients */
            if ( -- $transaction_size <= 0 ) {
              $this->sqlite->exec( 'COMMIT' );
              $this->sqlite->exec( 'BEGIN' );
              $transaction_size = self::TRANSACTION_SIZE_LIMIT;
            }
          }
        }
        foreach ( $intkeys as $key ) {
          if ( ! array_key_exists( $key, $values ) ) {
            $values [ $key ] = $this->get_by_normalized_name( $normalized[ $key ] );
            /* limit the size of the transaction, hopefully preventing timeouts in other clients */
            if ( -- $transaction_size <= 0 ) {
              $this->sqlite->exec( 'COMMIT' );
              $this->sqlite->exec( 'BEGIN' );
              $transaction_size = self::TRANSACTION_SIZE_LIMIT;
            }
          }
        }
        $this->sqlite->exec( 'COMMIT' );
        $this->transaction_active = false;
      } catch ( Exception $ex ) {
        $this->error_log( 'get_multiple', $ex );
        $this->delete_offending_files();
        self::drop_dead();
      }
      $this->get_multiple_keys []  = count( $keys_not_found );
      $this->get_multiple_times [] = $this->time_usec() - $start;

      return $values;
    }

    /**
     * Get the cache row name for a key and group.
     *
     * @param int|string $key Key name.
     * @param string $group Group name, default = 'default'.
     *
     * @return string
     */
    private function normalize_name( $key, $group ) {
      if ( is_numeric( $key ) && (int) $key == $key && (int) $key >= 0 && (int) $key <= $this->intkey_max ) {
        $key = self::INTKEY_SENTINEL . str_pad( $key, 1 + $this->intkey_length, '0', STR_PAD_LEFT );
      }

      if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
        $key = $this->blog_prefix . $key;
      }
      if ( empty( $group ) ) {
        $group = 'default';
      }

      return $group . '|' . $key;
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
     * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
     * @param bool $force Optional. Whether to force an update of the local cache
     *                          from the persistent cache. Default false.
     * @param bool $found Optional. Whether the key was found in the cache (passed by reference).
     *                          Disambiguates a return of false, a storable value. Default null.
     *
     * @return mixed|false The cache contents on success, false on failure to retrieve contents.
     * @since 2.0.0
     */
    public function get( $key, $group = 'default', $force = false, &$found = null ) {
      if ( -- $this->get_depth <= 0 ) {
        return false;
      }

      if ( ! $this->is_valid_key( $key ) ) {
        ++ $this->get_depth;

        return false;
      }

      $name = $this->normalize_name( $key, $group );

      if ( $force ) {
        unset( $this->cache[ $name ] );
        unset ( $this->not_in_persistent_cache[ $name ] );
      }

      try {
        if ( array_key_exists( $name, $this->cache ) || $this->cache_item_exists( $name ) ) {
          $found = true;
          ++ $this->cache_hits;
          ++ $this->get_depth;

          return is_object( $this->cache[ $name ] ) ? clone( $this->cache[ $name ] ) : $this->cache[ $name ];
        }
      } catch ( Exception $ex ) {
        $this->delete_offending_files();

        ++ $this->get_depth;

        return false;
      }

      $found = false;
      $this->cache_misses ++;

      ++ $this->get_depth;

      return false;
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
     * @param string $name Normalized name.
     *
     * @return mixed|false The cache contents on success, false on failure to retrieve contents.
     * @since 2.0.0
     */
    private function get_by_normalized_name( $name ) {
      if ( -- $this->get_depth <= 0 ) {
        return false;
      }
      try {
        if ( array_key_exists( $name, $this->cache ) || $this->cache_item_exists( $name ) ) {
          ++ $this->cache_hits;
          ++ $this->get_depth;

          return is_object( $this->cache[ $name ] ) ? clone( $this->cache[ $name ] ) : $this->cache[ $name ];
        }
      } catch ( Exception $ex ) {
        $this->delete_offending_files();

        ++ $this->get_depth;

        return false;
      }
      $this->cache_misses ++;
      ++ $this->get_depth;

      return false;
    }

    /**
     * Deletes multiple values from the cache in one call.
     *
     * @param array $keys Array of keys to be deleted.
     * @param string $group Optional. Where the cache contents are grouped. Default empty.
     *
     * @return bool[] Array of return values, grouped by key. Each value is either
     *                true on success, or false if the contents were not deleted.
     * @since 6.0.0
     */
    public function delete_multiple( array $keys, $group = '' ) {
      if ( 0 === count( $keys ) ) {
        return array();
      }
      $values = array();

      /* use a transaction to accelerate delete_multiple */
      $transaction_size         = self::TRANSACTION_SIZE_LIMIT;
      $this->transaction_active = true;
      $this->sqlite->exec( 'BEGIN' );

      foreach ( $keys as $key ) {
        $values[ $key ] = $this->delete( $key, $group );
        /* limit the size of the transaction, hopefully preventing timeouts in other clients */
        if ( -- $transaction_size <= 0 ) {
          $this->sqlite->exec( 'COMMIT' );
          $this->sqlite->exec( 'BEGIN' );
          $transaction_size = self::TRANSACTION_SIZE_LIMIT;
        }
      }
      $this->sqlite->exec( 'COMMIT' );
      $this->transaction_active = false;

      return $values;
    }

    /**
     * Removes the contents of the cache key in the group.
     *
     * If the cache key does not exist in the group, then nothing will happen.
     *
     * @param int|string $key What the contents in the cache are called.
     * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
     * @param bool $deprecated Optional. Unused. Default false.
     *
     * @return bool True on success, false if the contents were not deleted.
     * @since 2.0.0
     *
     */
    public function delete( $key, $group = 'default', $deprecated = false ) {
      if ( ! $this->is_valid_key( $key ) ) {
        return false;
      }

      $name = $this->normalize_name( $key, $group );
      unset ( $this->cache[ $name ] );
      $this->delete_by_name( $name );

      return true;
    }

    /**
     * Delete the oldest elements until the size falls below the target size.
     *
     * This uses a least-recently-UPDATED approach to aging the elements. A least-recently-USED
     * approach requires writing the time of use to the cache with every access, and that
     * is too expensive.
     *
     * @param int $target_size Desired size in bytes.
     * @param int $current_size Current size in bytes.
     *
     * @return void
     */
    public function sqlite_delete_old( $target_size, $current_size ) {
      $horizon = null;
      if ( ! $this->sqlite ) {
        return;
      }
      try {
        if ( $target_size < $current_size ) {
          $resultset = $this->sqlite_load_sizes();
          if ( ! $resultset ) {
            return;
          }
          while ( true ) {
            $row = $resultset->fetchArray( SQLITE3_NUM );
            if ( ! $row ) {
              break;
            }
            /* Find the time horizon that will delete enough entries */
            $horizon      = $row[1];
            $current_size -= $row[0];
            if ( $current_size <= $target_size ) {
              break;
            }
          }
          $resultset->finalize();
          if ( ! $horizon ) {
            return;
          }
          $object_cache = self::OBJECT_CACHE_TABLE;
          $offset       = $this->noexpire_timestamp_offset;
          $limit        = self::TRANSACTION_SIZE_LIMIT;
          $hit          = $limit;

          while ( $hit >= $limit ) {
            $sql = "DELETE FROM $object_cache WHERE name IN (SELECT name FROM $object_cache WHERE expires >= $offset AND expires <= $horizon LIMIT $limit)";
            $this->sqlite->exec( $sql );
            $hit = $this->sqlite->changes();
          }
          $this->sqlite->exec( 'PRAGMA optimize;' );
        }
        $this->checkpoint();
      } catch ( Exception $ex ) {
        /* Empty, intenionally. */
      }
    }

    /**
     * Delete from the persistent cache.
     *
     * @param string $name What to call the contents in the cache.
     *
     * @return void
     */
    private function delete_by_name( $name ) {
      $exception                              = null;
      $retries                                = 3;
      $stmt                                   = $this->deleteone;
      $this->not_in_persistent_cache[ $name ] = true;
      $start                                  = $this->time_usec();
      while ( $retries -- > 0 ) {
        try {
          $stmt->bindValue( ':name', $name, SQLITE3_TEXT );
          $result = $stmt->execute();
          $result->finalize();
          $this->delete_times[] = $this->time_usec() - $start;

          return;
        } catch ( Exception $ex ) {
          $exception = $ex;
          if ( ! str_contains( $ex->getMessage(), 'database is locked' ) || 5 !== $this->sqlite->lastErrorCode() ) {
            break;
          }
        }
        sleep( 1 );
      }
      if ( $exception ) {
        $this->error_log( 'delete_by_name', $exception );
        $this->delete_offending_files();
        self::drop_dead();
      }
    }

    /**
     * Increments numeric cache item's value.
     *
     * @param int|string $key The cache key to increment.
     * @param int $offset Optional. The amount by which to increment the item's value.
     *                           Default 1.
     * @param string $group Optional. The group the key is in. Default 'default'.
     *
     * @return int|false The item's new value on success, false on failure.
     * @since 3.3.0
     */
    public function incr( $key, $offset = 1, $group = 'default' ) {
      if ( ! $this->is_valid_key( $key ) ) {
        return false;
      }

      $name = $this->normalize_name( $key, $group );

      if ( $this->cache_item_not_exists( $name ) ) {
        return false;
      }

      if ( ! is_numeric( $this->cache[ $name ] ) ) {
        $this->cache[ $name ] = 0;
      }

      $offset = (int) $offset;

      $this->cache[ $name ] += $offset;

      if ( $this->cache[ $name ] < 0 ) {
        $this->cache[ $name ] = 0;
      }
      $this->put_by_name( $name, $this->cache[ $name ], 0 );

      return $this->cache[ $name ];
    }

    /**
     * Decrements numeric cache item's value.
     *
     * @param int|string $key The cache key to decrement.
     * @param int $offset Optional. The amount by which to decrement the item's value.
     *                           Default 1.
     * @param string $group Optional. The group the key is in. Default 'default'.
     *
     * @return int|false The item's new value on success, false on failure.
     * @since 3.3.0
     *
     */
    public function decr( $key, $offset = 1, $group = 'default' ) {
      return $this->incr( $key, - $offset, $group );
    }

    /**
     * Clears the object cache of all data.
     *
     * @param bool $vacuum True to do a VACUUM operation.
     *
     * @return bool Always returns true.
     * @since 2.0.0
     */
    public function flush( $vacuum = false ) {
      try {
        $this->cache                   = array();
        $this->not_in_persistent_cache = array();

        $selective =
          defined( 'WP_SQLITE_OBJECT_CACHE_SELECTIVE_FLUSH' ) ? WP_SQLITE_OBJECT_CACHE_SELECTIVE_FLUSH : null;

        if ( $selective && is_array( $this->unflushable_groups ) && count( $this->unflushable_groups ) > 0 ) {
          $clauses = array();
          foreach ( $this->unflushable_groups as $unflushable_group ) {
            $unflushable_group = sanitize_key( $unflushable_group );
            $clauses []        = "(name NOT LIKE '$unflushable_group|%')";
          }
          /* @noinspection SqlConstantCondition, SqlConstantExpression */
          $limit = self::TRANSACTION_SIZE_LIMIT;
          $hit   = $limit;

          $sql = 'DELETE FROM ' . $this->cache_table_name . ' WHERE name IN (SELECT name FROM ' . $this->cache_table_name . ' WHERE ' . implode( ' AND ', $clauses ) . ' LIMIT $limit);';
          while ( $hit >= $limit ) {
            $this->sqlite->exec( $sql );
            $hit = $this->sqlite->changes();
          }
        } else {
          /* SQLite's TRUNCATE TABLE equivalent */
          $sql = 'DELETE FROM ' . $this->cache_table_name . ';';
          $this->sqlite->exec( $sql );
        }

        if ( $vacuum ) {
          $this->sqlite->exec( 'VACUUM;' );
        }
      } catch ( Exception $ex ) {
        $this->error_log( 'flush failure, recreate cache.', $ex );
        $this->delete_offending_files();
      }

      return true;
    }

    /**
     * Clears the in-memory cache of all data leaving the external cache untouched.
     *
     * @return bool Always returns true.
     * @since 2.0.0
     */
    public function flush_runtime() {
      $this->cache                   = array();
      $this->not_in_persistent_cache = array();

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
      try {
        $names_to_flush = array();
        $prefix         = $group . '|';
        foreach ( $this->cache as $name => $data ) {
          if ( str_starts_with( $name, $prefix ) ) {
            $names_to_flush [] = $name;
          }
        }
        foreach ( $names_to_flush as $name ) {
          unset ( $this->cache[ $name ] );
          $this->not_in_persistent_cache[ $name ] = true;
        }
        unset ( $names_to_flush );

        $stmt = $this->deletegroup;
        $stmt->bindValue( ':group', $prefix, SQLITE3_TEXT );
        $result = $stmt->execute();
        $result->finalize();
      } catch ( Exception $ex ) {
        $this->error_log( 'flush_group', $ex );
        $this->delete_offending_files();
      }
      /* remove hints about what is in the persistent cache */
      $this->not_in_persistent_cache = array();

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
      $names_to_flush = array();
      foreach ( $this->cache as $name => $data ) {
        $splits = explode( '|', $name, 2 );
        if ( 2 === count( $splits ) ) {
          $group = $splits[0];
          if ( ! isset( $this->global_groups[ $group ] ) ) {
            $names_to_flush[] = $name;
          }
        }
      }
      foreach ( $names_to_flush as $name ) {
        unset ( $this->cache[ $name ] );
        $this->not_in_persistent_cache[ $name ] = true;
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
    }

    /**
     * Return the cache type. For use by "wp-cli cache type" and other display code.
     *
     * @return string The type of cache, "SQLite".
     */
    public function get_cache_type() {
      return 'SQLite';
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
     * Get the names of the SQLite files.
     *
     * Notice there are, possibly, multiple files used to hold sqlite data.
     *
     * @return Generator Name of one of the possible SQLite files.
     */
    public function sqlite_files() {
      foreach ( array( '', '-shm', '-wal', '-wal2' ) as $suffix ) {
        yield $this->sqlite_path . $suffix;
      }
    }

    /**
     * Delete sqlite files in hopes of recovering from trouble.
     *
     * @param int $retries
     *
     * @return void
     */
    private function delete_offending_files( $retries = 0 ) {
      error_log( "sqlite_object_cache failure, deleting sqlite files to retry. $retries" );
      require_once ABSPATH . 'wp-admin/includes/file.php';
      ob_start();
      $credentials = request_filesystem_credentials( '' );
      WP_Filesystem( $credentials );
      global $wp_filesystem;
      foreach ( $this->sqlite_files() as $file ) {
        $wp_filesystem->delete( $file );
      }
      ob_end_clean();
    }

    /**
     * Checkpoint the WAL log, incorporating it into the database.
     *
     * This should be done infrequently, but frequently enough so that the log doesn't just keep getting
     * bigger in busy sites with lots of concurrency.
     *
     * @return void
     */
    private function checkpoint() {
      $start = $this->time_usec();
      $this->sqlite->exec( 'PRAGMA wal_checkpoint(RESTART)' );
      $this->checkpoint_times[] = $this->time_usec() - $start;
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
      WP_Object_Cache::drop_dead( $message );
    }
  }

  /**
   * Adds data to the cache, if the cache key doesn't already exist.
   *
   * @param int|string $key The cache key to use for retrieval later.
   * @param mixed $data The data to add to the cache.
   * @param string $group Optional. The group to add the cache to. Enables the same key
   *                           to be used across groups. Default empty.
   * @param int $expire Optional. When the cache data should expire, in seconds.
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
   * @param array $data Array of keys and values to be set.
   * @param string $group Optional. Where the cache contents are grouped. Default empty.
   * @param int $expire Optional. When to expire the cache contents, in seconds.
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
   * @param int|string $key The key for the cache data that should be replaced.
   * @param mixed $data The new data to store in the cache.
   * @param string $group Optional. The group for the cache data that should be replaced.
   *                           Default empty.
   * @param int $expire Optional. When to expire the cache contents, in seconds.
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
   * @param int|string $key The cache key to use for retrieval later.
   * @param mixed $data The contents to store in the cache.
   * @param string $group Optional. Where to group the cache contents. Enables the same key
   *                           to be used across groups. Default empty.
   * @param int $expire Optional. When to expire the cache contents, in seconds.
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
   * @param array $data Array of keys and values to be set.
   * @param string $group Optional. Where the cache contents are grouped. Default empty.
   * @param int $expire Optional. When to expire the cache contents, in seconds.
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
   * @param int|string $key The key under which the cache contents are stored.
   * @param string $group Optional. Where the cache contents are grouped. Default empty.
   * @param bool $force Optional. Whether to force an update of the local cache
   *                          from the persistent cache. Default false.
   * @param bool $found Optional. Whether the key was found in the cache (passed by reference).
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
   * @param array $keys Array of keys under which the cache contents are stored.
   * @param string $group Optional. Where the cache contents are grouped. Default empty.
   * @param bool $force Optional. Whether to force an update of the local cache
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
    if ( 0 === count( $keys ) ) {
      return array();
    }
    global $wp_object_cache;

    return $wp_object_cache->get_multiple( $keys, $group, $force );
  }

  /**
   * Removes the cache contents matching key and group.
   *
   * @param int|string $key What the contents in the cache are called.
   * @param string $group Optional. Where the cache contents are grouped. Default empty.
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
   * @param array $keys Array of keys for deletion.
   * @param string $group Optional. Where the cache contents are grouped. Default empty.
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
   * @param int|string $key The key for the cache contents that should be incremented.
   * @param int $offset Optional. The amount by which to increment the item's value.
   *                           Default 1.
   * @param string $group Optional. The group the key is in. Default empty.
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
   * @param int|string $key The cache key to decrement.
   * @param int $offset Optional. The amount by which to decrement the item's value.
   *                           Default 1.
   * @param string $group Optional. The group the key is in. Default empty.
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
    global $wp_object_cache;

    return $wp_object_cache->flush_runtime();
  }

  /**
   * Removes all cache items in a group, if the object cache implementation supports it.
   *
   * Before calling this function, always check for group flushing support using the
   * `wp_cache_supports( 'flush_group' )` function.
   *
   * @param string $group Name of group to remove from cache.
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
   * @param string|string[] $groups A group or an array of groups to add.
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
   * @param int $blog_id Site ID.
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
