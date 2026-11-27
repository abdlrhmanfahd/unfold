<?php
/**
 * Turns validated project JSON into HTML. All output is escaped here.
 *
 * Page order tells the project's story: question → answer → try it → how it
 * works → evidence → limits.
 *
 * Status markers ("never guess" rule), kept deliberately quiet:
 *  - amber dot after a value  = taken from the report, not confirmed yet
 *  - red "Not given"          = the report does not give this value
 * A single legend near the top explains both, and lists everything missing.
 *
 * Text may contain inline LaTeX between $...$; it is rendered client-side by KaTeX.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Render {

	/** Display labels for schema enums. Kept in one place so the schema can evolve. */
	const LABELS = array(
		'mode'      => array(
			'qlabs_sim'      => 'QLabs simulation',
			'physical_qcar2' => 'Physical QCar 2',
			'both'           => 'QLabs + physical QCar 2',
		),
		'stack'     => array(
			'python'          => 'Python',
			'matlab_simulink' => 'MATLAB / Simulink',
			'ros2'            => 'ROS 2',
			'other'           => 'Other',
		),
		'sensor'    => array(
			'lidar'         => 'LiDAR',
			'rgb_camera'    => 'RGB camera',
			'depth_camera'  => 'Depth camera',
			'imu'           => 'IMU',
			'wheel_encoder' => 'Wheel encoder',
			'other'         => 'Other',
		),
		'map'       => array(
			'qlabs_builtin'  => 'QLabs built-in map',
			'custom'         => 'Custom map',
			'physical_track' => 'Physical track',
			'other'          => 'Other',
		),
		'obstacles' => array(
			'static'  => 'Static',
			'dynamic' => 'Dynamic',
			'both'    => 'Static and dynamic',
			'none'    => 'None',
		),
	);

	/** @var bool Owner preview or instructor review (shows reasons for gaps and unpassed simulations). */
	private static $review = false;

	/** @var array Counters for the status summary. */
	private static $stats;
	/** @var string[] Labels of everything missing, for the summary list. */
	private static $missing;

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------ */

	private static function reset_stats() {
		self::$stats   = array( 'total' => 0, 'missing' => 0, 'unconfirmed' => 0, 'confirmed' => 0, 'na' => 0 );
		self::$missing = array();
	}

	private static function label( $group, $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return self::LABELS[ $group ][ $value ] ?? ucfirst( str_replace( '_', ' ', $value ) );
	}

	private static function humanize( $slug ) {
		return ucfirst( str_replace( '_', ' ', (string) $slug ) );
	}

	private static function num( $v ) {
		if ( is_int( $v ) ) {
			return number_format_i18n( $v );
		}
		$a = abs( $v );
		if ( $a > 0 && ( $a < 1e-4 || $a >= 1e9 ) ) {
			return sprintf( '%.3g', $v ); // e.g. 1.71e-18 instead of "0".
		}
		$s = rtrim( rtrim( sprintf( '%.6F', $v ), '0' ), '.' );
		return '-0' === $s ? '0' : $s;
	}

	/** "Not given" marker; records the label for the summary list. */
	private static function missing( $label = '' ) {
		if ( $label && ! in_array( $label, self::$missing, true ) ) {
			self::$missing[] = $label;
		}
		return '<span class="uf-miss" title="' . esc_attr__( 'The report does not give this yet.', 'unfold' ) . '">' . esc_html__( 'Not given', 'unfold' ) . '</span>';
	}

	/** Plain text field: escaped value (may contain $...$ math), or a "Not given" marker. */
	private static function text( $value, $label = '' ) {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return self::missing( $label );
		}
		return esc_html( $value );
	}

	/** Escaped text without a marker when empty (for optional details). */
	private static function opt( $value ) {
		return ( null === $value || '' === trim( (string) $value ) ) ? '' : esc_html( $value );
	}

	/**
	 * A tracked value { value, source_ref, status } (or a parameter row with the same keys).
	 * The source reference is shown on hover/focus; unconfirmed values get an amber dot.
	 */
	private static function tracked( $t, $unit = null, $label = '' ) {
		$value  = $t['value'] ?? null;
		$status = $t['status'] ?? 'extracted';
		$src    = $t['source_ref'] ?? null;
		self::$stats['total']++;

		if ( null === $value ) {
			if ( 'missing_confirmed' === $status ) {
				self::$stats['na']++;
				return '<span class="uf-na" title="' . esc_attr__( 'Confirmed as not applying to this project.', 'unfold' ) . '">' . esc_html__( 'Not applicable', 'unfold' ) . '</span>';
			}
			self::$stats['missing']++;
			return self::missing( $label );
		}

		$tip = array();
		if ( $src ) {
			/* translators: %s: location in the report */
			$tip[] = sprintf( __( 'Source: %s', 'unfold' ), $src );
		}
		$unconfirmed = 'confirmed' !== $status;
		if ( $unconfirmed ) {
			self::$stats['unconfirmed']++;
			$tip[] = __( 'Taken from the report; not confirmed yet.', 'unfold' );
		} else {
			self::$stats['confirmed']++;
		}
		$text = esc_html( self::num( $value ) ) . ( $unit ? '&nbsp;' . esc_html( $unit ) : '' );
		return '<span class="uf-val' . ( $unconfirmed ? ' is-unconfirmed' : '' ) . '"' . ( $tip ? ' title="' . esc_attr( implode( ' ', $tip ) ) . '" tabindex="0"' : '' ) . '>'
			. $text
			. ( $unconfirmed ? '<span class="uf-dot" aria-hidden="true"></span><span class="uf-sr">' . esc_html__( '(unconfirmed)', 'unfold' ) . '</span>' : '' )
			. '</span>';
	}

	/** The name the owner chose to show (a person or a group), or ''. */
	public static function credit( $d ) {
		$c = $d['meta']['credit'] ?? '';
		return is_string( $c ) ? trim( $c ) : '';
	}

	private static function has_text( $v ) {
		return null !== $v && '' !== trim( (string) $v );
	}

	private static function list_items( $items, $class = 'uf-list' ) {
		$out = '<ul class="' . esc_attr( $class ) . '">';
		foreach ( $items as $i ) {
			$out .= '<li>' . esc_html( $i ) . '</li>';
		}
		return $out . '</ul>';
	}

	private static function clean_list( $items ) {
		return array_values(
			array_filter(
				(array) $items,
				static function ( $i ) {
					return is_string( $i ) && '' !== trim( $i );
				}
			)
		);
	}

	private static function section( $id, $kicker, $title, $body, $intro = '', $class = '' ) {
		return '<section class="uf-section ' . esc_attr( $class ) . '" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-h">'
			. '<header class="uf-section-head">'
			. '<h2 class="uf-h2" id="' . esc_attr( $id ) . '-h">' . esc_html( $title ) . '</h2>'
			. ( $intro ? '<p class="uf-intro">' . esc_html( $intro ) . '</p>' : '' ) . '</header>'
			. $body . '</section>';
	}

	private static function table( array $head, array $rows, $class = '' ) {
		$out = '<div class="uf-table-wrap"><table class="uf-table ' . esc_attr( $class ) . '"><thead><tr>';
		foreach ( $head as $h ) {
			$out .= '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr>';
			foreach ( $row as $i => $cell ) {
				// Cells are pre-escaped HTML. data-label lets the table stack on phones.
				$out .= '<td data-label="' . esc_attr( $head[ $i ] ) . '">' . $cell . '</td>';
			}
			$out .= '</tr>';
		}
		return $out . '</tbody></table></div>';
	}

	/** Attachments uploaded to this project post, keyed by file name. */
	public static function attachments( $post_id ) {
		static $cache = array();
		if ( ! isset( $cache[ $post_id ] ) ) {
			$cache[ $post_id ] = array();
			foreach ( get_attached_media( '', $post_id ) as $a ) {
				$name = get_post_meta( $a->ID, '_unfold_source_name', true );
				$cache[ $post_id ][ $name ? $name : wp_basename( get_attached_file( $a->ID ) ) ] = $a;
			}
		}
		return $cache[ $post_id ];
	}

	/** CSV data files that have an uploaded attachment and can be plotted. */
	public static function csv_sources( $post_id, $data ) {
		$found = array();
		$atts  = self::attachments( $post_id );
		foreach ( $data['data_files'] ?? array() as $f ) {
			if ( 'csv' === $f['format'] && isset( $atts[ wp_basename( $f['file'] ) ] ) ) {
				$found[ $f['file'] ] = wp_get_attachment_url( $atts[ wp_basename( $f['file'] ) ]->ID );
			}
		}
		return $found;
	}

	/** Number of parts a visitor can play with (template simulations). */
	public static function playable( $data ) {
		return count( Unfold_SimData::template_parts( $data ) );
	}

	/** Featured AI cover image if generated, otherwise first uploaded image in results.media. */
	public static function cover_image( $post_id, $data ) {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			return $thumb_id;
		}
		$atts = self::attachments( $post_id );
		foreach ( $data['results']['media'] ?? array() as $m ) {
			if ( isset( $atts[ wp_basename( $m['file'] ) ] ) && 0 === strpos( $atts[ wp_basename( $m['file'] ) ]->post_mime_type, 'image/' ) ) {
				return $atts[ wp_basename( $m['file'] ) ]->ID;
			}
		}
		return 0;
	}


	/** Line icons (24×24, stroke = currentColor). */
	const ICONS = array(
		'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
		'play'   => '<circle cx="12" cy="12" r="9"/><path d="m10 8.5 5 3.5-5 3.5z"/>',
		'split'  => '<rect x="3" y="4" width="7" height="6" rx="1.5"/><rect x="14" y="4" width="7" height="6" rx="1.5"/><rect x="8.5" y="14" width="7" height="6" rx="1.5"/><path d="M6.5 10v2h11v-2M12 12v2"/>',
		'target' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/>',
		'arrow'  => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'upload' => '<path d="M12 15V4M7 9l5-5 5 5M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
	);

	public static function icon( $name ) {
		return '<svg class="uf-i" viewBox="0 0 24 24" aria-hidden="true">' . ( self::ICONS[ $name ] ?? '' ) . '</svg>';
	}

	/** Card picture for a project without an uploaded figure: its first subsystems as blocks. */
	private static function auto_picture( $d ) {
		$parts = array_slice( $d['subsystems'], 0, 4 );
		$n     = max( 1, count( $parts ) );
		$w     = 320;
		$gap   = 14;
		$bw    = min( 78, ( $w - 36 - $gap * ( $n - 1 ) ) / $n );
		$x0    = ( $w - ( $bw * $n + $gap * ( $n - 1 ) ) ) / 2;
		$svg   = '<svg viewBox="0 0 320 180" aria-hidden="true"><rect width="320" height="180" fill="#F5F7F9"/>';
		foreach ( $parts as $i => $sub ) {
			$x   = $x0 + $i * ( $bw + $gap );
			$on  = 0 === $i % 2;
			// Wrap the name into at most two lines that fit the block.
			$max   = max( 6, (int) floor( $bw / 5.6 ) );
			$lines = array( '' );
			foreach ( preg_split( '/\s+/', $sub['name'] ) as $word ) {
				$try = trim( end( $lines ) . ' ' . $word );
				if ( mb_strlen( $try ) <= $max || '' === end( $lines ) ) {
					$lines[ count( $lines ) - 1 ] = $try;
				} else {
					$lines[] = $word;
				}
			}
			if ( count( $lines ) > 2 ) {
				$lines = array( $lines[0], mb_strimwidth( implode( ' ', array_slice( $lines, 1 ) ), 0, $max, '…' ) );
			}
			$lines = array_map( static function ( $l ) use ( $max ) { return mb_strimwidth( $l, 0, $max, '…' ); }, $lines );
			if ( $i ) {
				$svg .= '<path d="M' . round( $x - $gap, 1 ) . ' 90h' . $gap . '" stroke="#9AA7B4" stroke-width="1.5"/>';
			}
			$svg .= '<rect x="' . round( $x, 1 ) . '" y="64" width="' . round( $bw, 1 ) . '" height="52" rx="6" fill="' . ( $on ? '#E3F5F1' : '#fff' ) . '" stroke="' . ( $on ? '#0B8A7A' : '#C5CED8' ) . '" stroke-width="1.5"/>'
				. '<text text-anchor="middle" font-family="Onest, sans-serif" font-size="10.5" font-weight="600" fill="#0F1720">';
			$y0   = 2 === count( $lines ) ? 87 : 94;
			foreach ( $lines as $k => $l ) {
				$svg .= '<tspan x="' . round( $x + $bw / 2, 1 ) . '" y="' . ( $y0 + 13 * $k ) . '">' . esc_html( $l ) . '</tspan>';
			}
			$svg .= '</text>';
		}
		return $svg . '</svg><span class="uf-card-auto">' . esc_html__( 'Drawn from the project’s parts', 'unfold' ) . '</span>';
	}

	/* ---------------------------------------------------------------------
	 * Project page
	 * ------------------------------------------------------------------ */

	/** $review: owner preview or instructor review, where simulations show even before they pass the gate. */
	public static function project_html( $post_id, $data, $review = false ) {
		self::$review = (bool) $review;
		self::reset_stats();

		$names = array();
		foreach ( $data['subsystems'] as $s ) {
			$names[ $s['id'] ] = $s['name'];
		}

		// Body first, so every tracked value is counted before the summary is drawn.
		$sections = array();
		$sections['uf-goal']       = array( __( 'Goal', 'unfold' ), self::goal_section( $data ) );
		$sections['uf-how']        = array( __( 'How it works', 'unfold' ), self::how_section( $data, $names ) );
		$sections['uf-setup']      = array( __( 'Setup', 'unfold' ), self::setup_section( $data, $names ) );
		$sections['uf-results']    = array( __( 'Results', 'unfold' ), self::results_section( $data ) );
		$evidence                  = self::evidence_section( $post_id, $data, $names );
		if ( $evidence ) {
			$sections['uf-evidence'] = array( __( 'Evidence', 'unfold' ), $evidence );
		}
		$limits = self::limitations_section( $data );
		if ( $limits ) {
			$sections['uf-limitations'] = array( __( 'Limitations', 'unfold' ), $limits );
		}

		$nav = '<nav class="uf-toc" aria-label="' . esc_attr__( 'On this page', 'unfold' ) . '"><ul>';
		foreach ( $sections as $id => $s ) {
			$nav .= '<li><a href="#' . esc_attr( $id ) . '">' . esc_html( $s[0] ) . '</a></li>';
		}
		$nav .= '</ul></nav>';

		$body = '';
		foreach ( $sections as $s ) {
			$body .= $s[1];
		}
		$car  = self::on_car( $data );
		$hero = $car ? '<!--UF_STATUS-->' : self::hero( $data );

		$page = self::finalize(
			'<div class="unfold uf-project" data-unfold-project>'
			. $hero
			. $nav
			. '<div class="uf-body">' . $body . '</div>'
			. self::footer( $data )
			. '</div>'
		);
		return $car ? self::car_html( $post_id, $data, $review )
			. '<section id="uf-details" class="uf-p2-details"><button type="button" class="ufp-details-btn" aria-expanded="true" aria-controls="uf-details-body">' . esc_html__( 'All details', 'unfold' ) . '<span aria-hidden="true"> ▾</span></button>'
			. '<div id="uf-details-body" class="uf-p2-inner">' . $page . '</div></section>' : $page;
	}

	/* ---------------------------------------------------------------------
	 * Project on the QCar 2 (parts placed on the car, Break-it templates)
	 * ------------------------------------------------------------------ */

	/** A project is drawn on the car once any of its parts says which hardware it lives on. */
	public static function on_car( $data ) {
		foreach ( $data['subsystems'] ?? array() as $s ) {
			if ( ! empty( $s['on'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function first_sentence( $text ) {
		$text = trim( (string) $text );
		return preg_match( '/^(.{10,160}?[.!?])(\s|$)/u', $text, $m ) ? $m[1] : mb_strimwidth( $text, 0, 120, '…' );
	}

	/**
	 * For instructors: what to search the literature for, and a plain summary of the project to hand
	 * to Claude when they want to develop the idea further (qcar-project.js "Related research").
	 */
	public static function research_seed( $d ) {
		$m     = $d['meta'] ?? array();
		$words = array_filter( array_map( 'strval', (array) ( $m['keywords'] ?? array() ) ) );
		$query = $words ? implode( ' ', array_slice( $words, 0, 4 ) ) : (string) ( $m['title'] ?? '' );
		$lines = array();
		$lines[] = 'Title: ' . ( $m['title'] ?? '' );
		if ( ! empty( $m['question'] ) ) {
			$lines[] = 'Question: ' . $m['question'];
		}
		if ( ! empty( $m['short_description'] ) ) {
			$lines[] = 'Summary: ' . $m['short_description'];
		}
		if ( ! empty( $d['goal']['problem_statement'] ) ) {
			$lines[] = 'Problem: ' . $d['goal']['problem_statement'];
		}
		$h = $m['headline'] ?? null;
		if ( is_array( $h ) && null !== ( $h['after']['value'] ?? null ) ) {
			$lines[] = 'Main result: ' . ( $h['label'] ?? '' ) . ': ' . ( $h['before']['value'] ?? '?' ) . ' -> ' . $h['after']['value'] . ' ' . ( $h['unit'] ?? '' );
		}
		$parts = array();
		foreach ( (array) ( $d['subsystems'] ?? array() ) as $sub ) {
			$parts[] = ( $sub['name'] ?? $sub['id'] ?? '' ) . ( ! empty( $sub['method'] ) ? ' (' . $sub['method'] . ')' : '' );
		}
		if ( $parts ) {
			$lines[] = 'Parts: ' . implode( '; ', $parts );
		}
		foreach ( array( 'limitations' => 'Limitations', 'future_work' => 'Future work stated' ) as $k => $label ) {
			$v = $d['results'][ $k ] ?? null;
			if ( $v ) {
				$lines[] = $label . ': ' . ( is_array( $v ) ? implode( '; ', array_map( 'strval', $v ) ) : $v );
			}
		}
		$sum = implode( "\n", array_map( 'wp_strip_all_tags', $lines ) );
		return array( 'query' => wp_strip_all_tags( $query ), 'summary' => mb_substr( $sum, 0, 2500 ) );
	}

	/** The AI's match-to-brief score for the page (see Unfold_Rest::worker_brief_score), or null. */
	public static function brief_score( $post_id ) {
		$s = get_post_meta( $post_id, '_unfold_brief_score', true );
		if ( ! is_array( $s ) || ! isset( $s['score'] ) ) {
			return null;
		}
		return array(
			'score'  => (int) $s['score'],
			'briefs' => array_values( (array) ( $s['briefs'] ?? array() ) ),
			'items'  => array_map( static function ( $i ) {
				return array_intersect_key( $i, array_flip( array( 'text', 'verdict', 'evidence', 'where', 'source' ) ) );
			}, (array) ( $s['items'] ?? array() ) ),
		);
	}

	private static function car_config( $post_id, $data, $review ) {
		$streams = Unfold_SimData::get( $post_id, $data );
		$atts    = self::attachments( $post_id );
		$media   = array();
		foreach ( $data['results']['media'] ?? array() as $m ) {
			$a = $atts[ wp_basename( $m['file'] ?? '' ) ] ?? null;
			if ( ! $a || empty( $m['part'] ) ) {
				continue;
			}
			$video = 0 === strpos( $a->post_mime_type, 'video/' );
			if ( ! $video && 0 !== strpos( $a->post_mime_type, 'image/' ) ) {
				continue;
			}
			$img                   = $video ? null : wp_get_attachment_image_src( $a->ID, 'medium_large' );
			$media[ $m['part'] ][] = array(
				'type'    => $video ? 'video' : 'image',
				'src'     => $video ? wp_get_attachment_url( $a->ID ) : ( $img ? $img[0] : wp_get_attachment_url( $a->ID ) ),
				'full'    => wp_get_attachment_url( $a->ID ),
				'caption' => (string) ( $m['caption'] ?? '' ),
			);
		}
		$parts   = array();
		foreach ( $data['subsystems'] as $s ) {
			$sim    = is_array( $s['sim'] ?? null ) ? $s['sim'] : array();
			$inputs = array();
			foreach ( $sim['inputs'] ?? array() as $in ) {
				if ( null !== ( $in['value']['value'] ?? null ) ) {
					$inputs[ $in['name'] ] = (float) $in['value']['value'];
				}
			}
			$params = array();
			foreach ( $s['parameters'] ?? array() as $p ) {
				if ( null !== ( $p['value'] ?? null ) ) {
					$params[] = array( wp_strip_all_tags( $p['name'] ), self::num( $p['value'] ) . ( self::has_text( $p['unit'] ?? null ) ? ' ' . $p['unit'] : '' ) );
				}
			}
			$parts[] = array(
				'id'       => $s['id'],
				'name'     => $s['name'],
				'plain'    => self::has_text( $s['plain'] ?? null ) ? $s['plain'] : self::first_sentence( $s['description'] ),
				'on'       => array_values( $s['on'] ?? array() ),
				'tech'     => self::has_text( $s['method'] ) ? $s['method'] : '',
				'eq'       => array_values( $s['equations'] ?? array() ),
				'params'   => $params,
				'template' => $sim['template'] ?? null,
				'approved' => ! empty( $sim['student_approved'] ),
				'inputs'   => (object) $inputs,
				'streams'  => $streams[ $s['id'] ] ?? array(),
				'media'    => $media[ $s['id'] ] ?? array(),
			);
		}
		$h = $data['meta']['headline'] ?? null;
		return array(
			'review'   => (bool) $review,
			'title'    => $data['meta']['title'],
			'question' => $data['meta']['question'] ?? null,
			'summary'  => self::has_text( $data['meta']['short_description'] ?? '' ) ? (string) $data['meta']['short_description'] : null,
			'credit'   => self::credit( $data ),
			'brief'    => self::brief_score( $post_id ),
			'research' => current_user_can( Unfold_Accounts::CAP_REVIEW ) ? self::research_seed( $data ) : null,
			'headline' => is_array( $h ) && null !== ( $h['after']['value'] ?? null ) ? array(
				'label'  => (string) ( $h['label'] ?? '' ),
				'before' => null !== ( $h['before']['value'] ?? null ) ? self::num( $h['before']['value'] ) : null,
				'after'  => self::num( $h['after']['value'] ),
				'unit'   => (string) ( $h['unit'] ?? '' ),
			) : null,
			'parts'    => $parts,
			'flows'    => array_map(
				static function ( $c ) {
					return array( 'from' => $c['from'], 'to' => $c['to'] );
				},
				$data['connections'] ?? array()
			),
		);
	}

	public static function car_html( $post_id, $data, $review = false ) {
		wp_enqueue_style( 'unfold-qcar' );
		wp_enqueue_style( 'unfold-qcar-project' );
		wp_enqueue_script( 'unfold-qcar-project' );
		wp_enqueue_style( 'unfold-katex' );          // inline symbols in the description ($hat{b}$)
		wp_enqueue_script( 'unfold-katex-auto' );
		$json = wp_json_encode( self::car_config( $post_id, $data, $review ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		$close = '<button class="ufp-close" type="button">' . esc_html__( 'Close', 'unfold' ) . '</button>';
		return '<div class="uf-p2" data-unfold-p2><script type="application/json">' . $json . '</script>'
			. '<header class="ufp-head"></header>'
			. '<h2 class="ufp-sub" id="ufp-parts-h">' . esc_html__( 'Subsystems', 'unfold' ) . '</h2>'
			. '<section class="ufp-parts" aria-labelledby="ufp-parts-h"></section>'
			. '<div class="ufp-car" hidden role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'The project on the QCar 2', 'unfold' ) . '">'
			. '<div class="ufp-stage"><div class="ufp-top"><div class="ufp-flow" aria-label="' . esc_attr__( 'Software', 'unfold' ) . '"></div>' . $close . '</div><div class="ufp-info" hidden aria-live="polite"></div></div></div>'
			. '<div class="ufp-sheet" hidden role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Break it', 'unfold' ) . '"><div class="ufp-sheet-in"><div class="ufp-sheet-head"><h2></h2>' . $close . '</div><div class="ufp-sheet-body"></div></div></div>'
			. '</div>';
	}

	/** Pairs success criteria with results by metric name. */
	private static function pairs( $d ) {
		$norm = static function ( $s ) {
			return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $s ) ) );
		};
		$rows = array();
		foreach ( $d['goal']['success_criteria'] as $c ) {
			$rows[ $norm( $c['metric'] ) ] = array( 'metric' => $c['metric'], 'target' => $c, 'result' => null );
		}
		foreach ( $d['results']['metrics'] as $m ) {
			$k = $norm( $m['metric'] );
			if ( ! isset( $rows[ $k ] ) ) {
				$rows[ $k ] = array( 'metric' => $m['metric'], 'target' => null, 'result' => $m );
			} elseif ( null === $rows[ $k ]['result'] ) {
				$rows[ $k ]['result'] = $m;
			} else {
				$rows[] = array( 'metric' => $m['metric'], 'target' => $rows[ $k ]['target'], 'result' => $m );
			}
		}
		return $rows;
	}

	private static function hero( $d ) {
		$m    = $d['meta'];
		$p    = $m['platform'];
		$mode = self::label( 'mode', $p['mode'] );

		$eyebrow = array();
		if ( $mode ) {
			$eyebrow[] = esc_html( $mode );
		}
		$credit = self::credit( $d );

		$out  = '<header class="uf-hero">';
		$out .= '<div class="uf-hero-text">';
		$out .= $eyebrow ? '<p class="uf-eyebrow">' . implode( '<span aria-hidden="true"> · </span>', $eyebrow ) . '</p>' : '';
		$out .= '<h1 class="uf-title">' . esc_html( $m['title'] ) . '</h1>';
		$out .= self::has_text( $m['short_description'] ) ? '<p class="uf-lede">' . esc_html( $m['short_description'] ) . '</p>' : '';
		$out .= $credit ? '<p class="uf-byline">' . esc_html( $credit ) . '</p>' : '';

		$out .= '</div>';

		// Key result: the first success criterion that has a reported result.
		foreach ( self::pairs( $d ) as $r ) {
			if ( $r['target'] && $r['result'] && null !== ( $r['result']['value']['value'] ?? null ) ) {
				$res  = $r['result'];
				$tgt  = $r['target'];
				$out .= '<aside class="uf-keyresult" aria-label="' . esc_attr__( 'Key result', 'unfold' ) . '">'
					. '<p class="uf-keyresult-label">' . esc_html__( 'Key result', 'unfold' ) . '</p>'
					. '<p class="uf-keyresult-metric">' . esc_html( $r['metric'] ) . '</p>'
					. '<p class="uf-keyresult-value">' . esc_html( self::num( $res['value']['value'] ) ) . '<span>' . esc_html( (string) $res['unit'] ) . '</span></p>'
					. ( null !== ( $tgt['target']['value'] ?? null ) ? '<p class="uf-keyresult-target">' . esc_html( sprintf( __( 'Target: %1$s %2$s', 'unfold' ), self::num( $tgt['target']['value'] ), (string) $tgt['unit'] ) ) . '</p>' : '' )
					. ( self::has_text( $res['conditions'] ?? null ) ? '<p class="uf-keyresult-cond">' . esc_html( $res['conditions'] ) . '</p>' : '' )
					. '</aside>';
				break;
			}
		}
		$out .= '</header>';

		// Facts strip + status summary.
		$stack = array_map(
			static function ( $s ) {
				return esc_html( self::label( 'stack', $s ) );
			},
			$p['software_stack']
		);
		// Publication state is shown by the Workspace / Instructor portal, never on the project page itself.
		$out .= '<div class="uf-facts">'
			. '<div><span>' . esc_html__( 'Vehicles', 'unfold' ) . '</span>' . self::tracked( $p['vehicle_count'], null, __( 'Number of vehicles', 'unfold' ) ) . '</div>'
			. '<div><span>' . esc_html__( 'Software', 'unfold' ) . '</span>' . ( $stack ? implode( ', ', $stack ) : self::missing( __( 'Software', 'unfold' ) ) ) . '</div>'
			. '<div><span>' . esc_html__( 'Subsystems', 'unfold' ) . '</span>' . esc_html( count( $d['subsystems'] ) ) . '</div>'
			. '</div>';

		// Header-level missing text fields.
		if ( ! $mode ) {
			self::missing( __( 'Platform', 'unfold' ) );
		}

		return $out . '<!--UF_STATUS-->';
	}

	/** Plain-language status of the data, with a legend for the two markers. */
	private static function status_html() {
		$s   = self::$stats;
		$out = '<details class="uf-status">';
		$out .= '<summary><span class="uf-status-icon" aria-hidden="true">i</span><span>';
		if ( $s['unconfirmed'] ) {
			$out .= esc_html( sprintf( _n( '%d value comes from the report and is not confirmed yet.', '%d values come from the report and are not confirmed yet.', $s['unconfirmed'], 'unfold' ), $s['unconfirmed'] ) );
		} else {
			$out .= esc_html__( 'All values are confirmed.', 'unfold' );
		}
		if ( self::$missing ) {
			$out .= ' ' . esc_html( sprintf( _n( '%d item is not given in the report.', '%d items are not given in the report.', count( self::$missing ), 'unfold' ), count( self::$missing ) ) );
		}
		$out .= '</span><span class="uf-status-more">' . esc_html__( 'What does this mean?', 'unfold' ) . '</span></summary>';
		$out .= '<div class="uf-status-body"><p>' . esc_html__( 'Unfold builds this page from the project report and never guesses a number. Two small markers show how far each value has been checked:', 'unfold' ) . '</p>';
		$out .= '<ul class="uf-legend"><li><span class="uf-val is-unconfirmed">0.6&nbsp;m/s<span class="uf-dot" aria-hidden="true"></span></span> ' . esc_html__( 'Taken from the report, not confirmed yet. Hover a value to see where in the report it comes from.', 'unfold' ) . '</li>';
		$out .= '<li><span class="uf-miss">' . esc_html__( 'Not given', 'unfold' ) . '</span> ' . esc_html__( 'The report does not give this. It stays empty until it is added.', 'unfold' ) . '</li></ul>';
		if ( self::$missing ) {
			$out .= '<p class="uf-status-list"><strong>' . esc_html__( 'Not given yet:', 'unfold' ) . '</strong> ' . esc_html( implode( ', ', self::$missing ) ) . '.</p>';
		}
		return $out . '</div></details>';
	}

	private static function goal_section( $d ) {
		$g    = $d['goal'];
		$obj  = self::clean_list( $g['objectives'] );
		$body = '<div class="uf-split">';
		$body .= '<div class="uf-prose"><h3 class="uf-h3">' . esc_html__( 'The problem', 'unfold' ) . '</h3><p>' . self::text( $g['problem_statement'], __( 'Problem statement', 'unfold' ) ) . '</p></div>';
		$body .= '<div><h3 class="uf-h3">' . esc_html__( 'Objectives', 'unfold' ) . '</h3>' . ( $obj ? self::list_items( $obj, 'uf-list uf-list--check' ) : '<p>' . self::missing( __( 'Objectives', 'unfold' ) ) . '</p>' ) . '</div>';
		$body .= '</div>';

		if ( $g['success_criteria'] ) {
			$body .= '<h3 class="uf-h3">' . esc_html__( 'What counts as success', 'unfold' ) . '</h3><ul class="uf-criteria">';
			foreach ( $g['success_criteria'] as $c ) {
				$body .= '<li><span class="uf-criteria-metric">' . esc_html( $c['metric'] ) . '</span><span class="uf-criteria-target">' . self::tracked( $c['target'], $c['unit'], $c['metric'] . ' target' ) . '</span></li>';
			}
			$body .= '</ul>';
		} else {
			self::missing( __( 'Success criteria', 'unfold' ) );
		}
		return self::section( 'uf-goal', __( 'The question', 'unfold' ), __( 'Goal', 'unfold' ), $body );
	}

	private static function how_section( $d, $names ) {
		$nodes = array();
		foreach ( $d['subsystems'] as $s ) {
			$nodes[] = array( 'id' => $s['id'], 'name' => $s['name'], 'category' => $s['category'], 'sim' => ! empty( $s['sim']['template'] ) );
		}
		$edges = array();
		foreach ( $d['connections'] as $c ) {
			$edges[] = array( 'from' => $c['from'], 'to' => $c['to'], 'signal' => $c['signal'] ?? null );
		}

		$text = '<ol class="uf-edges">';
		foreach ( $edges as $e ) {
			$text .= '<li><strong>' . esc_html( $names[ $e['from'] ] ) . '</strong> → <strong>' . esc_html( $names[ $e['to'] ] ) . '</strong>'
				. ( $e['signal'] ? ' <span class="uf-signal">(' . esc_html( $e['signal'] ) . ')</span>' : '' ) . '</li>';
		}
		$text .= '</ol>';

		$body  = '<figure class="uf-diagram-fig">';
		$body .= '<div class="uf-diagram" data-uf-diagram="' . esc_attr( wp_json_encode( array( 'nodes' => $nodes, 'edges' => $edges ) ) ) . '" role="img" aria-label="' . esc_attr__( 'Block diagram of the system', 'unfold' ) . '"><p class="uf-loading">' . esc_html__( 'Drawing diagram…', 'unfold' ) . '</p></div>';
		$body .= '<figcaption>' . esc_html__( 'Select a block to open that subsystem.', 'unfold' ) . ( $edges ? ' <details class="uf-details"><summary>' . esc_html__( 'Connections as text', 'unfold' ) . '</summary>' . $text . '</details>' : '' ) . '</figcaption>';
		$body .= '</figure>';

		// Subsystem tabs.
		$tabs   = '<div class="uf-tabs" role="tablist" aria-label="' . esc_attr__( 'Subsystems', 'unfold' ) . '">';
		$panels = '';
		foreach ( $d['subsystems'] as $i => $s ) {
			$id      = $s['id'];
			$has_sim = ! empty( $s['sim']['template'] );
			$tabs   .= '<button type="button" role="tab" class="uf-tab" id="tab-' . esc_attr( $id ) . '" aria-controls="sub-' . esc_attr( $id ) . '" aria-selected="' . ( 0 === $i ? 'true' : 'false' ) . '" data-uf-tab="' . esc_attr( $id ) . '">'
				. '<span class="uf-tab-cat uf-cat--' . esc_attr( $s['category'] ) . '"></span>' . esc_html( $s['name'] )
				. ( $has_sim ? '<span class="uf-tab-sim">' . esc_html__( 'sim', 'unfold' ) . '</span>' : '' ) . '</button>';
			$panels .= self::subsystem_panel( $s, $names, 0 === $i );
		}
		$tabs .= '</div>';

		return self::section( 'uf-how', __( 'The system', 'unfold' ), __( 'How it works', 'unfold' ), $body . '<div class="uf-subsystems" id="uf-subsystems">' . $tabs . $panels . '</div>', '' );
	}

	private static function endpoint( $ref, $names ) {
		if ( null === $ref || '' === $ref ) {
			return '<span class="uf-muted">—</span>';
		}
		if ( isset( $names[ $ref ] ) ) {
			return '<a href="#sub-' . esc_attr( $ref ) . '" data-uf-open="' . esc_attr( $ref ) . '">' . esc_html( $names[ $ref ] ) . '</a>';
		}
		$label = self::LABELS['sensor'][ $ref ] ?? ( 'actuator' === $ref ? __( 'Actuator', 'unfold' ) : $ref );
		return esc_html( $label );
	}

	private static function io_list( $items, $key, $names, $arrow_first ) {
		if ( ! $items ) {
			return '<p class="uf-muted">—</p>';
		}
		$out = '<ul class="uf-io">';
		foreach ( $items as $p ) {
			$end  = '<span class="uf-io-end">' . ( $arrow_first ? '' : '→ ' ) . self::endpoint( $p[ $key ] ?? null, $names ) . ( $arrow_first ? ' →' : '' ) . '</span>';
			$sig  = '<span class="uf-io-name">' . esc_html( $p['name'] ) . ( self::has_text( $p['unit'] ?? null ) ? ' <span class="uf-unit">[' . esc_html( $p['unit'] ) . ']</span>' : '' ) . '</span>';
			$out .= '<li>' . ( $arrow_first ? $end . $sig : $sig . $end ) . '</li>';
		}
		return $out . '</ul>';
	}

	private static function subsystem_panel( $s, $names, $active ) {
		$id  = $s['id'];
		$out = '<article class="uf-panel' . ( $active ? ' is-active' : '' ) . '" id="sub-' . esc_attr( $id ) . '" role="tabpanel" aria-labelledby="tab-' . esc_attr( $id ) . '" tabindex="-1">';
		$out .= '<header class="uf-panel-head"><span class="uf-cat uf-cat--' . esc_attr( $s['category'] ) . '">' . esc_html( self::humanize( $s['category'] ) ) . '</span>'
			. '<h3 class="uf-panel-title">' . esc_html( $s['name'] ) . '</h3>'
			. '<dl class="uf-panel-meta"><div><dt>' . esc_html__( 'Method', 'unfold' ) . '</dt><dd>' . self::text( $s['method'], $s['name'] . ' method' ) . '</dd></div>'
			. '<div><dt>' . esc_html__( 'Runs at', 'unfold' ) . '</dt><dd>' . self::tracked( $s['rate_hz'], 'Hz', $s['name'] . ' update rate' ) . '</dd></div></dl></header>';

		$out .= '<p class="uf-desc">' . self::text( $s['description'], $s['name'] . ' description' ) . '</p>';

		$out .= '<div class="uf-io-grid">'
			. '<div><h4 class="uf-h4">' . esc_html__( 'Takes in', 'unfold' ) . '</h4>' . self::io_list( $s['inputs'], 'from', $names, true ) . '</div>'
			. '<div><h4 class="uf-h4">' . esc_html__( 'Sends out', 'unfold' ) . '</h4>' . self::io_list( $s['outputs'], 'to', $names, false ) . '</div>'
			. '</div>';

		if ( $s['equations'] ) {
			$out .= '<h4 class="uf-h4">' . esc_html__( 'Equations', 'unfold' ) . '</h4><div class="uf-eqs">';
			foreach ( $s['equations'] as $eq ) {
				$out .= '<div class="uf-eq" data-latex="' . esc_attr( $eq ) . '"><code>' . esc_html( $eq ) . '</code></div>';
			}
			$out .= '</div>';
		}

		if ( $s['parameters'] ) {
			$out .= '<h4 class="uf-h4">' . esc_html__( 'Parameters', 'unfold' ) . '</h4><dl class="uf-params">';
			foreach ( $s['parameters'] as $p ) {
				$out .= '<div><dt>' . esc_html( $p['name'] ) . '</dt><dd>' . self::tracked( $p, $p['unit'] ?? null, $s['name'] . ': ' . $p['name'] ) . '</dd></div>';
			}
			$out .= '</dl>';
		}

		$foot = array();
		if ( $s['code_files'] ) {
			$files = array_map(
				static function ( $f ) {
					return '<code>' . esc_html( $f ) . '</code>';
				},
				$s['code_files']
			);
			$foot[] = '<span>' . esc_html__( 'Code:', 'unfold' ) . ' ' . implode( ' ', $files ) . '</span>';
		}
		if ( $foot ) {
			$out .= '<footer class="uf-panel-foot">' . implode( '', $foot ) . '</footer>';
		}
		return $out . '</article>';
	}

	private static function setup_section( $d, $names ) {
		$e    = $d['environment'];
		$body = '<div class="uf-split">';
		$body .= '<div class="uf-prose"><h3 class="uf-h3">' . esc_html__( 'Environment', 'unfold' ) . '</h3><p>' . self::text( $e['scenario'], __( 'Scenario', 'unfold' ) ) . '</p>';
		$facts = array(
			__( 'Map', 'unfold' )          => self::text( self::label( 'map', $e['map_source'] ), __( 'Map source', 'unfold' ) ),
			__( 'Obstacles', 'unfold' )    => self::text( self::label( 'obstacles', $e['obstacles'] ), __( 'Obstacles', 'unfold' ) ),
			__( 'Other agents', 'unfold' ) => self::text( $e['other_agents'], __( 'Other agents', 'unfold' ) ),
		);
		if ( self::has_text( $e['map_file'] ?? null ) ) {
			$facts[ __( 'Map file', 'unfold' ) ] = '<code>' . esc_html( $e['map_file'] ) . '</code>';
		}
		$body .= '<dl class="uf-dl">';
		foreach ( $facts as $k => $v ) {
			$body .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . $v . '</dd></div>';
		}
		$body .= '</dl></div>';

		$body .= '<div><h3 class="uf-h3">' . esc_html__( 'Sensors', 'unfold' ) . '</h3>';
		if ( $d['sensors'] ) {
			$body .= '<ul class="uf-sensors">';
			foreach ( $d['sensors'] as $s ) {
				$users = array();
				foreach ( $s['used_by'] as $id ) {
					$users[] = '<a href="#sub-' . esc_attr( $id ) . '" data-uf-open="' . esc_attr( $id ) . '">' . esc_html( $names[ $id ] ?? $id ) . '</a>';
				}
				$body .= '<li><div class="uf-sensor-top"><strong>' . esc_html( self::label( 'sensor', $s['type'] ) ) . '</strong>' . self::tracked( $s['rate_hz'], 'Hz', self::label( 'sensor', $s['type'] ) . ' rate' ) . '</div>'
					. '<p>' . self::text( $s['purpose'] ) . '</p>'
					. ( $users ? '<p class="uf-sensor-users">' . esc_html__( 'Used by', 'unfold' ) . ' ' . implode( ', ', $users ) . '</p>' : '' ) . '</li>';
			}
			$body .= '</ul>';
		} else {
			$body .= '<p>' . self::missing( __( 'Sensors', 'unfold' ) ) . '</p>';
		}
		$body .= '</div></div>';
		return self::section( 'uf-setup', __( 'Where and with what', 'unfold' ), __( 'Setup', 'unfold' ), $body );
	}

	private static function results_section( $d ) {
		$rows = self::pairs( $d );
		if ( ! $rows ) {
			self::missing( __( 'Results', 'unfold' ) );
			return self::section( 'uf-results', __( 'The answer', 'unfold' ), __( 'Results', 'unfold' ), '<p>' . esc_html__( 'No results have been reported yet.', 'unfold' ) . '</p>' );
		}
		$targets = array();
		$other   = array();
		foreach ( $rows as $r ) {
			$t = $r['target'];
			$m = $r['result'];
			if ( $t ) {
				$targets[] = array(
					esc_html( $r['metric'] ),
					self::tracked( $t['target'], $t['unit'], $r['metric'] . ' target' ),
					$m ? self::tracked( $m['value'], $m['unit'], $r['metric'] . ' result' ) : self::missing( $r['metric'] . ' result' ),
					$m ? self::opt( $m['conditions'] ?? null ) : '',
				);
			} else {
				$other[] = array(
					esc_html( $r['metric'] ),
					self::tracked( $m['value'], $m['unit'], $r['metric'] ),
					self::opt( $m['conditions'] ?? null ),
				);
			}
		}
		$body = '';
		if ( $targets ) {
			$body .= '<h3 class="uf-h3">' . esc_html__( 'Against the targets', 'unfold' ) . '</h3>'
				. self::table( array( __( 'Metric', 'unfold' ), __( 'Target', 'unfold' ), __( 'Result', 'unfold' ), __( 'Details', 'unfold' ) ), $targets, 'uf-table--results' );
		}
		if ( $other ) {
			$body .= '<h3 class="uf-h3">' . esc_html__( 'Other measurements', 'unfold' ) . '</h3>'
				. self::table( array( __( 'Measurement', 'unfold' ), __( 'Value', 'unfold' ), __( 'Details', 'unfold' ) ), $other, 'uf-table--other' );
		}
		return self::section( 'uf-results', __( 'The answer', 'unfold' ), __( 'Results', 'unfold' ), $body );
	}

	private static function evidence_section( $post_id, $d, $names ) {
		$atts  = self::attachments( $post_id );
		$csv   = self::csv_sources( $post_id, $d );
		$plots = '';
		foreach ( $d['data_files'] as $f ) {
			if ( isset( $csv[ $f['file'] ] ) ) {
				$plots .= '<figure class="uf-plot-fig"><div class="uf-plot" data-uf-csv="' . esc_url( $csv[ $f['file'] ] ) . '" data-uf-columns="' . esc_attr( wp_json_encode( $f['columns'] ?? array() ) ) . '"><p class="uf-loading">' . esc_html__( 'Loading data…', 'unfold' ) . '</p></div>'
					. '<figcaption>' . esc_html( $f['description'] ?? $f['file'] ) . '</figcaption></figure>';
			}
		}

		$media = '';
		$notes = self::$review ? Unfold_Workflow::media_notes( $post_id, $d ) : array();
		foreach ( $d['results']['media'] as $item ) {
			if ( ! isset( $atts[ wp_basename( $item['file'] ) ] ) && ! self::$review ) {
				continue; // Visitors never see a missing figure; the owner sees why in the preview.
			}
			$media .= '<figure class="uf-media-item">';
			if ( isset( $atts[ wp_basename( $item['file'] ) ] ) ) {
				$a   = $atts[ wp_basename( $item['file'] ) ];
				$url = wp_get_attachment_url( $a->ID );
				$alt = $item['caption'] ?? $item['file'];
				if ( 0 === strpos( $a->post_mime_type, 'video/' ) ) {
					$media .= '<video controls preload="metadata" src="' . esc_url( $url ) . '"></video>';
				} elseif ( 0 === strpos( $a->post_mime_type, 'image/' ) ) {
					$media .= '<a class="uf-media-link" href="' . esc_url( $url ) . '" data-uf-lightbox>' . wp_get_attachment_image( $a->ID, 'large', false, array( 'alt' => $alt, 'loading' => 'lazy' ) ) . '</a>';
				} else {
					$media .= '<a href="' . esc_url( $url ) . '">' . esc_html( $item['file'] ) . '</a>';
				}
			} else {
				$why    = $notes[ wp_basename( $item['file'] ) ] ?? __( 'Not found in the ZIP.', 'unfold' );
				$media .= '<div class="uf-media-missing"><code>' . esc_html( $item['file'] ) . '</code> <span class="uf-miss">' . esc_html( $why ) . '</span></div>';
			}
			$media .= ( self::has_text( $item['caption'] ?? null ) ? '<figcaption>' . esc_html( $item['caption'] ) . '</figcaption>' : '' ) . '</figure>';
		}

		$files = '';
		if ( $d['data_files'] ) {
			$rows = array();
			foreach ( $d['data_files'] as $f ) {
				$cols = array();
				foreach ( $f['columns'] ?? array() as $c ) {
					$cols[] = esc_html( $c['name'] ) . ( ! empty( $c['unit'] ) ? ' <span class="uf-unit">[' . esc_html( $c['unit'] ) . ']</span>' : '' );
				}
				$uploaded = isset( $atts[ wp_basename( $f['file'] ) ] );
				$rows[]   = array(
					( $uploaded ? '<a href="' . esc_url( wp_get_attachment_url( $atts[ wp_basename( $f['file'] ) ]->ID ) ) . '" download><code>' . esc_html( $f['file'] ) . '</code></a>' : '<code>' . esc_html( $f['file'] ) . '</code> ' . self::missing( $f['file'] ) ),
					self::opt( $f['description'] ?? null ),
					! empty( $f['subsystem'] ) ? self::endpoint( $f['subsystem'], $names ) : '—',
					isset( $f['sample_rate_hz'] ) ? self::tracked( $f['sample_rate_hz'], 'Hz', $f['file'] . ' sample rate' ) : '—',
					$cols ? implode( ', ', $cols ) : '—',
				);
			}
			$files = '<details class="uf-details uf-files-box"><summary>' . esc_html( sprintf( _n( 'Data file (%d)', 'Data files (%d)', count( $rows ), 'unfold' ), count( $rows ) ) ) . '</summary>'
				. self::table( array( __( 'File', 'unfold' ), __( 'What it is', 'unfold' ), __( 'Subsystem', 'unfold' ), __( 'Sample rate', 'unfold' ), __( 'Columns', 'unfold' ) ), $rows ) . '</details>';
		}

		if ( ! $plots && ! $media && ! $files ) {
			return '';
		}
		$body = ( $plots ? '<h3 class="uf-h3">' . esc_html__( 'Recorded data', 'unfold' ) . '</h3><div class="uf-plots">' . $plots . '</div>' : '' )
			. ( $media ? '<h3 class="uf-h3">' . esc_html__( 'Figures and media', 'unfold' ) . '</h3><div class="uf-media">' . $media . '</div>' : '' )
			. $files;
		return self::section( 'uf-evidence', __( 'From the recorded runs', 'unfold' ), __( 'Evidence', 'unfold' ), $body );
	}

	private static function limitations_section( $d ) {
		$lim = self::clean_list( $d['results']['limitations'] );
		$fut = self::clean_list( $d['results']['future_work'] );
		if ( ! $lim && ! $fut ) {
			self::missing( __( 'Limitations', 'unfold' ) );
			return '';
		}
		$body = '<div class="uf-split">'
			. '<div><h3 class="uf-h3">' . esc_html__( 'Limitations', 'unfold' ) . '</h3>' . ( $lim ? self::list_items( $lim ) : '<p>' . self::missing( __( 'Limitations', 'unfold' ) ) . '</p>' ) . '</div>'
			. '<div><h3 class="uf-h3">' . esc_html__( 'Next steps', 'unfold' ) . '</h3>' . ( $fut ? self::list_items( $fut ) : '<p>' . self::missing( __( 'Future work', 'unfold' ) ) . '</p>' ) . '</div>'
			. '</div>';
		return self::section( 'uf-limitations', __( 'Honest limits', 'unfold' ), __( 'Limitations and next steps', 'unfold' ), $body );
	}

	private static function footer( $d ) {
		$r   = $d['review'];
		$out = '<footer class="uf-foot">';
		if ( ! empty( $r['instructor_notes'] ) && ! empty( $r['instructor_approved'] ) ) {
			$out .= '<p><strong>' . esc_html__( 'Instructor notes:', 'unfold' ) . '</strong> ' . esc_html( $r['instructor_notes'] ) . '</p>';
		}
		$out .= '<p>' . esc_html( sprintf( __( 'Built from the project report with Unfold (schema v%s).', 'unfold' ), $d['schema_version'] ) )
			. ( self::has_text( $r['extracted_at'] ?? null ) ? ' ' . esc_html( sprintf( __( 'Extracted %s.', 'unfold' ), $r['extracted_at'] ) ) : '' ) . '</p>';
		return $out . '</footer>';
	}

	/** Swaps in the status summary once all values were counted. */
	public static function finalize( $html ) {
		return str_replace( '<!--UF_STATUS-->', self::status_html(), $html );
	}

	/* ---------------------------------------------------------------------
	 * Gallery
	 * ------------------------------------------------------------------ */

	public static function gallery( $atts = array() ) {
		$atts = shortcode_atts( array( 'heading' => 'yes', 'filters' => 'yes', 'limit' => 200 ), $atts, 'unfold_gallery' );

		$posts = get_posts(
			array(
				'post_type'      => Unfold_Post_Type::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$cards    = '';
		$keywords = array();
		$items    = array();
		foreach ( $posts as $p ) {
			$d = Unfold_Store::get( $p->ID );
			if ( $d ) {
				$items[] = array( $p, $d );
			}
		}
		// Richer projects (with simulations, then more confirmed content) first.
		usort(
			$items,
			static function ( $a, $b ) {
				return self::playable( $b[1] ) <=> self::playable( $a[1] );
			}
		);
		$items = array_slice( $items, 0, max( 1, (int) $atts['limit'] ) );

		foreach ( $items as $it ) {
			list( $p, $d ) = $it;
			$m    = $d['meta'];
			foreach ( $m['keywords'] as $k ) {
				$keywords[ $k ] = true;
			}
			$credit = self::credit( $d );
			$mode  = self::label( 'mode', $m['platform']['mode'] );
			$play  = self::playable( $d );
			$cover = self::cover_image( $p->ID, $d );

			$cards .= '<li class="uf-card" data-keywords="' . esc_attr( wp_json_encode( array_values( $m['keywords'] ) ) ) . '">'
				. '<a class="uf-card-link" href="' . esc_url( get_permalink( $p ) ) . '">'
				. '<div class="uf-card-media">' . ( $cover ? wp_get_attachment_image( $cover, 'medium_large', false, array( 'alt' => '', 'loading' => 'lazy' ) ) : self::auto_picture( $d ) ) . '</div>'
				. '<div class="uf-card-body">'
				. '<h2 class="uf-card-title">' . esc_html( $m['title'] ) . '</h2>'
				. '<p class="uf-card-meta">'
				. ( $mode ? '<span>' . self::icon( 'layers' ) . esc_html( $mode ) . '</span>' : '' )
				. ( $play ? '<span class="uf-card-sim">' . self::icon( 'play' ) . esc_html( sprintf( _n( '%d simulation', '%d simulations', $play, 'unfold' ), $play ) ) . '</span>' : '' )
				. '<span>' . self::icon( 'split' ) . esc_html( sprintf( _n( '%d subsystem', '%d subsystems', count( $d['subsystems'] ), 'unfold' ), count( $d['subsystems'] ) ) ) . '</span>'
				. '</p>'
				. ( self::has_text( $m['short_description'] ) ? '<p class="uf-card-desc">' . esc_html( wp_trim_words( $m['short_description'], 26 ) ) . '</p>' : '' )
				. ( $credit ? '<p class="uf-card-kv"><span>' . esc_html( $credit ) . '</span></p>' : '' )
				. '</div></a></li>';
		}

		ksort( $keywords, SORT_NATURAL | SORT_FLAG_CASE );

		$out = '<div class="unfold uf-gallery" data-unfold-gallery>';
		if ( 'yes' === $atts['heading'] ) {
			$out .= '<header class="uf-gallery-head"><h1 class="uf-title">' . esc_html__( 'Projects', 'unfold' ) . '</h1>'
				. '<p class="uf-lede">' . esc_html__( 'Open a project to see how its parts fit together, and try the simulations yourself.', 'unfold' ) . '</p></header>';
		}
		if ( ! $items ) {
			return $out . '<p class="uf-empty">' . esc_html__( 'No projects have been published yet.', 'unfold' ) . '</p></div>';
		}

		if ( 'yes' === $atts['filters'] && count( $keywords ) > 1 ) {
			$out .= '<form class="uf-filters" role="search" onsubmit="return false">';
			$out .= '<label><span>' . esc_html__( 'Topic', 'unfold' ) . '</span><select data-uf-filter="keyword"><option value="">' . esc_html__( 'All topics', 'unfold' ) . '</option>';
			foreach ( array_keys( $keywords ) as $k ) {
				$out .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $k ) . '</option>';
			}
			$out .= '</select></label>';
			$out .= '<p class="uf-count" aria-live="polite" data-uf-count>' . esc_html( sprintf( _n( '%d project', '%d projects', count( $items ), 'unfold' ), count( $items ) ) ) . '</p>';
			$out .= '</form>';
		}

		$out .= '<ul class="uf-cards">' . $cards . '</ul>';
		$out .= '<p class="uf-empty" data-uf-none hidden>' . esc_html__( 'No projects match these filters.', 'unfold' ) . '</p>';
		return $out . '</div>';
	}
}
