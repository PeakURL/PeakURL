<?php
/**
 * Integration tests for Auth Controller and Service contracts (QA-003, QA-004, QA-007).
 *
 * @package PeakURL\Tests\Integration\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Auth\Controller as AuthController;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use ReflectionClass;

class AuthContractsTest extends TestCase {

	public function test_forgot_password_service_signature_accepts_request_and_string(): void {
		$ref    = new ReflectionClass( AuthService::class );
		$method = $ref->getMethod( 'forgot_password' );
		$params = $method->getParameters();

		$this->assertCount( 2, $params );
		$this->assertSame( 'request', $params[0]->getName() );
		$this->assertSame( 'string', $params[1]->getType()->getName() );
	}

	public function test_reset_password_service_signature_accepts_request_token_and_password(): void {
		$ref    = new ReflectionClass( AuthService::class );
		$method = $ref->getMethod( 'reset_password' );
		$params = $method->getParameters();

		$this->assertCount( 3, $params );
		$this->assertSame( 'request', $params[0]->getName() );
		$this->assertSame( 'token', $params[1]->getName() );
		$this->assertSame( 'password', $params[2]->getName() );
	}

	public function test_resend_verification_service_signature_accepts_request_and_nullable_email(): void {
		$ref    = new ReflectionClass( AuthService::class );
		$method = $ref->getMethod( 'resend_verification' );
		$params = $method->getParameters();

		$this->assertCount( 2, $params );
		$this->assertSame( 'request', $params[0]->getName() );
		$this->assertSame( 'string', $params[1]->getType()->getName() );
		$this->assertTrue( $params[1]->allowsNull() );
	}
}
