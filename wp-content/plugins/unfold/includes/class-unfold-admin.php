<?php
/**
 * Admin: a "Project data" box on the project edit screen where an editor pastes
 * or uploads the project JSON. Errors are listed clearly; nothing typed is lost.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Admin {

	const NONCE = 'unfold_save_json';

	public static function init() {
		add_action( 'add_meta_boxes_' . Unfold_Post_Type::TYPE, array( __CLASS__, 'add_box' ) );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
		add_action( 'save_post_' . Unfold_Post_Type::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . Unfold_Post_Type::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Unfold_Post_Type::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'default_title' ), 10, 2 );
	}

	private static function is_project_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && Unfold_Post_Type::TYPE === $screen->post_type && 'post' === $screen->base;
	}

	public static function assets() {
		if ( ! self::is_project_screen() && ! ( get_current_screen() && 'edit-' . Unfold_Post_Type::TYPE === get_current_screen()->id ) ) {
			return;
		}
		wp_enqueue_style( 'unfold-admin', UNFOLD_URL . 'assets/css/admin.css', array(), UNFOLD_VERSION . '.' . filemtime( UNFOLD_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'unfold-admin', UNFOLD_URL . 'assets/js/admin.js', array(), UNFOLD_VERSION . '.' . filemtime( UNFOLD_DIR . 'assets/js/admin.js' ), array( 'in_footer' => true ) );
	}

	public static function form_enctype( $post ) {
		if ( Unfold_Post_Type::TYPE === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public static function add_box() {
		add_meta_box( 'unfold-json', __( 'Project data (JSON)', 'unfold' ), array( __CLASS__, 'render_box' ), Unfold_Post_Type::TYPE, 'normal', 'high' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( self::NONCE, '_unfold_nonce' );
		$raw    = Unfold_Store::get_raw( $post->ID );
		$errors = Unfold_Store::get_errors( $post->ID );
		$valid  = (bool) Unfold_Store::get( $post->ID );

		echo '<div class="unfold-admin">';
		if ( $errors ) {
			self::error_list( $errors, $valid );
		} elseif ( $valid ) {
			echo '<div class="unfold-status unfold-status--ok"><strong>' . esc_html__( 'Valid.', 'unfold' ) . '</strong> ' . esc_html__( 'This JSON matches the project schema and is what the public page shows.', 'unfold' ) . '</div>';
		} else {
			echo '<div class="unfold-status"><strong>' . esc_html__( 'No project data yet.', 'unfold' ) . '</strong> ' . esc_html__( 'Paste the project JSON below or upload a .json file, then click Update / Publish.', 'unfold' ) . '</div>';
		}

		echo '<p class="unfold-live" data-unfold-live aria-live="polite"></p>';
		echo '<textarea name="unfold_json" id="unfold-json" class="unfold-json" rows="24" spellcheck="false" autocomplete="off" aria-label="' . esc_attr__( 'Project JSON', 'unfold' ) . '">' . esc_textarea( $raw ) . '</textarea>';

		echo '<div class="unfold-tools">';
		echo '<label class="unfold-upload">' . esc_html__( 'Or upload a .json file:', 'unfold' ) . ' <input type="file" name="unfold_json_file" accept=".json,application/json"></label>';
		echo '<button type="button" class="button" data-unfold-format>' . esc_html__( 'Format JSON', 'unfold' ) . '</button>';
		echo ' <button type="button" class="button" data-unfold-sample data-url="' . esc_url( UNFOLD_URL . 'sample/warehouse-fleet.json' ) . '">' . esc_html__( 'Load sample project', 'unfold' ) . '</button>';
		echo '</div>';
		echo '<p class="description">' . sprintf(
			/* translators: %s: link to the schema file */
			esc_html__( 'The JSON must match the %s. Unknown values must be null — never guessed. If the JSON is invalid, it is kept here so you can fix it, and the public page keeps showing the last valid version.', 'unfold' ),
			'<a href="' . esc_url( UNFOLD_URL . 'schema/project.schema.json' ) . '" target="_blank" rel="noopener">' . esc_html__( 'project schema (v0.1)', 'unfold' ) . '</a>'
		) . '</p>';
		echo '</div>';
	}

	private static function error_list( $errors, $has_valid ) {
		echo '<div class="unfold-status unfold-status--error" role="alert"><strong>'
			. esc_html( sprintf( _n( 'The JSON was not saved to the public page: %d problem found.', 'The JSON was not saved to the public page: %d problems found.', count( $errors ), 'unfold' ), count( $errors ) ) )
			. '</strong>';
		echo '<ol class="unfold-errors">';
		foreach ( array_slice( $errors, 0, 50 ) as $e ) {
			$parts = explode( ': ', $e, 2 );
			echo '<li>' . ( 2 === count( $parts ) ? '<code>' . esc_html( $parts[0] ) . '</code> ' . esc_html( $parts[1] ) : esc_html( $e ) ) . '</li>';
		}
		echo '</ol>';
		if ( count( $errors ) > 50 ) {
			echo '<p>' . esc_html( sprintf( __( '…and %d more.', 'unfold' ), count( $errors ) - 50 ) ) . '</p>';
		}
		echo '<p>' . ( $has_valid ? esc_html__( 'Visitors still see the last valid version.', 'unfold' ) : esc_html__( 'The public page shows a "no valid data" message until this is fixed.', 'unfold' ) ) . '</p></div>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['_unfold_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_unfold_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = null;
		// An uploaded file wins over the textarea.
		if ( ! empty( $_FILES['unfold_json_file']['tmp_name'] ) && UPLOAD_ERR_OK === $_FILES['unfold_json_file']['error'] ) {
			$file = $_FILES['unfold_json_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$name = sanitize_file_name( $file['name'] );
			if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
				return;
			}
			if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				update_post_meta( $post_id, UNFOLD_META_ERRORS, array( 'upload: only .json files are accepted (got "' . $name . '").' ) );
				return;
			}
			if ( $file['size'] > Unfold_Validator::MAX_BYTES ) {
				update_post_meta( $post_id, UNFOLD_META_ERRORS, array( 'upload: the file is larger than 1 MB.' ) );
				return;
			}
			$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( isset( $_POST['unfold_json'] ) ) {
			// Raw JSON is validated structurally below, never output unescaped, and stored as text.
			$raw = wp_unslash( $_POST['unfold_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( null === $raw ) {
			return;
		}
		if ( '' === trim( $raw ) && '' === Unfold_Store::get_raw( $post_id ) ) {
			return; // Nothing entered yet; don't nag on a fresh draft.
		}

		remove_action( 'save_post_' . Unfold_Post_Type::TYPE, array( __CLASS__, 'save' ), 10 );
		$errors = Unfold_Store::save( $post_id, $raw );
		if ( ! $errors && '' === trim( $post->post_title ) ) {
			$data = Unfold_Store::get( $post_id );
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $data['meta']['title'] ) );
		}
		add_action( 'save_post_' . Unfold_Post_Type::TYPE, array( __CLASS__, 'save' ), 10, 2 );
	}

	/** Use the JSON title when the editor left the title empty. */
	public static function default_title( $data, $postarr ) {
		if ( Unfold_Post_Type::TYPE !== $data['post_type'] || '' !== trim( $data['post_title'] ) || empty( $_POST['unfold_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $data;
		}
		$decoded = json_decode( wp_unslash( $_POST['unfold_json'] ), true ); // phpcs:ignore
		if ( is_array( $decoded ) && ! empty( $decoded['meta']['title'] ) && is_string( $decoded['meta']['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $decoded['meta']['title'] );
		}
		return $data;
	}

	public static function notices() {
		if ( ! self::is_project_screen() ) {
			return;
		}
		global $post;
		if ( $post && Unfold_Store::get_errors( $post->ID ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The project JSON has validation errors. See the "Project data (JSON)" box below.', 'unfold' ) . '</p></div>';
		}
	}

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['unfold_data'] = __( 'Project data', 'unfold' );
			}
		}
		return $new;
	}

	public static function column( $col, $post_id ) {
		if ( 'unfold_data' !== $col ) {
			return;
		}
		$valid  = (bool) Unfold_Store::get( $post_id );
		$errors = Unfold_Store::get_errors( $post_id );
		if ( $errors ) {
			echo '<span class="unfold-pill unfold-pill--error">' . esc_html( sprintf( _n( '%d error', '%d errors', count( $errors ), 'unfold' ), count( $errors ) ) ) . '</span>';
			if ( $valid ) {
				echo ' <span class="unfold-pill">' . esc_html__( 'showing last valid', 'unfold' ) . '</span>';
			}
		} elseif ( $valid ) {
			echo '<span class="unfold-pill unfold-pill--ok">' . esc_html__( 'Valid', 'unfold' ) . '</span>';
		} else {
			echo '<span class="unfold-pill">' . esc_html__( 'No data', 'unfold' ) . '</span>';
		}
	}
}
