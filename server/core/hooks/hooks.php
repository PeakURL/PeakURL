<?php
/**
 * PeakURL hook and filter registry.
 *
 * Provides a lightweight action/filter system inspired by WordPress so
 * extensibility points can be shared across the application.
 *
 * @package PeakURL\Core\Hooks
 * @since 1.0.2
 */

declare(strict_types=1);

namespace PeakURL\Core\Hooks;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Static hook registry.
 *
 * Provides a lightweight WordPress-style action and filter hook system
 * for PeakURL.
 *
 * ## Mutation Semantics During Dispatch
 * During dispatch (`apply_filters()` or `do_action()`), PeakURL takes a snapshot of
 * all registered priority levels and callback definitions for that hook at the start of
 * execution.
 * - Callbacks added during an active dispatch (whether at the same, earlier, or later
 *   priority) do not execute in the currently running dispatch; they take effect in
 *   subsequent dispatches.
 * - Callbacks removed during dispatch will not be executed if their callback index
 *   has not yet been reached, because each callback is verified via `isset` prior to
 *   invocation.
 * - If a callback is re-registered in-place with modified arguments or definition during
 *   dispatch, the latest definition is used when its turn arrives.
 *
 * @since 1.0.2
 */
class Hooks {

	/**
	 * Registered callbacks keyed by hook name, priority, and unique callback identifier.
	 *
	 * @var array<string, array<int, array<string, array{callback: callable, accepted_args: int}>>>
	 * @since 1.0.2
	 */
	private static array $hooks = array();

	/**
	 * Stack of currently executing hook names.
	 *
	 * @var list<string>
	 * @since 1.8.0
	 */
	private static array $current_stack = array();

	/**
	 * Action execution counters keyed by action hook name.
	 *
	 * @var array<string, int>
	 * @since 1.8.0
	 */
	private static array $did_actions = array();

	/**
	 * Active dispatches stack for mutation tracking.
	 *
	 * @var list<array{hook: string, removed_all: bool, removed_priorities: array<int, bool>, removed: array<string, bool>}>
	 * @since 1.8.0
	 */
	private static array $active_dispatches = array();

	/**
	 * Generate a unique identifier for a callback.
	 *
	 * Supports named functions, closures, static class methods, object methods,
	 * and invokable objects.
	 *
	 * @param callable|string|array $callback Callback to generate ID for.
	 * @return string|null Unique callback identifier, or null if invalid.
	 * @since 1.8.0
	 */
	private static function callback_id( callable|string|array $callback ): ?string {
		if ( is_string( $callback ) ) {
			return ltrim( $callback, '\\' );
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_string( $callback[1] ) ) {
			if ( is_object( $callback[0] ) ) {
				return spl_object_hash( $callback[0] ) . '::' . $callback[1];
			}
			if ( is_string( $callback[0] ) ) {
				return ltrim( $callback[0], '\\' ) . '::' . $callback[1];
			}
		}

		if ( $callback instanceof \Closure ) {
			return spl_object_hash( $callback );
		}

		if ( is_object( $callback ) ) {
			return spl_object_hash( $callback ) . '::__invoke';
		}

		return null;
	}

	/**
	 * Register a callback for a filter or action hook.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback to register.
	 * @param int      $priority      Execution priority.
	 * @param int      $accepted_args Number of accepted callback arguments.
	 * @return bool True if registered, false on error.
	 * @since 1.0.2
	 */
	public static function add(
		string $hook_name,
		callable $callback,
		int $priority = 10,
		int $accepted_args = 1
	): bool {
		$idx = self::callback_id( $callback );
		if ( null === $idx ) {
			return false;
		}

		if ( ! isset( self::$hooks[ $hook_name ] ) ) {
			self::$hooks[ $hook_name ] = array();
		}

		if ( ! isset( self::$hooks[ $hook_name ][ $priority ] ) ) {
			self::$hooks[ $hook_name ][ $priority ] = array();
		}

		self::$hooks[ $hook_name ][ $priority ][ $idx ] = array(
			'callback'      => $callback,
			'accepted_args' => max( 0, $accepted_args ),
		);

		return true;
	}

	/**
	 * Remove a callback from a hook.
	 *
	 * @param string                $hook_name Hook name.
	 * @param callable|string|array $callback  Callback to remove.
	 * @param int                   $priority  Execution priority.
	 * @return bool True if callback was found and removed, false otherwise.
	 * @since 1.8.0
	 */
	public static function remove(
		string $hook_name,
		callable|string|array $callback,
		int $priority = 10
	): bool {
		$idx = self::callback_id( $callback );
		if ( null === $idx ) {
			return false;
		}

		if ( ! isset( self::$hooks[ $hook_name ][ $priority ][ $idx ] ) ) {
			return false;
		}

		unset( self::$hooks[ $hook_name ][ $priority ][ $idx ] );

		foreach ( self::$active_dispatches as &$dispatch ) {
			if ( $dispatch['hook'] === $hook_name ) {
				$dispatch['removed'][ $priority . ':' . $idx ] = true;
			}
		}
		unset( $dispatch );

		if ( empty( self::$hooks[ $hook_name ][ $priority ] ) ) {
			unset( self::$hooks[ $hook_name ][ $priority ] );
		}

		if ( empty( self::$hooks[ $hook_name ] ) ) {
			unset( self::$hooks[ $hook_name ] );
		}

		return true;
	}

	/**
	 * Check if a hook has any callbacks or a specific callback registered.
	 *
	 * @param string                      $hook_name Hook name.
	 * @param callable|string|array|false $callback  Optional callback to check.
	 * @param int|false                   $priority  Optional priority to check.
	 * @return bool|int Priority if callback is registered, false if not, or bool if checking hook existence.
	 * @since 1.8.0
	 */
	public static function has(
		string $hook_name,
		callable|string|array|false $callback = false,
		int|false $priority = false
	): bool|int {
		if ( false === $priority ) {
			if ( false === $callback ) {
				return ! empty( self::$hooks[ $hook_name ] );
			}

			if ( empty( self::$hooks[ $hook_name ] ) ) {
				return false;
			}

			$idx = self::callback_id( $callback );
			if ( null === $idx ) {
				return false;
			}

			foreach ( self::$hooks[ $hook_name ] as $p => $callbacks ) {
				if ( isset( $callbacks[ $idx ] ) ) {
					return (int) $p;
				}
			}

			return false;
		}

		// Priority is specified: return bool.
		if ( false === $callback ) {
			return ! empty( self::$hooks[ $hook_name ][ $priority ] );
		}

		if ( empty( self::$hooks[ $hook_name ][ $priority ] ) ) {
			return false;
		}

		$idx = self::callback_id( $callback );
		if ( null === $idx ) {
			return false;
		}

		return isset( self::$hooks[ $hook_name ][ $priority ][ $idx ] );
	}

	/**
	 * Remove all callbacks for a hook, or all hooks if null.
	 *
	 * @param string|null $hook_name Optional hook name to clear.
	 * @param int|false   $priority  Optional priority to clear.
	 * @return void
	 * @since 1.2.2
	 */
	public static function remove_all( ?string $hook_name = null, int|false $priority = false ): void {
		if ( null === $hook_name ) {
			self::$hooks = array();
			foreach ( self::$active_dispatches as &$dispatch ) {
				$dispatch['removed_all'] = true;
			}
			unset( $dispatch );
			return;
		}

		if ( false === $priority ) {
			unset( self::$hooks[ $hook_name ] );
			foreach ( self::$active_dispatches as &$dispatch ) {
				if ( $dispatch['hook'] === $hook_name ) {
					$dispatch['removed_all'] = true;
				}
			}
			unset( $dispatch );
			return;
		}

		unset( self::$hooks[ $hook_name ][ $priority ] );
		foreach ( self::$active_dispatches as &$dispatch ) {
			if ( $dispatch['hook'] === $hook_name ) {
				$dispatch['removed_priorities'][ $priority ] = true;
			}
		}
		unset( $dispatch );

		if ( empty( self::$hooks[ $hook_name ][ $priority ] ) ) {
			unset( self::$hooks[ $hook_name ][ $priority ] );
		}

		if ( empty( self::$hooks[ $hook_name ] ) ) {
			unset( self::$hooks[ $hook_name ] );
		}
	}

	/**
	 * Reset recorded action execution counters.
	 *
	 * Intended for test suite isolation.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public static function reset_actions(): void {
		self::$did_actions = array();
	}

	/**
	 * Apply all filters for a hook and return the filtered value.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Initial filtered value.
	 * @param mixed  ...$args   Additional hook arguments.
	 * @return mixed
	 * @since 1.0.2
	 */
	public static function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		if ( empty( self::$hooks[ $hook_name ] ) ) {
			return $value;
		}

		self::$current_stack[]     = $hook_name;
		$dispatch_index            = count( self::$active_dispatches );
		self::$active_dispatches[] = array(
			'hook'               => $hook_name,
			'removed_all'        => false,
			'removed_priorities' => array(),
			'removed'            => array(),
		);

		try {
			$snapshot   = self::$hooks[ $hook_name ];
			$priorities = array_keys( $snapshot );
			sort( $priorities, SORT_NUMERIC );

			foreach ( $priorities as $priority ) {
				if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed_all'] ) ) {
					break;
				}

				if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed_priorities'][ $priority ] ) ) {
					continue;
				}

				if ( empty( $snapshot[ $priority ] ) ) {
					continue;
				}

				$callbacks = $snapshot[ $priority ];

				foreach ( $callbacks as $idx => $definition ) {
					if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed_all'] ) ) {
						break 2;
					}

					if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed_priorities'][ $priority ] ) ) {
						break;
					}

					if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed'][ $priority . ':' . $idx ] ) ) {
						continue;
					}

					if ( ! isset( self::$hooks[ $hook_name ][ $priority ][ $idx ] ) ) {
						continue;
					}

					$current_definition = self::$hooks[ $hook_name ][ $priority ][ $idx ];
					$callback_args      = array_merge( array( $value ), $args );
					$callback_args      = array_slice(
						$callback_args,
						0,
						$current_definition['accepted_args']
					);

					$value = call_user_func_array(
						$current_definition['callback'],
						$callback_args
					);
				}
			}
		} finally {
			array_pop( self::$current_stack );
			array_pop( self::$active_dispatches );
		}

		return $value;
	}

	/**
	 * Execute all action callbacks for a hook.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Hook arguments.
	 * @return void
	 * @since 1.0.2
	 */
	public static function do_action( string $hook_name, ...$args ): void {
		self::$did_actions[ $hook_name ] = ( self::$did_actions[ $hook_name ] ?? 0 ) + 1;

		if ( empty( self::$hooks[ $hook_name ] ) ) {
			return;
		}

		self::$current_stack[]     = $hook_name;
		$dispatch_index            = count( self::$active_dispatches );
		self::$active_dispatches[] = array(
			'hook'               => $hook_name,
			'removed_all'        => false,
			'removed_priorities' => array(),
			'removed'            => array(),
		);

		try {
			$snapshot   = self::$hooks[ $hook_name ];
			$priorities = array_keys( $snapshot );
			sort( $priorities, SORT_NUMERIC );

			foreach ( $priorities as $priority ) {
				if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed_all'] ) ) {
					break;
				}

				if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed_priorities'][ $priority ] ) ) {
					continue;
				}

				if ( empty( $snapshot[ $priority ] ) ) {
					continue;
				}

				$callbacks = $snapshot[ $priority ];

				foreach ( $callbacks as $idx => $definition ) {
					if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed_all'] ) ) {
						break 2;
					}

					if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed_priorities'][ $priority ] ) ) {
						break;
					}

					if ( ! empty( self::$active_dispatches[ $dispatch_index ]['removed'][ $priority . ':' . $idx ] ) ) {
						continue;
					}

					if ( ! isset( self::$hooks[ $hook_name ][ $priority ][ $idx ] ) ) {
						continue;
					}

					$current_definition = self::$hooks[ $hook_name ][ $priority ][ $idx ];

					call_user_func_array(
						$current_definition['callback'],
						array_slice( $args, 0, $current_definition['accepted_args'] )
					);
				}
			}
		} finally {
			array_pop( self::$current_stack );
			array_pop( self::$active_dispatches );
		}
	}

	/**
	 * Retrieve the name of the current filter or action hook being executed.
	 *
	 * @return string|false Hook name if executing, false otherwise.
	 * @since 1.8.0
	 */
	public static function current_filter(): string|false {
		if ( empty( self::$current_stack ) ) {
			return false;
		}

		return end( self::$current_stack );
	}

	/**
	 * Retrieve the name of the current action hook being executed.
	 *
	 * @return string|false Hook name if executing, false otherwise.
	 * @since 1.8.0
	 */
	public static function current_action(): string|false {
		return self::current_filter();
	}

	/**
	 * Check if a filter or action hook is currently being executed.
	 *
	 * @param string|null $hook_name Optional hook name to check.
	 * @return bool True if executing, false otherwise.
	 * @since 1.8.0
	 */
	public static function doing_filter( ?string $hook_name = null ): bool {
		if ( null === $hook_name ) {
			return ! empty( self::$current_stack );
		}

		return in_array( $hook_name, self::$current_stack, true );
	}

	/**
	 * Check if an action hook is currently being executed.
	 *
	 * @param string|null $hook_name Optional hook name to check.
	 * @return bool True if executing, false otherwise.
	 * @since 1.8.0
	 */
	public static function doing_action( ?string $hook_name = null ): bool {
		return self::doing_filter( $hook_name );
	}

	/**
	 * Retrieve the number of times an action hook has been fired.
	 *
	 * @param string $hook_name Hook name.
	 * @return int Number of times the action was fired.
	 * @since 1.8.0
	 */
	public static function did_action( string $hook_name ): int {
		return self::$did_actions[ $hook_name ] ?? 0;
	}
}
