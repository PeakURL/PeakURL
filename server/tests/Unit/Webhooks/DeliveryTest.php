<?php
/**
 * Unit tests for Webhooks Delivery engine.
 *
 * @package PeakURL\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Webhooks\Delivery;
use PeakURL\Features\Webhooks\Subscriptions;
use PeakURL\Features\Webhooks\Validator;
use PeakURL\Services\Database\PeakURL_DB;

class DeliveryTest extends TestCase {

	private PeakURL_DB $db;
	private Validator $validator;
	private Subscriptions $subscriptions;
	private Delivery $delivery;

	protected function setUp(): void {
		parent::setUp();
		$this->db            = $this->createMock( PeakURL_DB::class );
		$this->validator     = new Validator();
		$this->subscriptions = $this->createMock( Subscriptions::class );
		$this->delivery      = new Delivery(
			$this->db,
			$this->validator,
			$this->subscriptions,
			array( 'version' => '1.0.0' )
		);
	}


	public function test_create_delivery_id_has_expected_prefix(): void {
		$id = $this->delivery->create_delivery_id();
		$this->assertStringStartsWith( 'peakurl_del_', $id );
		$this->assertSame( 44, strlen( $id ) );
	}


	public function test_compute_signature_matches_expected_hmac_sha256(): void {
		$timestamp = 1700000000;
		$body      = '{"event":"test"}';
		$secret    = 'whsec_secret_123';

		$expected  = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		$signature = $this->delivery->compute_signature( $timestamp, $body, $secret );

		$this->assertSame( $expected, $signature );
	}

	public function test_create_webhook_headers_contains_required_x_peakurl_headers(): void {
		$headers = $this->delivery->create_webhook_headers(
			'link.created',
			1700000000,
			'sig_12345',
			'peakurl_del_abc'
		);

		$this->assertContains( 'Content-Type: application/json; charset=utf-8', $headers );
		$this->assertContains( 'X-PeakURL-Event: link.created', $headers );
		$this->assertContains( 'X-PeakURL-Delivery: peakurl_del_abc', $headers );
		$this->assertContains( 'X-PeakURL-Timestamp: 1700000000', $headers );
		$this->assertContains( 'X-PeakURL-Signature: sig_12345', $headers );
	}

	public function test_is_retryable_failure_classifies_transient_vs_permanent_errors(): void {
		// Transient (retryable)
		$this->assertTrue( $this->delivery->is_retryable_failure( 0 ) );
		$this->assertTrue( $this->delivery->is_retryable_failure( 408 ) );
		$this->assertTrue( $this->delivery->is_retryable_failure( 429 ) );
		$this->assertTrue( $this->delivery->is_retryable_failure( 500 ) );
		$this->assertTrue( $this->delivery->is_retryable_failure( 502 ) );
		$this->assertTrue( $this->delivery->is_retryable_failure( 503 ) );
		$this->assertTrue( $this->delivery->is_retryable_failure( 504 ) );

		// Permanent (not retryable)
		$this->assertFalse( $this->delivery->is_retryable_failure( 400 ) );
		$this->assertFalse( $this->delivery->is_retryable_failure( 401 ) );
		$this->assertFalse( $this->delivery->is_retryable_failure( 403 ) );
		$this->assertFalse( $this->delivery->is_retryable_failure( 404 ) );
		$this->assertFalse( $this->delivery->is_retryable_failure( 410 ) );
		$this->assertFalse( $this->delivery->is_retryable_failure( 422 ) );
	}

	public function test_calculate_retry_delay_exponential_backoff(): void {
		// Attempt 1: ~60s (+1-15 jitter)
		$delay1 = $this->delivery->calculate_retry_delay( 1 );
		$this->assertGreaterThanOrEqual( 61, $delay1 );
		$this->assertLessThanOrEqual( 75, $delay1 );

		// Attempt 2: ~120s (+1-15 jitter)
		$delay2 = $this->delivery->calculate_retry_delay( 2 );
		$this->assertGreaterThanOrEqual( 121, $delay2 );
		$this->assertLessThanOrEqual( 135, $delay2 );

		// Honors numeric Retry-After
		$delay_retry_after = $this->delivery->calculate_retry_delay( 1, '120' );
		$this->assertSame( 120, $delay_retry_after );
	}
}
