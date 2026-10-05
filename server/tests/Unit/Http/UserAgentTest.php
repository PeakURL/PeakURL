<?php
/**
 * Unit tests for PeakURL Outbound HTTP User-Agent.
 *
 * @package PeakURL\Tests\Unit\Http
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PeakURL\Http\UserAgent;

class UserAgentTest extends TestCase {

	public function test_canonical_user_agent_format(): void {
		$this->assertSame( 'PeakURL/1.7.2', UserAgent::format( '1.7.2' ) );
		$this->assertSame( 'PeakURL/2.0.0-beta.1', UserAgent::format( '2.0.0-beta.1' ) );
	}

	public function test_version_substitution_and_product_structure(): void {
		$version = '1.0.0';
		$ua      = UserAgent::format( $version );

		$this->assertSame( 'PeakURL/' . $version, $ua );
		$this->assertStringStartsWith( 'PeakURL/', $ua );
	}

	public function test_absence_of_project_url_and_purpose_contexts(): void {
		$ua = UserAgent::format( '1.7.2' );

		$this->assertStringNotContainsString( 'https://', $ua );
		$this->assertStringNotContainsString( 'peakurl.org', $ua );
		$this->assertStringNotContainsString( '(', $ua );
		$this->assertStringNotContainsString( ')', $ua );
		$this->assertStringNotContainsString( ';', $ua );
	}

	public function test_whitespace_and_newline_sanitization(): void {
		$this->assertSame( 'PeakURL/1.7.2', UserAgent::format( "  1.7.2 \t\r\n" ) );
		$this->assertSame( 'PeakURL/1.7.2', UserAgent::format( "1.7.2\r\nInjected-Header: evil" ) );
	}
}
