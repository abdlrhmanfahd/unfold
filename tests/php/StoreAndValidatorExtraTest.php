<?php

use PHPUnit\Framework\TestCase;

final class StoreAndValidatorExtraTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['unfold_test_post_meta'] = array();
	}

	private function v02Base(): array {
		$raw = file_get_contents( UNFOLD_DIR . 'sample/warehouse-fleet.json' );
		$this->assertIsString( $raw );
		$data                   = json_decode( $raw, true );
		$data['schema_version'] = '0.2';
		return $data;
	}

	public function test_v02_rejects_overlong_question_and_dangling_references(): void {
		$data                       = $this->v02Base();
		$data['meta']['question']   = str_repeat( 'a', 95 );
		$data['data_files'][]       = array( 'file' => 'extra.csv', 'subsystem' => 'nonexistent_sub', 'format' => 'csv', 'columns' => array() );
		$data['results']['media'][] = array( 'file' => 'fig.png', 'caption' => 'C', 'part' => 'ghost_part' );

		$res = Unfold_Validator::validate_raw( json_encode( $data ) );
		$all = implode( "\n", $res['errors'] );
		$this->assertNotEmpty( $res['errors'] );
		$this->assertStringContainsString( 'at most 90 characters', $all );
		$this->assertStringContainsString( 'nonexistent_sub', $all );
		$this->assertStringContainsString( 'ghost_part', $all );
	}

	public function test_store_save_preserves_raw_and_valid_snapshot(): void {
		$valid = file_get_contents( dirname( __DIR__ ) . '/fixtures/valid-project.json' );
		$this->assertIsString( $valid );

		$errs = Unfold_Store::save( 500, $valid );
		$this->assertSame( array(), $errs );
		$this->assertNotNull( Unfold_Store::get( 500 ) );
		$this->assertSame( array(), Unfold_Store::get_errors( 500 ) );

		// Saving invalid data preserves raw draft while recording errors and keeping last valid JSON.
		$bad   = '{"schema_version":"0.2","meta":{"title":"Broken"}}';
		$errs2 = Unfold_Store::save( 500, $bad );
		$this->assertNotEmpty( $errs2 );
		$this->assertSame( $bad, Unfold_Store::get_raw( 500 ) );
		$this->assertSame( $errs2, Unfold_Store::get_errors( 500 ) );
		$this->assertSame( 'Autonomous Warehouse Fleet Management', Unfold_Store::get( 500 )['meta']['title'] );
	}
}
