<?php
/**
 * PeakURL hook and filter registry.
 *
 * Provides a lightweight action/filter system inspired by WordPress so
 * extensibility points can be shared across the application.
 *
 * @package PeakURL\Includes
 * @since 1.0.2
 */

declare(strict_types=1);

namespace PeakURL\Includes;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Static hook registry.
 *
 * @since 1.0.2
 */
class Hooks {

	/**
	 * Registered callbacks keyed by hook name and priority.
	 *
	 * @var array<string, array<int, array<int, array{callback: callable, accepted_args: int}>>>
	 * @since 1.0.2
	 */
	private static array $hooks = array();

	/**
	 * Register a callback for a filter or action hook.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback to register.
	 * @param int      $priority      Execution priority.
	 * @param int      $accepted_args Number of accepted callback arguments.
	 * @return void
	 * @since 1.0.2
	 */
	public static function add(
		string $hook_name,
		callable $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		if ( ! isset( self::$hooks[ $hook_name ] ) ) {
			self::$hooks[ $hook_name ] = array();
		}

		if ( ! isset( self::$hooks[ $hook_name ][ $priority ] ) ) {
			self::$hooks[ $hook_name ][ $priority ] = array();
		}

		self::$hooks[ $hook_name ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => max( 0, $accepted_args ),
		);
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
	public static function apply_filters( string $hook_name, $value, ...$args ) {
		if ( empty( self::$hooks[ $hook_name ] ) ) {
			return $value;
		}

		ksort( self::$hooks[ $hook_name ] );

		foreach ( self::$hooks[ $hook_name ] as $callbacks ) {
			foreach ( $callbacks as $definition ) {
				$callback_args = array_merge( array( $value ), $args );
				$callback_args = array_slice(
					$callback_args,
					0,
					$definition['accepted_args'],
				);
				$value         = call_user_func_array(
					$definition['callback'],
					$callback_args,
				);
			}
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
		if ( empty( self::$hooks[ $hook_name ] ) ) {
			return;
		}

		ksort( self::$hooks[ $hook_name ] );

		foreach ( self::$hooks[ $hook_name ] as $callbacks ) {
			foreach ( $callbacks as $definition ) {
				call_user_func_array(
					$definition['callback'],
					array_slice( $args, 0, $definition['accepted_args'] ),
				);
			}
		}
	}
}
