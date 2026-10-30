<?php
/**
 * End-to-end integration acceptance test for the PeakURL Plugin Foundation.
 *
 * Demonstrates:
 * - Developer package convention (plugin.json, plugin.php, server/, assets/, readme)
 * - ZIP installation and active plugin inventory
 * - Lifecycle activation and deactivation
 * - Loading PHP classes from server/ directory
 * - Action and filter hook registration and execution
 * - Administrative page registration and settings persistence
 * - Authenticated backend action dispatching
 * - Scoped asset serving with MIME types and caching headers
 * - UI extension point registration
 * - In-place plugin upgrade from v1.0.0 to v1.1.0 with active state preservation
 * - Downgrade prevention
 *
 * @package PeakURL\Tests\Integration\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Plugins;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Core\Plugins\Extensions;
use PeakURL\Core\Plugins\Loader;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\System\Controller as SystemController;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Http\Request;
use PeakURL\Http\Response;
use ZipArchive;

class PluginFoundationIntegrationTest extends TestCase {

	private string $temp_dir;
	private string $plugins_dir;
	private string $mu_plugins_dir;
	private array $saved_options = array();

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();
		Extensions::get_instance()->reset();

		$this->temp_dir       = sys_get_temp_dir() . '/peakurl_p4e_integration_' . uniqid();
		$this->plugins_dir    = $this->temp_dir . '/plugins';
		$this->mu_plugins_dir = $this->temp_dir . '/mu-plugins';

		mkdir( $this->plugins_dir, 0777, true );
		mkdir( $this->mu_plugins_dir, 0777, true );

		$this->saved_options = array(
			'active_plugins' => json_encode( array() ),
		);
	}

	protected function tearDown(): void {
		Extensions::get_instance()->reset();
		Hooks::remove_all();
		Hooks::reset_actions();

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
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	private function create_system_service(
		array $user_roles = array( 'administrator' ),
		array $capabilities = array()
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
				static function ( array $user, string $cap ) use ( $user_roles, $capabilities ): bool {
					if ( in_array( 'administrator', $user_roles, true ) ) {
						return true;
					}
					return in_array( $cap, $capabilities, true );
				}
			);

		$authorization = new Authorization( $roles );

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturnCallback(
				function ( string $key, $fallback = null ) {
					return $this->saved_options[ $key ] ?? $fallback;
				}
			);
		$settings_api->method( 'update_option' )
			->willReturnCallback(
				function ( string $key, string $value, string $updated_at, bool $autoload = true ): void {
					$this->saved_options[ $key ] = $value;
				}
			);
		$settings_api->method( 'update_option_with_lock' )
			->willReturnCallback(
				function ( string $key, callable $updater ): ?string {
					$current                     = $this->saved_options[ $key ] ?? null;
					$new_val                     = $updater( $current );
					$this->saved_options[ $key ] = (string) $new_val;
					return $new_val;
				}
			);

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

	private function build_sample_zip( string $version = '1.0.0' ): string {
		$zip_file = $this->temp_dir . "/social-preview-{$version}.zip";
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$manifest = array(
			'$schema'       => 'https://peakurl.org/schemas/v1/plugin.json',
			'name'          => 'Social Preview',
			'version'       => $version,
			'requires'      => '1.8.0',
			'testedUpTo'    => '1.8.0',
			'php'           => '8.3',
			'license'       => 'MIT',
			'textDomain'    => 'social-preview',
			'domainPath'    => '/languages',
			'pluginUri'     => 'https://example.com/social-preview',
			'description'   => 'Provides social card generation for links.',
			'author'        => 'Social Labs',
			'authorUri'     => 'https://example.com/social-labs',
			'documentation' => 'https://example.com/social-preview/docs',
		);

		$zip->addFromString( 'social-preview/plugin.json', (string) json_encode( $manifest, JSON_PRETTY_PRINT ) );

		// server/services/social-metadata.php
		$service_php = <<<'PHP'
<?php
declare(strict_types=1);

namespace SocialPreviewPlugin\Services;

class SocialMetadataService {
	public function format_tag( string $property, string $content ): string {
		return sprintf( '<meta property="%s" content="%s" />', htmlspecialchars( $property ), htmlspecialchars( $content ) );
	}
}
PHP;
		$zip->addFromString( 'social-preview/server/services/social-metadata.php', $service_php );

		// plugin.php
		$plugin_php = <<<PHP
<?php
/**
 * Plugin Name: Social Preview
 * Plugin URI: https://example.com/social-preview
 * Description: Provides social card generation for links.
 * Version: {$version}
 * Requires PeakURL: 1.8.0
 * Tested up to: 1.8.0
 * Requires PHP: 8.3
 * Author: Social Labs
 * Author URI: https://example.com/social-labs
 * License: MIT
 * Text Domain: social-preview
 * Domain Path: /languages
 */

declare(strict_types=1);

require_once __DIR__ . '/server/services/social-metadata.php';

// Register a filter hook
add_filter( 'social_preview_title', function( string \$title ): string {
	return 'Social: ' . \$title;
} );

// Register administrative page
register_plugin_page( array(
	'plugin_slug' => 'social-preview',
	'page_id'     => 'config',
	'title'       => 'Social Preview Settings',
	'menu_title'  => 'Social Preview',
	'capability'  => 'manage_site_settings',
	'sections'    => array(
		array(
			'id'     => 'general',
			'title'  => 'Card Configuration',
			'fields' => array(
				array(
					'key'     => 'card_type',
					'label'   => 'Card Type',
					'type'    => 'select',
					'default' => 'summary_large_image',
					'options' => array(
						array( 'label' => 'Large Image', 'value' => 'summary_large_image' ),
						array( 'label' => 'Standard', 'value' => 'summary' ),
					),
				),
				array(
					'key'     => 'enabled',
					'label'   => 'Enable Cards',
					'type'    => 'switch',
					'default' => true,
				),
			),
		),
	),
	'actions'     => array(
		array(
			'id'    => 'flush_cache',
			'label' => 'Flush Image Cache',
		),
	),
) );

// Register settings schema
register_plugin_settings( 'social-preview', array(
	'capability' => 'manage_site_settings',
	'fields'     => array(
		array( 'key' => 'card_type', 'type' => 'string', 'default' => 'summary_large_image' ),
		array( 'key' => 'enabled', 'type' => 'boolean', 'default' => true ),
	),
) );

// Register backend action
register_plugin_action(
	'social-preview',
	'flush_cache',
	function( \$request, \$params ) {
		return array(
			'success' => true,
			'flushed' => 42,
			'version' => '{$version}',
		);
	},
	'manage_site_settings'
);

// Register scoped assets
register_plugin_asset( 'social-preview', 'admin.js', 'script', array(
	'scope'   => 'page',
	'page_id' => 'config',
) );

register_plugin_asset( 'social-preview', 'admin.css', 'style', array(
	'scope'   => 'page',
	'page_id' => 'config',
) );

// Register native UI extension point
register_plugin_ui_extension( array(
	'id'          => 'social_preview_refresh_card',
	'plugin_slug' => 'social-preview',
	'location'    => 'links_overview_actions',
	'label'       => 'Refresh Social Preview',
	'action_id'   => 'flush_cache',
	'capability'  => 'manage_site_settings',
) );
PHP;
		$zip->addFromString( 'social-preview/plugin.php', $plugin_php );

		// assets
		$zip->addFromString( 'social-preview/assets/admin.js', "/* Social Preview Admin Script v{$version} */ console.log('social-preview loaded');" );
		$zip->addFromString( 'social-preview/assets/admin.css', "/* Social Preview CSS v{$version} */ .social-card-badge { display: inline-block; }" );

		// readme files
		$zip->addFromString( 'social-preview/README.md', "# Social Preview\nOpen graph generator for PeakURL." );
		$zip->addFromString( 'social-preview/readme.txt', "=== Social Preview ===\nTested up to: 1.8.0\n" );

		$zip->close();
		return $zip_file;
	}

	private function create_upload_request( string $zip_path ): Request {
		$files = array(
			'plugin_zip' => array(
				'tmp_name' => $zip_path,
				'name'     => basename( $zip_path ),
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $zip_path ),
			),
		);

		return new Request( 'POST', '/api/v1/system/plugins/upload', array(), array(), array(), $files );
	}

	public function test_full_plugin_foundation_lifecycle(): void {
		$service    = $this->create_system_service();
		$controller = new SystemController( $service );

		// -------------------------------------------------------------
		// 1. Initial State: Empty installed inventory
		// -------------------------------------------------------------
		$list_request  = new Request( 'GET', '/api/v1/system/plugins', array(), array() );
		$list_response = $controller->plugins( $list_request );
		$this->assertSame( 200, $list_response['status'] );
		$this->assertSame( 0, $list_response['body']['data']['total'] );

		// -------------------------------------------------------------
		// 2. Upload and Install v1.0.0
		// -------------------------------------------------------------
		$zip_v100        = $this->build_sample_zip( '1.0.0' );
		$upload_request  = $this->create_upload_request( $zip_v100 );
		$upload_response = $controller->upload_plugin( $upload_request );

		$this->assertSame( 201, $upload_response['status'] );
		$this->assertSame( 'Plugin installed successfully.', $upload_response['body']['message'] );

		// Verify installed regular plugin appears in inventory
		$list_response2 = $controller->plugins( $list_request );
		$this->assertSame( 1, $list_response2['body']['data']['total'] );
		$this->assertSame( 'social-preview', $list_response2['body']['data']['regular'][0]['id'] );
		$this->assertSame( '1.0.0', $list_response2['body']['data']['regular'][0]['version'] );
		$this->assertFalse( $list_response2['body']['data']['regular'][0]['active'] );

		// While inactive: assets must return 404, extensions must be empty
		$ext_request  = new Request( 'GET', '/api/v1/system/plugins/extensions', array(), array() );
		$ext_response = $controller->plugin_extensions( $ext_request );
		$this->assertSame( 200, $ext_response['status'] );
		$this->assertEmpty( $ext_response['body']['data']['pages'] );
		$this->assertEmpty( $ext_response['body']['data']['uiExtensions'] );

		// Asset request while inactive throws 404 ApiException
		$asset_req = new Request( 'GET', '/dummy', array(), array() );
		$asset_req->set_route_params(
			array(
				'slug'      => 'social-preview',
				'assetPath' => 'admin.js',
			)
		);
		try {
			$controller->serve_plugin_asset( $asset_req );
			$this->fail( 'Expected 404 ApiException for inactive plugin asset' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->get_status() );
			$this->assertSame( 'Plugin is not active.', $e->getMessage() );
		}

		// -------------------------------------------------------------
		// 3. Activate Plugin
		// -------------------------------------------------------------
		$activate_request = new Request(
			'POST',
			'/api/v1/system/plugins/activate',
			array(),
			array( 'id' => 'social-preview' )
		);
		$act_response     = $controller->activate_plugin( $activate_request );
		$this->assertSame( 200, $act_response['status'] );
		$this->assertSame( 'Plugin activated.', $act_response['body']['message'] );

		// Verify active_plugins saved
		$active_plugins = json_decode( $this->saved_options['active_plugins'] ?? '[]', true );
		$this->assertContains( 'social-preview', $active_plugins );

		// Bootstrap Loader to simulate application bootstrap with active plugin
		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturn( $this->saved_options['active_plugins'] );

		$registry = new \PeakURL\Core\Plugins\Registry( $this->temp_dir, null, '1.8.0', '8.4.26' );
		$loader   = new Loader( $registry, $settings_api );
		$loader->load_plugins();

		// Verify server/services/social-metadata.php loaded and instantiable
		$this->assertTrue( class_exists( 'SocialPreviewPlugin\\Services\\SocialMetadataService' ) );
		$meta_service = new \SocialPreviewPlugin\Services\SocialMetadataService();
		$this->assertSame(
			'<meta property="og:title" content="Hello World" />',
			$meta_service->format_tag( 'og:title', 'Hello World' )
		);

		// Verify registered filter hook
		$filtered_title = apply_filters( 'social_preview_title', 'My Link' );
		$this->assertSame( 'Social: My Link', $filtered_title );

		// -------------------------------------------------------------
		// 4. Extensions and Admin Page Descriptors
		// -------------------------------------------------------------
		$ext_response = $controller->plugin_extensions( $ext_request );
		$this->assertSame( 200, $ext_response['status'] );
		$this->assertCount( 1, $ext_response['body']['data']['pages'] );
		$page = $ext_response['body']['data']['pages'][0];
		$this->assertSame( 'social-preview', $page['pluginSlug'] );
		$this->assertSame( 'config', $page['pageId'] );
		$this->assertSame( 'Social Preview Settings', $page['title'] );
		$this->assertCount( 1, $page['actions'] );
		$this->assertSame( 'flush_cache', $page['actions'][0]['id'] );

		// Verify native UI extension point registered on links_overview_actions
		$this->assertCount( 1, $ext_response['body']['data']['uiExtensions'] );
		$ui_ext = $ext_response['body']['data']['uiExtensions'][0];
		$this->assertSame( 'social_preview_refresh_card', $ui_ext['id'] );
		$this->assertSame( 'links_overview_actions', $ui_ext['location'] );
		$this->assertSame( 'flush_cache', $ui_ext['actionId'] );

		// Query specific page descriptor via controller
		$page_req = new Request( 'GET', '/dummy', array(), array() );
		$page_req->set_route_params(
			array(
				'slug'   => 'social-preview',
				'pageId' => 'config',
			)
		);
		$page_response = $controller->get_plugin_page( $page_req );
		$this->assertSame( 200, $page_response['status'] );
		$this->assertSame( 'summary_large_image', $page_response['body']['data']['values']['card_type'] );
		$this->assertTrue( $page_response['body']['data']['values']['enabled'] );
		$this->assertCount( 2, $page_response['body']['data']['assets'] );

		// -------------------------------------------------------------
		// 5. Plugin Settings Read & Update
		// -------------------------------------------------------------
		$update_settings_req = new Request(
			'POST',
			'/dummy',
			array(),
			array(
				'settings' => array(
					'card_type' => 'summary',
					'enabled'   => false,
				),
			)
		);
		$update_settings_req->set_route_params( array( 'slug' => 'social-preview' ) );
		$settings_res = $controller->update_plugin_settings( $update_settings_req );
		$this->assertSame( 200, $settings_res['status'] );
		$this->assertSame( 'summary', $settings_res['body']['data']['settings']['card_type'] );
		$this->assertFalse( $settings_res['body']['data']['settings']['enabled'] );

		// Verify persisted in SettingsApi
		$raw_saved = json_decode( $this->saved_options['plugin_social-preview_settings'] ?? '{}', true );
		$this->assertSame( 'summary', $raw_saved['card_type'] );
		$this->assertFalse( $raw_saved['enabled'] );

		// -------------------------------------------------------------
		// 6. Backend Action Dispatching
		// -------------------------------------------------------------
		$action_req = new Request( 'POST', '/dummy', array(), array() );
		$action_req->set_route_params(
			array(
				'slug'     => 'social-preview',
				'actionId' => 'flush_cache',
			)
		);
		$action_res = $controller->dispatch_plugin_action( $action_req );
		$this->assertSame( 200, $action_res['status'] );
		$this->assertTrue( $action_res['body']['data']['success'] );
		$this->assertSame( 42, $action_res['body']['data']['flushed'] );
		$this->assertSame( '1.0.0', $action_res['body']['data']['version'] );

		// -------------------------------------------------------------
		// 7. Scoped Asset Serving
		// -------------------------------------------------------------
		$asset_req->set_route_params(
			array(
				'slug'      => 'social-preview',
				'assetPath' => 'admin.js',
			)
		);
		$js_response = $controller->serve_plugin_asset( $asset_req );
		$this->assertSame( 200, $js_response['status'] );
		$this->assertSame( 'application/javascript; charset=utf-8', $js_response['headers']['Content-Type'] );
		$this->assertStringContainsString( 'social-preview loaded', (string) $js_response['body'] );
		$this->assertNotEmpty( $js_response['headers']['ETag'] );

		$asset_req->set_route_params(
			array(
				'slug'      => 'social-preview',
				'assetPath' => 'admin.css',
			)
		);
		$css_response = $controller->serve_plugin_asset( $asset_req );
		$this->assertSame( 200, $css_response['status'] );
		$this->assertSame( 'text/css; charset=utf-8', $css_response['headers']['Content-Type'] );
		$this->assertStringContainsString( '.social-card-badge', (string) $css_response['body'] );

		// -------------------------------------------------------------
		// 8. In-place Upgrade from v1.0.0 to v1.1.0
		// -------------------------------------------------------------
		$zip_v110         = $this->build_sample_zip( '1.1.0' );
		$upgrade_request  = $this->create_upload_request( $zip_v110 );
		$upgrade_response = $controller->upload_plugin( $upgrade_request );

		$this->assertSame( 200, $upgrade_response['status'] );
		$this->assertSame( 'Plugin updated successfully.', $upgrade_response['body']['message'] );

		// Active state must be preserved
		$active_plugins = json_decode( $this->saved_options['active_plugins'] ?? '[]', true );
		$this->assertContains( 'social-preview', $active_plugins );

		// Inventory should now show version 1.1.0 and active
		$list_response3 = $controller->plugins( $list_request );
		$this->assertSame( 1, $list_response3['body']['data']['total'] );
		$this->assertSame( '1.1.0', $list_response3['body']['data']['regular'][0]['version'] );
		$this->assertTrue( $list_response3['body']['data']['regular'][0]['active'] );

		// Ensure no leftover backup directory exists in plugins directory
		$files = scandir( $this->plugins_dir );
		$this->assertIsArray( $files );
		foreach ( $files as $f ) {
			$this->assertStringStartsNotWith( '.backup_social-preview', $f );
		}

		// -------------------------------------------------------------
		// 9. Downgrade Rejection
		// -------------------------------------------------------------
		// Uploading v1.0.0 again when v1.1.0 is installed must fail with 400
		try {
			$downgrade_request = $this->create_upload_request( $zip_v100 );
			$controller->upload_plugin( $downgrade_request );
			$this->fail( 'Expected ApiException for plugin downgrade attempt' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->get_status() );
			$this->assertStringContainsString( 'Cannot downgrade plugin', $e->getMessage() );
		}

		// -------------------------------------------------------------
		// 10. Deactivation Lifecycle
		// -------------------------------------------------------------
		$deactivate_request = new Request(
			'POST',
			'/api/v1/system/plugins/deactivate',
			array(),
			array( 'id' => 'social-preview' )
		);
		$deact_response     = $controller->deactivate_plugin( $deactivate_request );
		$this->assertSame( 200, $deact_response['status'] );
		$this->assertSame( 'Plugin deactivated.', $deact_response['body']['message'] );

		$active_plugins = json_decode( $this->saved_options['active_plugins'] ?? '[]', true );
		$this->assertNotContains( 'social-preview', $active_plugins );

		// Inventory shows inactive
		$list_response4 = $controller->plugins( $list_request );
		$this->assertFalse( $list_response4['body']['data']['regular'][0]['active'] );
	}
}
