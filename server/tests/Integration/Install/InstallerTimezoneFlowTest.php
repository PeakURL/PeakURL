<?php
/**
 * Integration tests for installer timezone detection, precedence, and persistence.
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
use PeakURL\Features\Settings\Validator as SettingsValidator;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Install\Initializer;
use PeakURL\Services\Install\Manager as InstallManager;
use PeakURL\Services\Install\Screen as InstallScreen;
use PDO;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class InstallerTimezoneFlowTest extends TestCase {

	private PDO $pdo;
	private array $base_config;
	private string $isolated_prefix;

	protected function setUp(): void {
		parent::setUp();

		$this->base_config     = Configuration::get_current();
		$this->isolated_prefix = 'tzfl_' . bin2hex( random_bytes( 4 ) ) . '_';
		$connection            = Connection::get_instance( $this->base_config );
		$this->pdo             = $connection->get_connection();

		$this->drop_isolated_tables();
	}

	protected function tearDown(): void {
		$this->drop_isolated_tables();
		parent::tearDown();
	}

	private function get_isolated_config( array $overrides = array() ): array {
		return array_merge(
			$this->base_config,
			array(
				Constants::DB_PREFIX        => $this->isolated_prefix,
				Constants::SITE_LANGUAGE    => 'en_US',
				Constants::SITE_TIMEZONE    => Constants::DEFAULT_TIMEZONE,
				Constants::OWNER_USERNAME   => 'tzadmin',
				Constants::OWNER_EMAIL      => 'tzadmin@example.com',
				Constants::OWNER_PASSWORD   => 'TzPassword123!',
				Constants::OWNER_FIRST_NAME => 'Timezone',
				Constants::OWNER_LAST_NAME  => 'Admin',
				Constants::WORKSPACE_NAME   => 'Timezone Site',
				Constants::WORKSPACE_SLUG   => 'timezone-site',
			),
			$overrides
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

	private function run_installer_flow( array $input ): array {
		$base_config  = $this->get_isolated_config();
		$method       = new \ReflectionMethod( InstallManager::class, 'normalize_input' );
		$values       = $method->invoke( null, $input, $base_config );
		$app_config   = Initializer::prepare_config( $values );
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $app_config, $runtime_root );

		$connection = new Connection( $app_config );
		Initializer::bootstrap_site( $connection, $app_config );

		$db           = new PeakURL_DB( $connection, $this->isolated_prefix );
		$settings_api = new SettingsApi( $db );

		return array(
			'config'       => $app_config,
			'connection'   => $connection,
			'settings_api' => $settings_api,
			'values'       => $values,
		);
	}

	public function test_install_persists_browser_timezone_when_no_explicit_timezone(): void {
		$input  = array(
			'workspace_name'   => 'Timezone Site',
			'owner_username'   => 'tzadmin',
			'owner_email'      => 'tzadmin@example.com',
			'owner_password'   => 'TzPassword123!',
			'browser_timezone' => 'Europe/London',
		);
		$result = $this->run_installer_flow( $input );

		$this->assertSame( 'Europe/London', $result['settings_api']->get_option( 'site_timezone' ) );
	}

	public function test_install_persists_explicit_timezone_over_browser(): void {
		$input  = array(
			'workspace_name'   => 'Timezone Site',
			'owner_username'   => 'tzadmin',
			'owner_email'      => 'tzadmin@example.com',
			'owner_password'   => 'TzPassword123!',
			'site_timezone'    => 'America/New_York',
			'browser_timezone' => 'Europe/London',
		);
		$result = $this->run_installer_flow( $input );

		$this->assertSame( 'America/New_York', $result['settings_api']->get_option( 'site_timezone' ) );
	}

	public function test_install_rejects_invalid_explicit_timezone(): void {
		$input = array(
			'workspace_name'   => 'Timezone Site',
			'owner_username'   => 'tzadmin',
			'owner_email'      => 'tzadmin@example.com',
			'owner_password'   => 'TzPassword123!',
			'site_timezone'    => 'Invalid/Timezone',
			'browser_timezone' => 'Europe/Berlin',
		);

		$this->expectException( \RuntimeException::class );
		$this->run_installer_flow( $input );
	}

	public function test_install_falls_back_to_utc_when_browser_timezone_is_unavailable(): void {
		$input  = array(
			'workspace_name' => 'Timezone Site',
			'owner_username' => 'tzadmin',
			'owner_email'    => 'tzadmin@example.com',
			'owner_password' => 'TzPassword123!',
		);
		$result = $this->run_installer_flow( $input );

		$this->assertSame( 'UTC', $result['settings_api']->get_option( 'site_timezone' ) );
	}

	public function test_install_falls_back_to_utc_when_browser_timezone_is_invalid(): void {
		$input  = array(
			'workspace_name'   => 'Timezone Site',
			'owner_username'   => 'tzadmin',
			'owner_email'      => 'tzadmin@example.com',
			'owner_password'   => 'TzPassword123!',
			'browser_timezone' => 'Malformed/Zone<script>',
		);
		$result = $this->run_installer_flow( $input );

		$this->assertSame( 'UTC', $result['settings_api']->get_option( 'site_timezone' ) );
	}

	public function test_runtime_dashboard_uses_persisted_site_timezone(): void {
		$input  = array(
			'workspace_name' => 'Timezone Site',
			'owner_username' => 'tzadmin',
			'owner_email'    => 'tzadmin@example.com',
			'owner_password' => 'TzPassword123!',
			'site_timezone'  => 'Asia/Tokyo',
		);
		$result = $this->run_installer_flow( $input );

		$peakurl_data = \get_peakurl_data(
			array(
				'settings_api' => $result['settings_api'],
				'config'       => $result['config'],
				'connection'   => $result['connection'],
			)
		);
		$this->assertSame( 'Asia/Tokyo', $peakurl_data['timezone'] );
	}

	public function test_installer_urls_preserve_timezone_across_steps(): void {
		$step_1_url = InstallScreen::format_url(
			'',
			'/setup-config.php',
			array(
				'step'             => 1,
				'site_language'    => 'en_US',
				'browser_timezone' => 'Europe/Berlin',
			)
		);
		$this->assertSame( '/setup-config.php?step=1&site_language=en_US&browser_timezone=Europe%2FBerlin', $step_1_url );

		$install_url = InstallScreen::format_url(
			'',
			'/install.php',
			array(
				'site_language'    => 'en_US',
				'site_timezone'    => 'America/Chicago',
				'browser_timezone' => 'Europe/Berlin',
			)
		);
		$this->assertSame( '/install.php?site_language=en_US&site_timezone=America%2FChicago&browser_timezone=Europe%2FBerlin', $install_url );
	}
}
