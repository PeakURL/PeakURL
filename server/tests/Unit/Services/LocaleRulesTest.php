<?php
/**
 * Unit tests for Locale rules and formatting helper.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\I18n\Locale;

class LocaleRulesTest extends TestCase {

	private Locale $locale;

	protected function setUp(): void {
		parent::setUp();
		$this->locale = new Locale();
	}

	public function test_get_default_locale_returns_constant(): void {
		$this->assertSame( Constants::DEFAULT_LOCALE, $this->locale->get_default_locale() );
		$this->assertSame( 'en_US', $this->locale->get_default_locale() );
	}

	public function test_canonicalize_locale_formats_valid_identifiers(): void {
		$this->assertSame( 'fr_FR', $this->locale->canonicalize_locale( 'fr-FR' ) );
		$this->assertSame( 'fr_FR', $this->locale->canonicalize_locale( 'fr_fr' ) );
		$this->assertSame( 'en_US', $this->locale->canonicalize_locale( 'en-US' ) );
		$this->assertSame( 'en_US', $this->locale->canonicalize_locale( 'en_us' ) );
		$this->assertSame( 'es_ES', $this->locale->canonicalize_locale( 'es-es' ) );
		$this->assertSame( 'ar', $this->locale->canonicalize_locale( 'ar' ) );
		$this->assertSame( 'zh_CN', $this->locale->canonicalize_locale( 'zh-cn' ) );
		$this->assertSame( 'zh_Hans', $this->locale->canonicalize_locale( 'zh-hans' ) );
		$this->assertSame( 'zh_Hans_CN', $this->locale->canonicalize_locale( 'zh-hans-cn' ) );
		$this->assertSame( 'pt_BR', $this->locale->canonicalize_locale( 'pt-br' ) );
		$this->assertSame( 'ur_PK', $this->locale->canonicalize_locale( 'ur-pk' ) );
	}

	public function test_canonicalize_locale_returns_empty_string_for_empty_or_invalid_input(): void {
		$this->assertSame( '', $this->locale->canonicalize_locale( '' ) );
		$this->assertSame( '', $this->locale->canonicalize_locale( '   ' ) );
		$this->assertSame( '', $this->locale->canonicalize_locale( '!invalid' ) );
		$this->assertSame( '', $this->locale->canonicalize_locale( '123' ) );
		$this->assertSame( '', $this->locale->canonicalize_locale( 'en_US_segmentwaytoolongovereightchars' ) );
		$this->assertSame( '', $this->locale->canonicalize_locale( 'invalid!tag' ) );
	}

	public function test_get_base_locale_extracts_base_language(): void {
		$this->assertSame( 'fr', $this->locale->get_base_locale( 'fr_FR' ) );
		$this->assertSame( 'fr', $this->locale->get_base_locale( 'fr-CA' ) );
		$this->assertSame( 'es', $this->locale->get_base_locale( 'es-ES' ) );
		$this->assertSame( 'ar', $this->locale->get_base_locale( 'ar' ) );
		$this->assertSame( 'en', $this->locale->get_base_locale( 'en_US' ) );
		$this->assertSame( 'ur', $this->locale->get_base_locale( 'ur_PK' ) );
		$this->assertSame( 'zh', $this->locale->get_base_locale( 'zh_Hans_CN' ) );
		$this->assertSame( '', $this->locale->get_base_locale( '' ) );
		$this->assertSame( '', $this->locale->get_base_locale( 'invalid!' ) );
	}

	public function test_get_html_lang_formats_attribute_value(): void {
		$this->assertSame( 'fr-FR', $this->locale->get_html_lang( 'fr_FR' ) );
		$this->assertSame( 'en-US', $this->locale->get_html_lang( 'en_US' ) );
		$this->assertSame( 'ar', $this->locale->get_html_lang( 'ar' ) );
		$this->assertSame( 'ur-PK', $this->locale->get_html_lang( 'ur_PK' ) );
		$this->assertSame( 'zh-Hans-CN', $this->locale->get_html_lang( 'zh-hans-cn' ) );
		// Invalid or empty input returns empty string; formatting does not choose a default.
		$this->assertSame( '', $this->locale->get_html_lang( '' ) );
		$this->assertSame( '', $this->locale->get_html_lang( 'invalid!' ) );
	}

	public function test_is_locale_rtl_identifies_rtl_languages(): void {
		$this->assertTrue( $this->locale->is_locale_rtl( 'ar' ) );
		$this->assertTrue( $this->locale->is_locale_rtl( 'ur_PK' ) );
		$this->assertTrue( $this->locale->is_locale_rtl( 'he_IL' ) );
		$this->assertTrue( $this->locale->is_locale_rtl( 'fa_IR' ) );

		$this->assertFalse( $this->locale->is_locale_rtl( 'en_US' ) );
		$this->assertFalse( $this->locale->is_locale_rtl( 'fr_FR' ) );
		$this->assertFalse( $this->locale->is_locale_rtl( 'es_ES' ) );
		$this->assertFalse( $this->locale->is_locale_rtl( 'de_DE' ) );
		$this->assertFalse( $this->locale->is_locale_rtl( '' ) );
	}

	public function test_get_text_direction_returns_direction_string(): void {
		$this->assertSame( 'rtl', $this->locale->get_text_direction( 'ar' ) );
		$this->assertSame( 'rtl', $this->locale->get_text_direction( 'ur_PK' ) );
		$this->assertSame( 'ltr', $this->locale->get_text_direction( 'en_US' ) );
		$this->assertSame( 'ltr', $this->locale->get_text_direction( 'fr_FR' ) );
		$this->assertSame( 'ltr', $this->locale->get_text_direction( '' ) );
	}
}
