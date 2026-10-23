<?php
/**
 * Unit tests for link input validation and alias sanitization rules.
 *
 * @package PeakURL\Tests\Unit\Links
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Links\Validator;

class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new Validator();
	}

	public function test_sanitize_code_preserves_alphanumeric_hyphens_dots_and_underscores(): void {
		$this->assertSame( 'v1.2.1...v1.2.2', $this->validator->sanitize_code( 'v1.2.1...v1.2.2' ) );
		$this->assertSame( 'my_custom_link', $this->validator->sanitize_code( 'my_custom_link' ) );
		$this->assertSame( 'release-2.0.4', $this->validator->sanitize_code( 'release-2.0.4' ) );
		$this->assertSame( 'helloworld', $this->validator->sanitize_code( 'hello/world' ) );
		$this->assertSame( 'promo', $this->validator->sanitize_code( 'promo+' ) );
		$this->assertSame( 'linkquery1', $this->validator->sanitize_code( 'link?query=1' ) );
		$this->assertSame( 'cleanlink', $this->validator->sanitize_code( '  clean link  ' ) );
	}

	public function test_validate_alias_accepts_valid_aliases(): void {
		$valid_aliases = array(
			'v1.2.1...v1.2.2',
			'my_custom_link',
			'release-2.0',
			'ios_17.4.1',
			'docs.v2',
			'a',
			'123',
			'link-with-dashes',
		);

		foreach ( $valid_aliases as $alias ) {
			$this->validator->validate_alias( $alias );
		}

		$this->assertTrue( true );
	}

	public function test_validate_alias_rejects_empty_code(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'A short code or alias cannot be empty.' );

		$this->validator->validate_alias( '' );
	}

	public function test_validate_alias_rejects_leading_dot(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases must begin and end with a letter or number.' );

		$this->validator->validate_alias( '.env' );
	}

	public function test_validate_alias_rejects_trailing_dot(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases must begin and end with a letter or number.' );

		$this->validator->validate_alias( 'v1.2.' );
	}

	public function test_validate_alias_rejects_leading_underscore(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases must begin and end with a letter or number.' );

		$this->validator->validate_alias( '_my_link' );
	}

	public function test_validate_alias_rejects_path_traversal_dots(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases must begin and end with a letter or number.' );

		$this->validator->validate_alias( '..' );
	}

	public function test_validate_alias_rejects_executable_script_extension(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases cannot end with executable script extensions.' );

		$this->validator->validate_alias( 'test.php' );
	}

	public function test_validate_alias_rejects_shell_script_extension(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases cannot end with executable script extensions.' );

		$this->validator->validate_alias( 'setup.sh' );
	}

	public function test_validate_alias_rejects_reserved_application_routes(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'That short code is reserved by the application.' );

		$this->validator->validate_alias( 'dashboard' );
	}

	public function test_validate_alias_rejects_mcp_and_oauth_as_reserved_routes(): void {
		$this->assertTrue( $this->validator->is_reserved_code( 'mcp' ) );
		$this->assertTrue( $this->validator->is_reserved_code( 'MCP' ) );
		$this->assertTrue( $this->validator->is_reserved_code( 'oauth' ) );
		$this->assertTrue( $this->validator->is_reserved_code( 'OAuth' ) );

		try {
			$this->validator->validate_alias( 'mcp' );
			$this->fail( 'Expected ApiException for reserved alias mcp' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'That short code is reserved by the application.', $e->getMessage() );
		}

		try {
			$this->validator->validate_alias( 'oauth' );
			$this->fail( 'Expected ApiException for reserved alias oauth' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'That short code is reserved by the application.', $e->getMessage() );
		}
	}

	public function test_validate_alias_rejects_existing_duplicate(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'That short code is already in use.' );

		$this->validator->validate_alias(
			'existing-alias',
			'',
			fn( string $code ): bool => true,
		);
	}

	public function test_validate_alias_permits_same_alias_on_update(): void {
		// When current_alias matches the alias being checked, it must not throw even if exists_check returns true
		$this->validator->validate_alias(
			'same-alias',
			'same-alias',
			fn( string $code ): bool => true,
		);

		$this->assertTrue( true );
	}

	public function test_validate_alias_accepts_exactly_64_characters(): void {
		$alias = str_repeat( 'a', 64 );
		$this->validator->validate_alias( $alias );
		$this->assertTrue( true );
	}

	public function test_validate_alias_rejects_more_than_64_characters(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases cannot exceed 64 characters.' );

		$this->validator->validate_alias( str_repeat( 'a', 65 ) );
	}

	public function test_validate_destination_accepts_valid_http_and_https_urls(): void {
		$this->assertSame(
			'https://example.com/target?query=val#section',
			$this->validator->validate_destination( 'https://example.com/target?query=val#section' )
		);
		$this->assertSame(
			'http://example.org/path',
			$this->validator->validate_destination( '  http://example.org/path  ' )
		);
	}

	public function test_validate_destination_rejects_unsupported_schemes_and_invalid_urls(): void {
		$invalid_destinations = array(
			'ftp://example.com/file.txt',
			'file:///etc/passwd',
			'javascript:alert(1)',
			'mailto:user@example.com',
			'data:text/plain;base64,SGVsbG8=',
			'//protocol-relative.com',
			'not-a-valid-url',
			'',
		);

		foreach ( $invalid_destinations as $destination ) {
			try {
				$this->validator->validate_destination( $destination );
				$this->fail( 'Expected ApiException for invalid destination: ' . $destination );
			} catch ( ApiException $exception ) {
				$this->assertSame( 422, $exception->getCode() );
				$this->assertSame( 'A valid destination URL is required.', $exception->getMessage() );
			}
		}
	}

	public function test_normalize_title_decodes_html_entities_and_trims(): void {
		$this->assertSame(
			'Summer & Winter "Sale"',
			$this->validator->normalize_title( '  Summer &amp; Winter &quot;Sale&quot;  ' )
		);
	}

	public function test_normalize_title_decodes_double_encoded_entities_exactly_once(): void {
		$this->assertSame(
			'&lt;script&gt;',
			$this->validator->normalize_title( '&amp;lt;script&amp;gt;' )
		);
	}

	public function test_normalize_title_enforces_191_char_limit(): void {
		$long_title = str_repeat( 'x', 250 );
		$normalized = $this->validator->normalize_title( $long_title );

		$this->assertSame( 191, mb_strlen( $normalized, 'UTF-8' ) );
		$this->assertSame( str_repeat( 'x', 191 ), $normalized );
	}

	public function test_normalize_title_multibyte_utf8_truncation(): void {
		$multibyte_title = str_repeat( '🚀PeakURL測試', 30 ); // 10 chars * 30 = 300 chars
		$normalized      = $this->validator->normalize_title( $multibyte_title );

		$this->assertSame( 191, mb_strlen( $normalized, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $normalized, 'UTF-8' ) );
	}

	public function test_normalize_title_handles_non_string_and_empty(): void {
		$this->assertSame( '', $this->validator->normalize_title( null ) );
		$this->assertSame( '', $this->validator->normalize_title( 12345 ) );
		$this->assertSame( '', $this->validator->normalize_title( '   ' ) );
	}
}
