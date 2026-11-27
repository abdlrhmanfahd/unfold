<?php

use PHPUnit\Framework\TestCase;

final class SimDataTest extends TestCase {

	public function test_template_parts_filters_subsystems_with_template(): void {
		$data = array(
			'subsystems' => array(
				array(
					'id'  => 'controller',
					'sim' => array( 'template' => 'pi_speed' ),
				),
				array(
					'id'  => 'planner',
					'sim' => array( 'type' => 'static' ),
				),
				array(
					'id' => 'allocator',
				),
			),
		);

		$parts = Unfold_SimData::template_parts( $data );

		$this->assertCount( 1, $parts );
		$this->assertSame( 'controller', $parts[0]['id'] );
	}

	public function test_columns_parses_csv_and_drops_negative_time(): void {
		$method = new ReflectionMethod( Unfold_SimData::class, 'columns' );

		$csv = implode(
			"\n",
			array(
				't,speed,ref',
				'-0.5,0.0,1.0',
				'0.0,0.1,1.0',
				'0.1,0.5,1.0',
				// Clock restart (shorter run of 1 point vs 2 points, then longer run of 3 points).
				'0.0,0.2,1.0',
				'0.1,0.4,1.0',
				'0.2,0.8,1.0',
			)
		);

		$parsed = $method->invoke( null, $csv, array( 't', 'speed' ) );

		$this->assertArrayHasKey( 'cols', $parsed );
		$this->assertSame( array( 0.0, 0.1, 0.2 ), $parsed['cols']['t'] );
		$this->assertSame( array( 0.2, 0.4, 0.8 ), $parsed['cols']['speed'] );

		$missing = $method->invoke( null, $csv, array( 't', 'nonexistent' ) );
		$this->assertArrayHasKey( 'error', $missing );
		$this->assertStringContainsString( 'nonexistent', $missing['error'] );
	}

	public function test_columns_strips_utf8_bom_and_handles_empty_wanted(): void {
		$method = new ReflectionMethod( Unfold_SimData::class, 'columns' );

		$bomCsv = "\xEF\xBB\xBFt,speed\n0.0,1.25\n0.5,2.5\n";
		$parsed = $method->invoke( null, $bomCsv, array( 't', 'speed' ) );

		$this->assertArrayHasKey( 'cols', $parsed );
		$this->assertSame( array( 0.0, 0.5 ), $parsed['cols']['t'] );
		$this->assertSame( array( 1.25, 2.5 ), $parsed['cols']['speed'] );

		$empty = $method->invoke( null, $bomCsv, array() );
		$this->assertSame( array( 'cols' => array() ), $empty );
	}

	public function test_read_streams_from_zip_and_handles_missing_entries(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ufzip' );
		$this->assertIsString( $tmp );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $tmp, ZipArchive::OVERWRITE ) === true );
		$zip->addFromString( 'logs/run.csv', "t,speed\n0,0.5\n" );
		$zip->close();

		$read = new ReflectionMethod( Unfold_SimData::class, 'read' );
		$reader = new ZipArchive();
		$this->assertTrue( $reader->open( $tmp, ZipArchive::RDONLY ) === true );

		try {
			$this->assertSame( "t,speed\n0,0.5\n", $read->invoke( null, $reader, 'logs/run.csv' ) );
			$this->assertSame( "t,speed\n0,0.5\n", $read->invoke( null, $reader, 'run.csv' ) );
			$this->assertNull( $read->invoke( null, $reader, 'missing.csv' ) );
		} finally {
			$reader->close();
			@unlink( $tmp );
		}
	}
}
