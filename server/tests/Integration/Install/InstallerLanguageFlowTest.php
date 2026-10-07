<?php
/**
 * Integration tests for installer language selection and persistence.
 *
 * @package PeakURL\Tests\Integration\Install
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Install;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\I18n;
use PeakURL\Services\Install\Initializer;
use PeakURL\Services\Install\Screen as InstallScreen;
use PDO;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class InstallerLanguageFlowTest extends TestCase {

	private PDO $pdo;
	private array $base_config;
	private string $isolated_prefix;

	protected function setUp(): void {
		parent::setUp();

		$this->base_config     = Configuration::get_current();
		$this->isolated_prefix = 'i18nfl_' . bin2hex( random_bytes( 4 ) ) . '_';
		$connection            = Connection::get_instance( $this->base_config );
		$this->pdo             = $connection->get_connection();

		$this->drop_isolated_tables();
	}

	protected function tearDown(): void {
		$this->drop_isolated_tables();
		parent::tearDown();
	}

	private function get_isolated_config( string $site_language = 'es_ES' ): array {
		return array_merge(
			$this->base_config,
			array(
				Constants::DB_PREFIX        => $this->isolated_prefix,
				Constants::SITE_LANGUAGE    => $site_language,
				Constants::OWNER_USERNAME   => 'i18nadmin',
				Constants::OWNER_EMAIL      => 'i18nadmin@example.com',
				Constants::OWNER_PASSWORD   => 'I18nPassword123!',
				Constants::OWNER_FIRST_NAME => 'Locale',
				Constants::OWNER_LAST_NAME  => 'Admin',
				Constants::WORKSPACE_NAME   => 'I18n Site',
				Constants::WORKSPACE_SLUG   => 'i18n-site',
			)
		);
	}

	private function drop_isolated_tables(): void {
		$stmt   = $this->pdo->query( "SHOW TABLES LIKE '{$this->isolated_prefix}%'" );
		$tables = $stmt->fetchAll( PDO::FETCH_COLUMN );

		if ( ! empty( $tables ) ) {
			$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
			foreach ( $tables as $table ) {
				$this->pdo->exec( "DROP TABLE IF EXISTS `{$table}`" );
			}
			$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 1' );
		}
	}

	public function test_install_persists_site_language_to_database_settings(): void {
		$config       = $this->get_isolated_config( 'es_ES' );
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );

		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		$db           = new PeakURL_DB( $connection, $this->isolated_prefix );
		$settings_api = new SettingsApi( $db );

		$persisted_locale = $settings_api->get_option( 'site_language' );
		$this->assertSame( 'es_ES', $persisted_locale );

		$i18n = new I18n( $config, $settings_api );
		$this->assertSame( 'es_ES', $i18n->get_site_locale() );
		$this->assertSame( 'es-ES', $i18n->get_html_lang() );
		$this->assertSame( 'ltr', $i18n->get_text_direction() );
		$this->assertFalse( $i18n->is_locale_rtl() );

		// Verify PHP gettext and dashboard JSON catalogs match the persisted locale.
		$loaded_locale = $i18n->load_locale();
		$this->assertSame( 'es_ES', $loaded_locale );

		$dashboard_catalog = $i18n->get_dashboard_catalog();
		$this->assertIsArray( $dashboard_catalog );
		$this->assertArrayHasKey( 'locale_data', $dashboard_catalog );
	}

	public function test_install_persists_rtl_language_to_database_settings(): void {
		$config       = $this->get_isolated_config( 'ar' );
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );

		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		$db           = new PeakURL_DB( $connection, $this->isolated_prefix );
		$settings_api = new SettingsApi( $db );

		$persisted_locale = $settings_api->get_option( 'site_language' );
		$this->assertSame( 'ar', $persisted_locale );

		$i18n = new I18n( $config, $settings_api );
		$this->assertSame( 'ar', $i18n->get_site_locale() );
		$this->assertSame( 'ar', $i18n->get_html_lang() );
		$this->assertSame( 'rtl', $i18n->get_text_direction() );
		$this->assertTrue( $i18n->is_locale_rtl() );
	}

	public function test_dashboard_runtime_uses_persisted_locale_not_browser_header(): void {
		$config       = $this->get_isolated_config( 'de_DE' );
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );

		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		$db           = new PeakURL_DB( $connection, $this->isolated_prefix );
		$settings_api = new SettingsApi( $db );

		// Simulate subsequent dashboard request with a conflicting Accept-Language header.
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,fr;q=0.9,en;q=0.8';

		$i18n = new I18n( $config, $settings_api );
		$this->assertSame( 'de_DE', $i18n->get_site_locale() );
		$this->assertSame( 'de-DE', $i18n->get_html_lang() );
	}

	public function test_installer_urls_preserve_site_language_across_steps(): void {
		$step_1_url = InstallScreen::format_url(
			'',
			'/setup-config.php',
			array(
				'step'          => 1,
				'site_language' => 'fr_FR',
			),
		);
		$this->assertSame( '/setup-config.php?step=1&site_language=fr_FR', $step_1_url );

		$install_url = InstallScreen::format_url(
			'',
			'/install.php',
			array( 'site_language' => 'fr_FR' ),
		);
		$this->assertSame( '/install.php?site_language=fr_FR', $install_url );
	}
}
