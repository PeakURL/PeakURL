<?php
/**
 * Unit tests for Screen helper and redirect sanitization.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PeakURL\Services\Install\Screen;

class InstallScreenTest extends TestCase {

	#[DataProvider( 'valid_internal_target_provider' )]
	public function test_sanitize_redirect_target_preserves_valid_internal_paths( string $target, string $expected ): void {
		$this->assertSame( $expected, Screen::sanitize_redirect_target( $target ) );
	}

	public static function valid_internal_target_provider(): array {
		return array(
			'dashboard root'            => array( '/dashboard', '/dashboard' ),
			'api route'                 => array( '/api/v1/foo', '/api/v1/foo' ),
			'site root'                 => array( '/', '/' ),
			'dashboard with query'      => array( '/dashboard?page=1&sort=desc', '/dashboard?page=1&sort=desc' ),
			'nested path'               => array( '/settings/general', '/settings/general' ),
			'nested with encoded query' => array( '/urls?search=test%20name', '/urls?search=test%20name' ),
		);
	}

	#[DataProvider( 'unsafe_target_provider' )]
	public function test_sanitize_redirect_target_rejects_unsafe_and_open_redirect_vectors( string $unsafe_target ): void {
		$this->assertSame( '/dashboard', Screen::sanitize_redirect_target( $unsafe_target, '/dashboard' ) );
	}

	public static function unsafe_target_provider(): array {
		return array(
			'https external scheme'          => array( 'https://evil.example' ),
			'http external scheme'           => array( 'http://evil.example' ),
			'protocol relative double slash' => array( '//evil.example' ),
			'backslash variant'              => array( '/\\evil.example' ),
			'encoded backslash'              => array( '/%5Cevil.example' ),
			'encoded double slash'           => array( '/%2f%2fevil.example' ),
			'leading backslash'              => array( '\\evil.example' ),
			'javascript scheme'              => array( 'javascript:alert(1)' ),
			'data scheme'                    => array( 'data:text/html,<html>' ),
			'crlf header injection'          => array( "/dashboard\r\nLocation: https://evil.example" ),
			'crlf newline injection'         => array( "/dashboard\nLocation: https://evil.example" ),
			'null byte injection'            => array( "/dashboard\0evil" ),
			'encoded null byte'              => array( '/dashboard%00evil' ),
			'empty string'                   => array( '' ),
			'whitespace only'                => array( '   ' ),
		);
	}

	public function test_sanitize_redirect_target_uses_custom_fallback(): void {
		$this->assertSame( '/', Screen::sanitize_redirect_target( 'https://evil.example', '/' ) );
		$this->assertSame( '/login', Screen::sanitize_redirect_target( '//evil.example', '/login' ) );
	}

	public function test_format_url_combines_base_path_and_suffix(): void {
		$this->assertSame( '/dashboard', Screen::format_url( '', '/dashboard' ) );
		$this->assertSame( '/sub/dashboard', Screen::format_url( '/sub', '/dashboard' ) );
		$this->assertSame( '/sub/dashboard?page=2', Screen::format_url( '/sub', '/dashboard', array( 'page' => '2' ) ) );
	}
}
