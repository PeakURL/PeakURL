<?php
/**
 * Unit tests for Auth Credentials (2FA backup codes).
 *
 * @package PeakURL\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Services\Database\PeakURL_DB;

class CredentialsTest extends TestCase {

	public function test_replace_backup_codes_persists_and_returns_eight_unique_codes(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$captured_data = null;
		$db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->callback(
					function ( $data ) use ( &$captured_data ) {
						$captured_data = $data;
						return isset( $data['backup_codes_json'] ) && isset( $data['backup_codes_generated_at'] );
					}
				),
				array( 'id' => 'user-1' )
			)
			->willReturn( 1 );

		$codes = $credentials->replace_backup_codes( 'user-1' );

		$this->assertCount( 8, $codes );
		$this->assertSame( 8, count( array_unique( $codes ) ) );
		foreach ( $codes as $code ) {
			$this->assertMatchesRegularExpression( '/^[A-F0-9]{4}-[A-F0-9]{4}$/', $code );
		}
		$this->assertNotNull( $captured_data );

		$decoded = json_decode( (string) $captured_data['backup_codes_json'], true );
		$this->assertSame( $codes, $decoded );
	}

	public function test_verify_backup_code_accepts_valid_code_and_consumes_it(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$initial_codes = array( 'A1B2-C3D4', 'E5F6-7890' );

		$db->method( 'get_var' )
			->with(
				'SELECT backup_codes_json FROM users WHERE id = :id FOR UPDATE',
				array( 'id' => 'user-1' )
			)
			->willReturn( json_encode( $initial_codes ) );

		$captured_update = null;
		$db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->callback(
					function ( $data ) use ( &$captured_update ) {
						$captured_update = $data;
						return true;
					}
				),
				array( 'id' => 'user-1' )
			)
			->willReturn( 1 );

		// Accept with lowercase and without hyphens
		$verified = $credentials->verify_backup_code( 'user-1', 'a1b2c3d4' );
		$this->assertTrue( $verified );

		$this->assertNotNull( $captured_update );
		$remaining = json_decode( (string) $captured_update['backup_codes_json'], true );
		$this->assertSame( array( 'E5F6-7890' ), $remaining );
	}

	public function test_verify_backup_code_rejects_invalid_or_consumed_code(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$initial_codes = array( 'E5F6-7890' );

		$db->method( 'get_var' )
			->willReturn( json_encode( $initial_codes ) );

		$db->expects( $this->never() )->method( 'update' );

		$this->assertFalse( $credentials->verify_backup_code( 'user-1', 'INVALID-CODE' ) );
		$this->assertFalse( $credentials->verify_backup_code( 'user-1', '' ) );
	}
}
