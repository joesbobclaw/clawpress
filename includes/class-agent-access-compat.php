<?php
/**
 * Agent Access Compatibility Layer
 *
 * Bridges the rename from ClawPress → Agent Access by providing meta-key fallback
 * helpers and legacy REST route proxies. Keeps existing clawpress.blog users
 * working without a forced migration.
 *
 * @package Agent_Access
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Compat {

	// ── Meta key maps ─────────────────────────────────────────────────────────

	/**
	 * Mapping of legacy _clawpress_* user meta keys → new _agent_access_* keys.
	 *
	 * @var array<string,string>
	 */
	private static $user_meta_map = array(
		'_clawpress_provisioned'            => '_agent_access_provisioned',
		'_clawpress_provisioned_at'         => '_agent_access_provisioned_at',
		'_clawpress_provisioned_ip'         => '_agent_access_provisioned_ip',
		'_clawpress_fingerprint'            => '_agent_access_fingerprint',
		'_clawpress_verified'               => '_agent_access_verified',
		'_clawpress_verified_at'            => '_agent_access_verified_at',
		'_clawpress_verified_email'         => '_agent_access_verified_email',
		'_clawpress_email_verified'         => '_agent_access_email_verified',
		'_clawpress_email_verified_at'      => '_agent_access_email_verified_at',
		'_clawpress_email_verified_address' => '_agent_access_email_verified_address',
	);

	/**
	 * Mapping of legacy _clawpress_* post meta keys → new _agent_access_* keys.
	 *
	 * @var array<string,string>
	 */
	private static $post_meta_map = array(
		'_clawpress_akismet_checked' => '_agent_access_akismet_checked',
		'_clawpress_spam'            => '_agent_access_spam',
		'_clawpress_spam_flagged_at' => '_agent_access_spam_flagged_at',
	);

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	/**
	 * Register legacy REST route proxies.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_legacy_routes' ) );
	}

	// ── User meta helpers ─────────────────────────────────────────────────────

	/**
	 * Read user meta, falling back to the legacy _clawpress_* key if the new key
	 * has no value yet.
	 *
	 * Pass the *new* _agent_access_* key; the legacy key is derived automatically.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     New meta key (_agent_access_*).
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	public static function get_meta( $user_id, $key, $single = true ) {
		// get_user_meta without $single returns [] when the key doesn't exist —
		// a reliable "not set" signal regardless of the stored value.
		$values = get_user_meta( $user_id, $key );

		if ( ! empty( $values ) ) {
			return $single ? $values[0] : $values;
		}

		// Fallback: swap prefix and try the legacy key.
		$legacy_key = str_replace( '_agent_access_', '_clawpress_', $key );
		if ( $legacy_key === $key ) {
			// Key doesn't follow the expected pattern; return WordPress default.
			return $single ? '' : array();
		}

		return get_user_meta( $user_id, $legacy_key, $single );
	}

	/**
	 * Write user meta using the new _agent_access_* namespace.
	 *
	 * Accepts either the new or legacy key; always writes to new namespace.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key (new or legacy).
	 * @param mixed  $value   Value to store.
	 * @return int|bool
	 */
	public static function update_meta( $user_id, $key, $value ) {
		$new_key = str_replace( '_clawpress_', '_agent_access_', $key );
		return update_user_meta( $user_id, $new_key, $value );
	}

	// ── Post meta helpers ─────────────────────────────────────────────────────

	/**
	 * Read post meta, falling back to the legacy _clawpress_* key if not set.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     New meta key (_agent_access_*).
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	public static function get_post_meta( $post_id, $key, $single = true ) {
		$values = get_post_meta( $post_id, $key );

		if ( ! empty( $values ) ) {
			return $single ? $values[0] : $values;
		}

		$legacy_key = str_replace( '_agent_access_', '_clawpress_', $key );
		if ( $legacy_key === $key ) {
			return $single ? '' : array();
		}

		return get_post_meta( $post_id, $legacy_key, $single );
	}

	// ── Legacy detection ──────────────────────────────────────────────────────

	/**
	 * Check whether a user still has any _clawpress_* meta keys.
	 *
	 * Used by the migrator to decide whether to show the admin notice.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_legacy_meta( $user_id ) {
		foreach ( array_keys( self::$user_meta_map ) as $legacy_key ) {
			if ( '' !== get_user_meta( $user_id, $legacy_key, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the full user meta map (legacy → new) for use by the migrator.
	 *
	 * @return array<string,string>
	 */
	public static function get_user_meta_map() {
		return self::$user_meta_map;
	}

	/**
	 * Return the full post meta map (legacy → new) for use by the migrator.
	 *
	 * @return array<string,string>
	 */
	public static function get_post_meta_map() {
		return self::$post_meta_map;
	}

	// ── Legacy REST routes ────────────────────────────────────────────────────

	/**
	 * Register clawpress/v1 routes that proxy to their agent-access/v1 equivalents.
	 *
	 * All proxied responses carry a Deprecation header and a Link header pointing
	 * to the canonical successor route per RFC 8594.
	 */
	public static function register_legacy_routes() {
		$routes = array(
			array(
				'route'   => '/provision',
				'methods' => WP_REST_Server::CREATABLE,
			),
			array(
				'route'   => '/verify',
				'methods' => WP_REST_Server::CREATABLE,
			),
			array(
				'route'   => '/verify/email',
				'methods' => WP_REST_Server::CREATABLE,
			),
			array(
				'route'   => '/verify/email/confirm',
				'methods' => WP_REST_Server::CREATABLE,
			),
			array(
				'route'   => '/recover',
				'methods' => WP_REST_Server::CREATABLE,
			),
			array(
				'route'   => '/fingerprints',
				'methods' => WP_REST_Server::READABLE,
			),
		);

		foreach ( $routes as $route_def ) {
			$new_path = '/agent-access/v1' . $route_def['route'];

			register_rest_route(
				'clawpress/v1',
				$route_def['route'],
				array(
					'methods'             => $route_def['methods'],
					// Permission checks are enforced by the proxied route.
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) use ( $new_path ) {
						return Agent_Access_Compat::proxy_request( $request, $new_path );
					},
				)
			);
		}
	}

	/**
	 * Proxy a REST request to the canonical agent-access/v1 route.
	 *
	 * Copies method, body params, query params, and headers; adds deprecation
	 * headers to the response.
	 *
	 * @param WP_REST_Request $original  The inbound clawpress/v1 request.
	 * @param string          $new_path  Full route path, e.g. /agent-access/v1/provision.
	 * @return WP_REST_Response
	 */
	public static function proxy_request( WP_REST_Request $original, $new_path ) {
		$proxy = new WP_REST_Request( $original->get_method(), $new_path );

		// Copy JSON params (from Content-Type: application/json bodies).
		$json = $original->get_json_params();
		if ( ! empty( $json ) ) {
			$proxy->set_body( $original->get_body() );
			$proxy->set_header( 'Content-Type', 'application/json' );
		}

		$proxy->set_body_params( $original->get_body_params() );
		$proxy->set_query_params( $original->get_query_params() );

		// Forward all headers so authentication (Application Passwords) carries through.
		foreach ( $original->get_headers() as $name => $values ) {
			$proxy->set_header( $name, implode( ', ', (array) $values ) );
		}

		$result = rest_do_request( $proxy );

		// Ensure we have a WP_REST_Response (rest_do_request can return WP_Error).
		if ( is_wp_error( $result ) ) {
			$result = rest_convert_error_to_response( $result );
		}

		// RFC 8594 deprecation signalling.
		$result->header( 'Deprecation', 'true' );
		$result->header(
			'Link',
			'<' . rest_url( ltrim( $new_path, '/' ) ) . '>; rel="successor-version"'
		);
		$result->header(
			'X-Clawpress-Deprecated',
			'This endpoint is deprecated. Use ' . rest_url( ltrim( $new_path, '/' ) ) . ' instead.'
		);

		return $result;
	}
}
