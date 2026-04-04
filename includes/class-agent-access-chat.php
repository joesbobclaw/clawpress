<?php
/**
 * Agent Access Chat v3.2.0 — Full-featured messaging system.
 *
 * Provides a Discord/Telegram-replacement chat inside WordPress with:
 *   - `chat_message` CPT for message persistence
 *   - DMs, threads, reactions, file attachments, message editing/deletion
 *   - Long-polling, typing indicators, online presence
 *   - REST API under agent-access/v1/chat/
 *   - Modern dark-theme frontend rendered at /agent-chat/
 *
 * @package Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Chat {

	/**
	 * Singleton instance.
	 *
	 * @var Agent_Access_Chat|null
	 */
	private static $instance = null;

	/**
	 * Return (or create) the singleton instance.
	 *
	 * @return Agent_Access_Chat
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor — use instance(). */
	private function __construct() {}

	/**
	 * Register all hooks.
	 */
	public function init() {
		add_action( 'init',              array( $this, 'register_post_type' ) );
		add_action( 'rest_api_init',     array( $this, 'register_rest_routes' ) );
		add_action( 'init',              array( $this, 'register_rewrite_rule' ) );
		add_filter( 'query_vars',        array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_frontend_route' ) );
	}

	// ── Post type ─────────────────────────────────────────────────────────────

	/**
	 * Register the chat_message CPT and its meta fields.
	 */
	public function register_post_type() {
		register_post_type(
			'chat_message',
			array(
				'labels'          => array(
					'name'          => __( 'Chat Messages', 'agent-access' ),
					'singular_name' => __( 'Chat Message', 'agent-access' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-format-chat',
				'show_in_rest'    => true,
				'rest_base'       => 'chat-messages',
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'capability_type' => 'post',
			)
		);

		$meta_fields = array(
			'channel'       => array( 'type' => 'string',  'default' => 'general' ),
			'sender_type'   => array( 'type' => 'string',  'default' => 'agent' ),
			'agent_id'      => array( 'type' => 'string',  'default' => '' ),
			'sender_name'   => array( 'type' => 'string',  'default' => '' ),
			'reply_to'      => array( 'type' => 'integer', 'default' => 0 ),
			'attachment_id' => array( 'type' => 'integer', 'default' => 0 ),
			'edited'        => array( 'type' => 'integer', 'default' => 0 ),
		);

		foreach ( $meta_fields as $key => $args ) {
			register_post_meta(
				'chat_message',
				$key,
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => $args['type'],
					'default'       => $args['default'],
					'auth_callback' => '__return_true',
				)
			);
		}
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	/**
	 * Register all REST routes under agent-access/v1/chat/.
	 */
	public function register_rest_routes() {

		// ── Send message ──────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_send_message' ),
				'permission_callback' => array( $this, 'permission_edit_posts' ),
				'args'                => array(
					'channel'       => array( 'type' => 'string',  'default' => 'ops' ),
					'sender'        => array( 'type' => 'string',  'required' => true ),
					'sender_type'   => array( 'type' => 'string',  'default' => 'human' ),
					'message'       => array( 'type' => 'string',  'required' => true ),
					'reply_to'      => array( 'type' => 'integer', 'default' => 0 ),
					'attachment_id' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);

		// ── Get messages ──────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/messages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_messages' ),
				'permission_callback' => array( $this, 'permission_read' ),
				'args'                => array(
					'channel'   => array( 'type' => 'string',  'default' => 'ops' ),
					'since'     => array( 'type' => 'string',  'default' => '' ),
					'since_id'  => array( 'type' => 'integer', 'default' => 0 ),
					'limit'     => array( 'type' => 'integer', 'default' => 50 ),
					'search'    => array( 'type' => 'string',  'default' => '' ),
					'thread_id' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);

		// ── Edit message ──────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/messages/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'rest_edit_message' ),
					'permission_callback' => array( $this, 'permission_edit_posts' ),
					'args'                => array(
						'id'      => array( 'type' => 'integer', 'required' => true ),
						'message' => array( 'type' => 'string',  'required' => true ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'rest_delete_message' ),
					'permission_callback' => array( $this, 'permission_edit_posts' ),
					'args'                => array(
						'id' => array( 'type' => 'integer', 'required' => true ),
					),
				),
			)
		);

		// ── Get channels ──────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/channels',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_channels' ),
				'permission_callback' => array( $this, 'permission_read' ),
			)
		);

		// ── Create channel ────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/channels/create',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_create_channel' ),
				'permission_callback' => array( $this, 'permission_edit_posts' ),
				'args'                => array(
					'name'        => array( 'type' => 'string',  'required' => true ),
					'description' => array( 'type' => 'string',  'default' => '' ),
					'private'     => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		// ── DM start ──────────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/dm/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_dm_start' ),
				'permission_callback' => array( $this, 'permission_edit_posts' ),
				'args'                => array(
					'user_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		// ── Unread counts ─────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/unread',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_unread' ),
				'permission_callback' => array( $this, 'permission_read' ),
			)
		);

		// ── Typing indicators ─────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/typing',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_post_typing' ),
					'permission_callback' => array( $this, 'permission_edit_posts' ),
					'args'                => array(
						'channel' => array( 'type' => 'string', 'required' => true ),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_get_typing' ),
					'permission_callback' => array( $this, 'permission_read' ),
					'args'                => array(
						'channel' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		// ── Reactions ─────────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/react',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_add_reaction' ),
					'permission_callback' => array( $this, 'permission_edit_posts' ),
					'args'                => array(
						'message_id' => array( 'type' => 'integer', 'required' => true ),
						'emoji'      => array( 'type' => 'string',  'required' => true ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'rest_remove_reaction' ),
					'permission_callback' => array( $this, 'permission_edit_posts' ),
					'args'                => array(
						'message_id' => array( 'type' => 'integer', 'required' => true ),
						'emoji'      => array( 'type' => 'string',  'required' => true ),
					),
				),
			)
		);

		// ── Long-poll ─────────────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_long_poll' ),
				'permission_callback' => array( $this, 'permission_read' ),
				'args'                => array(
					'channel'  => array( 'type' => 'string',  'required' => true ),
					'since_id' => array( 'type' => 'integer', 'default' => 0 ),
					'timeout'  => array( 'type' => 'integer', 'default' => 25 ),
				),
			)
		);

		// ── Online presence ───────────────────────────────────────────────────
		register_rest_route(
			'agent-access/v1',
			'/chat/presence',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_post_presence' ),
				'permission_callback' => array( $this, 'permission_edit_posts' ),
			)
		);

		register_rest_route(
			'agent-access/v1',
			'/chat/online',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_online' ),
				'permission_callback' => array( $this, 'permission_read' ),
			)
		);
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	/** @return bool */
	public function permission_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/** @return bool */
	public function permission_read() {
		return current_user_can( 'read' );
	}

	/** @return bool */
	public function permission_manage_options() {
		return current_user_can( 'manage_options' );
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Build a message array from a WP_Post object.
	 *
	 * @param WP_Post $post The post object.
	 * @return array
	 */
	private function format_message( WP_Post $post ) {
		$attachment_id  = (int) get_post_meta( $post->ID, 'attachment_id', true );
		$attachment_url = '';
		$attachment_type = '';
		if ( $attachment_id ) {
			$attachment_url  = wp_get_attachment_url( $attachment_id );
			$mime            = get_post_mime_type( $attachment_id );
			$attachment_type = $mime ? $mime : '';
		}

		$reactions = get_post_meta( $post->ID, '_reactions', true );
		if ( ! is_array( $reactions ) ) {
			$reactions = array();
		}

		$reply_to_id = (int) get_post_meta( $post->ID, 'reply_to', true );
		$reply_preview = null;
		if ( $reply_to_id ) {
			$parent = get_post( $reply_to_id );
			if ( $parent ) {
				$reply_preview = array(
					'id'      => $parent->ID,
					'sender'  => get_post_meta( $parent->ID, 'sender_name', true ),
					'snippet' => wp_trim_words( $parent->post_content, 10, '…' ),
				);
			}
		}

		return array(
			'id'              => $post->ID,
			'sender'          => get_post_meta( $post->ID, 'sender_name', true ),
			'sender_type'     => get_post_meta( $post->ID, 'sender_type', true ),
			'sender_id'       => (int) $post->post_author,
			'channel'         => get_post_meta( $post->ID, 'channel', true ),
			'message'         => $post->post_content,
			'timestamp'       => $post->post_date_gmt,
			'edited'          => (bool) get_post_meta( $post->ID, 'edited', true ),
			'reply_to'        => $reply_to_id,
			'reply_preview'   => $reply_preview,
			'attachment_id'   => $attachment_id,
			'attachment_url'  => $attachment_url,
			'attachment_type' => $attachment_type,
			'reactions'       => $reactions,
		);
	}

	/**
	 * Get channel registry from options.
	 *
	 * @return array
	 */
	private function get_channel_registry() {
		$channels = get_option( 'agent_chat_channels', array() );
		if ( ! is_array( $channels ) ) {
			$channels = array();
		}
		// Ensure default channels exist.
		$defaults = array( 'ops', 'general', 'dev' );
		foreach ( $defaults as $slug ) {
			if ( ! isset( $channels[ $slug ] ) ) {
				$channels[ $slug ] = array(
					'name'        => $slug,
					'description' => '',
					'private'     => false,
					'created_at'  => current_time( 'mysql', true ),
				);
			}
		}
		return $channels;
	}

	/**
	 * Save channel registry to options.
	 *
	 * @param array $channels Channel registry.
	 */
	private function save_channel_registry( array $channels ) {
		update_option( 'agent_chat_channels', $channels );
	}

	// ── REST callbacks ────────────────────────────────────────────────────────

	/**
	 * POST /chat/send — insert a new chat message.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_send_message( WP_REST_Request $request ) {
		$channel       = sanitize_text_field( $request['channel'] );
		$sender        = sanitize_text_field( $request['sender'] );
		$sender_type   = sanitize_text_field( $request['sender_type'] );
		$message       = wp_kses_post( $request['message'] );
		$reply_to      = (int) $request['reply_to'];
		$attachment_id = (int) $request['attachment_id'];

		// Ensure channel exists in registry.
		$channels = $this->get_channel_registry();
		if ( ! isset( $channels[ $channel ] ) && 0 !== strpos( $channel, 'dm:' ) ) {
			$channels[ $channel ] = array(
				'name'        => $channel,
				'description' => '',
				'private'     => false,
				'created_at'  => current_time( 'mysql', true ),
			);
			$this->save_channel_registry( $channels );
		}

		$post_id = wp_insert_post( array(
			'post_type'    => 'chat_message',
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id(),
			'post_title'   => $sender,
			'post_content' => $message,
		) );

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response(
				array( 'error' => $post_id->get_error_message() ),
				500
			);
		}

		update_post_meta( $post_id, 'channel',       $channel );
		update_post_meta( $post_id, 'sender_type',   $sender_type );
		update_post_meta( $post_id, 'sender_name',   $sender );
		update_post_meta( $post_id, 'agent_id',      $sender );
		update_post_meta( $post_id, 'reply_to',      $reply_to );
		update_post_meta( $post_id, 'attachment_id', $attachment_id );
		update_post_meta( $post_id, 'edited',        0 );
		update_post_meta( $post_id, '_reactions',    array() );

		$post = get_post( $post_id );

		return new WP_REST_Response( $this->format_message( $post ), 201 );
	}

	/**
	 * GET /chat/messages — fetch messages for a channel or thread.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_get_messages( WP_REST_Request $request ) {
		$channel   = sanitize_text_field( $request['channel'] );
		$since     = sanitize_text_field( $request['since'] );
		$since_id  = (int) $request['since_id'];
		$limit     = min( (int) $request['limit'], 200 );
		$search    = sanitize_text_field( $request['search'] );
		$thread_id = (int) $request['thread_id'];

		$meta_query = array(
			array(
				'key'   => 'channel',
				'value' => $channel,
			),
		);

		if ( $thread_id ) {
			// Fetching thread replies.
			$meta_query[] = array(
				'key'   => 'reply_to',
				'value' => $thread_id,
				'type'  => 'NUMERIC',
			);
		} else {
			// Top-level messages only (no replies).
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => 'reply_to',
					'value'   => '0',
					'compare' => '=',
				),
				array(
					'key'     => 'reply_to',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$args = array(
			'post_type'      => 'chat_message',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => $meta_query,
		);

		if ( $since ) {
			$args['date_query'] = array(
				array(
					'after'     => $since,
					'column'    => 'post_date_gmt',
					'inclusive' => false,
				),
			);
		}

		if ( $since_id ) {
			// Filter by ID greater than since_id.
			add_filter( 'posts_where', array( $this, '_filter_since_id' ) );
			$GLOBALS['_agent_chat_since_id'] = $since_id;
		}

		if ( $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );

		if ( $since_id ) {
			remove_filter( 'posts_where', array( $this, '_filter_since_id' ) );
			unset( $GLOBALS['_agent_chat_since_id'] );
		}

		$messages = array();
		foreach ( $query->posts as $post ) {
			$messages[] = $this->format_message( $post );
		}

		// Update last-read for this channel.
		$user_id = get_current_user_id();
		if ( $user_id && $messages ) {
			$meta_key = '_agent_chat_last_read_' . md5( $channel );
			update_user_meta( $user_id, $meta_key, current_time( 'mysql', true ) );
		}

		return new WP_REST_Response(
			array(
				'channel'  => $channel,
				'count'    => count( $messages ),
				'messages' => $messages,
			)
		);
	}

	/**
	 * posts_where filter to add since_id constraint.
	 *
	 * @param string $where SQL WHERE clause.
	 * @return string
	 */
	public function _filter_since_id( $where ) {
		global $wpdb;
		$since_id = isset( $GLOBALS['_agent_chat_since_id'] ) ? (int) $GLOBALS['_agent_chat_since_id'] : 0;
		if ( $since_id ) {
			$where .= $wpdb->prepare( ' AND ID > %d', $since_id );
		}
		return $where;
	}

	/**
	 * PUT /chat/messages/{id} — edit a message (sender only, within 15 min).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_edit_message( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'chat_message' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'Message not found.' ), 404 );
		}

		$user_id = get_current_user_id();

		// Only sender can edit.
		if ( (int) $post->post_author !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'You can only edit your own messages.' ), 403 );
		}

		// Within 15 minutes.
		$age = time() - strtotime( $post->post_date_gmt . ' UTC' );
		if ( $age > 900 ) {
			return new WP_REST_Response( array( 'error' => 'Messages can only be edited within 15 minutes.' ), 403 );
		}

		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => wp_kses_post( $request['message'] ),
		) );
		update_post_meta( $post_id, 'edited', 1 );

		$post = get_post( $post_id );
		return new WP_REST_Response( $this->format_message( $post ), 200 );
	}

	/**
	 * DELETE /chat/messages/{id} — soft-delete (trash) a message.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_delete_message( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'chat_message' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'Message not found.' ), 404 );
		}

		$user_id   = get_current_user_id();
		$is_admin  = current_user_can( 'manage_options' );
		$is_sender = (int) $post->post_author === $user_id;

		if ( ! $is_admin && ! $is_sender ) {
			return new WP_REST_Response( array( 'error' => 'You can only delete your own messages.' ), 403 );
		}

		wp_trash_post( $post_id );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $post_id ), 200 );
	}

	/**
	 * GET /chat/channels — list channels with metadata.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_get_channels( WP_REST_Request $request ) {
		global $wpdb;

		$registry = $this->get_channel_registry();
		$channels = array();

		foreach ( $registry as $slug => $info ) {
			// Get last message time for this channel.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$last_msg = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.post_date_gmt FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					 WHERE p.post_type = 'chat_message'
					 AND p.post_status = 'publish'
					 AND pm.meta_key = 'channel'
					 AND pm.meta_value = %s
					 ORDER BY p.post_date_gmt DESC
					 LIMIT 1",
					$slug
				)
			);

			// Count distinct users who posted.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$member_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.post_author) FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					 WHERE p.post_type = 'chat_message'
					 AND p.post_status = 'publish'
					 AND pm.meta_key = 'channel'
					 AND pm.meta_value = %s",
					$slug
				)
			);

			$channels[] = array(
				'name'            => $slug,
				'description'     => isset( $info['description'] ) ? $info['description'] : '',
				'private'         => isset( $info['private'] ) ? (bool) $info['private'] : false,
				'member_count'    => (int) $member_count,
				'last_message_at' => $last_msg ? $last_msg : null,
			);
		}

		return new WP_REST_Response( array( 'channels' => $channels ) );
	}

	/**
	 * POST /chat/channels/create — create a new channel.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_create_channel( WP_REST_Request $request ) {
		$name        = sanitize_key( $request['name'] );
		$description = sanitize_text_field( $request['description'] );
		$private     = (bool) $request['private'];

		if ( ! $name ) {
			return new WP_REST_Response( array( 'error' => 'Channel name is required.' ), 400 );
		}

		$channels = $this->get_channel_registry();

		if ( isset( $channels[ $name ] ) ) {
			return new WP_REST_Response( array( 'error' => 'Channel already exists.', 'channel' => $name ), 409 );
		}

		$channels[ $name ] = array(
			'name'        => $name,
			'description' => $description,
			'private'     => $private,
			'created_at'  => current_time( 'mysql', true ),
		);

		$this->save_channel_registry( $channels );

		return new WP_REST_Response( array( 'channel' => $name, 'created' => true ), 201 );
	}

	/**
	 * POST /chat/dm/start — start or return a DM channel between two users.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_dm_start( WP_REST_Request $request ) {
		$current_user_id = get_current_user_id();
		$other_user_id   = (int) $request['user_id'];

		if ( ! get_user_by( 'id', $other_user_id ) ) {
			return new WP_REST_Response( array( 'error' => 'User not found.' ), 404 );
		}

		$ids     = array( $current_user_id, $other_user_id );
		sort( $ids );
		$channel = 'dm:' . implode( ':', $ids );

		return new WP_REST_Response( array( 'channel' => $channel ), 200 );
	}

	/**
	 * GET /chat/unread — return unread counts per channel for current user.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_get_unread( WP_REST_Request $request ) {
		global $wpdb;

		$user_id  = get_current_user_id();
		$registry = $this->get_channel_registry();
		$unread   = array();

		foreach ( $registry as $slug => $info ) {
			$meta_key  = '_agent_chat_last_read_' . md5( $slug );
			$last_read = get_user_meta( $user_id, $meta_key, true );

			if ( $last_read ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
						 WHERE p.post_type = 'chat_message'
						 AND p.post_status = 'publish'
						 AND p.post_author != %d
						 AND p.post_date_gmt > %s
						 AND pm.meta_key = 'channel'
						 AND pm.meta_value = %s",
						$user_id,
						$last_read,
						$slug
					)
				);
				$unread[ $slug ] = (int) $count;
			} else {
				// Never read — count all messages from others.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
						 WHERE p.post_type = 'chat_message'
						 AND p.post_status = 'publish'
						 AND p.post_author != %d
						 AND pm.meta_key = 'channel'
						 AND pm.meta_value = %s",
						$user_id,
						$slug
					)
				);
				$unread[ $slug ] = (int) $count;
			}
		}

		return new WP_REST_Response( array( 'unread' => $unread ) );
	}

	/**
	 * POST /chat/typing — record that current user is typing.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_post_typing( WP_REST_Request $request ) {
		$channel = sanitize_text_field( $request['channel'] );
		$user_id = get_current_user_id();
		$key     = 'agent_chat_typing_' . md5( $channel ) . '_' . $user_id;

		$user = get_user_by( 'id', $user_id );
		set_transient( $key, $user ? $user->display_name : 'Someone', 5 );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /chat/typing — get users currently typing in a channel.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_get_typing( WP_REST_Request $request ) {
		$channel  = sanitize_text_field( $request['channel'] );
		$user_id  = get_current_user_id();
		$prefix   = 'agent_chat_typing_' . md5( $channel ) . '_';
		$typing   = array();

		// Check transients for all users — iterate site users (cap 100).
		$users = get_users( array( 'number' => 100, 'fields' => array( 'ID', 'display_name' ) ) );
		foreach ( $users as $u ) {
			if ( (int) $u->ID === $user_id ) {
				continue; // Don't show self as typing.
			}
			$val = get_transient( $prefix . $u->ID );
			if ( $val ) {
				$typing[] = array(
					'user_id'      => (int) $u->ID,
					'display_name' => $val,
				);
			}
		}

		return new WP_REST_Response( array( 'typing' => $typing ) );
	}

	/**
	 * POST /chat/react — add a reaction to a message.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_add_reaction( WP_REST_Request $request ) {
		$message_id = (int) $request['message_id'];
		$emoji      = sanitize_text_field( $request['emoji'] );
		$user_id    = get_current_user_id();

		$post = get_post( $message_id );
		if ( ! $post || 'chat_message' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'Message not found.' ), 404 );
		}

		$reactions = get_post_meta( $message_id, '_reactions', true );
		if ( ! is_array( $reactions ) ) {
			$reactions = array();
		}

		if ( ! isset( $reactions[ $emoji ] ) ) {
			$reactions[ $emoji ] = array();
		}

		if ( ! in_array( $user_id, $reactions[ $emoji ], true ) ) {
			$reactions[ $emoji ][] = $user_id;
		}

		update_post_meta( $message_id, '_reactions', $reactions );

		return new WP_REST_Response( array( 'reactions' => $reactions ), 200 );
	}

	/**
	 * DELETE /chat/react — remove a reaction from a message.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_remove_reaction( WP_REST_Request $request ) {
		$message_id = (int) $request['message_id'];
		$emoji      = sanitize_text_field( $request['emoji'] );
		$user_id    = get_current_user_id();

		$post = get_post( $message_id );
		if ( ! $post || 'chat_message' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'Message not found.' ), 404 );
		}

		$reactions = get_post_meta( $message_id, '_reactions', true );
		if ( ! is_array( $reactions ) ) {
			$reactions = array();
		}

		if ( isset( $reactions[ $emoji ] ) ) {
			$reactions[ $emoji ] = array_values( array_diff( $reactions[ $emoji ], array( $user_id ) ) );
			if ( empty( $reactions[ $emoji ] ) ) {
				unset( $reactions[ $emoji ] );
			}
		}

		update_post_meta( $message_id, '_reactions', $reactions );

		return new WP_REST_Response( array( 'reactions' => $reactions ), 200 );
	}

	/**
	 * GET /chat/poll — long-poll for new messages.
	 *
	 * Holds connection open up to $timeout seconds, returning as soon as
	 * a new message arrives. Falls back gracefully.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_long_poll( WP_REST_Request $request ) {
		$channel  = sanitize_text_field( $request['channel'] );
		$since_id = (int) $request['since_id'];
		$timeout  = min( (int) $request['timeout'], 30 );

		// Extend PHP execution time for long-poll.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.set_time_limit_set_time_limit
		set_time_limit( $timeout + 10 );

		$start    = time();
		$messages = array();

		do {
			$messages = $this->_fetch_since_id( $channel, $since_id );
			if ( ! empty( $messages ) ) {
				break;
			}
			if ( time() - $start >= $timeout ) {
				break;
			}
			sleep( 1 );
			wp_cache_flush();
		} while ( true );

		return new WP_REST_Response(
			array(
				'channel'  => $channel,
				'count'    => count( $messages ),
				'messages' => $messages,
			)
		);
	}

	/**
	 * Fetch messages newer than a given ID in a channel.
	 *
	 * @param string $channel  Channel slug.
	 * @param int    $since_id Return messages with ID > this.
	 * @return array
	 */
	private function _fetch_since_id( $channel, $since_id ) {
		$GLOBALS['_agent_chat_since_id'] = (int) $since_id;
		add_filter( 'posts_where', array( $this, '_filter_since_id' ) );

		$query = new WP_Query( array(
			'post_type'      => 'chat_message',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'   => 'channel',
					'value' => $channel,
				),
				array(
					'relation' => 'OR',
					array(
						'key'   => 'reply_to',
						'value' => '0',
					),
					array(
						'key'     => 'reply_to',
						'compare' => 'NOT EXISTS',
					),
				),
			),
		) );

		remove_filter( 'posts_where', array( $this, '_filter_since_id' ) );
		unset( $GLOBALS['_agent_chat_since_id'] );

		$messages = array();
		foreach ( $query->posts as $post ) {
			$messages[] = $this->format_message( $post );
		}

		return $messages;
	}

	/**
	 * POST /chat/presence — mark current user as online (60s TTL).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_post_presence( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$user    = get_user_by( 'id', $user_id );
		$key     = 'agent_chat_online_' . $user_id;

		set_transient( $key, array(
			'user_id'      => $user_id,
			'display_name' => $user ? $user->display_name : 'Unknown',
			'avatar'       => get_avatar_url( $user_id, array( 'size' => 40 ) ),
		), 60 );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /chat/online — return list of online users.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_get_online( WP_REST_Request $request ) {
		$users  = get_users( array( 'number' => 200, 'fields' => array( 'ID' ) ) );
		$online = array();

		foreach ( $users as $u ) {
			$data = get_transient( 'agent_chat_online_' . $u->ID );
			if ( $data ) {
				$online[] = $data;
			}
		}

		return new WP_REST_Response( array( 'online' => $online ) );
	}

	// ── Frontend page ─────────────────────────────────────────────────────────

	/**
	 * Register the /agent-chat/ rewrite rule.
	 */
	public function register_rewrite_rule() {
		add_rewrite_rule( '^agent-chat/?$', 'index.php?agent_chat_page=1', 'top' );
	}

	/**
	 * Expose agent_chat_page as a custom query variable.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'agent_chat_page';
		return $vars;
	}

	/**
	 * Intercept requests to /agent-chat/ and render the frontend.
	 */
	public function handle_frontend_route() {
		if ( ! get_query_var( 'agent_chat_page' ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access Agent Chat.', 'agent-access' ),
				esc_html__( 'Access Denied', 'agent-access' ),
				array( 'response' => 403 )
			);
		}

		$this->render_frontend();
		exit;
	}

	/**
	 * Render the full Agent Chat frontend page — modern dark-theme messaging app.
	 */
	private function render_frontend() {
		$user         = wp_get_current_user();
		$display_name = esc_js( $user->display_name );
		$user_id      = (int) $user->ID;
		$nonce        = wp_create_nonce( 'wp_rest' );
		$rest_url     = esc_url_raw( rest_url( 'agent-access/v1/chat/' ) );
		$avatar_url   = esc_url( get_avatar_url( $user_id, array( 'size' => 40 ) ) );
		$is_admin     = current_user_can( 'manage_options' ) ? 'true' : 'false';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php esc_html_e( 'Agent Chat', 'agent-access' ); ?></title>
<style>
/* ── Reset & variables ────────────────────────────────────────────────────── */
:root {
	--bg:        #0f0f1a;
	--sidebar:   #141425;
	--surface:   #1a1a2e;
	--surface2:  #22223a;
	--accent:    #6c5ce7;
	--accent-dk: #5a4dd0;
	--human:     #4ecca3;
	--agent:     #7b68ee;
	--danger:    #e55;
	--text:      #e8e8f0;
	--text-muted:#7a7a9d;
	--border:    #2a2a42;
	--scrollbar: #3a3a5a;
}
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html, body { height:100%; overflow:hidden; }
body {
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
	background: var(--bg);
	color: var(--text);
	font-size: 14px;
	line-height: 1.5;
}

/* ── Layout ───────────────────────────────────────────────────────────────── */
#app { display:flex; height:100vh; overflow:hidden; }

/* Sidebar */
#sidebar {
	width: 280px;
	min-width: 280px;
	background: var(--sidebar);
	border-right: 1px solid var(--border);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	transition: transform .25s ease;
	z-index: 50;
}
#sidebar.collapsed { transform: translateX(-100%); position:absolute; height:100%; }

.sidebar-user {
	padding: 16px;
	border-bottom: 1px solid var(--border);
	display: flex;
	align-items: center;
	gap: 10px;
}
.sidebar-user img {
	width: 36px; height: 36px;
	border-radius: 50%;
	border: 2px solid var(--accent);
	flex-shrink: 0;
}
.sidebar-user .user-info { flex:1; min-width:0; }
.sidebar-user .user-name { font-weight:600; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sidebar-user .user-status { font-size:11px; color:var(--human); }

.sidebar-section { padding:8px 0; }
.sidebar-section-header {
	padding: 6px 16px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: .08em;
	text-transform: uppercase;
	color: var(--text-muted);
	display: flex;
	align-items: center;
	justify-content: space-between;
}
.sidebar-section-header button {
	background: none;
	border: none;
	color: var(--text-muted);
	cursor: pointer;
	font-size: 16px;
	line-height: 1;
	padding: 0 2px;
	border-radius: 4px;
	transition: color .15s, background .15s;
}
.sidebar-section-header button:hover { color:var(--accent); background:var(--surface); }

.sidebar-channels { flex:1; overflow-y:auto; }
.sidebar-channels::-webkit-scrollbar { width:4px; }
.sidebar-channels::-webkit-scrollbar-track { background:transparent; }
.sidebar-channels::-webkit-scrollbar-thumb { background:var(--scrollbar); border-radius:2px; }

.channel-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 7px 16px;
	cursor: pointer;
	border-radius: 6px;
	margin: 1px 8px;
	transition: background .12s;
	color: var(--text-muted);
	font-size: 14px;
	user-select: none;
}
.channel-item:hover { background:var(--surface); color:var(--text); }
.channel-item.active { background:var(--surface2); color:var(--text); }
.channel-item .ch-icon { font-size:13px; flex-shrink:0; }
.channel-item .ch-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.channel-item .ch-badge {
	background: var(--accent);
	color: #fff;
	font-size: 10px;
	font-weight: 700;
	min-width: 18px;
	height: 18px;
	border-radius: 9px;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0 5px;
}
.online-dot {
	width: 8px; height: 8px;
	border-radius: 50%;
	background: var(--human);
	flex-shrink: 0;
}
.offline-dot { background: var(--text-muted); }

/* Main area */
#main {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	min-width: 0;
}

/* Channel header */
#channel-header {
	background: var(--surface);
	border-bottom: 1px solid var(--border);
	padding: 12px 20px;
	display: flex;
	align-items: center;
	gap: 12px;
	flex-shrink: 0;
	min-height: 56px;
}
#hamburger {
	display: none;
	background: none;
	border: none;
	color: var(--text);
	font-size: 20px;
	cursor: pointer;
	padding: 4px;
	line-height: 1;
}
#channel-name { font-weight: 700; font-size: 16px; }
#channel-desc { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
#channel-members { font-size: 12px; color: var(--text-muted); margin-left: 4px; }
.header-search {
	margin-left: auto;
	background: var(--surface2);
	border: 1px solid var(--border);
	color: var(--text);
	padding: 6px 12px;
	border-radius: 6px;
	font-size: 13px;
	width: 200px;
	outline: none;
}
.header-search::placeholder { color: var(--text-muted); }
.header-search:focus { border-color: var(--accent); }

/* Content area = messages + optional thread panel */
#content-area { flex:1; display:flex; overflow:hidden; }

/* Messages */
#messages-area {
	flex: 1;
	overflow-y: auto;
	padding: 16px 20px;
	display: flex;
	flex-direction: column;
	scroll-behavior: smooth;
}
#messages-area::-webkit-scrollbar { width: 6px; }
#messages-area::-webkit-scrollbar-track { background:transparent; }
#messages-area::-webkit-scrollbar-thumb { background:var(--scrollbar); border-radius:3px; }

.date-divider {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 16px 0 8px;
	color: var(--text-muted);
	font-size: 12px;
	font-weight: 600;
}
.date-divider::before, .date-divider::after {
	content: '';
	flex: 1;
	height: 1px;
	background: var(--border);
}

/* Message rows */
.msg-row {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	margin-bottom: 2px;
	padding: 3px 4px;
	border-radius: 8px;
	position: relative;
	animation: msgIn .18s ease;
	transition: background .1s;
}
.msg-row:hover { background: var(--surface); }
@keyframes msgIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }

.msg-row.continuation { margin-top: -2px; }
.msg-row.continuation .msg-avatar { visibility: hidden; }
.msg-row.continuation .msg-header { display: none; }

.msg-avatar {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	flex-shrink: 0;
	object-fit: cover;
	background: var(--surface2);
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: 700;
	font-size: 14px;
	cursor: pointer;
}
.msg-avatar.human { background: var(--human); color: #0f0f1a; }
.msg-avatar.agent { background: var(--agent); color: #0f0f1a; }

.msg-body { flex:1; min-width:0; }
.msg-header {
	display: flex;
	align-items: baseline;
	gap: 8px;
	margin-bottom: 3px;
}
.msg-sender { font-weight: 700; font-size: 14px; }
.msg-sender.human { color: var(--human); }
.msg-sender.agent { color: var(--agent); }
.msg-time { font-size: 11px; color: var(--text-muted); }
.msg-edited { font-size: 11px; color: var(--text-muted); font-style: italic; }

.reply-preview {
	background: var(--surface2);
	border-left: 3px solid var(--accent);
	border-radius: 0 6px 6px 0;
	padding: 4px 10px;
	margin-bottom: 5px;
	font-size: 12px;
	color: var(--text-muted);
	cursor: pointer;
	max-width: 500px;
}
.reply-preview strong { color: var(--text); }

.msg-content {
	font-size: 14px;
	line-height: 1.55;
	white-space: pre-wrap;
	word-break: break-word;
}

.msg-attachment {
	margin-top: 6px;
	max-width: 360px;
}
.msg-attachment img {
	max-width: 100%;
	border-radius: 8px;
	border: 1px solid var(--border);
	display: block;
}
.msg-attachment a {
	color: var(--accent);
	font-size: 13px;
	display: flex;
	align-items: center;
	gap: 6px;
}

.msg-reactions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 5px;
}
.reaction-badge {
	background: var(--surface2);
	border: 1px solid var(--border);
	border-radius: 12px;
	padding: 1px 7px;
	font-size: 13px;
	cursor: pointer;
	transition: border-color .12s, background .12s;
	display: flex;
	align-items: center;
	gap: 4px;
	user-select: none;
}
.reaction-badge:hover { border-color: var(--accent); background: var(--surface); }
.reaction-badge.mine { border-color: var(--accent); background: rgba(108,92,231,.15); }
.reaction-badge .r-count { font-size: 12px; color: var(--text-muted); }

/* Hover actions */
.msg-actions {
	position: absolute;
	right: 8px;
	top: -14px;
	background: var(--surface2);
	border: 1px solid var(--border);
	border-radius: 8px;
	display: none;
	gap: 2px;
	padding: 3px;
	z-index: 10;
	box-shadow: 0 2px 8px rgba(0,0,0,.4);
}
.msg-row:hover .msg-actions { display: flex; }
.msg-action-btn {
	background: none;
	border: none;
	cursor: pointer;
	font-size: 15px;
	padding: 4px 6px;
	border-radius: 5px;
	color: var(--text-muted);
	transition: color .12s, background .12s;
	line-height: 1;
}
.msg-action-btn:hover { color: var(--text); background: var(--surface); }
.msg-action-btn.danger:hover { color: var(--danger); }

/* Emoji picker */
#emoji-picker {
	position: absolute;
	bottom: 80px;
	right: 20px;
	background: var(--surface2);
	border: 1px solid var(--border);
	border-radius: 10px;
	padding: 10px;
	display: none;
	flex-wrap: wrap;
	gap: 4px;
	width: 240px;
	max-height: 200px;
	overflow-y: auto;
	z-index: 100;
	box-shadow: 0 4px 20px rgba(0,0,0,.5);
}
#emoji-picker.open { display: flex; }
#emoji-picker span {
	font-size: 20px;
	cursor: pointer;
	padding: 3px;
	border-radius: 4px;
	transition: background .1s;
	line-height: 1;
}
#emoji-picker span:hover { background: var(--surface); }

/* Compose */
#compose-area {
	background: var(--surface);
	border-top: 1px solid var(--border);
	padding: 10px 16px 14px;
	flex-shrink: 0;
}
#typing-bar {
	font-size: 12px;
	color: var(--text-muted);
	min-height: 18px;
	margin-bottom: 6px;
	font-style: italic;
}
.reply-to-bar {
	background: var(--surface2);
	border-left: 3px solid var(--accent);
	border-radius: 0 6px 6px 0;
	padding: 5px 10px;
	margin-bottom: 8px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 12px;
	color: var(--text-muted);
}
.reply-to-bar strong { color: var(--text); }
.reply-to-bar button { background:none; border:none; cursor:pointer; color:var(--text-muted); font-size:16px; line-height:1; }
.reply-to-bar button:hover { color:var(--danger); }
#compose-row { display:flex; align-items:flex-end; gap:8px; }
#msg-input {
	flex: 1;
	background: var(--surface2);
	border: 1px solid var(--border);
	color: var(--text);
	padding: 10px 14px;
	border-radius: 10px;
	font-size: 14px;
	font-family: inherit;
	resize: none;
	min-height: 44px;
	max-height: 130px;
	outline: none;
	transition: border-color .15s;
	line-height: 1.5;
}
#msg-input:focus { border-color: var(--accent); }
#msg-input::placeholder { color: var(--text-muted); }
.compose-btn {
	background: none;
	border: 1px solid var(--border);
	color: var(--text-muted);
	width: 40px;
	height: 40px;
	border-radius: 8px;
	cursor: pointer;
	font-size: 18px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: color .12s, border-color .12s, background .12s;
	flex-shrink: 0;
}
.compose-btn:hover { color: var(--text); border-color: var(--accent); background: var(--surface2); }
#send-btn {
	background: var(--accent);
	border: none;
	color: #fff;
	width: 44px;
	height: 44px;
	border-radius: 10px;
	cursor: pointer;
	font-size: 18px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background .15s, opacity .15s;
	flex-shrink: 0;
}
#send-btn:hover { background: var(--accent-dk); }
#send-btn:disabled { opacity: .4; cursor: not-allowed; }
/* File input hidden */
#file-input { display: none; }

/* Drop overlay */
#drop-overlay {
	display: none;
	position: fixed;
	inset: 0;
	background: rgba(108,92,231,.15);
	border: 3px dashed var(--accent);
	border-radius: 8px;
	z-index: 200;
	align-items: center;
	justify-content: center;
	font-size: 20px;
	color: var(--accent);
	pointer-events: none;
}
#drop-overlay.active { display: flex; }

/* Thread panel */
#thread-panel {
	width: 320px;
	min-width: 320px;
	background: var(--sidebar);
	border-left: 1px solid var(--border);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	transform: translateX(100%);
	transition: transform .25s ease;
	position: absolute;
	right: 0;
	top: 0;
	height: 100%;
	z-index: 30;
}
#thread-panel.open { transform: none; position: relative; }
#thread-header {
	padding: 14px 16px;
	border-bottom: 1px solid var(--border);
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-shrink: 0;
}
#thread-header h3 { font-size: 15px; font-weight: 700; }
#close-thread {
	background: none;
	border: none;
	color: var(--text-muted);
	cursor: pointer;
	font-size: 20px;
	line-height: 1;
	padding: 2px;
	border-radius: 4px;
}
#close-thread:hover { color: var(--danger); }
#thread-messages {
	flex: 1;
	overflow-y: auto;
	padding: 12px;
}
#thread-messages::-webkit-scrollbar { width: 4px; }
#thread-messages::-webkit-scrollbar-thumb { background: var(--scrollbar); border-radius: 2px; }
#thread-compose {
	padding: 10px 12px;
	border-top: 1px solid var(--border);
	display: flex;
	gap: 8px;
}
#thread-input {
	flex: 1;
	background: var(--surface2);
	border: 1px solid var(--border);
	color: var(--text);
	padding: 8px 12px;
	border-radius: 8px;
	font-size: 13px;
	font-family: inherit;
	resize: none;
	min-height: 38px;
	max-height: 100px;
	outline: none;
}
#thread-input:focus { border-color: var(--accent); }
#thread-send {
	background: var(--accent);
	border: none;
	color: #fff;
	width: 38px;
	border-radius: 8px;
	cursor: pointer;
	font-size: 16px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

/* Modal */
#modal-overlay {
	display: none;
	position: fixed;
	inset: 0;
	background: rgba(0,0,0,.7);
	z-index: 300;
	align-items: center;
	justify-content: center;
}
#modal-overlay.open { display: flex; }
.modal {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: 12px;
	padding: 24px;
	width: 360px;
	max-width: 90vw;
}
.modal h3 { font-size: 16px; font-weight: 700; margin-bottom: 16px; }
.modal label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px; margin-top: 12px; }
.modal input, .modal textarea {
	width: 100%;
	background: var(--surface2);
	border: 1px solid var(--border);
	color: var(--text);
	padding: 8px 12px;
	border-radius: 6px;
	font-size: 14px;
	font-family: inherit;
	outline: none;
}
.modal input:focus, .modal textarea:focus { border-color: var(--accent); }
.modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; }
.modal-actions button {
	padding: 8px 18px;
	border-radius: 7px;
	border: none;
	cursor: pointer;
	font-size: 14px;
	font-weight: 600;
}
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-dk); }
.btn-cancel { background: var(--surface2); color: var(--text-muted); }
.btn-cancel:hover { color: var(--text); }
.modal .checkbox-row { display:flex; align-items:center; gap:8px; margin-top:12px; font-size:14px; }
.modal .checkbox-row input[type=checkbox] { width:auto; }

/* Status bar */
#status-bar {
	font-size: 11px;
	color: var(--text-muted);
	text-align: center;
	padding: 3px;
	background: var(--bg);
}

/* Empty state */
.empty-state {
	text-align: center;
	color: var(--text-muted);
	padding: 60px 20px;
	font-size: 15px;
}
.empty-state .es-icon { font-size: 40px; margin-bottom: 12px; }
.loading { color: var(--text-muted); font-size: 13px; text-align: center; padding: 20px; }

/* Notification toast */
#toast {
	position: fixed;
	bottom: 24px;
	left: 50%;
	transform: translateX(-50%) translateY(20px);
	background: var(--surface2);
	border: 1px solid var(--border);
	border-radius: 8px;
	padding: 10px 18px;
	font-size: 13px;
	z-index: 500;
	opacity: 0;
	transition: opacity .25s, transform .25s;
	pointer-events: none;
}
#toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* Edit input inline */
.edit-input {
	width: 100%;
	background: var(--surface2);
	border: 1px solid var(--accent);
	color: var(--text);
	padding: 6px 10px;
	border-radius: 6px;
	font-size: 14px;
	font-family: inherit;
	outline: none;
	margin-top: 2px;
}
.edit-actions { display:flex; gap:6px; margin-top:6px; }
.edit-actions button { padding:4px 12px; border-radius:5px; border:none; cursor:pointer; font-size:12px; font-weight:600; }
.btn-save { background: var(--accent); color:#fff; }
.btn-discard { background: var(--surface); color:var(--text-muted); }

/* Responsive */
@media (max-width: 768px) {
	#sidebar { position:absolute; height:100%; }
	#sidebar:not(.collapsed) { box-shadow: 4px 0 20px rgba(0,0,0,.5); }
	#hamburger { display: flex !important; }
	#thread-panel.open { position:absolute; right:0; top:0; height:100%; }
}
@media (max-width: 480px) {
	.header-search { width: 140px; }
}
</style>
</head>
<body>
<div id="app">

<!-- Sidebar -->
<div id="sidebar">
	<div class="sidebar-user">
		<img id="user-avatar" src="<?php echo $avatar_url; ?>" alt="">
		<div class="user-info">
			<div class="user-name"><?php echo esc_html( $user->display_name ); ?></div>
			<div class="user-status">● <?php esc_html_e( 'Online', 'agent-access' ); ?></div>
		</div>
	</div>

	<div class="sidebar-channels" id="sidebar-scroll">

		<div class="sidebar-section">
			<div class="sidebar-section-header">
				<?php esc_html_e( 'Channels', 'agent-access' ); ?>
				<button id="new-channel-btn" title="<?php esc_attr_e( 'New Channel', 'agent-access' ); ?>">+</button>
			</div>
			<div id="channel-list">
				<div class="loading">Loading…</div>
			</div>
		</div>

		<div class="sidebar-section">
			<div class="sidebar-section-header">
				<?php esc_html_e( 'Direct Messages', 'agent-access' ); ?>
				<button id="new-dm-btn" title="<?php esc_attr_e( 'New DM', 'agent-access' ); ?>">+</button>
			</div>
			<div id="dm-list"></div>
		</div>

	</div><!-- /.sidebar-channels -->
</div><!-- /#sidebar -->

<!-- Main -->
<div id="main">

	<div id="channel-header">
		<button id="hamburger" aria-label="<?php esc_attr_e( 'Toggle sidebar', 'agent-access' ); ?>">☰</button>
		<div>
			<div id="channel-name">#ops</div>
			<div id="channel-desc"></div>
		</div>
		<span id="channel-members"></span>
		<input type="text" class="header-search" id="search-box" placeholder="<?php esc_attr_e( 'Search…', 'agent-access' ); ?>">
	</div>

	<div id="content-area">

		<div id="messages-area"></div>

		<!-- Thread panel (hidden by default) -->
		<div id="thread-panel">
			<div id="thread-header">
				<h3><?php esc_html_e( 'Thread', 'agent-access' ); ?></h3>
				<button id="close-thread">✕</button>
			</div>
			<div id="thread-messages"></div>
			<div id="thread-compose">
				<textarea id="thread-input" placeholder="<?php esc_attr_e( 'Reply in thread…', 'agent-access' ); ?>" rows="1"></textarea>
				<button id="thread-send">↑</button>
			</div>
		</div>

	</div><!-- /#content-area -->

	<!-- Compose -->
	<div id="compose-area">
		<div id="typing-bar"></div>
		<div class="reply-to-bar" id="reply-to-bar" style="display:none;">
			<span><?php esc_html_e( 'Replying to', 'agent-access' ); ?> <strong id="reply-to-name"></strong>: <em id="reply-to-snippet"></em></span>
			<button id="cancel-reply">✕</button>
		</div>
		<div id="compose-row">
			<input type="file" id="file-input" multiple>
			<button class="compose-btn" id="attach-btn" title="<?php esc_attr_e( 'Attach file', 'agent-access' ); ?>">📎</button>
			<textarea id="msg-input" placeholder="<?php esc_attr_e( 'Message… (Enter to send, Shift+Enter for newline)', 'agent-access' ); ?>" rows="1"></textarea>
			<button class="compose-btn" id="emoji-btn" title="<?php esc_attr_e( 'Emoji', 'agent-access' ); ?>">😊</button>
			<button id="send-btn" title="<?php esc_attr_e( 'Send', 'agent-access' ); ?>">↑</button>
		</div>
	</div>

</div><!-- /#main -->
</div><!-- /#app -->

<!-- Emoji picker -->
<div id="emoji-picker"></div>

<!-- Drop overlay -->
<div id="drop-overlay">📎 <?php esc_html_e( 'Drop files to upload', 'agent-access' ); ?></div>

<!-- Toast -->
<div id="toast"></div>

<!-- Status bar -->
<div id="status-bar"></div>

<!-- Modal (channel create / DM start) -->
<div id="modal-overlay">
	<div class="modal" id="modal">
		<h3 id="modal-title">New Channel</h3>
		<div id="modal-body"></div>
		<div class="modal-actions">
			<button class="btn-cancel" id="modal-cancel"><?php esc_html_e( 'Cancel', 'agent-access' ); ?></button>
			<button class="btn-primary" id="modal-ok"><?php esc_html_e( 'Create', 'agent-access' ); ?></button>
		</div>
	</div>
</div>

<script>
/* ─── Config ─────────────────────────────────────────────────────────────── */
const CFG = {
	restUrl:  '<?php echo $rest_url; ?>',
	nonce:    '<?php echo esc_js( $nonce ); ?>',
	userName: '<?php echo $display_name; ?>',
	userId:   <?php echo $user_id; ?>,
	isAdmin:  <?php echo $is_admin; ?>,
	avatarUrl:'<?php echo esc_js( $avatar_url ); ?>',
};

/* ─── State ──────────────────────────────────────────────────────────────── */
let state = {
	channel:     'ops',
	channelDesc: '',
	channels:    [],
	dmChannels:  [],
	onlineUsers: [],
	unread:      {},
	messages:    [],
	lastId:      0,
	replyTo:     null,
	threadId:    null,
	longPollXHR: null,
	pollFallback:false,
	notifyPerm:  false,
	searchTimer: null,
	typingTimer: null,
	presenceInt: null,
	typingInt:   null,
	pollInt:     null,
};

/* ─── API ────────────────────────────────────────────────────────────────── */
async function api(path, opts={}) {
	const url = CFG.restUrl + path;
	const res = await fetch(url, {
		...opts,
		headers: {
			'X-WP-Nonce': CFG.nonce,
			'Content-Type': 'application/json',
			...(opts.headers||{}),
		},
	});
	if (!res.ok) {
		const err = await res.json().catch(()=>({error:'HTTP '+res.status}));
		throw new Error(err.error||'HTTP '+res.status);
	}
	return res.json();
}

/* ─── Utilities ──────────────────────────────────────────────────────────── */
function esc(str) {
	const d = document.createElement('div');
	d.textContent = String(str||'');
	return d.innerHTML;
}

function fmt(ts) {
	if (!ts) return '';
	const d = new Date(ts.includes('T')||ts.endsWith('Z') ? ts : ts+' UTC');
	return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
}

function fmtDate(ts) {
	if (!ts) return '';
	const d   = new Date(ts.includes('T')||ts.endsWith('Z') ? ts : ts+' UTC');
	const now = new Date();
	const diff= Math.floor((now-d)/(1000*60*60*24));
	if (diff===0) return 'Today';
	if (diff===1) return 'Yesterday';
	return d.toLocaleDateString([], {month:'long', day:'numeric'});
}

function initials(name) {
	return (name||'?').split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
}

function toast(msg, ms=3000) {
	const t = document.getElementById('toast');
	t.textContent = msg;
	t.classList.add('show');
	setTimeout(()=>t.classList.remove('show'), ms);
}

function setStatus(s) {
	document.getElementById('status-bar').textContent = s;
}

/* ─── Notifications ──────────────────────────────────────────────────────── */
function requestNotifyPerm() {
	if ('Notification' in window && Notification.permission==='default') {
		Notification.requestPermission().then(p=>{ state.notifyPerm = p==='granted'; });
	} else if (Notification.permission==='granted') {
		state.notifyPerm = true;
	}
}

function notify(title, body) {
	if (!state.notifyPerm || document.hasFocus()) return;
	new Notification(title, {body, icon: CFG.avatarUrl});
}

/* ─── Emoji picker ───────────────────────────────────────────────────────── */
const EMOJIS = ['👍','❤️','😂','😮','😢','😡','🎉','🔥','✅','🚀',
	'👀','💯','🤔','😊','🙏','😎','💪','🤝','✨','⚡',
	'🐛','💡','🎯','📌','🔑','⚠️','🧠','🦾','💻','🤖'];

(function(){
	const p = document.getElementById('emoji-picker');
	EMOJIS.forEach(e=>{
		const s = document.createElement('span');
		s.textContent = e;
		s.title = e;
		s.onclick = () => insertEmoji(e);
		p.appendChild(s);
	});
})();

function insertEmoji(e) {
	const inp = document.getElementById('msg-input');
	const pos = inp.selectionStart;
	const val = inp.value;
	inp.value = val.slice(0,pos)+e+val.slice(inp.selectionEnd);
	inp.selectionStart = inp.selectionEnd = pos+e.length;
	inp.focus();
	document.getElementById('emoji-picker').classList.remove('open');
}

document.getElementById('emoji-btn').onclick = (ev) => {
	ev.stopPropagation();
	document.getElementById('emoji-picker').classList.toggle('open');
};
document.addEventListener('click', ()=>document.getElementById('emoji-picker').classList.remove('open'));

/* ─── Message rendering ──────────────────────────────────────────────────── */
function renderReactions(reactions, msgId) {
	if (!reactions || !Object.keys(reactions).length) return '';
	let html = '<div class="msg-reactions">';
	Object.entries(reactions).forEach(([emoji, users])=>{
		if (!users.length) return;
		const mine = users.includes(CFG.userId);
		html += `<span class="reaction-badge${mine?' mine':''}" data-emoji="${esc(emoji)}" data-id="${msgId}" title="${users.length} reaction${users.length>1?'s':''}">
			${esc(emoji)}<span class="r-count">${users.length}</span>
		</span>`;
	});
	html += '</div>';
	return html;
}

function renderAttachment(msg) {
	if (!msg.attachment_url) return '';
	const isImage = msg.attachment_type && msg.attachment_type.startsWith('image/');
	if (isImage) {
		return `<div class="msg-attachment"><img src="${esc(msg.attachment_url)}" loading="lazy"></div>`;
	}
	const name = msg.attachment_url.split('/').pop();
	return `<div class="msg-attachment"><a href="${esc(msg.attachment_url)}" target="_blank">📎 ${esc(name)}</a></div>`;
}

function renderMessage(msg, isContinuation=false) {
	const type     = (msg.sender_type||'agent').toLowerCase();
	const sndClass = type==='human' ? 'human' : 'agent';
	const avInit   = initials(msg.sender);
	const edited   = msg.edited ? ' <span class="msg-edited">(edited)</span>' : '';
	const timeStr  = fmt(msg.timestamp);

	let replyHtml = '';
	if (msg.reply_preview) {
		replyHtml = `<div class="reply-preview" data-thread="${msg.reply_preview.id}">
			<strong>${esc(msg.reply_preview.sender)}</strong>: ${esc(msg.reply_preview.snippet)}
		</div>`;
	}

	const canEdit   = msg.sender_id===CFG.userId;
	const canDelete = msg.sender_id===CFG.userId || CFG.isAdmin;

	const actions = `<div class="msg-actions">
		<button class="msg-action-btn" data-action="react" data-id="${msg.id}" title="React">😊</button>
		<button class="msg-action-btn" data-action="thread" data-id="${msg.id}" title="Reply in thread">💬</button>
		${canEdit?`<button class="msg-action-btn" data-action="edit" data-id="${msg.id}" title="Edit">✏️</button>`:''}
		${canDelete?`<button class="msg-action-btn danger" data-action="delete" data-id="${msg.id}" title="Delete">🗑️</button>`:''}
	</div>`;

	const contClass = isContinuation ? ' continuation' : '';

	return `<div class="msg-row${contClass}" data-id="${msg.id}">
		<div class="msg-avatar ${sndClass}">${avInit}</div>
		<div class="msg-body">
			<div class="msg-header">
				<span class="msg-sender ${sndClass}">${esc(msg.sender)}</span>
				<span class="msg-time">${esc(timeStr)}</span>
				${edited}
			</div>
			${replyHtml}
			<div class="msg-content" id="mc-${msg.id}">${esc(msg.message)}</div>
			${renderAttachment(msg)}
			${renderReactions(msg.reactions, msg.id)}
		</div>
		${actions}
	</div>`;
}

function renderMessageList(messages, container) {
	if (!messages.length) {
		container.innerHTML = `<div class="empty-state">
			<div class="es-icon">💬</div>
			No messages yet. Start the conversation!
		</div>`;
		return;
	}

	let html    = '';
	let lastDay = '';
	let lastAuthor = null;
	let lastTs     = 0;

	messages.forEach((msg, i) => {
		const day = fmtDate(msg.timestamp);
		if (day !== lastDay) {
			html += `<div class="date-divider">${esc(day)}</div>`;
			lastDay    = day;
			lastAuthor = null;
		}

		// Continuation: same author within 5 minutes, no reply_to.
		const ts   = msg.timestamp ? new Date(msg.timestamp.includes('T')||msg.timestamp.endsWith('Z') ? msg.timestamp : msg.timestamp+' UTC').getTime() : 0;
		const isCont = lastAuthor===msg.sender_id && !msg.reply_to && (ts-lastTs)<300000;

		html += renderMessage(msg, isCont);

		lastAuthor = msg.sender_id;
		lastTs     = ts;
	});

	container.innerHTML = html;
}

/* ─── Load messages ──────────────────────────────────────────────────────── */
async function loadMessages(search='') {
	const area = document.getElementById('messages-area');
	const params = new URLSearchParams({channel: state.channel, limit: '100'});
	if (search) params.set('search', search);

	try {
		const data = await api('messages?'+params);
		state.messages = data.messages||[];
		if (state.messages.length) {
			state.lastId = Math.max(...state.messages.map(m=>m.id));
		}
		renderMessageList(state.messages, area);
		area.scrollTop = area.scrollHeight;
	} catch(e) {
		area.innerHTML = `<div class="empty-state">Error loading messages: ${esc(e.message)}</div>`;
	}
}

/* ─── Long-poll ──────────────────────────────────────────────────────────── */
function startLongPoll() {
	if (state.pollFallback) return;
	doLongPoll();
}

async function doLongPoll() {
	if (state.pollFallback) return;
	try {
		const params = new URLSearchParams({
			channel:  state.channel,
			since_id: state.lastId,
			timeout:  25,
		});
		const ctl = new AbortController();
		state.longPollCtrl = ctl;

		const res = await fetch(CFG.restUrl+'poll?'+params, {
			headers: {'X-WP-Nonce': CFG.nonce},
			signal: ctl.signal,
		});

		if (!res.ok) throw new Error('Poll '+res.status);
		const data = await res.json();

		if (data.messages&&data.messages.length) {
			appendNewMessages(data.messages);
		}
		setStatus('● Connected');
		// Chain next poll immediately.
		setTimeout(doLongPoll, 100);
	} catch(e) {
		if (e.name==='AbortError') return; // Channel switch.
		// Fall back to interval polling.
		setStatus('⚠ Long-poll failed, using interval polling');
		state.pollFallback = true;
		state.pollInt = setInterval(intervalPoll, 3000);
	}
}

async function intervalPoll() {
	if (document.getElementById('search-box').value.trim()) return;
	try {
		const data = await api(`messages?channel=${encodeURIComponent(state.channel)}&since_id=${state.lastId}&limit=50`);
		if (data.messages&&data.messages.length) {
			appendNewMessages(data.messages);
		}
		setStatus('● Connected (polling)');
	} catch(e) {
		setStatus('⚠ Connection error');
	}
}

function appendNewMessages(newMsgs) {
	const area     = document.getElementById('messages-area');
	const atBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 80;

	newMsgs.forEach(msg => {
		// Avoid duplicates.
		if (state.messages.find(m=>m.id===msg.id)) return;
		state.messages.push(msg);
		if (msg.id > state.lastId) state.lastId = msg.id;

		const wrapper = document.createElement('div');
		wrapper.innerHTML = renderMessage(msg, false);
		area.appendChild(wrapper.firstElementChild);

		// Desktop notification.
		if (msg.sender_id!==CFG.userId) {
			notify('Agent Chat — '+state.channel, (msg.sender||'Agent')+': '+msg.message.slice(0,80));
		}
	});

	if (atBottom) area.scrollTop = area.scrollHeight;
	updateUnreadBadges();
}

function stopPoll() {
	if (state.longPollCtrl) { state.longPollCtrl.abort(); state.longPollCtrl=null; }
	if (state.pollInt)       { clearInterval(state.pollInt); state.pollInt=null; }
	state.pollFallback = false;
}

/* ─── Channels ───────────────────────────────────────────────────────────── */
async function loadChannels() {
	try {
		const data = await api('channels');
		state.channels = data.channels||[];
		renderSidebarChannels();
	} catch(e) {}
}

function renderSidebarChannels() {
	const list = document.getElementById('channel-list');
	list.innerHTML = state.channels.map(ch => {
		const isDM    = ch.name.startsWith('dm:');
		if (isDM) return '';
		const unread  = state.unread[ch.name]||0;
		const active  = ch.name===state.channel ? ' active' : '';
		const badge   = unread ? `<span class="ch-badge">${unread}</span>` : '';
		return `<div class="channel-item${active}" data-ch="${esc(ch.name)}">
			<span class="ch-icon">#</span>
			<span class="ch-name">${esc(ch.name)}</span>
			${badge}
		</div>`;
	}).join('');

	// Re-bind click events.
	list.querySelectorAll('.channel-item').forEach(el=>{
		el.onclick = () => switchChannel(el.dataset.ch);
	});
}

function updateChannelHeader() {
	const ch = state.channels.find(c=>c.name===state.channel);
	document.getElementById('channel-name').textContent = '#'+state.channel;
	document.getElementById('channel-desc').textContent = ch ? (ch.description||'') : '';
	document.getElementById('channel-members').textContent = ch ? '👥 '+ch.member_count : '';
}

async function switchChannel(ch) {
	if (ch===state.channel) return;
	state.channel   = ch;
	state.lastId    = 0;
	state.replyTo   = null;
	state.threadId  = null;
	document.getElementById('reply-to-bar').style.display = 'none';
	closeThread();
	stopPoll();

	renderSidebarChannels();
	updateChannelHeader();
	await loadMessages();
	startLongPoll();
}

/* ─── Unread ─────────────────────────────────────────────────────────────── */
async function loadUnread() {
	try {
		const data = await api('unread');
		state.unread = data.unread||{};
		updateUnreadBadges();
	} catch(e) {}
}

function updateUnreadBadges() {
	document.querySelectorAll('.channel-item[data-ch]').forEach(el=>{
		const ch     = el.dataset.ch;
		const unread = state.unread[ch]||0;
		let badge    = el.querySelector('.ch-badge');
		if (unread && ch!==state.channel) {
			if (!badge) {
				badge = document.createElement('span');
				badge.className = 'ch-badge';
				el.appendChild(badge);
			}
			badge.textContent = unread;
		} else if (badge) {
			badge.remove();
		}
	});
}

/* ─── Presence & typing ──────────────────────────────────────────────────── */
function startPresence() {
	const ping = () => api('presence', {method:'POST'}).catch(()=>{});
	ping();
	state.presenceInt = setInterval(ping, 45000);
}

async function updateTyping() {
	try {
		const data = await api('typing?channel='+encodeURIComponent(state.channel));
		const bar  = document.getElementById('typing-bar');
		const names = (data.typing||[]).map(u=>u.display_name);
		if (names.length===0)      bar.textContent = '';
		else if (names.length===1) bar.textContent = names[0]+' is typing…';
		else                       bar.textContent = names.slice(0,-1).join(', ')+' and '+names.slice(-1)+' are typing…';
	} catch(e) {}
}

function onTyping() {
	clearTimeout(state.typingTimer);
	state.typingTimer = setTimeout(()=>{
		api('typing', {method:'POST', body: JSON.stringify({channel:state.channel})}).catch(()=>{});
	}, 400);
}

/* ─── Send message ───────────────────────────────────────────────────────── */
async function sendMessage() {
	const inp  = document.getElementById('msg-input');
	const btn  = document.getElementById('send-btn');
	const text = inp.value.trim();
	if (!text) return;

	btn.disabled = true;
	try {
		const payload = {
			channel:     state.channel,
			sender:      CFG.userName,
			sender_type: 'human',
			message:     text,
		};
		if (state.replyTo) payload.reply_to = state.replyTo.id;

		const msg = await api('send', {method:'POST', body: JSON.stringify(payload)});

		inp.value = '';
		inp.style.height = 'auto';
		state.replyTo = null;
		document.getElementById('reply-to-bar').style.display = 'none';

		// Append optimistically.
		appendNewMessages([msg]);
	} catch(e) {
		toast('Failed to send: '+e.message);
	}
	btn.disabled = false;
	inp.focus();
}

/* ─── Thread ─────────────────────────────────────────────────────────────── */
function openThread(parentId) {
	state.threadId = parentId;
	const panel    = document.getElementById('thread-panel');
	panel.classList.add('open');
	loadThreadMessages(parentId);
}

function closeThread() {
	state.threadId = null;
	document.getElementById('thread-panel').classList.remove('open');
}

async function loadThreadMessages(parentId) {
	const area = document.getElementById('thread-messages');
	area.innerHTML = '<div class="loading">Loading…</div>';
	try {
		const data = await api(`messages?channel=${encodeURIComponent(state.channel)}&thread_id=${parentId}&limit=100`);
		const msgs = data.messages||[];
		if (!msgs.length) {
			area.innerHTML = '<div class="loading">No replies yet.</div>';
			return;
		}
		area.innerHTML = msgs.map(m=>renderMessage(m)).join('');
	} catch(e) {
		area.innerHTML = '<div class="loading">Error loading thread.</div>';
	}
}

async function sendThreadMessage() {
	if (!state.threadId) return;
	const inp  = document.getElementById('thread-input');
	const text = inp.value.trim();
	if (!text) return;
	inp.value = '';
	try {
		await api('send', {method:'POST', body: JSON.stringify({
			channel:     state.channel,
			sender:      CFG.userName,
			sender_type: 'human',
			message:     text,
			reply_to:    state.threadId,
		})});
		loadThreadMessages(state.threadId);
	} catch(e) {
		toast('Failed: '+e.message);
	}
}

/* ─── Reply setup ────────────────────────────────────────────────────────── */
function setReply(msg) {
	state.replyTo = msg;
	document.getElementById('reply-to-name').textContent    = msg.sender;
	document.getElementById('reply-to-snippet').textContent = msg.message.slice(0,60)+(msg.message.length>60?'…':'');
	document.getElementById('reply-to-bar').style.display   = 'flex';
	document.getElementById('msg-input').focus();
}

/* ─── Edit message ───────────────────────────────────────────────────────── */
function editMessage(msgId) {
	const msg    = state.messages.find(m=>m.id===msgId);
	if (!msg) return;
	const cont   = document.getElementById('mc-'+msgId);
	if (!cont) return;
	const orig   = msg.message;
	cont.innerHTML = `<textarea class="edit-input" id="edit-input-${msgId}">${esc(orig)}</textarea>
		<div class="edit-actions">
			<button class="btn-save" data-id="${msgId}">Save</button>
			<button class="btn-discard" data-id="${msgId}">Discard</button>
		</div>`;

	cont.querySelector('.btn-save').onclick = async () => {
		const newText = cont.querySelector('.edit-input').value.trim();
		if (!newText) return;
		try {
			const updated = await api('messages/'+msgId, {method:'PUT', body: JSON.stringify({message: newText})});
			const idx = state.messages.findIndex(m=>m.id===msgId);
			if (idx>-1) state.messages[idx] = updated;
			// Re-render this row.
			const row = document.querySelector(`.msg-row[data-id="${msgId}"]`);
			if (row) {
				const wrapper = document.createElement('div');
				wrapper.innerHTML = renderMessage(updated);
				row.replaceWith(wrapper.firstElementChild);
			}
		} catch(e) { toast('Edit failed: '+e.message); }
	};
	cont.querySelector('.btn-discard').onclick = () => {
		cont.textContent = orig;
	};

	const editInp = document.getElementById('edit-input-'+msgId);
	editInp.focus();
	editInp.selectionStart = editInp.selectionEnd = editInp.value.length;
}

/* ─── Delete message ─────────────────────────────────────────────────────── */
async function deleteMessage(msgId) {
	if (!confirm('Delete this message?')) return;
	try {
		await api('messages/'+msgId, {method:'DELETE'});
		state.messages = state.messages.filter(m=>m.id!==msgId);
		const row = document.querySelector(`.msg-row[data-id="${msgId}"]`);
		if (row) row.remove();
	} catch(e) {
		toast('Delete failed: '+e.message);
	}
}

/* ─── Reactions ──────────────────────────────────────────────────────────── */
let reactionTarget = null;

function showReactionPicker(msgId, anchorEl) {
	reactionTarget = msgId;
	const picker   = document.getElementById('emoji-picker');
	picker.style.position = 'fixed';
	const rect = anchorEl.getBoundingClientRect();
	picker.style.bottom = (window.innerHeight - rect.top + 8)+'px';
	picker.style.left   = rect.left+'px';
	picker.style.right  = 'auto';
	picker.classList.add('open');
}

// Override insertEmoji when reactionTarget is set.
const _origInsert = insertEmoji;
window.insertEmoji = function(e) {
	if (reactionTarget) {
		const id = reactionTarget;
		reactionTarget = null;
		document.getElementById('emoji-picker').classList.remove('open');
		toggleReaction(id, e);
	} else {
		_origInsert(e);
	}
};

async function toggleReaction(msgId, emoji) {
	const msg = state.messages.find(m=>m.id===msgId);
	if (!msg) return;
	const reactions = msg.reactions||{};
	const userList  = reactions[emoji]||[];
	const alreadyOn = userList.includes(CFG.userId);

	try {
		const body = JSON.stringify({message_id: msgId, emoji});
		const data = await api('react', {method: alreadyOn?'DELETE':'POST', body});
		// Update local state.
		if (msg) msg.reactions = data.reactions;
		// Re-render reactions only.
		const row = document.querySelector(`.msg-row[data-id="${msgId}"]`);
		if (row) {
			let reacDiv = row.querySelector('.msg-reactions');
			const newHtml = renderReactions(data.reactions, msgId);
			if (reacDiv) {
				reacDiv.outerHTML = newHtml;
			} else {
				row.querySelector('.msg-body').insertAdjacentHTML('beforeend', newHtml);
			}
		}
	} catch(e) { toast('Reaction failed: '+e.message); }
}

/* ─── File upload ────────────────────────────────────────────────────────── */
document.getElementById('attach-btn').onclick = () => document.getElementById('file-input').click();

document.getElementById('file-input').onchange = async (ev) => {
	const files = Array.from(ev.target.files);
	for (const file of files) {
		await uploadAndSend(file);
	}
	ev.target.value = '';
};

async function uploadAndSend(file) {
	const formData = new FormData();
	formData.append('file', file);
	formData.append('title', file.name);

	try {
		toast('Uploading…');
		const res = await fetch('/wp-json/wp/v2/media', {
			method: 'POST',
			headers: {'X-WP-Nonce': CFG.nonce},
			body: formData,
		});
		if (!res.ok) throw new Error('Upload failed');
		const media = await res.json();

		// Send message with attachment.
		const msg = await api('send', {method:'POST', body: JSON.stringify({
			channel:       state.channel,
			sender:        CFG.userName,
			sender_type:   'human',
			message:       file.name,
			attachment_id: media.id,
		})});
		appendNewMessages([msg]);
		toast('File uploaded.');
	} catch(e) {
		toast('Upload error: '+e.message);
	}
}

/* ─── Drag & drop ────────────────────────────────────────────────────────── */
let dragCounter = 0;
document.addEventListener('dragenter', ()=>{ dragCounter++; document.getElementById('drop-overlay').classList.add('active'); });
document.addEventListener('dragleave', ()=>{ dragCounter--; if (dragCounter<=0) { dragCounter=0; document.getElementById('drop-overlay').classList.remove('active'); } });
document.addEventListener('dragover', ev=>ev.preventDefault());
document.addEventListener('drop', async ev=>{
	ev.preventDefault();
	dragCounter = 0;
	document.getElementById('drop-overlay').classList.remove('active');
	const files = Array.from(ev.dataTransfer.files);
	for (const f of files) await uploadAndSend(f);
});

/* ─── DM ─────────────────────────────────────────────────────────────────── */
async function startDM(userId) {
	try {
		const data = await api('dm/start', {method:'POST', body: JSON.stringify({user_id: userId})});
		const ch   = data.channel;
		// Add to DM list in sidebar if not present.
		if (!state.channels.find(c=>c.name===ch)) {
			state.channels.push({name:ch, description:'DM', private:true, member_count:2, last_message_at:null});
		}
		switchChannel(ch);
		renderDMList();
	} catch(e) {
		toast('DM error: '+e.message);
	}
}

function renderDMList() {
	const list = document.getElementById('dm-list');
	const dms  = state.channels.filter(c=>c.name.startsWith('dm:'));
	if (!dms.length) { list.innerHTML=''; return; }

	list.innerHTML = dms.map(ch => {
		const ids   = ch.name.replace('dm:','').split(':').map(Number);
		const other = ids.find(id=>id!==CFG.userId)||ids[0];
		const isOn  = state.onlineUsers.some(u=>u.user_id===other);
		const unread= state.unread[ch.name]||0;
		const active= ch.name===state.channel ? ' active' : '';
		const badge = unread ? `<span class="ch-badge">${unread}</span>` : '';
		const dot   = `<span class="online-dot ${isOn?'':'offline-dot'}"></span>`;
		return `<div class="channel-item${active}" data-ch="${esc(ch.name)}">
			${dot}
			<span class="ch-name">DM #${other}</span>
			${badge}
		</div>`;
	}).join('');

	list.querySelectorAll('.channel-item').forEach(el=>{
		el.onclick = ()=> switchChannel(el.dataset.ch);
	});
}

/* ─── Modals ─────────────────────────────────────────────────────────────── */
document.getElementById('modal-cancel').onclick = closeModal;
document.getElementById('modal-overlay').onclick = ev=>{ if (ev.target===document.getElementById('modal-overlay')) closeModal(); };

function closeModal() {
	document.getElementById('modal-overlay').classList.remove('open');
}

// New channel modal.
document.getElementById('new-channel-btn').onclick = () => {
	document.getElementById('modal-title').textContent = 'New Channel';
	document.getElementById('modal-body').innerHTML = `
		<label>Channel name</label>
		<input type="text" id="m-ch-name" placeholder="my-channel" maxlength="40">
		<label>Description</label>
		<input type="text" id="m-ch-desc" placeholder="Optional description">
		<div class="checkbox-row">
			<input type="checkbox" id="m-ch-private">
			<label for="m-ch-private">Private</label>
		</div>`;
	document.getElementById('modal-ok').textContent = 'Create';
	document.getElementById('modal-ok').onclick = async () => {
		const name = document.getElementById('m-ch-name').value.trim();
		if (!name) { toast('Enter a channel name.'); return; }
		try {
			await api('channels/create', {method:'POST', body: JSON.stringify({
				name,
				description: document.getElementById('m-ch-desc').value.trim(),
				private:     document.getElementById('m-ch-private').checked,
			})});
			closeModal();
			await loadChannels();
			switchChannel(name);
		} catch(e) { toast(e.message); }
	};
	document.getElementById('modal-overlay').classList.add('open');
	setTimeout(()=>document.getElementById('m-ch-name').focus(), 50);
};

// New DM modal.
document.getElementById('new-dm-btn').onclick = async () => {
	document.getElementById('modal-title').textContent = 'New Direct Message';
	document.getElementById('modal-body').innerHTML = `
		<label>User ID</label>
		<input type="number" id="m-dm-uid" placeholder="WordPress user ID">`;
	document.getElementById('modal-ok').textContent = 'Start DM';
	document.getElementById('modal-ok').onclick = () => {
		const uid = parseInt(document.getElementById('m-dm-uid').value, 10);
		if (!uid) { toast('Enter a user ID.'); return; }
		closeModal();
		startDM(uid);
	};
	document.getElementById('modal-overlay').classList.add('open');
	setTimeout(()=>document.getElementById('m-dm-uid').focus(), 50);
};

/* ─── Event delegation for message actions ───────────────────────────────── */
document.getElementById('messages-area').addEventListener('click', ev => {
	const btn = ev.target.closest('[data-action]');
	if (!btn) {
		// Check reply-preview click.
		const rp = ev.target.closest('.reply-preview');
		if (rp && rp.dataset.thread) openThread(parseInt(rp.dataset.thread, 10));
		// Check reaction badge click.
		const rb = ev.target.closest('.reaction-badge');
		if (rb) toggleReaction(parseInt(rb.dataset.id,10), rb.dataset.emoji);
		return;
	}
	const id = parseInt(btn.dataset.id, 10);
	const msg= state.messages.find(m=>m.id===id);
	switch (btn.dataset.action) {
		case 'react':
			reactionTarget = null;
			showReactionPicker(id, btn);
			break;
		case 'thread':
			openThread(id);
			break;
		case 'edit':
			editMessage(id);
			break;
		case 'delete':
			deleteMessage(id);
			break;
	}
});

/* ─── Compose events ─────────────────────────────────────────────────────── */
document.getElementById('msg-input').addEventListener('keydown', ev => {
	if ('Enter'===ev.key && !ev.shiftKey) {
		ev.preventDefault();
		sendMessage();
	}
});
document.getElementById('msg-input').addEventListener('input', function() {
	this.style.height = 'auto';
	this.style.height = Math.min(this.scrollHeight, 130)+'px';
	onTyping();
});
document.getElementById('send-btn').onclick = sendMessage;
document.getElementById('cancel-reply').onclick = () => {
	state.replyTo = null;
	document.getElementById('reply-to-bar').style.display = 'none';
};

/* ─── Thread events ──────────────────────────────────────────────────────── */
document.getElementById('close-thread').onclick = closeThread;
document.getElementById('thread-send').onclick  = sendThreadMessage;
document.getElementById('thread-input').addEventListener('keydown', ev => {
	if ('Enter'===ev.key && !ev.shiftKey) {
		ev.preventDefault();
		sendThreadMessage();
	}
});
document.getElementById('thread-input').addEventListener('input', function() {
	this.style.height = 'auto';
	this.style.height = Math.min(this.scrollHeight, 100)+'px';
});

/* ─── Search ─────────────────────────────────────────────────────────────── */
document.getElementById('search-box').addEventListener('input', function() {
	clearTimeout(state.searchTimer);
	const q = this.value.trim();
	state.searchTimer = setTimeout(()=>loadMessages(q), 350);
});

/* ─── Sidebar toggle (mobile) ────────────────────────────────────────────── */
document.getElementById('hamburger').onclick = () => {
	document.getElementById('sidebar').classList.toggle('collapsed');
};

// Collapse sidebar when clicking outside on mobile.
document.getElementById('main').addEventListener('click', ()=>{
	if (window.innerWidth<=768) {
		document.getElementById('sidebar').classList.add('collapsed');
	}
});

/* ─── Online users ───────────────────────────────────────────────────────── */
async function refreshOnline() {
	try {
		const data    = await api('online');
		state.onlineUsers = data.online||[];
		renderDMList();
	} catch(e) {}
}

/* ─── Init ───────────────────────────────────────────────────────────────── */
async function boot() {
	requestNotifyPerm();
	await loadChannels();
	await loadUnread();
	await loadMessages();
	updateChannelHeader();
	startLongPoll();
	startPresence();
	refreshOnline();

	// Periodic tasks.
	setInterval(updateTyping,   3000);
	setInterval(loadUnread,    30000);
	setInterval(refreshOnline, 60000);
}

boot();
</script>
</body>
</html>
		<?php
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// ── Stat helper ───────────────────────────────────────────────────────────

	/**
	 * Count total published chat messages.
	 *
	 * @return int
	 */
	public static function get_message_count() {
		$counts = wp_count_posts( 'chat_message' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}
}
