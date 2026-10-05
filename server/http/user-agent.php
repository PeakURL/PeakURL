<?php
/**
 * Outbound HTTP User-Agent.
 *
 * @package PeakURL\Http
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Http;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Canonical outbound HTTP User-Agent.
 *
 * @since 1.7.2
 */
class UserAgent {

	/**
	 * Format the canonical PeakURL outbound User-Agent.
	 *
	 * @param string $version PeakURL application version.
	 * @return string Canonical outbound User-Agent value.
	 * @since 1.7.2
	 */
	public static function format( string $version ): string {
		$version = (string) preg_replace( '/[\r\n].*$/s', '', $version );
		$version = trim( $version );

		return 'PeakURL/' . $version;
	}
}
