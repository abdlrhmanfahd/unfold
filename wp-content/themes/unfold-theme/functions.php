<?php
/**
 * Unfold theme. Block theme: layout lives in theme.json, templates/ and parts/.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function () {
		$path = get_stylesheet_directory() . '/style.css';
		wp_enqueue_style( 'unfold-theme', get_stylesheet_uri(), array(), (string) filemtime( $path ) );
	}
);

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'wp-block-styles' );
		add_editor_style( 'style.css' );
	}
);
