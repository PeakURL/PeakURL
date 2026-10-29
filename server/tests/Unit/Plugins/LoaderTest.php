<?php
/**
 * Unit tests for Loader.
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
use PeakURL\Services\Database\PeakURL_DB;
use PHPUnit\Framework\TestCase;

/**
 * Tests runtime plugin execution, bootstrap timing, recovery mode, and activation gates.
 */
class LoaderTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		Loader::reset_instance();
		Hooks::remove_all();
		Hooks::reset_actions();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_loader_test_' . uniqid();
		mkdir( $this->temp_dir, 0777, true );
	}

	protected function tearDown(): void {
		Loader::reset_instance();
		Hooks::remove_all();
		Hooks::reset_actions();
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	public function test_must_use_plugins_load_automatically_on_bootstrap(): void {
		$mu_dir = $this->temp_dir . '/mu-plugins/test-mu';
		mkdir( $mu_dir, 0777, true );

		file_put_contents(
			$mu_dir . '/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => 'Test MU',
					'version'    => '1.0.0',
					'requires'   => '1.0.0',
					'testedUpTo' => '1.8.0',
					'php'        => '8.0',
					'license'    => 'MIT',
				)
			)
		);

		$token = 'mu_loaded_' . uniqid();
		file_put_contents(
			$mu_dir . '/plugin.php',
			"<?php\n\$GLOBALS['{$token}'] = true;\n"
		);

		$muplugins_loaded_fired = false;
		Hooks::add(
			'muplugins_loaded',
			function () use ( &$muplugins_loaded_fired ): void {
				$muplugins_loaded_fired = true;
			}
		);

		$registry = new Registry( $this->temp_dir );
		$loader   = new Loader( $registry, null, false, false );
		$loader->load_plugins();

		$this->assertTrue( $muplugins_loaded_fired, 'muplugins_loaded action should fire' );
		$this->assertTrue( ! empty( $GLOBALS[ $token ] ), 'Must-use plugin code should have executed' );
		$this->assertTrue( $loader->is_loaded( 'test-mu' ) );

		unset( $GLOBALS[ $token ] );
	}

	public function test_regular_plugins_only_load_when_marked_active(): void {
		$reg_dir = $this->temp_dir . '/plugins/test-regular';
		mkdir( $reg_dir, 0777, true );

		file_put_contents(
			$reg_dir . '/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => 'Test Regular',
					'version'    => '1.0.0',
					'requires'   => '1.0.0',
					'testedUpTo' => '1.8.0',
					'php'        => '8.0',
					'license'    => 'MIT',
				)
			)
		);

		$token = 'reg_loaded_' . uniqid();
		file_put_contents(
			$reg_dir . '/plugin.php',
			"<?php\n\$GLOBALS['{$token}'] = true;\n"
		);

		$plugins_loaded_fired = false;
		Hooks::add(
			'plugins_loaded',
			function () use ( &$plugins_loaded_fired ): void {
				$plugins_loaded_fired = true;
			}
		);

		// Case 1: Inactive - should not load.
		$settings_mock = $this->createMock( SettingsApi::class );
		$settings_mock->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array() ) );

		$registry = new Registry( $this->temp_dir );
		$loader1  = new Loader( $registry, $settings_mock, false, false );
		$loader1->load_plugins();

		$this->assertFalse( isset( $GLOBALS[ $token ] ), 'Inactive plugin should not execute' );
		$this->assertFalse( $loader1->is_loaded( 'test-regular' ) );
		$this->assertFalse( $loader1->is_active( 'test-regular' ) );

		// Case 2: Active - should load.
		Loader::reset_instance();
		$settings_mock2 = $this->createMock( SettingsApi::class );
		$settings_mock2->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'test-regular' ) ) );

		$loader2 = new Loader( $registry, $settings_mock2, false, false );
		$loader2->load_plugins();

		$this->assertTrue( $plugins_loaded_fired, 'plugins_loaded action should fire' );
		$this->assertTrue( ! empty( $GLOBALS[ $token ] ), 'Active plugin should have executed' );
		$this->assertTrue( $loader2->is_loaded( 'test-regular' ) );
		$this->assertTrue( $loader2->is_active( 'test-regular' ) );

		unset( $GLOBALS[ $token ] );
	}

	public function test_invalid_or_incompatible_plugins_are_never_executed(): void {
		$reg_dir = $this->temp_dir . '/plugins/incompatible-plugin';
		mkdir( $reg_dir, 0777, true );

		file_put_contents(
			$reg_dir . '/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => 'Incompatible Plugin',
					'version'    => '1.0.0',
					'requires'   => '99.0.0', // Impossible requirement.
					'testedUpTo' => '99.0.0',
					'php'        => '99.0.0',
					'license'    => 'MIT',
				)
			)
		);

		$token = 'incompatible_' . uniqid();
		file_put_contents(
			$reg_dir . '/plugin.php',
			"<?php\n\$GLOBALS['{$token}'] = true;\n"
		);

		$settings_mock = $this->createMock( SettingsApi::class );
		$settings_mock->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'incompatible-plugin' ) ) );

		$registry = new Registry( $this->temp_dir );
		$loader   = new Loader( $registry, $settings_mock, false, false );
		$loader->load_plugins();

		$this->assertFalse( isset( $GLOBALS[ $token ] ), 'Incompatible plugin must never be executed' );
		$this->assertFalse( $loader->is_loaded( 'incompatible-plugin' ) );

		unset( $GLOBALS[ $token ] );
	}

	public function test_safe_mode_recovery_bypasses_regular_plugins(): void {
		$reg_dir = $this->temp_dir . '/plugins/fatal-plugin';
		mkdir( $reg_dir, 0777, true );

		file_put_contents(
			$reg_dir . '/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => 'Fatal Plugin',
					'version'    => '1.0.0',
					'requires'   => '1.0.0',
					'testedUpTo' => '1.8.0',
					'php'        => '8.0',
					'license'    => 'MIT',
				)
			)
		);

		$token = 'fatal_executed_' . uniqid();
		file_put_contents(
			$reg_dir . '/plugin.php',
			"<?php\n\$GLOBALS['{$token}'] = true;\n"
		);

		$settings_mock = $this->createMock( SettingsApi::class );
		$settings_mock->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'fatal-plugin' ) ) );

		$registry = new Registry( $this->temp_dir );
		// Force safe mode = true.
		$loader = new Loader( $registry, $settings_mock, true, false );
		$this->assertTrue( $loader->is_safe_mode() );
		$loader->load_plugins();

		$this->assertFalse( isset( $GLOBALS[ $token ] ), 'Safe mode must prevent execution of regular plugins' );
		$this->assertFalse( $loader->is_loaded( 'fatal-plugin' ) );

		unset( $GLOBALS[ $token ] );
	}

	public function test_skip_mu_plugins_recovery_bypasses_must_use(): void {
		$mu_dir = $this->temp_dir . '/mu-plugins/mu-fatal';
		mkdir( $mu_dir, 0777, true );

		file_put_contents(
			$mu_dir . '/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => 'MU Fatal',
					'version'    => '1.0.0',
					'requires'   => '1.0.0',
					'testedUpTo' => '1.8.0',
					'php'        => '8.0',
					'license'    => 'MIT',
				)
			)
		);

		$token = 'mu_fatal_executed_' . uniqid();
		file_put_contents(
			$mu_dir . '/plugin.php',
			"<?php\n\$GLOBALS['{$token}'] = true;\n"
		);

		$registry = new Registry( $this->temp_dir );
		// Force skip_mu = true.
		$loader = new Loader( $registry, null, false, true );
		$this->assertTrue( $loader->is_skip_mu() );
		$loader->load_plugins();

		$this->assertFalse( isset( $GLOBALS[ $token ] ), 'Skip MU mode must prevent execution of must-use plugins' );
		$this->assertFalse( $loader->is_loaded( 'mu-fatal' ) );

		unset( $GLOBALS[ $token ] );
	}

	public function test_real_sample_plugins_hook_registration(): void {
		$repo_root = dirname( __DIR__, 4 );
		$registry  = new Registry( $repo_root . '/content' );

		// 1. Without active regular plugins, only MU should load and register filter.
		$settings_mock = $this->createMock( SettingsApi::class );
		$settings_mock->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array() ) );

		$loader = new Loader( $registry, $settings_mock, false, false );
		$loader->load_plugins();

		$this->assertTrue( $loader->is_loaded( 'system-integration' ), 'Must-use sample plugin must be loaded' );
		$this->assertTrue( Hooks::has( 'peakurl_system_integration_filter' ), 'system-integration filter hook must be registered' );

		// Filter callback should work.
		$filtered_mu = Hooks::apply_filters( 'peakurl_system_integration_filter', 'test' );
		$this->assertSame( 'filtered_by_system_integration:test', $filtered_mu );

		// Regular plugin should not have registered filter yet.
		$this->assertFalse( $loader->is_loaded( 'example-plugin' ) );
		$this->assertFalse( Hooks::has( 'peakurl_example_plugin_filter' ) );

		// 2. Now activate example-plugin.
		Loader::reset_instance();
		$settings_mock_active = $this->createMock( SettingsApi::class );
		$settings_mock_active->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'example-plugin' ) ) );

		$loader2 = new Loader( $registry, $settings_mock_active, false, false );
		$loader2->load_plugins();

		$this->assertTrue( $loader2->is_loaded( 'example-plugin' ), 'Example plugin should now be loaded' );
		$this->assertTrue( Hooks::has( 'peakurl_example_plugin_filter' ), 'example-plugin filter hook must be registered when active' );

		$filtered_reg = Hooks::apply_filters( 'peakurl_example_plugin_filter', 'url123' );
		$this->assertSame( 'filtered_by_example_plugin:url123', $filtered_reg );
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
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}
}
