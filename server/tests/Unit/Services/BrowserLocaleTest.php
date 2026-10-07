<?php
/**
 * Unit tests for BrowserLocale matching helper.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Services\I18n\BrowserLocale;
use PeakURL\Services\I18n\Locale;

class BrowserLocaleTest extends TestCase {

	private BrowserLocale $browser_locale;

	/**
	 * Installed test locales simulating the bundled language packs.
	 *
	 * @var array<int, string>
	 */
	private array $installed_locales = array(
		'ar',
		'cs_CZ',
		'da_DK',
		'de_DE',
		'el_GR',
		'en_AU',
		'en_GB',
		'en_US',
		'es_ES',
		'fi',
		'fr_FR',
		'hi_IN',
		'id_ID',
		'it_IT',
		'ja',
		'nb_NO',
		'nl_NL',
		'pl_PL',
		'pt_PT',
		'ro_RO',
		'ru_RU',
		'sv_SE',
		'tr_TR',
		'uk',
		'ur_PK',
		'vi_VN',
		'zh_CN',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->browser_locale = new BrowserLocale( new Locale() );
	}

	public function test_exact_supported_locale(): void {
		$result = $this->browser_locale->match_browser_locale(
			'fr-FR,fr;q=0.9,en;q=0.8',
			$this->installed_locales,
		);

		$this->assertSame( 'fr_FR', $result );
	}

	public function test_regional_supported_locale(): void {
		// es_AR is not installed, but es_ES is installed.
		$result = $this->browser_locale->match_browser_locale(
			'es-AR,es;q=0.9,en;q=0.8',
			$this->installed_locales,
		);

		$this->assertSame( 'es_ES', $result );
	}

	public function test_regional_fallback_examples(): void {
		$this->assertSame(
			'es_ES',
			$this->browser_locale->match_browser_locale( 'es-CO', $this->installed_locales )
		);
		$this->assertSame(
			'fr_FR',
			$this->browser_locale->match_browser_locale( 'fr-CA', $this->installed_locales )
		);
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale( 'de-AT', $this->installed_locales )
		);
		$this->assertSame(
			'pt_PT',
			$this->browser_locale->match_browser_locale( 'pt-BR', $this->installed_locales )
		);
		$this->assertSame(
			'ar',
			$this->browser_locale->match_browser_locale( 'ar-SA', $this->installed_locales )
		);
	}

	public function test_base_language_match(): void {
		// de matches installed de_DE.
		$result = $this->browser_locale->match_browser_locale(
			'de,en-US;q=0.8',
			$this->installed_locales,
		);

		$this->assertSame( 'de_DE', $result );
	}

	public function test_quality_ordering(): void {
		// Higher quality preference wins regardless of header order.
		$result = $this->browser_locale->match_browser_locale(
			'fr-FR;q=0.5, de-DE;q=0.9, es-ES;q=0.7',
			$this->installed_locales,
		);

		$this->assertSame( 'de_DE', $result );
	}

	public function test_equal_quality_preserves_header_order(): void {
		$result = $this->browser_locale->match_browser_locale(
			'it-IT;q=0.8, fr-FR;q=0.8',
			$this->installed_locales,
		);

		$this->assertSame( 'it_IT', $result );
	}

	public function test_unsupported_preferred_languages_returns_empty_string(): void {
		$result = $this->browser_locale->match_browser_locale(
			'xx-YY,zz-ZZ;q=0.8',
			$this->installed_locales,
		);

		$this->assertSame( '', $result );
	}

	public function test_empty_accept_language_returns_empty_string(): void {
		$this->assertSame( '', $this->browser_locale->match_browser_locale( '', $this->installed_locales ) );
		$this->assertSame( '', $this->browser_locale->match_browser_locale( '   ', $this->installed_locales ) );
	}

	public function test_multiple_browser_preferences_skips_unsupported_to_first_supported(): void {
		$result = $this->browser_locale->match_browser_locale(
			'xx-YY;q=1.0, nl-NL;q=0.8, en-US;q=0.5',
			$this->installed_locales,
		);

		$this->assertSame( 'nl_NL', $result );
	}

	public function test_ignores_zero_or_negative_quality(): void {
		// q=0 means unacceptable.
		$result = $this->browser_locale->match_browser_locale(
			'fr-FR;q=0, de-DE;q=0.8',
			$this->installed_locales,
		);

		$this->assertSame( 'de_DE', $result );
	}

	public function test_ignores_invalid_quality_values(): void {
		// Quality above 1.0, negative, or non-numeric must not become high-priority preferences.
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'fr-FR;q=2.5, de-DE;q=0.8',
				$this->installed_locales,
			)
		);
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'fr-FR;q=-0.5, de-DE;q=0.8',
				$this->installed_locales,
			)
		);
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'fr-FR;q=invalid, de-DE;q=0.8',
				$this->installed_locales,
			)
		);
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'fr-FR;q=, de-DE;q=0.8',
				$this->installed_locales,
			)
		);
	}

	public function test_default_locale_tie_breaker_for_multiple_regional_variants(): void {
		// Generic 'en' should deterministically choose the application default en_US among en variants.
		$this->assertSame(
			'en_US',
			$this->browser_locale->match_browser_locale( 'en', $this->installed_locales )
		);
		$this->assertSame(
			'en_GB',
			$this->browser_locale->match_browser_locale( 'en-GB', $this->installed_locales )
		);
		$this->assertSame(
			'en_AU',
			$this->browser_locale->match_browser_locale( 'en-AU', $this->installed_locales )
		);
	}

	public function test_accepts_valid_q_value_shapes_up_to_three_decimals(): void {
		$this->assertSame(
			'fr_FR',
			$this->browser_locale->match_browser_locale(
				'de-DE;q=0.5, fr-FR;q=0.501',
				$this->installed_locales,
			)
		);
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'de-DE;q=1.000, fr-FR;q=0.9',
				$this->installed_locales,
			)
		);
		$this->assertSame(
			'fr_FR',
			$this->browser_locale->match_browser_locale(
				'de-DE;q=0.50, fr-FR;q=1.0',
				$this->installed_locales,
			)
		);
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'de-DE;q=1, fr-FR;q=0.999',
				$this->installed_locales,
			)
		);
	}

	public function test_omitted_q_value_defaults_to_one(): void {
		$result = $this->browser_locale->match_browser_locale(
			'de-DE;q=0.9, fr-FR',
			$this->installed_locales,
		);

		$this->assertSame( 'fr_FR', $result );
	}

	public function test_excessive_decimal_precision_or_scientific_notation_ignored(): void {
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'fr-FR;q=1.0000, de-DE;q=0.8',
				$this->installed_locales,
			)
		);
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'fr-FR;q=0.1234, de-DE;q=0.8',
				$this->installed_locales,
			)
		);
		$this->assertSame(
			'de_DE',
			$this->browser_locale->match_browser_locale(
				'fr-FR;q=1e-1, de-DE;q=0.8',
				$this->installed_locales,
			)
		);
	}

	public function test_ignores_wildcards_and_invalid_language_tags(): void {
		$result = $this->browser_locale->match_browser_locale(
			'*;q=1.0, invalid!tag, sv-SE;q=0.7',
			$this->installed_locales,
		);

		$this->assertSame( 'sv_SE', $result );
	}
}
