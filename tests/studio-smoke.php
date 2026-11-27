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

$sample_path = __DIR__ . '/fixtures/valid-project.json';
$raw         = file_get_contents( $sample_path );
if ( false === $raw ) {
	fwrite( STDERR, "FAIL: Could not read {$sample_path}\n" );
	exit( 1 );
}

$result = Unfold_Validator::validate_raw( $raw );
if ( ! empty( $result['errors'] ) || ! is_array( $result['data'] ) ) {
	fwrite( STDERR, "FAIL: Sample JSON validation failed:\n" . implode( "\n", $result['errors'] ) . "\n" );
	exit( 1 );
}

echo "OK: Unfold Studio smoke test passed.\n";
