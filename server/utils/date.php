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
	 * Return the current UTC timestamp formatted for MySQL datetime storage.
	 *
	 * @return string MySQL datetime string (Y-m-d H:i:s).
	 * @since 1.0.0
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Convert a datetime string to an ISO 8601 / RFC 3339 timestamp.
	 *
	 * @param string $value Raw datetime string.
	 * @return string ISO 8601 datetime string.
	 * @since 1.0.0
	 */
	public static function to_iso( string $value ): string {
		$clean = trim( $value );
		if ( '' === $clean ) {
			return gmdate( DATE_ATOM );
		}

		$timestamp = strtotime( $clean );
		if ( false === $timestamp ) {
			$timestamp = strtotime( $clean . ' UTC' );
		}

		if ( false === $timestamp ) {
			return gmdate( DATE_ATOM );
		}

		return gmdate( DATE_ATOM, $timestamp );
	}

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
