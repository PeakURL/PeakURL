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
}
