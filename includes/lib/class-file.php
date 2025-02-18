<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
/**
 * Read and write an existing file.
 */
class SQLite_Object_Cache_File {

  /**
   * Read data from file.
   *
   * @param string $filename The pathname of the file to read.
   *
   * @return bool|string The file's contents. Empty string if the file doesn't already exist. false if it is not readable.
   */
  public static function read( $filename ) {
    if ( ! file_exists( $filename ) ) {
      return '';
    }

    if ( ! is_readable( $filename ) ) {
      return false;
    }

    $content = file_get_contents( $filename );
    return self::remove_zero_space( $content );
  }

  /**
   * Save data to file.
   *
   * @param string $filename
   * @param string $data
   *
   * @return bool True if the save succeeded.
   */
  public static function save( $filename, $data ) {

    if ( ! file_exists( $filename ) ) {
      return false;
    }
    $data = self::remove_zero_space( $data );
    $ret  = file_put_contents( $filename, $data, LOCK_EX );

    return ( false !== $ret );
  }

  /**
   * Remove Unicode zero-width spaced <200b><200c> and BOMs
   *
   * @param array|string $content
   *
   * @return array|string|string[]
   */
  public static function remove_zero_space( $content ) {
    if ( is_array( $content ) ) {
      $content = array_map( __CLASS__ . '::remove_zero_space', $content );
      return $content;
    }

    // Remove UTF-8 BOM if present
    if ( substr( $content, 0, 3 ) === "\xEF\xBB\xBF" ) {
      $content = substr( $content, 3 );
    }

    $content = str_replace( "\xe2\x80\x8b", '', $content );
    $content = str_replace( "\xe2\x80\x8c", '', $content );
    $content = str_replace( "\xe2\x80\x8d", '', $content );

    return $content;
  }

}
