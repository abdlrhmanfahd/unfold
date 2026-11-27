<?php
/**
 * The unfold_project custom post type, plus block templates so block themes
 * show projects full-width with the theme's own header and footer.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Post_Type {

	const TYPE = 'unfold_project';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'register_templates' ), 20 );
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Projects', 'unfold' ),
					'singular_name'      => __( 'Project', 'unfold' ),
					'add_new_item'       => __( 'Add New Project', 'unfold' ),
					'edit_item'          => __( 'Edit Project', 'unfold' ),
					'new_item'           => __( 'New Project', 'unfold' ),
					'view_item'          => __( 'View Project', 'unfold' ),
					'search_items'       => __( 'Search Projects', 'unfold' ),
					'not_found'          => __( 'No projects found.', 'unfold' ),
					'not_found_in_trash' => __( 'No projects found in Trash.', 'unfold' ),
					'all_items'          => __( 'All Projects', 'unfold' ),
					'menu_name'          => __( 'Unfold', 'unfold' ),
				),
				'public'       => true,
				'has_archive'  => 'projects',
				'rewrite'      => array( 'slug' => 'projects', 'with_front' => false ),
				'menu_icon'    => 'dashicons-networking',
				// No 'editor': the page is rendered entirely from the project JSON.
				'supports'     => array( 'title', 'revisions' ),
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * Block-theme templates. Classic themes ignore these and fall back to the
	 * content filter in Unfold_Frontend, which works with any theme.
	 */
	public static function register_templates() {
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return;
		}
		$wrap = static function ( $inner ) {
			return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
				. '<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"layout":{"type":"default"}} -->'
				. '<main class="wp-block-group" style="padding-right:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">'
				. $inner
				. '</main><!-- /wp:group -->'
				. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
		};

		register_block_template(
			'unfold//single-' . self::TYPE,
			array(
				'title'       => __( 'Unfold project', 'unfold' ),
				'description' => __( 'Displays one Unfold project, rendered from its JSON.', 'unfold' ),
				'post_types'  => array( self::TYPE ),
				'content'     => $wrap( '<!-- wp:post-content {"layout":{"type":"default"}} /-->' ),
			)
		);
		register_block_template(
			'unfold//archive-' . self::TYPE,
			array(
				'title'       => __( 'Unfold gallery', 'unfold' ),
				'description' => __( 'Lists all Unfold projects.', 'unfold' ),
				'content'     => $wrap( '<!-- wp:shortcode -->[unfold_gallery]<!-- /wp:shortcode -->' ),
			)
		);
	}
}
