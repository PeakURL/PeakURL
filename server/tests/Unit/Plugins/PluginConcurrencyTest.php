<?php
/**
 * Concurrency and persistence safety test suite for plugin activation/deactivation.
 *
 * Verifies that SettingsApi::update_option_with_lock() is used for atomic updates,
 * preserves unrelated plugins during concurrent mutations, and fails explicitly
 * when settings persistence is unavailable.
 *
 * @package PeakURL\Tests\Unit\Plugins
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Plugins;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests atomic row locking, concurrent activation/deactivation, and explicit persistence failures.
 */
class PluginConcurrencyTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_conc_test_' . uniqid();
		mkdir( $this->temp_dir . '/plugins', 0777, true );
		mkdir( $this->temp_dir . '/mu-plugins', 0777, true );
	}

	protected function tearDown(): void {
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function create_service( ?SettingsApi $settings_api = null ): SystemService {
		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'    => 1,
					'email' => 'admin@peakurl.local',
					'roles' => array( 'administrator' ),
				)
			);

		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturn( true );

		$authorization = new Authorization( $roles );

		$ref_class    = new \ReflectionClass( SystemService::class );
		$service_inst = $ref_class->newInstanceWithoutConstructor();

		$props = array(
			'auth_service'  => $auth_service,
			'authorization' => $authorization,
			'roles'         => $roles,
			'settings_api'  => $settings_api,
			'config'        => array(
				Constants::CONTENT_DIR => $this->temp_dir,
				Constants::VERSION     => '1.8.0',
			),
		);

		foreach ( $props as $name => $val ) {
			$prop = $ref_class->getProperty( $name );
			$prop->setValue( $service_inst, $val );
		}

		return $service_inst;
	}

	private function install_fake_plugin( string $id ): void {
		$p_dir = $this->temp_dir . '/plugins/' . $id;
		mkdir( $p_dir, 0777, true );
		file_put_contents(
			$p_dir . '/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => ucfirst( $id ),
					'version'    => '1.0.0',
					'requires'   => '1.0.0',
					'testedUpTo' => '1.8.0',
					'php'        => '8.3',
					'license'    => 'MIT',
					'textDomain' => $id,
					'domainPath' => '/languages',
				)
			)
		);
		file_put_contents( $p_dir . '/plugin.php', "<?php // {$id}\n" );
	}

	public function test_concurrent_activations_preserve_unrelated_active_plugins(): void {
		$this->install_fake_plugin( 'plugin-alpha' );
		$this->install_fake_plugin( 'plugin-beta' );

		// Simulated shared database row locked by FOR UPDATE.
		$shared_db_setting = json_encode( array( 'existing-plugin' ) );

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'update_option_with_lock' )
			->willReturnCallback(
				function ( string $key, callable $modifier ) use ( &$shared_db_setting ): string {
					$this->assertSame( 'active_plugins', $key );
					// In a real database transaction, FOR UPDATE reads the latest committed value.
					$new_val           = $modifier( $shared_db_setting );
					$shared_db_setting = $new_val;
					return $new_val;
				}
			);

		$service1 = $this->create_service( $settings );
		$service2 = $this->create_service( $settings );

		$req1 = new Request( 'POST', '/api/v1/system/plugins/activate', array(), array( 'id' => 'plugin-alpha' ) );
		$req2 = new Request( 'POST', '/api/v1/system/plugins/activate', array(), array( 'id' => 'plugin-beta' ) );

		$res1 = $service1->activate_plugin( $req1 );
		$res2 = $service2->activate_plugin( $req2 );

		$this->assertTrue( $res1['active'] );
		$this->assertTrue( $res2['active'] );

		// Final shared setting must contain existing-plugin, plugin-alpha, and plugin-beta.
		$final_active = json_decode( (string) $shared_db_setting, true );
		$this->assertIsArray( $final_active );
		$this->assertContains( 'existing-plugin', $final_active );
		$this->assertContains( 'plugin-alpha', $final_active );
		$this->assertContains( 'plugin-beta', $final_active );
		$this->assertCount( 3, $final_active );
	}

	public function test_concurrent_deactivations_preserve_unrelated_active_plugins(): void {
		$this->install_fake_plugin( 'plugin-alpha' );
		$this->install_fake_plugin( 'plugin-beta' );

		$shared_db_setting = json_encode( array( 'existing-plugin', 'plugin-alpha', 'plugin-beta' ) );

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'update_option_with_lock' )
			->willReturnCallback(
				function ( string $key, callable $modifier ) use ( &$shared_db_setting ): string {
					$this->assertSame( 'active_plugins', $key );
					$new_val           = $modifier( $shared_db_setting );
					$shared_db_setting = $new_val;
					return $new_val;
				}
			);

		$service1 = $this->create_service( $settings );
		$service2 = $this->create_service( $settings );

		$req1 = new Request( 'POST', '/api/v1/system/plugins/deactivate', array(), array( 'id' => 'plugin-alpha' ) );
		$req2 = new Request( 'POST', '/api/v1/system/plugins/deactivate', array(), array( 'id' => 'plugin-beta' ) );

		$res1 = $service1->deactivate_plugin( $req1 );
		$res2 = $service2->deactivate_plugin( $req2 );

		$this->assertFalse( $res1['active'] );
		$this->assertFalse( $res2['active'] );

		$final_active = json_decode( (string) $shared_db_setting, true );
		$this->assertIsArray( $final_active );
		$this->assertContains( 'existing-plugin', $final_active );
		$this->assertNotContains( 'plugin-alpha', $final_active );
		$this->assertNotContains( 'plugin-beta', $final_active );
		$this->assertSame( array( 'existing-plugin' ), $final_active );
	}

	public function test_activation_fails_explicitly_when_settings_api_missing(): void {
		$this->install_fake_plugin( 'plugin-alpha' );

		$service = $this->create_service( null );
		$request = new Request( 'POST', '/api/v1/system/plugins/activate', array(), array( 'id' => 'plugin-alpha' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Settings persistence is unavailable.' );
		$service->activate_plugin( $request );
	}

	public function test_activation_fails_explicitly_when_settings_persistence_throws(): void {
		$this->install_fake_plugin( 'plugin-alpha' );

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'update_option_with_lock' )
			->willThrowException( new \RuntimeException( 'Settings storage table is not available.', 500 ) );

		$service = $this->create_service( $settings );
		$request = new Request( 'POST', '/api/v1/system/plugins/activate', array(), array( 'id' => 'plugin-alpha' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Failed to persist plugin activation: Settings storage table is not available.' );
		$service->activate_plugin( $request );
	}

	public function test_deactivation_fails_explicitly_when_settings_api_missing(): void {
		$service = $this->create_service( null );
		$request = new Request( 'POST', '/api/v1/system/plugins/deactivate', array(), array( 'id' => 'plugin-alpha' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Settings persistence is unavailable.' );
		$service->deactivate_plugin( $request );
	}

	public function test_deactivation_fails_explicitly_when_settings_persistence_throws(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'update_option_with_lock' )
			->willThrowException( new \RuntimeException( 'Connection refused', 500 ) );

		$service = $this->create_service( $settings );
		$request = new Request( 'POST', '/api/v1/system/plugins/deactivate', array(), array( 'id' => 'plugin-alpha' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Failed to persist plugin deactivation: Connection refused' );
		$service->deactivate_plugin( $request );
	}

	public function test_delete_fails_explicitly_when_settings_persistence_unavailable(): void {
		$this->install_fake_plugin( 'plugin-alpha' );

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->willThrowException( new \RuntimeException( 'Settings storage table is not available.' ) );

		$service = $this->create_service( $settings );
		$request = new Request( 'POST', '/api/v1/system/plugins/delete', array(), array( 'id' => 'plugin-alpha' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Failed to retrieve active plugins: Settings storage table is not available.' );
		$service->delete_plugin( $request );
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
