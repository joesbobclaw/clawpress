<?php
/**
 * Agent Access Migrator
 *
 * One-shot migration from legacy _clawpress_* meta keys to _agent_access_* keys.
 *
 * Three entry points:
 *  1. WP-CLI  — wp agent-access migrate [--delete-legacy]
 *  2. Admin   — Dashboard notice with a "Migrate Now" AJAX button
 *  3. Direct  — Agent_Access_Migrator::migrate( $delete_legacy )
 *
 * @package Agent_Access
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Migrator {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	/**
	 * Register admin notice and AJAX handler; optionally register WP-CLI command.
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'wp_ajax_agent_access_migrate', array( $this, 'ajax_migrate' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'agent-access migrate', array( $this, 'cli_migrate' ) );
		}
	}

	// ── Core migration logic ──────────────────────────────────────────────────

	/**
	 * Run the full migration.
	 *
	 * Copies every _clawpress_* user meta / post meta value to the corresponding
	 * _agent_access_* key. Existing _agent_access_* values are NOT overwritten
	 * (the new key takes precedence once it exists).
	 *
	 * @param bool $delete_legacy Whether to delete the old _clawpress_* keys after
	 *                            copying. Default false (keeps both).
	 * @return array {
	 *     @type int   $users_scanned     Total users examined.
	 *     @type int   $users_migrated    Users that had at least one key migrated.
	 *     @type int   $user_keys_copied  Total user-meta rows copied.
	 *     @type int   $posts_scanned     Total posts examined.
	 *     @type int   $post_keys_copied  Total post-meta rows copied.
	 *     @type bool  $deleted_legacy    Whether old keys were deleted.
	 *     @type array $errors            Any non-fatal errors encountered.
	 * }
	 */
	public function migrate( $delete_legacy = false ) {
		$user_meta_map = Agent_Access_Compat::get_user_meta_map();
		$post_meta_map = Agent_Access_Compat::get_post_meta_map();

		$summary = array(
			'users_scanned'    => 0,
			'users_migrated'   => 0,
			'user_keys_copied' => 0,
			'posts_scanned'    => 0,
			'post_keys_copied' => 0,
			'deleted_legacy'   => $delete_legacy,
			'errors'           => array(),
		);

		// ── User meta ────────────────────────────────────────────────────────

		// Find every user that has at least one legacy key — avoid loading all users.
		$legacy_user_ids = $this->get_users_with_legacy_meta( array_keys( $user_meta_map ) );

		$summary['users_scanned'] = count( $legacy_user_ids );

		foreach ( $legacy_user_ids as $user_id ) {
			$migrated_any = false;

			foreach ( $user_meta_map as $old_key => $new_key ) {
				// get_user_meta() returns [] when key absent — safe "not set" signal.
				$old_values = get_user_meta( $user_id, $old_key );
				if ( empty( $old_values ) ) {
					continue;
				}

				// Skip if the new key already has data; don't overwrite.
				$new_values = get_user_meta( $user_id, $new_key );
				if ( ! empty( $new_values ) ) {
					if ( $delete_legacy ) {
						delete_user_meta( $user_id, $old_key );
					}
					continue;
				}

				// Copy — handle multiple rows for the same key (rare but possible).
				foreach ( $old_values as $value ) {
					$result = add_user_meta( $user_id, $new_key, $value );
					if ( false === $result ) {
						$summary['errors'][] = sprintf(
							'Failed to copy user meta %s → %s for user %d.',
							$old_key,
							$new_key,
							$user_id
						);
						continue;
					}
					$summary['user_keys_copied']++;
					$migrated_any = true;
				}

				if ( $delete_legacy ) {
					delete_user_meta( $user_id, $old_key );
				}
			}

			if ( $migrated_any ) {
				$summary['users_migrated']++;
			}
		}

		// ── Post meta ────────────────────────────────────────────────────────

		$post_ids = $this->get_posts_with_legacy_meta( array_keys( $post_meta_map ) );

		$summary['posts_scanned'] = count( $post_ids );

		foreach ( $post_ids as $post_id ) {
			foreach ( $post_meta_map as $old_key => $new_key ) {
				$old_values = get_post_meta( $post_id, $old_key );
				if ( empty( $old_values ) ) {
					continue;
				}

				$new_values = get_post_meta( $post_id, $new_key );
				if ( ! empty( $new_values ) ) {
					if ( $delete_legacy ) {
						delete_post_meta( $post_id, $old_key );
					}
					continue;
				}

				foreach ( $old_values as $value ) {
					$result = add_post_meta( $post_id, $new_key, $value );
					if ( false === $result ) {
						$summary['errors'][] = sprintf(
							'Failed to copy post meta %s → %s for post %d.',
							$old_key,
							$new_key,
							$post_id
						);
						continue;
					}
					$summary['post_keys_copied']++;
				}

				if ( $delete_legacy ) {
					delete_post_meta( $post_id, $old_key );
				}
			}
		}

		return $summary;
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Return IDs of users that have at least one of the given meta keys.
	 *
	 * @param string[] $keys List of meta key names.
	 * @return int[]
	 */
	private function get_users_with_legacy_meta( array $keys ) {
		if ( empty( $keys ) ) {
			return array();
		}

		$meta_queries = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) {
			$meta_queries[] = array(
				'key'     => $key,
				'compare' => 'EXISTS',
			);
		}

		return get_users( array(
			'meta_query' => $meta_queries,
			'fields'     => 'ID',
			'number'     => -1,
		) );
	}

	/**
	 * Return IDs of posts that have at least one of the given meta keys.
	 *
	 * @param string[] $keys List of meta key names.
	 * @return int[]
	 */
	private function get_posts_with_legacy_meta( array $keys ) {
		if ( empty( $keys ) ) {
			return array();
		}

		$meta_queries = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) {
			$meta_queries[] = array(
				'key'     => $key,
				'compare' => 'EXISTS',
			);
		}

		$query = new WP_Query( array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'meta_query'     => $meta_queries,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		) );

		return $query->posts;
	}

	// ── Admin notice ──────────────────────────────────────────────────────────

	/**
	 * Show a persistent admin notice when any user still has legacy meta.
	 *
	 * Dismissed via a user-specific option; clicking "Migrate Now" triggers AJAX.
	 */
	public function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Allow permanent dismissal.
		$dismissed_key = 'agent_access_migrate_dismissed';
		if ( get_user_meta( get_current_user_id(), $dismissed_key, true ) ) {
			return;
		}

		// Quick check: is there at least one user with legacy meta?
		$has_legacy = ! empty( $this->get_users_with_legacy_meta(
			array_keys( Agent_Access_Compat::get_user_meta_map() )
		) );

		if ( ! $has_legacy ) {
			return;
		}

		$nonce = wp_create_nonce( 'agent_access_migrate' );
		?>
		<div class="notice notice-warning is-dismissible" id="agent-access-migrate-notice">
			<p>
				<strong><?php esc_html_e( 'Agent Access:', 'agent-access' ); ?></strong>
				<?php esc_html_e( 'Legacy ClawPress user data detected. Migrate to the new meta key format to ensure full compatibility.', 'agent-access' ); ?>
			</p>
			<p>
				<button
					type="button"
					class="button button-primary"
					id="agent-access-migrate-btn"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
				>
					<?php esc_html_e( 'Migrate Now', 'agent-access' ); ?>
				</button>
				&nbsp;
				<button
					type="button"
					class="button button-secondary"
					id="agent-access-migrate-dismiss"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
				>
					<?php esc_html_e( 'Dismiss', 'agent-access' ); ?>
				</button>
				<span id="agent-access-migrate-status" style="margin-left:10px;"></span>
			</p>
		</div>
		<script>
		(function () {
			var btn     = document.getElementById( 'agent-access-migrate-btn' );
			var dismiss = document.getElementById( 'agent-access-migrate-dismiss' );
			var status  = document.getElementById( 'agent-access-migrate-status' );
			var notice  = document.getElementById( 'agent-access-migrate-notice' );

			if ( ! btn ) { return; }

			btn.addEventListener( 'click', function () {
				btn.disabled    = true;
				status.textContent = '<?php echo esc_js( __( 'Migrating…', 'agent-access' ) ); ?>';

				var data = new FormData();
				data.append( 'action',       'agent_access_migrate' );
				data.append( 'nonce',        btn.dataset.nonce );
				data.append( 'delete_legacy', '0' );

				fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						if ( json.success ) {
							status.textContent = json.data.message;
							notice.classList.add( 'notice-success' );
							notice.classList.remove( 'notice-warning' );
							btn.remove();
						} else {
							status.textContent = json.data || '<?php echo esc_js( __( 'Migration failed.', 'agent-access' ) ); ?>';
							btn.disabled = false;
						}
					} )
					.catch( function () {
						status.textContent = '<?php echo esc_js( __( 'Request failed. Please try again.', 'agent-access' ) ); ?>';
						btn.disabled = false;
					} );
			} );

			dismiss.addEventListener( 'click', function () {
				dismiss.disabled = true;

				var data = new FormData();
				data.append( 'action', 'agent_access_migrate' );
				data.append( 'nonce',  dismiss.dataset.nonce );
				data.append( 'dismiss', '1' );

				fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
					.finally( function () {
						notice.remove();
					} );
			} );
		}());
		</script>
		<?php
	}

	// ── AJAX handler ─────────────────────────────────────────────────────────

	/**
	 * Handle the admin AJAX request triggered by the "Migrate Now" button.
	 *
	 * Accepts:
	 *  - nonce          (required)
	 *  - delete_legacy  '1' | '0'  (optional, default '0')
	 *  - dismiss        '1'        (optional — dismiss without migrating)
	 */
	public function ajax_migrate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'agent-access' ), 403 );
		}

		check_ajax_referer( 'agent_access_migrate', 'nonce' );

		// Handle dismiss-only action.
		if ( ! empty( $_POST['dismiss'] ) ) {
			update_user_meta( get_current_user_id(), 'agent_access_migrate_dismissed', true );
			wp_send_json_success( array( 'message' => __( 'Notice dismissed.', 'agent-access' ) ) );
		}

		$delete_legacy = isset( $_POST['delete_legacy'] ) && '1' === $_POST['delete_legacy'];

		$summary = $this->migrate( $delete_legacy );

		$message = sprintf(
			/* translators: 1: users migrated, 2: user keys copied, 3: post keys copied */
			__( 'Migration complete. %1$d user(s) migrated (%2$d user-meta rows, %3$d post-meta rows).', 'agent-access' ),
			(int) $summary['users_migrated'],
			(int) $summary['user_keys_copied'],
			(int) $summary['post_keys_copied']
		);

		if ( ! empty( $summary['errors'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of errors */
				__( '%d non-fatal error(s) — check server logs.', 'agent-access' ),
				count( $summary['errors'] )
			);
			foreach ( $summary['errors'] as $err ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Agent Access Migrator] ' . $err );
			}
		}

		wp_send_json_success( array(
			'message' => $message,
			'summary' => $summary,
		) );
	}

	// ── WP-CLI command ────────────────────────────────────────────────────────

	/**
	 * Migrate legacy ClawPress meta keys to Agent Access keys.
	 *
	 * ## OPTIONS
	 *
	 * [--delete-legacy]
	 * : Remove the old _clawpress_* keys after copying. Default: keep both.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-access migrate
	 *     wp agent-access migrate --delete-legacy
	 *     wp agent-access migrate --delete-legacy --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named/flag arguments.
	 */
	public function cli_migrate( $args, $assoc_args ) {
		$delete_legacy = ! empty( $assoc_args['delete-legacy'] );

		if ( $delete_legacy && empty( $assoc_args['yes'] ) ) {
			WP_CLI::confirm(
				'This will DELETE all _clawpress_* meta keys after copying. Are you sure?',
				$assoc_args
			);
		}

		WP_CLI::log( 'Starting migration…' );

		$summary = $this->migrate( $delete_legacy );

		WP_CLI::log( sprintf( 'Users scanned  : %d', $summary['users_scanned'] ) );
		WP_CLI::log( sprintf( 'Users migrated : %d', $summary['users_migrated'] ) );
		WP_CLI::log( sprintf( 'User meta rows : %d copied', $summary['user_keys_copied'] ) );
		WP_CLI::log( sprintf( 'Posts scanned  : %d', $summary['posts_scanned'] ) );
		WP_CLI::log( sprintf( 'Post meta rows : %d copied', $summary['post_keys_copied'] ) );
		WP_CLI::log( sprintf( 'Legacy keys    : %s', $delete_legacy ? 'deleted' : 'kept' ) );

		if ( ! empty( $summary['errors'] ) ) {
			foreach ( $summary['errors'] as $err ) {
				WP_CLI::warning( $err );
			}
		}

		WP_CLI::success( 'Migration complete.' );
	}
}
