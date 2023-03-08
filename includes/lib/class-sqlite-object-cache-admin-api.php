<?php
/**
 * Post type Admin API file.
 *
 * @package WordPress Plugin Template/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin API class.
 */
class SQLite_Object_Cache_Admin_API {

	/**
	 * Constructor function
	 */
	public function __construct() {
		add_action( 'save_post', array( $this, 'save_meta_boxes' ), 10, 1 );
	}

	/**
	 * Add meta box to the dashboard.
	 *
	 * @param string $id Unique ID for metabox.
	 * @param string $title Display title of metabox.
	 * @param array  $post_types Post types to which this metabox applies.
	 * @param string $context Context in which to display this metabox ('advanced' or 'side').
	 * @param string $priority Priority of this metabox ('default', 'low' or 'high').
	 * @param array  $callback_args Any axtra arguments that will be passed to the display function for this metabox.
	 *
	 * @return void
	 */
	public function add_meta_box( $id = '', $title = '', $post_types = array(), $context = 'advanced', $priority = 'default', $callback_args = null ) {

		// Get post type(s).
		if ( ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		}

		// Generate each metabox.
		foreach ( $post_types as $post_type ) {
			add_meta_box( $id, $title, array( $this, 'meta_box_content' ), $post_type, $context, $priority, $callback_args );
		}
	}

	/**
	 * Display metabox content
	 *
	 * @param object $post Post object.
	 * @param array  $args Arguments unique to this metabox.
	 *
	 * @return void
	 */
	public function meta_box_content( $post, $args ) {

		$fields = apply_filters( $post->post_type . '_custom_fields', array(), $post->post_type );

		if ( ! is_array( $fields ) || 0 === count( $fields ) ) {
			return;
		}

		echo '<div class="custom-field-panel">' . PHP_EOL;

		foreach ( $fields as $field ) {

			if ( ! isset( $field['metabox'] ) ) {
				continue;
			}

			if ( ! is_array( $field['metabox'] ) ) {
				$field['metabox'] = array( $field['metabox'] );
			}

			if ( in_array( $args['id'], $field['metabox'], true ) ) {
				$this->display_meta_box_field( $field, $post );
			}
		}

		echo '</div>' . PHP_EOL;
	}

	/**
	 * Dispay field in metabox
	 *
	 * @param array  $field Field data.
	 * @param object $post Post object.
	 *
	 * @return void
	 */
	public function display_meta_box_field( $field = array(), $post = null ) {

		if ( ! is_array( $field ) || 0 === count( $field ) ) {
			return;
		}
		echo '<p class="form-field"><label for="' . esc_attr( $field['id'] ) . '">' . esc_html( $field['label'] ) . '</label>';
		$this->echo_field( $field, $post );
		echo '</p>' . PHP_EOL;
	}

	/**
	 * Generate HTML for displaying fields.
	 *
	 * @param mixed  $data Data array.
	 * @param object $post Post object.
	 */
	public function echo_field( $data = array(), $post = null ) {

		// Get field info.
		if ( isset( $data['field'] ) ) {
			$field = $data['field'];
		} else {
			$field = $data;
		}

		$option_name = '';
		if ( isset( $data['option'] ) ) {
			$option_name = $data['option'];
		}

		/* Get saved data. */
		$field_id = $field['id'];
		$data     = null;

		/* Data to display, if set */
		$option = get_option( $option_name, array() );
		if ( is_array( $option ) && array_key_exists( $field_id, $option ) ) {
			$data = $option[ $field_id ];
		}

		/* the reset element sets the value of the field, overriding whatever is in the option */
		if ( array_key_exists( 'reset', $field ) ) {
			$data = $field['reset'];
		}

		/* Show default data if no option saved and default is supplied. */
		if ( null === $data && isset( $field['default'] ) ) {
			$data = $field['default'];
		} elseif ( null === $data ) {
			$data = '';
		}

		/* CSS class array */
		$classes = array();
		if ( array_key_exists( 'cssclass', $field ) ) {
			$classes = is_array( $field['cssclass'] ) ? $field['cssclass'] : array( $field['cssclass'] );
		}

		echo '<input ';
		echo 'id="' . esc_attr( $field['id'] ) . '" ';
		$this->echo_classes( $classes ) . ' ';
		echo 'name="' . esc_attr( $option_name ) . '[' . esc_attr( $field_id ) . ']" ';
		if ( array_key_exists( 'placeholder', $field ) ) {
			echo 'placeholder="' . esc_attr( $field['placeholder'] ) . '" ';
		}
		switch ( $field['type'] ) {

			case 'text':
			case 'url':
			case 'email':
				echo 'type="text" ';
				echo 'value="' . esc_attr( $data ) . '" ';
				break;

			case 'number':
				echo 'type="number" ';
				echo 'value="' . esc_attr( $data ) . '" ';
				if ( isset( $field['min'] ) ) {
					echo 'min="' . esc_attr( $field['min'] ) . '" ';
				}
				if ( isset( $field['max'] ) ) {
					echo 'max="' . esc_attr( $field['max'] ) . '" ';
				}
				if ( isset( $field['step'] ) ) {
					echo 'step="' . esc_attr( $field['step'] ) . '" ';
				}
				break;

			case 'password':
			case 'hidden':
				echo 'type="' . esc_attr( $field['type'] ) . '" ';
				echo 'value="' . esc_attr( $data ) . '" ';
				break;

			case 'checkbox':
				echo 'type="checkbox" ';
				if ( 'on' === $data ) {
					echo 'checked="checked" ';
				}
				break;
		}
		echo '>' . PHP_EOL;

		if ( ! $post ) {
			echo '<label for="' . esc_attr( $field['id'] ) . '">' . PHP_EOL;
		}

		echo '<span class="description">' . esc_html( $field['description'] ) . '</span>' . PHP_EOL;

		if ( ! $post ) {
			echo '</label>' . PHP_EOL;
		}
	}

	/**
	 * Given an array of CSS class names ['foo', 'bar'] echo class="foo bar".
	 *
	 * If the array is empty just echo a space.
	 *
	 * @param array $classes
	 *
	 * @return void
	 */
	private function echo_classes( array $classes ) {
		if ( count( $classes ) > 0 ) {
			echo 'class="';
			echo implode( ' ', array_map( static function ( $class ) {
				return esc_attr( $class );
			}, $classes ) );
			echo '"';
		}
		echo ' ';
	}

	/**
	 * Save metabox fields.
	 *
	 * @param integer $post_id Post ID.
	 *
	 * @return void
	 */
	public function save_meta_boxes( $post_id = 0 ) {

		if ( ! $post_id ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		$fields = apply_filters( $post_type . '_custom_fields', array(), $post_type );

		if ( ! is_array( $fields ) || 0 === count( $fields ) ) {
			return;
		}

		foreach ( $fields as $field ) {
			if ( isset( $_REQUEST[ $field['id'] ] ) ) {
				update_post_meta( $post_id, $field['id'], $this->sanitize_field( $_REQUEST[ $field['id'] ], $field['type'] ) );
			} else {
				update_post_meta( $post_id, $field['id'], '' );
			}
		}
	}

	/**
	 * Sanitize form field
	 *
	 * @param string $data Submitted value.
	 * @param string $type Type of field to sanitize.
	 *
	 * @return string       Sanitized value
	 */
	public function sanitize_field( $data = '', $type = 'text' ) {

		switch ( $type ) {
			case 'text':
				$data = sanitize_text_field( $data );
				break;
			case 'url':
				$data = sanitize_url( $data );
				break;
			case 'email':
				$data = sanitize_email( $data );
				break;
			default:
				$data = sanitize_key ($data);
				break;
		}

		return $data;
	}

}
