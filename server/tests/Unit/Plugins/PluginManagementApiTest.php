<?php
/**
 * Unit tests for Plugin Management Lifecycle and ZIP Installation.
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
use PeakURL\Features\System\Controller as SystemController;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Http\Request;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests activation, deactivation, deletion safeguards, and secure ZIP extraction.
 */
class PluginManagementApiTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_mgmt_test_' . uniqid();
		mkdir( $this->temp_dir . '/plugins', 0777, true );
		mkdir( $this->temp_dir . '/mu-plugins', 0777, true );
	}

	protected function tearDown(): void {
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function create_service(
		array $user_roles = array( 'administrator' ),
		?SettingsApi $settings_api = null
	): SystemService {
		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'    => 1,
					'email' => 'admin@peakurl.local',
					'roles' => $user_roles,
				)
			);

		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )
			->willReturnCallback(
				static function ( array $user, string $cap ) use ( $user_roles ): bool {
					return in_array( 'administrator', $user_roles, true );
				}
			);

		$authorization = new Authorization( $roles );

		if ( null === $settings_api ) {
			$settings_api = $this->createMock( SettingsApi::class );
		}

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

	public function test_activate_plugin_persists_in_settings(): void {
		// Set up a valid regular plugin.
		$p_dir = $this->temp_dir . '/plugins/test-plugin';
		mkdir( $p_dir, 0777, true );
		file_put_contents(
			$p_dir . '/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => 'Test Plugin',
					'version'    => '1.0.0',
					'requires'   => '1.0.0',
					'testedUpTo' => '1.8.0',
					'php'        => '8.0',
					'license'    => 'MIT',
					'textDomain' => 'test-plugin',
					'domainPath' => '/languages',
				)
			)
		);
		file_put_contents( $p_dir . '/plugin.php', "<?php // Entry\n" );

		$settings    = $this->createMock( SettingsApi::class );
		$updated_val = null;
		$settings->expects( $this->once() )
			->method( 'update_option_with_lock' )
			->with(
				'active_plugins',
				$this->callback(
					function ( $modifier ) use ( &$updated_val ) {
						if ( is_callable( $modifier ) ) {
							$updated_val = $modifier( json_encode( array() ) );
							return str_contains( (string) $updated_val, 'test-plugin' );
						}
						return false;
					}
				)
			)
			->willReturnCallback(
				function ( $key, $modifier ) {
					return $modifier( json_encode( array() ) );
				}
			);

		$service  = $this->create_service( array( 'administrator' ), $settings );
		$request  = new Request( 'POST', '/api/v1/system/plugins/activate', array(), array( 'id' => 'test-plugin' ) );
		$response = $service->activate_plugin( $request );

		$this->assertSame( 'test-plugin', $response['id'] );
		$this->assertTrue( $response['active'] );
		$this->assertContains( 'test-plugin', $response['activePlugins'] );
	}

	public function test_activate_plugin_rejects_incompatible(): void {
		$p_dir = $this->temp_dir . '/plugins/incompat-plugin';
		mkdir( $p_dir, 0777, true );
		file_put_contents(
			$p_dir . '/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => 'Incompat Plugin',
					'version'    => '1.0.0',
					'requires'   => '99.0.0',
					'testedUpTo' => '99.0.0',
					'php'        => '99.0',
					'license'    => 'MIT',
				)
			)
		);
		file_put_contents( $p_dir . '/plugin.php', "<?php // Entry\n" );

		$service = $this->create_service();
		$request = new Request( 'POST', '/api/v1/system/plugins/activate', array(), array( 'id' => 'incompat-plugin' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 400 );
		$service->activate_plugin( $request );
	}

	public function test_deactivate_plugin_removes_from_settings(): void {
		$settings    = $this->createMock( SettingsApi::class );
		$updated_val = null;

		$settings->expects( $this->once() )
			->method( 'update_option_with_lock' )
			->with(
				'active_plugins',
				$this->callback(
					static function ( $modifier ): bool {
						if ( is_callable( $modifier ) ) {
							$res     = $modifier( json_encode( array( 'test-plugin', 'other-plugin' ) ) );
							$decoded = json_decode( (string) $res, true );
							return is_array( $decoded ) && ! in_array( 'test-plugin', $decoded, true ) && in_array( 'other-plugin', $decoded, true );
						}
						return false;
					}
				)
			)
			->willReturnCallback(
				function ( $key, $modifier ) {
					return $modifier( json_encode( array( 'test-plugin', 'other-plugin' ) ) );
				}
			);

		$service  = $this->create_service( array( 'administrator' ), $settings );
		$request  = new Request( 'POST', '/api/v1/system/plugins/deactivate', array(), array( 'id' => 'test-plugin' ) );
		$response = $service->deactivate_plugin( $request );

		$this->assertSame( 'test-plugin', $response['id'] );
		$this->assertFalse( $response['active'] );
		$this->assertNotContains( 'test-plugin', $response['activePlugins'] );
	}

	public function test_delete_plugin_rejects_active_plugin(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'active-plugin' ) ) );

		$service = $this->create_service( array( 'administrator' ), $settings );
		$request = new Request( 'POST', '/api/v1/system/plugins/delete', array(), array( 'id' => 'active-plugin' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Cannot delete an active plugin' );
		$service->delete_plugin( $request );
	}

	public function test_delete_plugin_rejects_must_use(): void {
		$mu_dir = $this->temp_dir . '/mu-plugins/mu-test';
		mkdir( $mu_dir, 0777, true );

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )->willReturn( json_encode( array() ) );

		$service = $this->create_service( array( 'administrator' ), $settings );
		$request = new Request( 'POST', '/api/v1/system/plugins/delete', array(), array( 'id' => 'mu-test' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$service->delete_plugin( $request );
	}

	public function test_delete_plugin_safely_removes_inactive_directory(): void {
		$p_dir = $this->temp_dir . '/plugins/delete-me';
		mkdir( $p_dir . '/sub', 0777, true );
		file_put_contents( $p_dir . '/plugin.json', '{}' );
		file_put_contents( $p_dir . '/sub/file.txt', 'hello' );

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )->willReturn( json_encode( array() ) );

		$service  = $this->create_service( array( 'administrator' ), $settings );
		$request  = new Request( 'POST', '/api/v1/system/plugins/delete', array(), array( 'id' => 'delete-me' ) );
		$response = $service->delete_plugin( $request );

		$this->assertTrue( $response['deleted'] );
		$this->assertFalse( file_exists( $p_dir ) );
	}

	public function test_upload_plugin_rejects_zip_bomb(): void {
		$zip_file = $this->temp_dir . '/bomb.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		// Single huge repeating stream.
		$zip->addFromString( 'bomb/huge.txt', str_repeat( '0', 1024 * 1024 * 5 ) ); // 5MB uncompressed.
		$zip->addFromString( 'bomb/plugin.json', '{}' );
		$zip->addFromString( 'bomb/plugin.php', '<?php' );
		$zip->close();

		$files = array(
			'plugin_zip' => array(
				'tmp_name' => $zip_file,
				'name'     => 'bomb.zip',
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $zip_file ),
			),
		);

		$service = $this->create_service();
		$request = new Request( 'POST', '/api/v1/system/plugins/upload', array(), array(), array(), $files );

		$this->expectException( ApiException::class );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_path_traversal_archive(): void {
		$zip_file = $this->temp_dir . '/traversal.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( '../escaped.txt', 'danger' );
		$zip->addFromString( 'plugin.json', '{}' );
		$zip->addFromString( 'plugin.php', '<?php' );
		$zip->close();

		$files = array(
			'plugin_zip' => array(
				'tmp_name' => $zip_file,
				'name'     => 'traversal.zip',
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $zip_file ),
			),
		);

		$service = $this->create_service();
		$request = new Request( 'POST', '/api/v1/system/plugins/upload', array(), array(), array(), $files );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains unsafe path entries' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_installs_valid_package_inactive(): void {
		$zip_file = $this->temp_dir . '/uploaded-plugin.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString(
			'custom-plugin/plugin.json',
			json_encode(
				array(
					'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
					'name'       => 'Custom Plugin',
					'version'    => '1.0.0',
					'requires'   => '1.0.0',
					'testedUpTo' => '1.8.0',
					'php'        => '8.0',
					'license'    => 'MIT',
					'textDomain' => 'custom-plugin',
					'domainPath' => '/languages',
				)
			)
		);
		$zip->addFromString( 'custom-plugin/plugin.php', "<?php // custom plugin entry\n" );
		$zip->addFromString( 'custom-plugin/README.md', "Custom Plugin Readme\n" );
		$zip->close();

		$files = array(
			'plugin_zip' => array(
				'tmp_name' => $zip_file,
				'name'     => 'uploaded-plugin.zip',
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $zip_file ),
			),
		);

		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )->willReturn( json_encode( array() ) );

		$service  = $this->create_service( array( 'administrator' ), $settings );
		$request  = new Request( 'POST', '/api/v1/system/plugins/upload', array(), array(), array(), $files );
		$response = $service->upload_plugin( $request );

		$this->assertSame( 'custom-plugin', $response['id'] );
		$this->assertSame( 'Custom Plugin', $response['name'] );
		$this->assertFalse( $response['active'] );
		$this->assertTrue( is_dir( $this->temp_dir . '/plugins/custom-plugin' ) );
		$this->assertTrue( is_file( $this->temp_dir . '/plugins/custom-plugin/plugin.json' ) );
		$this->assertTrue( is_file( $this->temp_dir . '/plugins/custom-plugin/plugin.php' ) );
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
