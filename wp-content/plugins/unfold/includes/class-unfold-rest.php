<?php
/**
 * REST API (namespace unfold/v1).
 *
 * Portal routes use the normal WordPress cookie + X-WP-Nonce authentication.
 * Worker routes use a bearer token (Settings → Extraction worker token) and are the
 * only way the external AI extraction service talks to WordPress.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Rest {

	const NS = 'unfold/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$member   = static function () {
			return current_user_can( Unfold_Accounts::CAP_SUBMIT ) || current_user_can( Unfold_Accounts::CAP_REVIEW );
		};
		$reviewer = static function () {
			return current_user_can( Unfold_Accounts::CAP_REVIEW );
		};
		$worker   = array( __CLASS__, 'worker_auth' );
		$id_arg   = array( 'id' => array( 'validate_callback' => static function ( $v ) { return is_numeric( $v ); } ) );

		// Uploads (chunked).
		register_rest_route( self::NS, '/uploads', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload_start' ), 'permission_callback' => $member ) );
		register_rest_route( self::NS, '/uploads/(?P<uid>[a-f0-9]{32})', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'upload_chunk' ), 'permission_callback' => $member ) );
		register_rest_route( self::NS, '/uploads/(?P<uid>[a-f0-9]{32})/complete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload_complete' ), 'permission_callback' => $member ) );

		// Projects.
		register_rest_route( self::NS, '/projects/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'project_get' ), 'permission_callback' => $member, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/projects/(?P<id>\d+)/data', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'project_save' ), 'permission_callback' => $member, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/projects/(?P<id>\d+)/submit', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'project_submit' ), 'permission_callback' => $member, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/projects/(?P<id>\d+)/extract', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'project_extract' ), 'permission_callback' => $member, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/projects/(?P<id>\d+)/preview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'project_preview' ), 'permission_callback' => $member, 'args' => $id_arg ) );
		register_rest_route( self::NS, '/projects/(?P<id>\d+)/review', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'project_review' ), 'permission_callback' => $reviewer, 'args' => $id_arg ) );

		// Public engagement beacon.
		register_rest_route( self::NS, '/track', array( 'methods' => 'POST', 'callback' => array( 'Unfold_Stats', 'track' ), 'permission_callback' => '__return_true' ) );

		// Extraction worker.
		register_rest_route( self::NS, '/worker/next', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'worker_next' ), 'permission_callback' => $worker ) );
		register_rest_route( self::NS, '/worker/jobs/(?P<id>\d+)/zip', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'worker_zip' ), 'permission_callback' => $worker ) );
		register_rest_route( self::NS, '/worker/jobs/(?P<id>\d+)/result', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'worker_result' ), 'permission_callback' => $worker ) );
		register_rest_route( self::NS, '/worker/projects/(?P<id>\d+)/brief-score', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'worker_brief_score' ), 'permission_callback' => $worker ) );
	}

	/* ---------------------------------------------------------------------
	 * Uploads
	 * ------------------------------------------------------------------ */

	public static function upload_start( WP_REST_Request $r ) {
		$post_id = absint( $r['project'] ?? 0 );
		if ( $post_id && ! Unfold_Workflow::can_edit( $post_id ) ) {
			return new WP_Error( 'unfold_forbidden', __( 'You cannot replace the files of this project now.', 'unfold' ), array( 'status' => 403 ) );
		}
		return Unfold_Workflow::start_upload( (string) $r['name'], (int) $r['size'] );
	}

	public static function upload_chunk( WP_REST_Request $r ) {
		return Unfold_Workflow::put_chunk( $r['uid'], (int) $r->get_param( 'offset' ), $r->get_body() );
	}

	public static function upload_complete( WP_REST_Request $r ) {
		$id = Unfold_Workflow::finish_upload( $r['uid'], absint( $r['project'] ?? 0 ) );
		return is_wp_error( $id ) ? $id : self::project_payload( $id );
	}

	/* ---------------------------------------------------------------------
	 * Projects
	 * ------------------------------------------------------------------ */

	private static function guard( $id, $edit = false ) {
		if ( ! Unfold_Workflow::can_view( $id ) ) {
			return new WP_Error( 'unfold_forbidden', __( 'Not your project.', 'unfold' ), array( 'status' => 403 ) );
		}
		if ( $edit && ! Unfold_Workflow::can_edit( $id ) ) {
			return new WP_Error( 'unfold_locked', __( 'This project is with the instructor and cannot be edited right now.', 'unfold' ), array( 'status' => 409 ) );
		}
		return null;
	}

	public static function project_payload( $id ) {
		$raw   = Unfold_Store::get_raw( $id );
		$data  = json_decode( $raw, true );
		$job   = get_post_meta( $id, '_unfold_job', true );
		$man   = Unfold_Workflow::manifest( $id );
		$note  = Unfold_Workflow::last_review_note( $id );
		return array(
			'id'         => $id,
			'title'      => get_the_title( $id ),
			'state'      => Unfold_Workflow::state( $id ),
			'worker_online' => Unfold_Workflow::reader_available(),
			'now'        => time(),
			'typical_s'  => Unfold_Workflow::typical_read_seconds(),
			'editable'   => Unfold_Workflow::can_edit( $id ),
			'data'       => is_array( $data ) ? $data : null,
			'errors'     => Unfold_Store::get_errors( $id ),
			'valid'      => (bool) Unfold_Store::get( $id ) && ! Unfold_Store::get_errors( $id ),
			'job'        => is_array( $job ) ? array( 'error' => $job['error'] ?? null, 'queued_at' => $job['queued_at'] ?? null, 'claimed_at' => $job['claimed_at'] ?? null ) : null,
			'manifest'   => array( 'totals' => $man['totals'] ?? array(), 'count' => count( $man['files'] ?? array() ), 'reports' => array_values( array_map( static function ( $f ) { return $f['path']; }, array_filter( $man['files'] ?? array(), static function ( $f ) { return 'report' === $f['kind']; } ) ) ) ),
			'report'     => get_post_meta( $id, '_unfold_report', true ),
			'zip'        => get_post_meta( $id, '_unfold_zip_name', true ),
			'review'     => $note ? array( 'decision' => $note['decision'] ?? '', 'note' => $note['note'], 't' => $note['t'] ) : null,
			'url'        => 'approved' === Unfold_Workflow::state( $id ) ? get_permalink( $id ) : null,
			'attachments' => array_keys( Unfold_Render::attachments( $id ) ),
			'media_notes' => is_array( $data ) ? (object) Unfold_Workflow::media_notes( $id, $data ) : (object) array(),
			// File names from the ZIP, offered as suggestions in file fields.
			'files'      => array_values( array_unique( array_map( static function ( $f ) { return wp_basename( $f['path'] ); }, array_filter( $man['files'] ?? array(), static function ( $f ) { return empty( $f['blocked'] ); } ) ) ) ),
		);
	}

	public static function project_get( WP_REST_Request $r ) {
		$err = self::guard( (int) $r['id'] );
		return $err ?: self::project_payload( (int) $r['id'] );
	}

	public static function project_save( WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$err = self::guard( $id, true );
		if ( $err ) {
			return $err;
		}
		$data = $r->get_json_params()['data'] ?? null;
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'unfold_data', __( 'Missing project data.', 'unfold' ), array( 'status' => 400 ) );
		}
		// Review fields belong to the system, never to the form.
		$old            = json_decode( Unfold_Store::get_raw( $id ), true );
		$data['review'] = is_array( $old['review'] ?? null ) ? $old['review'] : array( 'instructor_approved' => false );
		if ( ! current_user_can( Unfold_Accounts::CAP_REVIEW ) ) {
			$data['review']['instructor_approved'] = false;
		}
		Unfold_Store::save( $id, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		if ( ! empty( $data['meta']['title'] ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( $data['meta']['title'] ) ) );
		}
		if ( 'failed' === Unfold_Workflow::state( $id ) ) {
			Unfold_Workflow::set_state( $id, 'review', __( 'Filled in by hand.', 'unfold' ) );
		}
		Unfold_Workflow::attach_referenced( $id, $data );
		return self::project_payload( $id );
	}

	public static function project_submit( WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$err = self::guard( $id, true );
		if ( $err ) {
			return $err;
		}
		$res = Unfold_Workflow::submit( $id );
		return is_wp_error( $res ) ? $res : self::project_payload( $id );
	}

	public static function project_extract( WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$err = self::guard( $id, true );
		if ( $err ) {
			return $err;
		}
		if ( ! Unfold_Workflow::zip_path( $id ) ) {
			return new WP_Error( 'unfold_nozip', __( 'Upload the project ZIP first.', 'unfold' ), array( 'status' => 400 ) );
		}
		$report = (string) ( $r['report'] ?? '' );
		if ( $report ) {
			$paths = wp_list_pluck( Unfold_Workflow::manifest( $id )['files'], 'path' );
			if ( ! in_array( $report, $paths, true ) ) {
				return new WP_Error( 'unfold_report', __( 'That report is not in the ZIP.', 'unfold' ), array( 'status' => 400 ) );
			}
			update_post_meta( $id, '_unfold_report', $report );
		}
		Unfold_Workflow::queue_extraction( $id );
		return self::project_payload( $id );
	}

	public static function project_preview( WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$err = self::guard( $id );
		if ( $err ) {
			return $err;
		}
		$data = Unfold_Store::get( $id );
		if ( ! $data ) {
			return new WP_Error( 'unfold_invalid', __( 'There is no valid data to preview yet.', 'unfold' ), array( 'status' => 400 ) );
		}
		return array( 'html' => Unfold_Render::project_html( $id, $data, true ) );
	}

	public static function project_review( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( Unfold_Post_Type::TYPE !== get_post_type( $id ) ) {
			return new WP_Error( 'unfold_missing', __( 'Project not found.', 'unfold' ), array( 'status' => 404 ) );
		}
		$res = Unfold_Workflow::review( $id, sanitize_key( (string) $r['decision'] ), sanitize_textarea_field( (string) $r['note'] ) );
		return is_wp_error( $res ) ? $res : self::project_payload( $id );
	}

	/* ---------------------------------------------------------------------
	 * Worker
	 * ------------------------------------------------------------------ */

	public static function worker_auth( WP_REST_Request $r ) {
		$h = (string) $r->get_header( 'authorization' );
		return 0 === stripos( $h, 'Bearer ' ) && Unfold_Settings::worker_token_ok( trim( substr( $h, 7 ) ) );
	}

	public static function worker_next() {
		update_option( 'unfold_worker_seen', time(), false ); // Heartbeat: the worker polls every few seconds.
		$claim = Unfold_Workflow::claim_job();
		if ( ! $claim ) {
			return new WP_REST_Response( null, 204 );
		}
		$id  = $claim['post_id'];
		$man = Unfold_Workflow::manifest( $id );
		return array(
			'project_id' => $id,
			'job_id'     => $claim['job']['id'],
			'report'     => get_post_meta( $id, '_unfold_report', true ),
			'files'      => $man['files'],
			'zip_url'    => rest_url( self::NS . '/worker/jobs/' . $id . '/zip' ),
			'schema'     => Unfold_Validator::schema(),
		);
	}

	/**
	 * The AI reader's "how well does this project match the instructor's brief" score (0-100).
	 * Kept beside the project (post meta), not in the project document, and never editable by the owner.
	 */
	public static function worker_brief_score( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( Unfold_Post_Type::TYPE !== get_post_type( $id ) ) {
			return new WP_Error( 'unfold_project', 'No such project.', array( 'status' => 404 ) );
		}
		$b    = $r->get_json_params();
		$keep = static function ( $v, $n = 600 ) {
			return mb_substr( sanitize_text_field( (string) $v ), 0, $n );
		};
		$items = array();
		foreach ( (array) ( $b['items'] ?? array() ) as $it ) {
			$v = (string) ( $it['verdict'] ?? '' );
			if ( ! in_array( $v, array( 'met', 'partial', 'not_met' ), true ) ) {
				continue;
			}
			$items[] = array(
				'text'     => $keep( $it['text'] ?? '', 400 ),
				'source'   => $keep( $it['source'] ?? '', 160 ),
				'weight'   => max( 0, (float) ( $it['weight'] ?? 1 ) ),
				'verdict'  => $v,
				'evidence' => $keep( $it['evidence'] ?? '', 600 ),
				'where'    => $keep( $it['where'] ?? '', 160 ),
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'unfold_score', 'No checklist.', array( 'status' => 400 ) );
		}
		// The number is worked out here from the verdicts, never taken from the model.
		$tot = 0;
		$got = 0;
		foreach ( $items as $it ) {
			$tot += $it['weight'];
			$got += $it['weight'] * ( 'met' === $it['verdict'] ? 1 : ( 'partial' === $it['verdict'] ? 0.5 : 0 ) );
		}
		$score = array(
			'score'   => $tot > 0 ? (int) round( 100 * $got / $tot ) : 0,
			'items'   => $items,
			'briefs'  => array_map( $keep, array_slice( (array) ( $b['briefs'] ?? array() ), 0, 4 ) ),
			'model'   => $keep( $b['model'] ?? '', 80 ),
			'version' => $keep( $b['version'] ?? '', 20 ),
			'at'      => time(),
		);
		update_post_meta( $id, '_unfold_brief_score', $score );
		Unfold_Workflow::log( $id, 'brief_scored', (string) $score['score'] );
		return array( 'ok' => true, 'score' => $score['score'] );
	}

	public static function worker_zip( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Unfold_Workflow::job_matches( $id, (string) $r->get_param( 'job' ) ) ) {
			return new WP_Error( 'unfold_job', 'Job not active.', array( 'status' => 409 ) );
		}
		$file = Unfold_Workflow::zip_path( $id );
		if ( ! $file ) {
			return new WP_Error( 'unfold_nozip', 'ZIP missing.', array( 'status' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function worker_result( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$body = $r->get_json_params();
		if ( ! Unfold_Workflow::job_matches( $id, (string) ( $body['job_id'] ?? '' ) ) ) {
			return new WP_Error( 'unfold_job', 'Job not active.', array( 'status' => 409 ) );
		}
		if ( ! empty( $body['error'] ) ) {
			Unfold_Workflow::fail_job( $id, (string) $body['error'] );
			return array( 'ok' => true );
		}
		if ( ! is_array( $body['data'] ?? null ) ) {
			Unfold_Workflow::fail_job( $id, 'Worker returned no data.' );
			return new WP_Error( 'unfold_data', 'No data.', array( 'status' => 400 ) );
		}
		$res = Unfold_Workflow::complete_job( $id, $body['data'], (array) ( $body['meta'] ?? array() ) );
		return array( 'ok' => true, 'schema_errors' => $res['errors'], 'attached' => $res['attached'] );
	}
}
