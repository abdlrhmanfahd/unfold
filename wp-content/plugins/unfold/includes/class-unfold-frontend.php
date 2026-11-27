<?php
/**
 * Public side: replaces a project's content with the rendered page, provides the
 * [unfold_gallery] shortcode, and loads assets only where they are needed.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Frontend {

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		add_shortcode( 'unfold_gallery', array( __CLASS__, 'gallery_shortcode' ) );
		add_shortcode( 'unfold_featured', array( __CLASS__, 'featured_shortcode' ) );
		add_shortcode( 'unfold_facts', array( __CLASS__, 'facts_shortcode' ) );
		add_shortcode( 'unfold_qcar_hero', array( __CLASS__, 'qcar_hero_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ), 15 ); // Early: block themes render shortcodes before wp_enqueue_scripts.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_project_assets' ) );
	}

	private static function asset_ver( $rel ) {
		$path = UNFOLD_DIR . $rel;
		return is_file( $path ) ? UNFOLD_VERSION . '.' . filemtime( $path ) : UNFOLD_VERSION;
	}

	public static function register_assets() {
		$v = 'assets/vendor/';
		wp_register_style( 'unfold', UNFOLD_URL . 'assets/css/unfold.css', array(), self::asset_ver( 'assets/css/unfold.css' ) );
		wp_register_style( 'unfold-katex', UNFOLD_URL . $v . 'katex/katex.min.css', array(), '0.16.11' );
		wp_register_script( 'unfold-katex', UNFOLD_URL . $v . 'katex/katex.min.js', array(), '0.16.11', array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-katex-auto', UNFOLD_URL . $v . 'katex/auto-render.min.js', array( 'unfold-katex' ), '0.16.11', array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-math', UNFOLD_URL . 'assets/js/math.js', array( 'unfold-katex-auto' ), self::asset_ver( 'assets/js/math.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-mermaid', UNFOLD_URL . $v . 'mermaid/mermaid.min.js', array(), '11.4.1', array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-plotly', UNFOLD_URL . $v . 'plotly/plotly-basic.min.js', array(), '2.35.2', array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-project', UNFOLD_URL . 'assets/js/project.js', array( 'unfold-math', 'unfold-mermaid' ), self::asset_ver( 'assets/js/project.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-gallery', UNFOLD_URL . 'assets/js/gallery.js', array(), self::asset_ver( 'assets/js/gallery.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-three', UNFOLD_URL . $v . 'three/three.min.js', array(), 'r128', array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-three-orbit', UNFOLD_URL . $v . 'three/OrbitControls.js', array( 'unfold-three' ), 'r128', array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-qcar', UNFOLD_URL . 'assets/js/qcar.js', array( 'unfold-three-orbit' ), self::asset_ver( 'assets/js/qcar.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-qcar-hero', UNFOLD_URL . 'assets/js/qcar-hero.js', array( 'unfold-qcar' ), self::asset_ver( 'assets/js/qcar-hero.js' ), array( 'strategy' => 'defer' ) );
		wp_register_style( 'unfold-qcar', UNFOLD_URL . 'assets/css/qcar.css', array(), self::asset_ver( 'assets/css/qcar.css' ) );
		wp_register_style( 'unfold-qcar-project', UNFOLD_URL . 'assets/css/qcar-project.css', array( 'unfold-qcar' ), self::asset_ver( 'assets/css/qcar-project.css' ) );
		wp_register_script( 'unfold-templates', UNFOLD_URL . 'assets/js/templates.js', array(), self::asset_ver( 'assets/js/templates.js' ), array( 'strategy' => 'defer' ) );
		wp_register_script( 'unfold-qcar-project', UNFOLD_URL . 'assets/js/qcar-project.js', array( 'unfold-qcar', 'unfold-templates' ), self::asset_ver( 'assets/js/qcar-project.js' ), array( 'strategy' => 'defer' ) );
	}

	/** Home hero: the QCar 2 that unfolds into its parts. */
	public static function qcar_hero_shortcode() {
		wp_enqueue_style( 'unfold-qcar' );
		wp_enqueue_script( 'unfold-qcar-hero' );
		// The halo: every published project and 3D world, circling the car until it is unfolded.
		$halo = array();
		foreach ( get_posts( array( 'post_type' => Unfold_Post_Type::TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ) ) as $p ) {
			$halo[] = array( 'n' => html_entity_decode( get_the_title( $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'u' => get_permalink( $p ), 'k' => 'p' );
		}
		if ( class_exists( 'Unfold_Worlds' ) ) {
			foreach ( get_posts( array( 'post_type' => Unfold_Worlds::WORLD, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ) ) as $w ) {
				$halo[] = array( 'n' => Unfold_Worlds::plain_title( $w ), 'u' => get_permalink( $w ), 'k' => 'w' );
			}
		}
		return '<section class="uf-qhero alignfull" aria-label="' . esc_attr__( 'The QCar 2, part by part', 'unfold' ) . '">'
			. '<div class="uf-qhero-stage" data-unfold-qcar-hero data-halo="' . esc_attr( wp_json_encode( $halo ) ) . '">'
			. '<div class="uf-qhero-copy"><h1 class="uf-qhero-title">' . esc_html__( 'Take the car apart.', 'unfold' ) . ' <span>' . esc_html__( 'See how it works.', 'unfold' ) . '</span></h1>'
			. '<div class="uf-qhero-row"><a class="uf-hbtn" href="' . esc_url( home_url( '/projects/' ) ) . '">' . esc_html__( 'Browse projects', 'unfold' ) . '</a>'
			. '<a class="uf-hbtn uf-hbtn--line" href="' . esc_url( home_url( '/workspace/' ) ) . '">' . esc_html__( 'Upload a project', 'unfold' ) . '</a></div></div>'
			. '<div class="uf-qhero-card" hidden aria-live="polite"><button type="button" class="uf-qhero-x" aria-label="' . esc_attr__( 'Close', 'unfold' ) . '">×</button><h2></h2><p></p></div>'
			. '</div></section>';
	}

	public static function enqueue_project_assets() {
		if ( ! is_singular( Unfold_Post_Type::TYPE ) ) {
			if ( is_post_type_archive( Unfold_Post_Type::TYPE ) || is_front_page() ) {
				self::enqueue_gallery_assets();
			}
			return;
		}
		wp_enqueue_style( 'unfold' );
		$data = Unfold_Store::get( get_queried_object_id() );
		if ( ! $data ) {
			return;
		}
		wp_enqueue_style( 'unfold-katex' );
		if ( Unfold_Render::csv_sources( get_queried_object_id(), $data ) ) {
			wp_enqueue_script( 'unfold-plotly' );
		}
		wp_enqueue_script( 'unfold-project' );
		// Anonymous engagement counters (views, simulation uses) for the instructor dashboard.
		if ( 'publish' === get_post_status( get_queried_object_id() ) ) {
			wp_localize_script(
				'unfold-project',
				'UNFOLD_TRACK',
				array(
					'url'     => esc_url_raw( rest_url( Unfold_Rest::NS . '/track' ) ),
					'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
					'project' => get_queried_object_id(),
				)
			);
		}
	}

	public static function filter_content( $content ) {
		static $rendering = false;
		if ( $rendering || ! is_singular( Unfold_Post_Type::TYPE ) || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$post_id = get_queried_object_id();
		$data    = Unfold_Store::get( $post_id );

		if ( ! $data ) {
			$msg = '<div class="unfold uf-notice"><p>' . esc_html__( 'This project has no valid project data yet.', 'unfold' ) . '</p>';
			if ( current_user_can( 'edit_post', $post_id ) ) {
				$msg .= '<p><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Add or fix the project JSON', 'unfold' ) . '</a></p>';
			}
			return $msg . '</div>';
		}

		$rendering = true;
		$html      = Unfold_Render::project_html( $post_id, $data ); // Includes the status summary.
		$rendering = false;

		// Editors see a notice when the latest submission failed and an older valid version is shown.
		if ( Unfold_Store::get_errors( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
			$html = '<div class="unfold uf-notice uf-notice--warn"><p>' . esc_html__( 'Your latest JSON had validation errors, so the last valid version is shown. Only editors can see this message.', 'unfold' )
				. ' <a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'See the errors', 'unfold' ) . '</a></p></div>' . $html;
		}
		return $html;
	}

	/** Published projects that have valid data, richest (most simulations) first. */
	private static function published() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => Unfold_Post_Type::TYPE, 'post_status' => 'publish', 'numberposts' => 50 ) ) as $p ) {
			$d = Unfold_Store::get( $p->ID );
			if ( $d ) {
				$out[] = array( $p, $d );
			}
		}
		usort( $out, static function ( $a, $b ) { return count( Unfold_Render::sims( $b[1] ) ) <=> count( Unfold_Render::sims( $a[1] ) ); } );
		return $out;
	}

	/** [unfold_featured] — one real published project in a frame, for the home page. */
	public static function featured_shortcode() {
		$list = self::published();
		if ( ! $list ) {
			return '';
		}
		list( $p, $d ) = $list[0];
		$cover = Unfold_Render::cover_image( $p->ID, $d );
		$sims  = Unfold_Render::sims( $d );
		$parts = array_slice( wp_list_pluck( $d['subsystems'], 'name' ), 0, 5 );
		$more  = count( $d['subsystems'] ) - count( $parts );

		// Key result: first target that has a reported value.
		$result = '';
		foreach ( $d['goal']['success_criteria'] as $c ) {
			foreach ( $d['results']['metrics'] as $m ) {
				if ( strtolower( trim( $m['metric'] ) ) === strtolower( trim( $c['metric'] ) ) && null !== ( $m['value']['value'] ?? null ) ) {
					$result = '<p class="uf-feature-result"><b>' . esc_html( rtrim( rtrim( number_format( (float) $m['value']['value'], 3, '.', '' ), '0' ), '.' ) . ' ' . $m['unit'] ) . '</b><span>' . esc_html( $m['metric'] ) . '</span></p>';
					break 2;
				}
			}
		}

		$out  = '<div class="uf-frame"><a class="uf-frame-inner uf-feature" href="' . esc_url( get_permalink( $p ) ) . '">';
		$out .= $cover ? '<div class="uf-feature-media">' . wp_get_attachment_image( $cover, 'large', false, array( 'alt' => '', 'loading' => 'eager' ) ) . '</div>' : '';
		$out .= '<div class="uf-feature-body"><p class="uf-feature-label">' . esc_html( $sims ? sprintf( _n( 'Featured project · %d simulation', 'Featured project · %d simulations', count( $sims ), 'unfold' ), count( $sims ) ) : __( 'Featured project', 'unfold' ) ) . '</p>'
			. '<h2 class="uf-feature-title">' . esc_html( $d['meta']['title'] ) . '</h2>' . $result . '<ul class="uf-feature-parts">';
		foreach ( $parts as $n ) {
			$out .= '<li>' . esc_html( $n ) . '</li>';
		}
		$out .= ( $more > 0 ? '<li>+' . esc_html( $more ) . '</li>' : '' ) . '</ul><p class="uf-feature-go">' . esc_html__( 'Open the project', 'unfold' ) . ' →</p></div></a></div>';
		return $out;
	}

	/** [unfold_facts] — a short line of real counts. */
	public static function facts_shortcode() {
		$list = self::published();
		if ( ! $list ) {
			return '';
		}
		$parts = 0;
		$sims  = 0;
		foreach ( $list as $it ) {
			$parts += count( $it[1]['subsystems'] );
			$sims  += count( Unfold_Render::sims( $it[1] ) );
		}
		return '<p class="uf-hero-facts">' . esc_html(
			sprintf( _n( '%d project', '%d projects', count( $list ), 'unfold' ), count( $list ) ) . ' · '
			. sprintf( _n( '%d subsystem', '%d subsystems', $parts, 'unfold' ), $parts ) . ' · '
			. sprintf( _n( '%d simulation', '%d simulations', $sims, 'unfold' ), $sims )
		) . '</p>';
	}

	private static function enqueue_gallery_assets() {
		wp_enqueue_style( 'unfold' );
		wp_enqueue_style( 'unfold-katex' );
		wp_enqueue_script( 'unfold-math' );
		wp_enqueue_script( 'unfold-gallery' );
	}

	public static function gallery_shortcode( $atts ) {
		self::enqueue_gallery_assets();
		return Unfold_Render::gallery( (array) $atts );
	}
}
