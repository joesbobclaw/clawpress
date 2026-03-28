<?php
/**
 * Agent Access Artifacts — Custom post type for deploying self-contained HTML/JS/CSS apps.
 *
 * Ported from OC Artifacts v1.0.0. Agents POST raw HTML to the wp/v2/artifacts
 * REST endpoint; this class parses it, extracts scripts/styles to upload-dir files,
 * and renders the artifact on a standalone page at /artifacts/{slug}/.
 *
 * @package Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Artifacts {

	/**
	 * Singleton instance.
	 *
	 * @var Agent_Access_Artifacts|null
	 */
	private static $instance = null;

	/**
	 * Return (or create) the singleton instance.
	 *
	 * @return Agent_Access_Artifacts
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
		add_action( 'init',               array( $this, 'register_post_type' ) );
		add_action( 'rest_api_init',      array( $this, 'register_rest_fields' ) );
		add_filter( 'template_include',   array( $this, 'template_include' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_artifact_assets' ) );
	}

	// ── Post type ─────────────────────────────────────────────────────────────

	/**
	 * Register the artifact CPT and its meta fields.
	 */
	public function register_post_type() {
		register_post_type(
			'artifact',
			array(
				'labels'          => array(
					'name'          => __( 'Artifacts', 'agent-access' ),
					'singular_name' => __( 'Artifact', 'agent-access' ),
					'add_new'       => __( 'Add New Artifact', 'agent-access' ),
					'edit_item'     => __( 'Edit Artifact', 'agent-access' ),
					'view_item'     => __( 'View Artifact', 'agent-access' ),
					'all_items'     => __( 'All Artifacts', 'agent-access' ),
					'not_found'     => __( 'No artifacts found', 'agent-access' ),
				),
				'public'          => true,
				'show_in_rest'    => true,
				'rest_base'       => 'artifacts',
				'supports'        => array( 'title', 'custom-fields' ),
				'has_archive'     => true,
				'rewrite'         => array( 'slug' => 'artifacts' ),
				'menu_icon'       => 'dashicons-art',
				'capability_type' => 'artifact',
				'map_meta_cap'    => true,
			)
		);

		// Publicly exposed via REST.
		register_post_meta(
			'artifact',
			'artifact_html',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => array(
					'schema' => array( 'type' => 'string' ),
				),
				'auth_callback' => function () {
					return current_user_can( 'edit_artifacts' );
				},
			)
		);

		register_post_meta(
			'artifact',
			'artifact_description',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);

		// Internal — not exposed via REST.
		register_post_meta(
			'artifact',
			'_artifact_assets',
			array(
				'type'         => 'object',
				'single'       => true,
				'show_in_rest' => false,
			)
		);

		register_post_meta(
			'artifact',
			'_artifact_body',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => false,
			)
		);
	}

	// ── REST hook ─────────────────────────────────────────────────────────────

	/**
	 * Attach the post-save REST hook for processing artifact HTML.
	 */
	public function register_rest_fields() {
		add_action( 'rest_after_insert_artifact', array( $this, 'process_artifact_html' ), 10, 2 );
	}

	/**
	 * Process and extract assets from artifact HTML after a REST save.
	 *
	 * @param WP_Post         $post    The saved post.
	 * @param WP_REST_Request $request The REST request.
	 */
	public function process_artifact_html( $post, $request ) {
		$raw_html = get_post_meta( $post->ID, 'artifact_html', true );

		if ( empty( $raw_html ) ) {
			return;
		}

		$parsed = $this->parse_html( $raw_html, $post->ID );

		update_post_meta( $post->ID, '_artifact_body',   $parsed['body'] );
		update_post_meta( $post->ID, '_artifact_assets', $parsed['assets'] );
	}

	// ── HTML parser ───────────────────────────────────────────────────────────

	/**
	 * Parse HTML and extract <style> and <script> tags to separate upload-dir files.
	 *
	 * @param string $html    Raw HTML string.
	 * @param int    $post_id Artifact post ID (used for directory naming).
	 * @return array { body: string, assets: array }
	 */
	public function parse_html( $html, $post_id ) {
		$assets = array(
			'styles'  => array(),
			'scripts' => array(),
			'head'    => '',
		);

		// Prepare upload directory.
		$upload_dir   = wp_upload_dir();
		$artifact_dir = $upload_dir['basedir'] . '/artifacts/' . $post_id;
		$artifact_url = $upload_dir['baseurl'] . '/artifacts/' . $post_id;

		if ( ! file_exists( $artifact_dir ) ) {
			wp_mkdir_p( $artifact_dir );
		}

		// Clear old generated files.
		$old_files = glob( $artifact_dir . '/*' );
		if ( $old_files ) {
			foreach ( $old_files as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
		}

		// Parse with DOMDocument.
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );

		// ── Extract <style> tags ──
		$style_index = 0;
		$styles      = $xpath->query( '//style' );

		foreach ( $styles as $style ) {
			$css_content = $style->textContent;
			if ( '' !== trim( $css_content ) ) {
				$filename = "style-{$style_index}.css";
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $artifact_dir . '/' . $filename, $css_content );
				$assets['styles'][] = array(
					'handle' => "artifact-{$post_id}-style-{$style_index}",
					'url'    => $artifact_url . '/' . $filename,
				);
				$style_index++;
			}
			$style->parentNode->removeChild( $style );
		}

		// ── Extract <script> tags ──
		$script_index = 0;
		$scripts      = $xpath->query( '//script' );
		$script_nodes = array();

		foreach ( $scripts as $script ) {
			$script_nodes[] = $script; // Collect first to avoid live-NodeList mutation.
		}

		foreach ( $script_nodes as $script ) {
			$src = $script->getAttribute( 'src' );

			if ( $src ) {
				// External script — keep reference.
				$assets['scripts'][] = array(
					'handle'   => "artifact-{$post_id}-ext-{$script_index}",
					'url'      => $src,
					'external' => true,
				);
			} else {
				$js_content = $script->textContent;
				if ( '' !== trim( $js_content ) ) {
					$filename = "script-{$script_index}.js";
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $artifact_dir . '/' . $filename, $js_content );
					$assets['scripts'][] = array(
						'handle'   => "artifact-{$post_id}-script-{$script_index}",
						'url'      => $artifact_url . '/' . $filename,
						'external' => false,
					);
				}
			}
			$script_index++;
			$script->parentNode->removeChild( $script );
		}

		// ── Extract <head> content ──
		$heads = $xpath->query( '//head' );
		if ( $heads->length > 0 ) {
			$head             = $heads->item( 0 );
			$assets['head']   = $dom->saveHTML( $head );
			$head->parentNode->removeChild( $head );
		}

		// ── Get remaining body HTML ──
		$bodies = $xpath->query( '//body' );
		if ( $bodies->length > 0 ) {
			$body     = $bodies->item( 0 );
			$body_html = '';
			foreach ( $body->childNodes as $child ) {
				$body_html .= $dom->saveHTML( $child );
			}
		} else {
			$body_html = $dom->saveHTML();
		}

		// Clean up XML/HTML wrapper artifacts.
		$body_html = preg_replace( '/^<\?xml[^>]*\?>/', '', $body_html );
		$body_html = preg_replace( '/<\/?html[^>]*>/', '', $body_html );
		$body_html = preg_replace( '/<\/?body[^>]*>/', '', $body_html );

		return array(
			'body'   => trim( $body_html ),
			'assets' => $assets,
		);
	}

	// ── Asset enqueueing ──────────────────────────────────────────────────────

	/**
	 * Enqueue extracted artifact CSS/JS on singular artifact pages.
	 */
	public function enqueue_artifact_assets() {
		if ( ! is_singular( 'artifact' ) ) {
			return;
		}

		$post_id = get_the_ID();
		$assets  = get_post_meta( $post_id, '_artifact_assets', true );

		if ( empty( $assets ) ) {
			return;
		}

		if ( ! empty( $assets['styles'] ) ) {
			foreach ( $assets['styles'] as $style ) {
				$path    = $this->url_to_path( $style['url'] );
				$version = file_exists( $path ) ? filemtime( $path ) : AGENT_ACCESS_VERSION;
				wp_enqueue_style( $style['handle'], $style['url'], array(), $version );
			}
		}

		if ( ! empty( $assets['scripts'] ) ) {
			foreach ( $assets['scripts'] as $script ) {
				if ( ! empty( $script['external'] ) ) {
					$version = null;
				} else {
					$path    = $this->url_to_path( $script['url'] );
					$version = file_exists( $path ) ? filemtime( $path ) : AGENT_ACCESS_VERSION;
				}
				wp_enqueue_script( $script['handle'], $script['url'], array(), $version, true );
			}
		}
	}

	/**
	 * Convert an upload URL to a filesystem path.
	 *
	 * @param string $url File URL.
	 * @return string Filesystem path.
	 */
	private function url_to_path( $url ) {
		$upload_dir = wp_upload_dir();
		return str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
	}

	// ── Template routing ──────────────────────────────────────────────────────

	/**
	 * Route singular artifact requests to the plugin template.
	 *
	 * Theme-provided single-artifact.php takes precedence if present.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function template_include( $template ) {
		if ( is_singular( 'artifact' ) ) {
			$theme_template = locate_template( 'single-artifact.php' );
			if ( $theme_template ) {
				return $theme_template;
			}
			return AGENT_ACCESS_PLUGIN_DIR . 'templates/single-artifact.php';
		}
		return $template;
	}

	// ── Activation helper ─────────────────────────────────────────────────────

	/**
	 * Grant all artifact capabilities to the administrator role.
	 *
	 * Called from the plugin's activation hook.
	 */
	public static function activate() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$caps = array( 'edit', 'edit_others', 'publish', 'read_private', 'delete', 'delete_others', 'edit_published', 'delete_published' );
			foreach ( $caps as $cap ) {
				$admin->add_cap( $cap . '_artifacts' );
			}
		}
		flush_rewrite_rules();
	}

	/**
	 * Grant artifact capabilities to an additional role.
	 *
	 * @param string $role_name WordPress role slug.
	 */
	public static function grant_to_role( $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$caps = array( 'edit', 'edit_others', 'publish', 'read_private', 'delete', 'delete_others', 'edit_published', 'delete_published' );
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap . '_artifacts' );
			}
		}
	}
}
