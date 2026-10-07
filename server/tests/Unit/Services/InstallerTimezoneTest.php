<?php
/**
 * Unit tests for installer timezone selection and validation.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Settings\Validator;
use PeakURL\Services\Install\Manager as InstallManager;

class InstallerTimezoneTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new Validator();
	}

	public function test_resolves_site_timezone_over_browser_timezone(): void {
		$selected = InstallManager::resolve_timezone(
			'Europe/London',
			'America/New_York'
		);

		$this->assertSame( 'Europe/London', $selected );
	}

	public function test_rejects_invalid_site_timezone(): void {
		$this->expectException( \RuntimeException::class );

		InstallManager::resolve_timezone(
			'Invalid/Timezone',
			'America/New_York'
		);
	}

	public function test_resolves_browser_timezone_when_site_timezone_is_not_provided(): void {
		$selected_null  = InstallManager::resolve_timezone( null, 'Asia/Karachi' );
		$selected_empty = InstallManager::resolve_timezone( '', 'Asia/Karachi' );
		$selected_space = InstallManager::resolve_timezone( '   ', 'Asia/Karachi' );

		$this->assertSame( 'Asia/Karachi', $selected_null );
		$this->assertSame( 'Asia/Karachi', $selected_empty );
		$this->assertSame( 'Asia/Karachi', $selected_space );
	}

	public function test_europe_london_is_accepted(): void {
		$this->assertTrue( Validator::is_valid_timezone( 'Europe/London' ) );
		$this->assertSame( 'Europe/London', InstallManager::resolve_timezone( null, 'Europe/London' ) );
		$this->assertSame( 'Europe/London', InstallManager::resolve_timezone( 'Europe/London', null ) );
	}

	public function test_america_new_york_is_accepted(): void {
		$this->assertTrue( Validator::is_valid_timezone( 'America/New_York' ) );
		$this->assertSame( 'America/New_York', InstallManager::resolve_timezone( null, 'America/New_York' ) );
		$this->assertSame( 'America/New_York', InstallManager::resolve_timezone( 'America/New_York', null ) );
	}

	public function test_valid_iana_timezone_with_dst_rules_is_accepted(): void {
		$this->assertTrue( Validator::is_valid_timezone( 'Australia/Sydney' ) );
		$this->assertTrue( Validator::is_valid_timezone( 'Europe/Berlin' ) );
		$this->assertTrue( Validator::is_valid_timezone( 'America/Chicago' ) );

		$this->assertSame( 'Australia/Sydney', InstallManager::resolve_timezone( null, 'Australia/Sydney' ) );
		$this->assertSame( 'Europe/Berlin', InstallManager::resolve_timezone( null, 'Europe/Berlin' ) );
	}

	public function test_falls_back_to_utc_when_browser_timezone_is_unavailable(): void {
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, null ) );
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, '' ) );
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, '   ' ) );
	}

	public function test_falls_back_to_utc_when_browser_timezone_is_unsupported(): void {
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, 'Not/A_Real_Timezone' ) );
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, 'Mars/Curiosity' ) );
	}

	public function test_malformed_browser_value_cannot_be_persisted(): void {
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, '<script>alert(1)</script>' ) );
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, "America/New_York\r\nInjected: true" ) );
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, 'GMT+5' ) );
		$this->assertSame( 'UTC', InstallManager::resolve_timezone( null, '+05:00' ) );
	}

	public function test_validator_is_valid_timezone_recognizes_utc_and_iana(): void {
		$this->assertTrue( Validator::is_valid_timezone( 'UTC' ) );
		$this->assertTrue( Validator::is_valid_timezone( 'Asia/Tokyo' ) );
		$this->assertTrue( Validator::is_valid_timezone( 'Pacific/Auckland' ) );

		$this->assertFalse( Validator::is_valid_timezone( '' ) );
		$this->assertFalse( Validator::is_valid_timezone( '   ' ) );
		$this->assertFalse( Validator::is_valid_timezone( 'Invalid/Tz' ) );
		$this->assertFalse( Validator::is_valid_timezone( 'UTC+2' ) );
	}

	public function test_validator_normalize_timezone_preserves_valid_and_enforces_fallback_rules(): void {
		$this->assertSame( 'Europe/Paris', $this->validator->normalize_timezone( 'Europe/Paris' ) );
		$this->assertSame( 'UTC', $this->validator->normalize_timezone( '' ) );
		$this->assertSame( 'UTC', $this->validator->normalize_timezone( 'Invalid/Tz', true ) );

		$this->expectException( ApiException::class );
		$this->validator->normalize_timezone( 'Invalid/Tz', false );
	}
}
