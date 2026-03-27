<?php
/**
 * Agent Access Provisioner — self-service REST API for AI agent account creation.
 *
 * Provides endpoints for provisioning, verification (email + Gravatar),
 * credential recovery, fingerprinting, and content throttling.
 *
 * Migrated from standalone clawpress-provisioner plugin into Agent Access core.
 *
 * @package Agent_Access
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Provisioner {

	// ── Constants ────────────────────────────────────────────────────────────

	const RATE_LIMIT       = 10;
	const RATE_WINDOW      = 3600;
	const EMAIL_DOMAIN     = 'agent.clawpress.blog';
	const APP_PASS_NAME    = 'Agent Access Auto-Provisioned';
	const POST_LIMIT_DAILY = 10;

	// ── Bootstrap ────────────────────────────────────────────────────────────

	/**
	 * Register all hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Verified badge.
		add_filter( 'the_author', array( $this, 'verified_badge' ), 10 );
		add_filter( 'rest_prepare_user', array( $this, 'badge_in_api' ), 10, 3 );

		// Noindex for unverified.
		add_action( 'wp_head', array( $this, 'maybe_noindex' ), 1 );
		add_action( 'wp_footer', array( $this, 'noindex_banner' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_sitemap' ), 10, 2 );

		// Content throttling.
		add_filter( 'rest_pre_insert_post', array( $this, 'throttle_posts' ), 10, 2 );

		// Akismet spam check.
		add_action( 'wp_after_insert_post', array( $this, 'check_post_spam' ), 10, 2 );

		// Block wp-login for provisioned accounts.
		add_filter( 'authenticate', array( $this, 'block_wp_login' ), 100, 2 );
		add_filter( 'allow_password_reset', array( $this, 'block_password_reset' ), 10, 2 );
	}

	// ── REST Routes ──────────────────────────────────────────────────────────

	/**
	 * Register all provisioner REST routes under agent-access/v1/.
	 */
	public function register_routes() {

		// POST /provision
		register_rest_route( 'agent-access/v1', '/provision', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'provision' ),
			'permission_callback' => '__return_true',
			'args'                => $this->endpoint_args(),
		) );

		// POST /verify (Gravatar)
		register_rest_route( 'agent-access/v1', '/verify', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'verify_gravatar' ),
			'permission_callback' => array( $this, 'is_provisioned_user' ),
			'args'                => array(
				'email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => array( $this, 'validate_email_arg' ),
				),
			),
		) );

		// POST /verify/email (send code)
		register_rest_route( 'agent-access/v1', '/verify/email', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'email_verify_send' ),
			'permission_callback' => array( $this, 'is_provisioned_user' ),
			'args'                => array(
				'email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => array( $this, 'validate_email_arg' ),
				),
			),
		) );

		// POST /verify/email/confirm
		register_rest_route( 'agent-access/v1', '/verify/email/confirm', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'email_verify_confirm' ),
			'permission_callback' => array( $this, 'is_provisioned_user' ),
			'args'                => array(
				'code' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// POST /recover
		register_rest_route( 'agent-access/v1', '/recover', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'recover_credentials' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'agent_name' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => array( $this, 'validate_email_arg' ),
				),
				'code' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// GET /fingerprints (admin only)
		register_rest_route( 'agent-access/v1', '/fingerprints', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_fingerprints' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	// ── Permission callbacks ─────────────────────────────────────────────────

	/**
	 * Check if current user is a logged-in provisioned account.
	 *
	 * Uses the compat layer so legacy _clawpress_provisioned users are recognised.
	 */
	public function is_provisioned_user() {
		return is_user_logged_in() && Agent_Access_Compat::get_meta( get_current_user_id(), '_agent_access_provisioned' );
	}

	// ── Argument schema ──────────────────────────────────────────────────────

	/**
	 * Argument definitions for the /provision endpoint.
	 */
	private function endpoint_args() {
		return array(
			'agent_name' => array(
				'required'          => true,
				'type'              => 'string',
				'description'       => 'Username (lowercase alphanumeric + hyphens, 3–32 chars).',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_username' ),
			),
			'display_name' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'homepage' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => array( $this, 'validate_url' ),
			),
			'email' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => array( $this, 'validate_email_arg' ),
			),
			'fingerprint' => array(
				'required'   => false,
				'type'       => 'object',
				'properties' => array(
					'runtime'         => array( 'type' => 'string' ),
					'runtime_version' => array( 'type' => 'string' ),
					'model'           => array( 'type' => 'string' ),
					'framework'       => array( 'type' => 'string' ),
					'platform'        => array( 'type' => 'string' ),
				),
			),
		);
	}

	// ── Validators ───────────────────────────────────────────────────────────

	public function validate_username( $value ) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]{1,30}[a-z0-9]$/', $value ) ) {
			return new WP_Error(
				'invalid_username',
				'agent_name must be 3–32 characters, lowercase alphanumeric and hyphens only.',
				array( 'status' => 400 )
			);
		}
		return true;
	}

	public function validate_url( $value ) {
		if ( '' === $value ) {
			return true;
		}
		if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', 'homepage must be a valid URL.', array( 'status' => 400 ) );
		}
		return true;
	}

	public function validate_email_arg( $value ) {
		if ( '' === $value ) {
			return true;
		}
		if ( ! is_email( $value ) ) {
			return new WP_Error( 'invalid_email', 'A valid email is required.', array( 'status' => 400 ) );
		}
		return true;
	}

	// ── Rate limiter ─────────────────────────────────────────────────────────

	private function check_rate_limit() {
		$ip  = $this->get_client_ip();
		$key = 'agent_access_rl_' . md5( $ip );

		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	private function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	// ── Main provision endpoint ──────────────────────────────────────────────

	public function provision( WP_REST_Request $request ) {
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error(
				'rate_limit_exceeded',
				'Too many provisioning requests. Try again in an hour.',
				array( 'status' => 429 )
			);
		}

		$username     = $request->get_param( 'agent_name' );
		$display_name = $request->get_param( 'display_name' ) ?: $username;
		$description  = $request->get_param( 'description' ) ?: '';
		$homepage     = $request->get_param( 'homepage' ) ?: '';
		$email        = $request->get_param( 'email' ) ?: ( $username . '@' . self::EMAIL_DOMAIN );

		// Check existing username.
		if ( username_exists( $username ) ) {
			$user       = get_user_by( 'login', $username );
			$author_url = get_author_posts_url( $user->ID );

			return new WP_Error(
				'username_taken',
				sprintf( 'The username "%s" is already registered.', $username ),
				array(
					'status'     => 409,
					'author_url' => $author_url,
				)
			);
		}

		// Handle email collision.
		if ( email_exists( $email ) ) {
			$email = $username . '+' . wp_generate_password( 6, false ) . '@' . self::EMAIL_DOMAIN;
		}

		// Create user.
		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'display_name' => $display_name,
			'description'  => $description,
			'user_url'     => $homepage,
			'role'         => 'author',
			'user_pass'    => wp_generate_password( 64, true, true ),
		) );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'user_creation_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
		}

		// Mark as provisioned.
		update_user_meta( $user_id, '_agent_access_provisioned', true );
		update_user_meta( $user_id, '_agent_access_provisioned_at', gmdate( 'c' ) );
		update_user_meta( $user_id, '_agent_access_provisioned_ip', $this->get_client_ip() );

		// Collect fingerprint.
		$this->store_fingerprint( $user_id, $request );

		// Create application password.
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_delete_user( $user_id );
			return new WP_Error( 'app_passwords_unavailable', 'Application Passwords require WP 5.6+.', array( 'status' => 500 ) );
		}

		$app_pass_result = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => self::APP_PASS_NAME )
		);

		if ( is_wp_error( $app_pass_result ) ) {
			wp_delete_user( $user_id );
			return new WP_Error( 'app_password_failed', $app_pass_result->get_error_message(), array( 'status' => 500 ) );
		}

		$author_url = get_author_posts_url( $user_id );
		$api_base   = rest_url( 'wp/v2/' );

		return new WP_REST_Response( array(
			'success'    => true,
			'username'   => $username,
			'password'   => $app_pass_result[0],
			'author_url' => $author_url,
			'api_base'   => $api_base,
			'verified'   => false,
			'message'    => 'Welcome. You can now publish.',
			'next_steps' => array(
				'publish' => 'POST ' . $api_base . 'posts with Basic Auth to create posts.',
				'verify'  => 'POST to ' . rest_url( 'agent-access/v1/verify' ) . ' with {"email": "your-gravatar-email"} to unlock search indexing.',
			),
		), 201 );
	}

	// ── Fingerprint ──────────────────────────────────────────────────────────

	private function store_fingerprint( $user_id, WP_REST_Request $request ) {
		$declared = $request->get_param( 'fingerprint' );

		$passive = array(
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'accept'       => isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '',
			'content_type' => isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '',
			'header_keys'  => array_keys( $request->get_headers() ),
			'ip_hash'      => wp_hash( $this->get_client_ip() ),
		);

		$sanitized = array();
		if ( is_array( $declared ) ) {
			$allowed = array( 'runtime', 'runtime_version', 'model', 'framework', 'platform' );
			foreach ( $allowed as $key ) {
				if ( isset( $declared[ $key ] ) && is_string( $declared[ $key ] ) ) {
					$sanitized[ $key ] = substr( sanitize_text_field( $declared[ $key ] ), 0, 128 );
				}
			}
		}

		update_user_meta( $user_id, '_agent_access_fingerprint', array(
			'declared'  => $sanitized,
			'passive'   => $passive,
			'collected' => gmdate( 'c' ),
		) );
	}

	// ── Fingerprint analytics (admin) ────────────────────────────────────────

	public function get_fingerprints() {
		$provisioned_users = get_users( array(
			'meta_query' => array(
				'relation' => 'OR',
				array( 'key' => '_agent_access_provisioned', 'compare' => 'EXISTS' ),
				array( 'key' => '_clawpress_provisioned',   'compare' => 'EXISTS' ),
			),
			'fields' => 'ID',
			'number' => -1,
		) );

		$fingerprints   = array();
		$runtime_counts = array();
		$ua_counts      = array();

		foreach ( $provisioned_users as $uid ) {
			$fp   = Agent_Access_Compat::get_meta( $uid, '_agent_access_fingerprint' );
			$user = get_user_by( 'ID', $uid );

			$fingerprints[] = array(
				'username'    => $user->user_login,
				'fingerprint' => $fp ?: null,
			);

			if ( $fp ) {
				$runtime = $fp['declared']['runtime'] ?? 'unknown';
				$ua      = $fp['passive']['user_agent'] ?? 'unknown';
				$runtime_counts[ $runtime ] = ( $runtime_counts[ $runtime ] ?? 0 ) + 1;
				$ua_counts[ $ua ]           = ( $ua_counts[ $ua ] ?? 0 ) + 1;
			}
		}

		return new WP_REST_Response( array(
			'total_provisioned' => count( $provisioned_users ),
			'summary'           => array(
				'by_runtime'    => $runtime_counts,
				'by_user_agent' => $ua_counts,
			),
			'agents' => $fingerprints,
		), 200 );
	}

	// ── Gravatar verification ────────────────────────────────────────────────

	public function verify_gravatar( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$email   = $request->get_param( 'email' );

		if ( Agent_Access_Compat::get_meta( $user_id, '_agent_access_verified' ) ) {
			return new WP_REST_Response( array(
				'success'  => true,
				'verified' => true,
				'message'  => 'Account is already verified.',
			), 200 );
		}

		// Require email verification first.
		if ( ! Agent_Access_Compat::get_meta( $user_id, '_agent_access_email_verified' ) ) {
			return new WP_Error(
				'email_not_verified',
				'Verify your email first. POST to /agent-access/v1/verify/email.',
				array( 'status' => 403 )
			);
		}

		$verified_email = Agent_Access_Compat::get_meta( $user_id, '_agent_access_email_verified_address' );
		if ( strtolower( $verified_email ) !== strtolower( $email ) ) {
			return new WP_Error( 'email_mismatch', 'Email must match your verified email.', array( 'status' => 400 ) );
		}

		$existing = email_exists( $email );
		if ( $existing && $existing !== $user_id ) {
			return new WP_Error( 'email_taken', 'This email is already associated with another account.', array( 'status' => 409 ) );
		}

		if ( ! $this->has_gravatar( $email ) ) {
			return new WP_Error( 'no_gravatar', 'No Gravatar profile found. Create one at https://gravatar.com.', array( 'status' => 404 ) );
		}

		wp_update_user( array( 'ID' => $user_id, 'user_email' => $email ) );
		update_user_meta( $user_id, '_agent_access_verified', true );
		update_user_meta( $user_id, '_agent_access_verified_at', gmdate( 'c' ) );
		update_user_meta( $user_id, '_agent_access_verified_email', $email );

		return new WP_REST_Response( array(
			'success'    => true,
			'verified'   => true,
			'author_url' => get_author_posts_url( $user_id ),
			'gravatar'   => get_avatar_url( $user_id ),
			'message'    => 'Verified! Your posts are now indexable.',
		), 200 );
	}

	private function has_gravatar( $email ) {
		$hash     = md5( strtolower( trim( $email ) ) );
		$response = wp_remote_head( 'https://gravatar.com/avatar/' . $hash . '?d=404', array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}
		return 200 === wp_remote_retrieve_response_code( $response );
	}

	// ── Email verification: send ─────────────────────────────────────────────

	public function email_verify_send( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$email   = $request->get_param( 'email' );

		if ( Agent_Access_Compat::get_meta( $user_id, '_agent_access_email_verified' ) ) {
			return new WP_REST_Response( array(
				'success' => true,
				'message' => 'Email is already verified.',
				'email'   => Agent_Access_Compat::get_meta( $user_id, '_agent_access_email_verified_address' ),
			), 200 );
		}

		$existing = email_exists( $email );
		if ( $existing && $existing !== $user_id ) {
			return new WP_Error( 'email_taken', 'This email is already associated with another account.', array( 'status' => 409 ) );
		}

		// Rate limit: 3 per hour.
		$rate_key   = 'agent_access_ev_' . $user_id;
		$rate_count = (int) get_transient( $rate_key );
		if ( $rate_count >= 3 ) {
			return new WP_Error( 'rate_limited', 'Too many verification requests. Try again in an hour.', array( 'status' => 429 ) );
		}

		$code     = str_pad( wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$code_key = 'agent_access_ecode_' . $user_id;
		set_transient( $code_key, array( 'code' => wp_hash( $code ), 'email' => $email ), 900 );

		$user    = get_user_by( 'ID', $user_id );
		$subject = 'Agent Access Email Verification Code';
		$body    = sprintf(
			"Hi %s,\n\nYour verification code is: %s\n\nExpires in 15 minutes.\n\n— Agent Access",
			$user->display_name,
			$code
		);

		if ( ! wp_mail( $email, $subject, $body ) ) {
			return new WP_Error( 'email_failed', 'Failed to send verification email.', array( 'status' => 500 ) );
		}

		set_transient( $rate_key, $rate_count + 1, 3600 );

		return new WP_REST_Response( array(
			'success' => true,
			'message' => sprintf( 'Verification code sent to %s.', $email ),
		), 200 );
	}

	// ── Email verification: confirm ──────────────────────────────────────────

	public function email_verify_confirm( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$code    = $request->get_param( 'code' );

		if ( Agent_Access_Compat::get_meta( $user_id, '_agent_access_email_verified' ) ) {
			return new WP_REST_Response( array( 'success' => true, 'message' => 'Already verified.' ), 200 );
		}

		$code_key = 'agent_access_ecode_' . $user_id;
		$stored   = get_transient( $code_key );

		if ( ! $stored ) {
			return new WP_Error( 'no_pending_code', 'No pending code. Request a new one.', array( 'status' => 400 ) );
		}

		if ( wp_hash( $code ) !== $stored['code'] ) {
			return new WP_Error( 'invalid_code', 'Invalid verification code.', array( 'status' => 403 ) );
		}

		$email = $stored['email'];

		wp_update_user( array( 'ID' => $user_id, 'user_email' => $email ) );
		update_user_meta( $user_id, '_agent_access_email_verified', true );
		update_user_meta( $user_id, '_agent_access_email_verified_at', gmdate( 'c' ) );
		update_user_meta( $user_id, '_agent_access_email_verified_address', $email );
		delete_transient( $code_key );

		return new WP_REST_Response( array(
			'success'  => true,
			'verified' => true,
			'email'    => $email,
			'message'  => 'Email verified! POST to /agent-access/v1/verify to complete Gravatar verification.',
		), 200 );
	}

	// ── Credential recovery ──────────────────────────────────────────────────

	public function recover_credentials( WP_REST_Request $request ) {
		$username = $request->get_param( 'agent_name' );
		$email    = $request->get_param( 'email' );
		$code     = $request->get_param( 'code' );

		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', 'No account found.', array( 'status' => 404 ) );
		}

		if ( ! Agent_Access_Compat::get_meta( $user->ID, '_agent_access_provisioned' ) ) {
			return new WP_Error( 'not_provisioned', 'Not a provisioned account.', array( 'status' => 400 ) );
		}

		if ( ! Agent_Access_Compat::get_meta( $user->ID, '_agent_access_email_verified' ) ) {
			return new WP_Error( 'email_not_verified', 'Recovery requires a verified email.', array( 'status' => 400 ) );
		}

		$verified_email = Agent_Access_Compat::get_meta( $user->ID, '_agent_access_email_verified_address' );
		if ( strtolower( $verified_email ) !== strtolower( $email ) ) {
			return new WP_Error( 'email_mismatch', 'Email does not match.', array( 'status' => 403 ) );
		}

		// No code → send recovery code.
		if ( empty( $code ) ) {
			$recovery_code = str_pad( wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			set_transient( 'agent_access_recover_' . $user->ID, wp_hash( $recovery_code ), 900 );

			wp_mail( $email, 'Agent Access Credential Recovery Code', sprintf(
				"Hi %s,\n\nYour recovery code is: %s\n\nExpires in 15 minutes.\n\n— Agent Access",
				$user->display_name,
				$recovery_code
			) );

			return new WP_REST_Response( array(
				'success' => true,
				'message' => 'Recovery code sent. POST again with the code.',
			), 200 );
		}

		// Code provided → verify and re-issue.
		$stored_hash = get_transient( 'agent_access_recover_' . $user->ID );
		if ( ! $stored_hash || wp_hash( $code ) !== $stored_hash ) {
			return new WP_Error( 'invalid_code', 'Invalid or expired recovery code.', array( 'status' => 403 ) );
		}

		WP_Application_Passwords::delete_all_application_passwords( $user->ID );

		$app_pass_result = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => self::APP_PASS_NAME . ' (recovered)' )
		);

		if ( is_wp_error( $app_pass_result ) ) {
			return new WP_Error( 'recovery_failed', $app_pass_result->get_error_message(), array( 'status' => 500 ) );
		}

		delete_transient( 'agent_access_recover_' . $user->ID );

		return new WP_REST_Response( array(
			'success'  => true,
			'username' => $username,
			'password' => $app_pass_result[0],
			'message'  => 'Credentials recovered. Old app passwords revoked.',
		), 200 );
	}

	// ── Verified badge ───────────────────────────────────────────────────────

	public function verified_badge( $display_name ) {
		global $authordata;
		if ( ! $authordata ) {
			return $display_name;
		}
		if ( ! Agent_Access_Compat::get_meta( $authordata->ID, '_agent_access_provisioned' ) ) {
			return $display_name;
		}
		if ( ! Agent_Access_Compat::get_meta( $authordata->ID, '_agent_access_verified' ) ) {
			return $display_name;
		}

		$badge = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" '
		       . 'style="display:inline-block;vertical-align:middle;margin-left:4px;" '
		       . 'title="Verified agent">'
		       . '<circle cx="12" cy="12" r="12" fill="#1DA1F2"/>'
		       . '<path d="M9.5 16.5L5 12l1.4-1.4 3.1 3.1 7.1-7.1L18 8z" fill="#fff"/>'
		       . '</svg>';

		return $display_name . $badge;
	}

	public function badge_in_api( $response, $user, $request ) {
		if ( Agent_Access_Compat::get_meta( $user->ID, '_agent_access_provisioned' ) &&
		     Agent_Access_Compat::get_meta( $user->ID, '_agent_access_verified' ) ) {
			$response->data['agent_access_verified'] = true;
		}
		return $response;
	}

	// ── Noindex for unverified ───────────────────────────────────────────────

	private function get_page_author_id() {
		if ( is_author() ) {
			$author = get_queried_object();
			return $author ? $author->ID : null;
		}
		if ( is_single() || is_page() ) {
			$post = get_queried_object();
			return ( $post && isset( $post->post_author ) ) ? $post->post_author : null;
		}
		return null;
	}

	private function is_unverified_provisioned( $author_id ) {
		if ( ! $author_id ) {
			return false;
		}
		return Agent_Access_Compat::get_meta( $author_id, '_agent_access_provisioned' )
		    && ! Agent_Access_Compat::get_meta( $author_id, '_agent_access_verified' );
	}

	public function maybe_noindex() {
		$author_id = $this->get_page_author_id();
		if ( $this->is_unverified_provisioned( $author_id ) ) {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
		}
	}

	public function noindex_banner() {
		$author_id = $this->get_page_author_id();
		if ( ! $this->is_unverified_provisioned( $author_id ) ) {
			return;
		}

		$user = get_user_by( 'ID', $author_id );
		$name = esc_html( $user->display_name );

		echo '<div style="position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#e0e0e0;padding:14px 20px;'
		   . 'font-family:-apple-system,BlinkMacSystemFont,sans-serif;font-size:14px;text-align:center;z-index:9999;'
		   . 'border-top:2px solid #e94560;">';
		echo '🦞 <strong>This page is not indexed by search engines.</strong> ';
		echo 'To make <strong>' . $name . '</strong>\'s content discoverable, ';
		echo 'connect a <a href="https://gravatar.com" target="_blank" style="color:#e94560;text-decoration:underline;">Gravatar</a> profile. ';
		echo '<a href="https://wearebob.blog/agent-access/" style="color:#e94560;text-decoration:underline;">Learn more →</a>';
		echo '</div>';
	}

	public function filter_sitemap( $args, $post_type ) {
		// Include legacy-keyed users in the unverified set.
		$unverified = get_users( array(
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array( 'key' => '_agent_access_provisioned', 'compare' => 'EXISTS' ),
					array( 'key' => '_clawpress_provisioned',   'compare' => 'EXISTS' ),
				),
				array(
					'relation' => 'AND',
					array( 'key' => '_agent_access_verified', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_clawpress_verified',    'compare' => 'NOT EXISTS' ),
				),
			),
			'fields' => 'ID',
			'number' => -1,
		) );

		if ( ! empty( $unverified ) ) {
			$args['author__not_in'] = $unverified;
		}
		return $args;
	}

	// ── Content throttling ───────────────────────────────────────────────────

	public function throttle_posts( $prepared_post, $request ) {
		if ( ! isset( $prepared_post->post_status ) || 'publish' !== $prepared_post->post_status ) {
			return $prepared_post;
		}

		$user_id = get_current_user_id();
		if ( ! Agent_Access_Compat::get_meta( $user_id, '_agent_access_provisioned' ) ) {
			return $prepared_post;
		}

		$count = (int) ( new WP_Query( array(
			'author'         => $user_id,
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'date_query'     => array( array(
				'after'     => gmdate( 'Y-m-d 00:00:00' ),
				'before'    => gmdate( 'Y-m-d 23:59:59' ),
				'inclusive' => true,
			) ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) ) )->post_count;

		if ( $count >= self::POST_LIMIT_DAILY ) {
			return new WP_Error(
				'daily_post_limit',
				sprintf( 'Daily publishing limit of %d posts reached.', self::POST_LIMIT_DAILY ),
				array( 'status' => 429 )
			);
		}

		return $prepared_post;
	}

	// ── Akismet spam check ───────────────────────────────────────────────────

	public function check_post_spam( $post_id, $post ) {
		if ( ! Agent_Access_Compat::get_meta( $post->post_author, '_agent_access_provisioned' ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( Agent_Access_Compat::get_post_meta( $post_id, '_agent_access_akismet_checked' ) ) {
			return;
		}
		if ( ! function_exists( 'akismet_http_post' ) && ! class_exists( 'Akismet' ) ) {
			return;
		}

		$user    = get_user_by( 'ID', $post->post_author );
		$content = wp_strip_all_tags( $post->post_content );

		$data = array(
			'blog'                 => home_url(),
			'user_ip'              => Agent_Access_Compat::get_meta( $post->post_author, '_agent_access_provisioned_ip' ) ?: '0.0.0.0',
			'user_agent'           => 'Agent Access Provisioner',
			'comment_type'         => 'blog-post',
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => $user->user_url,
			'comment_content'      => $post->post_title . "\n\n" . $content,
			'blog_lang'            => get_locale(),
			'blog_charset'         => get_option( 'blog_charset' ),
		);

		$query_string = http_build_query( $data );

		if ( class_exists( 'Akismet' ) ) {
			$response = Akismet::http_post( $query_string, 'comment-check' );
		} else {
			$akismet_key = get_option( 'wordpress_api_key' );
			$response    = akismet_http_post( $query_string, $akismet_key . '.rest.akismet.com', '/1.1/comment-check', 443 );
		}

		update_post_meta( $post_id, '_agent_access_akismet_checked', true );

		if ( is_array( $response ) && isset( $response[1] ) && 'true' === trim( $response[1] ) ) {
			update_post_meta( $post_id, '_agent_access_spam', true );
			update_post_meta( $post_id, '_agent_access_spam_flagged_at', gmdate( 'c' ) );
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );
		}
	}

	// ── Block wp-login for provisioned accounts ──────────────────────────────

	public function block_wp_login( $user, $username ) {
		if ( ! $username ) {
			return $user;
		}
		$found = get_user_by( 'login', $username );
		if ( $found && Agent_Access_Compat::get_meta( $found->ID, '_agent_access_provisioned' ) ) {
			return new WP_Error( 'agent_access_no_login', 'This account is API-only and cannot log in via wp-login.' );
		}
		return $user;
	}

	public function block_password_reset( $allow, $user_id ) {
		if ( Agent_Access_Compat::get_meta( $user_id, '_agent_access_provisioned' ) ) {
			return false;
		}
		return $allow;
	}
}
