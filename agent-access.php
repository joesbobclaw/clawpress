<?php
/**
 * Plugin Name: Agent Access
 * Plugin URI:  https://wearebob.blog/agent-access/
 * Description: Connect AI agents to WordPress — manage Application Passwords, track agent content, and @mentions.
 * Version:     3.1.1
 * Author:      Joe Boydston
 * Author URI:  https://wearebob.blog
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: agent-access
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENT_ACCESS_VERSION', '3.1.1' );
define( 'AGENT_ACCESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENT_ACCESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENT_ACCESS_APP_PASSWORD_NAME', 'Agent Access' );

require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-compat.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-migrator.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-api.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-admin.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-tracker.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-mentions.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-provisioner.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-artifacts.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-chat.php';

/**
 * Initialize the plugin.
 *
 * Compat and migrator are loaded first so the compat layer's REST routes and
 * meta helpers are available to all other components.
 */
function agent_access_init() {
	// Compat: register legacy clawpress/v1 REST route proxies.
	Agent_Access_Compat::init();

	// Migrator: admin notice + AJAX handler (+ WP-CLI via its own hook).
	$migrator = new Agent_Access_Migrator();
	$migrator->init();

	$api         = new Agent_Access_API();
	$admin       = new Agent_Access_Admin( $api );
	$tracker     = new Agent_Access_Tracker();
	$mentions    = new Agent_Access_Mentions();
	$provisioner = new Agent_Access_Provisioner();
	$admin->init();
	$tracker->init();
	$mentions->init();
	$provisioner->init();

	// Module: Artifacts.
	Agent_Access_Artifacts::instance()->init();

	// Module: Agent Chat.
	Agent_Access_Chat::instance()->init();
}
add_action( 'plugins_loaded', 'agent_access_init' );

/**
 * Plugin activation: register artifact capabilities and flush rewrite rules.
 */
function agent_access_activate() {
	// Artifact capabilities — requires the CPT to be registered first.
	// We call the static helper directly since init() hasn't fired yet at activation time.
	// The CPT is registered on 'init' so we need to trigger it manually here.
	if ( class_exists( 'Agent_Access_Artifacts' ) ) {
		Agent_Access_Artifacts::instance()->register_post_type();
		Agent_Access_Artifacts::activate();
	}

	// Chat rewrite rule.
	if ( class_exists( 'Agent_Access_Chat' ) ) {
		add_rewrite_rule( '^agent-chat/?$', 'index.php?agent_chat_page=1', 'top' );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'agent_access_activate' );

/**
 * Plugin deactivation: flush rewrite rules.
 */
function agent_access_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'agent_access_deactivate' );
