<?php
/**
 * Unit Tests for Destination Health Probe Transport.
 *
 * @package PeakURL\Tests\Unit\Links\Health
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links\Health;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Links\Health\Probe;

class ProbeTest extends TestCase {

	public function test_host_mapping_formats_ipv4_entry(): void {
		$entry = Probe::host_mapping( 'example.com', 443, '93.184.216.34' );

		$this->assertSame( 'example.com:443:93.184.216.34', $entry );
	}

	public function test_host_mapping_formats_ipv6_entry_with_brackets(): void {
		$entry1 = Probe::host_mapping( 'example.com', 443, '2606:4700:4700::1111' );
		$this->assertSame( 'example.com:443:[2606:4700:4700::1111]', $entry1 );

		// Host with authority brackets stripped
		$entry2 = Probe::host_mapping( '[2606:4700:4700::1111]', 443, '2606:4700:4700::1111' );
		$this->assertSame( '2606:4700:4700::1111:443:[2606:4700:4700::1111]', $entry2 );
	}

	public function test_curl_options_enforces_tls_verification(): void {
		$probe   = new Probe();
		$options = $probe->curl_options(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			2500
		);

		$this->assertTrue( $options[ CURLOPT_SSL_VERIFYPEER ] );
		$this->assertSame( 2, $options[ CURLOPT_SSL_VERIFYHOST ] );
	}

	public function test_curl_options_disables_redirect_following(): void {
		$probe   = new Probe();
		$options = $probe->curl_options(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			2500
		);

		$this->assertFalse( $options[ CURLOPT_FOLLOWLOCATION ] );
	}

	public function test_curl_options_explicitly_disables_proxy(): void {
		$probe   = new Probe();
		$options = $probe->curl_options(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			2500
		);

		$this->assertArrayHasKey( CURLOPT_PROXY, $options );
		$this->assertSame( '', $options[ CURLOPT_PROXY ] );
	}

	public function test_curl_options_omits_resolve_for_ipv4_and_ipv6_literals(): void {
		$probe = new Probe();

		// IPv4 literal
		$ipv4_options = $probe->curl_options(
			'http://93.184.216.34/test',
			'93.184.216.34',
			80,
			'93.184.216.34',
			2500
		);
		$this->assertArrayNotHasKey( CURLOPT_RESOLVE, $ipv4_options );

		// Bracketed IPv6 literal
		$ipv6_options = $probe->curl_options(
			'https://[2606:4700:4700::1111]/test',
			'[2606:4700:4700::1111]',
			443,
			'2606:4700:4700::1111',
			2500
		);
		$this->assertArrayNotHasKey( CURLOPT_RESOLVE, $ipv6_options );
	}

	public function test_curl_options_includes_resolve_for_hostnames(): void {
		$probe   = new Probe();
		$options = $probe->curl_options(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			2500
		);

		$this->assertArrayHasKey( CURLOPT_RESOLVE, $options );
		$this->assertSame( array( 'example.com:443:93.184.216.34' ), $options[ CURLOPT_RESOLVE ] );
	}

	public function test_curl_options_disables_body_storage(): void {
		$probe   = new Probe();
		$options = $probe->curl_options(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			2500
		);

		$this->assertTrue( $options[ CURLOPT_NOBODY ] );
	}

	public function test_curl_options_for_get_fallback_enables_get_and_disables_nobody_and_proxy(): void {
		$probe   = new Probe();
		$options = $probe->curl_options(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			2500,
			'GET'
		);

		$this->assertTrue( $options[ CURLOPT_HTTPGET ] );
		$this->assertFalse( $options[ CURLOPT_NOBODY ] );
		$this->assertFalse( $options[ CURLOPT_HEADER ] );
		$this->assertFalse( $options[ CURLOPT_RETURNTRANSFER ] );
		$this->assertFalse( $options[ CURLOPT_FOLLOWLOCATION ] );
		$this->assertTrue( $options[ CURLOPT_SSL_VERIFYPEER ] );
		$this->assertSame( '', $options[ CURLOPT_PROXY ] );
		$this->assertSame( array( 'example.com:443:93.184.216.34' ), $options[ CURLOPT_RESOLVE ] );
	}

	public function test_curl_options_timeout_respects_remaining_budget(): void {
		$probe    = new Probe();
		$options1 = $probe->curl_options(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			850
		);

		$this->assertSame( 850, $options1[ CURLOPT_TIMEOUT_MS ] );
		$this->assertSame( 850, $options1[ CURLOPT_CONNECTTIMEOUT_MS ] );

		$options2 = $probe->curl_options(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			0
		);

		// Never drops below 1 ms
		$this->assertSame( 1, $options2[ CURLOPT_TIMEOUT_MS ] );
		$this->assertSame( 1, $options2[ CURLOPT_CONNECTTIMEOUT_MS ] );
	}

	public function test_extract_location_header(): void {
		$headers = "HTTP/1.1 301 Moved Permanently\r\n" .
			"Date: Wed, 01 Oct 2026 12:00:00 GMT\r\n" .
			"Location: https://new.example.com/path?foo=bar\r\n" .
			"Content-Type: text/html\r\n\r\n";

		$this->assertSame(
			'https://new.example.com/path?foo=bar',
			Probe::extract_location_header( $headers )
		);

		$no_location = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n";
		$this->assertNull( Probe::extract_location_header( $no_location ) );
	}

	public function test_probe_delegates_to_custom_prober_when_injected(): void {
		$called = false;
		$probe  = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port ) use ( &$called ): array {
				$called = true;
				return array(
					'response_code' => 200,
					'duration_ms'   => 42,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$result = $probe->probe(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			3000
		);

		$this->assertTrue( $called );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertSame( 42, $result['duration_ms'] );
	}

	public function test_curl_probe_fails_closed_when_transport_configuration_fails(): void {
		$probe = new class() extends Probe {
			public function curl_options(
				string $url,
				string $host,
				int $port,
				string $pinned_ip,
				int $timeout_ms,
				string $method = 'HEAD'
			): array {
				// Inject an invalid cURL option to cause curl_setopt_array() to return false.
				return array(
					-99999 => 'invalid_option',
				);
			}
		};

		$result = $probe->curl_probe(
			'https://example.com/test',
			'example.com',
			443,
			'93.184.216.34',
			3000
		);

		$this->assertNull( $result['response_code'] );
		$this->assertSame( 1, $result['error_code'] );
		$this->assertSame( 'Failed to configure transport options.', $result['error_message'] );
	}
}
