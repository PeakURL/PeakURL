<?php
/**
 * String helpers compatible with PHP 7.4+.
 *
 * Provides polyfills for common string inspection methods that became
 * native in PHP 8.0 (str_starts_with, str_ends_with, str_contains).
 *
 * @package PeakURL\Bootstrap
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Static string utility helpers.
 *
 * Each method mirrors its PHP 8.0 built-in counterpart so the rest of the
 * codebase can use a single call style regardless of runtime version.
 *
 * @since 1.0.0
 */
class String_Utils {

	/**
	 * Check whether a string begins with the given prefix.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The prefix to look for.
	 * @return bool True when $haystack starts with $needle.
	 * @since 1.0.0
	 */
	public static function starts_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}

		return 0 === strpos( $haystack, $needle );
	}

	/**
	 * Check whether a string ends with the given suffix.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The suffix to look for.
	 * @return bool True when $haystack ends with $needle.
	 * @since 1.0.0
	 */
	public static function ends_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}

		if ( strlen( $needle ) > strlen( $haystack ) ) {
			return false;
		}

		return 0 === substr_compare( $haystack, $needle, -strlen( $needle ) );
	}

	/**
	 * Check whether a string contains the given substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to look for.
	 * @return bool True when $haystack contains $needle.
	 * @since 1.0.0
	 */
	public static function contains( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}

		return false !== strpos( $haystack, $needle );
	}
}
