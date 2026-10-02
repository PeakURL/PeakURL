<?php
/**
 * Unit tests for Link Health webhook event catalogue, matrix, and payload shaping.
 *
 * @package PeakURL\Tests\Unit\Webhooks
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Services\Database\PeakURL_DB;
use ReflectionMethod;

class LinkHealthWebhookEventsTest extends TestCase {

	/**
	 * Verify that determine_health_events accurately implements the authoritative transition matrix.
	 */
	public function test_determine_health_events_full_transition_matrix(): void {
		// 1. First-ever checks (previous = null)
		$this->assertSame(
			array( 'link.health.checked' ),
			WebhooksService::determine_health_events( null, 'healthy' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.degraded' ),
			WebhooksService::determine_health_events( null, 'slow' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.broken', 'link.health.unreachable' ),
			WebhooksService::determine_health_events( null, 'unreachable' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.broken', 'link.health.dns_error' ),
			WebhooksService::determine_health_events( null, 'dns_error' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.broken', 'link.health.tls_error' ),
			WebhooksService::determine_health_events( null, 'tls_error' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.broken', 'link.health.timeout' ),
			WebhooksService::determine_health_events( null, 'timeout' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.broken', 'link.health.http_error' ),
			WebhooksService::determine_health_events( null, 'http_error' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.broken', 'link.health.redirect_loop' ),
			WebhooksService::determine_health_events( null, 'redirect_loop' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.ssrf_blocked' ),
			WebhooksService::determine_health_events( null, 'ssrf_blocked' )
		);

		// 2. From healthy
		$this->assertSame(
			array( 'link.health.checked' ),
			WebhooksService::determine_health_events( 'healthy', 'healthy' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.degraded' ),
			WebhooksService::determine_health_events( 'healthy', 'slow' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.broken', 'link.health.timeout' ),
			WebhooksService::determine_health_events( 'healthy', 'timeout' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.broken', 'link.health.http_error' ),
			WebhooksService::determine_health_events( 'healthy', 'http_error' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.ssrf_blocked' ),
			WebhooksService::determine_health_events( 'healthy', 'ssrf_blocked' )
		);

		// 3. From slow
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.recovered' ),
			WebhooksService::determine_health_events( 'slow', 'healthy' )
		);
		$this->assertSame(
			array( 'link.health.checked' ),
			WebhooksService::determine_health_events( 'slow', 'slow' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.broken', 'link.health.unreachable' ),
			WebhooksService::determine_health_events( 'slow', 'unreachable' )
		);

		// 4. From broken status
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.recovered' ),
			WebhooksService::determine_health_events( 'timeout', 'healthy' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.degraded' ),
			WebhooksService::determine_health_events( 'timeout', 'slow' )
		);
		$this->assertSame(
			array( 'link.health.checked' ),
			WebhooksService::determine_health_events( 'timeout', 'timeout' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.broken', 'link.health.dns_error' ),
			WebhooksService::determine_health_events( 'timeout', 'dns_error' )
		);

		// 5. From ssrf_blocked
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.recovered' ),
			WebhooksService::determine_health_events( 'ssrf_blocked', 'healthy' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.degraded' ),
			WebhooksService::determine_health_events( 'ssrf_blocked', 'slow' )
		);
		$this->assertSame(
			array( 'link.health.checked', 'link.health.changed', 'link.health.broken', 'link.health.timeout' ),
			WebhooksService::determine_health_events( 'ssrf_blocked', 'timeout' )
		);
		$this->assertSame(
			array( 'link.health.checked' ),
			WebhooksService::determine_health_events( 'ssrf_blocked', 'ssrf_blocked' )
		);
	}

	/**
	 * Verify that get_link_health_event_data shapes safe payloads without leaking internals.
	 */
	public function test_get_link_health_event_data_structure(): void {
		$service = new WebhooksService(
			$this->createMock( PeakURL_DB::class ),
			new WebhooksValidator(),
			$this->createMock( AuthService::class ),
			new Roles(),
			new Authorization( new Roles() ),
			array(),
			$this->createMock( \PeakURL\Services\Crypto::class )
		);

		$method = new ReflectionMethod( WebhooksService::class, 'get_link_health_event_data' );

		$link_data = array(
			'id'              => 'lnk_abc123',
			'alias'           => 'custom-code',
			'destination_url' => 'https://example.com/target',
			'short_url'       => 'https://peakurl.dev/custom-code',
			'secret'          => 'sensitive_secret_value',
			'api_key'         => 'peakurl_key_12345',
			'password'        => 'my_password_hash',
			'lock_token'      => 'token_abc',
			'scheduler_state' => 'running',
			'retry_state'     => 'retrying',
		);

		$current_health = array(
			'status'           => 'timeout',
			'checked_at'       => '2026-10-02 18:30:00',
			'response_code'    => null,
			'response_time_ms' => 3000,
			'error_message'    => 'Operation timed out.',
			'redirect_count'   => 0,
			'resolved_ip'      => '192.168.1.1',
			'dns_records'      => array( 'A 192.168.1.1' ),
			'curl_options'     => array( CURLOPT_TIMEOUT => 3 ),
			'secret'           => 'internal_secret',
			'password'         => 'internal_pwd',
			'lock_token'       => 'internal_token',
		);

		$prev_health = array(
			'status'           => 'healthy',
			'checked_at'       => '2026-10-02 17:30:00',
			'response_code'    => 200,
			'response_time_ms' => 142,
			'error_message'    => null,
			'redirect_count'   => 0,
			'resolved_ip'      => '93.184.216.34',
			'dns_records'      => array( 'A 93.184.216.34' ),
			'curl_options'     => array( CURLOPT_TIMEOUT => 3 ),
		);

		$forbidden_keys = array(
			'secret',
			'api_key',
			'password',
			'lock_token',
			'resolved_ip',
			'dns_records',
			'curl_options',
			'scheduler_state',
			'retry_state',
		);

		// 1. link.health.checked does NOT include previousHealth
		$checked_payload = $method->invoke( $service, 'link.health.checked', $link_data, $current_health, $prev_health );
		$this->assertSame( 'lnk_abc123', $checked_payload['id'] );
		$this->assertSame( 'custom-code', $checked_payload['alias'] );
		$this->assertSame( 'https://peakurl.dev/custom-code', $checked_payload['shortUrl'] );
		$this->assertSame( 'https://example.com/target', $checked_payload['destinationUrl'] );
		$this->assertArrayHasKey( 'health', $checked_payload );
		$this->assertSame( 'timeout', $checked_payload['health']['status'] );
		$this->assertSame( 3000, $checked_payload['health']['responseTimeMs'] );
		$this->assertNull( $checked_payload['health']['responseCode'] );
		$this->assertArrayNotHasKey( 'previousHealth', $checked_payload );

		foreach ( $forbidden_keys as $key ) {
			$this->assertArrayNotHasKey( $key, $checked_payload, "Top-level payload must not leak sensitive field [{$key}]." );
			$this->assertArrayNotHasKey( $key, $checked_payload['health'], "Health snapshot must not leak sensitive field [{$key}]." );
		}

		// 2. All 11 Transition/Status-entry events DO include previousHealth and exclude sensitive fields
		$transition_events = array(
			'link.health.changed',
			'link.health.recovered',
			'link.health.degraded',
			'link.health.broken',
			'link.health.unreachable',
			'link.health.dns_error',
			'link.health.tls_error',
			'link.health.timeout',
			'link.health.http_error',
			'link.health.redirect_loop',
			'link.health.ssrf_blocked',
		);

		foreach ( $transition_events as $evt ) {
			$trans_payload = $method->invoke( $service, $evt, $link_data, $current_health, $prev_health );
			$this->assertArrayHasKey( 'previousHealth', $trans_payload, "Event [{$evt}] must include previousHealth." );
			$this->assertSame( 'healthy', $trans_payload['previousHealth']['status'] );
			$this->assertSame( 200, $trans_payload['previousHealth']['responseCode'] );
			$this->assertSame( 142, $trans_payload['previousHealth']['responseTimeMs'] );

			foreach ( $forbidden_keys as $key ) {
				$this->assertArrayNotHasKey( $key, $trans_payload, "Event [{$evt}] top-level payload must not leak sensitive field [{$key}]." );
				$this->assertArrayNotHasKey( $key, $trans_payload['health'], "Event [{$evt}] health block must not leak sensitive field [{$key}]." );
				if ( isset( $trans_payload['previousHealth'] ) && is_array( $trans_payload['previousHealth'] ) ) {
					$this->assertArrayNotHasKey( $key, $trans_payload['previousHealth'], "Event [{$evt}] previousHealth block must not leak sensitive field [{$key}]." );
				}
			}
		}
	}
}
