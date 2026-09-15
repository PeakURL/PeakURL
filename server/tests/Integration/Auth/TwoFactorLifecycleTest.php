<?php
/**
 * Integration test for Two-Factor Authentication lifecycle and backup codes.
 *
 * @package PeakURL\Tests\Integration\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Services\Database\PeakURL_DB;

class TwoFactorLifecycleTest extends TestCase {

	public function test_backup_code_generation_returns_eight_unique_formatted_codes(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$codes = $credentials->generate_backup_codes( 8 );

		$this->assertCount( 8, $codes );
		$this->assertSame( 8, count( array_unique( $codes ) ) );
		foreach ( $codes as $code ) {
			$this->assertMatchesRegularExpression( '/^[A-F0-9]{4}-[A-F0-9]{4}$/', $code );
		}
	}

	public function test_backup_code_verification_normalizes_hyphens_and_case(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$stored_codes = array( 'A1B2-C3D4', 'E5F6-7890' );

		$db->method( 'get_var_by' )
			->willReturn( json_encode( $stored_codes ) );

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
				array( 'id' => 'user-42' )
			)
			->willReturn( 1 );

		// Input with lowercase and missing hyphen should still verify and consume
		$result = $credentials->verify_backup_code( 'user-42', 'a1b2c3d4' );
		$this->assertTrue( $result );

		$remaining = json_decode( (string) $captured_update['backup_codes_json'], true );
		$this->assertSame( array( 'E5F6-7890' ), $remaining );
	}

	public function test_backup_code_verification_rejects_invalid_or_empty_codes(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$stored_codes = array( 'E5F6-7890' );

		$db->method( 'get_var_by' )
			->willReturn( json_encode( $stored_codes ) );

		$db->expects( $this->never() )->method( 'update' );

		$this->assertFalse( $credentials->verify_backup_code( 'user-42', 'INVALID-CODE' ) );
		$this->assertFalse( $credentials->verify_backup_code( 'user-42', '' ) );
	}
}
