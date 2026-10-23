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
	 * @return bool True if registered, false on error.
	 * @since 1.0.2
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function add_filter(
		string $hook_name,
		callable $callback,
		int $priority = 10,
		int $accepted_args = 1
	): bool {
		return Hooks::add( $hook_name, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Remove a callback from a filter hook.
	 *
	 * Mirrors the role of WordPress `remove_filter()`.
	 *
	 * @param string                $hook_name Hook name.
	 * @param callable|string|array $callback  Callback to remove.
	 * @param int                   $priority  Execution priority.
	 * @return bool True if callback was found and removed, false otherwise.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function remove_filter(
		string $hook_name,
		callable|string|array $callback,
		int $priority = 10
	): bool {
		return Hooks::remove( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Check if a filter has any callbacks or a specific callback registered.
	 *
	 * Mirrors the role of WordPress `has_filter()`.
	 *
	 * @param string                      $hook_name Hook name.
	 * @param callable|string|array|false $callback  Optional callback to check.
	 * @param int|false                   $priority  Optional priority to check.
	 * @return bool|int Priority if callback is registered, false if not, or bool if checking hook existence.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function has_filter(
		string $hook_name,
		callable|string|array|false $callback = false,
		int|false $priority = false
	): bool|int {
		return Hooks::has( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	/**
	 * Remove all registered callbacks for a filter hook.
	 *
	 * Mirrors the role of WordPress `remove_all_filters()`.
	 *
	 * @param string    $hook_name Hook name to clear.
	 * @param int|false $priority  Optional priority to clear.
	 * @return bool
	 * @since 1.2.2
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function remove_all_filters( string $hook_name, int|false $priority = false ): bool {
		Hooks::remove_all( $hook_name, $priority );
		return true;
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
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
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
	 * @return bool True if registered, false on error.
	 * @since 1.0.2
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function add_action(
		string $hook_name,
		callable $callback,
		int $priority = 10,
		int $accepted_args = 1
	): bool {
		return Hooks::add( $hook_name, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Remove a callback from an action hook.
	 *
	 * Mirrors the role of WordPress `remove_action()`.
	 *
	 * @param string                $hook_name Hook name.
	 * @param callable|string|array $callback  Callback to remove.
	 * @param int                   $priority  Execution priority.
	 * @return bool True if callback was found and removed, false otherwise.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function remove_action(
		string $hook_name,
		callable|string|array $callback,
		int $priority = 10
	): bool {
		return Hooks::remove( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Check if an action has any callbacks or a specific callback registered.
	 *
	 * Mirrors the role of WordPress `has_action()`.
	 *
	 * @param string                      $hook_name Hook name.
	 * @param callable|string|array|false $callback  Optional callback to check.
	 * @param int|false                   $priority  Optional priority to check.
	 * @return bool|int Priority if callback is registered, false if not, or bool if checking hook existence.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function has_action(
		string $hook_name,
		callable|string|array|false $callback = false,
		int|false $priority = false
	): bool|int {
		return Hooks::has( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'remove_all_actions' ) ) {
	/**
	 * Remove all registered callbacks for an action hook.
	 *
	 * Mirrors the role of WordPress `remove_all_actions()`.
	 *
	 * @param string    $hook_name Hook name to clear.
	 * @param int|false $priority  Optional priority to clear.
	 * @return bool
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function remove_all_actions( string $hook_name, int|false $priority = false ): bool {
		return remove_all_filters( $hook_name, $priority );
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

if ( ! function_exists( 'current_filter' ) ) {
	/**
	 * Retrieve the name of the current filter or action hook being executed.
	 *
	 * Mirrors the role of WordPress `current_filter()`.
	 *
	 * @return string|false Hook name if executing, false otherwise.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function current_filter(): string|false {
		return Hooks::current_filter();
	}
}

if ( ! function_exists( 'current_action' ) ) {
	/**
	 * Retrieve the name of the current action hook being executed.
	 *
	 * Mirrors the role of WordPress `current_action()`.
	 *
	 * @return string|false Hook name if executing, false otherwise.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function current_action(): string|false {
		return Hooks::current_action();
	}
}

if ( ! function_exists( 'doing_filter' ) ) {
	/**
	 * Check if a filter or action hook is currently being executed.
	 *
	 * Mirrors the role of WordPress `doing_filter()`.
	 *
	 * @param string|null $hook_name Optional hook name to check.
	 * @return bool True if executing, false otherwise.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function doing_filter( ?string $hook_name = null ): bool {
		return Hooks::doing_filter( $hook_name );
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	/**
	 * Check if an action hook is currently being executed.
	 *
	 * Mirrors the role of WordPress `doing_action()`.
	 *
	 * @param string|null $hook_name Optional hook name to check.
	 * @return bool True if executing, false otherwise.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function doing_action( ?string $hook_name = null ): bool {
		return Hooks::doing_action( $hook_name );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	/**
	 * Retrieve the number of times an action hook has been fired.
	 *
	 * Mirrors the role of WordPress `did_action()`.
	 *
	 * @param string $hook_name Hook name.
	 * @return int Number of times the action was fired.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function did_action( string $hook_name ): int {
		return Hooks::did_action( $hook_name );
	}
}
