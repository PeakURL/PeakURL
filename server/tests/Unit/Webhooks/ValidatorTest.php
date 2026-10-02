<?php
/**
 * Unit tests for Webhooks Validator (QA-011 regression).
 *
 * @package PeakURL\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Webhooks\Validator;
use PeakURL\Core\Errors\ApiException;

class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new Validator();
	}

	public function test_validate_events_accepts_all_thirteen_supported_events(): void {
		$events = array(
			'link.created',
			'link.updated',
			'link.clicked',
			'link.deleted',
			'link.restored',
			'link.activated',
			'link.deactivated',
			'link.expired',
			'link.health.checked',
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
			'api_key.created',
			'api_key.revoked',
			'user.created',
			'user.updated',
			'user.deleted',
		);
		$result = $this->validator->validate_events( $events );
		$this->assertSame( $events, $result );
	}

	public function test_get_event_catalogue_contains_all_supported_events_with_metadata(): void {
		$catalogue = Validator::get_event_catalogue();
		$this->assertCount( 25, $catalogue );

		$ids = array_column( $catalogue, 'id' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ) );

		$this->assertContains( 'link.created', $ids );
		$this->assertContains( 'link.updated', $ids );
		$this->assertContains( 'link.clicked', $ids );
		$this->assertContains( 'link.deleted', $ids );
		$this->assertContains( 'link.restored', $ids );
		$this->assertContains( 'link.activated', $ids );
		$this->assertContains( 'link.deactivated', $ids );
		$this->assertContains( 'link.expired', $ids );
		$this->assertContains( 'link.health.checked', $ids );
		$this->assertContains( 'link.health.changed', $ids );
		$this->assertContains( 'link.health.recovered', $ids );
		$this->assertContains( 'link.health.degraded', $ids );
		$this->assertContains( 'link.health.broken', $ids );
		$this->assertContains( 'link.health.unreachable', $ids );
		$this->assertContains( 'link.health.dns_error', $ids );
		$this->assertContains( 'link.health.tls_error', $ids );
		$this->assertContains( 'link.health.timeout', $ids );
		$this->assertContains( 'link.health.http_error', $ids );
		$this->assertContains( 'link.health.redirect_loop', $ids );
		$this->assertContains( 'link.health.ssrf_blocked', $ids );
		$this->assertContains( 'api_key.created', $ids );
		$this->assertContains( 'api_key.revoked', $ids );
		$this->assertContains( 'user.created', $ids );
		$this->assertContains( 'user.updated', $ids );
		$this->assertContains( 'user.deleted', $ids );

		// webhook.test must never be in the normal catalogue.
		$this->assertNotContains( 'webhook.test', $ids );

		foreach ( $catalogue as $item ) {
			$this->assertArrayHasKey( 'id', $item );
			$this->assertArrayNotHasKey( 'event', $item );
			$this->assertArrayHasKey( 'label', $item );
			$this->assertArrayHasKey( 'description', $item );
			$this->assertArrayHasKey( 'group', $item );
			$this->assertNotEmpty( $item['label'] );
			$this->assertNotEmpty( $item['description'] );
			$this->assertContains( $item['group'], array( 'link', 'api_key', 'user' ) );
		}
	}

	public function test_validate_events_rejects_unsupported_events(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_events( array( 'invalid.event.name' ) );
	}

	public function test_validate_events_rejects_empty_array(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_events( array() );
	}

	public function test_validate_label_accepts_valid_string_and_trims(): void {
		$label = $this->validator->validate_label( '  Production Notifications  ' );
		$this->assertSame( 'Production Notifications', $label );
	}

	public function test_validate_label_rejects_missing_or_non_string(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_label( null );
	}

	public function test_validate_label_rejects_empty_or_whitespace_string(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_label( "   \t\n   " );
	}

	public function test_validate_label_rejects_string_exceeding_max_length(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_label( str_repeat( 'a', 256 ) );
	}

	public function test_validate_create_rejects_missing_or_empty_label(): void {
		try {
			$this->validator->validate_create(
				array(
					'url'    => 'https://example.com/webhook',
					'events' => array( 'link.created' ),
				)
			);
			$this->fail( 'Expected ApiException when label is missing' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->get_status() );
		}

		try {
			$this->validator->validate_create(
				array(
					'label'  => '   ',
					'url'    => 'https://example.com/webhook',
					'events' => array( 'link.created' ),
				)
			);
			$this->fail( 'Expected ApiException when label is whitespace only' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->get_status() );
		}
	}

	public function test_validate_update_validates_label_when_present(): void {
		// 1. Valid label is trimmed.
		$updates = $this->validator->validate_update( array( 'label' => '  New Name  ' ) );
		$this->assertSame( 'New Name', $updates['label'] );

		// 2. Empty label is rejected.
		try {
			$this->validator->validate_update( array( 'label' => '   ' ) );
			$this->fail( 'Expected ApiException when updating with empty label' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->get_status() );
		}

		// 3. Omitting label leaves it out of updates.
		$no_label_updates = $this->validator->validate_update( array( 'verifySsl' => false ) );
		$this->assertArrayNotHasKey( 'label', $no_label_updates );
	}

	public function test_validate_create_rejects_invalid_url(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_create(
			array(
				'label'  => 'Test Webhook',
				'url'    => 'not-a-valid-url',
				'events' => array( 'link.created' ),
			)
		);
	}

	public function test_validate_create_rejects_http_protocol(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_create(
			array(
				'label'  => 'Test Webhook',
				'url'    => 'http://example.com/webhook',
				'events' => array( 'link.created' ),
			)
		);
	}

	public function test_validate_create_rejects_private_and_loopback_ips(): void {
		$private_urls = array(
			'https://localhost/webhook',
			'https://127.0.0.1/webhook',
			'https://10.0.0.5/webhook',
			'https://172.16.0.1/webhook',
			'https://192.168.1.100/webhook',
			'https://169.254.169.254/latest/meta-data',
			'https://0.0.0.0/webhook',
		);

		foreach ( $private_urls as $url ) {
			try {
				$this->validator->validate_create(
					array(
						'label'  => 'Test Webhook',
						'url'    => $url,
						'events' => array( 'link.created' ),
					)
				);
				$this->fail( "Expected ApiException for SSRF target: {$url}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->get_status() );
			}
		}
	}

	public function test_validate_create_accepts_valid_public_https_url(): void {
		$validated = $this->validator->validate_create(
			array(
				'label'     => 'Public Webhook',
				'url'       => 'https://93.184.216.34/webhook',
				'events'    => array( 'link.created' ),
				'verifySsl' => false,
			)
		);

		$this->assertSame( 'Public Webhook', $validated['label'] );
		$this->assertSame( 'https://93.184.216.34/webhook', $validated['url'] );
		$this->assertSame( 0, $validated['verify_ssl'] );
	}

	public function test_validate_create_handles_verify_ssl_camel_case(): void {
		$created_default = $this->validator->validate_create(
			array(
				'label'  => 'Default Webhook',
				'url'    => 'https://93.184.216.34/webhook',
				'events' => array( 'link.created' ),
			)
		);
		$this->assertSame( 1, $created_default['verify_ssl'] );

		$created_disabled = $this->validator->validate_create(
			array(
				'label'     => 'Disabled SSL',
				'url'       => 'https://93.184.216.34/webhook',
				'events'    => array( 'link.created' ),
				'verifySsl' => false,
			)
		);
		$this->assertSame( 0, $created_disabled['verify_ssl'] );

		// Snake case alias must NOT be honored, defaults to 1.
		$created_snake = $this->validator->validate_create(
			array(
				'label'      => 'Snake Case Test',
				'url'        => 'https://93.184.216.34/webhook',
				'events'     => array( 'link.created' ),
				'verify_ssl' => 0,
			)
		);
		$this->assertSame( 1, $created_snake['verify_ssl'] );
	}

	public function test_validate_create_rejects_unresolvable_hostname(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_create(
			array(
				'label'  => 'Unresolvable',
				'url'    => 'https://non-existent-domain-that-cannot-resolve.invalid/hook',
				'events' => array( 'link.created' ),
			)
		);
	}

	public function test_validate_create_rejects_ipv6_loopback_and_private(): void {
		$ipv6_private_hosts = array(
			'https://[::1]/hook',
			'https://[fe80::1]/hook',
			'https://[fc00::1]/hook',
		);

		foreach ( $ipv6_private_hosts as $url ) {
			try {
				$this->validator->validate_create(
					array(
						'label'  => 'IPv6 Test',
						'url'    => $url,
						'events' => array( 'link.created' ),
					)
				);
				$this->fail( "Expected ApiException for SSRF IPv6 target: {$url}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->get_status() );
			}
		}
	}

	public function test_validate_destination_returns_public_ip_or_null(): void {
		// Valid public IP returns the public IP string.
		$this->assertSame( '93.184.216.34', $this->validator->validate_destination( 'https://93.184.216.34/webhook' ) );

		// Non-HTTPS schemes return null.
		$this->assertNull( $this->validator->validate_destination( 'http://93.184.216.34/webhook' ) );
		$this->assertNull( $this->validator->validate_destination( 'ftp://93.184.216.34/webhook' ) );

		// Malformed or empty URLs return null.
		$this->assertNull( $this->validator->validate_destination( '' ) );
		$this->assertNull( $this->validator->validate_destination( 'not-a-url' ) );
		$this->assertNull( $this->validator->validate_destination( 'https://' ) );

		// Loopback and private destinations return null.
		$this->assertNull( $this->validator->validate_destination( 'https://localhost/hook' ) );
		$this->assertNull( $this->validator->validate_destination( 'https://127.0.0.1/hook' ) );
		$this->assertNull( $this->validator->validate_destination( 'https://10.0.0.1/hook' ) );
		$this->assertNull( $this->validator->validate_destination( 'https://192.168.0.1/hook' ) );
		$this->assertNull( $this->validator->validate_destination( 'https://169.254.169.254/hook' ) );
		$this->assertNull( $this->validator->validate_destination( 'https://[::1]/hook' ) );

		// Unresolvable hostname returns null.
		$this->assertNull( $this->validator->validate_destination( 'https://unresolvable.domain.invalid/hook' ) );
	}
}
