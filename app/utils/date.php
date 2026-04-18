<?php
/**
 * Date and time helpers.
 *
 * @package PeakURL\Utils
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Utils;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Static date and time helpers.
 *
 * @since 1.0.14
 */
class Date {

	/**
	 * Convert a MySQL datetime string to an RFC 3339 timestamp or null.
	 *
	 * @param string|null $value Datetime string.
	 * @return string|null RFC 3339 datetime or null.
	 * @since 1.0.14
	 */
	public static function mysql_to_rfc3339( ?string $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( DATE_ATOM, $timestamp );
	}
}
