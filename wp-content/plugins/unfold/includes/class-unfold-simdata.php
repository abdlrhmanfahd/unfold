<?php
/**
 * Recorded data for simulation templates, read from the project's own ZIP.
 *
 * Each subsystem's sim.data names a file in the ZIP and the columns to use. This class
 * extracts those columns, keeps at most MAX_POINTS rows, and caches the result in post
 * meta keyed by a hash of the sim blocks, so a page view never reopens the ZIP.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_SimData {

	const MAX_POINTS = 1800;
	const MAX_FILE   = 20 * 1048576;

	/** Subsystems that use a simulation template. */
	public static function template_parts( $data ) {
		return array_values(
			array_filter(
				$data['subsystems'] ?? array(),
				static function ( $s ) {
					return ! empty( $s['sim']['template'] );
				}
			)
		);
	}

	/** @return array subsystem id => list of { role, label, set, cols: { name: values[] }, error? } */
	public static function get( $post_id, $data ) {
		$parts = self::template_parts( $data );
		if ( ! $parts ) {
			return array();
		}
		$hash  = md5( wp_json_encode( wp_list_pluck( $parts, 'sim' ) ) . filemtime( __FILE__ ) );
		$cache = get_post_meta( $post_id, '_unfold_simdata', true );
		if ( is_array( $cache ) && ( $cache['hash'] ?? '' ) === $hash ) {
			return $cache['data'];
		}
		$out = self::build( $post_id, $parts );
		update_post_meta( $post_id, '_unfold_simdata', array( 'hash' => $hash, 'data' => $out ) );
		return $out;
	}

	private static function build( $post_id, $parts ) {
		$zip_path = Unfold_Workflow::zip_path( $post_id );
		$zip      = null;
		if ( $zip_path && class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
				$zip = null;
			}
		}
		$out = array();
		foreach ( $parts as $s ) {
			$streams = array();
			foreach ( $s['sim']['data'] ?? array() as $d ) {
				$item = array(
					'role'  => $d['role'],
					'label' => $d['label'] ?? null,
					'set'   => $d['set'] ?? array(),
					'cols'  => array(),
				);
				$csv = $zip ? self::read( $zip, $d['file'] ) : null;
				if ( null === $csv ) {
					$item['error'] = sprintf( 'File not found in the ZIP: %s', $d['file'] );
				} else {
					$item = array_merge( $item, self::columns( $csv, $d['columns'] ) );
				}
				$streams[] = $item;
			}
			$out[ $s['id'] ] = $streams;
		}
		if ( $zip ) {
			$zip->close();
		}
		return $out;
	}

	/** Reads one file from the ZIP by its path, or by its file name if the path is not exact. */
	private static function read( ZipArchive $zip, $name ) {
		$name = ltrim( str_replace( '\\', '/', (string) $name ), '/' );
		$idx  = $zip->locateName( $name );
		if ( false === $idx ) {
			$idx = $zip->locateName( basename( $name ), ZipArchive::FL_NODIR );
		}
		if ( false === $idx ) {
			return null;
		}
		$stat = $zip->statIndex( $idx );
		if ( ! $stat || $stat['size'] > self::MAX_FILE ) {
			return null;
		}
		$raw = $zip->getFromIndex( $idx );
		return false === $raw ? null : $raw;
	}

	/** Logs that hold several runs restart their clock; keep the longest run with increasing time. */
	private static function longest_run( $rows ) {
		$best  = array();
		$run   = array();
		$last  = null;
		foreach ( $rows as $row ) {
			if ( null !== $last && $row[0] < $last ) {
				if ( count( $run ) > count( $best ) ) {
					$best = $run;
				}
				$run = array();
			}
			$run[] = $row;
			$last  = $row[0];
		}
		return count( $run ) > count( $best ) ? $run : $best;
	}

	/** Picks columns from CSV text. Rows with t < 0 are dropped when the first column is time. */
	private static function columns( $csv, $wanted ) {
		$lines = preg_split( '/\r\n|\n|\r/', trim( $csv ) );
		$head  = str_getcsv( array_shift( $lines ) );
		$idx   = array();
		foreach ( $wanted as $c ) {
			$i = array_search( $c, $head, true );
			if ( false === $i ) {
				return array( 'error' => sprintf( 'Column "%s" is not in the file.', $c ) );
			}
			$idx[ $c ] = $i;
		}
		$time  = in_array( $wanted[0], array( 't', 'time', 'time_s' ), true );
		$rows  = array();
		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$r   = str_getcsv( $line );
			$row = array();
			foreach ( $idx as $c => $i ) {
				$v       = $r[ $i ] ?? '';
				$row[]   = is_numeric( $v ) ? (float) $v : null;
			}
			if ( $time && ( null === $row[0] || $row[0] < 0 ) ) {
				continue;
			}
			$rows[] = $row;
		}
		if ( $time ) {
			$rows = self::longest_run( $rows );
		}
		$step = max( 1, (int) ceil( count( $rows ) / self::MAX_POINTS ) );
		$cols = array_fill_keys( $wanted, array() );
		foreach ( $rows as $k => $row ) {
			if ( $k % $step ) {
				continue;
			}
			foreach ( $wanted as $j => $c ) {
				$cols[ $c ][] = null === $row[ $j ] ? null : round( $row[ $j ], 6 );
			}
		}
		return array( 'cols' => $cols );
	}
}
