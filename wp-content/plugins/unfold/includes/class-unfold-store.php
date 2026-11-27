<?php
/**
 * Reads and writes project JSON in post meta. The admin screen and the WP-CLI
 * command both go through save(), so they validate identically.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Store {

	/**
	 * Validate raw JSON and store it on a project post.
	 *
	 * The raw text is always kept (so nothing typed is lost). The public page only
	 * ever uses the last version that passed validation.
	 *
	 * @return string[] Validation errors; empty on success.
	 */
	public static function save( $post_id, $raw ) {
		$result = Unfold_Validator::validate_raw( $raw );

		// update_post_meta() unslashes its input, so slash first to keep backslashes in LaTeX intact.
		update_post_meta( $post_id, UNFOLD_META_RAW, wp_slash( (string) $raw ) );

		if ( $result['errors'] ) {
			update_post_meta( $post_id, UNFOLD_META_ERRORS, wp_slash( $result['errors'] ) );
			return $result['errors'];
		}

		update_post_meta( $post_id, UNFOLD_META_JSON, wp_slash( wp_json_encode( $result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		delete_post_meta( $post_id, UNFOLD_META_ERRORS );
		return array();
	}

	/** Last valid project data, or null. */
	public static function get( $post_id ) {
		$json = get_post_meta( $post_id, UNFOLD_META_JSON, true );
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	public static function get_raw( $post_id ) {
		$raw = get_post_meta( $post_id, UNFOLD_META_RAW, true );
		return is_string( $raw ) ? $raw : '';
	}

	/** @return string[] */
	public static function get_errors( $post_id ) {
		$errors = get_post_meta( $post_id, UNFOLD_META_ERRORS, true );
		return is_array( $errors ) ? $errors : array();
	}
}
