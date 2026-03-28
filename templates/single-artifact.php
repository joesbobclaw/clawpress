<?php
/**
 * Template for rendering artifact pages.
 *
 * Artifacts are fully standalone HTML/JS/CSS apps. Scripts and styles have been
 * extracted to the uploads directory by Agent_Access_Artifacts and are enqueued
 * via WordPress. This template outputs a minimal, standalone HTML document.
 *
 * @package Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();
$body    = get_post_meta( $post_id, '_artifact_body', true );
$assets  = get_post_meta( $post_id, '_artifact_assets', true );

// ── Fallback: no processed body ──────────────────────────────────────────────

if ( empty( $body ) ) {
	$raw_html = get_post_meta( $post_id, 'artifact_html', true );

	if ( ! empty( $raw_html ) ) {
		// Legacy mode (pre-1.0 artifacts stored raw HTML).
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $raw_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional legacy passthrough
		exit;
	}

	// No content at all.
	get_header();
	echo '<div class="artifact-empty" style="padding: 2rem; text-align: center;">';
	echo '<h1>' . esc_html( get_the_title() ) . '</h1>';
	echo '<p>' . esc_html__( 'This artifact has no content yet.', 'agent-access' ) . '</p>';
	echo '</div>';
	get_footer();
	exit;
}

// ── Security headers ──────────────────────────────────────────────────────────

header( 'X-Content-Type-Options: nosniff' );
header( 'X-Frame-Options: SAMEORIGIN' );
header( 'Referrer-Policy: strict-origin-when-cross-origin' );

// CSP — scripts are served from the uploads directory so we can be strict.
$upload_dir    = wp_upload_dir();
$artifacts_url = $upload_dir['baseurl'] . '/artifacts/';

$csp_parts = array(
	"default-src 'self'",
	"script-src 'self' {$artifacts_url}",
	"style-src 'self' 'unsafe-inline' {$artifacts_url}",
	"img-src 'self' data: blob:",
	"font-src 'self' data:",
	"connect-src 'self'",
	"frame-src 'none'",
	"frame-ancestors 'self'",
	"form-action 'self'",
	"base-uri 'self'",
);

$csp = implode( '; ', $csp_parts );
/**
 * Filters the Content-Security-Policy header value for artifact pages.
 *
 * @param string $csp     The CSP header value.
 * @param int    $post_id The artifact post ID.
 */
$csp = apply_filters( 'agent_access_artifact_csp', $csp, $post_id );
header( "Content-Security-Policy: {$csp}" );

// ── Allowed HTML for wp_kses ──────────────────────────────────────────────────

$allowed_html = wp_kses_allowed_html( 'post' );

// Canvas.
$allowed_html['canvas'] = array(
	'id'     => true,
	'class'  => true,
	'width'  => true,
	'height' => true,
	'style'  => true,
);

// SVG basics.
$allowed_html['svg'] = array(
	'xmlns'   => true,
	'viewbox' => true,
	'width'   => true,
	'height'  => true,
	'class'   => true,
	'id'      => true,
	'style'   => true,
);
$allowed_html['path'] = array(
	'd'      => true,
	'fill'   => true,
	'stroke' => true,
	'class'  => true,
);

// Form elements.
$allowed_html['input'] = array(
	'type'        => true,
	'id'          => true,
	'class'       => true,
	'name'        => true,
	'value'       => true,
	'placeholder' => true,
	'disabled'    => true,
	'readonly'    => true,
	'checked'     => true,
	'min'         => true,
	'max'         => true,
	'step'        => true,
	'style'       => true,
);
$allowed_html['button'] = array(
	'type'     => true,
	'id'       => true,
	'class'    => true,
	'disabled' => true,
	'style'    => true,
);
$allowed_html['select'] = array(
	'id'    => true,
	'class' => true,
	'name'  => true,
	'style' => true,
);
$allowed_html['option'] = array(
	'value'    => true,
	'selected' => true,
);
$allowed_html['label'] = array(
	'for'   => true,
	'class' => true,
);

// Media.
$allowed_html['video'] = array(
	'src'      => true,
	'controls' => true,
	'autoplay' => true,
	'loop'     => true,
	'muted'    => true,
	'width'    => true,
	'height'   => true,
	'class'    => true,
	'id'       => true,
);
$allowed_html['audio'] = array(
	'src'      => true,
	'controls' => true,
	'autoplay' => true,
	'loop'     => true,
	'class'    => true,
	'id'       => true,
);

// Allow data-* attributes on common interactive elements.
foreach ( array( 'div', 'span', 'button', 'input', 'a', 'canvas' ) as $tag ) {
	if ( isset( $allowed_html[ $tag ] ) ) {
		$allowed_html[ $tag ]['data-*'] = true;
	}
}

/**
 * Filters the allowed HTML tags/attributes for artifact body output.
 *
 * @param array $allowed_html Array of allowed tags and their attributes.
 */
$allowed_html = apply_filters( 'agent_access_artifact_allowed_html', $allowed_html );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( get_the_title() ); ?> — <?php bloginfo( 'name' ); ?></title>
	<?php
	// Output preserved <head> content (meta tags, etc.) — strip the outer <head> wrapper.
	if ( ! empty( $assets['head'] ) ) {
		$head_content = preg_replace( '/<\/?head[^>]*>/i', '', $assets['head'] );
		echo $head_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized by DOMDocument during parse
	}

	// WordPress head hook — outputs our enqueued styles.
	wp_head();
	?>
</head>
<body <?php body_class( 'artifact-page' ); ?>>
	<?php wp_body_open(); ?>

	<div class="artifact-container">
		<?php echo wp_kses( $body, $allowed_html ); ?>
	</div>

	<?php
	// WordPress footer hook — outputs our enqueued scripts.
	wp_footer();
	?>
</body>
</html>
