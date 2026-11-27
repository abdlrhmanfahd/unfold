<?php
/**
 * Workspace (upload and manage your projects), Instructor portal, and their pages.
 *
 * Pages (created once, ids in option unfold_pages):
 *   login       [unfold_login]              /sign-in/
 *   register    [unfold_register]           /sign-up/
 *   workspace   [unfold_workspace]          /workspace/
 *   instructor  [unfold_instructor_portal]  /instructor/
 *
 * The public site stays read-only; everything that changes a project happens here.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Portals {

	const PAGES = array(
		'login'      => array( 'slug' => 'sign-in', 'title' => 'Sign in', 'content' => '[unfold_login]' ),
		'register'   => array( 'slug' => 'sign-up', 'title' => 'Create an account', 'content' => '[unfold_register]' ),
		'workspace'  => array( 'slug' => 'workspace', 'title' => 'Workspace', 'content' => '[unfold_workspace]' ),
		'instructor' => array( 'slug' => 'instructor', 'title' => 'Instructor', 'content' => '[unfold_instructor_portal]' ),
	);

	/** [label for the project owner, label for the instructor] */
	const STATE_LABELS = array(
		'queued'     => array( 'Reading your files', 'AI reading files' ),
		'extracting' => array( 'Reading your files', 'AI reading files' ),
		'failed'     => array( 'Needs attention', 'Extraction failed' ),
		'review'     => array( 'Your review', 'Being prepared' ),
		'submitted'  => array( 'With the instructor', 'Waiting for review' ),
		'changes'    => array( 'Returned with notes', 'Returned with notes' ),
		'approved'   => array( 'Published', 'Published' ),
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_pages' ), 40 );
		add_action( 'init', array( __CLASS__, 'register_assets' ), 20 ); // Early: block themes render shortcodes before wp_enqueue_scripts.
		add_shortcode( 'unfold_workspace', array( __CLASS__, 'workspace' ) );
		add_shortcode( 'unfold_student_portal', array( __CLASS__, 'workspace' ) ); // Old page content.
		add_shortcode( 'unfold_instructor_portal', array( __CLASS__, 'instructor' ) );
		add_shortcode( 'unfold_account_link', array( __CLASS__, 'account_link' ) );
		add_action( 'template_redirect', array( __CLASS__, 'gate' ) );
	}

	public static function state_label( $state, $for = 0 ) {
		if ( in_array( $state, array( 'queued', 'extracting' ), true ) && ! Unfold_Workflow::reader_running() ) {
			return $for ? __( 'Waiting for the AI reader', 'unfold' ) : __( 'Waiting to be read', 'unfold' );
		}
		return self::STATE_LABELS[ $state ][ $for ] ?? ucfirst( $state );
	}

	public static function ensure_pages() {
		$pages = get_option( 'unfold_pages', array() );
		// The workspace used to be the "My projects" page: reuse it.
		if ( ! empty( $pages['student'] ) && empty( $pages['workspace'] ) && get_post( $pages['student'] ) ) {
			wp_update_post( array( 'ID' => $pages['student'], 'post_title' => 'Workspace', 'post_name' => 'workspace', 'post_content' => '<!-- wp:shortcode -->[unfold_workspace]<!-- /wp:shortcode -->' ) );
			$pages['workspace'] = $pages['student'];
			unset( $pages['student'] );
			update_option( 'unfold_pages', $pages );
		}
		$dirty = false;
		foreach ( self::PAGES as $key => $p ) {
			$existing_id = ! empty( $pages[ $key ] ) ? (int) $pages[ $key ] : 0;
			$existing    = $existing_id ? get_post( $existing_id ) : null;
			if ( $existing && 'trash' !== $existing->post_status ) {
				continue;
			}
			$by_slug = get_page_by_path( $p['slug'] );
			$id      = ( $by_slug && 'trash' !== $by_slug->post_status ) ? $by_slug->ID : wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $p['title'],
					'post_name'    => $p['slug'],
					'post_content' => '<!-- wp:shortcode -->' . $p['content'] . '<!-- /wp:shortcode -->',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_wp_page_template', 'portal' );
				$pages[ $key ] = $id;
				$dirty         = true;
			}
		}
		if ( $dirty ) {
			update_option( 'unfold_pages', $pages );
		}
	}

	public static function url( $key, $args = array() ) {
		$pages = get_option( 'unfold_pages', array() );
		$url   = ! empty( $pages[ $key ] ) ? get_permalink( $pages[ $key ] ) : home_url( '/' . self::PAGES[ $key ]['slug'] . '/' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	private static function is_page( $key ) {
		$pages = get_option( 'unfold_pages', array() );
		return ! empty( $pages[ $key ] ) && is_page( $pages[ $key ] );
	}

	/** Portals need a signed-in user with the right capability. */
	public static function gate() {
		foreach ( array( 'workspace' => Unfold_Accounts::CAP_SUBMIT, 'instructor' => Unfold_Accounts::CAP_REVIEW ) as $key => $cap ) {
			if ( ! self::is_page( $key ) ) {
				continue;
			}
			nocache_headers();
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( self::url( 'login' ) );
				exit;
			}
			if ( ! current_user_can( $cap ) ) {
				wp_safe_redirect( Unfold_Accounts::home_for( wp_get_current_user() ) );
				exit;
			}
		}
	}

	public static function register_assets() {
		$v = static function ( $rel ) {
			return UNFOLD_VERSION . '.' . ( is_file( UNFOLD_DIR . $rel ) ? filemtime( UNFOLD_DIR . $rel ) : 0 );
		};
		wp_register_style( 'unfold-portal', UNFOLD_URL . 'assets/css/portal.css', array( 'unfold' ), $v( 'assets/css/portal.css' ) );
		wp_register_script( 'unfold-auth', UNFOLD_URL . 'assets/js/auth.js', array(), $v( 'assets/js/auth.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-form', UNFOLD_URL . 'assets/js/form.js', array( 'unfold-katex-auto' ), $v( 'assets/js/form.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-portal', UNFOLD_URL . 'assets/js/portal.js', array( 'unfold-form' ), $v( 'assets/js/portal.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-instructor', UNFOLD_URL . 'assets/js/instructor.js', array(), $v( 'assets/js/instructor.js' ), array( 'strategy' => 'defer' ) );
	}

	private static function shell( $title, $subtitle, $body, $tabs = '' ) {
		$u = wp_get_current_user();
		return '<div class="unfold uf-portal">'
			. '<header class="uf-portal-head"><div><p class="uf-kicker">' . esc_html( $subtitle ) . '</p><h1 class="uf-portal-title">' . esc_html( $title ) . '</h1></div>'
			. '<div class="uf-portal-user"><span class="uf-avatar" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $u->display_name, 0, 1 ) ) ) . '</span><span>' . esc_html( $u->display_name ) . '</span>'
			. '<a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Sign out', 'unfold' ) . '</a></div></header>'
			. $tabs . $body . '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Workspace
	 * ------------------------------------------------------------------ */

	public static function workspace() {
		if ( ! current_user_can( Unfold_Accounts::CAP_SUBMIT ) ) {
			return '';
		}
		wp_enqueue_style( 'unfold-portal' );
		wp_enqueue_style( 'unfold-katex' );
		$config = array(
			'rest'      => esc_url_raw( rest_url( Unfold_Rest::NS ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'workspace' => self::url( 'workspace' ),
			'schema'    => Unfold_Validator::schema(),
			'maxZip'    => Unfold_Workflow::MAX_ZIP_BYTES,
			'ai'        => array(
				'presets'  => Unfold_AI::public_presets(),
				'user'     => Unfold_AI::get_user_config( get_current_user_id() ),
				'defaults' => Unfold_AI::DEFAULT_MODELS,
				'models'   => Unfold_AI::CURATED_MODELS,
			),
		);

		$project = absint( $_GET['project'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $project ) {
			$back = '<p class="uf-back"><a href="' . esc_url( self::url( 'workspace' ) ) . '">← ' . esc_html__( 'Workspace', 'unfold' ) . '</a></p>';
			if ( ! Unfold_Workflow::can_view( $project ) ) {
				return self::shell( __( 'Project not found', 'unfold' ), __( 'Workspace', 'unfold' ), $back );
			}
			if ( ! empty( $_GET['preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$data = Unfold_Store::get( $project );
				wp_enqueue_style( 'unfold' );
				if ( $data && Unfold_Render::csv_sources( $project, $data ) ) {
					wp_enqueue_script( 'unfold-plotly' );
				}
				wp_enqueue_script( 'unfold-project' );
				$body = '<p class="uf-back"><a href="' . esc_url( self::url( 'workspace', array( 'project' => $project ) ) ) . '">← ' . esc_html__( 'Back to editing', 'unfold' ) . '</a></p>'
					. '<p class="uf-preview-label">' . esc_html__( 'Preview — how visitors will see the project once it is accepted', 'unfold' ) . '</p>'
					. ( $data ? Unfold_Render::project_html( $project, $data, true ) : '<div class="uf-alert uf-alert--error">' . esc_html__( 'There is nothing valid to preview yet. Fix the problems listed in the form first.', 'unfold' ) . '</div>' );
				return self::shell( get_the_title( $project ), __( 'Preview', 'unfold' ), $body );
			}
			wp_enqueue_script( 'unfold-portal' );
			wp_localize_script( 'unfold-portal', 'UNFOLD_PORTAL', $config + array( 'project' => $project ) );
			return self::shell( get_the_title( $project ), __( 'Workspace', 'unfold' ), $back . '<div id="uf-workspace" class="uf-workspace" aria-live="polite"><p class="uf-loading">' . esc_html__( 'Loading project…', 'unfold' ) . '</p></div>' );
		}

		wp_enqueue_script( 'unfold-portal' );
		wp_localize_script( 'unfold-portal', 'UNFOLD_PORTAL', $config );

		$reading   = Unfold_Workflow::reader_running() ? __( 'Unfold is reading your files. This takes a few minutes.', 'unfold' ) : __( 'In line. Unfold starts reading as soon as the AI reader is running.', 'unfold' );
		$next_step = array(
			'queued'     => $reading,
			'extracting' => $reading,
			'failed'     => __( 'Reading the files failed. Open the project to try again.', 'unfold' ),
			'review'     => __( 'Check what Unfold found, then submit.', 'unfold' ),
			'submitted'  => __( 'Waiting for the instructor.', 'unfold' ),
			'changes'    => __( 'The instructor returned it with notes.', 'unfold' ),
			'approved'   => __( 'Live on the site.', 'unfold' ),
		);
		$list = '';
		foreach ( get_posts( array( 'post_type' => Unfold_Post_Type::TYPE, 'post_status' => 'any', 'author' => get_current_user_id(), 'numberposts' => -1, 'orderby' => 'modified' ) ) as $p ) {
			$state = Unfold_Workflow::state( $p->ID );
			$list .= '<li class="uf-proj"><a class="uf-proj-link" href="' . esc_url( self::url( 'workspace', array( 'project' => $p->ID ) ) ) . '">'
				. '<span class="uf-state uf-state--' . esc_attr( $state ) . '">' . esc_html( self::state_label( $state ) ) . '</span>'
				. '<h2 class="uf-proj-title">' . esc_html( get_the_title( $p ) ) . '</h2>'
				. '<p class="uf-proj-next">' . esc_html( $next_step[ $state ] ?? '' ) . '</p>'
				. '<p class="uf-proj-meta">' . esc_html( sprintf( __( 'Updated %s ago', 'unfold' ), human_time_diff( (int) get_post_timestamp( $p, 'modified' ) ) ) ) . '</p>'
				. '</a></li>';
		}

		$body = '<section class="uf-upload" id="uf-upload" aria-labelledby="uf-upload-h">'
			. '<h2 id="uf-upload-h" class="uf-h2">' . esc_html__( 'Upload a project', 'unfold' ) . '</h2>'
			. '<p class="uf-muted">' . esc_html__( 'Put the report (PDF or Word), code, data logs (CSV), figures and videos into one ZIP file. Unfold reads the report, breaks the project into parts and fills in a form for you to check.', 'unfold' ) . '</p>'
			. '<div class="uf-ai-box" data-uf-ai></div>'
			. '<label class="uf-drop" data-uf-drop><input type="file" accept=".zip,application/zip" class="screen-reader-text">'
			. '<span class="uf-drop-icon" aria-hidden="true">⤒</span><strong>' . esc_html__( 'Drop the project ZIP here', 'unfold' ) . '</strong><span>' . esc_html__( 'or click to choose a file · up to 1 GB', 'unfold' ) . '</span></label>'
			. '<div class="uf-progress" data-uf-progress hidden><div class="uf-progress-bar"><span></span></div><p data-uf-progress-text></p></div>'
			. '<div class="uf-alert uf-alert--error" data-uf-upload-error role="alert" hidden></div>'
			. '</section>'
			. '<section aria-labelledby="uf-mine-h"><h2 id="uf-mine-h" class="uf-h2">' . esc_html__( 'Your projects', 'unfold' ) . '</h2>'
			. ( $list ? '<ul class="uf-projlist">' . $list . '</ul>' : '<p class="uf-muted">' . esc_html__( 'Nothing uploaded yet.', 'unfold' ) . '</p>' ) . '</section>';
		return self::shell( __( 'Workspace', 'unfold' ), __( 'Upload and manage your projects', 'unfold' ), $body );
	}

	/* ---------------------------------------------------------------------
	 * Instructor portal
	 * ------------------------------------------------------------------ */

	public static function instructor() {
		if ( ! current_user_can( Unfold_Accounts::CAP_REVIEW ) ) {
			return '';
		}
		wp_enqueue_style( 'unfold-portal' );
		wp_enqueue_script( 'unfold-instructor' );
		wp_localize_script( 'unfold-instructor', 'UNFOLD_PORTAL', array( 'rest' => esc_url_raw( rest_url( Unfold_Rest::NS ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );

		$review = absint( $_GET['review'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $review && Unfold_Post_Type::TYPE === get_post_type( $review ) ) {
			return self::review_screen( $review );
		}
		$world = absint( $_GET['world'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $world && Unfold_Worlds::WORLD === get_post_type( $world ) ) {
			return Unfold_Worlds::world_screen( $world );
		}

		$tab  = sanitize_key( $_GET['tab'] ?? 'overview' ); // phpcs:ignore WordPress.Security.NonceVerification
		$o    = Unfold_Stats::overview();
		$tabs = array(
			'overview' => __( 'Overview', 'unfold' ),
			'queue'    => sprintf( __( 'Waiting for review (%d)', 'unfold' ), count( $o['queue'] ) ),
			'projects' => __( 'All projects', 'unfold' ),
			'worlds'   => __( '3D worlds', 'unfold' ),
		);
		$nav = '<nav class="uf-ptabs" aria-label="' . esc_attr__( 'Instructor menu', 'unfold' ) . '">';
		foreach ( $tabs as $k => $label ) {
			$nav .= '<a href="' . esc_url( self::url( 'instructor', array( 'tab' => $k ) ) ) . '"' . ( $k === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		$nav .= '<a class="uf-ptabs-action" href="' . esc_url( self::url( 'workspace' ) ) . '">' . esc_html__( '+ Upload a project', 'unfold' ) . '</a></nav>';

		if ( 'queue' === $tab ) {
			$body = self::queue_table( $o['queue'] );
		} elseif ( 'projects' === $tab ) {
			$body = self::projects_table( $o['rows'] );
		} elseif ( 'worlds' === $tab ) {
			$body = Unfold_Worlds::worlds_tab();
		} else {
			$body = self::overview_html( $o );
		}
		return self::shell( __( 'Instructor', 'unfold' ), __( 'Instructor portal', 'unfold' ), $body, $nav );
	}

	private static function tile( $label, $value, $sub = '', $spark = '' ) {
		return '<div class="uf-tile"><p class="uf-tile-label">' . esc_html( $label ) . '</p><p class="uf-tile-value">' . esc_html( $value ) . '</p>'
			. ( $sub ? '<p class="uf-tile-sub">' . esc_html( $sub ) . '</p>' : '' ) . $spark . '</div>';
	}

	/** Tiny inline trend line (no axes) for tiles and table rows. */
	private static function sparkline( $values, $label ) {
		$n = count( $values );
		if ( $n < 2 || ! array_sum( $values ) ) {
			return '<span class="uf-spark-none">' . esc_html__( 'no views yet', 'unfold' ) . '</span>';
		}
		$w   = 120;
		$h   = 28;
		$max = max( $values );
		$pts = array();
		foreach ( array_values( $values ) as $i => $v ) {
			$pts[] = round( $i * $w / ( $n - 1 ), 1 ) . ',' . round( $h - 2 - ( $v / $max ) * ( $h - 4 ), 1 );
		}
		return '<svg class="uf-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="' . esc_attr( $label ) . '"><polyline points="' . esc_attr( implode( ' ', $pts ) ) . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>';
	}

	private static function overview_html( $o ) {
		$k     = $o['kpi'];
		$days  = $o['daily'];
		$views = wp_list_pluck( $days, 'view' );

		$out = '<div class="uf-tiles">'
			. self::tile( __( 'Waiting for review', 'unfold' ), (string) $k['waiting'], $k['waiting'] ? sprintf( __( 'oldest waiting %s', 'unfold' ), Unfold_Stats::human_duration( $k['oldest_wait'] ) ) : __( 'nothing to review', 'unfold' ) )
			. self::tile( __( 'Returned with notes', 'unfold' ), (string) $k['returned'], __( 'being updated by their owners', 'unfold' ) )
			. self::tile( __( 'Published', 'unfold' ), (string) $k['published'], sprintf( __( 'of %d projects', 'unfold' ), $k['total'] ) )
			. self::tile( __( 'Typical review time', 'unfold' ), Unfold_Stats::human_duration( $k['median_wait'] ), $k['decisions'] ? sprintf( _n( 'median of %d decision', 'median of %d decisions', $k['decisions'], 'unfold' ), $k['decisions'] ) : __( 'no decisions yet', 'unfold' ) )
			. self::tile( __( 'Views, last 30 days', 'unfold' ), number_format_i18n( array_sum( $views ) ), sprintf( __( '%s simulation uses', 'unfold' ), number_format_i18n( array_sum( wp_list_pluck( $days, 'sim' ) ) ) ), self::sparkline( $views, __( 'Daily views, last 30 days', 'unfold' ) ) )
			. '</div>';

		// Trend over time: a line chart drawn by instructor.js from the data attribute.
		$out .= '<section class="uf-panel-box"><h2 class="uf-h3">' . esc_html__( 'Visits to published projects, last 30 days', 'unfold' ) . '</h2>'
			. '<div class="uf-linechart" data-uf-linechart="' . esc_attr( wp_json_encode( $days ) ) . '"><p class="uf-muted">' . esc_html__( 'Loading chart…', 'unfold' ) . '</p></div>'
			. '<p class="uf-muted uf-chart-note">' . esc_html__( 'Anonymous counts. Visits by a project\'s own owner and by instructors are not counted.', 'unfold' ) . '</p></section>';

		// Per project: a table, each row with its own 30-day trend.
		$pub = array_values( array_filter( $o['rows'], static function ( $r ) { return 'approved' === $r['state']; } ) );
		usort( $pub, static function ( $a, $b ) { return array_sum( $b['daily_views'] ) <=> array_sum( $a['daily_views'] ); } );
		$out .= '<section class="uf-panel-box"><h2 class="uf-h3">' . esc_html__( 'Published projects', 'unfold' ) . '</h2>';
		if ( $pub ) {
			$out .= '<div class="uf-table-wrap"><table class="uf-table"><thead><tr><th scope="col">' . esc_html__( 'Project', 'unfold' ) . '</th><th scope="col" class="uf-num">' . esc_html__( 'Views (30 days)', 'unfold' ) . '</th><th scope="col">' . esc_html__( 'Trend', 'unfold' ) . '</th><th scope="col" class="uf-num">' . esc_html__( 'Simulation uses', 'unfold' ) . '</th></tr></thead><tbody>';
			foreach ( $pub as $r ) {
				$out .= '<tr><td data-label="' . esc_attr__( 'Project', 'unfold' ) . '"><a href="' . esc_url( $r['url'] ) . '">' . esc_html( $r['title'] ) . '</a></td>'
					. '<td class="uf-num" data-label="' . esc_attr__( 'Views (30 days)', 'unfold' ) . '">' . esc_html( number_format_i18n( array_sum( $r['daily_views'] ) ) ) . '</td>'
					. '<td data-label="' . esc_attr__( 'Trend', 'unfold' ) . '">' . self::sparkline( $r['daily_views'], sprintf( __( 'Daily views of %s', 'unfold' ), $r['title'] ) ) . '</td>'
					. '<td class="uf-num" data-label="' . esc_attr__( 'Simulation uses', 'unfold' ) . '">' . esc_html( number_format_i18n( $r['sim_uses'] ) ) . '</td></tr>';
			}
			$out .= '</tbody></table></div>';
		} else {
			$out .= '<p class="uf-muted">' . esc_html__( 'Nothing published yet.', 'unfold' ) . '</p>';
		}
		return $out . '</section>';
	}

	private static function queue_table( $queue ) {
		if ( ! $queue ) {
			return '<div class="uf-empty-box"><p>' . esc_html__( 'Nothing is waiting for review.', 'unfold' ) . '</p></div>';
		}
		$out = '<div class="uf-table-wrap"><table class="uf-table"><thead><tr><th scope="col">' . esc_html__( 'Project', 'unfold' ) . '</th><th scope="col">' . esc_html__( 'Submitted by', 'unfold' ) . '</th><th scope="col">' . esc_html__( 'Waiting', 'unfold' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $queue as $r ) {
			$out .= '<tr><td data-label="' . esc_attr__( 'Project', 'unfold' ) . '"><strong>' . esc_html( $r['title'] ) . '</strong></td>'
				. '<td data-label="' . esc_attr__( 'Submitted by', 'unfold' ) . '">' . esc_html( $r['owner'] ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Waiting', 'unfold' ) . '">' . esc_html( Unfold_Stats::human_duration( $r['waiting'] ) ) . '</td>'
				. '<td><a class="uf-btn uf-btn--small" href="' . esc_url( self::url( 'instructor', array( 'review' => $r['id'] ) ) ) . '">' . esc_html__( 'Open', 'unfold' ) . '</a></td></tr>';
		}
		return $out . '</tbody></table></div>';
	}

	private static function projects_table( $rows ) {
		if ( ! $rows ) {
			return '<p class="uf-muted">' . esc_html__( 'No projects yet.', 'unfold' ) . '</p>';
		}
		usort( $rows, static function ( $a, $b ) { return $b['modified'] <=> $a['modified']; } );
		$out = '<div class="uf-table-wrap"><table class="uf-table"><thead><tr><th scope="col">' . esc_html__( 'Project', 'unfold' ) . '</th><th scope="col">' . esc_html__( 'Status', 'unfold' ) . '</th><th scope="col">' . esc_html__( 'Owner', 'unfold' ) . '</th><th scope="col">' . esc_html__( 'Updated', 'unfold' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$out .= '<tr><td data-label="' . esc_attr__( 'Project', 'unfold' ) . '"><strong>' . esc_html( $r['title'] ) . '</strong>' . ( $r['url'] ? '<br><a href="' . esc_url( $r['url'] ) . '">' . esc_html__( 'View live', 'unfold' ) . ' ↗</a>' : '' ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Status', 'unfold' ) . '"><span class="uf-state uf-state--' . esc_attr( $r['state'] ) . '">' . esc_html( self::state_label( $r['state'], 1 ) ) . '</span></td>'
				. '<td data-label="' . esc_attr__( 'Owner', 'unfold' ) . '">' . esc_html( $r['owner'] ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Updated', 'unfold' ) . '">' . esc_html( sprintf( __( '%s ago', 'unfold' ), human_time_diff( $r['modified'] ) ) ) . '</td>'
				. '<td><a class="uf-btn uf-btn--small uf-btn--ghost" href="' . esc_url( self::url( 'instructor', array( 'review' => $r['id'] ) ) ) . '">' . esc_html__( 'Open', 'unfold' ) . '</a></td></tr>';
		}
		return $out . '</tbody></table></div>';
	}

	/** The instructor only decides: accept, or return with notes. */
	private static function review_screen( $id ) {
		$state = Unfold_Workflow::state( $id );
		$data  = Unfold_Store::get( $id );
		if ( ! $data ) {
			$decoded = json_decode( Unfold_Store::get_raw( $id ), true );
			$data    = is_array( $decoded ) ? $decoded : null;
		}
		$owner = get_userdata( (int) get_post_field( 'post_author', $id ) );

		wp_enqueue_style( 'unfold' );
		wp_enqueue_style( 'unfold-katex' );
		if ( $data && Unfold_Render::csv_sources( $id, $data ) ) {
			wp_enqueue_script( 'unfold-plotly' );
		}
		wp_enqueue_script( 'unfold-project' );

		$submitted = null;
		foreach ( array_reverse( Unfold_Workflow::events( $id ) ) as $e ) {
			if ( 'state' === $e['action'] && 'submitted' === ( $e['to'] ?? '' ) ) {
				$submitted = $e['t'];
				break;
			}
		}
		$last = Unfold_Workflow::last_review_note( $id );

		$side  = '<aside class="uf-review-side"><div class="uf-review-card">';
		$side .= '<p><span class="uf-state uf-state--' . esc_attr( $state ) . '">' . esc_html( self::state_label( $state, 1 ) ) . '</span></p>';
		$side .= '<p class="uf-muted">' . esc_html( ( $owner ? $owner->display_name : '—' ) . ( $submitted ? ' · ' . sprintf( __( 'submitted %s ago', 'unfold' ), human_time_diff( $submitted ) ) : '' ) ) . '</p>';
		$side .= '<form class="uf-decision" data-uf-decision="' . esc_attr( $id ) . '">';
		if ( 'approved' === $state ) {
			$side .= '<p>' . esc_html__( 'This project is live.', 'unfold' ) . '</p><label class="uf-field"><span>' . esc_html__( 'Notes (optional)', 'unfold' ) . '</span><textarea name="note" rows="3"></textarea></label>'
				. '<div class="uf-decision-btns"><button class="uf-btn uf-btn--ghost" type="submit" name="decision" value="unpublish">' . esc_html__( 'Take offline and return with notes', 'unfold' ) . '</button></div>';
		} elseif ( 'submitted' === $state ) {
			$side .= '<label class="uf-field"><span>' . esc_html__( 'Notes', 'unfold' ) . '</span><textarea name="note" rows="5" placeholder="' . esc_attr__( 'Needed when you return the project. Optional when you accept.', 'unfold' ) . '"></textarea></label>'
				. '<div class="uf-decision-btns"><button class="uf-btn uf-btn--approve" type="submit" name="decision" value="approve">' . esc_html__( 'Accept & publish', 'unfold' ) . '</button>'
				. '<button class="uf-btn uf-btn--ghost" type="submit" name="decision" value="changes">' . esc_html__( 'Return with notes', 'unfold' ) . '</button></div>';
		} else {
			$side .= '<p class="uf-muted">' . esc_html__( 'Not submitted for review yet.', 'unfold' ) . '</p>';
		}
		$side .= '<p class="uf-decision-msg" role="status"></p></form>';
		if ( $last && '' !== $last['note'] ) {
			$side .= '<p class="uf-last-note"><span class="uf-muted">' . esc_html__( 'Last notes:', 'unfold' ) . '</span> ' . esc_html( $last['note'] ) . '</p>';
		}
		$side .= '</div></aside>';

		$preview = $data ? Unfold_Render::project_html( $id, $data, true ) : '<p class="uf-muted">' . esc_html__( 'Nothing to preview yet.', 'unfold' ) . '</p>';
		$body    = '<p class="uf-back"><a href="' . esc_url( self::url( 'instructor', array( 'tab' => 'queue' ) ) ) . '">← ' . esc_html__( 'Waiting for review', 'unfold' ) . '</a></p>'
			. '<div class="uf-review-layout"><div class="uf-review-preview"><p class="uf-preview-label">' . esc_html__( 'Preview — exactly what visitors will see', 'unfold' ) . '</p>' . $preview . '</div>' . $side . '</div>';
		return self::shell( get_the_title( $id ), __( 'Review', 'unfold' ), $body );
	}

	/* ---------------------------------------------------------------------
	 * Header account link
	 * ------------------------------------------------------------------ */

	public static function account_link() {
		if ( ! is_user_logged_in() ) {
			return '<a class="uf-account" href="' . esc_url( self::url( 'login' ) ) . '">' . esc_html__( 'Sign in', 'unfold' ) . '</a>';
		}
		$u     = wp_get_current_user();
		$label = user_can( $u, Unfold_Accounts::CAP_REVIEW ) ? __( 'Instructor', 'unfold' ) : __( 'Workspace', 'unfold' );
		return '<a class="uf-account is-in" href="' . esc_url( Unfold_Accounts::home_for( $u ) ) . '"><span class="uf-avatar" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $u->display_name, 0, 1 ) ) ) . '</span>' . esc_html( $label ) . '</a>';
	}
}
