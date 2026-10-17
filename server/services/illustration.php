<?php
/**
 * Local illustration assets shared by server-rendered pages.
 *
 * @package PeakURL\Services
 */
declare(strict_types=1);
namespace PeakURL\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/** Resolves the same illustration manifest consumed by the React client. */
class Illustration {
	/**
	 * Embed an allowlisted illustration without a network or database dependency.
	 *
	 * @param string $name Canonical manifest key.
	 * @return string SVG data URL, to be escaped at the HTML output boundary.
	 */
	public static function get_data_url( string $name ): string {
		$directory = __DIR__ . '/status-illustrations/';
		$manifest  = json_decode( (string) file_get_contents( $directory . 'manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		$filename  = is_array( $manifest ) ? ( $manifest[ $name ] ?? null ) : null;
		if ( ! is_string( $filename ) || ! preg_match( '/^[a-z][a-z0-9-]*\.svg$/D', $filename ) ) {
			throw new \InvalidArgumentException( 'Unknown status illustration.' );
		}
		$svg = file_get_contents( $directory . $filename );
		if ( false === $svg || '' === trim( $svg ) ) {
			throw new \RuntimeException( 'The bundled status illustration is missing.' );
		}
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
