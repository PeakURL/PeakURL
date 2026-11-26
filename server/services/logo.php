<?php
/**
 * Shared PeakURL artwork for server-rendered pages.
 *
 * @package PeakURL\Services
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Core\Config\Environment;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/** Logo assets are bundled and do not depend on installation or database state. */
class Logo {

	/**
	 * Embed the bundled vector without an asset request or a dependency on the site's base path.
	 *
	 * @return string SVG data URL suitable for an escaped image src attribute.
	 * @throws \RuntimeException When the bundled artwork is missing.
	 */
	public static function get_data_url(): string {
		$svg = self::read_bundled_svg();

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Render an inline, theme-aware decorative Compression mark derived from the bundled SVG.
	 *
	 * Preserves the bundled asset viewBox and uses semantic CSS custom properties so
	 * manual light/dark toggles stay synchronized.
	 *
	 * @param int    $size       Rendered width and height in pixels.
	 * @param string $class_name Optional CSS class attribute.
	 * @return string Trusted decorative inline SVG markup.
	 * @throws \RuntimeException When the bundled artwork is missing.
	 * @since 1.8.0
	 */
	public static function get_inline_svg( int $size = 34, string $class_name = '' ): string {
		$svg = self::read_bundled_svg();
		$svg = preg_replace( '/<g\b[^>]*\bfill="[^"]+"/', '<g fill="var(--logo-foreground, #126e68)"', $svg, 1 ) ?? $svg;
		$svg = preg_replace( '/(<circle\b[^>]*\bfill=")[^"]+(")/', '$1var(--logo-dot, #17373b)$2', $svg, 1 ) ?? $svg;

		return self::prepare_decorative_svg( $svg, $class_name, $size );
	}

	/**
	 * Normalize a bundled SVG asset for inline decorative rendering.
	 *
	 * @param string   $svg        Raw SVG markup.
	 * @param string   $class_name Optional CSS class attribute.
	 * @param int|null $size       Optional explicit width and height in pixels.
	 * @return string Decorative inline SVG preserving its canonical viewBox.
	 * @since 1.9.0
	 */
	public static function prepare_decorative_svg(
		string $svg,
		string $class_name = '',
		?int $size = null
	): string {
		$svg = preg_replace( '/<title>.*?<\/title>\s*/s', '', $svg ) ?? $svg;
		$svg = preg_replace( '/<style>.*?<\/style>\s*/s', '', $svg ) ?? $svg;

		$view_box = '12 12.5 72 72';
		if ( 1 === preg_match( '/<svg\b[^>]*\bviewBox="([^"]+)"/', $svg, $matches ) ) {
			$view_box = $matches[1];
		}

		$size_attrs = null !== $size ? ' width="' . $size . '" height="' . $size . '"' : '';
		$class_attr = '' !== trim( $class_name )
			? ' class="' . htmlspecialchars( trim( $class_name ), ENT_QUOTES, 'UTF-8' ) . '"'
			: '';

		return preg_replace(
			'/<svg\b[^>]*>/',
			'<svg xmlns="http://www.w3.org/2000/svg"' .
				$size_attrs .
				' viewBox="' . htmlspecialchars( $view_box, ENT_QUOTES, 'UTF-8' ) . '"' .
				' fill="none"' .
				$class_attr .
				' aria-hidden="true" focusable="false">',
			$svg,
			1,
		) ?? $svg;
	}

	/**
	 * Read the bundled vector SVG source.
	 *
	 * @return string Raw SVG document.
	 * @throws \RuntimeException When the bundled artwork is missing.
	 */
	private static function read_bundled_svg(): string {
		$path = Environment::get_instance()->get_logo_path();
		$svg  = is_readable( $path ) ? file_get_contents( $path ) : false;

		if ( false === $svg || '' === trim( $svg ) ) {
			throw new \RuntimeException( 'The bundled PeakURL logo is missing.' );
		}

		return $svg;
	}
}
