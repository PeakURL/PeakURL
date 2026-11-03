<?php
/**
 * Bootstrap and lifecycle test suite for plugin loading and recovery across entrypoints.
 *
 * Verifies must-use priority, single execution of regular plugins, hook registration
 * prior to lifecycle actions (init, rest_api_init, register_jobs), recovery flags,
 * distinction between active and loaded state, and explicit handling of unavailable settings.
 *
 * @package PeakURL\Tests\Unit\Plugins
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Plugins;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Core\Plugins\Loader;
use PeakURL\Core\Plugins\Registry;
use PeakURL\Core\Scheduler\JobDefinition;
use PeakURL\Core\Scheduler\JobRegistry;
use PeakURL\Http\Request;
use PeakURL\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Tests entrypoint bootstrapping, hook ordering, recovery modes, and diagnostics.
 */
class PluginBootstrapLifecycleTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		Loader::reset_instance();
		Hooks::remove_all();
		Hooks::reset_actions();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_boot_test_' . uniqid();
		mkdir( $this->temp_dir . '/plugins', 0777, true );
		mkdir( $this->temp_dir . '/mu-plugins', 0777, true );
	}

	protected function tearDown(): void {
		Loader::reset_instance();
		Hooks::remove_all();
		Hooks::reset_actions();
		putenv( 'PEAKURL_SKIP_PLUGINS' );
		putenv( 'PEAKURL_SAFE_MODE' );
		putenv( 'PEAKURL_SKIP_MU_PLUGINS' );
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function create_valid_manifest( array $overrides = array() ): array {
		$name = (string) ( $overrides['name'] ?? 'Lifecycle Plugin' );
		return array_merge(
			array(
				'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
				'name'       => $name,
				'version'    => '1.0.0',
				'requires'   => '1.0.0',
				'testedUpTo' => '1.8.0',
				'php'        => '8.3',
				'license'    => 'MIT',
				'textDomain' => \PeakURL\Core\Plugins\Validator::slugify( $name ),
				'domainPath' => '/languages',
			),
			$overrides
		);
	}

	public function test_must_use_loads_first_and_actions_fire_in_strict_order(): void {
		$mu_dir = $this->temp_dir . '/mu-plugins/alpha-mu';
		mkdir( $mu_dir, 0777, true );
		file_put_contents( $mu_dir . '/plugin.json', json_encode( $this->create_valid_manifest( array( 'name' => 'Alpha MU' ) ) ) );
		file_put_contents(
			$mu_dir . '/plugin.php',
			"<?php\n\$GLOBALS['event_order'][] = 'mu_executed';\n"
		);

		$reg_dir = $this->temp_dir . '/plugins/beta-reg';
		mkdir( $reg_dir, 0777, true );
		file_put_contents(
			$reg_dir . '/plugin.json',
			json_encode(
				$this->create_valid_manifest(
					array(
						'name'       => 'Beta Regular',
						'textDomain' => 'beta-reg',
					)
				)
			)
		);
		file_put_contents(
			$reg_dir . '/plugin.php',
			"<?php\n\$GLOBALS['event_order'][] = 'regular_executed';\n"
		);

		$GLOBALS['event_order'] = array();

		Hooks::add(
			'muplugins_loaded',
			function (): void {
				$GLOBALS['event_order'][] = 'hook_muplugins_loaded';
			}
		);

		Hooks::add(
			'plugins_loaded',
			function (): void {
				$GLOBALS['event_order'][] = 'hook_plugins_loaded';
			}
		);

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'beta-reg' ) ) );

		$registry = new Registry( $this->temp_dir );
		$loader   = new Loader( $registry, $settings, false, false );
		$loader->load_plugins();

		// Simulate request-level init.
		$GLOBALS['event_order'][] = 'hook_init';
		\do_action( 'init' );

		$this->assertSame(
			array(
				'mu_executed',
				'hook_muplugins_loaded',
				'regular_executed',
				'hook_plugins_loaded',
				'hook_init',
			),
			$GLOBALS['event_order']
		);

		unset( $GLOBALS['event_order'] );
	}

	public function test_distinguishes_persisted_active_from_actually_loaded(): void {
		// Plugin 1: Valid and active -> active = true, loaded = true.
		$good_dir = $this->temp_dir . '/plugins/good-plugin';
		mkdir( $good_dir, 0777, true );
		file_put_contents( $good_dir . '/plugin.json', json_encode( $this->create_valid_manifest( array( 'name' => 'Good Plugin' ) ) ) );
		file_put_contents( $good_dir . '/plugin.php', "<?php // good\n" );

		// Plugin 2: Incompatible requirement but active in settings -> active = true, loaded = false.
		$bad_dir = $this->temp_dir . '/plugins/incompat-plugin';
		mkdir( $bad_dir, 0777, true );
		file_put_contents(
			$bad_dir . '/plugin.json',
			json_encode(
				$this->create_valid_manifest(
					array(
						'name'     => 'Incompatible Plugin',
						'requires' => '99.0.0',
					)
				)
			)
		);
		file_put_contents( $bad_dir . '/plugin.php', "<?php // incompat\n" );

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'good-plugin', 'incompat-plugin' ) ) );

		$registry = new Registry( $this->temp_dir );
		$loader   = new Loader( $registry, $settings, false, false );
		$loader->load_plugins();

		// Persisted active state in settings:
		$this->assertTrue( $loader->is_active( 'good-plugin' ) );
		$this->assertTrue( $loader->is_active( 'incompat-plugin' ) );

		// Actually executed runtime loaded state:
		$this->assertTrue( $loader->is_loaded( 'good-plugin' ) );
		$this->assertFalse( $loader->is_loaded( 'incompat-plugin' ) );

		$loaded_map = $loader->get_loaded();
		$this->assertArrayHasKey( 'good-plugin', $loaded_map );
		$this->assertArrayNotHasKey( 'incompat-plugin', $loaded_map );

		$good_meta = $loaded_map['good-plugin'];
		$this->assertTrue( $good_meta->is_active() );
		$this->assertTrue( $good_meta->is_loaded() );

		$good_arr = $good_meta->to_array();
		$this->assertTrue( $good_arr['active'] );
		$this->assertTrue( $good_arr['loaded'] );
	}

	public function test_handles_unavailable_activation_settings_explicitly(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willThrowException( new \RuntimeException( 'Settings storage table is not available.' ) );

		$registry = new Registry( $this->temp_dir );
		$loader   = new Loader( $registry, $settings, false, false );

		$active_ids = $loader->fetch_active_plugin_ids();
		$this->assertSame( array(), $active_ids );

		// Loader must explicitly record the failure rather than silently claiming success.
		$this->assertFalse( $loader->is_settings_available() );
		$this->assertSame( 'Settings storage table is not available.', $loader->get_settings_error() );

		$status = $loader->get_startup_status();
		$this->assertFalse( $status['settings_available'] );
		$this->assertSame( 'Settings storage table is not available.', $status['settings_error'] );
	}

	public function test_recovery_environment_flags(): void {
		$mu_dir = $this->temp_dir . '/mu-plugins/recovery-mu';
		mkdir( $mu_dir, 0777, true );
		file_put_contents( $mu_dir . '/plugin.json', json_encode( $this->create_valid_manifest( array( 'name' => 'Recovery MU' ) ) ) );
		file_put_contents( $mu_dir . '/plugin.php', "<?php \$GLOBALS['rec_mu_run'] = true;\n" );

		$reg_dir = $this->temp_dir . '/plugins/recovery-reg';
		mkdir( $reg_dir, 0777, true );
		file_put_contents(
			$reg_dir . '/plugin.json',
			json_encode(
				$this->create_valid_manifest(
					array(
						'name'       => 'Recovery Regular',
						'textDomain' => 'recovery-reg',
					)
				)
			)
		);
		file_put_contents( $reg_dir . '/plugin.php', "<?php \$GLOBALS['rec_reg_run'] = true;\n" );

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'recovery-reg' ) ) );

		$registry = new Registry( $this->temp_dir );

		// Case 1: PEAKURL_SKIP_PLUGINS=1 engages safe mode.
		putenv( 'PEAKURL_SKIP_PLUGINS=1' );
		Loader::reset_instance();
		$GLOBALS['rec_mu_run']  = false;
		$GLOBALS['rec_reg_run'] = false;

		$loader1 = new Loader( $registry, $settings );
		$this->assertTrue( $loader1->is_safe_mode() );
		$this->assertFalse( $loader1->is_skip_mu() );
		$loader1->load_plugins();

		$this->assertTrue( $GLOBALS['rec_mu_run'], 'Must-use plugins must still load in safe mode' );
		$this->assertFalse( $GLOBALS['rec_reg_run'], 'Regular plugins must be skipped in safe mode' );

		// Case 2: PEAKURL_SKIP_MU_PLUGINS=1 skips must-use plugins.
		putenv( 'PEAKURL_SKIP_PLUGINS' );
		putenv( 'PEAKURL_SKIP_MU_PLUGINS=1' );
		Loader::reset_instance();
		$GLOBALS['rec_mu_run']  = false;
		$GLOBALS['rec_reg_run'] = false;

		$loader2 = new Loader( $registry, $settings );
		$this->assertFalse( $loader2->is_safe_mode() );
		$this->assertTrue( $loader2->is_skip_mu() );
		$loader2->load_plugins();

		$this->assertFalse( $GLOBALS['rec_mu_run'], 'Must-use plugins must be skipped when flag set' );
		$this->assertTrue( $GLOBALS['rec_reg_run'], 'Regular plugins must load when not in safe mode' );

		unset( $GLOBALS['rec_mu_run'], $GLOBALS['rec_reg_run'] );
	}

	public function test_api_entrypoint_registers_routes_before_rest_api_init(): void {
		$reg_dir = $this->temp_dir . '/plugins/api-route-plugin';
		mkdir( $reg_dir, 0777, true );
		file_put_contents( $reg_dir . '/plugin.json', json_encode( $this->create_valid_manifest( array( 'name' => 'API Route Plugin' ) ) ) );
		file_put_contents(
			$reg_dir . '/plugin.php',
			"<?php\n" .
			"\\PeakURL\\Core\\Hooks\\Hooks::add('rest_api_init', function ( \$router ): void {\n" .
			"    \$router->get('/api/v1/custom-plugin-route', function (): array {\n" .
			"        return array('success' => true, 'plugin' => 'custom');\n" .
			"    });\n" .
			"});\n"
		);

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'api-route-plugin' ) ) );

		$registry = new Registry( $this->temp_dir );
		$loader   = new Loader( $registry, $settings, false, false );
		$loader->load_plugins();

		// Simulate API entrypoint rest_api_init action dispatch.
		$router = new Router();
		\do_action( 'rest_api_init', $router );

		$dispatch_res = $router->dispatch( new Request( 'GET', '/api/v1/custom-plugin-route', array(), array() ) );
		$this->assertSame(
			array(
				'success' => true,
				'plugin'  => 'custom',
			),
			$dispatch_res
		);
	}

	public function test_cron_entrypoint_registers_jobs_before_register_jobs_action(): void {
		$reg_dir = $this->temp_dir . '/plugins/cron-job-plugin';
		mkdir( $reg_dir, 0777, true );
		file_put_contents( $reg_dir . '/plugin.json', json_encode( $this->create_valid_manifest( array( 'name' => 'Cron Job Plugin' ) ) ) );
		file_put_contents(
			$reg_dir . '/plugin.php',
			"<?php\n" .
			"\\PeakURL\\Core\\Hooks\\Hooks::add('register_jobs', function ( \$registry ): void {\n" .
			"    \$registry->register( new \\PeakURL\\Core\\Scheduler\\JobDefinition( 'custom_plugin_cleanup', 'Custom Cleanup', 3600, function (): bool { return true; } ) );\n" .
			"});\n"
		);

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'cron-job-plugin' ) ) );

		$registry = new Registry( $this->temp_dir );
		$loader   = new Loader( $registry, $settings, false, false );
		$loader->load_plugins();

		// Simulate Cron entrypoint register_jobs action dispatch.
		$job_registry = new JobRegistry();
		\do_action( 'register_jobs', $job_registry );

		$this->assertTrue( $job_registry->has( 'custom_plugin_cleanup' ) );
	}

	public function test_dashboard_and_root_entrypoint_bootstrapping(): void {
		$reg_dir = $this->temp_dir . '/plugins/web-hook-plugin';
		mkdir( $reg_dir, 0777, true );
		file_put_contents( $reg_dir . '/plugin.json', json_encode( $this->create_valid_manifest( array( 'name' => 'Web Hook Plugin' ) ) ) );
		file_put_contents(
			$reg_dir . '/plugin.php',
			"<?php\n" .
			"\\PeakURL\\Core\\Hooks\\Hooks::add('init', function (): void {\n" .
			"    \$GLOBALS['web_init_fired'] = true;\n" .
			"});\n"
		);

		$GLOBALS['web_init_fired'] = false;

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'web-hook-plugin' ) ) );

		$registry = new Registry( $this->temp_dir );
		$loader   = Loader::bootstrap(
			array( Constants::CONTENT_DIR => $this->temp_dir ),
			null,
			$settings,
			$registry
		);

		$this->assertTrue( $loader->is_bootstrapped() );
		$this->assertTrue( $loader->is_loaded( 'web-hook-plugin' ) );

		// Simulate request init hook in index.php.
		\do_action( 'init' );

		$this->assertTrue( $GLOBALS['web_init_fired'], 'Plugin callback registered for init must execute' );

		unset( $GLOBALS['web_init_fired'] );
	}

	private function recursive_rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) ) {
				@unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}
}
