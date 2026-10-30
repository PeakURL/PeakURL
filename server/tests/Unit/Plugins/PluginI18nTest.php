<?php
/**
 * Unit tests for plugin internationalization (i18n) runtime integration.
 *
 * @package PeakURL\Tests\Unit\Plugins
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Plugins;

use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Translation;
use Gettext\Translations;
use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Plugins\Loader;
use PeakURL\Core\Plugins\Metadata;
use PeakURL\Core\Plugins\Registry;
use PeakURL\Core\Plugins\Type;
use PeakURL\Services\I18n;

/**
 * Tests independent text domains, catalog loading, fallback behavior, and path safety.
 */
class PluginI18nTest extends TestCase {

	private string $temp_dir;
	private I18n $i18n;

	protected function setUp(): void {
		parent::setUp();

		$this->temp_dir = sys_get_temp_dir() . '/peakurl_i18n_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir . '/languages', 0777, true );
		touch( $this->temp_dir . '/languages/peakurl-es_ES.po' );
		touch( $this->temp_dir . '/languages/peakurl-fr_FR.po' );

		$config = array(
			Constants::CONTENT_DIR   => $this->temp_dir,
			Constants::SITE_LANGUAGE => 'es_ES',
		);

		$this->i18n = new I18n( $config );
		set_i18n_service( $this->i18n );
		$this->i18n->load_locale( 'es_ES' );
	}

	protected function tearDown(): void {
		set_i18n_service( null );
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function recursive_rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}

	private function create_plugin_catalog(
		string $domain,
		string $locale,
		array $translations,
		string $dir
	): void {
		$catalog = Translations::create( $domain );

		foreach ( $translations as $original => $translated ) {
			if ( is_array( $translated ) ) {
				$single = $original;
				$plural = $translated['plural'] ?? $original;
				$trans  = Translation::create( null, $single );
				if ( '' !== $plural ) {
					$trans->setPlural( $plural );
				}
				$trans->translate( $translated[0] ?? $single );
				if ( isset( $translated[1] ) ) {
					$trans->translatePlural( $translated[1] );
				}
				$catalog->add( $trans );
			} else {
				$catalog->add( Translation::create( null, $original )->translate( (string) $translated ) );
			}
		}

		$mo_generator = new MoGenerator();
		$mo_content   = $mo_generator->generateString( $catalog );
		file_put_contents( $dir . "/{$domain}-{$locale}.mo", $mo_content );

		$po_generator = new PoGenerator();
		$po_content   = $po_generator->generateString( $catalog );
		file_put_contents( $dir . "/{$domain}-{$locale}.po", $po_content );
	}

	public function test_load_plugin_textdomain_translates_singular_strings(): void {
		$plugin_dir = $this->temp_dir . '/plugins/my-plugin';
		$lang_dir   = $plugin_dir . '/languages';
		mkdir( $lang_dir, 0777, true );

		$this->create_plugin_catalog(
			'my-plugin',
			'es_ES',
			array(
				'Clear click history' => 'Borrar historial de clics',
				'Settings'            => 'Configuración',
			),
			$lang_dir
		);

		$loaded = $this->i18n->load_plugin_textdomain( 'my-plugin', $lang_dir, $plugin_dir );
		$this->assertTrue( $loaded, 'Plugin textdomain must load successfully' );

		// 1. Translated string in plugin domain
		$translated = __( 'Clear click history', 'my-plugin' );
		$this->assertSame( 'Borrar historial de clics', $translated );

		// 2. Untranslated string falls back to original English
		$untranslated = __( 'Missing String', 'my-plugin' );
		$this->assertSame( 'Missing String', $untranslated );

		// 3. PeakURL core domain is preserved and isolated
		$core_text = __( 'Clear click history', 'peakurl' );
		$this->assertSame( 'Clear click history', $core_text, 'Core domain must not be polluted by plugin catalog' );
	}

	public function test_load_plugin_textdomain_plural_translations(): void {
		$plugin_dir = $this->temp_dir . '/plugins/counter-plugin';
		$lang_dir   = $plugin_dir . '/languages';
		mkdir( $lang_dir, 0777, true );

		$this->create_plugin_catalog(
			'counter-plugin',
			'es_ES',
			array(
				'%d click' => array(
					'%d clic',
					'%d clics',
					'plural' => '%d clicks',
				),
			),
			$lang_dir
		);

		$this->i18n->load_plugin_textdomain( 'counter-plugin', $lang_dir, $plugin_dir );

		// translators: %d: Number of clicks.
		$single = _n( '%d click', '%d clicks', 1, 'counter-plugin' );
		$this->assertSame( '%d clic', $single );

		// translators: %d: Number of clicks.
		$plural = _n( '%d click', '%d clicks', 5, 'counter-plugin' );
		$this->assertSame( '%d clics', $plural );
	}

	public function test_path_traversal_outside_plugin_boundary_rejected(): void {
		$plugin_dir  = $this->temp_dir . '/plugins/evil-plugin';
		$outside_dir = sys_get_temp_dir();
		mkdir( $plugin_dir, 0777, true );

		// Attempting to point languages directory outside the plugin boundary
		$loaded = $this->i18n->load_plugin_textdomain( 'evil-plugin', $outside_dir, $plugin_dir );
		$this->assertFalse( $loaded, 'Path outside plugin directory boundary must be rejected' );

		// Non-existent directory returns false
		$bad_dir = $plugin_dir . '/non-existent-dir';
		$loaded2 = $this->i18n->load_plugin_textdomain( 'evil-plugin', $bad_dir, $plugin_dir );
		$this->assertFalse( $loaded2, 'Non-existent directory must return false' );

		// Sibling directory with matching prefix must be rejected
		$sibling_plugin_dir = $this->temp_dir . '/plugins/evil-plugin-sibling/languages';
		mkdir( $sibling_plugin_dir, 0777, true );
		$loaded3 = $this->i18n->load_plugin_textdomain( 'evil-plugin', $sibling_plugin_dir, $plugin_dir );
		$this->assertFalse( $loaded3, 'Sibling directory matching prefix must be rejected by directory boundary check' );

		// Symlink pointing inside plugin boundary must be rejected
		$target_dir = $plugin_dir . '/languages';
		mkdir( $target_dir, 0777, true );
		$symlink_dir = $plugin_dir . '/symlink_lang';
		if ( function_exists( 'symlink' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort symlink creation in test environment.
			@symlink( $target_dir, $symlink_dir );
			if ( is_link( $symlink_dir ) ) {
				$loaded4 = $this->i18n->load_plugin_textdomain( 'evil-plugin', $symlink_dir, $plugin_dir );
				$this->assertFalse( $loaded4, 'Symlinked languages directory must be rejected' );
			}
		}
	}

	public function test_reserved_text_domains_rejected(): void {
		$plugin_dir = $this->temp_dir . '/plugins/reserved-test';
		$lang_dir   = $plugin_dir . '/languages';
		mkdir( $lang_dir, 0777, true );

		$reserved = array( 'peakurl', 'default', 'core', 'system', 'messages', 'admin', 'api' );
		foreach ( $reserved as $domain ) {
			$loaded = $this->i18n->load_plugin_textdomain( $domain, $lang_dir, $plugin_dir );
			$this->assertFalse( $loaded, "Reserved domain {$domain} must be rejected" );
		}
	}

	public function test_domain_collision_with_different_directory_rejected(): void {
		$plugin1_dir = $this->temp_dir . '/plugins/first-plugin';
		$lang1_dir   = $plugin1_dir . '/languages';
		mkdir( $lang1_dir, 0777, true );

		$plugin2_dir = $this->temp_dir . '/plugins/second-plugin';
		$lang2_dir   = $plugin2_dir . '/languages';
		mkdir( $lang2_dir, 0777, true );

		$this->assertTrue( $this->i18n->load_plugin_textdomain( 'shared-domain', $lang1_dir, $plugin1_dir ) );
		// Re-registering with different directory must be rejected
		$this->assertFalse( $this->i18n->load_plugin_textdomain( 'shared-domain', $lang2_dir, $plugin2_dir ) );
	}

	public function test_dashboard_catalog_domain_isolation(): void {
		$plugin_dir = $this->temp_dir . '/plugins/isolated-plugin';
		$lang_dir   = $plugin_dir . '/languages';
		mkdir( $lang_dir, 0777, true );

		// Create a malicious JSON catalog attempting to inject into 'peakurl' and 'other-domain'
		$malicious_json = array(
			'locale_data' => array(
				'peakurl'      => array(
					''            => array(
						'domain' => 'peakurl',
					),
					'Core String' => array( 'Malicious Override' ),
				),
				'other-domain' => array(
					'Other String' => array( 'Other Override' ),
				),
				'messages'     => array(
					'Legit String' => array( 'Legit Translation' ),
				),
			),
		);
		file_put_contents( $lang_dir . '/isolated-plugin-es_ES.json', json_encode( $malicious_json ) );

		$this->assertTrue( $this->i18n->load_plugin_textdomain( 'isolated-plugin', $lang_dir, $plugin_dir ) );

		$catalog = $this->i18n->get_dashboard_catalog( 'es_ES' );
		$this->assertArrayHasKey( 'locale_data', $catalog );

		// 1. PeakURL core catalog must NOT contain the malicious override
		if ( isset( $catalog['locale_data']['peakurl'] ) ) {
			$this->assertArrayNotHasKey( 'Core String', $catalog['locale_data']['peakurl'] );
		}

		// 2. 'other-domain' must NOT be registered
		$this->assertArrayNotHasKey( 'other-domain', $catalog['locale_data'] );

		// 3. 'isolated-plugin' receives its messages strictly under its own domain
		$this->assertArrayHasKey( 'isolated-plugin', $catalog['locale_data'] );
		$this->assertSame(
			array( 'Legit Translation' ),
			$catalog['locale_data']['isolated-plugin']['Legit String']
		);

		// 4. Header '' is sanitized
		$this->assertSame( 'isolated-plugin', $catalog['locale_data']['isolated-plugin']['']['domain'] );
		$this->assertSame( 'es_ES', $catalog['locale_data']['isolated-plugin']['']['lang'] );
	}

	public function test_locale_switch_reloads_plugin_catalogs(): void {
		$plugin_dir = $this->temp_dir . '/plugins/multi-lang';
		$lang_dir   = $plugin_dir . '/languages';
		mkdir( $lang_dir, 0777, true );

		$this->create_plugin_catalog(
			'multi-lang',
			'es_ES',
			array( 'Hello' => 'Hola' ),
			$lang_dir
		);

		$this->create_plugin_catalog(
			'multi-lang',
			'fr_FR',
			array( 'Hello' => 'Bonjour' ),
			$lang_dir
		);

		$this->i18n->load_plugin_textdomain( 'multi-lang', $lang_dir, $plugin_dir );
		$this->assertSame( 'Hola', __( 'Hello', 'multi-lang' ) );

		// Switch locale to fr_FR
		$this->i18n->load_locale( 'fr_FR' );
		$this->assertSame( 'Bonjour', __( 'Hello', 'multi-lang' ) );

		// Switch locale back to en_US (English default)
		$this->i18n->load_locale( 'en_US' );
		$this->assertSame( 'Hello', __( 'Hello', 'multi-lang' ) );
	}

	public function test_dashboard_catalog_merges_plugin_translations(): void {
		$plugin_dir = $this->temp_dir . '/plugins/dashboard-plugin';
		$lang_dir   = $plugin_dir . '/languages';
		mkdir( $lang_dir, 0777, true );

		$this->create_plugin_catalog(
			'dashboard-plugin',
			'es_ES',
			array( 'Export Records' => 'Exportar Registros' ),
			$lang_dir
		);

		$this->i18n->load_plugin_textdomain( 'dashboard-plugin', $lang_dir, $plugin_dir );

		$catalog = $this->i18n->get_dashboard_catalog( 'es_ES' );
		$this->assertArrayHasKey( 'locale_data', $catalog );
		$this->assertArrayHasKey( 'dashboard-plugin', $catalog['locale_data'] );

		$plugin_data = $catalog['locale_data']['dashboard-plugin'];
		$this->assertArrayHasKey( 'Export Records', $plugin_data );
		$this->assertSame( array( 'Exportar Registros' ), $plugin_data['Export Records'] );
	}

	public function test_loader_auto_registers_plugin_i18n(): void {
		$plugin_dir = $this->temp_dir . '/plugins/auto-i18n';
		$lang_dir   = $plugin_dir . '/languages';
		mkdir( $lang_dir, 0777, true );

		$this->create_plugin_catalog(
			'auto-i18n',
			'es_ES',
			array( 'Quick Action' => 'Acción Rápida' ),
			$lang_dir
		);

		$metadata = new Metadata(
			array(
				'id'          => 'auto-i18n',
				'type'        => Type::REGULAR,
				'name'        => 'Auto I18n',
				'version'     => '1.0.0',
				'requires'    => '1.8.0',
				'testedUpTo'  => '1.8.0',
				'php'         => '8.3',
				'license'     => 'MIT',
				'textDomain'  => 'auto-i18n',
				'domainPath'  => '/languages',
				'valid'       => true,
				'compatible'  => true,
				'plugin_path' => $plugin_dir,
			)
		);

		$registry = $this->createMock( Registry::class );
		$registry->method( 'must_use' )->willReturn( array() );
		$registry->method( 'regular' )->willReturn( array( 'auto-i18n' => $metadata ) );

		file_put_contents( $plugin_dir . '/plugin.php', "<?php // auto-i18n entry\n" );

		$settings = $this->createMock( \PeakURL\Api\SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'auto-i18n' ) ) );

		$loader = new Loader( $registry, $settings, false, false );
		$loader->load_plugins();

		// Loader must have automatically loaded the plugin textdomain!
		$this->assertSame( 'Acción Rápida', __( 'Quick Action', 'auto-i18n' ) );
	}
}
