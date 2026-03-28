<?php
/**
 * Agent Access Admin — Dashboard, profile page integration, and AJAX handlers.
 *
 * @package Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Admin {

	/**
	 * @var Agent_Access_API
	 */
	private $api;

	/**
	 * @param Agent_Access_API $api
	 */
	public function __construct( Agent_Access_API $api ) {
		$this->api = $api;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'show_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'wp_ajax_agent_access_create', array( $this, 'handle_create_ajax' ) );
		add_action( 'wp_ajax_agent_access_revoke', array( $this, 'handle_revoke_ajax' ) );
	}

	// ── Menu registration ─────────────────────────────────────────────────────

	/**
	 * Register the top-level "Agent Access" menu and submenu pages.
	 */
	public function add_admin_menu() {
		// Top-level dashboard page.
		add_menu_page(
			__( 'Agent Access', 'agent-access' ),
			__( 'Agent Access', 'agent-access' ),
			'manage_options',
			'agent-access',
			array( $this, 'render_dashboard_page' ),
			'dashicons-rest-api',
			75
		);

		// "Dashboard" submenu (same page as the top-level).
		add_submenu_page(
			'agent-access',
			__( 'Agent Access', 'agent-access' ),
			__( 'Dashboard', 'agent-access' ),
			'manage_options',
			'agent-access',
			array( $this, 'render_dashboard_page' )
		);

		// "Connected Agents" submenu — the former Tools → Agent Access page.
		add_submenu_page(
			'agent-access',
			__( 'Connected Agents', 'agent-access' ),
			__( 'Connected Agents', 'agent-access' ),
			'manage_options',
			'agent-access-connected',
			array( $this, 'render_connected_agents_page' )
		);
	}

	// ── Asset enqueueing ──────────────────────────────────────────────────────

	/**
	 * Enqueue admin CSS and JS on relevant pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$allowed_hooks = array(
			'profile.php',
			'user-edit.php',
			'toplevel_page_agent-access',
			'agent-access_page_agent-access-connected',
		);

		if ( ! in_array( $hook_suffix, $allowed_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'agent-access-admin',
			AGENT_ACCESS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AGENT_ACCESS_VERSION
		);

		wp_enqueue_script(
			'agent-access-admin',
			AGENT_ACCESS_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			AGENT_ACCESS_VERSION,
			true
		);

		// Determine if we are on another user's profile page and pass that user's ID.
		$profile_user_id = 0;
		if ( in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			// On user-edit.php, $_GET['user_id'] is the target user.
			// On profile.php, it is always the current user.
			$profile_user_id = isset( $_GET['user_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? (int) $_GET['user_id'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				: get_current_user_id();
		}

		wp_localize_script( 'agent-access-admin', 'agentAccess', array(
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'create_nonce'    => wp_create_nonce( 'agent_access_create' ),
			'revoke_nonce'    => wp_create_nonce( 'agent_access_revoke' ),
			'profile_user_id' => $profile_user_id,
			'confirm_msg'     => __( 'Are you sure you want to revoke the agent connection? You will need to reconfigure your agent with a new password.', 'agent-access' ),
			'creating_text'   => __( 'Connecting…', 'agent-access' ),
			'revoking_text'   => __( 'Revoking…', 'agent-access' ),
			'copied_text'     => __( 'Copied!', 'agent-access' ),
			'copy_text'       => __( 'Copy', 'agent-access' ),
		) );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * Handle the AJAX create request.
	 *
	 * Accepts an optional `user_id` param; admins with edit_users may create for others.
	 */
	public function handle_create_ajax() {
		check_ajax_referer( 'agent_access_create', 'nonce' );

		$target_user_id = $this->resolve_target_user_id();

		if ( is_wp_error( $target_user_id ) ) {
			wp_send_json_error( $target_user_id->get_error_message() );
		}

		// Capability check: own profile needs edit_posts; another user's needs edit_users.
		if ( $target_user_id !== get_current_user_id() ) {
			if ( ! current_user_can( 'edit_users' ) ) {
				wp_send_json_error( __( 'You do not have permission to manage other users\' agents.', 'agent-access' ) );
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'agent-access' ) );
		}

		$result = $this->api->create_password( $target_user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$connection_info = $this->api->get_connection_info( $result['password'], $target_user_id );

		$actor = wp_get_current_user();
		do_action( 'agent_access_audit', 'app_password_created', array(
			'username'    => $actor->user_login,
			'target_user' => $target_user_id,
		) );

		wp_send_json_success( $connection_info );
	}

	/**
	 * Handle the AJAX revoke request.
	 *
	 * Accepts an optional `user_id` param; admins with edit_users may revoke for others.
	 */
	public function handle_revoke_ajax() {
		check_ajax_referer( 'agent_access_revoke', 'nonce' );

		$target_user_id = $this->resolve_target_user_id();

		if ( is_wp_error( $target_user_id ) ) {
			wp_send_json_error( $target_user_id->get_error_message() );
		}

		if ( $target_user_id !== get_current_user_id() ) {
			if ( ! current_user_can( 'edit_users' ) ) {
				wp_send_json_error( __( 'You do not have permission to manage other users\' agents.', 'agent-access' ) );
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'agent-access' ) );
		}

		$result = $this->api->revoke_password( $target_user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$actor = wp_get_current_user();
		do_action( 'agent_access_audit', 'app_password_revoked', array(
			'username'    => $actor->user_login,
			'target_user' => $target_user_id,
		) );

		wp_send_json_success( __( 'Agent connection revoked successfully.', 'agent-access' ) );
	}

	/**
	 * Resolve the target user ID from the AJAX request.
	 *
	 * If `user_id` is present and differs from the current user, the caller must
	 * verify `edit_users` capability themselves.
	 *
	 * @return int|WP_Error
	 */
	private function resolve_target_user_id() {
		$current_user_id = get_current_user_id();

		if ( empty( $_POST['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $current_user_id;
		}

		$requested_id = (int) $_POST['user_id']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Same user — no special handling needed.
		if ( $requested_id === $current_user_id ) {
			return $current_user_id;
		}

		// Must be a real user.
		if ( ! get_user_by( 'ID', $requested_id ) ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'agent-access' ) );
		}

		return $requested_id;
	}

	// ── Profile section ───────────────────────────────────────────────────────

	/**
	 * Render the Agent Access section on the profile page.
	 *
	 * Shows for the current user's own profile, and also when an admin with
	 * edit_users capability views another user's profile.
	 *
	 * @param WP_User $user The user being edited.
	 */
	public function render_profile_section( $user ) {
		$is_own_profile = ( get_current_user_id() === $user->ID );
		$is_admin_view  = ( ! $is_own_profile && current_user_can( 'edit_users' ) );

		// Only show on own profile or when admin is managing another user.
		if ( ! $is_own_profile && ! $is_admin_view ) {
			return;
		}

		$existing      = $this->api->get_existing_password( $user->ID );
		$error_message = get_transient( 'agent_access_error_' . $user->ID );
		$created_info  = get_transient( 'agent_access_created_' . $user->ID );
		$just_created  = ! empty( $created_info );

		if ( $error_message ) {
			delete_transient( 'agent_access_error_' . $user->ID );
		}
		if ( $just_created ) {
			delete_transient( 'agent_access_created_' . $user->ID );
		}

		?>
		<div id="agent-access" class="agent-access-profile-section" data-user-id="<?php echo esc_attr( $user->ID ); ?>">
			<h2 class="agent-access-title">
				<span class="agent-access-logo">&#129438;</span>
				<?php esc_html_e( 'Agent Access', 'agent-access' ); ?>
				<span class="dashicons dashicons-wordpress" style="font-size:1.2em;vertical-align:middle;opacity:0.7;"></span>
				<?php if ( $is_admin_view ) : ?>
					<span class="agent-access-admin-badge">
						<?php
						printf(
							/* translators: %s: user display name */
							esc_html__( 'Managing: %s', 'agent-access' ),
							esc_html( $user->display_name )
						);
						?>
					</span>
				<?php endif; ?>
			</h2>
			<p class="description">
				<?php
				if ( $is_admin_view ) {
					printf(
						/* translators: %s: user display name */
						esc_html__( 'Manage the AI agent connection for %s.', 'agent-access' ),
						esc_html( $user->display_name )
					);
				} else {
					esc_html_e( 'Connect your AI agent to WordPress in one click.', 'agent-access' );
				}
				?>
			</p>

			<?php if ( $error_message ) : ?>
				<div class="notice notice-error inline agent-access-notice">
					<p><?php echo esc_html( $error_message ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $just_created ) : ?>
				<?php $this->render_created_state( $created_info ); ?>
			<?php elseif ( $existing ) : ?>
				<?php $this->render_connected_state( $existing, $user->ID ); ?>
			<?php else : ?>
				<?php $this->render_disconnected_state( $user->ID, $is_admin_view ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "just created" state showing the password once.
	 *
	 * @param array $info Connection info with site_url, username, password.
	 */
	private function render_created_state( $info ) {
		$json = wp_json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		?>
		<p><span class="agent-access-success-icon">&#10003;</span> <strong><?php esc_html_e( 'Connection Created!', 'agent-access' ); ?></strong></p>

		<div class="agent-access-warning-box">
			<strong><?php esc_html_e( 'Important:', 'agent-access' ); ?></strong>
			<?php esc_html_e( 'This password will only be shown once. Copy the message below and send it to your AI agent.', 'agent-access' ); ?>
		</div>

		<div class="agent-access-json-block">
			<pre class="agent-access-json" id="agent-access-json"><?php echo esc_html( 'Save these WordPress Application Password credentials and use them to connect to my site via the WordPress REST API:' . "\n" . $json ); ?></pre>
			<button type="button" class="button agent-access-copy-btn" data-target="agent-access-json">
				<?php esc_html_e( 'Copy', 'agent-access' ); ?>
			</button>
		</div>

		<p class="agent-access-next-step">
			<?php esc_html_e( 'Paste this into your Agent Access chat (Telegram, WhatsApp, etc.) and your agent will handle the rest.', 'agent-access' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "connected" state with status info and revoke button.
	 *
	 * @param array $existing The existing application password entry.
	 * @param int   $user_id  The user whose connection is shown.
	 */
	private function render_connected_state( $existing, $user_id ) {
		$created_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $existing['created'] );
		$last_used    = ! empty( $existing['last_used'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $existing['last_used'] )
			: __( 'Never', 'agent-access' );
		$stats        = Agent_Access_Tracker::get_stats( $user_id );
		?>
		<div class="agent-access-notice-row">
			<div class="agent-access-notice-box agent-access-notice-box--green">
				<?php esc_html_e( 'Connected', 'agent-access' ); ?>
			</div>
			<div class="agent-access-notice-box agent-access-notice-box--red">
				<?php esc_html_e( 'Your AI agent can post here on your behalf.', 'agent-access' ); ?>
			</div>
		</div>

		<table class="agent-access-status-table">
			<tr>
				<th><?php esc_html_e( 'Created', 'agent-access' ); ?></th>
				<td><?php echo esc_html( $created_date ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last Used', 'agent-access' ); ?></th>
				<td><?php echo esc_html( $last_used ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Posts', 'agent-access' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %d: number of posts */
						esc_html( _n( '%d post created via agent', '%d posts created via agent', $stats['post_count'], 'agent-access' ) ),
						(int) $stats['post_count']
					);
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Media', 'agent-access' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %d: number of files */
						esc_html( _n( '%d file uploaded via agent', '%d files uploaded via agent', $stats['media_count'], 'agent-access' ) ),
						(int) $stats['media_count']
					);
					?>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $stats['recent_posts'] ) ) : ?>
			<h3><?php esc_html_e( 'Recent Agent Posts', 'agent-access' ); ?></h3>
			<table class="widefat striped agent-access-recent-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'agent-access' ); ?></th>
						<th><?php esc_html_e( 'Date', 'agent-access' ); ?></th>
						<th><?php esc_html_e( 'Status', 'agent-access' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats['recent_posts'] as $post ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
									<?php echo esc_html( $post->post_title ?: __( '(no title)', 'agent-access' ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $post->post_date ) ) ); ?></td>
							<td><span class="agent-access-badge agent-access-badge--<?php echo esc_attr( $post->post_status ); ?>"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<div class="agent-access-revoke-section">
			<button type="button" class="button agent-access-revoke-btn" id="agent-access-revoke-btn" data-user-id="<?php echo esc_attr( $user_id ); ?>">
				<?php esc_html_e( 'Revoke Connection', 'agent-access' ); ?>
			</button>
			<span class="agent-access-revoke-hint">
				<?php esc_html_e( 'This will disconnect the agent from this account.', 'agent-access' ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render the "disconnected" state with create button.
	 *
	 * @param int  $user_id       The target user ID.
	 * @param bool $is_admin_view Whether an admin is managing another user.
	 */
	private function render_disconnected_state( $user_id, $is_admin_view = false ) {
		?>
		<div id="agent-access-card">
			<p>
				<button type="button" class="button button-primary agent-access-create-btn" id="agent-access-create-btn" data-user-id="<?php echo esc_attr( $user_id ); ?>">
					<?php
					echo $is_admin_view
						? esc_html__( 'Connect Agent for This User', 'agent-access' )
						: esc_html__( 'Connect Agent', 'agent-access' );
					?>
				</button>
			</p>
			<p class="agent-access-create-hint">
				<?php
				if ( $is_admin_view ) {
					esc_html_e( 'This will generate a secure Application Password for Agent Access on behalf of this user.', 'agent-access' );
				} else {
					esc_html_e( 'This will generate a secure Application Password for Agent Access. You\'ll be given credentials to paste into your Agent Access config.', 'agent-access' );
				}
				?>
			</p>
		</div>
		<?php
	}

	// ── Dashboard page ────────────────────────────────────────────────────────

	/**
	 * Render the top-level Agent Access dashboard page.
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-access' ) );
		}

		$has_provisioner     = class_exists( 'Agent_Access_Provisioner' );
		$connected_users     = $this->get_all_openclaw_users();
		$connected_count     = count( $connected_users );
		$provisioned_count   = $has_provisioner ? $this->get_provisioned_count() : 0;
		$recent_activity     = $this->get_recent_activity_count();
		$profile_url         = admin_url( 'profile.php' ) . '#agent-access';
		$connected_page_url  = admin_url( 'admin.php?page=agent-access-connected' );
		$provisioner_enabled = $has_provisioner ? $this->is_provisioner_enabled() : false;

		?>
		<div class="wrap agent-access-dashboard">

			<div class="agent-access-dashboard-header">
				<h1 class="agent-access-dashboard-title">
					<span class="agent-access-dashboard-emoji">&#129438;</span>
					<?php esc_html_e( 'Agent Access', 'agent-access' ); ?>
				</h1>
				<p class="agent-access-dashboard-tagline">
					<?php esc_html_e( 'Connect AI agents to WordPress — manage Application Passwords, track agent content, and @mentions.', 'agent-access' ); ?>
				</p>
			</div>

			<?php /* ── Quick stats ── */ ?>
			<div class="agent-access-stats-row">
				<div class="agent-access-stat-card">
					<div class="agent-access-stat-number"><?php echo esc_html( $connected_count ); ?></div>
					<div class="agent-access-stat-label">
						<span class="dashicons dashicons-admin-users"></span>
						<?php esc_html_e( 'Connected Agents', 'agent-access' ); ?>
					</div>
				</div>

				<?php if ( $has_provisioner ) : ?>
				<div class="agent-access-stat-card">
					<div class="agent-access-stat-number"><?php echo esc_html( $provisioned_count ); ?></div>
					<div class="agent-access-stat-label">
						<span class="dashicons dashicons-superhero"></span>
						<?php esc_html_e( 'Provisioned Agents', 'agent-access' ); ?>
					</div>
				</div>
				<?php endif; ?>

				<div class="agent-access-stat-card">
					<div class="agent-access-stat-number"><?php echo esc_html( $recent_activity ); ?></div>
					<div class="agent-access-stat-label">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Posts This Month', 'agent-access' ); ?>
					</div>
				</div>
			</div>

			<?php /* ── Two-column layout: modes ── */ ?>
			<div class="agent-access-cards-grid">

				<?php /* ── Mode 1: Connect Your Agent ── */ ?>
				<div class="agent-access-card postbox">
					<div class="agent-access-card-header">
						<span class="dashicons dashicons-admin-links agent-access-card-icon"></span>
						<h2 class="agent-access-card-title">
							<?php esc_html_e( 'Mode 1 — Connect Your Agent', 'agent-access' ); ?>
						</h2>
					</div>
					<div class="agent-access-card-body">
						<p>
							<?php esc_html_e( 'Link an external AI agent (like OpenClaw, Claude, or any automation tool) directly to your WordPress account using a secure Application Password.', 'agent-access' ); ?>
						</p>
						<p>
							<?php esc_html_e( 'Your agent acts as you — publishing posts, uploading media, and interacting with the REST API under your user account. All activity is tracked and attributed.', 'agent-access' ); ?>
						</p>
						<ul class="agent-access-feature-list">
							<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'One-click Application Password generation', 'agent-access' ); ?></li>
							<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Revoke access instantly at any time', 'agent-access' ); ?></li>
							<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Activity tracked in your profile', 'agent-access' ); ?></li>
						</ul>
						<div class="agent-access-card-actions">
							<a href="<?php echo esc_url( $profile_url ); ?>" class="button button-primary">
								<span class="dashicons dashicons-admin-users"></span>
								<?php esc_html_e( 'Set Up on My Profile', 'agent-access' ); ?>
							</a>
							<a href="<?php echo esc_url( $connected_page_url ); ?>" class="button">
								<?php esc_html_e( 'View All Connected Agents', 'agent-access' ); ?>
							</a>
						</div>
					</div>
				</div>

				<?php /* ── Mode 2: Agent Provisioning ── */ ?>
				<div class="agent-access-card postbox <?php echo $has_provisioner ? '' : 'agent-access-card--inactive'; ?>">
					<div class="agent-access-card-header">
						<span class="dashicons dashicons-superhero agent-access-card-icon"></span>
						<h2 class="agent-access-card-title">
							<?php esc_html_e( 'Mode 2 — Agent Provisioning', 'agent-access' ); ?>
						</h2>
						<?php if ( $has_provisioner ) : ?>
							<?php if ( $provisioner_enabled ) : ?>
								<span class="agent-access-status-pill agent-access-status-pill--active">
									<span class="dashicons dashicons-marker"></span>
									<?php esc_html_e( 'Active', 'agent-access' ); ?>
								</span>
							<?php else : ?>
								<span class="agent-access-status-pill agent-access-status-pill--inactive">
									<?php esc_html_e( 'Inactive', 'agent-access' ); ?>
								</span>
							<?php endif; ?>
						<?php else : ?>
							<span class="agent-access-status-pill agent-access-status-pill--unavailable">
								<?php esc_html_e( 'Not Available', 'agent-access' ); ?>
							</span>
						<?php endif; ?>
					</div>
					<div class="agent-access-card-body">
						<?php if ( $has_provisioner ) : ?>
							<p>
								<?php esc_html_e( 'Allow AI agents to self-register their own WordPress accounts via the REST API — no human sign-up required.', 'agent-access' ); ?>
							</p>
							<p>
								<?php esc_html_e( 'Provisioned agents get a dedicated account with an Application Password, rate limiting, spam protection, and optional Gravatar verification for search indexing.', 'agent-access' ); ?>
							</p>
							<ul class="agent-access-feature-list">
								<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Agents self-register via POST /agent-access/v1/provision', 'agent-access' ); ?></li>
								<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Daily post throttling & Akismet spam checks', 'agent-access' ); ?></li>
								<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Gravatar verification for search visibility', 'agent-access' ); ?></li>
								<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Credential recovery via verified email', 'agent-access' ); ?></li>
							</ul>
							<div class="agent-access-card-actions">
								<code class="agent-access-endpoint-pill">POST <?php echo esc_html( rest_url( 'agent-access/v1/provision' ) ); ?></code>
							</div>
						<?php else : ?>
							<p class="agent-access-muted">
								<?php esc_html_e( 'The Agent Provisioner module is not available. It is included in Agent Access 2.0+ when the plugin is fully installed.', 'agent-access' ); ?>
							</p>
							<p class="agent-access-muted">
								<?php esc_html_e( 'With provisioning enabled, external AI agents can self-register and receive their own WordPress accounts automatically via the REST API.', 'agent-access' ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>

			</div><!-- .agent-access-cards-grid -->

			<?php /* ── Recent connected agents preview ── */ ?>
			<?php if ( ! empty( $connected_users ) ) : ?>
			<div class="agent-access-section">
				<h2 class="agent-access-section-title">
					<span class="dashicons dashicons-admin-users"></span>
					<?php esc_html_e( 'Recent Agent Connections', 'agent-access' ); ?>
				</h2>
				<table class="widefat striped agent-access-dashboard-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Role', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Connected', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Posts', 'agent-access' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$preview = array_slice( $connected_users, 0, 5 );
						foreach ( $preview as $entry ) :
						?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( get_edit_user_link( $entry['user']->ID ) ); ?>">
											<?php echo esc_html( $entry['user']->display_name ); ?>
										</a>
									</strong>
									<span class="description"> — <?php echo esc_html( $entry['user']->user_login ); ?></span>
								</td>
								<td>
									<span class="agent-access-badge agent-access-badge--<?php echo esc_attr( $entry['role_slug'] ); ?>">
										<?php echo esc_html( $entry['role_name'] ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $entry['created'] ); ?></td>
								<td><?php echo esc_html( $entry['last_used'] ); ?></td>
								<td><?php echo esc_html( $entry['stats']['post_count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $connected_users ) > 5 ) : ?>
					<p>
						<a href="<?php echo esc_url( $connected_page_url ); ?>" class="button">
							<?php
							printf(
								/* translators: %d: number of agents */
								esc_html__( 'View all %d connected agents →', 'agent-access' ),
								(int) $connected_count
							);
							?>
						</a>
					</p>
				<?php endif; ?>
			</div>
			<?php endif; ?>

		</div><!-- .agent-access-dashboard -->
		<?php
	}

	// ── Connected Agents page ─────────────────────────────────────────────────

	/**
	 * Render the Connected Agents page (all users with active agent connections).
	 */
	public function render_connected_agents_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-access' ) );
		}

		$users_with_passwords = $this->get_all_openclaw_users();
		?>
		<div class="wrap">
			<h1>
				<span>&#129438;</span>
				<?php esc_html_e( 'Connected Agents', 'agent-access' ); ?>
			</h1>
			<p><?php esc_html_e( 'All users with active agent connections on this site.', 'agent-access' ); ?></p>

			<?php if ( empty( $users_with_passwords ) ) : ?>
				<p><em><?php esc_html_e( 'No users have connected an agent yet.', 'agent-access' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Role', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Created', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Posts', 'agent-access' ); ?></th>
							<th><?php esc_html_e( 'Media', 'agent-access' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $users_with_passwords as $entry ) : ?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( get_edit_user_link( $entry['user']->ID ) ); ?>">
											<?php echo esc_html( $entry['user']->display_name ); ?>
										</a>
									</strong>
									<br>
									<span class="description"><?php echo esc_html( $entry['user']->user_login ); ?></span>
								</td>
								<td>
									<span class="agent-access-badge agent-access-badge--<?php echo esc_attr( $entry['role_slug'] ); ?>">
										<?php echo esc_html( $entry['role_name'] ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $entry['created'] ); ?></td>
								<td><?php echo esc_html( $entry['last_used'] ); ?></td>
								<td><?php echo esc_html( $entry['stats']['post_count'] ); ?></td>
								<td><?php echo esc_html( $entry['stats']['media_count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Helper queries ────────────────────────────────────────────────────────

	/**
	 * Get all users who have an Agent Access Application Password.
	 *
	 * @return array
	 */
	private function get_all_openclaw_users() {
		$results = array();
		$users   = get_users( array( 'number' => 200 ) );

		foreach ( $users as $user ) {
			$passwords = WP_Application_Passwords::get_user_application_passwords( $user->ID );

			foreach ( $passwords as $item ) {
				if ( $item['name'] !== AGENT_ACCESS_APP_PASSWORD_NAME ) {
					continue;
				}

				$roles     = $user->roles;
				$role_slug = ! empty( $roles ) ? $roles[0] : 'none';

				$wp_roles  = wp_roles();
				$role_name = isset( $wp_roles->role_names[ $role_slug ] )
					? translate_user_role( $wp_roles->role_names[ $role_slug ] )
					: ucfirst( $role_slug );

				$created   = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created'] );
				$last_used = ! empty( $item['last_used'] )
					? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['last_used'] )
					: __( 'Never', 'agent-access' );

				$results[] = array(
					'user'      => $user,
					'role_slug' => $role_slug,
					'role_name' => $role_name,
					'created'   => $created,
					'last_used' => $last_used,
					'stats'     => Agent_Access_Tracker::get_stats( $user->ID ),
				);

				break; // Only one Agent Access password per user.
			}
		}

		return $results;
	}

	/**
	 * Count provisioned agent accounts.
	 *
	 * @return int
	 */
	private function get_provisioned_count() {
		$provisioned = get_users( array(
			'meta_query' => array(
				'relation' => 'OR',
				array( 'key' => '_agent_access_provisioned', 'compare' => 'EXISTS' ),
				array( 'key' => '_clawpress_provisioned',   'compare' => 'EXISTS' ),
			),
			'fields' => 'ID',
			'number' => -1,
		) );

		return count( $provisioned );
	}

	/**
	 * Check whether the provisioner REST route is registered (i.e. enabled).
	 *
	 * @return bool
	 */
	private function is_provisioner_enabled() {
		if ( ! class_exists( 'Agent_Access_Provisioner' ) ) {
			return false;
		}
		// The provisioner is always enabled once the class is instantiated (its init
		// registers the routes); we just confirm the class exists.
		return true;
	}

	/**
	 * Count posts created via agent in the last 30 days (across all users).
	 *
	 * @return int
	 */
	private function get_recent_activity_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			 AND p.post_type IN ('post', 'page')
			 AND p.post_status != 'trash'
			 AND p.post_date >= %s",
			Agent_Access_Tracker::META_KEY,
			gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
		) );

		return $count;
	}
}
