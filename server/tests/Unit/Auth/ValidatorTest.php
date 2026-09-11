<?php
/**
 * Unit tests for Auth Validator (QA-005 regression).
 *
 * @package PeakURL\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
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
}
