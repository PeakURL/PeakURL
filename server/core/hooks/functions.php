<?php
/**
 * Global action and filter hook helpers.
 *
 * Exposes WordPress-style runtime hook functions backed by the
 * central Hooks registry in the application core.
 *
 * @package PeakURL\Core\Hooks
 * @since 1.0.2
 */

declare(strict_types=1);

use PeakURL\Core\Hooks\Hooks;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'add_filter' ) ) {
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
}

if ( ! function_exists( 'apply_filters' ) ) {
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
}

if ( ! function_exists( 'add_action' ) ) {
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
}

if ( ! function_exists( 'do_action' ) ) {
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
}
