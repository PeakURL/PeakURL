<?php
/**
 * Unit tests for PeakURL core Hooks system.
 *
 * @package PeakURL\Tests\Unit\Hooks
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Hooks\Hooks;
use RuntimeException;

/**
 * HooksSystemTest — tests the Hooks registry, actions, filters, priority, and argument binding.
 */
class HooksSystemTest extends TestCase {

	/**
	 * Static log for static callback testing.
	 *
	 * @var array<string>
	 */
	public static array $captured_static = array();

	/**
	 * Instance log for object callback testing.
	 *
	 * @var array<string>
	 */
	public array $captured_instance = array();

	protected function setUp(): void {
		parent::setUp();
		self::$captured_static   = array();
		$this->captured_instance = array();
		Hooks::remove_all();
		Hooks::reset_actions();
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	/**
	 * Static callback helper.
	 *
	 * @param string $val Input string.
	 * @return string
	 */
	public static function sample_static_callback( string $val ): string {
		self::$captured_static[] = $val;
		return $val . '_static';
	}

	/**
	 * Instance callback helper.
	 *
	 * @param string $val Input string.
	 * @return string
	 */
	public function sample_instance_callback( string $val ): string {
		$this->captured_instance[] = $val;
		return $val . '_instance';
	}

	/**
	 * Test that actions execute with accurate argument count and values.
	 */
	public function test_action_execution_and_argument_passing(): void {
		$captured = array();

		add_action(
			'test_action_event',
			function ( string $first, int $second, array $third ) use ( &$captured ): void {
				$captured = array(
					'first'  => $first,
					'second' => $second,
					'third'  => $third,
				);
			},
			10,
			3
		);

		do_action( 'test_action_event', 'hello', 42, array( 'key' => 'val' ) );

		$this->assertSame(
			array(
				'first'  => 'hello',
				'second' => 42,
				'third'  => array( 'key' => 'val' ),
			),
			$captured
		);
	}

	/**
	 * Test that action callbacks only receive up to accepted_args arguments.
	 */
	public function test_action_accepted_args_slicing(): void {
		$received_args = array();

		add_action(
			'test_action_sliced',
			function () use ( &$received_args ): void {
				$received_args = func_get_args();
			},
			10,
			1
		);

		do_action( 'test_action_sliced', 'arg1', 'arg2', 'arg3' );

		$this->assertSame( array( 'arg1' ), $received_args );
	}

	/**
	 * Test filters modify values sequentially across priorities.
	 */
	public function test_filter_chaining_and_transformation(): void {
		add_filter(
			'test_transform_value',
			static function ( string $val, string $suffix ): string {
				return $val . '_first_' . $suffix;
			},
			10,
			2
		);

		add_filter(
			'test_transform_value',
			static function ( string $val, string $suffix ): string {
				return strtoupper( $val ) . '!' . $suffix;
			},
			20,
			2
		);

		$result = apply_filters( 'test_transform_value', 'seed', 'end' );

		$this->assertSame( 'SEED_FIRST_END!end', $result );
	}

	/**
	 * Test callbacks execute strictly in ascending priority order.
	 */
	public function test_priority_ordering_execution(): void {
		$order = array();

		add_action(
			'test_priority_hook',
			function () use ( &$order ): void {
				$order[] = 'priority_20';
			},
			20
		);

		add_action(
			'test_priority_hook',
			function () use ( &$order ): void {
				$order[] = 'priority_5';
			},
			5
		);

		add_action(
			'test_priority_hook',
			function () use ( &$order ): void {
				$order[] = 'priority_10_first';
			},
			10
		);

		add_action(
			'test_priority_hook',
			function () use ( &$order ): void {
				$order[] = 'priority_10_second';
			},
			10
		);

		do_action( 'test_priority_hook' );

		$this->assertSame(
			array( 'priority_5', 'priority_10_first', 'priority_10_second', 'priority_20' ),
			$order
		);
	}

	/**
	 * Test no-listener behavior does not throw or mutate value.
	 */
	public function test_no_listener_behavior(): void {
		do_action( 'unregistered_action_never_bound', 1, 2, 3 );
		$this->assertTrue( true );

		$filtered = apply_filters( 'unregistered_filter_never_bound', 'original_payload', 'extra' );
		$this->assertSame( 'original_payload', $filtered );
	}

	/**
	 * Test add_filter and add_action return boolean true.
	 */
	public function test_add_filter_and_add_action_return_bool(): void {
		$action_result = add_action(
			'test_return_bool_action',
			static function (): void {}
		);
		$filter_result = add_filter(
			'test_return_bool_filter',
			static function ( $v ) {
				return $v;
			}
		);

		$this->assertTrue( $action_result );
		$this->assertTrue( $filter_result );
	}

	/**
	 * Test removal of actions and filters across all supported callback types.
	 */
	public function test_remove_action_and_remove_filter_with_various_callables(): void {
		// 1. Closures.
		$closure = static function (): void {};
		add_action( 'closure_hook', $closure, 10 );
		$this->assertNotFalse( has_action( 'closure_hook', $closure ) );

		$this->assertFalse( remove_action( 'closure_hook', $closure, 20 ), 'Wrong priority must return false' );
		$this->assertTrue( remove_action( 'closure_hook', $closure, 10 ) );
		$this->assertFalse( has_action( 'closure_hook', $closure ) );

		// 2. Static class methods (array format and Class::method string format interchangeably).
		add_filter( 'static_hook', array( self::class, 'sample_static_callback' ), 15 );
		$this->assertSame( 15, has_filter( 'static_hook', self::class . '::sample_static_callback' ) );

		$this->assertTrue(
			remove_filter( 'static_hook', self::class . '::sample_static_callback', 15 ),
			'Removing with string syntax when registered with array syntax should succeed'
		);
		$this->assertFalse( has_filter( 'static_hook', array( self::class, 'sample_static_callback' ) ) );

		// Register as string, remove as array.
		add_action( 'static_hook_str', self::class . '::sample_static_callback', 8 );
		$this->assertSame( 8, has_action( 'static_hook_str', array( self::class, 'sample_static_callback' ) ) );
		$this->assertTrue( remove_action( 'static_hook_str', array( self::class, 'sample_static_callback' ), 8 ) );
		$this->assertFalse( has_action( 'static_hook_str', self::class . '::sample_static_callback' ) );

		// 3. Object methods.
		$instance1 = $this;
		$instance2 = new self( 'test_dummy' );

		add_action( 'object_hook', array( $instance1, 'sample_instance_callback' ), 10 );
		$this->assertSame( 10, has_action( 'object_hook', array( $instance1, 'sample_instance_callback' ) ) );
		$this->assertFalse( has_action( 'object_hook', array( $instance2, 'sample_instance_callback' ) ) );

		$this->assertFalse(
			remove_action( 'object_hook', array( $instance2, 'sample_instance_callback' ), 10 ),
			'Attempting to remove using a different object instance must fail'
		);
		$this->assertTrue( remove_action( 'object_hook', array( $instance1, 'sample_instance_callback' ), 10 ) );
		$this->assertFalse( has_action( 'object_hook', array( $instance1, 'sample_instance_callback' ) ) );

		// 4. Invokable objects.
		$invokable = new class() {
			public function __invoke( string $val ): string {
				return $val . '_invoked';
			}
		};

		add_filter( 'invokable_hook', $invokable, 12 );
		$this->assertSame( 12, has_filter( 'invokable_hook', $invokable ) );
		$this->assertTrue( remove_filter( 'invokable_hook', $invokable, 12 ) );
		$this->assertFalse( has_filter( 'invokable_hook', $invokable ) );

		// 5. Non-existent hook or callback.
		$this->assertFalse( remove_action( 'non_existent_hook', $closure, 10 ) );
	}

	/**
	 * Test has_filter and has_action contracts, including priority 0 distinction.
	 */
	public function test_has_filter_and_has_action_contracts(): void {
		$callback = static function ( $val ) {
			return $val;
		};

		// 1. Hook existence without callback.
		$this->assertFalse( has_filter( 'contract_hook' ) );
		$this->assertFalse( has_action( 'contract_hook' ) );

		add_filter( 'contract_hook', $callback, 0 );

		$this->assertTrue( has_filter( 'contract_hook' ), 'has_filter without callback must return boolean true' );
		$this->assertTrue( has_action( 'contract_hook' ), 'has_action without callback must return boolean true' );

		// 2. Priority 0 check with callback.
		// In WordPress / PHP, priority 0 must return int 0, which is truthy when compared with false !== has_filter().
		$p0_filter = has_filter( 'contract_hook', $callback );
		$this->assertSame( 0, $p0_filter );
		$this->assertNotFalse( $p0_filter );

		$p0_action = has_action( 'contract_hook', $callback );
		$this->assertSame( 0, $p0_action );
		$this->assertNotFalse( $p0_action );

		// 3. Callback at priority 25.
		add_action( 'contract_hook_25', $callback, 25 );
		$this->assertSame( 25, has_action( 'contract_hook_25', $callback ) );

		// 4. Checking specific priority (3rd argument): returns bool.
		$this->assertTrue( has_action( 'contract_hook_25', $callback, 25 ) );
		$this->assertFalse( has_action( 'contract_hook_25', $callback, 10 ) );
		$this->assertTrue( has_action( 'contract_hook_25', false, 25 ) );
		$this->assertFalse( has_action( 'contract_hook_25', false, 10 ) );

		// 5. Non-registered callback.
		$other_cb = static function (): void {};
		$this->assertFalse( has_filter( 'contract_hook', $other_cb ) );
	}

	/**
	 * Test repeated registration of the same callback replaces the definition in-place without duplicating.
	 */
	public function test_repeated_registration_replaces_in_place_without_duplicating(): void {
		$execution_log = array();

		$cb_a = function ( string $val, string $extra = 'default' ) use ( &$execution_log ): void {
			$execution_log[] = "A:{$val}:{$extra}";
		};

		$cb_b = function ( string $val ) use ( &$execution_log ): void {
			$execution_log[] = "B:{$val}";
		};

		// First registration of A with accepted_args = 1.
		add_action( 'test_repeated', $cb_a, 10, 1 );

		// Registration of B at same priority.
		add_action( 'test_repeated', $cb_b, 10, 1 );

		// Re-register A at priority 10 with accepted_args = 2.
		add_action( 'test_repeated', $cb_a, 10, 2 );

		do_action( 'test_repeated', 'payload', 'supplied' );

		// Expected: A executed ONCE (replaced, not duplicated), before B (FIFO preserved), and received 2 args.
		$this->assertSame(
			array(
				'A:payload:supplied',
				'B:payload',
			),
			$execution_log
		);
	}

	/**
	 * Test remove_all_actions and remove_all_filters with and without priority parameter.
	 */
	public function test_remove_all_actions_and_filters_with_and_without_priority(): void {
		$calls = array();

		add_action(
			'multi_hook_1',
			function () use ( &$calls ): void {
				$calls[] = 'h1_p10';
			},
			10
		);

		add_action(
			'multi_hook_1',
			function () use ( &$calls ): void {
				$calls[] = 'h1_p20';
			},
			20
		);

		add_action(
			'multi_hook_2',
			function () use ( &$calls ): void {
				$calls[] = 'h2_p10';
			},
			10
		);

		// Remove only priority 10 from multi_hook_1.
		$res1 = remove_all_actions( 'multi_hook_1', 10 );
		$this->assertTrue( $res1 );

		do_action( 'multi_hook_1' );
		do_action( 'multi_hook_2' );

		$this->assertSame( array( 'h1_p20', 'h2_p10' ), $calls );

		// Remove all priorities from multi_hook_1.
		$calls = array();
		remove_all_actions( 'multi_hook_1' );

		do_action( 'multi_hook_1' );
		do_action( 'multi_hook_2' );

		$this->assertSame( array( 'h2_p10' ), $calls );

		// Clear multi_hook_2 using remove_all_filters with required hook_name.
		$calls = array();
		remove_all_filters( 'multi_hook_2' );

		do_action( 'multi_hook_2' );
		$this->assertEmpty( $calls );

		// Clear all hooks globally using low-level Hooks::remove_all().
		add_action(
			'global_clear_test',
			function () use ( &$calls ): void {
				$calls[] = 'ran';
			}
		);
		Hooks::remove_all();
		do_action( 'global_clear_test' );
		$this->assertEmpty( $calls );
	}

	/**
	 * Test did_action increments even without listeners, tracks multiple runs, and resets on reset_actions.
	 */
	public function test_did_action_counter_increments_with_and_without_listeners(): void {
		$this->assertSame( 0, did_action( 'no_listener_action' ) );

		do_action( 'no_listener_action' );
		$this->assertSame( 1, did_action( 'no_listener_action' ) );

		do_action( 'no_listener_action' );
		$this->assertSame( 2, did_action( 'no_listener_action' ) );

		// With registered listener.
		add_action(
			'with_listener_action',
			static function (): void {}
		);

		$this->assertSame( 0, did_action( 'with_listener_action' ) );
		do_action( 'with_listener_action' );
		$this->assertSame( 1, did_action( 'with_listener_action' ) );

		// Hooks::remove_all() must NOT reset did_actions counter.
		Hooks::remove_all();
		$this->assertSame( 2, did_action( 'no_listener_action' ) );
		$this->assertSame( 1, did_action( 'with_listener_action' ) );

		// Dedicated Hooks::reset_actions() clears did_actions.
		Hooks::reset_actions();
		$this->assertSame( 0, did_action( 'no_listener_action' ) );
		$this->assertSame( 0, did_action( 'with_listener_action' ) );
	}

	/**
	 * Test current_action, current_filter, doing_action, doing_filter with nested dispatch.
	 */
	public function test_introspection_current_and_doing_hooks_with_nested_stack(): void {
		$this->assertFalse( current_filter() );
		$this->assertFalse( current_action() );
		$this->assertFalse( doing_filter() );
		$this->assertFalse( doing_action() );

		$observations = array();

		add_action(
			'outer_event',
			function () use ( &$observations ): void {
				$observations['outer_before'] = array(
					'current_action' => current_action(),
					'current_filter' => current_filter(),
					'doing_outer'    => doing_action( 'outer_event' ),
					'doing_inner'    => doing_action( 'inner_event' ),
					'doing_any'      => doing_action(),
				);

				// Nested call.
				do_action( 'inner_event' );

				$observations['outer_after'] = array(
					'current_action' => current_action(),
					'doing_outer'    => doing_action( 'outer_event' ),
					'doing_inner'    => doing_action( 'inner_event' ),
				);
			}
		);

		add_action(
			'inner_event',
			function () use ( &$observations ): void {
				$observations['inner'] = array(
					'current_action' => current_action(),
					'current_filter' => current_filter(),
					'doing_outer'    => doing_action( 'outer_event' ),
					'doing_inner'    => doing_action( 'inner_event' ),
					'doing_any'      => doing_action(),
				);
			}
		);

		do_action( 'outer_event' );

		// After completion, stack must be completely unwound.
		$this->assertFalse( current_action() );
		$this->assertFalse( current_filter() );
		$this->assertFalse( doing_action() );
		$this->assertFalse( doing_filter() );

		$this->assertSame(
			array(
				'current_action' => 'outer_event',
				'current_filter' => 'outer_event',
				'doing_outer'    => true,
				'doing_inner'    => false,
				'doing_any'      => true,
			),
			$observations['outer_before']
		);

		$this->assertSame(
			array(
				'current_action' => 'inner_event',
				'current_filter' => 'inner_event',
				'doing_outer'    => true,
				'doing_inner'    => true,
				'doing_any'      => true,
			),
			$observations['inner']
		);

		$this->assertSame(
			array(
				'current_action' => 'outer_event',
				'doing_outer'    => true,
				'doing_inner'    => false,
			),
			$observations['outer_after']
		);
	}

	/**
	 * Test that hook execution stack is properly cleaned up when a callback throws an exception.
	 */
	public function test_try_finally_stack_cleanup_on_exception(): void {
		add_action(
			'failing_action',
			static function (): void {
				throw new RuntimeException( 'Simulated action callback failure' );
			}
		);

		$caught_action = false;
		try {
			do_action( 'failing_action' );
		} catch ( RuntimeException $e ) {
			$caught_action = true;
			$this->assertSame( 'Simulated action callback failure', $e->getMessage() );
		}

		$this->assertTrue( $caught_action );
		$this->assertFalse( current_action(), 'Action stack must be popped even if callback throws' );
		$this->assertFalse( doing_action() );

		// Same verification for apply_filters.
		add_filter(
			'failing_filter',
			static function ( $val ) {
				throw new RuntimeException( 'Simulated filter callback failure' );
			}
		);

		$caught_filter = false;
		try {
			apply_filters( 'failing_filter', 'value' );
		} catch ( RuntimeException $e ) {
			$caught_filter = true;
		}

		$this->assertTrue( $caught_filter );
		$this->assertFalse( current_filter(), 'Filter stack must be popped even if callback throws' );
		$this->assertFalse( doing_filter() );
	}

	/**
	 * Test removing a callback while the hook is actively iterating.
	 */
	public function test_removal_while_iterating(): void {
		$ran = array();

		$cb2 = function () use ( &$ran ): void {
			$ran[] = 'cb2';
		};

		$cb1 = function () use ( &$ran, $cb2 ): void {
			$ran[] = 'cb1';
			// Remove sibling callback at the same priority before it runs.
			remove_action( 'test_iterate_removal', $cb2, 10 );
			// Remove later callback at priority 20 before it runs.
			remove_all_actions( 'test_iterate_removal', 20 );
		};

		$cb3 = function () use ( &$ran ): void {
			$ran[] = 'cb3';
		};

		add_action( 'test_iterate_removal', $cb1, 10 );
		add_action( 'test_iterate_removal', $cb2, 10 );
		add_action( 'test_iterate_removal', $cb3, 20 );

		do_action( 'test_iterate_removal' );

		// Only cb1 should have run; cb2 and cb3 were removed before their turn.
		$this->assertSame( array( 'cb1' ), $ran );
	}

	/**
	 * Test negative and zero priorities execute in proper ascending numerical order.
	 */
	public function test_negative_and_zero_priorities_execution_order(): void {
		$order = array();

		add_action(
			'test_negative_p',
			function () use ( &$order ): void {
				$order[] = 'p20';
			},
			20
		);

		add_action(
			'test_negative_p',
			function () use ( &$order ): void {
				$order[] = 'p_minus_10';
			},
			-10
		);

		add_action(
			'test_negative_p',
			function () use ( &$order ): void {
				$order[] = 'p10';
			},
			10
		);

		add_action(
			'test_negative_p',
			function () use ( &$order ): void {
				$order[] = 'p0';
			},
			0
		);

		do_action( 'test_negative_p' );

		$this->assertSame(
			array( 'p_minus_10', 'p0', 'p10', 'p20' ),
			$order
		);
	}

	/**
	 * Test mutation semantics during active dispatch:
	 * - Adding callbacks during active dispatch does not execute them in current dispatch;
	 *   they take effect on subsequent dispatches.
	 * - Removing callbacks before their turn prevents execution.
	 * - Removing self prevents execution in subsequent dispatches.
	 */
	public function test_mutation_behavior_during_active_dispatch(): void {
		$log = array();

		// 1. Adding higher priority during dispatch does not execute in current dispatch,
		// but executes on subsequent dispatch.
		$added = false;
		add_action(
			'test_dynamic_dispatch',
			function () use ( &$log, &$added ): void {
				$log[] = 'p10';
				if ( ! $added ) {
					$added = true;
					add_action(
						'test_dynamic_dispatch',
						function () use ( &$log ): void {
							$log[] = 'p20_dynamically_added';
						},
						20
					);
				}
			},
			10
		);

		do_action( 'test_dynamic_dispatch' );
		$this->assertSame( array( 'p10' ), $log, 'Dynamic additions must not run in current snapshotted dispatch' );

		// Subsequent dispatch executes both.
		$log = array();
		do_action( 'test_dynamic_dispatch' );
		$this->assertSame( array( 'p10', 'p20_dynamically_added' ), $log );

		// 2. Adding at same priority during dispatch does not execute in current cycle.
		$log2 = array();
		add_action(
			'test_same_priority_add',
			function () use ( &$log2 ): void {
				$log2[] = 'first';
				add_action(
					'test_same_priority_add',
					function () use ( &$log2 ): void {
						$log2[] = 'dynamic_same_priority';
					},
					10
				);
			},
			10
		);

		do_action( 'test_same_priority_add' );
		$this->assertSame( array( 'first' ), $log2 );

		// 3. Removing self during dispatch prevents subsequent executions.
		$log3         = array();
		$self_remover = function () use ( &$log3, &$self_remover ): void {
			$log3[] = 'invoked';
			remove_action( 'test_self_remove', $self_remover, 10 );
		};

		add_action( 'test_self_remove', $self_remover, 10 );
		do_action( 'test_self_remove' );
		do_action( 'test_self_remove' ); // second dispatch should not invoke it
		$this->assertSame( array( 'invoked' ), $log3 );

		// 4. Adding a callback to an ALREADY EXISTING higher priority level during dispatch:
		// The pre-existing callback runs, but the newly added callback at that priority does NOT run in current dispatch.
		$log4 = array();
		add_action(
			'test_existing_priority_mutation',
			function () use ( &$log4 ): void {
				$log4[] = 'p10';
				add_action(
					'test_existing_priority_mutation',
					function () use ( &$log4 ): void {
						$log4[] = 'p20_added_dynamically';
					},
					20
				);
			},
			10
		);
		add_action(
			'test_existing_priority_mutation',
			function () use ( &$log4 ): void {
				$log4[] = 'p20_pre_existing';
			},
			20
		);

		do_action( 'test_existing_priority_mutation' );
		$this->assertSame(
			array( 'p10', 'p20_pre_existing' ),
			$log4,
			'Callback added to an existing higher priority must not run until subsequent dispatch'
		);

		// Subsequent dispatch runs all three.
		$log4 = array();
		do_action( 'test_existing_priority_mutation' );
		$this->assertSame(
			array( 'p10', 'p20_pre_existing', 'p20_added_dynamically' ),
			$log4
		);
	}

	/**
	 * Test filter mutation during active dispatch adheres to snapshot semantics.
	 */
	public function test_filter_mutation_during_dispatch_adheres_to_snapshot(): void {
		add_filter(
			'test_filter_snap',
			function ( string $val ): string {
				add_filter(
					'test_filter_snap',
					fn( string $v ): string => $v . '_appended_later',
					20
				);
				return $val . '_first';
			},
			10
		);

		$res = apply_filters( 'test_filter_snap', 'base' );
		$this->assertSame( 'base_first', $res, 'Dynamically added filter must not run in current dispatch' );

		$res2 = apply_filters( 'test_filter_snap', 'base' );
		$this->assertSame( 'base_first_appended_later', $res2 );
	}

	/**
	 * Test removal and re-registration during the same dispatch runs only on the next dispatch.
	 */
	public function test_removal_and_reregistration_during_same_dispatch_runs_only_on_next_dispatch(): void {
		$log = array();

		$initial_callback = function () use ( &$log, &$initial_callback ): void {
			$log[] = 'initial_ran';
			remove_action( 'test_re_reg', $initial_callback, 10 );
			add_action(
				'test_re_reg',
				function () use ( &$log ): void {
					$log[] = 'replacement_ran';
				},
				10
			);
		};

		add_action( 'test_re_reg', $initial_callback, 10 );

		// First dispatch: initial callback runs, unregisters itself, and registers replacement.
		// Replacement must NOT execute in the same dispatch.
		do_action( 'test_re_reg' );
		$this->assertSame( array( 'initial_ran' ), $log );

		// Second dispatch: replacement callback executes.
		$log = array();
		do_action( 'test_re_reg' );
		$this->assertSame( array( 'replacement_ran' ), $log );
	}

	/**
	 * Test that when callback 1 removes callback 2 before its turn and re-registers it
	 * at the same priority during the same dispatch, callback 2 does not run until next dispatch.
	 */
	public function test_callback_removed_and_reregistered_by_peer_at_same_priority_does_not_run_in_current_dispatch(): void {
		$log = array();

		$cb2 = function () use ( &$log ): void {
			$log[] = 'cb2_ran';
		};

		$cb1 = function () use ( &$log, &$cb2 ): void {
			$log[] = 'cb1_ran';
			// Remove callback 2 before its turn at the same priority (10).
			remove_action( 'test_peer_re_reg', $cb2, 10 );
			// Re-register callback 2 at the same priority (10).
			add_action( 'test_peer_re_reg', $cb2, 10 );
		};

		add_action( 'test_peer_re_reg', $cb1, 10 );
		add_action( 'test_peer_re_reg', $cb2, 10 );

		// First dispatch: cb1 runs, unregisters cb2, and re-adds cb2.
		// cb2 must NOT run in this dispatch.
		do_action( 'test_peer_re_reg' );
		$this->assertSame( array( 'cb1_ran' ), $log );

		// On subsequent dispatch, cb2 is active.
		remove_action( 'test_peer_re_reg', $cb1, 10 );
		$log = array();
		do_action( 'test_peer_re_reg' );
		$this->assertSame( array( 'cb2_ran' ), $log );
	}

	/**
	 * Test filter removed and re-registered by peer at same priority does not run in current dispatch.
	 */
	public function test_filter_removed_and_reregistered_by_peer_at_same_priority_does_not_run_in_current_dispatch(): void {
		$filter2 = function ( string $val ): string {
			return $val . '_filter2';
		};

		$filter1 = function ( string $val ) use ( &$filter2 ): string {
			remove_filter( 'test_filter_peer_re_reg', $filter2, 10 );
			add_filter( 'test_filter_peer_re_reg', $filter2, 10 );
			return $val . '_filter1';
		};

		add_filter( 'test_filter_peer_re_reg', $filter1, 10 );
		add_filter( 'test_filter_peer_re_reg', $filter2, 10 );

		$res = apply_filters( 'test_filter_peer_re_reg', 'init' );
		$this->assertSame( 'init_filter1', $res );

		remove_filter( 'test_filter_peer_re_reg', $filter1, 10 );
		$res2 = apply_filters( 'test_filter_peer_re_reg', 'init' );
		$this->assertSame( 'init_filter2', $res2 );
	}
}
