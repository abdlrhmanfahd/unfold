<?php
/**
 * PHPUnit bootstrap for standalone unit tests (no WordPress database required).
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'UNFOLD_DIR' ) ) {
	define( 'UNFOLD_DIR', dirname( __DIR__, 2 ) . '/wp-content/plugins/unfold/' );
}
if ( ! defined( 'UNFOLD_META_JSON' ) ) {
	define( 'UNFOLD_META_JSON', '_unfold_project_json' );
}
if ( ! defined( 'UNFOLD_META_RAW' ) ) {
	define( 'UNFOLD_META_RAW', '_unfold_project_raw' );
}
if ( ! defined( 'UNFOLD_META_ERRORS' ) ) {
	define( 'UNFOLD_META_ERRORS', '_unfold_project_errors' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['unfold_test_options']   = array();
$GLOBALS['unfold_test_user_meta'] = array();
$GLOBALS['unfold_test_post_meta'] = array();
$GLOBALS['unfold_test_posts']     = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['unfold_test_options'] ) ? $GLOBALS['unfold_test_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['unfold_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		$uid = (int) $user_id;
		if ( '' === $key ) {
			return $GLOBALS['unfold_test_user_meta'][ $uid ] ?? array();
		}
		if ( ! isset( $GLOBALS['unfold_test_user_meta'][ $uid ][ $key ] ) ) {
			return $single ? '' : array();
		}
		$val = $GLOBALS['unfold_test_user_meta'][ $uid ][ $key ];
		return $single ? $val : array( $val );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $meta_key, $meta_value ) {
		$GLOBALS['unfold_test_user_meta'][ (int) $user_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$pid = (int) $post_id;
		if ( '' === $key ) {
			return $GLOBALS['unfold_test_post_meta'][ $pid ] ?? array();
		}
		if ( ! isset( $GLOBALS['unfold_test_post_meta'][ $pid ][ $key ] ) ) {
			return $single ? '' : array();
		}
		$val = $GLOBALS['unfold_test_post_meta'][ $pid ][ $key ];
		return $single ? $val : array( $val );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value ) {
		$GLOBALS['unfold_test_post_meta'][ (int) $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $meta_key ) {
		unset( $GLOBALS['unfold_test_post_meta'][ (int) $post_id ][ $meta_key ] );
		return true;
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $post_id ) {
		$pid = (int) $post_id;
		return $GLOBALS['unfold_test_posts'][ $pid ][ $field ] ?? '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$allow  = is_array( $protocols ) ? $protocols : array( 'http', 'https' );
		return in_array( $scheme, $allow, true ) ? $url : '';
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'unfold-phpunit-salt-' . $scheme;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}
		if ( is_string( $value ) ) {
			return addslashes( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $input_list, $field, $index_key = null ) {
		$output = array();
		foreach ( $input_list as $key => $value ) {
			$val = is_array( $value ) ? ( $value[ $field ] ?? null ) : ( $value->$field ?? null );
			if ( null === $index_key ) {
				$output[] = $val;
			} else {
				$idx            = is_array( $value ) ? ( $value[ $index_key ] ?? $key ) : ( $value->$index_key ?? $key );
				$output[ $idx ] = $val;
			}
		}
		return $output;
	}
}

require_once UNFOLD_DIR . 'includes/class-unfold-validator.php';
require_once UNFOLD_DIR . 'includes/class-unfold-store.php';
require_once UNFOLD_DIR . 'includes/class-unfold-simdata.php';
require_once UNFOLD_DIR . 'includes/class-unfold-accounts.php';
require_once UNFOLD_DIR . 'includes/class-unfold-ai.php';
