<?php
/**
 * Unit tests for generator meta tag helper (get_generator_tag).
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class GeneratorTagTest extends TestCase {

	public function test_get_generator_tag_with_valid_version(): void {
		$tag = \get_generator_tag( '1.6.2' );
		$this->assertSame( '<meta name="generator" content="PeakURL 1.6.2">', $tag );
	}

	public function test_get_generator_tag_normalizes_whitespace(): void {
		$tag = \get_generator_tag( "  1.6.2 \n\t " );
		$this->assertSame( '<meta name="generator" content="PeakURL 1.6.2">', $tag );
	}

	public function test_get_generator_tag_empty_version_behaviour(): void {
		$this->assertSame( '', \get_generator_tag( '' ) );
		$this->assertSame( '', \get_generator_tag( '   ' ) );
	}

	public function test_get_generator_tag_escapes_html_entities(): void {
		$tag = \get_generator_tag( '1.6.2<script>alert("xss")</script>' );
		$this->assertSame(
			'<meta name="generator" content="PeakURL 1.6.2&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;">',
			$tag,
		);
	}

	public function test_get_generator_tag_resolves_canonical_version_by_default(): void {
		$expected_version = trim( (string) file_get_contents( dirname( __DIR__, 4 ) . '/.version' ) );
		$tag              = \get_generator_tag();
		$this->assertSame( '<meta name="generator" content="PeakURL ' . $expected_version . '">', $tag );

		$tag_with_null = \get_generator_tag( null );
		$this->assertSame( $tag, $tag_with_null );
	}
}
