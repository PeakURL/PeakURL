<?php
/**
 * Global runtime helper functions.
 *
 * @package PeakURL\Includes
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Connection;
use PeakURL\Includes\Hooks;
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Services\Crypto;
use PeakURL\Services\Mailer;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

/**
 * Send an email through the active PeakURL transport.
 *
 * Mirrors the role of WordPress `wp_mail()` while keeping PeakURL's
 * transport settings behind one public helper.
 *
 * @param string               $to_email Recipient email address.
 * @param string               $subject  Email subject line.
 * @param string               $message  Primary message body.
 * @param array<string, mixed> $args     Optional send arguments.
 * @return bool
 *
 * @throws \RuntimeException When PeakURL cannot deliver the email.
 * @since 1.0.0
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function PeakURL_Mail(
	string $to_email,
	string $subject,
	string $message,
	array $args = array()
): bool {
	$config     = RuntimeConfig::bootstrap( ABSPATH . 'app' );
	$connection = new Connection( $config );
	$settings   = new SettingsApi( new PeakURL_DB( $connection ) );
	$crypto     = new Crypto( $config );
	$mailer     = new Mailer( $config, $settings, $crypto );
	$to_name    = trim( (string) ( $args['to_name'] ?? '' ) );
	$text       = array_key_exists( 'text_body', $args )
		? (string) $args['text_body']
		: trim( html_entity_decode( strip_tags( $message ), ENT_QUOTES, 'UTF-8' ) );
	$html       = ! empty( $args['html'] )
		? $message
		: nl2br( htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ) );

	$mailer->send( $to_email, $to_name, $subject, $html, $text );

	return true;
}

/**
 * Get the configured PeakURL site name.
 *
 * Mirrors the role of WordPress site helper functions so runtime code can
 * resolve the current site name without instantiating service classes.
 *
 * @return string
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_site_name(): string {
	$config     = RuntimeConfig::bootstrap( ABSPATH . 'app' );
	$connection = new Connection( $config );
	$settings   = new SettingsApi( new PeakURL_DB( $connection ) );
	$site_name  = trim( (string) $settings->get_option( 'site_name' ) );
	$site_name  = '' !== $site_name ? $site_name : 'PeakURL';

	return (string) apply_filters(
		'site_name',
		$site_name,
		$settings,
		$config,
	);
}

/**
 * Get the configured PeakURL site URL.
 *
 * Mirrors the role of WordPress `get_site_url()` and accepts an optional
 * path plus a limited scheme override.
 *
 * @param string      $path   Optional path relative to the site URL.
 * @param string|null $scheme Optional scheme override.
 * @return string
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_site_url( string $path = '', ?string $scheme = null ): string {
	$config     = RuntimeConfig::bootstrap( ABSPATH . 'app' );
	$connection = new Connection( $config );
	$settings   = new SettingsApi( new PeakURL_DB( $connection ) );
	$site_url   = trim( (string) $settings->get_option( 'site_url' ) );

	if ( '' === $site_url ) {
		$site_url = trim( (string) ( $config['SITE_URL'] ?? '' ) );
	}

	$site_url = rtrim( $site_url, '/' );

	if ( null !== $scheme ) {
		$normalized_scheme = strtolower( trim( $scheme ) );

		if ( in_array( $normalized_scheme, array( 'http', 'https' ), true ) ) {
			$parts = parse_url( $site_url );

			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$site_url = $normalized_scheme . '://' . $parts['host'];

				if ( ! empty( $parts['port'] ) ) {
					$site_url .= ':' . (int) $parts['port'];
				}

				if ( ! empty( $parts['path'] ) ) {
					$site_url .= rtrim( (string) $parts['path'], '/' );
				}
			}
		} elseif ( 'relative' === $normalized_scheme ) {
			$path_only = (string) parse_url( $site_url, PHP_URL_PATH );
			$site_url  = '' !== $path_only ? rtrim( $path_only, '/' ) : '';
		}
	}

	if ( '' !== $path ) {
		$site_url .= '/' . ltrim( $path, '/' );
	}

	return (string) apply_filters(
		'site_url',
		$site_url,
		$path,
		$scheme,
	);
}

/**
 * Get the configured PeakURL site URL.
 *
 * Mirrors the role of WordPress `site_url()` as a wrapper around
 * `get_site_url()`.
 *
 * @param string      $path   Optional path relative to the site URL.
 * @param string|null $scheme Optional scheme override.
 * @return string
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function site_url( string $path = '', ?string $scheme = null ): string {
	return get_site_url( $path, $scheme );
}

/**
 * Build a human-readable display name from a user row.
 *
 * @param array<string, mixed> $user User database row.
 * @return string
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_user_display_name( array $user ): string {
	$full_name    = trim(
		(string) ( $user['first_name'] ?? '' ) . ' ' . (string) ( $user['last_name'] ?? '' )
	);
	$display_name = '' !== $full_name
		? $full_name
		: trim( (string) ( $user['username'] ?? '' ) );
	$display_name = '' !== $display_name ? $display_name : 'there';

	return (string) apply_filters(
		'user_display_name',
		$display_name,
		$user,
	);
}

/**
 * Register a filter callback.
 *
 * Mirrors the role of WordPress `add_filter()` for PeakURL runtime hooks.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback to register.
 * @param int      $priority      Execution priority.
 * @param int      $accepted_args Number of accepted callback arguments.
 * @return void
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function add_filter(
	string $hook_name,
	callable $callback,
	int $priority = 10,
	int $accepted_args = 1
): void {
	Hooks::add( $hook_name, $callback, $priority, $accepted_args );
}

/**
 * Apply filters to a value.
 *
 * Mirrors the role of WordPress `apply_filters()` for PeakURL runtime hooks.
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value     Initial filtered value.
 * @param mixed  ...$args   Additional hook arguments.
 * @return mixed
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function apply_filters( string $hook_name, $value, ...$args ) {
	return Hooks::apply_filters( $hook_name, $value, ...$args );
}

/**
 * Register an action callback.
 *
 * Mirrors the role of WordPress `add_action()` for PeakURL runtime hooks.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback to register.
 * @param int      $priority      Execution priority.
 * @param int      $accepted_args Number of accepted callback arguments.
 * @return void
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function add_action(
	string $hook_name,
	callable $callback,
	int $priority = 10,
	int $accepted_args = 1
): void {
	Hooks::add( $hook_name, $callback, $priority, $accepted_args );
}

/**
 * Execute action callbacks for a hook.
 *
 * Mirrors the role of WordPress `do_action()` for PeakURL runtime hooks.
 *
 * @param string $hook_name Hook name.
 * @param mixed  ...$args   Hook arguments.
 * @return void
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function do_action( string $hook_name, ...$args ): void {
	Hooks::do_action( $hook_name, ...$args );
}
