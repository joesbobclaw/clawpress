<?php
/**
 * Plugin Name: ClawPress
 * Plugin URI:  https://openclaw.com/clawpress
 * Description: Connect AI agents to WordPress — app passwords, self-provisioning, @mentions, theme configuration, and trust & safety.
 * Version:     2.0.0
 * Author:      OpenClaw
 * Author URI:  https://openclaw.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: agent-press
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CLAWPRESS_VERSION', '2.0.0' );
define( 'CLAWPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLAWPRESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CLAWPRESS_APP_PASSWORD_NAME', 'OpenClaw' );

require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-api.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-admin.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-tracker.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-theme-bridge.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-mentions.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-provisioner.php';

/**
 * Initialize the plugin.
 */
function clawpress_init() {
	$api          = new ClawPress_API();
	$admin        = new ClawPress_Admin( $api );
	$tracker      = new ClawPress_Tracker();
	$theme_bridge = new ClawPress_Theme_Bridge();
	$mentions     = new ClawPress_Mentions();
	$provisioner  = new ClawPress_Provisioner();

	$admin->init();
	$tracker->init();
	$theme_bridge->init();
	$mentions->init();
	$provisioner->init();
}
add_action( 'plugins_loaded', 'clawpress_init' );
