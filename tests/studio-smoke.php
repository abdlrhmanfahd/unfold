<?php
/**
 * WordPress Studio integration smoke test.
 * Run with: studio wp eval-file tests/studio-smoke.php
 */

$required_classes = array(
	'Unfold_Validator',
	'Unfold_Store',
	'Unfold_Post_Type',
	'Unfold_Rest',
	'Unfold_SimData',
);

foreach ( $required_classes as $class ) {
	if ( ! class_exists( $class ) ) {
		fwrite( STDERR, "FAIL: Missing class {$class} (is the Unfold plugin active?)\n" );
		exit( 1 );
	}
}

if ( ! post_type_exists( 'unfold_project' ) ) {
	fwrite( STDERR, "FAIL: Post type unfold_project is not registered.\n" );
	exit( 1 );
}

foreach ( array( __DIR__ . '/fixtures/valid-project.json', UNFOLD_DIR . 'sample/warehouse-fleet.json' ) as $sample_path ) {
	$raw = file_get_contents( $sample_path );
	if ( false === $raw ) {
		fwrite( STDERR, "FAIL: Could not read {$sample_path}\n" );
		exit( 1 );
	}

	$result = Unfold_Validator::validate_raw( $raw );
	if ( ! empty( $result['errors'] ) || ! is_array( $result['data'] ) ) {
		fwrite( STDERR, "FAIL: JSON validation failed for {$sample_path}:\n" . implode( "\n", $result['errors'] ) . "\n" );
		exit( 1 );
	}
}

do_shortcode( '[unfold_featured]' );
do_shortcode( '[unfold_facts]' );
do_shortcode( '[unfold_gallery]' );

// Full project lifecycle integration test (cleaned up in finally).
require_once ABSPATH . 'wp-admin/includes/user.php';
Unfold_Accounts::maybe_install_roles();
$fixture_raw  = file_get_contents( __DIR__ . '/fixtures/valid-project.json' );
$fixture_data = json_decode( $fixture_raw, true );
$student_id   = wp_insert_user(
	array(
		'user_login' => 'smoke_student_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 12 ),
		'user_email' => 'smoke_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'unfold_member',
	)
);
$lead_id      = wp_insert_user(
	array(
		'user_login' => 'smoke_lead_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 12 ),
		'user_email' => 'smoke_lead_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'unfold_lead_instructor',
	)
);
$inst_a_id    = wp_insert_user(
	array(
		'user_login' => 'smoke_inst_a_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 12 ),
		'user_email' => 'smoke_insta_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'unfold_instructor',
	)
);
$inst_b_id    = wp_insert_user(
	array(
		'user_login' => 'smoke_inst_b_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 12 ),
		'user_email' => 'smoke_instb_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'unfold_instructor',
	)
);
if ( is_wp_error( $student_id ) || ! $student_id || is_wp_error( $lead_id ) || ! $lead_id || is_wp_error( $inst_a_id ) || ! $inst_a_id || is_wp_error( $inst_b_id ) || ! $inst_b_id ) {
	fwrite( STDERR, "FAIL: Could not create temporary smoke users.\n" );
	exit( 1 );
}
update_user_meta( $student_id, 'unfold_email_verified', '1' );
$tmp_id = wp_insert_post(
	array(
		'post_type'   => Unfold_Post_Type::TYPE,
		'post_status' => 'draft',
		'post_title'  => 'Smoke Integration Project',
		'post_author' => $student_id,
	)
);
$inst_tmp_id = wp_insert_post(
	array(
		'post_type'   => Unfold_Post_Type::TYPE,
		'post_status' => 'draft',
		'post_title'  => 'Smoke Instructor Project',
		'post_author' => $inst_a_id,
	)
);
if ( is_wp_error( $tmp_id ) || ! $tmp_id || is_wp_error( $inst_tmp_id ) || ! $inst_tmp_id ) {
	wp_delete_user( $student_id );
	fwrite( STDERR, "FAIL: Could not create temporary integration post.\n" );
	exit( 1 );
}

try {
	Unfold_Workflow::queue_extraction( $tmp_id );
	if ( 'queued' !== Unfold_Workflow::state( $tmp_id ) ) {
		throw new RuntimeException( 'Expected state queued after queue_extraction.' );
	}

	$claim = Unfold_Workflow::claim_job();
	if ( ! is_array( $claim ) || (int) $claim['post_id'] !== (int) $tmp_id ) {
		throw new RuntimeException( 'Expected claim_job to claim temporary project.' );
	}

	$prog_req = new WP_REST_Request( 'POST', '/unfold/v1/worker/jobs/' . $tmp_id . '/progress' );
	$prog_req['id'] = $tmp_id;
	$prog_req->set_header( 'Content-Type', 'application/json' );
	$prog_req->set_body( wp_json_encode( array( 'job_id' => $claim['job']['id'], 'stage' => 'ai', 'label' => 'Testing progress', 'pct' => 62, 'chars' => 500 ) ) );
	$prog_res = Unfold_Rest::worker_progress( $prog_req );
	if ( is_wp_error( $prog_res ) || empty( $prog_res['ok'] ) ) {
		throw new RuntimeException( 'Expected worker_progress to succeed.' );
	}
	$payload = Unfold_Rest::project_payload( $tmp_id );
	if ( 'ai' !== ( $payload['job']['progress']['stage'] ?? '' ) || 62 !== (int) ( $payload['job']['progress']['pct'] ?? 0 ) ) {
		throw new RuntimeException( 'Expected project_payload to expose live job progress.' );
	}

	$score_req = new WP_REST_Request( 'POST', '/unfold/v1/worker/projects/' . $tmp_id . '/brief-score' );
	$score_req['id'] = $tmp_id;
	$score_req->set_header( 'Content-Type', 'application/json' );
	$score_req->set_body( wp_json_encode( array(
		'items'  => array(
			array( 'text' => 'Req 1', 'weight' => 2, 'verdict' => 'met' ),
			array( 'text' => 'Req 2', 'weight' => 1, 'verdict' => 'partial' ),
			array( 'text' => 'Req 3', 'weight' => 1, 'verdict' => 'not_met' ),
		),
		'briefs' => array( 'brief.pdf' ),
		'model'  => 'gemini-2.5-flash',
	) ) );
	$score_res = Unfold_Rest::worker_brief_score( $score_req );
	if ( is_wp_error( $score_res ) || 63 !== (int) ( $score_res['score'] ?? -1 ) ) {
		throw new RuntimeException( 'Expected weighted brief score 63.' );
	}

	// Complete extraction with a soft v0.2 question warning and verify submit & publish remain non-blocking.
	$soft_data                      = $fixture_data;
	$soft_data['schema_version']    = '0.2';
	$soft_data['meta']['question']  = str_repeat( 'q', 95 );
	Unfold_Workflow::complete_job( $tmp_id, $soft_data, array( 'model' => 'smoke-test', 'usage' => array( 'output_tokens' => 42 ) ) );
	if ( 'review' !== Unfold_Workflow::state( $tmp_id ) ) {
		throw new RuntimeException( 'Expected state review after complete_job.' );
	}
	if ( true !== Unfold_Workflow::submit( $tmp_id, $inst_a_id ) || 'submitted' !== Unfold_Workflow::state( $tmp_id ) ) {
		throw new RuntimeException( 'Expected student project submit to enter submitted state.' );
	}
	if ( ! Unfold_Workflow::can_review( $tmp_id, $inst_a_id ) || Unfold_Workflow::can_review( $tmp_id, $inst_b_id ) || ! Unfold_Workflow::can_review( $tmp_id, $lead_id ) ) {
		throw new RuntimeException( 'Expected assigned reviewer scoping to allow assigned/lead and block peer instructor.' );
	}

	// Regular instructor B cannot see or approve student project assigned to instructor A.
	wp_set_current_user( $inst_b_id );
	$b_overview = Unfold_Stats::overview();
	if ( in_array( $tmp_id, wp_list_pluck( $b_overview['queue'], 'id' ), true ) ) {
		throw new RuntimeException( 'Expected peer instructor queue to exclude submissions assigned to another instructor.' );
	}
	if ( ! is_wp_error( Unfold_Workflow::review( $tmp_id, 'approve', 'Unauthorized' ) ) ) {
		throw new RuntimeException( 'Expected peer instructor approval on another instructor submission to error.' );
	}

	// Role delegation checks: regular instructor blocked; Lead Instructor can promote & demote verified member.
	if ( ! is_wp_error( Unfold_Accounts::set_portal_role( $inst_a_id, $student_id, 'unfold_instructor' ) ) ) {
		throw new RuntimeException( 'Expected regular instructor role grant to be rejected.' );
	}
	$promoted = Unfold_Accounts::set_portal_role( $lead_id, $student_id, 'unfold_instructor' );
	if ( is_wp_error( $promoted ) || 'instructor' !== ( $promoted['role'] ?? '' ) ) {
		throw new RuntimeException( 'Expected Lead Instructor to grant instructor role to verified member.' );
	}
	if ( ! is_wp_error( Unfold_Accounts::set_portal_role( $lead_id, $student_id, 'unfold_lead_instructor' ) ) ) {
		throw new RuntimeException( 'Expected non-admin Lead Instructor to be blocked from creating another Lead Instructor.' );
	}
	$demoted = Unfold_Accounts::set_portal_role( $lead_id, $student_id, 'unfold_member' );
	if ( is_wp_error( $demoted ) || 'member' !== ( $demoted['role'] ?? '' ) ) {
		throw new RuntimeException( 'Expected Lead Instructor to revert role back to member.' );
	}

	wp_set_current_user( $inst_a_id );
	$a_overview = Unfold_Stats::overview();
	if ( ! in_array( $tmp_id, wp_list_pluck( $a_overview['queue'], 'id' ), true ) ) {
		throw new RuntimeException( 'Expected assigned instructor queue to include assigned submission.' );
	}
	$empty_changes = Unfold_Workflow::review( $tmp_id, 'changes', '   ' );
	if ( ! is_wp_error( $empty_changes ) ) {
		throw new RuntimeException( 'Expected changes review without note to error.' );
	}
	if ( true !== Unfold_Workflow::review( $tmp_id, 'approve', 'Smoke approved' ) || 'approved' !== Unfold_Workflow::state( $tmp_id ) ) {
		throw new RuntimeException( 'Expected non-blocking publish approval to succeed.' );
	}

	// Instructor-authored projects must skip pending instructor review and publish directly.
	wp_set_current_user( $inst_a_id );
	Unfold_Workflow::queue_extraction( $inst_tmp_id );
	Unfold_Workflow::complete_job( $inst_tmp_id, $soft_data, array( 'model' => 'smoke-test', 'usage' => array( 'output_tokens' => 42 ) ) );
	$inst_payload = Unfold_Rest::project_payload( $inst_tmp_id );
	if ( empty( $inst_payload['direct_publish'] ) ) {
		throw new RuntimeException( 'Expected direct_publish=true for instructor-authored project.' );
	}
	if ( true !== Unfold_Workflow::submit( $inst_tmp_id ) || 'approved' !== Unfold_Workflow::state( $inst_tmp_id ) || 'publish' !== get_post_status( $inst_tmp_id ) ) {
		throw new RuntimeException( 'Expected instructor-authored project to publish directly without pending instructor review.' );
	}
	if ( Unfold_Workflow::can_review( $inst_tmp_id, $inst_b_id ) ) {
		throw new RuntimeException( 'Expected peer instructor to be blocked from moderating another instructor upload.' );
	}
	wp_set_current_user( 0 );

	$html = Unfold_Render::project_html( $tmp_id, Unfold_Store::get( $tmp_id ), false );
	if ( false === strpos( $html, 'Autonomous Warehouse Fleet Management' ) ) {
		throw new RuntimeException( 'Expected rendered project HTML to include title.' );
	}
} finally {
	wp_set_current_user( 0 );
	wp_delete_post( $tmp_id, true );
	wp_delete_post( $inst_tmp_id, true );
	wp_delete_user( $student_id );
	wp_delete_user( $lead_id );
	wp_delete_user( $inst_a_id );
	wp_delete_user( $inst_b_id );
}

echo "OK: Unfold Studio smoke test passed.\n";
