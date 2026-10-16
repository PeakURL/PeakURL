<?php
/**
 * Verify the public presentation boundary without connecting to a database.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Application;
use PeakURL\Core\Config\Constants;
use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;

class PublicErrorResponseTest extends TestCase {

	public function test_api_errors_and_existing_html_are_preserved(): void {
		$response = JsonResponse::error( 'Private runtime error', 500, array( 'trace' => 'debug information' ) );
		$this->assertSame( $response, $this->format_response( $response, '/api/v1/urls' ) );
		$html = JsonResponse::text( '<form>Access check</form>', 403, 'text/html; charset=utf-8' );
		$this->assertSame( $html, $this->format_response( $html, '/protected' ) );
	}

	public function test_public_errors_keep_status_and_headers_without_production_diagnostics(): void {
		$response                           = JsonResponse::error( 'Private runtime error', 429, array( 'trace' => 'debug information' ) );
		$response['headers']['Retry-After'] = '60';
		$result                             = $this->format_response( $response, '/missing/page' );
		$this->assertSame( 429, $result['status'] );
		$this->assertSame( '60', $result['headers']['Retry-After'] );
		$this->assertSame( 'text/html; charset=utf-8', $result['headers']['Content-Type'] );
		$this->assertStringNotContainsString( 'Private runtime error', $result['body'] );
		$this->assertStringNotContainsString( 'debug information', $result['body'] );
		$this->assertStringContainsString( 'href="/installed/"', $result['body'] );
	}

	public function test_debug_and_development_show_escaped_details_immediately(): void {
		foreach ( array( array( Constants::DEBUG => true ), array( Constants::ENV => 'development' ) ) as $config ) {
			$result = $this->format_response( JsonResponse::error( '<script>failure</script>', 500 ), '/missing', $config );
			$this->assertStringContainsString( '&lt;script&gt;failure&lt;/script&gt;', $result['body'] );
			$this->assertStringNotContainsString( '<details', $result['body'] );
		}
	}

	/** Invoke only the presentation boundary; request dispatch and storage stay isolated. */
	private function format_response( array $response, string $path, array $config = array() ): array {
		$reflection  = new \ReflectionClass( Application::class );
		$application = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty( 'config' )->setValue( $application, $config );
		$request = new Request( 'GET', $path, array(), array(), array(), array(), array( 'SCRIPT_NAME' => '/installed/index.php' ) );
		return $reflection->getMethod( 'format_public_error_response' )->invoke( $application, $response, $request );
	}
}
