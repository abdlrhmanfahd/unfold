<?php

use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

	private function sampleJson(): string {
		$path = dirname( __DIR__ ) . '/fixtures/valid-project.json';
		$raw  = file_get_contents( $path );
		$this->assertIsString( $raw );
		return $raw;
	}

	public function test_valid_sample_passes(): void {
		$result = Unfold_Validator::validate_raw( $this->sampleJson() );

		$this->assertSame( array(), $result['errors'] );
		$this->assertIsArray( $result['data'] );
		$this->assertSame( 'Autonomous Warehouse Fleet Management', $result['data']['meta']['title'] );
	}

	public function test_empty_json_rejected(): void {
		$result = Unfold_Validator::validate_raw( "   \n\t  " );

		$this->assertNull( $result['data'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_oversized_json_rejected(): void {
		$oversized = str_repeat( 'a', Unfold_Validator::MAX_BYTES + 1 );
		$result    = Unfold_Validator::validate_raw( $oversized );

		$this->assertNull( $result['data'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( '1 MB', $result['errors'][0] );
	}

	public function test_invalid_syntax_reports_helpful_error(): void {
		$trailingComma = '{"schema_version": "0.1",}';
		$res1          = Unfold_Validator::validate_raw( $trailingComma );
		$this->assertNull( $res1['data'] );
		$this->assertNotEmpty( $res1['errors'] );
		$this->assertStringContainsString( 'trailing comma', $res1['errors'][0] );

		$singleQuotes = "{'schema_version': '0.1'}";
		$res2         = Unfold_Validator::validate_raw( $singleQuotes );
		$this->assertNull( $res2['data'] );
		$this->assertNotEmpty( $res2['errors'] );
		$this->assertStringContainsString( 'double quotes', $res2['errors'][0] );
	}

	public function test_non_object_top_level_rejected(): void {
		$result = Unfold_Validator::validate_raw( '[1, 2, 3]' );

		$this->assertNull( $result['data'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'JSON object', $result['errors'][0] );
	}

	public function test_utf8_bom_stripped(): void {
		$withBom = "\xEF\xBB\xBF" . $this->sampleJson();
		$result  = Unfold_Validator::validate_raw( $withBom );

		$this->assertSame( array(), $result['errors'] );
		$this->assertIsArray( $result['data'] );
	}

	public function test_missing_required_fields_rejected(): void {
		$result = Unfold_Validator::validate_raw( '{}' );

		$this->assertNull( $result['data'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_shipped_sample_warehouse_fleet_passes(): void {
		$raw = file_get_contents( UNFOLD_DIR . 'sample/warehouse-fleet.json' );
		$this->assertIsString( $raw );

		$result = Unfold_Validator::validate_raw( $raw );

		$this->assertSame( array(), $result['errors'] );
		$this->assertIsArray( $result['data'] );
	}
}
