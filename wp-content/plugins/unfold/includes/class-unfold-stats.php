<?php
/**
 * Statistics for the instructor dashboard, all computed from data stored in
 * WordPress (no third-party analytics):
 *  - projects waiting, returned and published; typical time to a decision
 *  - page views and simulation uses per day (anonymous counters)
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Stats {

	/** Anonymous public counters. One view per page load; one sim use per frame per page load. */
	public static function track( WP_REST_Request $r ) {
		$id   = absint( $r['project'] );
		$kind = sanitize_key( (string) $r['kind'] );
		if ( ! $id || 'publish' !== get_post_status( $id ) || Unfold_Post_Type::TYPE !== get_post_type( $id ) || ! in_array( $kind, array( 'view', 'sim' ), true ) ) {
			return new WP_REST_Response( null, 204 );
		}
		// Do not count the owner's or instructors' own visits.
		if ( is_user_logged_in() && ( Unfold_Workflow::is_owner( $id ) || current_user_can( Unfold_Accounts::CAP_REVIEW ) ) ) {
			return new WP_REST_Response( null, 204 );
		}
		$ua = strtolower( (string) $r->get_header( 'user_agent' ) );
		if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|headless|preview/', $ua ) ) {
			return new WP_REST_Response( null, 204 );
		}
		$key   = 'view' === $kind ? '_unfold_views' : '_unfold_sim_uses';
		update_post_meta( $id, $key, (int) get_post_meta( $id, $key, true ) + 1 );
		$daily = get_post_meta( $id, '_unfold_daily', true );
		$daily = is_array( $daily ) ? $daily : array();
		$day   = gmdate( 'Y-m-d' );
		$daily[ $day ][ $kind ] = ( $daily[ $day ][ $kind ] ?? 0 ) + 1;
		ksort( $daily );
		update_post_meta( $id, '_unfold_daily', array_slice( $daily, -90, null, true ) );
		return new WP_REST_Response( null, 204 );
	}

	/** Data quality of one project: tracked values confirmed / missing, simulations. */
	public static function quality( $data ) {
		$q = array( 'tracked' => 0, 'confirmed' => 0, 'missing' => 0, 'sims' => 0, 'data_backed' => 0 );
		if ( ! is_array( $data ) ) {
			return $q;
		}
		$walk = static function ( $node ) use ( &$walk, &$q ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( array_key_exists( 'value', $node ) && array_key_exists( 'status', $node ) ) {
				$q['tracked']++;
				if ( 'confirmed' === $node['status'] || 'missing_confirmed' === $node['status'] ) {
					$q['confirmed']++;
				} elseif ( null === $node['value'] ) {
					$q['missing']++;
				}
			}
			foreach ( $node as $child ) {
				$walk( $child );
			}
		};
		$walk( $data );
		foreach ( $data['subsystems'] ?? array() as $s ) {
			if ( ! empty( $s['sim']['template'] ) ) {
				$q['sims']++;
				if ( 'data_backed' === ( $s['sim']['type'] ?? '' ) ) {
					$q['data_backed']++;
				}
			}
		}
		$q['pct'] = $q['tracked'] ? (int) round( 100 * $q['confirmed'] / $q['tracked'] ) : 0;
		return $q;
	}

	/** When the project is waiting since (if submitted) and how long each past decision took. */
	private static function timings( $events ) {
		$submitted_at = null;
		$waits        = array();
		foreach ( $events as $e ) {
			if ( 'state' === $e['action'] && 'submitted' === ( $e['to'] ?? '' ) ) {
				$submitted_at = $e['t'];
			}
			if ( 'review' === $e['action'] && in_array( $e['decision'] ?? '', array( 'approve', 'changes' ), true ) && $submitted_at ) {
				$waits[]      = $e['t'] - $submitted_at;
				$submitted_at = null;
			}
		}
		return array( 'pending_since' => $submitted_at, 'waits' => $waits );
	}

	/** Last $n days as Y-m-d keys, oldest first. */
	private static function day_keys( $n = 30 ) {
		$out = array();
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$out[] = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		}
		return $out;
	}

	/** Everything the instructor overview shows, in one pass over all projects. */
	public static function overview() {
		$ids   = get_posts( array( 'post_type' => Unfold_Post_Type::TYPE, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'numberposts' => -1, 'fields' => 'ids' ) );
		$days  = self::day_keys();
		$daily = array_fill_keys( $days, array( 'view' => 0, 'sim' => 0 ) );
		$rows  = array();
		$waits = array();
		$now   = time();

		foreach ( $ids as $id ) {
			$state  = Unfold_Workflow::state( $id );
			$t      = self::timings( Unfold_Workflow::events( $id ) );
			$author = get_userdata( (int) get_post_field( 'post_author', $id ) );
			$counts = get_post_meta( $id, '_unfold_daily', true );
			$counts = is_array( $counts ) ? $counts : array();
			$views  = array();
			foreach ( $days as $d ) {
				$views[]                = (int) ( $counts[ $d ]['view'] ?? 0 );
				$daily[ $d ]['view']   += (int) ( $counts[ $d ]['view'] ?? 0 );
				$daily[ $d ]['sim']    += (int) ( $counts[ $d ]['sim'] ?? 0 );
			}
			$waits  = array_merge( $waits, $t['waits'] );
			$rows[] = array(
				'id'          => $id,
				'title'       => get_the_title( $id ),
				'state'       => $state,
				'owner'       => $author ? $author->display_name : '—',
				'waiting'     => 'submitted' === $state && $t['pending_since'] ? $now - $t['pending_since'] : null,
				'modified'    => (int) get_post_timestamp( $id, 'modified' ),
				'daily_views' => $views,
				'sim_uses'    => (int) get_post_meta( $id, '_unfold_sim_uses', true ),
				'url'         => 'approved' === $state ? get_permalink( $id ) : null,
			);
		}

		sort( $waits );
		$n      = count( $waits );
		$median = $n ? ( $n % 2 ? $waits[ intdiv( $n, 2 ) ] : ( $waits[ $n / 2 - 1 ] + $waits[ $n / 2 ] ) / 2 ) : null;
		$queue  = array_values( array_filter( $rows, static function ( $r ) { return 'submitted' === $r['state']; } ) );
		usort( $queue, static function ( $a, $b ) { return $b['waiting'] <=> $a['waiting']; } );
		$count  = static function ( $state ) use ( $rows ) {
			return count( array_filter( $rows, static function ( $r ) use ( $state ) { return $state === $r['state']; } ) );
		};

		$series = array();
		foreach ( $daily as $d => $v ) {
			$series[] = array( 'date' => $d, 'view' => $v['view'], 'sim' => $v['sim'] );
		}
		return array(
			'rows'  => $rows,
			'queue' => $queue,
			'daily' => $series,
			'kpi'   => array(
				'total'       => count( $rows ),
				'waiting'     => count( $queue ),
				'oldest_wait' => $queue ? $queue[0]['waiting'] : null,
				'returned'    => $count( 'changes' ),
				'published'   => $count( 'approved' ),
				'median_wait' => $median,
				'decisions'   => $n,
			),
		);
	}

	public static function human_duration( $seconds ) {
		if ( null === $seconds ) {
			return '—';
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			return sprintf( _n( '%d min', '%d min', max( 1, (int) round( $seconds / 60 ) ), 'unfold' ), max( 1, (int) round( $seconds / 60 ) ) );
		}
		if ( $seconds < 2 * DAY_IN_SECONDS ) {
			$h = (int) round( $seconds / HOUR_IN_SECONDS );
			return sprintf( _n( '%d hour', '%d hours', $h, 'unfold' ), $h );
		}
		$d = (int) round( $seconds / DAY_IN_SECONDS );
		return sprintf( _n( '%d day', '%d days', $d, 'unfold' ), $d );
	}
}
