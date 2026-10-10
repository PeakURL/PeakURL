<?php
/**
 * Unit tests for Webhooks Dispatcher.
 *
 * @package PeakURL\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'remove_filter' ) ) {
		/**
		 * Remove a specific filter callback for a hook and priority.
		 *
		 * @param string   $hook_name Hook name.
		 * @param callable $callback  Callback to remove.
		 * @param int      $priority  Execution priority.
		 * @return bool Whether the callback was removed.
		 */
		function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
			$reflector = new \ReflectionProperty( \PeakURL\Core\Hooks\Hooks::class, 'hooks' );
			$reflector->setAccessible( true );
			/** @var array<string, array<int, array<int, array{callback: callable, accepted_args: int}>>> $hooks */
			$hooks = $reflector->getValue();

			if ( ! isset( $hooks[ $hook_name ][ $priority ] ) || ! is_array( $hooks[ $hook_name ][ $priority ] ) ) {
				return false;
			}

			$removed = false;
			foreach ( $hooks[ $hook_name ][ $priority ] as $index => $registration ) {
				if ( isset( $registration['callback'] ) && $registration['callback'] === $callback ) {
					unset( $hooks[ $hook_name ][ $priority ][ $index ] );
					$removed = true;
				}
			}

			if ( $removed ) {
				$hooks[ $hook_name ][ $priority ] = array_values( $hooks[ $hook_name ][ $priority ] );
				if ( empty( $hooks[ $hook_name ][ $priority ] ) ) {
					unset( $hooks[ $hook_name ][ $priority ] );
				}
				if ( empty( $hooks[ $hook_name ] ) ) {
					unset( $hooks[ $hook_name ] );
				}
				$reflector->setValue( null, $hooks );
			}

			return $removed;
		}
	}
}

namespace PeakURL\Tests\Unit\Webhooks {

	use PHPUnit\Framework\TestCase;
	use PeakURL\Features\Webhooks\Delivery;
	use PeakURL\Features\Webhooks\Dispatcher;
	use PeakURL\Features\Webhooks\Subscriptions;
	use PeakURL\Services\Database\PeakURL_DB;

	class DispatcherTest extends TestCase {

		private PeakURL_DB $db;
		private Subscriptions $subscriptions;
		private Delivery $delivery;
		private Dispatcher $dispatcher;

		protected function setUp(): void {
			parent::setUp();
			$this->db            = $this->createMock( PeakURL_DB::class );
			$this->subscriptions = $this->createMock( Subscriptions::class );
			$this->delivery      = $this->createMock( Delivery::class );
			$this->dispatcher    = new Dispatcher(
				$this->db,
				$this->subscriptions,
				$this->delivery,
				array( 'site_url' => 'https://example.com' )
			);
		}


		public function test_create_event_id_has_expected_prefix(): void {
			$id = $this->dispatcher->create_event_id();
			$this->assertStringStartsWith( 'peakurl_evt_', $id );
			$this->assertSame( 44, strlen( $id ) );
		}


		public function test_create_event_payload_has_standard_envelope(): void {
			$data    = array( 'key' => 'value' );
			$payload = $this->dispatcher->create_event_payload( 'link.created', $data, 'evt_custom_123', 1700000000 );

			$this->assertTrue( $payload['success'] );
			$this->assertSame( 200, $payload['statusCode'] );
			$this->assertSame( 'link.created', $payload['event'] );
			$this->assertSame( 'link.created', $payload['type'] );
			$this->assertSame( 'evt_custom_123', $payload['id'] );
			$this->assertSame( 1700000000, $payload['timestamp'] );
			$this->assertSame( '2023-11-14T22:13:20Z', $payload['created_at'] );
			$this->assertSame( $data, $payload['data'] );
		}

		public function test_get_api_key_event_data_shapes_safe_fields_and_no_secrets(): void {
			$key_data = array(
				'id'         => 'peakurl_key_abc',
				'label'      => 'CI Key',
				'prefix'     => 'peakurl_live_',
				'last_four'  => '9999',
				'created_at' => '2026-10-01 10:00:00',
				'revoked_at' => '2026-10-02 11:00:00',
				'key_hash'   => 'secret_hash_not_allowed',
				'token'      => 'secret_token_not_allowed',
			);

			$result = $this->dispatcher->get_api_key_event_data( 'api_key.revoked', $key_data );
			$this->assertSame( 'peakurl_key_abc', $result['id'] );
			$this->assertSame( 'CI Key', $result['label'] );
			$this->assertSame( 'peakurl_live_', $result['prefix'] );
			$this->assertSame( '9999', $result['last_four'] );
			$this->assertArrayNotHasKey( 'key_hash', $result );
			$this->assertArrayNotHasKey( 'token', $result );
			$this->assertArrayHasKey( 'revoked_at', $result );
		}

		public function test_get_user_event_data_shapes_safe_metadata_only(): void {
			$user_data = array(
				'id'            => '42',
				'username'      => 'alice',
				'email'         => 'alice@example.com',
				'first_name'    => 'Alice',
				'last_name'     => 'Smith',
				'role'          => 'admin',
				'password_hash' => 'bcrypt_hash_not_allowed',
				'created_at'    => '2026-10-01 10:00:00',
				'updated_at'    => '2026-10-02 10:00:00',
			);

			$result = $this->dispatcher->get_user_event_data( 'user.updated', $user_data );
			$this->assertSame( '42', $result['id'] );
			$this->assertSame( 'alice', $result['username'] );
			$this->assertSame( 'Alice Smith', $result['display_name'] );
			$this->assertArrayNotHasKey( 'password_hash', $result );
		}

		public function test_determine_health_events_full_transition_matrix(): void {
			// 1. Initial check healthy -> [link.health.checked]
			$events = Dispatcher::determine_health_events( null, 'healthy' );
			$this->assertSame( array( 'link.health.checked' ), $events );

			// 2. Recovery from broken -> [link.health.checked, link.health.changed, link.health.recovered]
			$events = Dispatcher::determine_health_events( 'timeout', 'healthy' );
			$this->assertSame( array( 'link.health.checked', 'link.health.changed', 'link.health.recovered' ), $events );

			// 3. Degraded into slow -> [link.health.checked, link.health.changed, link.health.degraded]
			$events = Dispatcher::determine_health_events( 'healthy', 'slow' );
			$this->assertSame( array( 'link.health.checked', 'link.health.changed', 'link.health.degraded' ), $events );

			// 4. Broken status -> [link.health.checked, link.health.changed, link.health.broken, link.health.timeout]
			$events = Dispatcher::determine_health_events( 'healthy', 'timeout' );
			$this->assertSame( array( 'link.health.checked', 'link.health.changed', 'link.health.broken', 'link.health.timeout' ), $events );
		}

		public function test_get_webhook_site_url_resolves_fallback_url_with_path_and_alias(): void {
			// 1. Normal resolution with custom alias/path
			$url = $this->dispatcher->get_webhook_site_url( 'my-custom-alias' );
			$this->assertStringEndsWith( '/my-custom-alias', $url );

			// 2. Base URL resolution with empty path
			$base_url = $this->dispatcher->get_webhook_site_url( '' );
			$this->assertNotEmpty( $base_url );
			$this->assertStringEndsNotWith( '/', $base_url );
			$this->assertSame( $base_url . '/my-custom-alias', $url );

			// 3. WordPress filter customization via apply_filters('site_url')
			$other_filter_called = false;
			$other_filter        = function ( $filtered_url ) use ( &$other_filter_called ) {
				$other_filter_called = true;
				return $filtered_url;
			};
			\add_filter( 'site_url', $other_filter, 20 );

			try {
				$callback = function ( $filtered_url, $path ) {
					return 'https://custom-mock.org/' . ltrim( $path, '/' );
				};
				\add_filter(
					'site_url',
					$callback,
					10,
					2
				);

				try {
					$filtered = $this->dispatcher->get_webhook_site_url( 'my-custom-alias' );
					$this->assertSame( 'https://custom-mock.org/my-custom-alias', $filtered );
					$this->assertTrue( $other_filter_called );
				} finally {
					\remove_filter( 'site_url', $callback, 10 );
				}

				// 4. Verify targeted cleanup preserved other filters on the hook
				$other_filter_called = false;
				$retested_url        = $this->dispatcher->get_webhook_site_url( 'my-custom-alias' );
				$this->assertTrue( $other_filter_called );
				$this->assertSame( $url, $retested_url );
			} finally {
				\remove_filter( 'site_url', $other_filter, 20 );
			}
		}

		public function test_get_link_event_data_falls_back_to_site_url_with_alias_when_short_url_is_empty(): void {
			$link_data = array(
				'id'             => 'link_123',
				'title'          => 'Custom Alias Link',
				'alias'          => 'my-custom-alias',
				'shortUrl'       => '',
				'destinationUrl' => 'https://example.org/destination',
			);

			$data = $this->dispatcher->get_link_event_data( 'link.created', $link_data );
			$this->assertSame( 'link_123', $data['id'] );
			$this->assertSame( 'my-custom-alias', $data['alias'] );
			$this->assertNotEmpty( $data['shortUrl'] );
			$this->assertStringEndsWith( '/my-custom-alias', $data['shortUrl'] );
		}
	}
}
