<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Read and write an existing file.
 */
class SQLite_Object_Cache_File {

  private $lines;
  private $filename;

  public function __construct( $filename ) {

    $this->filename = $filename;
    $this->lines    = self::read_lines( $this->filename );

  }

  public function remove( $item ) {
    $result = array();
    $found  = false;
    foreach ( $this->lines as $line ) {
      if ( ! str_contains( $line, $item ) ) {
        $result[] = $line;
      } else {
        $found = true;
      }
    }
    if ( $found ) {
      $this->lines = $result;
    }
    return $found;
  }

  /**
   * @throws Exception if no opening php tag was found.
   */
  public function insert_before( $item, $before = "That's all, stop editing!" ) {
    $result = array();
    $found  = false;
    foreach ( $this->lines as $line ) {
      if ( ! $found && str_contains( $line, $before ) ) {
        $result[] = $item;
        $found    = true;
      }
      $result[] = $line;
    }
    if ( $found ) {
      $this->lines = $result;
      return true;
    } else {
      return $this->insert_after( $item );
    }
  }

  /**
   * @throws Exception
   */
  public function insert_after( $item, $after = '<?php' ) {
    $result = array();
    $found  = false;
    foreach ( $this->lines as $line ) {
      $result[] = $line;
      if ( ! $found && str_contains( $line, $after ) ) {
        $result[] = $item;
        $found    = true;
      }
    }
    if ( ! $found ) {
      throw new Exception( esc_html( 'No opening php tag in ' . $this->filename ) );
    }
    $this->lines = $result;
    return true;
  }

  /**
   * Read data into line-by-line array.
   *
   * @param string $filename
   *
   * @return array
   */
  public static function read_lines( $filename ) {
    $str    = self::read( $filename );
    $result = explode( "\n", $str );
    $result = array_map( function ( $line ) {
      return ltrim( $line, "\r" );
    }, $result );
    return $result;
  }


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
   */
  public function save() {

    $data = implode( "\n", $this->lines );
    $data = self::remove_zero_space( $data );
    file_put_contents( $this->filename, $data, LOCK_EX );
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
