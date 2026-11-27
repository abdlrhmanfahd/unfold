<?php
/**
 * Plugin Name:       Unfold
 * Description:       Presents engineering projects as interactive, explorable systems: block diagrams, subsystem simulations and results vs targets, all rendered from one project JSON document.
 * Version:           0.3.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            Unfold
 * License:           GPL-2.0-or-later
 * Text Domain:       unfold
 */

defined( 'ABSPATH' ) || exit;

define( 'UNFOLD_VERSION', '0.3.0' );
define( 'UNFOLD_FILE', __FILE__ );
define( 'UNFOLD_DIR', plugin_dir_path( __FILE__ ) );
define( 'UNFOLD_URL', plugin_dir_url( __FILE__ ) );

/** Post meta key holding the last VALID project JSON (what the public page renders). */
define( 'UNFOLD_META_JSON', '_unfold_project_json' );
/** Post meta key holding the last submitted raw text (valid or not), so the editor never loses work. */
define( 'UNFOLD_META_RAW', '_unfold_project_raw' );
/** Post meta key holding the validation errors of the last submission. */
define( 'UNFOLD_META_ERRORS', '_unfold_project_errors' );

require_once UNFOLD_DIR . 'includes/class-unfold-validator.php';
require_once UNFOLD_DIR . 'includes/class-unfold-store.php';
require_once UNFOLD_DIR . 'includes/class-unfold-post-type.php';
require_once UNFOLD_DIR . 'includes/class-unfold-simdata.php';
require_once UNFOLD_DIR . 'includes/class-unfold-session.php';
require_once UNFOLD_DIR . 'includes/class-unfold-worlds.php';
require_once UNFOLD_DIR . 'includes/class-unfold-render.php';
require_once UNFOLD_DIR . 'includes/class-unfold-frontend.php';
require_once UNFOLD_DIR . 'includes/class-unfold-admin.php';
require_once UNFOLD_DIR . 'includes/class-unfold-settings.php';
require_once UNFOLD_DIR . 'includes/class-unfold-accounts.php';
require_once UNFOLD_DIR . 'includes/class-unfold-workflow.php';
require_once UNFOLD_DIR . 'includes/class-unfold-stats.php';
require_once UNFOLD_DIR . 'includes/class-unfold-rest.php';
require_once UNFOLD_DIR . 'includes/class-unfold-portals.php';

Unfold_Post_Type::init();
Unfold_Frontend::init();
Unfold_Session::init();
Unfold_Worlds::init();
Unfold_Settings::init();
Unfold_Accounts::init();
Unfold_Workflow::init();
Unfold_Rest::init();
Unfold_Portals::init();
if ( is_admin() ) {
	Unfold_Admin::init();
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once UNFOLD_DIR . 'includes/class-unfold-cli.php';
	WP_CLI::add_command( 'unfold', 'Unfold_CLI' );
}

register_activation_hook(
	__FILE__,
	static function () {
		Unfold_Post_Type::register();
		flush_rewrite_rules();
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
