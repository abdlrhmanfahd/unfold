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

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['unfold_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['unfold_test_options'] ) ? $GLOBALS['unfold_test_options'][ $option ] : $default;
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
require_once UNFOLD_DIR . 'includes/class-unfold-simdata.php';
require_once UNFOLD_DIR . 'includes/class-unfold-accounts.php';
