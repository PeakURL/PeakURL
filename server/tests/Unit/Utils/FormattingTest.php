<?php
/**
 * Unit tests for formatting and peakurl_json_encode helper (QA-006 regression).
 *
 * @package PeakURL\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;

class FormattingTest extends TestCase {

	public function test_peakurl_json_encode_encodes_array_cleanly(): void {
		$data = array(
			'event'  => 'link.created',
			'active' => true,
		);
		$json = \peakurl_json_encode( $data );
		$this->assertSame( '{"event":"link.created","active":true}', $json );
	}

	public function test_peakurl_json_encode_handles_empty_array(): void {
		$this->assertSame( '[]', \peakurl_json_encode( array() ) );
	}

	public function test_sanitize_key_normalizes_case_and_symbols(): void {
		$this->assertSame( 'my-key_123', \sanitize_key( 'My-Key_123!' ) );
	}
}
