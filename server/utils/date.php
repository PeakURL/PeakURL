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
	 * MySQL timestamps in PeakURL are stored in UTC. If the raw value lacks an
	 * explicit timezone offset, it is parsed as UTC to avoid local timezone skew.
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

		try {
			$date_time = new \DateTimeImmutable( $clean, new \DateTimeZone( 'UTC' ) );
			return $date_time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM );
		} catch ( \Throwable $exception ) {
			return gmdate( DATE_ATOM );
		}
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

		try {
			$date_time = new \DateTimeImmutable( trim( $value ), new \DateTimeZone( 'UTC' ) );
			return $date_time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM );
		} catch ( \Throwable $exception ) {
			return null;
		}
	}
}
