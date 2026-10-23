<?php
/**
 * Unit tests for PHP 8.3+ runtime preflight guard in load.php.
 *
 * @package PeakURL\Tests\Unit\Config
 * @since 1.7.3
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

class RuntimePreflightTest extends TestCase {

	public function test_runtime_environment_meets_php_floor(): void {
		$this->assertGreaterThanOrEqual(
			80300,
			PHP_VERSION_ID,
			'PHP runtime version ID must be at least 80300 (PHP 8.3.0).'
		);

		$this->assertTrue(
			version_compare( PHP_VERSION, '8.3.0', '>=' ),
			'PHP version string must be 8.3.0 or higher.'
		);
	}

	public function test_load_php_declares_strict_floor_guard(): void {
		$load_php_path = defined( 'ABSPATH' )
			? ABSPATH . 'load.php'
			: dirname( __DIR__, 4 ) . '/load.php';
		$this->assertFileExists( $load_php_path );

		$contents = file_get_contents( $load_php_path );
		$this->assertIsString( $contents );

		$this->assertStringContainsString( 'PHP_VERSION_ID < 80300', $contents );
		$this->assertStringContainsString( "'cli' === PHP_SAPI", $contents );
		$this->assertStringContainsString( 'application/json', $contents );
		$this->assertStringContainsString( 'meta name="viewport"', $contents );
		$this->assertStringContainsString( 'meta name="robots" content="noindex, nofollow"', $contents );
		$this->assertStringContainsString( 'Unsupported PHP Version', $contents );
		$this->assertStringContainsString( 'data-status-theme="auto"', $contents );
		$this->assertStringContainsString( 'status-page', $contents );
		$this->assertStringContainsString( 'status-container', $contents );
		$this->assertStringContainsString( 'status-hero', $contents );
		$this->assertStringContainsString( 'status-artwork', $contents );
		$this->assertStringContainsString( 'powered-by', $contents );
		$this->assertStringContainsString( 'https://peakurl.org/docs/getting-started#installation', $contents );
	}

	public function test_json_error_structure(): void {
		$mock_php_version = '8.2.14';
		$payload          = array(
			'error'           => sprintf( 'PeakURL requires PHP 8.3.0 or higher. Your server is currently running PHP %s.', $mock_php_version ),
			'requiredVersion' => '8.3.0',
			'currentVersion'  => $mock_php_version,
		);

		$json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$this->assertIsString( $json );

		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( '8.3.0', $decoded['requiredVersion'] );
		$this->assertSame( '8.2.14', $decoded['currentVersion'] );
		$this->assertStringContainsString( '8.2.14', $decoded['error'] );
	}

	public function test_negative_unsupported_php_cli_exits_with_error(): void {
		$php82_binary = '/usr/local/opt/php@8.2/bin/php';
		if ( ! is_executable( $php82_binary ) ) {
			$this->markTestSkipped( 'PHP 8.2 binary not found at /usr/local/opt/php@8.2/bin/php for negative test.' );
		}

		$repo_root = dirname( __DIR__, 4 );
		$command   = sprintf(
			'%s -r %s 2>&1',
			escapeshellarg( $php82_binary ),
			escapeshellarg( 'define("ABSPATH", "' . $repo_root . '/"); require "' . $repo_root . '/load.php";' )
		);

		$output      = array();
		$status_code = 0;
		exec( $command, $output, $status_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		$this->assertSame( 1, $status_code );
		$combined_output = implode( "\n", $output );
		$this->assertStringContainsString( 'Error: PeakURL requires PHP 8.3.0 or higher.', $combined_output );
		$this->assertStringContainsString( 'Current version: 8.2.', $combined_output );
	}
}
