<?php
/**
 * WP-CLI: `wp unfold validate <file>` and `wp unfold import <file>`.
 * Uses exactly the same validation as the admin screen.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_CLI {

	/**
	 * Validate a project JSON file without saving it.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON file.
	 *
	 * @when after_wp_load
	 */
	public function validate( $args ) {
		$raw    = $this->read( $args[0] );
		$result = Unfold_Validator::validate_raw( $raw );
		if ( $result['errors'] ) {
			foreach ( $result['errors'] as $e ) {
				WP_CLI::log( ' - ' . $e );
			}
			WP_CLI::error( count( $result['errors'] ) . ' problem(s) found.' );
		}
		WP_CLI::success( 'Valid project JSON.' );
	}

	/**
	 * Create or update a project from a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON file.
	 *
	 * [--post_id=<id>]
	 * : Update this project instead of creating a new one.
	 *
	 * [--status=<status>]
	 * : Post status for a new project. Default: publish.
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc ) {
		$raw    = $this->read( $args[0] );
		$check  = Unfold_Validator::validate_raw( $raw );
		$post_id = isset( $assoc['post_id'] ) ? absint( $assoc['post_id'] ) : 0;

		if ( ! $post_id ) {
			if ( $check['errors'] ) {
				foreach ( $check['errors'] as $e ) {
					WP_CLI::log( ' - ' . $e );
				}
				WP_CLI::error( 'Not imported: ' . count( $check['errors'] ) . ' problem(s).' );
			}
			$post_id = wp_insert_post(
				array(
					'post_type'   => Unfold_Post_Type::TYPE,
					'post_status' => $assoc['status'] ?? 'publish',
					'post_title'  => $check['data']['meta']['title'],
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::error( $post_id );
			}
		} elseif ( Unfold_Post_Type::TYPE !== get_post_type( $post_id ) ) {
			WP_CLI::error( "Post $post_id is not an Unfold project." );
		}

		$errors = Unfold_Store::save( $post_id, $raw );
		if ( $errors ) {
			foreach ( $errors as $e ) {
				WP_CLI::log( ' - ' . $e );
			}
			$state_label = 'publish' === get_post_status( $post_id ) ? 'public data was not updated' : 'not published';
			WP_CLI::error( "Saved raw text on project $post_id ($state_label): " . count( $errors ) . ' problem(s).' );
		}
		WP_CLI::success( "Project $post_id saved: " . get_permalink( $post_id ) );
	}

	private function read( $file ) {
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read $file" );
		}
		return file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
