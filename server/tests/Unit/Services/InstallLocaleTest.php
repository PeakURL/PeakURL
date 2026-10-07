<?php
/**
 * Unit tests for installer Locale service.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Install\Locale as InstallLocale;

class InstallLocaleTest extends TestCase {

	private string $root_path;

	protected function setUp(): void {
		parent::setUp();
		$this->root_path = Environment::get_instance()->get_source_root();
	}

	public function test_explicit_supported_locale_takes_precedence_over_browser(): void {
		// Explicit Spanish selection should override French browser header.
		$installer_locale = new InstallLocale(
			$this->root_path,
			'es_ES',
			'fr-FR,fr;q=0.9,en;q=0.8',
		);

		$this->assertSame( 'es_ES', $installer_locale->get_locale() );
		$this->assertSame( 'es-ES', $installer_locale->get_html_lang() );
		$this->assertSame( 'ltr', $installer_locale->get_text_direction() );
	}

	public function test_browser_locale_used_when_explicit_locale_is_absent(): void {
		$installer_locale = new InstallLocale(
			$this->root_path,
			null,
			'de-DE,de;q=0.9,en;q=0.8',
		);

		$this->assertSame( 'de_DE', $installer_locale->get_locale() );
		$this->assertSame( 'de-DE', $installer_locale->get_html_lang() );
		$this->assertSame( 'ltr', $installer_locale->get_text_direction() );
	}

	public function test_browser_locale_used_when_explicit_locale_is_empty_string(): void {
		$installer_locale = new InstallLocale(
			$this->root_path,
			'',
			'fr-FR,fr;q=0.9,en;q=0.8',
		);

		$this->assertSame( 'fr_FR', $installer_locale->get_locale() );
	}

	public function test_invalid_explicit_locale_does_not_suppress_browser_matching(): void {
		// An unsupported/invalid explicit locale must not default before browser matching.
		$installer_locale = new InstallLocale(
			$this->root_path,
			'not-a-supported-locale',
			'es-ES,es;q=0.9,en;q=0.8',
		);

		$this->assertSame( 'es_ES', $installer_locale->get_locale() );
	}

	public function test_invalid_explicit_locale_with_special_characters_evaluates_browser(): void {
		$installer_locale = new InstallLocale(
			$this->root_path,
			'invalid!@#',
			'it-IT,it;q=0.9',
		);

		$this->assertSame( 'it_IT', $installer_locale->get_locale() );
	}

	public function test_default_locale_used_when_no_supported_browser_language(): void {
		$installer_locale = new InstallLocale(
			$this->root_path,
			null,
			'xx-YY,zz-ZZ;q=0.8',
		);

		$this->assertSame( Constants::DEFAULT_LOCALE, $installer_locale->get_locale() );
		$this->assertSame( 'en-US', $installer_locale->get_html_lang() );
		$this->assertSame( 'ltr', $installer_locale->get_text_direction() );
	}

	public function test_default_locale_used_when_accept_language_is_empty(): void {
		$installer_locale = new InstallLocale(
			$this->root_path,
			null,
			'',
		);

		$this->assertSame( Constants::DEFAULT_LOCALE, $installer_locale->get_locale() );
	}

	public function test_rtl_locale_produces_rtl_direction(): void {
		$installer_locale = new InstallLocale(
			$this->root_path,
			'ar',
			null,
		);

		$this->assertSame( 'ar', $installer_locale->get_locale() );
		$this->assertSame( 'ar', $installer_locale->get_html_lang() );
		$this->assertSame( 'rtl', $installer_locale->get_text_direction() );
	}

	public function test_installer_uses_actual_available_language_catalogue(): void {
		$installer_locale = new InstallLocale(
			$this->root_path,
			null,
			null,
		);

		$languages = $installer_locale->list_languages();
		$this->assertNotEmpty( $languages );

		$locales = array_column( $languages, 'locale' );
		$this->assertContains( 'en_US', $locales );
		$this->assertContains( 'es_ES', $locales );
		$this->assertContains( 'fr_FR', $locales );
		$this->assertContains( 'de_DE', $locales );
		$this->assertContains( 'ar', $locales );
	}
}
