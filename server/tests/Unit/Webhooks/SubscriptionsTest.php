<?php
/**
 * Unit tests for Webhooks Subscriptions.
 *
 * @package PeakURL\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Webhooks\Subscriptions;
use PeakURL\Features\Webhooks\Validator;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;

class SubscriptionsTest extends TestCase {

	private PeakURL_DB $db;
	private Validator $validator;
	private AuthService $auth_service;
	private Roles $roles;
	private Authorization $authorization;
	private Crypto $crypto;
	private Subscriptions $subscriptions;

	protected function setUp(): void {
		parent::setUp();
		$this->db            = $this->createMock( PeakURL_DB::class );
		$this->validator     = new Validator();
		$this->auth_service  = $this->createMock( AuthService::class );
		$this->roles         = new Roles();
		$this->authorization = new Authorization( $this->roles );
		$this->crypto        = $this->createMock( Crypto::class );

		$this->subscriptions = new Subscriptions(
			$this->db,
			$this->validator,
			$this->auth_service,
			$this->roles,
			$this->authorization,
			$this->crypto
		);
	}

	public function test_create_webhook_id_has_expected_prefix_and_length(): void {
		$id = $this->subscriptions->create_webhook_id();
		$this->assertStringStartsWith( 'peakurl_wh_', $id );
		$this->assertSame( 43, strlen( $id ) );
	}

	public function test_create_signing_secret_has_expected_prefix(): void {
		$secret = $this->subscriptions->create_signing_secret();
		$this->assertStringStartsWith( 'peakurl_whsec_', $secret );
		$this->assertSame( 50, strlen( $secret ) );
	}


	public function test_mask_webhook_secret_masks_tail_only(): void {
		$secret = 'peakurl_whsec_1234567890abcdef';
		$masked = $this->subscriptions->mask_webhook_secret( $secret );
		$this->assertStringStartsWith( 'peakurl_whsec_1234', $masked );
		$this->assertStringEndsWith( '••••••••••••••••••', $masked );
		$this->assertStringNotContainsString( 'abcdef', $masked );
	}

	public function test_encrypt_secret_delegates_to_crypto_service(): void {
		$this->crypto->expects( $this->once() )
			->method( 'encrypt' )
			->with( 'test_secret' )
			->willReturn( 'enc:v1:encrypted_val' );

		$encrypted = $this->subscriptions->encrypt_secret( 'test_secret' );
		$this->assertSame( 'enc:v1:encrypted_val', $encrypted );
	}

	public function test_encrypt_secret_rejects_empty_string(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->subscriptions->encrypt_secret( '   ' );
	}

	public function test_decrypt_secret_validates_envelope(): void {
		$this->expectException( \RuntimeException::class );
		$this->subscriptions->decrypt_secret( 'raw_unencrypted_secret' );
	}

	public function test_decrypt_secret_returns_plaintext(): void {
		$this->crypto->expects( $this->once() )
			->method( 'decrypt' )
			->with( 'enc:v1:stored_val' )
			->willReturn( 'decrypted_secret' );

		$result = $this->subscriptions->decrypt_secret( 'enc:v1:stored_val' );
		$this->assertSame( 'decrypted_secret', $result );
	}

	public function test_format_webhook_does_not_leak_plaintext_secret_by_default(): void {
		$row = array(
			'id'          => 'peakurl_wh_123',
			'label'       => 'Production Slack',
			'url'         => 'https://example.com/webhook',
			'events'      => '["link.created","link.clicked"]',
			'secret'      => 'enc:v1:encrypted',
			'secret_hint' => 'peakurl_whsec_••••••••••••••••',
			'verify_ssl'  => 1,
			'is_active'   => 1,
			'created_at'  => '2026-10-08 12:00:00',
			'updated_at'  => '2026-10-08 12:00:00',
		);

		$this->db->method( 'in_placeholders' )->willReturn(
			array(
				'sql'    => ':wh_0',
				'params' => array( 'wh_0' => 'peakurl_wh_123' ),
			)
		);
		$this->db->method( 'get_results' )->willReturn( array() );

		$formatted = $this->subscriptions->format_webhook( $row );
		$this->assertArrayNotHasKey( 'secret', $formatted );
		$this->assertSame( 'peakurl_wh_123', $formatted['id'] );
		$this->assertSame( array( 'link.created', 'link.clicked' ), $formatted['events'] );
		$this->assertTrue( $formatted['isActive'] );
		$this->assertTrue( $formatted['verifySsl'] );
	}

	public function test_format_webhook_includes_one_time_secret_when_explicitly_passed(): void {
		$row = array(
			'id'          => 'peakurl_wh_123',
			'label'       => 'Production Slack',
			'url'         => 'https://example.com/webhook',
			'events'      => '["link.created"]',
			'secret'      => 'enc:v1:encrypted',
			'secret_hint' => 'peakurl_whsec_••••••••••••••••',
			'verify_ssl'  => 1,
			'is_active'   => 1,
			'created_at'  => '2026-10-08 12:00:00',
			'updated_at'  => '2026-10-08 12:00:00',
		);

		$health = array(
			'total24h'         => 10,
			'failed24h'        => 0,
			'lastStatus'       => 'delivered',
			'lastResponseCode' => 200,
			'lastError'        => null,
		);

		$formatted = $this->subscriptions->format_webhook( $row, $health, 'peakurl_whsec_onetime' );
		$this->assertArrayHasKey( 'secret', $formatted );
		$this->assertSame( 'peakurl_whsec_onetime', $formatted['secret'] );
		$this->assertSame( $health, $formatted['health'] );
	}
}
