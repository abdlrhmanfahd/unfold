<?php
/**
 * 3D worlds (instructors only).
 *
 * A world is one place in one coordinate frame (the lab, a building). It is built from drives:
 * raw QCar 2 sessions exactly as recorded on the car (bag/bag_0.db3, calib/, notes.txt), uploaded
 * as a ZIP. A worker (tools/session_export/world_worker.py) turns each drive into a map, the car's
 * path, camera frames and LiDAR scans, and places it in the world. Everything is private: raw files
 * and outputs live in wp-content/unfold-private/ and are served only to users who can review.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Worlds {

	const WORLD = 'unfold_world';
	const DRIVE = 'unfold_drive';

	const MAX_ZIP_BYTES = 17179869184; // 16 GB: one drive is ~3 GB raw.
	const CLAIM_TIMEOUT = 5400;        // A claimed drive is re-queued after 90 min without progress.
	const STATES        = array( 'queued', 'processing', 'ready', 'failed' );

	/** Files a viewer may fetch from a drive's output folder. */
	const FILE_RE = '/^(map\.bin|map\.json|path\.json|frames\.json|scans\.json|frames-\d{3}\.bin|scans-\d{3}\.bin|preview_top\.png|plan\.json|plan\.bin|preview_plan\.png|map_rgb\.bin|mesh\.json|mesh\.bin|cover\.png)$/';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ), 16 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		add_shortcode( 'unfold_worlds', array( __CLASS__, 'worlds_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_recordings' ) );
	}

	public static function register_assets() {
		$v = static function ( $rel ) {
			return UNFOLD_VERSION . '.' . ( is_file( UNFOLD_DIR . $rel ) ? filemtime( UNFOLD_DIR . $rel ) : 0 );
		};
		wp_register_style( 'unfold-worlds', UNFOLD_URL . 'assets/css/worlds.css', array( 'unfold' ), $v( 'assets/css/worlds.css' ) );
		wp_register_script( 'unfold-worlds', UNFOLD_URL . 'assets/js/worlds.js', array(), $v( 'assets/js/worlds.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-world', UNFOLD_URL . 'assets/js/world.js', array( 'unfold-qcar' ), $v( 'assets/js/world.js' ), array( 'strategy' => 'defer' ) );
	}

	private static function config() {
		return array(
			'rest'       => esc_url_raw( rest_url( Unfold_Rest::NS ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'worldUrl'   => esc_url_raw( Unfold_Portals::url( 'instructor', array( 'world' => '__ID__' ) ) ),
			'workerSeen' => (int) get_option( 'unfold_world_worker_seen', 0 ),
			'autoStart'  => defined( 'UNFOLD_WORLD_PYTHON' ) && defined( 'UNFOLD_WORLD_WORKER' ),
			'now'        => time(),
		);
	}

	private static function public_config() {
		return array(
			'rest'  => esc_url_raw( rest_url( Unfold_Rest::NS ) ),
			'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Instructor screens
	 * ------------------------------------------------------------------ */

	/** Portal tab: worlds, their recordings and their state. Filled in by worlds.js. */
	public static function worlds_tab() {
		wp_enqueue_style( 'unfold-worlds' );
		wp_enqueue_script( 'unfold-worlds' );
		wp_localize_script( 'unfold-worlds', 'UNFOLD_WORLDS', self::config() );
		return '<div class="ufw" data-ufw-list><p class="ufw-empty">' . esc_html__( 'Loading…', 'unfold' ) . '</p></div>';
	}

	/** The 3D simulation of one world (instructor portal): the same view as the public page, plus its state. */
	public static function world_screen( $world_id ) {
		wp_enqueue_style( 'unfold-portal' );
		$back = Unfold_Portals::url( 'instructor', array( 'tab' => 'worlds' ) );
		$pub  = self::is_published( $world_id );
		return '<div class="unfold uf-portal ufw-screen">'
			. '<header class="ufw-head"><a class="ufw-back" href="' . esc_url( $back ) . '">' . esc_html__( '← 3D worlds', 'unfold' ) . '</a>'
			. '<h1 class="ufw-title">' . esc_html( get_the_title( $world_id ) ) . '</h1>'
			. ( $pub ? '<a class="ufw-pill ufw-pill--ready" href="' . esc_url( get_permalink( $world_id ) ) . '">' . esc_html__( 'On the site', 'unfold' ) . ' ↗</a>' : '<span class="ufw-pill">' . esc_html__( 'Not published', 'unfold' ) . '</span>' )
			. '</header>' . self::sim_markup( $world_id, true ) . '</div>';
	}

	/** Stats line for a world: recordings, metres driven, minutes. */
	public static function stats( $world_id ) {
		$n = 0;
		$m = 0.0;
		$t = 0.0;
		foreach ( self::drives( $world_id ) as $d ) {
			if ( 'ready' !== self::state( $d->ID ) ) {
				continue;
			}
			$s = (array) get_post_meta( $d->ID, '_unfold_summary', true );
			$n++;
			$m += (float) ( $s['path_length_m'] ?? 0 );
			$t += (float) ( $s['duration_s'] ?? 0 );
		}
		return array( 'recordings' => $n, 'metres' => $m, 'minutes' => $t / 60 );
	}

	/** 3D simulation (and, when the world has clips, a "Sensor replay" toggle). */
	public static function sim_markup( $world_id, $staff = false ) {
		wp_enqueue_style( 'unfold-worlds' );
		wp_enqueue_script( 'unfold-world' );
		$cfg = $staff ? self::config() : self::public_config();
		wp_localize_script( 'unfold-world', 'UNFOLD_WORLDS', $cfg + array( 'world' => absint( $world_id ) ) );
		$clips  = self::clips( $world_id );
		$toggle = '';
		$replay = '';
		if ( $clips ) {
			$toggle = '<div class="ufw-switch" role="tablist" aria-label="' . esc_attr__( 'View', 'unfold' ) . '">'
				. '<button type="button" role="tab" data-panel="sim" aria-selected="true">' . esc_html__( '3D simulation', 'unfold' ) . '</button>'
				. '<button type="button" role="tab" data-panel="replay" aria-selected="false">' . esc_html__( 'Sensor replay', 'unfold' ) . '</button></div>';
			$replay = '<div class="ufw-panel" data-panel-body="replay" hidden>' . Unfold_Session::shortcode( array( 'id' => $clips[0] ) ) . '</div>';
		}
		return '<div class="ufw-page" data-ufw-page>' . $toggle . '<div class="ufw-panel" data-panel-body="sim">'
			. '<section class="ufw-sim" data-ufw-sim aria-label="' . esc_attr__( '3D simulation', 'unfold' ) . '">'
			. '<div class="ufw-stage"><div class="ufw-gl"></div>'
			. '<figure class="ufw-inset"><canvas aria-label="' . esc_attr__( 'Front camera', 'unfold' ) . '" role="img"></canvas></figure>'
			. '<div class="ufw-hud" aria-live="off"><span data-hud="speed">–</span><span data-hud="turn">–</span></div>'
			. '<div class="ufw-views" role="group" aria-label="' . esc_attr__( 'Camera view', 'unfold' ) . '">'
			. '<button type="button" data-view="chase" aria-pressed="true">' . esc_html__( 'Behind', 'unfold' ) . '</button>'
			. '<button type="button" data-view="top" aria-pressed="false">' . esc_html__( 'Top', 'unfold' ) . '</button>'
			. '<button type="button" data-view="driver" aria-pressed="false">' . esc_html__( 'Driver', 'unfold' ) . '</button>'
			. '<button type="button" data-view="free" aria-pressed="false">' . esc_html__( 'Free', 'unfold' ) . '</button></div>'
			. '<div class="ufw-modes" role="group" aria-label="' . esc_attr__( 'Look', 'unfold' ) . '">'
			. '<button type="button" data-mode="points" aria-pressed="true">' . esc_html__( 'LiDAR points', 'unfold' ) . '</button>'
			. '<button type="button" data-mode="camera" aria-pressed="false">' . esc_html__( 'Real colours', 'unfold' ) . '</button>'
			. '<button type="button" data-mode="clean" aria-pressed="false">' . esc_html__( 'Clean', 'unfold' ) . '</button>'
			. '<button type="button" data-mode="split" aria-pressed="false">' . esc_html__( 'Side by side', 'unfold' ) . '</button></div>'
			. '<div class="ufw-toggles" role="group" aria-label="' . esc_attr__( 'Layers', 'unfold' ) . '">'
			. '<button type="button" data-layer="ceiling" aria-pressed="false">' . esc_html__( 'Ceiling', 'unfold' ) . '</button>'
			. '<button type="button" data-layer="scan" aria-pressed="true">' . esc_html__( 'Live LiDAR', 'unfold' ) . '</button>'
			. '<button type="button" data-layer="camera" aria-pressed="true">' . esc_html__( 'Camera', 'unfold' ) . '</button></div>'
			. '<p class="ufw-status" aria-live="polite">' . esc_html__( 'Loading the world…', 'unfold' ) . '</p></div>'
			. '<div class="ufw-bar">'
			. '<button type="button" class="ufw-play" aria-label="' . esc_attr__( 'Play', 'unfold' ) . '"><span aria-hidden="true"></span></button>'
			. '<span class="ufw-drives" role="group" aria-label="' . esc_attr__( 'Recording', 'unfold' ) . '"></span>'
			. '<input type="range" class="ufw-slider" min="0" max="1" step="0.01" value="0" aria-label="' . esc_attr__( 'Time', 'unfold' ) . '">'
			. '<output class="ufw-time">0:00 / 0:00</output>'
			. '<span class="ufw-seg ufw-speed" role="group" aria-label="' . esc_attr__( 'Speed', 'unfold' ) . '">'
			. '<button type="button" data-speed="1" aria-pressed="true">1×</button><button type="button" data-speed="2" aria-pressed="false">2×</button><button type="button" data-speed="4" aria-pressed="false">4×</button></span>'
			. '</div></section></div>' . $replay . '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Public pages
	 * ------------------------------------------------------------------ */

	/** /worlds/<slug>/: the world as a project page. */
	public static function filter_content( $content ) {
		static $busy = false;
		if ( $busy || ! is_singular( self::WORLD ) || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$busy = true;
		$id   = get_queried_object_id();
		$st   = self::stats( $id );
		$line = array_filter( array(
			sprintf( _n( '%d recording', '%d recordings', $st['recordings'], 'unfold' ), $st['recordings'] ),
			$st['metres'] ? sprintf( __( '%s m driven', 'unfold' ), number_format_i18n( $st['metres'] ) ) : '',
			$st['minutes'] ? sprintf( __( '%s min', 'unfold' ), number_format_i18n( $st['minutes'], 0 ) ) : '',
		) );
		$html = '<div class="unfold ufw-public"><header class="ufw-head"><p class="ufw-kind">' . esc_html__( '3D world · QCar 2', 'unfold' ) . '</p>'
			. '<h1 class="ufw-title">' . esc_html( get_the_title( $id ) ) . '</h1><p class="ufw-stats">' . esc_html( implode( ' · ', $line ) ) . '</p></header>'
			. self::sim_markup( $id ) . '</div>';
		$busy = false;
		return $html;
	}

	/** Floor plan of the first ready recording, used as the card picture. */
	public static function cover_url( $world_id ) {
		foreach ( self::drives( $world_id ) as $d ) {
			if ( 'ready' === self::state( $d->ID ) && is_file( self::out_dir( $d->ID ) . '/cover.png' ) ) {
				return add_query_arg( 'name', 'cover.png', rest_url( Unfold_Rest::NS . '/drives/' . $d->ID . '/file' ) );
			}
		}
		return '';
	}

	/** [unfold_worlds] — published worlds as project cards. Renders nothing when there are none. */
	public static function worlds_shortcode( $atts ) {
		$atts   = shortcode_atts( array( 'heading' => 'yes' ), $atts, 'unfold_worlds' );
		$worlds = get_posts( array( 'post_type' => self::WORLD, 'post_status' => 'publish', 'numberposts' => 50, 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( ! $worlds ) {
			return '';
		}
		wp_enqueue_style( 'unfold' );
		wp_enqueue_style( 'unfold-worlds' );
		$cards = '';
		foreach ( $worlds as $w ) {
			$st    = self::stats( $w->ID );
			$cover = self::cover_url( $w->ID );
			$cards .= '<li class="uf-card ufw-card-world"><a class="uf-card-link" href="' . esc_url( get_permalink( $w ) ) . '">'
				. '<div class="uf-card-media ufw-card-media">' . ( $cover ? '<img src="' . esc_url( $cover ) . '" alt="" loading="lazy">' : '' ) . '<span class="ufw-card-tag">' . esc_html__( '3D world', 'unfold' ) . '</span></div>'
				. '<div class="uf-card-body"><h2 class="uf-card-title">' . esc_html( get_the_title( $w ) ) . '</h2>'
				. '<p class="uf-card-meta"><span>' . esc_html( sprintf( _n( '%d recording', '%d recordings', $st['recordings'], 'unfold' ), $st['recordings'] ) ) . '</span>'
				. ( $st['metres'] ? '<span>' . esc_html( sprintf( __( '%s m driven', 'unfold' ), number_format_i18n( $st['metres'] ) ) ) . '</span>' : '' )
				. ( self::clips( $w->ID ) ? '<span>' . esc_html__( 'Sensor replay', 'unfold' ) . '</span>' : '' ) . '</p></div></a></li>';
		}
		return '<div class="unfold uf-gallery ufw-worlds">'
			. ( 'yes' === $atts['heading'] ? '<h2 class="ufw-worlds-title">' . esc_html__( '3D worlds', 'unfold' ) . '</h2>' : '' )
			. '<ul class="uf-cards">' . $cards . '</ul></div>';
	}

	/** The old Recordings page now lives inside its world (as "Sensor replay"). */
	public static function redirect_recordings() {
		if ( ! is_page( 'recordings' ) ) {
			return;
		}
		foreach ( get_posts( array( 'post_type' => self::WORLD, 'post_status' => 'publish', 'numberposts' => 20 ) ) as $w ) {
			if ( self::clips( $w->ID ) ) {
				wp_safe_redirect( get_permalink( $w ), 301 );
				exit;
			}
		}
	}

	public static function register() {
		$args = array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title' ) );
		// A world is a draft until an instructor publishes it; then it has a public page at /worlds/<slug>/.
		register_post_type( self::WORLD, array(
			'label'               => __( '3D worlds', 'unfold' ),
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'show_in_nav_menus'   => false,
			'has_archive'         => false,
			'rewrite'             => array( 'slug' => 'worlds', 'with_front' => false ),
			'supports'            => array( 'title' ),
		) );
		register_post_type( self::DRIVE, $args + array( 'label' => __( 'Drives', 'unfold' ), 'hierarchical' => false ) );
		if ( get_option( 'unfold_worlds_rewrite' ) !== '1' ) {
			flush_rewrite_rules( false );
			update_option( 'unfold_worlds_rewrite', '1' );
		}
	}

	public static function is_published( $world_id ) {
		return 'publish' === get_post_status( $world_id );
	}

	/** Replay clips (uploads/unfold/sessions/<id>/) shown with this world as "Sensor replay". */
	public static function clips( $world_id ) {
		$c = get_post_meta( $world_id, '_unfold_clips', true );
		return array_values( array_filter( is_array( $c ) ? $c : array(), static function ( $id ) {
			return (bool) Unfold_Session::locate( $id );
		} ) );
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	public static function drive_dir( $drive_id ) {
		$dir = Unfold_Workflow::private_root() . '/worlds/drive-' . absint( $drive_id );
		wp_mkdir_p( $dir . '/out' );
		return $dir;
	}

	public static function out_dir( $drive_id ) {
		return self::drive_dir( $drive_id ) . '/out';
	}

	/** The title as typed (no typographic entities like &#8211;), for JSON that the page escapes itself. */
	public static function plain_title( $post ) {
		return html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public static function zip_path( $drive_id ) {
		$of = absint( get_post_meta( $drive_id, '_unfold_zip_of', true ) );
		$p  = self::drive_dir( $of ? $of : $drive_id ) . '/raw.zip';
		return is_file( $p ) ? $p : null;
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	public static function worlds() {
		return get_posts( array( 'post_type' => self::WORLD, 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 100, 'orderby' => 'date', 'order' => 'ASC' ) );
	}

	public static function drives( $world_id ) {
		return get_posts( array( 'post_type' => self::DRIVE, 'post_status' => 'publish', 'post_parent' => absint( $world_id ), 'numberposts' => 200, 'orderby' => 'date', 'order' => 'ASC' ) );
	}

	public static function state( $drive_id ) {
		$s = get_post_meta( $drive_id, '_unfold_state', true );
		return in_array( $s, self::STATES, true ) ? $s : 'queued';
	}

	/** Identity unless the worker or an instructor placed the drive in the world. Row-major 4x4. */
	public static function transform( $drive_id ) {
		$t = get_post_meta( $drive_id, '_unfold_T', true );
		return ( is_array( $t ) && 16 === count( $t ) ) ? array_map( 'floatval', $t ) : array( 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1 );
	}

	public static function drive_payload( $d ) {
		$id    = $d->ID;
		$state = self::state( $id );
		$prog  = get_post_meta( $id, '_unfold_progress', true );
		$sum   = get_post_meta( $id, '_unfold_summary', true );
		return array(
			'id'        => $id,
			'name'      => self::plain_title( $d ),
			'state'     => $state,
			'progress'  => is_array( $prog ) ? $prog : null,
			'started'   => 'processing' === $state ? (int) ( get_post_meta( $id, '_unfold_job', true )['claimed_at'] ?? 0 ) : 0,
			'error'     => 'failed' === $state ? (string) get_post_meta( $id, '_unfold_error', true ) : '',
			'summary'   => is_array( $sum ) ? $sum : null,
			'T'         => self::transform( $id ),
			'placement' => (string) get_post_meta( $id, '_unfold_placement', true ),
			'files'     => 'ready' === $state ? rest_url( Unfold_Rest::NS . '/drives/' . $id . '/file' ) : null,
			'uploaded'  => get_post_time( 'U', true, $d ),
		);
	}

	public static function world_payload( $w ) {
		$pub = self::is_published( $w->ID );
		return array(
			'id'        => $w->ID,
			'name'      => self::plain_title( $w ),
			'published' => $pub,
			'url'       => $pub ? get_permalink( $w ) : '',
			'drives'    => array_map( array( __CLASS__, 'drive_payload' ), self::drives( $w->ID ) ),
		);
	}

	/** What a viewer needs (no errors, no job details): ready recordings only. */
	public static function view_payload( $w ) {
		$drives = array();
		foreach ( self::drives( $w->ID ) as $d ) {
			if ( 'ready' === self::state( $d->ID ) ) {
				$p        = self::drive_payload( $d );
				$drives[] = array_intersect_key( $p, array_flip( array( 'id', 'name', 'state', 'T', 'placement', 'files', 'summary' ) ) );
			}
		}
		return array( 'id' => $w->ID, 'name' => self::plain_title( $w ), 'drives' => $drives );
	}

	private static function can_view_world( $world_id ) {
		return self::WORLD === get_post_type( $world_id ) && ( self::is_published( $world_id ) || current_user_can( Unfold_Accounts::CAP_REVIEW ) );
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	public static function routes() {
		$ns     = Unfold_Rest::NS;
		$staff  = static function () {
			return current_user_can( Unfold_Accounts::CAP_REVIEW );
		};
		$worker = array( 'Unfold_Rest', 'worker_auth' );
		$num    = array( 'id' => array( 'validate_callback' => static function ( $v ) { return is_numeric( $v ); } ) );

		register_rest_route( $ns, '/worlds', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $staff ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => $staff ),
		) );
		register_rest_route( $ns, '/worlds/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_world' ), 'permission_callback' => $staff, 'args' => $num ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_delete_world' ), 'permission_callback' => $staff, 'args' => $num ),
		) );
		register_rest_route( $ns, '/drives/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_delete_drive' ), 'permission_callback' => $staff, 'args' => $num ) );
		register_rest_route( $ns, '/worlds/(?P<id>\d+)/uploads', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_upload_start' ), 'permission_callback' => $staff, 'args' => $num ) );
		register_rest_route( $ns, '/worlds/(?P<id>\d+)/uploads/(?P<uid>[a-f0-9]{32})/complete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_upload_complete' ), 'permission_callback' => $staff, 'args' => $num ) );
		register_rest_route( $ns, '/drives/(?P<id>\d+)/retry', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_retry' ), 'permission_callback' => $staff, 'args' => $num ) );
		// Files and the viewer payload: anyone for a published world, instructors for drafts.
		register_rest_route( $ns, '/drives/(?P<id>\d+)/file', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_file' ), 'args' => $num,
			'permission_callback' => static function ( WP_REST_Request $r ) {
				return self::can_view_world( (int) wp_get_post_parent_id( (int) $r['id'] ) );
			} ) );
		register_rest_route( $ns, '/worlds/(?P<id>\d+)/view', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_view' ), 'args' => $num,
			'permission_callback' => static function ( WP_REST_Request $r ) {
				return self::can_view_world( (int) $r['id'] );
			} ) );
		register_rest_route( $ns, '/worlds/(?P<id>\d+)/rename', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_rename' ), 'permission_callback' => $staff, 'args' => $num ) );
		register_rest_route( $ns, '/drives/(?P<id>\d+)/rename', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_rename' ), 'permission_callback' => $staff, 'args' => $num ) );
		register_rest_route( $ns, '/worlds/(?P<id>\d+)/publish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_publish' ), 'permission_callback' => $staff, 'args' => $num ) );

		register_rest_route( $ns, '/worker/drives/next', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'worker_next' ), 'permission_callback' => $worker ) );
		register_rest_route( $ns, '/worker/drives/(?P<id>\d+)/zip', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'worker_zip' ), 'permission_callback' => $worker, 'args' => $num ) );
		// Progress and result carry the job id, a random UUID only the site and its worker know; it is checked in job_ok().
		register_rest_route( $ns, '/worker/drives/(?P<id>\d+)/progress', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'worker_progress' ), 'permission_callback' => '__return_true', 'args' => $num ) );
		register_rest_route( $ns, '/worker/drives/(?P<id>\d+)/result', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'worker_result' ), 'permission_callback' => '__return_true', 'args' => $num ) );
	}

	private static function world_or_error( $id ) {
		$w = get_post( absint( $id ) );
		return ( $w && self::WORLD === $w->post_type ) ? $w : new WP_Error( 'unfold_world', __( 'World not found.', 'unfold' ), array( 'status' => 404 ) );
	}

	private static function drive_or_error( $id ) {
		$d = get_post( absint( $id ) );
		return ( $d && self::DRIVE === $d->post_type ) ? $d : new WP_Error( 'unfold_drive', __( 'Recording not found.', 'unfold' ), array( 'status' => 404 ) );
	}

	public static function rest_list() {
		return array_map( array( __CLASS__, 'world_payload' ), self::worlds() );
	}

	public static function rest_create( WP_REST_Request $r ) {
		$name = trim( sanitize_text_field( (string) $r['name'] ) );
		if ( '' === $name || mb_strlen( $name ) > 60 ) {
			return new WP_Error( 'unfold_name', __( 'Give the world a name (up to 60 characters).', 'unfold' ), array( 'status' => 400 ) );
		}
		$id = wp_insert_post( array( 'post_type' => self::WORLD, 'post_status' => 'draft', 'post_title' => $name, 'post_name' => sanitize_title( $name ), 'post_author' => get_current_user_id() ), true );
		return is_wp_error( $id ) ? $id : self::world_payload( get_post( $id ) );
	}

	public static function rest_view( WP_REST_Request $r ) {
		return self::view_payload( get_post( (int) $r['id'] ) );
	}

	/** Rename a world or a recording. A draft world's address follows its name; a published one keeps its link. */
	public static function rest_rename( WP_REST_Request $r ) {
		$is_world = false !== strpos( $r->get_route(), '/worlds/' );
		$obj      = $is_world ? self::world_or_error( $r['id'] ) : self::drive_or_error( $r['id'] );
		if ( is_wp_error( $obj ) ) {
			return $obj;
		}
		$name = trim( sanitize_text_field( (string) $r['name'] ) );
		if ( '' === $name || mb_strlen( $name ) > 60 ) {
			return new WP_Error( 'unfold_name', __( 'Use a name of 1 to 60 characters.', 'unfold' ), array( 'status' => 400 ) );
		}
		$up = array( 'ID' => $obj->ID, 'post_title' => $name );
		if ( $is_world && ! self::is_published( $obj->ID ) ) {
			$up['post_name'] = sanitize_title( $name );
		}
		wp_update_post( $up );
		$w = get_post( $is_world ? $obj->ID : $obj->post_parent );
		return self::world_payload( $w );
	}

	public static function rest_publish( WP_REST_Request $r ) {
		$w = self::world_or_error( $r['id'] );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		$on = rest_sanitize_boolean( $r['on'] ?? true );
		if ( $on && ! array_filter( self::drives( $w->ID ), static function ( $d ) { return 'ready' === self::state( $d->ID ); } ) ) {
			return new WP_Error( 'unfold_empty', __( 'Publish it once at least one recording is ready.', 'unfold' ), array( 'status' => 409 ) );
		}
		wp_update_post( array( 'ID' => $w->ID, 'post_status' => $on ? 'publish' : 'draft' ) );
		return self::world_payload( get_post( $w->ID ) );
	}

	public static function rest_world( WP_REST_Request $r ) {
		$w = self::world_or_error( $r['id'] );
		return is_wp_error( $w ) ? $w : self::world_payload( $w );
	}

	/** Same chunked upload as project ZIPs (PUT /uploads/<uid>), with a larger limit. */
	public static function rest_upload_start( WP_REST_Request $r ) {
		$w = self::world_or_error( $r['id'] );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		$name = (string) $r['name'];
		$size = (int) $r['size'];
		if ( $size <= 0 || $size > self::MAX_ZIP_BYTES ) {
			return new WP_Error( 'unfold_size', __( 'The ZIP must be smaller than 16 GB.', 'unfold' ), array( 'status' => 400 ) );
		}
		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'unfold_type', __( 'Upload the recording as a .zip file.', 'unfold' ), array( 'status' => 400 ) );
		}
		Unfold_Workflow::purge_stale_uploads();
		$uid = bin2hex( random_bytes( 16 ) );
		wp_mkdir_p( Unfold_Workflow::upload_dir( $uid ) );
		set_transient( 'unfold_upload_' . $uid, array( 'user' => get_current_user_id(), 'name' => sanitize_file_name( $name ), 'size' => $size, 'received' => 0, 'world' => $w->ID ), DAY_IN_SECONDS );
		return array( 'upload_id' => $uid, 'chunk_bytes' => Unfold_Workflow::CHUNK_BYTES );
	}

	public static function rest_upload_complete( WP_REST_Request $r ) {
		$w   = self::world_or_error( $r['id'] );
		$uid = (string) $r['uid'];
		$u   = get_transient( 'unfold_upload_' . $uid );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		if ( ! $u || (int) $u['user'] !== get_current_user_id() || (int) ( $u['world'] ?? 0 ) !== $w->ID ) {
			return new WP_Error( 'unfold_upload', __( 'Upload not found or expired.', 'unfold' ), array( 'status' => 404 ) );
		}
		$tmp  = Unfold_Workflow::upload_dir( $uid );
		$part = $tmp . '/part';
		if ( (int) $u['received'] !== (int) $u['size'] || ! is_file( $part ) ) {
			return new WP_Error( 'unfold_upload', __( 'The upload is not complete.', 'unfold' ), array( 'status' => 400 ) );
		}
		$sessions = self::sessions_in_zip( $part );
		if ( is_wp_error( $sessions ) ) {
			self::rrmdir( $tmp );
			delete_transient( 'unfold_upload_' . $uid );
			return $sessions;
		}
		// One drive per recording folder in the ZIP; the first drive keeps the file, the others point to it.
		$owner = 0;
		foreach ( $sessions as $folder ) {
			$title = '' !== $folder ? wp_basename( $folder ) : preg_replace( '/\.zip$/i', '', $u['name'] );
			$id    = wp_insert_post( array( 'post_type' => self::DRIVE, 'post_status' => 'publish', 'post_parent' => $w->ID, 'post_title' => $title, 'post_author' => get_current_user_id() ), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( ! $owner ) {
				$owner = $id;
				rename( $part, self::drive_dir( $id ) . '/raw.zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} else {
				update_post_meta( $id, '_unfold_zip_of', $owner );
			}
			update_post_meta( $id, '_unfold_session', $folder );
			self::queue( $id );
		}
		self::rrmdir( $tmp );
		delete_transient( 'unfold_upload_' . $uid );
		self::launch();
		return self::world_payload( $w );
	}

	/**
	 * Folders in the ZIP that look like a QCar 2 recording: <folder>/bag/bag_0.db3 (+ notes.txt).
	 * Reads only the central directory.
	 */
	public static function sessions_in_zip( $file ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'unfold_zip', 'ZipArchive is missing on this server.', array( 'status' => 500 ) );
		}
		$z = new ZipArchive();
		if ( true !== $z->open( $file ) ) {
			return new WP_Error( 'unfold_zip', __( 'That file is not a readable ZIP.', 'unfold' ), array( 'status' => 400 ) );
		}
		$found = array();
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$n = str_replace( '\\', '/', (string) $z->getNameIndex( $i ) );
			if ( preg_match( '#^(?:(.*)/)?bag/[^/]+\.db3$#', $n, $m ) ) {
				$found[ $m[1] ?? '' ] = true;
			}
		}
		$z->close();
		if ( ! $found ) {
			return new WP_Error( 'unfold_zip', __( 'No recording found in the ZIP. It should contain the session folder from the car, with bag/bag_0.db3 inside.', 'unfold' ), array( 'status' => 400 ) );
		}
		return array_map( 'strval', array_keys( $found ) );
	}

	public static function queue( $id ) {
		update_post_meta( $id, '_unfold_job', array( 'id' => wp_generate_uuid4(), 'claimed_at' => 0 ) );
		update_post_meta( $id, '_unfold_state', 'queued' );
		delete_post_meta( $id, '_unfold_error' );
		update_post_meta( $id, '_unfold_progress', array( 'pct' => 0, 'stage' => 'queued' ) );
	}

	public static function rest_retry( WP_REST_Request $r ) {
		$d = self::drive_or_error( $r['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		if ( ! self::zip_path( $d->ID ) ) {
			return new WP_Error( 'unfold_nozip', __( 'The recording file is missing. Upload it again.', 'unfold' ), array( 'status' => 409 ) );
		}
		self::queue( $d->ID );
		self::launch();
		return self::drive_payload( get_post( $d->ID ) );
	}

	/* ---------------------------------------------------------------------
	 * Delete
	 * ------------------------------------------------------------------ */

	private static function busy_error() {
		return new WP_Error( 'unfold_busy', __( 'This recording is being built right now. Delete it when it finishes.', 'unfold' ), array( 'status' => 409 ) );
	}

	/** Remove one recording: its post, its outputs and, unless another recording of the same ZIP still needs it, the raw file. */
	public static function delete_drive( $id ) {
		$dir = Unfold_Workflow::private_root() . '/worlds/drive-' . absint( $id );
		// Recordings split from the same ZIP point at the first one's raw.zip: hand the file to the next one.
		$heirs = get_posts( array( 'post_type' => self::DRIVE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_unfold_zip_of', 'meta_value' => $id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $heirs && is_file( $dir . '/raw.zip' ) ) {
			$heir = array_shift( $heirs );
			rename( $dir . '/raw.zip', self::drive_dir( $heir ) . '/raw.zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			delete_post_meta( $heir, '_unfold_zip_of' );
			foreach ( $heirs as $h ) {
				update_post_meta( $h, '_unfold_zip_of', $heir );
			}
		}
		self::rrmdir( $dir );
		wp_delete_post( $id, true );
	}

	public static function rest_delete_drive( WP_REST_Request $r ) {
		$d = self::drive_or_error( $r['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		if ( 'processing' === self::state( $d->ID ) ) {
			return self::busy_error();
		}
		$world = (int) $d->post_parent;
		self::delete_drive( $d->ID );
		$w = get_post( $world );
		return $w ? self::world_payload( $w ) : array( 'ok' => true );
	}

	public static function rest_delete_world( WP_REST_Request $r ) {
		$w = self::world_or_error( $r['id'] );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		$drives = self::drives( $w->ID );
		foreach ( $drives as $d ) {
			if ( 'processing' === self::state( $d->ID ) ) {
				return self::busy_error();
			}
		}
		// Delete the recordings that borrow a ZIP first, so no file gets handed over needlessly.
		usort( $drives, static function ( $a, $b ) {
			return (int) (bool) get_post_meta( $b->ID, '_unfold_zip_of', true ) <=> (int) (bool) get_post_meta( $a->ID, '_unfold_zip_of', true );
		} );
		foreach ( $drives as $d ) {
			self::delete_drive( $d->ID );
		}
		wp_delete_post( $w->ID, true );
		return array( 'ok' => true );
	}

	public static function rest_file( WP_REST_Request $r ) {
		$d    = self::drive_or_error( $r['id'] );
		$name = (string) $r->get_param( 'name' );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		if ( ! preg_match( self::FILE_RE, $name ) ) {
			return new WP_Error( 'unfold_file', 'Unknown file.', array( 'status' => 404 ) );
		}
		$file = self::out_dir( $d->ID ) . '/' . $name;
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'unfold_file', 'Missing.', array( 'status' => 404 ) );
		}
		$types = array( 'json' => 'application/json', 'png' => 'image/png', 'bin' => 'application/octet-stream' );
		$ext   = pathinfo( $file, PATHINFO_EXTENSION );
		if ( headers_sent() ) {
			return new WP_REST_Response( array( 'file' => $name, 'size' => filesize( $file ) ), 200 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		// The browser keeps a copy but asks every time whether it changed (outputs are rebuilt in place).
		$etag = '"' . md5( filemtime( $file ) . ':' . filesize( $file ) ) . '"';
		header( 'Cache-Control: private, no-cache' );
		header( 'ETag: ' . $etag );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			status_header( 304 );
			exit;
		}
		header( 'Content-Type: ' . $types[ $ext ] );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Worker
	 * ------------------------------------------------------------------ */

	public static function worker_next() {
		update_option( 'unfold_world_worker_seen', time(), false );
		$job = self::claim();
		return $job ? $job : new WP_REST_Response( null, 204 );
	}

	/** Claim the oldest waiting recording (or one whose worker went silent). Returns the job, or null. */
	public static function claim() {
		$query = static function ( $state ) {
			return get_posts( array(
				'post_type'   => self::DRIVE,
				'post_status' => 'publish',
				'numberposts' => 20,
				'orderby'     => 'date',
				'order'       => 'ASC',
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_unfold_state', 'value' => $state ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
		};
		foreach ( array( 'queued', 'processing' ) as $target_state ) {
			foreach ( $query( $target_state ) as $id ) {
				$job = get_post_meta( $id, '_unfold_job', true );
				if ( ! is_array( $job ) || ! self::zip_path( $id ) ) {
					continue;
				}
				if ( 'processing' === $target_state ) {
					$prog = get_post_meta( $id, '_unfold_progress', true );
					$seen = max( (int) ( $job['claimed_at'] ?? 0 ), (int) ( $prog['at'] ?? 0 ) );
					if ( time() - $seen < self::CLAIM_TIMEOUT ) {
						continue;
					}
					$job['id'] = wp_generate_password( 24, false, false );
				}
				$job['claimed_at'] = time();
				update_post_meta( $id, '_unfold_job', $job );
				update_post_meta( $id, '_unfold_state', 'processing' );
				$d      = get_post( $id );
				$others = array();
				foreach ( self::drives( $d->post_parent ) as $o ) {
					if ( $o->ID !== $id && 'ready' === self::state( $o->ID ) ) {
						$others[] = array( 'drive_id' => $o->ID, 'out_dir' => self::out_dir( $o->ID ), 'T' => self::transform( $o->ID ), 'placement' => (string) get_post_meta( $o->ID, '_unfold_placement', true ) );
					}
				}
				return array(
					'drive_id' => $id,
					'job_id'   => $job['id'],
					'world_id' => (int) $d->post_parent,
					'name'     => self::plain_title( $d ),
					'session'  => (string) get_post_meta( $id, '_unfold_session', true ), // Folder inside the ZIP ('' = root).
					// The worker runs on the same machine for now; zip_url is there for a remote one.
					'zip_path' => self::zip_path( $id ),
					'zip_url'  => rest_url( Unfold_Rest::NS . '/worker/drives/' . $id . '/zip' ),
					'out_dir'  => self::out_dir( $id ),
					'others'   => $others,
					'site'     => home_url(),
				);
			}
		}
		return null;
	}

	/**
	 * Start the worker for the next waiting recording, right after an upload (no polling).
	 * Needs UNFOLD_WORLD_PYTHON and UNFOLD_WORLD_WORKER in wp-config.php. One recording at a time:
	 * when it finishes, worker_result() starts the next one.
	 */
	public static function launch() {
		$is_win = ( 'Windows' === PHP_OS_FAMILY );
		if ( ! defined( 'UNFOLD_WORLD_PYTHON' ) || ! defined( 'UNFOLD_WORLD_WORKER' ) ) {
			return false;
		}
		if ( $is_win ? ! function_exists( 'popen' ) : ! function_exists( 'exec' ) ) {
			return false;
		}
		// When PHP may only read inside the site (open_basedir, as on WordPress Studio), it cannot
		// look at the Python files, but it can still start them; check them only when it can see them.
		if ( '' === (string) ini_get( 'open_basedir' ) && ( ! is_file( UNFOLD_WORLD_PYTHON ) || ! is_file( UNFOLD_WORLD_WORKER ) ) ) {
			return false;
		}
		$busy = get_posts( array( 'post_type' => self::DRIVE, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_unfold_state', 'meta_value' => 'processing' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $busy ) {
			$prog = get_post_meta( $busy[0], '_unfold_progress', true );
			$job  = get_post_meta( $busy[0], '_unfold_job', true );
			if ( time() - max( (int) ( $prog['at'] ?? 0 ), (int) ( $job['claimed_at'] ?? 0 ) ) < 600 ) {
				return false; // Still working on one.
			}
		}
		$job = self::claim();
		if ( ! $job ) {
			return false;
		}
		$dir  = self::drive_dir( $job['drive_id'] );
		$file = $dir . '/job.json';
		file_put_contents( $file, wp_json_encode( $job ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_option( 'unfold_world_worker_seen', time(), false );
		if ( $is_win ) {
			$q   = static function ( $a ) {
				return '"' . str_replace( array( '"', '&', '|', '<', '>', '^', '%' ), '', (string) $a ) . '"';
			};
			$cmd = $q( UNFOLD_WORLD_PYTHON ) . ' -u ' . $q( UNFOLD_WORLD_WORKER ) . ' --job ' . $q( $file ) . ' > ' . $q( $dir . '/worker.log' ) . ' 2>&1';
			pclose( popen( 'start "" /B cmd /C "' . $cmd . '"', 'r' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			$cmd = escapeshellarg( UNFOLD_WORLD_PYTHON ) . ' -u ' . escapeshellarg( UNFOLD_WORLD_WORKER ) . ' --job ' . escapeshellarg( $file ) . ' > ' . escapeshellarg( $dir . '/worker.log' ) . ' 2>&1';
			exec( 'nohup ' . $cmd . ' &' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		return true;
	}

	private static function job_ok( $id, $job_id ) {
		$job = get_post_meta( $id, '_unfold_job', true );
		return is_array( $job ) && hash_equals( (string) $job['id'], (string) $job_id ) && 'processing' === self::state( $id );
	}

	public static function worker_zip( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$file = self::zip_path( $id );
		if ( ! self::job_ok( $id, (string) $r->get_param( 'job' ) ) || ! $file ) {
			return new WP_Error( 'unfold_job', 'Job not active.', array( 'status' => 409 ) );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function worker_progress( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$b  = $r->get_json_params();
		if ( ! self::job_ok( $id, (string) ( $b['job_id'] ?? '' ) ) ) {
			return new WP_Error( 'unfold_job', 'Job not active.', array( 'status' => 409 ) );
		}
		update_option( 'unfold_world_worker_seen', time(), false );
		update_post_meta( $id, '_unfold_progress', array( 'pct' => max( 0, min( 100, (int) ( $b['pct'] ?? 0 ) ) ), 'stage' => sanitize_text_field( (string) ( $b['stage'] ?? '' ) ), 'at' => time() ) );
		return array( 'ok' => true );
	}

	public static function worker_result( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$b  = $r->get_json_params();
		if ( ! self::job_ok( $id, (string) ( $b['job_id'] ?? '' ) ) ) {
			return new WP_Error( 'unfold_job', 'Job not active.', array( 'status' => 409 ) );
		}
		update_option( 'unfold_world_worker_seen', time(), false );
		if ( ! empty( $b['error'] ) ) {
			update_post_meta( $id, '_unfold_state', 'failed' );
			update_post_meta( $id, '_unfold_error', sanitize_text_field( mb_substr( (string) $b['error'], 0, 500 ) ) );
			self::launch();
			return array( 'ok' => true );
		}
		if ( ! is_file( self::out_dir( $id ) . '/map.bin' ) || ! is_file( self::out_dir( $id ) . '/path.json' ) ) {
			update_post_meta( $id, '_unfold_state', 'failed' );
			update_post_meta( $id, '_unfold_error', 'The worker finished but the map files are missing.' );
			return new WP_Error( 'unfold_files', 'Missing outputs.', array( 'status' => 400 ) );
		}
		$t = $b['T'] ?? null;
		if ( is_array( $t ) && 16 === count( $t ) ) {
			update_post_meta( $id, '_unfold_T', array_map( 'floatval', $t ) );
		}
		update_post_meta( $id, '_unfold_placement', sanitize_key( (string) ( $b['placement'] ?? 'origin' ) ) );
		update_post_meta( $id, '_unfold_summary', is_array( $b['summary'] ?? null ) ? $b['summary'] : array() );
		update_post_meta( $id, '_unfold_progress', array( 'pct' => 100, 'stage' => 'ready', 'at' => time() ) );
		update_post_meta( $id, '_unfold_state', 'ready' );
		self::launch();
		return array( 'ok' => true );
	}

	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
