<?php
/**
 * Unit tests for Auth Validator (QA-005 regression).
 *
 * @package PeakURL\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PeakURL\Features\Auth\Validator;
use PeakURL\Core\Errors\ApiException;

class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new Validator();
	}

	public function test_sanitize_key_label_preserves_clean_string(): void {
		$label = 'Production API Key';
		$this->assertSame( 'Production API Key', $this->validator->sanitize_key_label( $label ) );
	}

	public function test_sanitize_key_label_strips_html_tags(): void {
		$label = '<script>alert(1)</script><b>Valid Label</b>';
		$this->assertSame( 'alert(1)Valid Label', $this->validator->sanitize_key_label( $label ) );
	}

	public function test_sanitize_key_label_defaults_when_empty(): void {
		$this->assertSame( 'Generated Key', $this->validator->sanitize_key_label( '' ) );
		$this->assertSame( 'Generated Key', $this->validator->sanitize_key_label( '   ' ) );
	}

	public function test_validate_email_or_username_accepts_valid_email(): void {
		$this->assertSame( 'user@example.com', $this->validator->validate_email_or_username( 'USER@EXAMPLE.COM' ) );
	}

	public function test_validate_email_or_username_accepts_valid_username(): void {
		$this->assertSame( 'admin_user', $this->validator->validate_email_or_username( 'admin_user' ) );
	}

	public function test_validate_email_or_username_rejects_empty(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_email_or_username( '' );
	}

	public function test_validate_user_login_normalizes_lowercase_and_accepts_professional_usernames(): void {
		$this->assertSame( 'abc', $this->validator->validate_user_login( 'abc' ) );
		$this->assertSame( 'john', $this->validator->validate_user_login( 'john' ) );
		$this->assertSame( 'john-doe', $this->validator->validate_user_login( 'john-doe' ) );
		$this->assertSame( 'john_doe', $this->validator->validate_user_login( 'john_doe' ) );
		$this->assertSame( 'john123', $this->validator->validate_user_login( 'john123' ) );
		$this->assertSame( 'john-doe123', $this->validator->validate_user_login( 'john-doe123' ) );
		$this->assertSame( 'john-doe', $this->validator->validate_user_login( 'John-Doe' ) );
		$this->assertSame( 'john_doe', $this->validator->validate_user_login( 'JOHN_DOE' ) );

		// Exactly 120 chars
		$max_len_username = str_repeat( 'a', 120 );
		$this->assertSame( $max_len_username, $this->validator->validate_user_login( $max_len_username ) );
	}

	#[DataProvider( 'invalid_username_provider' )]
	public function test_validate_user_login_rejects_invalid_characters( string $invalid_username ): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_user_login( $invalid_username );
	}

	public static function invalid_username_provider(): array {
		return array(
			'spaces'           => array( 'John Doe' ),
			'internal spaces'  => array( 'john doe' ),
			'dots'             => array( 'john.doe' ),
			'at symbol'        => array( 'john@doe' ),
			'exclamation'      => array( 'john!doe' ),
			'question mark'    => array( 'john?doe' ),
			'hash'             => array( 'john#doe' ),
			'dollar'           => array( 'john$doe' ),
			'slashes'          => array( 'john/doe' ),
			'plus'             => array( 'john+test' ),
			'emoji'            => array( 'john🚀' ),
			'accented unicode' => array( 'jöhn-doe' ),
			'too short'        => array( 'jo' ),
			'too long'         => array( str_repeat( 'a', 121 ) ),
		);
	}
}
