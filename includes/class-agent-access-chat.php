<?php
/**
 * Agent Access Chat — CPT-based multi-agent chat system.
 *
 * Ported from Agent Chat v0.2.0. Provides:
 *   - `chat_message` custom post type
 *   - REST API under agent-access/v1/chat/ (send, messages, channels)
 *   - Authenticated frontend page at /agent-chat/
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
	 * Register all hooks. Called from agent_access_init().
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
			'channel'     => array( 'type' => 'string', 'default' => 'general' ),
			'sender_type' => array( 'type' => 'string', 'default' => 'agent' ),
			'agent_id'    => array( 'type' => 'string', 'default' => '' ),
			'sender_name' => array( 'type' => 'string', 'default' => '' ),
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
	 * Register REST routes under agent-access/v1/chat/.
	 */
	public function register_rest_routes() {
		// POST /wp-json/agent-access/v1/chat/send
		register_rest_route(
			'agent-access/v1',
			'/chat/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_send_message' ),
				'permission_callback' => array( $this, 'permission_edit_posts' ),
				'args'                => array(
					'channel'     => array( 'type' => 'string', 'default' => 'ops' ),
					'sender'      => array( 'type' => 'string', 'required' => true ),
					'sender_type' => array( 'type' => 'string', 'default' => 'agent' ),
					'message'     => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		// GET /wp-json/agent-access/v1/chat/messages
		register_rest_route(
			'agent-access/v1',
			'/chat/messages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_messages' ),
				'permission_callback' => array( $this, 'permission_read' ),
				'args'                => array(
					'channel' => array( 'type' => 'string',  'default' => 'ops' ),
					'since'   => array( 'type' => 'string',  'default' => '' ),
					'limit'   => array( 'type' => 'integer', 'default' => 50 ),
					'search'  => array( 'type' => 'string',  'default' => '' ),
				),
			)
		);

		// GET /wp-json/agent-access/v1/chat/channels
		register_rest_route(
			'agent-access/v1',
			'/chat/channels',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_channels' ),
				'permission_callback' => array( $this, 'permission_read' ),
			)
		);
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	/**
	 * Require edit_posts capability.
	 *
	 * @return bool
	 */
	public function permission_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Require read capability.
	 *
	 * @return bool
	 */
	public function permission_read() {
		return current_user_can( 'read' );
	}

	// ── REST callbacks ────────────────────────────────────────────────────────

	/**
	 * Handle POST /chat/send — insert a new chat message.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_send_message( WP_REST_Request $request ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'chat_message',
			'post_status'  => 'publish',
			'post_title'   => sanitize_text_field( $request['sender'] ),
			'post_content' => wp_kses_post( $request['message'] ),
		) );

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response(
				array( 'error' => $post_id->get_error_message() ),
				500
			);
		}

		update_post_meta( $post_id, 'channel',     sanitize_text_field( $request['channel'] ) );
		update_post_meta( $post_id, 'sender_type', sanitize_text_field( $request['sender_type'] ) );
		update_post_meta( $post_id, 'sender_name', sanitize_text_field( $request['sender'] ) );
		update_post_meta( $post_id, 'agent_id',    sanitize_text_field( $request['sender'] ) );

		return new WP_REST_Response(
			array(
				'id'        => $post_id,
				'channel'   => $request['channel'],
				'sender'    => $request['sender'],
				'message'   => $request['message'],
				'timestamp' => get_post_field( 'post_date_gmt', $post_id ),
			),
			201
		);
	}

	/**
	 * Handle GET /chat/messages — fetch messages for a channel.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_get_messages( WP_REST_Request $request ) {
		$args = array(
			'post_type'      => 'chat_message',
			'post_status'    => 'publish',
			'posts_per_page' => min( (int) $request['limit'], 200 ),
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'   => 'channel',
					'value' => sanitize_text_field( $request['channel'] ),
				),
			),
		);

		if ( ! empty( $request['since'] ) ) {
			$args['date_query'] = array(
				array(
					'after'  => $request['since'],
					'column' => 'post_date_gmt',
				),
			);
		}

		if ( ! empty( $request['search'] ) ) {
			$args['s'] = sanitize_text_field( $request['search'] );
		}

		$query    = new WP_Query( $args );
		$messages = array();

		foreach ( $query->posts as $post ) {
			$messages[] = array(
				'id'          => $post->ID,
				'sender'      => get_post_meta( $post->ID, 'sender_name', true ),
				'sender_type' => get_post_meta( $post->ID, 'sender_type', true ),
				'channel'     => get_post_meta( $post->ID, 'channel', true ),
				'message'     => $post->post_content,
				'timestamp'   => $post->post_date_gmt,
			);
		}

		return new WP_REST_Response(
			array(
				'channel'  => $request['channel'],
				'count'    => count( $messages ),
				'messages' => $messages,
			)
		);
	}

	/**
	 * Handle GET /chat/channels — list all distinct channel names.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_get_channels( WP_REST_Request $request ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$channels = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = 'channel'
			 AND post_id IN (
			     SELECT ID FROM {$wpdb->posts}
			     WHERE post_type = 'chat_message'
			     AND post_status = 'publish'
			 )
			 ORDER BY meta_value ASC"
		);

		return new WP_REST_Response( array( 'channels' => $channels ) );
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
	 * Render the full Agent Chat frontend page.
	 *
	 * All user-supplied values are escaped at the point of output.
	 */
	private function render_frontend() {
		$user         = wp_get_current_user();
		$display_name = esc_js( $user->display_name );
		$nonce        = wp_create_nonce( 'wp_rest' );
		$rest_url     = esc_url_raw( rest_url( 'agent-access/v1/chat/' ) );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JS vars are escaped above; HTML is static.
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php esc_html_e( 'Agent Chat', 'agent-access' ); ?></title>
<style>
	:root {
		--bg: #1a1a2e;
		--surface: #16213e;
		--surface2: #0f3460;
		--accent: #e94560;
		--text: #eee;
		--text-muted: #888;
		--human: #4ecca3;
		--agent: #7b68ee;
		--bot: #ff6b6b;
		--border: #2a2a4a;
	}
	* { margin: 0; padding: 0; box-sizing: border-box; }
	body {
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		background: var(--bg);
		color: var(--text);
		height: 100vh;
		display: flex;
		flex-direction: column;
	}
	header {
		background: var(--surface);
		border-bottom: 1px solid var(--border);
		padding: 12px 20px;
		display: flex;
		align-items: center;
		gap: 12px;
		flex-shrink: 0;
	}
	header h1 { font-size: 18px; font-weight: 600; }
	header .channel-select {
		background: var(--surface2);
		border: 1px solid var(--border);
		color: var(--text);
		padding: 6px 10px;
		border-radius: 6px;
		font-size: 14px;
	}
	header .search-box {
		margin-left: auto;
		background: var(--surface2);
		border: 1px solid var(--border);
		color: var(--text);
		padding: 6px 12px;
		border-radius: 6px;
		font-size: 14px;
		width: 200px;
	}
	header .search-box::placeholder { color: var(--text-muted); }
	header .user-badge { font-size: 13px; color: var(--text-muted); }
	#messages {
		flex: 1;
		overflow-y: auto;
		padding: 16px 20px;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.msg {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		max-width: 80%;
		animation: fadeIn 0.2s ease;
	}
	@keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; } }
	.msg.human { align-self: flex-end; flex-direction: row-reverse; }
	.msg .avatar {
		width: 32px;
		height: 32px;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 14px;
		font-weight: 700;
		flex-shrink: 0;
	}
	.msg.agent .avatar { background: var(--agent); }
	.msg.human .avatar { background: var(--human); }
	.msg.bot .avatar   { background: var(--bot); }
	.msg .bubble {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: 12px;
		padding: 10px 14px;
		font-size: 14px;
		line-height: 1.5;
	}
	.msg.human .bubble  { background: var(--surface2); }
	.msg .bubble .sender { font-size: 12px; font-weight: 600; margin-bottom: 4px; }
	.msg.agent .bubble .sender { color: var(--agent); }
	.msg.human .bubble .sender { color: var(--human); }
	.msg.bot .bubble .sender   { color: var(--bot); }
	.msg .bubble .time { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
	.msg .bubble .content { white-space: pre-wrap; word-wrap: break-word; }
	#compose {
		background: var(--surface);
		border-top: 1px solid var(--border);
		padding: 12px 20px;
		display: flex;
		gap: 10px;
		flex-shrink: 0;
	}
	#compose textarea {
		flex: 1;
		background: var(--surface2);
		border: 1px solid var(--border);
		color: var(--text);
		padding: 10px 14px;
		border-radius: 8px;
		font-size: 14px;
		font-family: inherit;
		resize: none;
		min-height: 44px;
		max-height: 120px;
	}
	#compose textarea::placeholder { color: var(--text-muted); }
	#compose button {
		background: var(--accent);
		color: white;
		border: none;
		padding: 10px 20px;
		border-radius: 8px;
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		transition: opacity 0.15s;
	}
	#compose button:hover    { opacity: 0.85; }
	#compose button:disabled { opacity: 0.4; cursor: not-allowed; }
	.status-bar {
		text-align: center;
		font-size: 12px;
		color: var(--text-muted);
		padding: 4px;
	}
	.empty-state {
		text-align: center;
		color: var(--text-muted);
		margin-top: 40px;
		font-size: 14px;
	}
</style>
</head>
<body>

<header>
	<h1>🤖 <?php esc_html_e( 'Agent Chat', 'agent-access' ); ?></h1>
	<select class="channel-select" id="channelSelect">
		<option value="ops">#ops</option>
	</select>
	<input type="text" class="search-box" id="searchBox" placeholder="<?php esc_attr_e( 'Search messages…', 'agent-access' ); ?>">
	<span class="user-badge">👤 <?php echo esc_html( $user->display_name ); ?></span>
</header>

<div id="messages"></div>
<div class="status-bar" id="statusBar"><?php esc_html_e( 'Connected', 'agent-access' ); ?></div>

<div id="compose">
	<textarea id="msgInput" placeholder="<?php esc_attr_e( 'Type a message… (Enter to send, Shift+Enter for new line)', 'agent-access' ); ?>" rows="1"></textarea>
	<button id="sendBtn" onclick="sendMessage()"><?php esc_html_e( 'Send', 'agent-access' ); ?></button>
</div>

<script>
const CONFIG = {
	restUrl: '<?php echo $rest_url; ?>',
	nonce: '<?php echo esc_js( $nonce ); ?>',
	user: '<?php echo $display_name; ?>',
	pollInterval: 5000,
};

let currentChannel = 'ops';
let lastMessageId  = 0;
let pollTimer      = null;
let searchTimer    = null;

// ─── API helpers ───────────────────────────────────────────────────────────

async function api( endpoint, opts = {} ) {
	const url = CONFIG.restUrl + endpoint;
	const res = await fetch( url, {
		...opts,
		headers: {
			'X-WP-Nonce': CONFIG.nonce,
			'Content-Type': 'application/json',
			...( opts.headers || {} ),
		},
	} );
	if ( ! res.ok ) throw new Error( `API ${ res.status }` );
	return res.json();
}

// ─── Messages ──────────────────────────────────────────────────────────────

function renderMessage( msg ) {
	const type     = msg.sender_type || 'agent';
	const initials = ( msg.sender || '?' ).slice( 0, 2 ).toUpperCase();
	const time     = msg.timestamp
		? new Date( msg.timestamp + 'Z' ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } )
		: '';
	const content  = escapeHtml( msg.message || '' );

	return `<div class="msg ${ type }">
		<div class="avatar">${ initials }</div>
		<div class="bubble">
			<div class="sender">${ escapeHtml( msg.sender || 'Unknown' ) }</div>
			<div class="content">${ content }</div>
			<div class="time">${ time }</div>
		</div>
	</div>`;
}

function escapeHtml( str ) {
	const d = document.createElement( 'div' );
	d.textContent = str;
	return d.innerHTML;
}

async function loadMessages( search = '' ) {
	const params = new URLSearchParams( { channel: currentChannel, limit: '100' } );
	if ( search ) params.set( 'search', search );

	const data      = await api( 'messages?' + params );
	const container = document.getElementById( 'messages' );

	if ( ! data.messages.length ) {
		container.innerHTML = '<div class="empty-state"><?php echo esc_js( __( 'No messages yet. Start the conversation!', 'agent-access' ) ); ?></div>';
		return;
	}

	container.innerHTML = data.messages.map( renderMessage ).join( '' );

	if ( data.messages.length ) {
		lastMessageId = Math.max( ...data.messages.map( m => m.id ) );
	}

	container.scrollTop = container.scrollHeight;
}

async function pollNewMessages() {
	try {
		const search = document.getElementById( 'searchBox' ).value.trim();
		if ( search ) return; // Don't poll while searching.

		const params = new URLSearchParams( { channel: currentChannel, limit: '100' } );
		const data   = await api( 'messages?' + params );

		if ( data.messages.length ) {
			const newMax = Math.max( ...data.messages.map( m => m.id ) );
			if ( newMax > lastMessageId ) {
				const container  = document.getElementById( 'messages' );
				const wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 50;

				container.innerHTML = data.messages.map( renderMessage ).join( '' );
				lastMessageId       = newMax;

				if ( wasAtBottom ) {
					container.scrollTop = container.scrollHeight;
				}
			}
		}

		document.getElementById( 'statusBar' ).textContent = '<?php echo esc_js( __( 'Connected — polling every 5s', 'agent-access' ) ); ?>';
	} catch ( e ) {
		document.getElementById( 'statusBar' ).textContent = '⚠ <?php echo esc_js( __( 'Connection error', 'agent-access' ) ); ?>';
	}
}

// ─── Send ──────────────────────────────────────────────────────────────────

async function sendMessage() {
	const input = document.getElementById( 'msgInput' );
	const btn   = document.getElementById( 'sendBtn' );
	const text  = input.value.trim();
	if ( ! text ) return;

	btn.disabled = true;
	try {
		await api( 'send', {
			method: 'POST',
			body: JSON.stringify( {
				channel:     currentChannel,
				sender:      CONFIG.user,
				sender_type: 'human',
				message:     text,
			} ),
		} );
		input.value       = '';
		input.style.height = 'auto';
		await loadMessages();
	} catch ( e ) {
		alert( '<?php echo esc_js( __( 'Failed to send:', 'agent-access' ) ); ?> ' + e.message );
	}
	btn.disabled = false;
	input.focus();
}

// ─── Channels ──────────────────────────────────────────────────────────────

async function loadChannels() {
	try {
		const data   = await api( 'channels' );
		const select = document.getElementById( 'channelSelect' );
		const channels = data.channels.length ? data.channels : [ 'ops' ];

		select.innerHTML = channels.map( c =>
			`<option value="${ c }" ${ c === currentChannel ? 'selected' : '' }>#${ c }</option>`
		).join( '' );
	} catch ( e ) {}
}

// ─── Events ────────────────────────────────────────────────────────────────

document.getElementById( 'channelSelect' ).addEventListener( 'change', function () {
	currentChannel = this.value;
	lastMessageId  = 0;
	loadMessages();
} );

document.getElementById( 'searchBox' ).addEventListener( 'input', function () {
	clearTimeout( searchTimer );
	searchTimer = setTimeout( () => loadMessages( this.value.trim() ), 300 );
} );

document.getElementById( 'msgInput' ).addEventListener( 'keydown', function ( e ) {
	if ( 'Enter' === e.key && ! e.shiftKey ) {
		e.preventDefault();
		sendMessage();
	}
} );

document.getElementById( 'msgInput' ).addEventListener( 'input', function () {
	this.style.height = 'auto';
	this.style.height = Math.min( this.scrollHeight, 120 ) + 'px';
} );

// ─── Init ──────────────────────────────────────────────────────────────────

loadChannels();
loadMessages();
pollTimer = setInterval( pollNewMessages, CONFIG.pollInterval );
document.getElementById( 'msgInput' ).focus();
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
	 * Used by the dashboard for the stat card.
	 *
	 * @return int
	 */
	public static function get_message_count() {
		$counts = wp_count_posts( 'chat_message' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}
}
