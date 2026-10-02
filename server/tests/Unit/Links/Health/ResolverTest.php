<?php
/**
 * Unit Tests for Destination Health Resolver.
 *
 * @package PeakURL\Tests\Unit\Links\Health
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links\Health;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Links\Health\Resolver;

class ResolverTest extends TestCase {

	public function test_ipv4_literal_returns_directly_without_dns(): void {
		$dns_called = false;
		$resolver   = new Resolver(
			static function () use ( &$dns_called ): array {
				$dns_called = true;
				return array();
			}
		);

		$ips = $resolver->public_addresses( '93.184.216.34' );

		$this->assertSame( array( '93.184.216.34' ), $ips );
		$this->assertFalse( $dns_called );
	}

	public function test_ipv6_literal_returns_normalized_without_dns(): void {
		$dns_called = false;
		$resolver   = new Resolver(
			static function () use ( &$dns_called ): array {
				$dns_called = true;
				return array();
			}
		);

		$ips = $resolver->public_addresses( '[2606:4700:4700::1111]' );

		$this->assertSame( array( '2606:4700:4700::1111' ), $ips );
		$this->assertFalse( $dns_called );
	}

	public function test_is_public_ip_identifies_public_and_private_ips(): void {
		// Public IPv4
		$this->assertTrue( Resolver::is_public_ip( '93.184.216.34' ) );
		$this->assertTrue( Resolver::is_public_ip( '8.8.8.8' ) );
		$this->assertTrue( Resolver::is_public_ip( '1.1.1.1' ) );

		// Public IPv6
		$this->assertTrue( Resolver::is_public_ip( '2606:4700:4700::1111' ) );
		$this->assertTrue( Resolver::is_public_ip( '2001:4860:4860::8888' ) );

		// Loopback
		$this->assertFalse( Resolver::is_public_ip( '127.0.0.1' ) );
		$this->assertFalse( Resolver::is_public_ip( '127.1.2.3' ) );
		$this->assertFalse( Resolver::is_public_ip( '::1' ) );

		// RFC 1918 Private ranges
		$this->assertFalse( Resolver::is_public_ip( '10.0.0.1' ) );
		$this->assertFalse( Resolver::is_public_ip( '10.10.10.10' ) );
		$this->assertFalse( Resolver::is_public_ip( '172.16.0.1' ) );
		$this->assertFalse( Resolver::is_public_ip( '192.168.0.1' ) );
		$this->assertFalse( Resolver::is_public_ip( '192.168.1.1' ) );

		// Cloud metadata / link-local
		$this->assertFalse( Resolver::is_public_ip( '169.254.169.254' ) );
		$this->assertFalse( Resolver::is_public_ip( '169.254.1.1' ) );
		$this->assertFalse( Resolver::is_public_ip( 'fe80::1' ) );

		// Unspecified
		$this->assertFalse( Resolver::is_public_ip( '0.0.0.0' ) );
		$this->assertFalse( Resolver::is_public_ip( '::' ) );

		// Invalid strings
		$this->assertFalse( Resolver::is_public_ip( 'not-an-ip' ) );
		$this->assertFalse( Resolver::is_public_ip( '' ) );
	}

	public function test_mixed_dns_results_filter_to_public_only(): void {
		$resolver = new Resolver(
			static function ( string $host ): array {
				return array( '10.0.0.1', '93.184.216.34', '192.168.1.1', '2606:4700:4700::1111' );
			}
		);

		$public_ips = $resolver->public_addresses( 'mixed.example.com' );

		$this->assertSame( array( '93.184.216.34', '2606:4700:4700::1111' ), $public_ips );
	}

	public function test_no_dns_result_returns_empty(): void {
		$resolver = new Resolver(
			static function ( string $host ): array {
				return array();
			}
		);

		$this->assertSame( array(), $resolver->public_addresses( 'nonexistent.example.com' ) );
	}

	public function test_unsafe_dns_results_return_empty(): void {
		$resolver = new Resolver(
			static function ( string $host ): array {
				return array( '127.0.0.1', '10.1.2.3', '169.254.169.254', '::1' );
			}
		);

		$this->assertSame( array(), $resolver->public_addresses( 'internal.example.com' ) );
	}

	public function test_localhost_and_local_domain_rejected(): void {
		$resolver = new Resolver();

		$this->assertSame( array(), $resolver->public_addresses( 'localhost' ) );
		$this->assertSame( array(), $resolver->public_addresses( 'printer.local' ) );
		$this->assertSame( array(), $resolver->public_addresses( '' ) );
	}

	public function test_redirect_absolute_url(): void {
		$this->assertSame(
			'https://other.com/target',
			Resolver::redirect_target( 'https://example.com/base', 'https://other.com/target' )
		);
	}

	public function test_redirect_protocol_relative_url(): void {
		$this->assertSame(
			'https://cdn.example.com/asset.js',
			Resolver::redirect_target( 'https://example.com/app', '//cdn.example.com/asset.js' )
		);
		$this->assertSame(
			'http://cdn.example.com/asset.js',
			Resolver::redirect_target( 'http://example.com/app', '//cdn.example.com/asset.js' )
		);
	}

	public function test_redirect_root_relative_path(): void {
		$this->assertSame(
			'https://example.com/destination',
			Resolver::redirect_target( 'https://example.com/deep/nested/page', '/destination' )
		);
	}

	public function test_redirect_path_relative(): void {
		$this->assertSame(
			'https://example.com/dir/sub/file.html',
			Resolver::redirect_target( 'https://example.com/dir/', 'sub/file.html' )
		);
	}

	public function test_redirect_dot_segments(): void {
		// ../ against file base
		$this->assertSame(
			'https://example.com/a/d',
			Resolver::redirect_target( 'https://example.com/a/b/c', '../d' )
		);

		// ./ against file base
		$this->assertSame(
			'https://example.com/a/b/d',
			Resolver::redirect_target( 'https://example.com/a/b/c', './d' )
		);

		// ../ against directory base
		$this->assertSame(
			'https://example.com/a/b/d',
			Resolver::redirect_target( 'https://example.com/a/b/c/', '../d' )
		);

		// ../../ against directory base
		$this->assertSame(
			'https://example.com/a/d',
			Resolver::redirect_target( 'https://example.com/a/b/c/', '../../d' )
		);
	}

	public function test_redirect_query_only_and_fragment_only(): void {
		$this->assertSame(
			'https://example.com/dir/page?page=2',
			Resolver::redirect_target( 'https://example.com/dir/page?a=1', '?page=2' )
		);

		$this->assertSame(
			'https://example.com/dir/page?a=1#section',
			Resolver::redirect_target( 'https://example.com/dir/page?a=1', '#section' )
		);
	}

	public function test_redirect_custom_port(): void {
		$this->assertSame(
			'https://example.com:8443/other',
			Resolver::redirect_target( 'https://example.com:8443/base', '/other' )
		);

		$this->assertSame(
			'https://example.com:8443/base?q=1',
			Resolver::redirect_target( 'https://example.com:8443/base', '?q=1' )
		);
	}

	public function test_redirect_unsupported_schemes_rejected(): void {
		$this->assertNull( Resolver::redirect_target( 'https://example.com/base', 'javascript:alert(1)' ) );
		$this->assertNull( Resolver::redirect_target( 'https://example.com/base', 'ftp://example.com/file' ) );
		$this->assertNull( Resolver::redirect_target( 'https://example.com/base', 'data:text/html,<h1>Test</h1>' ) );
		$this->assertNull( Resolver::redirect_target( 'https://example.com/base', 'file:///etc/passwd' ) );
		$this->assertNull( Resolver::redirect_target( 'https://example.com/base', '' ) );
	}

	public function test_normalized_redirect_loop_targets(): void {
		$target1 = Resolver::normalize_target( 'https://example.com/page#section1' );
		$target2 = Resolver::normalize_target( 'https://example.com/page#section2' );
		$target3 = Resolver::normalize_target( 'https://example.com/page' );

		$this->assertSame( 'https://example.com/page', $target1 );
		$this->assertSame( 'https://example.com/page', $target2 );
		$this->assertSame( 'https://example.com/page', $target3 );
		$this->assertSame( $target1, $target2 );
	}

	public function test_rfc3986_dot_segment_normalization_preserves_empty_segments(): void {
		// Preserves meaningful empty path segments
		$this->assertSame( '/foo//bar', Resolver::normalize_path( '/foo//bar' ) );
		$this->assertSame( '//foo/bar', Resolver::normalize_path( '//foo/bar' ) );

		// Standard RFC 3986 dot-segment removals
		$this->assertSame( '/a/b', Resolver::normalize_path( '/a/./b' ) );
		$this->assertSame( '/a/c', Resolver::normalize_path( '/a/b/../c' ) );
		$this->assertSame( '/c', Resolver::normalize_path( '/a/b/../../c' ) );
		$this->assertSame( '/a/b/', Resolver::normalize_path( '/a/b/.' ) );
		$this->assertSame( '/a/', Resolver::normalize_path( '/a/b/..' ) );
		$this->assertSame( 'c', Resolver::normalize_path( '../c' ) );
		$this->assertSame( 'c', Resolver::normalize_path( './c' ) );
		$this->assertSame( '/', Resolver::normalize_path( '/' ) );
		$this->assertSame( '', Resolver::normalize_path( '' ) );
	}
}
