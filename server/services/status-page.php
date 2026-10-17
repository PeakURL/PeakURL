<?php
/**
 * Branded public status pages, independent of database and dashboard assets.
 *
 * @package PeakURL\Services
 */

declare(strict_types=1);

namespace PeakURL\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/** Shared document for missing links, access checks, maintenance, and runtime errors. */
class StatusPage {

	/**
	 * Render an escaped status message with an optional local recovery action.
	 *
	 * @param string $title        Page heading.
	 * @param string $description  Visitor-facing explanation.
	 * @param string $label        Status or HTTP code.
	 * @param string $note         Recovery guidance.
	 * @param string $action_url   Local, root-relative recovery URL.
	 * @param string $action_label Recovery action label.
	 * @param string $support_html Trusted, escaped supporting markup.
	 * @param string|null $illustration Semantic illustration from the shared manifest.
	 * @return string Complete HTML document.
	 */
	public static function render(
		string $title,
		string $description,
		string $label = '',
		string $note = '',
		string $action_url = '',
		string $action_label = '',
		string $support_html = '',
		?string $illustration = null
	): string {
		$content = '<p class="status-eyebrow">' . self::escape( $label ) . '</p>' .
			'<h1 id="page-heading" class="status-title">' . self::escape( $title ) . '</h1>' .
			'<p class="status-description">' . self::escape( $description ) . '</p>';

		// Only local URLs are accepted; never turn untrusted request data into a redirect link.
		if ( preg_match( '#^/(?!/)[^\r\n\\\\]*$#', $action_url ) && '' !== $action_label ) {
			$content .= '<div class="status-actions"><a class="status-button" href="' . self::escape( $action_url ) . '">' . self::escape( $action_label ) . '</a></div>';
		}

		if ( '' !== $note ) {
			$content .= '<p class="status-note">' . self::escape( $note ) . '</p>';
		}

		return self::render_document( $title, $content, array(), $support_html, $illustration );
	}

	/**
	 * Render a public HTTP error without disclosing runtime diagnostics by default.
	 *
	 * @param int    $status        Original HTTP status.
	 * @param string $base_path     Installation URL prefix.
	 * @param string $debug_message Explicitly permitted development diagnostics.
	 * @return string Complete HTML document.
	 */
	public static function render_http_error( int $status, string $base_path = '', string $debug_message = '' ): string {
		if ( 404 === $status ) {
			$illustration = 'missing-page';
			$title        = __( 'Page not found', 'peakurl' );
			$description  = __( 'We couldn’t find the page you’re looking for. Check the address, or contact the person who shared it with you.', 'peakurl' );
		} elseif ( 403 === $status ) {
			$illustration = 'forbidden';
			$title        = __( 'Access to this page is restricted', 'peakurl' );
			$description  = __( 'You don’t have permission to open this page. Contact the site administrator if you think you should have access.', 'peakurl' );
		} elseif ( 429 === $status ) {
			$illustration = 'rate-limit';
			$title        = __( 'Please wait a moment', 'peakurl' );
			$description  = __( 'Too many requests have been made. Give it a moment, then try again.', 'peakurl' );
		} elseif ( $status >= 500 ) {
			$illustration = 'server-error';
			$title        = __( 'Unable to complete your request', 'peakurl' );
			$description  = __( 'PeakURL encountered a server error. Please try again. If the problem persists, contact the site administrator.', 'peakurl' );
		} else {
			$illustration = 'request-error';
			$title        = __( 'Unable to open this page', 'peakurl' );
			$description  = __( 'This request couldn’t be completed. Check the address, or contact the site administrator for help.', 'peakurl' );
		}

		$details = '';
		if ( '' !== $debug_message ) {
			$details = '<section class="status-support"><h2 class="status-support-title">' . self::escape( __( 'Error details', 'peakurl' ) ) . '</h2><p>' . self::escape( $debug_message ) . '</p></section>';
		}

		return self::render(
			$title,
			$description,
			/* translators: %s: HTTP status code. */
			sprintf( __( 'Error %s', 'peakurl' ), (string) $status ),
			'',
			rtrim( $base_path, '/' ) . '/',
			__( 'Go to homepage', 'peakurl' ),
			$details,
			$illustration,
		);
	}

	/**
	 * Render trusted, already-escaped content inside the common public document.
	 *
	 * @param string                $title        Browser page title.
	 * @param string                $content_html Trusted HTML, including any access-check forms.
	 * @param array<string, string> $metadata     Optional language, direction, and generator version.
	 * @param string                $support_html Trusted, escaped supporting markup.
	 * @param string|null           $illustration Semantic illustration from the shared manifest.
	 * @param bool                  $compact      Keep access checks compact and near the header.
	 * @return string Complete HTML document with inline assets for offline/error recovery.
	 */
	public static function render_document( string $title, string $content_html, array $metadata = array(), string $support_html = '', ?string $illustration = null, bool $compact = false ): string {
		$title          = self::escape( $title );
		$language       = self::escape( $metadata['htmlLang'] ?? ( function_exists( 'get_html_lang_attribute' ) ? get_html_lang_attribute() : 'en' ) );
		$direction      = 'rtl' === ( $metadata['textDirection'] ?? ( function_exists( 'get_text_direction' ) ? get_text_direction() : 'ltr' ) ) ? 'rtl' : 'ltr';
		$generator      = function_exists( 'get_generator_tag' ) ? get_generator_tag( $metadata['version'] ?? null ) : '';
		$logo           = self::escape( Logo::get_data_url() );
		$is_compact     = $compact || null === $illustration;
		$page_class     = $is_compact ? 'status-page status-page-compact' : 'status-page';
		$artwork_html   = self::format_illustration( $illustration, $is_compact );
		$access_artwork = $is_compact ? $artwork_html : '';
		$hero_artwork   = $is_compact ? '' : $artwork_html;
		$styles         = file_get_contents( __DIR__ . '/status-page.css' ) . file_get_contents( __DIR__ . '/public-link-forms.css' ) . Brand::get_badge_styles();
		$powered        = Brand::get_powered_by_badge();
		$tagline        = self::escape( Brand::get_tagline() );

		return <<<HTML
<!doctype html>
<html lang="{$language}" dir="{$direction}" data-status-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
{$generator}
<title>{$title} — PeakURL</title>
<style>{$styles}</style>
<script>try{const theme=localStorage.getItem('theme');if(theme==='light'||theme==='dark')document.documentElement.dataset.statusTheme=theme;}catch{}</script>
</head>
<body class="{$page_class}">
<div class="status-container">
<header class="status-header"><div class="status-brand"><img src="{$logo}" alt="" width="40" height="40"><span>PeakURL</span></div><span class="status-tagline">{$tagline}</span></header>
<main class="status-main"><section class="status-hero"><div class="status-copy">{$access_artwork}{$content_html}</div>{$hero_artwork}</section>{$support_html}</main>
<footer class="status-footer">{$powered}</footer>
</div>
</body>
</html>
HTML;
	}

	/**
	 * Embed semantic artwork without an external request or database dependency.
	 *
	 * @param string|null $name    Canonical illustration name.
	 * @param bool        $compact Whether this is a compact access-check layout.
	 * @return string Decorative, escaped image markup.
	 */
	private static function format_illustration( ?string $name, bool $compact ): string {
		if ( null === $name ) {
			return '';
		}
		$source     = self::escape( Illustration::get_data_url( $name ) );
		$class_name = $compact ? 'status-access-artwork' : 'status-artwork';
		return '<img class="' . $class_name . '" data-status-illustration="' . self::escape( $name ) . '" src="' . $source . '" alt="" aria-hidden="true" draggable="false" oncontextmenu="return false" width="360" height="280">';
	}

	/**
	 * Escape plain text for HTML attributes and content.
	 *
	 * @param string $value Untrusted text.
	 * @return string Escaped text.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}
