<?php
/**
 * Security unit tests for Scoped Plugin Browser Asset Serving.
 *
 * Verifies strict path traversal prevention, symlink escape defense,
 * PHP and sensitive file blocking, active-only serving, MIME headers, and ETag caching.
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
use PeakURL\Core\Plugins\Extensions;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests asset path security, MIME type validation, active plugin prerequisite,
 * explicit asset registration requirement, and HTTP caching headers.
 */
class PluginAssetSecurityTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		Extensions::reset_instance();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_asset_test_' . uniqid();
		mkdir( $this->temp_dir . '/plugins/test-plugin/assets', 0777, true );
		mkdir( $this->temp_dir . '/mu-plugins', 0777, true );
	}

	protected function tearDown(): void {
		Extensions::reset_instance();
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function create_service(
		array $active_plugins = array( 'test-plugin' ),
		?array $user = array(
			'id'   => 1,
			'role' => 'admin',
		)
	): SystemService {
		$auth_service = $this->createMock( AuthService::class );
		if ( null === $user ) {
			$auth_service->method( 'get_current_user' )
				->willThrowException( new ApiException( 'Authentication required.', 401 ) );
		} else {
			$auth_service->method( 'get_current_user' )
				->willReturn( $user );
		}

		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturn( true );
		$authorization = new Authorization( $roles );

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( $active_plugins ) );

		$config = array(
			Constants::CONTENT_DIR => $this->temp_dir,
			Constants::VERSION     => '1.8.0',
		);

		return new SystemService(
			$this->createMock( \PeakURL\Services\Database\PeakURL_DB::class ),
			$this->createMock( \PeakURL\Services\Database\Connection::class ),
			$auth_service,
			$settings_api,
			$this->createMock( \PeakURL\Services\Geoip::class ),
			$this->createMock( \PeakURL\Services\Mailer::class ),
			$this->createMock( \PeakURL\Services\Database\Schema::class ),
			$this->createMock( \PeakURL\Services\I18n::class ),
			$roles,
			$authorization,
			$config,
			$this->createMock( \PeakURL\Core\Scheduler\Scheduler::class ),
			$this->createMock( \PeakURL\Services\Update\Manager::class )
		);
	}

	private function create_asset_request(
		string $slug,
		string $asset_path,
		array $headers = array()
	): Request {
		$server = array();
		if ( isset( $headers['If-None-Match'] ) ) {
			$server['HTTP_IF_NONE_MATCH'] = $headers['If-None-Match'];
		}

		$request = new Request(
			'GET',
			'/api/v1/system/plugins/' . $slug . '/assets/' . $asset_path,
			array(),
			array(),
			array(),
			array(),
			$server
		);
		$request->set_route_params(
			array(
				'slug'      => $slug,
				'assetPath' => $asset_path,
			)
		);

		return $request;
	}

	public function test_serve_plugin_asset_serves_valid_js_and_css_with_caching(): void {
		$js_path = $this->temp_dir . '/plugins/test-plugin/assets/admin.js';
		file_put_contents( $js_path, 'console.log("PeakURL Plugin");' );

		Extensions::get_instance()->register_asset( 'test-plugin', 'admin.js', 'script' );

		$service  = $this->create_service();
		$request  = $this->create_asset_request( 'test-plugin', 'admin.js' );
		$response = $service->serve_plugin_asset( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'application/javascript; charset=utf-8', $response['headers']['Content-Type'] );
		$this->assertNotEmpty( $response['headers']['ETag'] );
		$this->assertStringContainsString( 'private, no-transform, max-age=3600', $response['headers']['Cache-Control'] );
		$this->assertSame( 'console.log("PeakURL Plugin");', $response['body'] );

		// Test ETag If-None-Match => 304 Not Modified.
		$etag_request  = $this->create_asset_request( 'test-plugin', 'admin.js', array( 'If-None-Match' => $response['headers']['ETag'] ) );
		$etag_response = $service->serve_plugin_asset( $etag_request );
		$this->assertSame( 304, $etag_response['status'] );
		$this->assertSame( '', $etag_response['body'] );
	}

	public function test_serve_plugin_asset_requires_authentication(): void {
		$js_path = $this->temp_dir . '/plugins/test-plugin/assets/admin.js';
		file_put_contents( $js_path, 'console.log("PeakURL Plugin");' );

		Extensions::get_instance()->register_asset( 'test-plugin', 'admin.js', 'script' );

		// Pass null user to simulate unauthenticated request.
		$service = $this->create_service( array( 'test-plugin' ), null );
		$request = $this->create_asset_request( 'test-plugin', 'admin.js' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 401 );
		$this->expectExceptionMessage( 'Authentication required.' );

		$service->serve_plugin_asset( $request );
	}

	public function test_serve_plugin_asset_rejects_unregistered_asset(): void {
		$js_path = $this->temp_dir . '/plugins/test-plugin/assets/unregistered.js';
		file_put_contents( $js_path, 'console.log("unregistered");' );

		// Do not register unregistered.js in Extensions.
		$service = $this->create_service();
		$request = $this->create_asset_request( 'test-plugin', 'unregistered.js' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 404 );
		$this->expectExceptionMessage( 'Asset is not registered for this plugin.' );

		$service->serve_plugin_asset( $request );
	}

	public function test_serve_plugin_asset_rejects_inactive_plugin(): void {
		$js_path = $this->temp_dir . '/plugins/test-plugin/assets/admin.js';
		file_put_contents( $js_path, 'console.log("inactive");' );

		Extensions::get_instance()->register_asset( 'test-plugin', 'admin.js', 'script' );

		// Plugin 'test-plugin' is NOT in active plugins list.
		$service = $this->create_service( array( 'other-plugin' ) );
		$request = $this->create_asset_request( 'test-plugin', 'admin.js' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 404 );
		$this->expectExceptionMessage( 'Plugin is not active.' );

		$service->serve_plugin_asset( $request );
	}

	public function test_serve_plugin_asset_rejects_directory_traversal(): void {
		$service = $this->create_service();
		$request = $this->create_asset_request( 'test-plugin', '../plugin.json' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'Invalid asset path' );

		$service->serve_plugin_asset( $request );
	}

	public function test_serve_plugin_asset_rejects_php_and_sensitive_extensions(): void {
		$php_file = $this->temp_dir . '/plugins/test-plugin/assets/secret.php';
		file_put_contents( $php_file, "<?php echo 'forbidden';\n" );

		// Register asset to bypass registration check and test extension validation.
		// Directly populate assets array since register_asset rejects .php.
		$ref  = new \ReflectionClass( Extensions::class );
		$prop = $ref->getProperty( 'assets' );
		$prop->setAccessible( true );
		$prop->setValue(
			Extensions::get_instance(),
			array(
				'test-plugin' => array(
					array( 'file' => 'secret.php' ),
				),
			)
		);

		$service = $this->create_service();
		$request = $this->create_asset_request( 'test-plugin', 'secret.php' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'forbidden' );

		$service->serve_plugin_asset( $request );
	}

	public function test_serve_plugin_asset_rejects_source_maps(): void {
		$map_file = $this->temp_dir . '/plugins/test-plugin/assets/admin.js.map';
		file_put_contents( $map_file, '{"version":3}' );

		$ref  = new \ReflectionClass( Extensions::class );
		$prop = $ref->getProperty( 'assets' );
		$prop->setAccessible( true );
		$prop->setValue(
			Extensions::get_instance(),
			array(
				'test-plugin' => array(
					array( 'file' => 'admin.js.map' ),
				),
			)
		);

		$service = $this->create_service();
		$request = $this->create_asset_request( 'test-plugin', 'admin.js.map' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'forbidden' );

		$service->serve_plugin_asset( $request );
	}

	public function test_serve_plugin_asset_rejects_symbolic_link_escape(): void {
		// Create a file outside the plugin assets directory.
		$outside_file = $this->temp_dir . '/outside.js';
		file_put_contents( $outside_file, 'console.log("escape");' );

		// Create a symlink inside assets/ pointing to outside file.
		$symlink_asset = $this->temp_dir . '/plugins/test-plugin/assets/symlink.js';
		symlink( $outside_file, $symlink_asset );

		Extensions::get_instance()->register_asset( 'test-plugin', 'symlink.js', 'script' );

		$service = $this->create_service();
		$request = $this->create_asset_request( 'test-plugin', 'symlink.js' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 404 );
		$this->expectExceptionMessage( 'Asset file not found.' );

		$service->serve_plugin_asset( $request );
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
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
