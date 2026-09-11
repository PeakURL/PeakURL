<?php
/**
 * Global string sanitization, escaping, and formatting helper functions.
 *
 * Provides WordPress-compatible string sanitization, URL validation,
 * HTML filtering, and body class helpers.
 *
 * @package PeakURL\Utils
 * @since 1.0.14
 */

declare(strict_types=1);

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Remove trailing forward and backslashes from a string.
	 *
	 * Mirrors WordPress `untrailingslashit()`.
	 *
	 * @param string $value Raw string value.
	 * @return string
	 * @since 1.0.14
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Append one trailing forward slash to a string.
	 *
	 * Mirrors WordPress `trailingslashit()`.
	 *
	 * @param string $value Raw string value.
	 * @return string
	 * @since 1.0.14
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function trailingslashit( string $value ): string {
		return untrailingslashit( $value ) . '/';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitize a string key.
	 *
	 * Mirrors the role of WordPress `sanitize_key()`.
	 *
	 * @param string $key Raw key input.
	 * @return string
	 * @since 1.0.14
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function sanitize_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		$key = preg_replace( '/[^a-z0-9_-]/', '', $key );

		return is_string( $key ) ? $key : '';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Sanitize a string into a URL-safe title slug.
	 *
	 * Mirrors the role of WordPress `sanitize_title()`.
	 *
	 * @param string $title Raw title input.
	 * @return string
	 * @since 1.0.14
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function sanitize_title( string $title ): string {
		$title  = strtolower( trim( $title ) );
		$result = preg_replace( '/[^a-z0-9]+/', '-', $title );
		$title  = is_string( $result ) ? $result : '';

		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Sanitize an email address for storage or comparisons.
	 *
	 * Mirrors the role of WordPress `sanitize_email()`.
	 *
	 * @param string $email Raw email input.
	 * @return string
	 * @since 1.0.14
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function sanitize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Validate an email address and return its sanitized value.
	 *
	 * Mirrors the role of WordPress `is_email()`.
	 *
	 * @param string $email Raw email input.
	 * @return string|false
	 * @since 1.0.14
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function is_email( string $email ): string|false {
		$email = sanitize_email( $email );

		if ( '' === $email ) {
			return false;
		}

		return false === filter_var( $email, FILTER_VALIDATE_EMAIL )
			? false
			: $email;
	}
}

if ( ! function_exists( 'sanitize_body_class' ) ) {
	/**
	 * Normalize a raw body class value into a sanitized class token.
	 *
	 * @param string $class_name Raw class token.
	 * @return string
	 * @since 1.0.11
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
	function sanitize_body_class( string $class_name ): string {
		$sanitized = strtolower( trim( $class_name ) );
		$sanitized = preg_replace( '/[^a-z0-9-]+/', '-', $sanitized );
		$sanitized = is_string( $sanitized ) ? $sanitized : '';
		$sanitized = preg_replace( '/-{2,}/', '-', $sanitized );
		$sanitized = is_string( $sanitized ) ? $sanitized : '';

		return trim( $sanitized, '-' );
	}
}

if ( ! function_exists( 'sanitize_body_class_list' ) ) {
	/**
	 * Normalize body class input into a unique list of sanitized class names.
	 *
	 * @param array|string $css_class Optional body classes.
	 * @return array<int, string>
	 * @since 1.0.11
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
	function sanitize_body_class_list( array|string $css_class = array() ): array {
		$raw_classes = is_array( $css_class )
			? $css_class
			: preg_split( '/\s+/', trim( (string) $css_class ) );
		$raw_classes = is_array( $raw_classes ) ? $raw_classes : array();
		$classes     = array();
		$seen        = array();

		foreach ( $raw_classes as $class_name ) {
			if ( ! is_scalar( $class_name ) ) {
				continue;
			}

			$sanitized = sanitize_body_class( (string) $class_name );

			if ( '' === $sanitized || isset( $seen[ $sanitized ] ) ) {
				continue;
			}

			$seen[ $sanitized ] = true;
			$classes[]          = $sanitized;
		}

		return $classes;
	}
}

if ( ! function_exists( 'get_body_class' ) ) {
	/**
	 * Get the current document body classes.
	 *
	 * Mirrors WordPress `get_body_class()` so runtime code and future plugins
	 * can extend one shared class list from PHP.
	 *
	 * @param array|string         $css_class Optional body classes.
	 * @param array<string, mixed> $context   Optional runtime context passed to filters.
	 * @return array<int, string>
	 * @since 1.0.11
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function get_body_class(
		array|string $css_class = array(),
		array $context = array()
	): array {
		$class_names = sanitize_body_class_list(
			array_merge(
				array( 'peakurl-app' ),
				sanitize_body_class_list( $css_class ),
			)
		);
		$filtered    = apply_filters(
			'body_class',
			$class_names,
			$css_class,
			$context,
		);

		if ( ! is_array( $filtered ) && ! is_string( $filtered ) ) {
			return $class_names;
		}

		return sanitize_body_class_list( $filtered );
	}
}

if ( ! function_exists( 'body_class' ) ) {
	/**
	 * Echo the current document body classes as a `class` attribute.
	 *
	 * Mirrors WordPress `body_class()`.
	 *
	 * @param array|string         $css_class Optional body classes.
	 * @param array<string, mixed> $context   Optional runtime context passed to filters.
	 * @return void
	 * @since 1.0.11
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function body_class(
		array|string $css_class = array(),
		array $context = array()
	): void {
		$class_names = get_body_class( $css_class, $context );

		if ( empty( $class_names ) ) {
			return;
		}

		echo 'class="' .
			htmlspecialchars(
				implode( ' ', $class_names ),
				ENT_QUOTES,
				'UTF-8',
			) .
			'"';
	}
}

if ( ! function_exists( 'sanitize_url' ) ) {
	/**
	 * Sanitize a URL for storage or internal validation.
	 *
	 * Mirrors the role of WordPress `sanitize_url()` with optional protocol
	 * allow-listing and support for root-relative paths when requested.
	 *
	 * @param string             $url            Candidate URL value.
	 * @param array<int, string> $protocols      Allowed URL protocols.
	 * @param bool               $allow_relative Whether a single-slash relative path is allowed.
	 * @return string
	 * @since 1.0.14
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function sanitize_url(
		string $url,
		array $protocols = array( 'http', 'https' ),
		bool $allow_relative = false
	): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( $allow_relative && 0 === strpos( $url, '/' ) ) {
			return 0 === strpos( $url, '//' ) ? '' : $url;
		}

		$sanitized = filter_var( $url, FILTER_SANITIZE_URL );

		if ( ! is_string( $sanitized ) || '' === $sanitized ) {
			return '';
		}

		if ( false === filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$parts = parse_url( $sanitized );

		if (
			! is_array( $parts ) ||
			empty( $parts['scheme'] ) ||
			empty( $parts['host'] )
		) {
			return '';
		}

		$allowed_protocols = array_map( 'strtolower', $protocols );
		$scheme            = strtolower( (string) $parts['scheme'] );

		if ( ! in_array( $scheme, $allowed_protocols, true ) ) {
			return '';
		}

		return $sanitized;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escape a URL for HTML output.
	 *
	 * Mirrors WordPress `esc_url()`.
	 *
	 * @param string             $url            Candidate URL value.
	 * @param array<int, string> $protocols      Allowed URL protocols.
	 * @param bool               $allow_relative Whether a single-slash relative path is allowed.
	 * @return string
	 * @since 1.0.14
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function esc_url(
		string $url,
		array $protocols = array( 'http', 'https' ),
		bool $allow_relative = false
	): string {
		return htmlspecialchars(
			sanitize_url( $url, $protocols, $allow_relative ),
			ENT_QUOTES,
			'UTF-8',
		);
	}
}

if ( ! function_exists( 'PeakURL_sanitize_html' ) ) {
	/**
	 * Sanitize HTML to allow only specified tags and attributes.
	 *
	 * This provides a safe way to output HTML that includes translations or
	 * user-provided links, mirroring the role of WordPress `wp_kses()`.
	 *
	 * @param string               $html        Raw HTML to sanitize.
	 * @param array<string, array> $allowed_tags Allowed tags and their attributes.
	 * @return string
	 * @since 1.2.4
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function PeakURL_sanitize_html( string $html, array $allowed_tags ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$tags_list = '';
		foreach ( array_keys( $allowed_tags ) as $tag ) {
			$tags_list .= '<' . $tag . '>';
		}

		$sanitized = strip_tags( $html, $tags_list );

		foreach ( $allowed_tags as $tag => $attributes ) {
			$pattern   = '/<' . $tag . '\b([^>]*)>/i';
			$sanitized = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $tag, $attributes ) {
					$attr_string = $matches[1];
					$new_attrs   = '';

					foreach ( $attributes as $attr_name => $true ) {
						if ( preg_match( '/\b' . $attr_name . '=(["\'])(.*?)\1/i', $attr_string, $attr_matches ) ) {
							$val = $attr_matches[2];
							if ( 'href' === $attr_name || 'src' === $attr_name ) {
								$val = sanitize_url( $val );
							}
							$new_attrs .= ' ' . $attr_name . '="' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '"';
						}
					}

					return '<' . $tag . $new_attrs . '>';
				},
				$sanitized
			);
		}

		return $sanitized;
	}
}

if ( ! function_exists( 'peakurl_json_encode' ) ) {
	/**
	 * Encode a variable into JSON with safe defaults.
	 *
	 * @param mixed $data    Variable (usually an array or object) to encode as JSON.
	 * @param int   $options Optional. Options to be passed to json_encode(). Default 0.
	 * @param int   $depth   Optional. Maximum depth to walk through $data. Default 512.
	 * @return string|false The JSON-encoded string, or false if encoding failed.
	 * @since 1.2.4
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function peakurl_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}
