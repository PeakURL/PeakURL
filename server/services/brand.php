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
