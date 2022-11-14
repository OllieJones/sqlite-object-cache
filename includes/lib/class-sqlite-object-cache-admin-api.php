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
		add_action( 'save_post', [ $this, 'save_meta_boxes' ], 10, 1 );
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
	public function add_meta_box( $id = '', $title = '', $post_types = [], $context = 'advanced', $priority = 'default', $callback_args = null ) {

		// Get post type(s).
		if ( ! is_array( $post_types ) ) {
			$post_types = [ $post_types ];
		}

		// Generate each metabox.
		foreach ( $post_types as $post_type ) {
			add_meta_box( $id, $title, [ $this, 'meta_box_content' ], $post_type, $context, $priority, $callback_args );
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

		$fields = apply_filters( $post->post_type . '_custom_fields', [], $post->post_type );

		if ( ! is_array( $fields ) || 0 === count( $fields ) ) {
			return;
		}

		echo '<div class="custom-field-panel">' . "\n";

		foreach ( $fields as $field ) {

			if ( ! isset( $field['metabox'] ) ) {
				continue;
			}

			if ( ! is_array( $field['metabox'] ) ) {
				$field['metabox'] = [ $field['metabox'] ];
			}

			if ( in_array( $args['id'], $field['metabox'], true ) ) {
				$this->display_meta_box_field( $field, $post );
			}
		}

		echo '</div>' . "\n";
	}

	/**
	 * Dispay field in metabox
	 *
	 * @param array  $field Field data.
	 * @param object $post Post object.
	 *
	 * @return void
	 */
	public function display_meta_box_field( $field = [], $post = null ) {

		if ( ! is_array( $field ) || 0 === count( $field ) ) {
			return;
		}

		$field = '<p class="form-field"><label for="' . $field['id'] . '">' . $field['label'] . '</label>' . $this->display_field( $field, $post, false ) . '</p>' . "\n";

		echo $field; //phpcs:ignore
	}

	/**
	 * Generate HTML for displaying fields.
	 *
	 * @param array   $data Data array.
	 * @param object  $post Post object.
	 * @param boolean $echo Whether to echo the field HTML or return it.
	 *
	 * @return string
	 */
	public function display_field( $data = [], $post = null, $echo = true ) {

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
		$field_id   = $field['id'];
		$field_name = $option_name . "[$field_id]";
		$data       = null;

		/* Data to display, if set */
		$option = get_option( $option_name, [] );
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

        /* CSS class */
        $cssclass = '';
        if ( array_key_exists('cssclass', $field)) {
            $classes = is_array ($field['cssclass']) ?  $field['cssclass'] : [$field['cssclass']];
            $cssclass = 'class = "' . implode (' ', $classes) . '"';
        }


		$html = '';

		switch ( $field['type'] ) {

			case 'text':
			case 'url':
			case 'email':
				$html .= '<input id="' . esc_attr( $field['id'] ) . '"' . $cssclass . ' type="text" name="' . esc_attr( $field_name ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="' . esc_attr( $data ) . '" />' . "\n";
				break;

			case 'password':
			case 'number':
			case 'hidden':
				$min = '';
				if ( isset( $field['min'] ) ) {
					$min = ' min="' . esc_attr( $field['min'] ) . '"';
				}
				$max = '';
				if ( isset( $field['max'] ) ) {
					$max = ' max="' . esc_attr( $field['max'] ) . '"';
				}
				$step = '';
				if ( isset( $field['step'] ) ) {
					$step = ' step="' . esc_attr( $field['step'] ) . '"';
				}
				$html .= '<input id="' . esc_attr( $field['id'] ) . '"' . $cssclass . ' type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $field_name ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="' . esc_attr( $data ) . '"' . $min . ' ' . $max . ' ' . $step . '/>' . "\n";
				break;

			case 'text_secret':
				$html .= '<input id="' . esc_attr( $field['id'] ) . '"' . $cssclass . '  type="text" name="' . esc_attr( $field_name ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="" />' . "\n";
				break;

			case 'textarea':
				$html .= '<textarea id="' . esc_attr( $field['id'] )  . '"' . $cssclass . '  rows="5" cols="50" name="' . esc_attr( $field_name ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '">' . $data . '</textarea><br/>' . "\n";
				break;

			case 'checkbox':
				$checked = '';
				if ( $data && 'on' === $data ) {
					$checked = 'checked="checked"';
				}
				$html .= '<input id="' . esc_attr( $field['id'] ) . '"' . $cssclass . '  type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $field_name ) . '" ' . $checked . '/>' . "\n";
				break;

			case 'checkbox_multi':
				foreach ( $field['options'] as $k => $v ) {
					$checked = false;
					if ( in_array( $k, (array) $data, true ) ) {
						$checked = true;
					}
					$html .= '<p><label for="' . esc_attr( $field['id'] . '_' . $k ) . '" class="checkbox_multi"><input type="checkbox" ' . checked( $checked, true, false ) . ' name="' . esc_attr( $field_name ) . '[]" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ' . $v . '</label></p> ';
				}
				break;

			case 'radio':
				foreach ( $field['options'] as $k => $v ) {
					$checked = false;
					if ( $k === $data ) {
						$checked = true;
					}
					$html .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '"><input type="radio" ' . checked( $checked, true, false ) . ' name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ' . $v . '</label> ';
				}
				break;

			case 'select':
				$html .= '<select name="' . esc_attr( $field_name ) . '" id="' . esc_attr( $field['id'] ) . '">';
				foreach ( $field['options'] as $k => $v ) {
					$selected = false;
					if ( $k === $data ) {
						$selected = true;
					}
					$html .= '<option ' . selected( $selected, true, false ) . ' value="' . esc_attr( $k ) . '">' . $v . '</option>';
				}
				$html .= '</select> ';
				break;

			case 'select_multi':
				$html .= '<select name="' . esc_attr( $field_name ) . '[]" id="' . esc_attr( $field['id'] ) . '" multiple="multiple">';
				foreach ( $field['options'] as $k => $v ) {
					$selected = false;
					if ( in_array( $k, (array) $data, true ) ) {
						$selected = true;
					}
					$html .= '<option ' . selected( $selected, true, false ) . ' value="' . esc_attr( $k ) . '">' . $v . '</option>';
				}
				$html .= '</select> ';
				break;

			case 'image':
				$image_thumb = '';
				if ( $data ) {
					$image_thumb = wp_get_attachment_thumb_url( $data );
				}
				$html .= '<img alt="Image to upload." id="' . $option_name . '_preview" class="image_preview" src="' . $image_thumb . '" /><br/>' . "\n";
				$html .= '<input id="' . $option_name . '_button" type="button" data-uploader_title="' . __( 'Upload an image', 'sqlite-object-cache' ) . '" data-uploader_button_text="' . __( 'Use image', 'sqlite-object-cache' ) . '" class="image_upload_button button" value="' . __( 'Upload new image', 'sqlite-object-cache' ) . '" />' . "\n";
				$html .= '<input id="' . $option_name . '_delete" type="button" class="image_delete_button button" value="' . __( 'Remove image', 'sqlite-object-cache' ) . '" />' . "\n";
				$html .= '<input id="' . $option_name . '" class="image_data_field" type="hidden" name="' . $field_name . '" value="' . $data . '"/><br/>' . "\n";
				break;

			case 'color':
				//phpcs:disable
				?>
                <div class="color-picker" style="position:relative;">
                    <input type="text" name="<?php esc_attr_e( $field_name ); ?>" class="color"
                           value="<?php esc_attr_e( $data ); ?>"/>
                    <div style="position:absolute;background:#FFF;z-index:99;border-radius:100%;"
                         class="colorpicker"></div>
                </div>
				<?php
				//phpcs:enable
				break;

			case 'editor':
				wp_editor(
					$data,
					$option_name,
					[
						'textarea_name' => $field_name,
					]
				);
				break;
		}

		switch ( $field['type'] ) {

			case 'checkbox_multi':
			case 'radio':
			case 'select_multi':
				$html .= '<br/><span class="description">' . $field['description'] . '</span>';
				break;

			default:
				if ( ! $post ) {
					$html .= '<label for="' . esc_attr( $field['id'] ) . '">' . "\n";
				}

				$html .= '<span class="description">' . $field['description'] . '</span>' . "\n";

				if ( ! $post ) {
					$html .= '</label>' . "\n";
				}
				break;
		}

		if ( ! $echo ) {
			return $html;
		}

		echo $html; //phpcs:ignore
        return '';

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

		$fields = apply_filters( $post_type . '_custom_fields', [], $post_type );

		if ( ! is_array( $fields ) || 0 === count( $fields ) ) {
			return;
		}

		foreach ( $fields as $field ) {
			if ( isset( $_REQUEST[ $field['id'] ] ) ) { //phpcs:ignore
				update_post_meta( $post_id, $field['id'], $this->validate_field( $_REQUEST[ $field['id'] ], $field['type'] ) ); //phpcs:ignore
			} else {
				update_post_meta( $post_id, $field['id'], '' );
			}
		}
	}

	/**
	 * Validate form field
	 *
	 * @param string $data Submitted value.
	 * @param string $type Type of field to validate.
	 *
	 * @return string       Validated value
	 */
	public function validate_field( $data = '', $type = 'text' ) {

		switch ( $type ) {
			case 'text':
				$data = esc_attr( $data );
				break;
			case 'url':
				$data = esc_url( $data );
				break;
			case 'email':
				$data = is_email( $data );
				break;
		}

		return $data;
	}

}
