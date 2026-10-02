<?php
/**
 * Unit Tests for Destination Health Checker Orchestration.
 *
 * @package PeakURL\Tests\Unit\Links\Health
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links\Health;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Links\Health\Checker;
use PeakURL\Features\Links\Health\Resolver;
use PeakURL\Features\Links\Health\Probe;

class CheckerTest extends TestCase {

	public function test_healthy_response_classification(): void {
		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);
		$probe    = new Probe(
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 120,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$checker  = new Checker( $resolver, $probe, 3.0, 5, 1500 );

		$result = $checker->check( 'https://example.com/ok' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertNull( $result['error_message'] );
		$this->assertSame( 0, $result['redirect_count'] );
	}

	public function test_slow_response_classification(): void {
		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);
		$probe    = new Probe(
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 1600,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$checker  = new Checker( $resolver, $probe, 5.0, 5, 1500 );

		$result = $checker->check( 'https://example.com/slow' );

		$this->assertSame( Checker::STATUS_SLOW, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
	}

	public function test_http_error_classification(): void {
		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);
		$probe    = new Probe(
			static fn() => array(
				'response_code' => 404,
				'duration_ms'   => 80,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$checker  = new Checker( $resolver, $probe );

		$result = $checker->check( 'https://example.com/missing' );

		$this->assertSame( Checker::STATUS_HTTP_ERROR, $result['status'] );
		$this->assertSame( 404, $result['response_code'] );
		$this->assertStringContainsString( '404', (string) $result['error_message'] );
	}

	public function test_dns_failure_classifies_as_dns_error(): void {
		$resolver = new Resolver(
			static fn() => array()
		);
		$probe    = new Probe();
		$checker  = new Checker( $resolver, $probe );

		$result = $checker->check( 'https://unresolvable-domain-xyz.com' );

		$this->assertSame( Checker::STATUS_DNS_ERROR, $result['status'] );
		$this->assertNull( $result['response_code'] );
	}

	public function test_tls_failure_classifies_as_tls_error(): void {
		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);
		$probe    = new Probe(
			static fn() => array(
				'response_code' => null,
				'duration_ms'   => 100,
				'error_code'    => 60, // CURLE_SSL_CACERT
				'error_message' => 'SSL certificate problem: self signed certificate',
				'redirect_url'  => null,
			)
		);
		$checker  = new Checker( $resolver, $probe );

		$result = $checker->check( 'https://self-signed.example.com' );

		$this->assertSame( Checker::STATUS_TLS_ERROR, $result['status'] );
		$this->assertNull( $result['response_code'] );
	}

	public function test_timeout_classifies_as_timeout(): void {
		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);
		$probe    = new Probe(
			static fn() => array(
				'response_code' => null,
				'duration_ms'   => 3000,
				'error_code'    => 28, // CURLE_OPERATION_TIMEDOUT
				'error_message' => 'Operation timed out after 3000 milliseconds',
				'redirect_url'  => null,
			)
		);
		$checker  = new Checker( $resolver, $probe );

		$result = $checker->check( 'https://hanging.example.com' );

		$this->assertSame( Checker::STATUS_TIMEOUT, $result['status'] );
		$this->assertNull( $result['response_code'] );
	}

	public function test_unreachable_classifies_as_unreachable(): void {
		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);
		$probe    = new Probe(
			static fn() => array(
				'response_code' => null,
				'duration_ms'   => 50,
				'error_code'    => 7, // CURLE_COULDNT_CONNECT
				'error_message' => 'Failed to connect to host',
				'redirect_url'  => null,
			)
		);
		$checker  = new Checker( $resolver, $probe );

		$result = $checker->check( 'https://refused.example.com' );

		$this->assertSame( Checker::STATUS_UNREACHABLE, $result['status'] );
	}

	public function test_ssrf_blocked_destinations(): void {
		$resolver = new Resolver(
			static fn() => array( '10.0.0.1' )
		);
		$probe    = new Probe();
		$checker  = new Checker( $resolver, $probe );

		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $checker->check( 'http://127.0.0.1' )['status'] );
		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $checker->check( 'http://169.254.169.254' )['status'] );
		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $checker->check( 'http://localhost' )['status'] );
		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $checker->check( 'http://printer.local' )['status'] );
		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $checker->check( 'http://private.example.com' )['status'] );
		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $checker->check( 'ftp://example.com' )['status'] );
	}

	public function test_redirect_loop_detected(): void {
		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);

		$hop   = 0;
		$probe = new Probe(
			static function ( string $url ) use ( &$hop ): array {
				++$hop;
				return array(
					'response_code' => 301,
					'duration_ms'   => 20,
					'error_code'    => 0,
					'error_message' => '',
					// Loop back to /a (even with fragment difference)
					'redirect_url'  => 1 === $hop ? '/b' : '/a#frag',
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/a' );

		$this->assertSame( Checker::STATUS_REDIRECT_LOOP, $result['status'] );
	}

	public function test_maximum_redirects_exceeded(): void {
		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);

		$hop   = 0;
		$probe = new Probe(
			static function () use ( &$hop ): array {
				++$hop;
				return array(
					'response_code' => 302,
					'duration_ms'   => 15,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => "/step-{$hop}",
				);
			}
		);

		$checker = new Checker( $resolver, $probe, 5.0, 3 );
		$result  = $checker->check( 'https://example.com/start' );

		$this->assertSame( Checker::STATUS_REDIRECT_LOOP, $result['status'] );
		$this->assertStringContainsString( '3 hops', (string) $result['error_message'] );
	}

	public function test_multiple_public_addresses_first_fails_second_succeeds(): void {
		$attempted_ips = array();
		$resolver      = new Resolver(
			static fn() => array( '198.51.100.1', '198.51.100.2' )
		);
		$probe         = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip ) use ( &$attempted_ips ): array {
				$attempted_ips[] = $pinned_ip;
				if ( '198.51.100.1' === $pinned_ip ) {
					return array(
						'response_code' => null,
						'duration_ms'   => 300,
						'error_code'    => 7, // CURLE_COULDNT_CONNECT
						'error_message' => 'Failed to connect to 198.51.100.1',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 200,
					'duration_ms'   => 150,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://multi-ip.example.com' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertSame( array( '198.51.100.1', '198.51.100.2' ), $attempted_ips );
	}

	public function test_timeout_budget_decreases_across_attempts_and_hops(): void {
		$virtual_now_ms    = 0;
		$mock_clock        = static function () use ( &$virtual_now_ms ): int {
			return (int) ( $virtual_now_ms * 1e6 );
		};
		$received_timeouts = array();

		$resolver = new Resolver(
			static fn() => array( '93.184.216.34' )
		);
		$probe    = new Probe(
			static function ( string $url, int $remaining_ms ) use ( &$virtual_now_ms, &$received_timeouts ): array {
				$received_timeouts[] = $remaining_ms;
				$virtual_now_ms     += 1200; // Advance clock by 1200 ms
				return array(
					'response_code' => 301,
					'duration_ms'   => 1200,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => 'https://example.com/step2',
				);
			}
		);

		// Budget: 3000 ms total
		$checker = new Checker( $resolver, $probe, 3.0, 5, 1500, $mock_clock );
		$result  = $checker->check( 'https://example.com/step1' );

		$this->assertCount( 2, $received_timeouts );
		// Hop 1: full 3000ms budget
		$this->assertSame( 3000, $received_timeouts[0] );
		// Hop 2: 3000 - 1200 = 1800ms remaining
		$this->assertSame( 1800, $received_timeouts[1] );
	}

	public function test_multi_ip_tls_error_takes_precedence_over_connection_error(): void {
		$resolver = new Resolver(
			static fn() => array( '198.51.100.1', '198.51.100.2' )
		);
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip ): array {
				if ( '198.51.100.1' === $pinned_ip ) {
					return array(
						'response_code' => null,
						'duration_ms'   => 50,
						'error_code'    => 35,
						'error_message' => 'SSL connect error',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => null,
					'duration_ms'   => 50,
					'error_code'    => 7,
					'error_message' => 'Failed to connect',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://multi.example.com' );

		// TLS failure takes precedence over generic connection failure
		$this->assertSame( Checker::STATUS_TLS_ERROR, $result['status'] );
		$this->assertNull( $result['response_code'] );
	}

	public function test_multi_ip_retry_recovers_from_timeout_with_successful_response(): void {
		$resolver = new Resolver(
			static fn() => array( '198.51.100.1', '198.51.100.2' )
		);
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip ): array {
				if ( '198.51.100.1' === $pinned_ip ) {
					return array(
						'response_code' => null,
						'duration_ms'   => 800,
						'error_code'    => 28,
						'error_message' => 'Operation timed out',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 200,
					'duration_ms'   => 100,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe, 3.0 );
		$result  = $checker->check( 'https://multi.example.com' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
	}

	public function test_multi_ip_retry_recovers_from_connection_error_with_http_403(): void {
		$resolver = new Resolver(
			static fn() => array( '198.51.100.1', '198.51.100.2' )
		);
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip ): array {
				if ( '198.51.100.1' === $pinned_ip ) {
					return array(
						'response_code' => null,
						'duration_ms'   => 50,
						'error_code'    => 7,
						'error_message' => 'Failed to connect',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 403,
					'duration_ms'   => 80,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://multi.example.com' );

		$this->assertSame( Checker::STATUS_HTTP_ERROR, $result['status'] );
		$this->assertSame( 403, $result['response_code'] );
	}

	public function test_head_200_does_not_trigger_get_fallback(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				return array(
					'response_code' => 200,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/direct-ok' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertSame( array( 'HEAD' ), $methods );
	}

	public function test_head_301_follows_redirect_without_get_fallback(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				if ( str_contains( $url, 'redir' ) ) {
					return array(
						'response_code' => 301,
						'duration_ms'   => 50,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => 'https://example.com/final',
					);
				}
				return array(
					'response_code' => 200,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/redir' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertSame( 1, $result['redirect_count'] );
		$this->assertSame( array( 'HEAD', 'HEAD' ), $methods );
	}

	public function test_head_404_with_get_200_returns_healthy_200(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				if ( 'HEAD' === $method ) {
					return array(
						'response_code' => 404,
						'duration_ms'   => 40,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 200,
					'duration_ms'   => 60,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/spa-route' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertNull( $result['error_message'] );
		$this->assertSame( array( 'HEAD', 'GET' ), $methods );
	}

	public function test_n8n_express_spa_regression_head_404_with_get_200(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				if ( 'HEAD' === $method ) {
					return array(
						'response_code' => 404,
						'duration_ms'   => 50,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 200,
					'duration_ms'   => 70,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://n8n.techsysforge.com/workflow/example' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertSame( array( 'HEAD', 'GET' ), $methods );
	}

	public function test_head_200_only_calls_head_method(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				return array(
					'response_code' => 200,
					'duration_ms'   => 35,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/healthy' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertSame( array( 'HEAD' ), $methods );
	}

	public function test_head_405_with_get_200_returns_healthy_200(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				if ( 'HEAD' === $method ) {
					return array(
						'response_code' => 405,
						'duration_ms'   => 40,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 200,
					'duration_ms'   => 60,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/no-head' );

		$this->assertSame( Checker::STATUS_HEALTHY, $result['status'] );
		$this->assertSame( 200, $result['response_code'] );
		$this->assertSame( array( 'HEAD', 'GET' ), $methods );
	}

	public function test_head_404_with_get_404_returns_http_error_404(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				return array(
					'response_code' => 404,
					'duration_ms'   => 40,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/really-missing' );

		$this->assertSame( Checker::STATUS_HTTP_ERROR, $result['status'] );
		$this->assertSame( 404, $result['response_code'] );
		$this->assertSame( array( 'HEAD', 'GET' ), $methods );
	}

	public function test_head_405_with_get_500_returns_http_error_500(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				if ( 'HEAD' === $method ) {
					return array(
						'response_code' => 405,
						'duration_ms'   => 40,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 500,
					'duration_ms'   => 60,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/server-error' );

		$this->assertSame( Checker::STATUS_HTTP_ERROR, $result['status'] );
		$this->assertSame( 500, $result['response_code'] );
		$this->assertSame( array( 'HEAD', 'GET' ), $methods );
	}

	public function test_head_404_with_get_timeout_returns_timeout_status(): void {
		$resolver = new Resolver( static fn() => array( '93.184.216.34' ) );
		$methods  = array();
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( &$methods ): array {
				$methods[] = $method;
				if ( 'HEAD' === $method ) {
					return array(
						'response_code' => 404,
						'duration_ms'   => 40,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => null,
					'duration_ms'   => 2900,
					'error_code'    => 28,
					'error_message' => 'Operation timed out',
					'redirect_url'  => null,
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/get-times-out' );

		$this->assertSame( Checker::STATUS_TIMEOUT, $result['status'] );
		$this->assertNull( $result['response_code'] );
		$this->assertSame( array( 'HEAD', 'GET' ), $methods );
	}

	public function test_head_404_with_get_redirect_to_private_ip_is_ssrf_blocked(): void {
		$resolver = new Resolver(
			static function ( string $host ): array {
				if ( 'example.com' === $host ) {
					return array( '93.184.216.34' );
				}
				// 10.0.0.1 has no public address.
				return array();
			}
		);
		$probe    = new Probe(
			static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ): array {
				if ( 'HEAD' === $method ) {
					return array(
						'response_code' => 404,
						'duration_ms'   => 40,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 302,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => 'http://10.0.0.1/admin',
				);
			}
		);

		$checker = new Checker( $resolver, $probe );
		$result  = $checker->check( 'https://example.com/sneaky-redirect' );

		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $result['status'] );
	}
}
