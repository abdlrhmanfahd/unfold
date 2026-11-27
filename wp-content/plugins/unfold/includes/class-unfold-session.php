<?php
/**
 * Recording replay: [unfold_session id="session_1"].
 *
 * Plays an exported QCar 2 recording (camera frames, LiDAR scans, IMU) in sync. The assets are
 * static files made offline by tools/session_export/export_session.py and placed in
 * wp-content/uploads/unfold/sessions/<id>/. The viewer is Unfold's own code, so it runs on the
 * page (no sandbox); it only fetches files from that folder.
 */

defined( 'ABSPATH' ) || exit;

class Unfold_Session {

	public static function init() {
		add_shortcode( 'unfold_session', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ), 16 );
	}

	public static function register_assets() {
		$ver = static function ( $rel ) {
			$p = UNFOLD_DIR . $rel;
			return is_file( $p ) ? UNFOLD_VERSION . '.' . filemtime( $p ) : UNFOLD_VERSION;
		};
		wp_register_script( 'unfold-session', UNFOLD_URL . 'assets/js/session.js', array( 'unfold-three-orbit' ), $ver( 'assets/js/session.js' ), array( 'strategy' => 'defer' ) );
		wp_register_style( 'unfold-session', UNFOLD_URL . 'assets/css/session.css', array(), $ver( 'assets/css/session.css' ) );
	}

	/** Folder and URL of an exported session, or null when the id is invalid or nothing is exported. */
	public static function locate( $id ) {
		$id = (string) $id;
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $id ) ) {
			return null;
		}
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'unfold/sessions/' . $id;
		if ( ! is_file( $dir . '/manifest.json' ) ) {
			return null;
		}
		return array( 'dir' => $dir, 'url' => trailingslashit( $up['baseurl'] ) . 'unfold/sessions/' . $id . '/' );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => '', 'title' => '' ), $atts, 'unfold_session' );
		$loc  = self::locate( $atts['id'] );
		if ( ! $loc ) {
			return current_user_can( 'manage_options' )
				? '<p class="ufsr-missing">' . esc_html( sprintf( __( 'No exported recording "%s" in uploads/unfold/sessions/.', 'unfold' ), $atts['id'] ) ) . '</p>'
				: '';
		}
		wp_enqueue_style( 'unfold-session' );
		wp_enqueue_script( 'unfold-session' );
		$title = $atts['title'];
		return '<section class="ufsr" data-unfold-session data-base="' . esc_url( $loc['url'] ) . '" aria-label="' . esc_attr__( 'Recording replay', 'unfold' ) . '">'
			. ( $title ? '<h2 class="ufsr-title">' . esc_html( $title ) . '</h2>' : '' )
			. '<div class="ufsr-grid">'
			. '<figure class="ufsr-pane ufsr-cam"><canvas aria-label="' . esc_attr__( 'Front camera', 'unfold' ) . '" role="img"></canvas><figcaption>' . esc_html__( 'Front camera', 'unfold' ) . '</figcaption></figure>'
			. '<figure class="ufsr-pane ufsr-lidar"><div class="ufsr-3d" aria-label="' . esc_attr__( 'LiDAR point cloud around the car. Drag to turn it.', 'unfold' ) . '" role="img"></div>'
			. '<figcaption><span>' . esc_html__( 'LiDAR', 'unfold' ) . '</span><span class="ufsr-seg" role="group" aria-label="' . esc_attr__( 'Point colour', 'unfold' ) . '">'
			. '<button type="button" data-color="height" aria-pressed="true">' . esc_html__( 'Height', 'unfold' ) . '</button>'
			. '<button type="button" data-color="refl" aria-pressed="false">' . esc_html__( 'Reflectivity', 'unfold' ) . '</button></span></figcaption></figure>'
			. '</div>'
			. '<figure class="ufsr-pane ufsr-imu"><canvas aria-label="' . esc_attr__( 'Yaw rate and forward acceleration over time', 'unfold' ) . '" role="img"></canvas>'
			. '<figcaption><span class="ufsr-key ufsr-key--yaw">' . esc_html__( 'Yaw rate (°/s)', 'unfold' ) . '</span><span class="ufsr-key ufsr-key--acc">' . esc_html__( 'Forward acceleration (m/s²)', 'unfold' ) . '</span></figcaption></figure>'
			. '<div class="ufsr-bar">'
			. '<button type="button" class="ufsr-play" aria-label="' . esc_attr__( 'Play', 'unfold' ) . '"><span aria-hidden="true"></span></button>'
			. '<input type="range" class="ufsr-slider" min="0" max="1" step="0.01" value="0" aria-label="' . esc_attr__( 'Time', 'unfold' ) . '">'
			. '<output class="ufsr-time">0.0 / 0.0 s</output>'
			. '<span class="ufsr-seg ufsr-speed" role="group" aria-label="' . esc_attr__( 'Speed', 'unfold' ) . '">'
			. '<button type="button" data-speed="0.5" aria-pressed="false">0.5×</button><button type="button" data-speed="1" aria-pressed="true">1×</button><button type="button" data-speed="2" aria-pressed="false">2×</button></span>'
			. '</div>'
			. '<p class="ufsr-status" aria-live="polite"></p>'
			. '</section>';
	}
}
