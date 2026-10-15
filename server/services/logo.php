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
		$path = Environment::get_instance()->get_logo_path();
		$svg  = is_readable( $path ) ? file_get_contents( $path ) : false;

		if ( false === $svg || '' === trim( $svg ) ) {
			throw new \RuntimeException( 'The bundled PeakURL logo is missing.' );
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
