<?php
/**
 * Canonical product copy and reusable public attribution markup.
 *
 * @package PeakURL\Services
 */

declare(strict_types=1);

namespace PeakURL\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/** Branding remains available without database or installation state. */
class Brand {

	/**
	 * Read the same tagline used by the React app.
	 *
	 * @return string Localized product tagline.
	 */
	public static function get_tagline(): string {
		$data = json_decode( (string) file_get_contents( __DIR__ . '/brand.json' ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || ! is_string( $data['tagline'] ?? null ) ) {
			throw new \RuntimeException( 'The bundled PeakURL brand metadata is invalid.' );
		}

		// The catalog builder extracts this message from brand.json.
		return function_exists( 'get_i18n_service' ) ? get_i18n_service()->translate( $data['tagline'] ) : $data['tagline'];
	}

	/**
	 * Render the shared attribution badge with locally bundled artwork.
	 *
	 * @return string Escaped, accessible badge markup.
	 */
	public static function get_powered_by_badge(): string {
		$logo     = Logo::get_inline_svg( 20, 'powered-by-mark' );
		$wordmark = self::get_wordmark_svg( 'powered-by-wordmark' );
		$label    = htmlspecialchars( function_exists( '__' ) ? __( 'Powered by', 'peakurl' ) : 'Powered by', ENT_QUOTES, 'UTF-8' );

		return '<a class="powered-by" href="https://peakurl.org?utm_source=peakurl_public&amp;utm_medium=public_page&amp;utm_campaign=powered_by" target="_blank" rel="noopener noreferrer"><span>' . $label . '</span><span class="powered-by-brand">' . $logo . $wordmark . '<span class="powered-by-sr">PeakURL</span></span></a>';
	}

	/**
	 * Render an accessible light/dark mode toggle button for server-rendered public pages.
	 *
	 * @param string $id Optional DOM id attribute.
	 * @return string Escaped button markup with Sun/Moon Lucide icons.
	 * @since 1.9.0
	 */
	public static function get_theme_toggle( string $id = 'status-theme-toggle' ): string {
		$label = htmlspecialchars( function_exists( '__' ) ? __( 'Toggle theme', 'peakurl' ) : 'Toggle theme', ENT_QUOTES, 'UTF-8' );
		$id    = '' !== trim( $id ) ? ' id="' . htmlspecialchars( trim( $id ), ENT_QUOTES, 'UTF-8' ) . '"' : '';

		// Lucide Sun/Moon geometry matches the React theme toggle; notices ship in assets/LICENSE-Lucide.txt.
		$sun_icon  = '<svg class="status-theme-icon-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
		$moon_icon = '<svg class="status-theme-icon-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/></svg>';

		return '<button' . $id . ' class="status-theme-toggle" type="button" aria-label="' . $label . '" aria-pressed="false" data-theme-toggle>' . $sun_icon . $moon_icon . '</button>';
	}

	/**
	 * Return badge CSS for server-rendered documents.
	 *
	 * @return string Shared stylesheet contents.
	 */
	public static function get_badge_styles(): string {
		return (string) file_get_contents( __DIR__ . '/powered-by.css' );
	}

	/**
	 * Render the canonical vector Plus Jakarta Sans Bold (700) PeakURL wordmark.
	 *
	 * Falls back to a plain span if the bundled vector file is unavailable during
	 * partial deployments.
	 *
	 * @param string $class_name Optional CSS class attribute.
	 * @return string Trusted inline SVG wordmark or escaped fallback span.
	 * @since 1.9.0
	 */
	public static function get_wordmark_svg( string $class_name = '' ): string {
		$path = \PeakURL\Core\Config\Environment::get_instance()->get_wordmark_path();
		$svg  = is_readable( $path ) ? file_get_contents( $path ) : false;

		if ( false === $svg || '' === trim( $svg ) ) {
			$class_attr = '' !== trim( $class_name )
				? ' class="' . htmlspecialchars( trim( $class_name ), ENT_QUOTES, 'UTF-8' ) . '"'
				: '';
			return '<span' . $class_attr . '>PeakURL</span>';
		}

		return Logo::prepare_decorative_svg( $svg, $class_name );
	}
}
