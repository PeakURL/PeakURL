<?php
/**
 * Unit tests for Date utility.
 *
 * @package PeakURL\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Utils;

use PeakURL\Utils\Date;
use PHPUnit\Framework\TestCase;

class DateTest extends TestCase {

	public function test_to_iso_parses_mysql_utc_datetime_accurately(): void {
		$mysql_datetime = '2026-09-14 17:30:00';
		$iso            = Date::to_iso( $mysql_datetime );

		$this->assertSame( '2026-09-14T17:30:00+00:00', $iso );
	}

	public function test_to_iso_preserves_explicit_timezone_offsets(): void {
		$with_offset = '2026-09-14T19:30:00+02:00';
		$iso         = Date::to_iso( $with_offset );

		$this->assertSame( '2026-09-14T17:30:00+00:00', $iso );
	}

	public function test_to_iso_handles_empty_or_invalid_strings(): void {
		$empty_iso = Date::to_iso( '' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $empty_iso );

		$invalid_iso = Date::to_iso( 'not-a-date' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $invalid_iso );
	}

	public function test_mysql_to_rfc3339_returns_null_for_empty_values(): void {
		$this->assertNull( Date::mysql_to_rfc3339( null ) );
		$this->assertNull( Date::mysql_to_rfc3339( '' ) );
		$this->assertNull( Date::mysql_to_rfc3339( '   ' ) );
	}

	public function test_mysql_to_rfc3339_converts_valid_mysql_string(): void {
		$iso = Date::mysql_to_rfc3339( '2026-09-14 12:00:00' );
		$this->assertSame( '2026-09-14T12:00:00+00:00', $iso );
	}
}
