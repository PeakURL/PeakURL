<?php
/**
 * Unit tests for Plugin Extensions Registry, Pages, Settings, and Actions.
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
 * Tests extension registration, collision prevention, capability enforcement,
 * and plugin settings and action dispatching.
 */
class PluginExtensionsTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_ext_test_' . uniqid();
		mkdir( $this->temp_dir . '/plugins', 0777, true );
		mkdir( $this->temp_dir . '/mu-plugins', 0777, true );
		Extensions::get_instance()->reset();
	}

	protected function tearDown(): void {
		Extensions::get_instance()->reset();
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function create_service(
		array $user_roles = array( 'administrator' ),
		?SettingsApi $settings_api = null,
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

		if ( null === $settings_api ) {
			$settings_api = $this->createMock( SettingsApi::class );
		}

		$config = array(
			Constants::CONTENT_DIR => $this->temp_dir,
			Constants::VERSION     => '1.8.0',
		);

		$service_inst = new SystemService(
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

		return $service_inst;
	}

	public function test_register_page_validates_and_stores_descriptor(): void {
		$ext = Extensions::get_instance();

		$registered = $ext->register_page(
			array(
				'plugin_slug' => 'social-tools',
				'page_id'     => 'metadata-settings',
				'title'       => 'Social Metadata',
				'menu_title'  => 'Social Share',
				'capability'  => 'manage_social_tags',
				'icon'        => 'share',
				'order'       => 45,
				'sections'    => array(
					array(
						'id'     => 'general',
						'title'  => 'General Meta',
						'fields' => array(
							array(
								'key'     => 'enable_twitter',
								'label'   => 'Enable Twitter Cards',
								'type'    => 'switch',
								'default' => true,
							),
						),
					),
				),
			)
		);

		$this->assertTrue( $registered );
		$page = $ext->get_page( 'social-tools', 'metadata-settings' );
		$this->assertNotNull( $page );
		$this->assertSame( 'Social Metadata', $page['title'] );
		$this->assertSame( 'Social Share', $page['menu_title'] );
		$this->assertSame( 'share', $page['icon'] );
		$this->assertSame( 45, $page['order'] );
	}

	public function test_register_page_rejects_reserved_core_route_slugs(): void {
		$ext = Extensions::get_instance();

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'is not available' );

		$ext->register_page(
			array(
				'plugin_slug' => 'my-tool',
				'page_id'     => 'settings', // Reserved!
				'title'       => 'Tool Settings',
			)
		);
	}

	public function test_register_settings_and_action(): void {
		$ext = Extensions::get_instance();

		$ext->register_settings(
			'audit-logger',
			array(
				'capability' => 'manage_audit_logs',
				'fields'     => array(
					'retention_days' => array(
						'type'    => 'number',
						'default' => 30,
					),
				),
			)
		);

		$action_ran = false;
		$ext->register_action(
			'audit-logger',
			'purge_logs',
			function () use ( &$action_ran ): array {
				$action_ran = true;
				return array( 'purged' => 15 );
			},
			'manage_audit_logs'
		);

		$schema = $ext->get_settings_schema( 'audit-logger' );
		$this->assertNotNull( $schema );
		$this->assertSame( 'manage_audit_logs', $schema['capability'] );

		$action = $ext->get_action( 'audit-logger', 'purge_logs' );
		$this->assertNotNull( $action );
		$res = call_user_func( $action['handler'], new Request( 'POST', '/dummy', array(), array() ), array() );
		$this->assertTrue( $action_ran );
		$this->assertSame( 15, $res['purged'] );
	}

	public function test_register_ui_extension_point(): void {
		$ext = Extensions::get_instance();

		$registered = $ext->register_ui_extension(
			array(
				'id'          => 'audit-link-action',
				'plugin_slug' => 'audit-logger',
				'location'    => 'links_overview_actions',
				'label'       => 'Audit All Links',
				'action_id'   => 'run_audit',
				'capability'  => 'manage_audit_logs',
				'icon'        => 'sparkles',
				'confirm'     => 'Run full audit on current links?',
			)
		);

		$this->assertTrue( $registered );
		$extensions = $ext->get_ui_extensions( 'links_overview_actions' );
		$this->assertArrayHasKey( 'audit-link-action', $extensions );
		$this->assertSame( 'Audit All Links', $extensions['audit-link-action']['label'] );
	}

	public function test_get_plugin_extensions_filters_inactive_plugins(): void {
		$ext = Extensions::get_instance();

		// Register an active plugin and an inactive plugin.
		$ext->register_page(
			array(
				'plugin_slug' => 'active-plugin',
				'page_id'     => 'page1',
				'title'       => 'Active Page',
			)
		);
		$ext->register_page(
			array(
				'plugin_slug' => 'inactive-plugin',
				'page_id'     => 'page2',
				'title'       => 'Inactive Page',
			)
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( 'active_plugins' )
			->willReturn( json_encode( array( 'active-plugin' ) ) );

		$service = $this->create_service( array( 'administrator' ), $settings_api );
		$request = new Request( 'GET', '/api/v1/system/plugins/extensions', array(), array() );

		$result = $service->get_plugin_extensions( $request );

		$pages = $result['pages'];
		$this->assertCount( 1, $pages );
		$this->assertSame( 'active-plugin', $pages[0]['pluginSlug'] );
		$this->assertSame( 'page1', $pages[0]['pageId'] );
	}

	public function test_get_plugin_page_checks_capability_and_populates_values(): void {
		$ext = Extensions::get_instance();
		$ext->register_page(
			array(
				'plugin_slug' => 'active-plugin',
				'page_id'     => 'config',
				'title'       => 'Config Page',
				'capability'  => 'manage_special_plugin',
			)
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturnCallback(
				static function ( string $key ) {
					if ( 'active_plugins' === $key ) {
						return json_encode( array( 'active-plugin' ) );
					}
					if ( 'plugin_active-plugin_settings' === $key ) {
						return json_encode( array( 'api_key' => 'secret123' ) );
					}
					return null;
				}
			);

		// Non-admin user lacking required capability => 403.
		$service_forbidden = $this->create_service( array( 'editor' ), $settings_api, array( 'other_cap' ) );
		$request_forbidden = new Request( 'GET', '/api/v1/system/plugins/active-plugin/page/config', array(), array() );
		$request_forbidden->set_route_params(
			array(
				'slug'   => 'active-plugin',
				'pageId' => 'config',
			)
		);

		try {
			$service_forbidden->get_plugin_page( $request_forbidden );
			$this->fail( 'Expected 403 ApiException' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// User with required capability => 200 with populated values.
		$service_ok = $this->create_service( array( 'editor' ), $settings_api, array( 'manage_special_plugin' ) );
		$request_ok = new Request( 'GET', '/api/v1/system/plugins/active-plugin/page/config', array(), array() );
		$request_ok->set_route_params(
			array(
				'slug'   => 'active-plugin',
				'pageId' => 'config',
			)
		);

		$page_data = $service_ok->get_plugin_page( $request_ok );
		$this->assertSame( 'active-plugin', $page_data['pluginSlug'] );
		$this->assertSame( 'config', $page_data['pageId'] );
		$this->assertSame( 'secret123', $page_data['values']['api_key'] );
	}

	public function test_update_plugin_settings_and_dispatch_action(): void {
		$ext = Extensions::get_instance();
		$ext->register_settings(
			'active-plugin',
			array(
				'capability' => 'manage_site_settings',
				'fields'     => array(
					array(
						'key'  => 'webhook_url',
						'type' => 'string',
					),
				),
			)
		);

		$action_called = false;
		$ext->register_action(
			'active-plugin',
			'sync_now',
			function () use ( &$action_called ): array {
				$action_called = true;
				return array( 'synced' => true );
			},
			'manage_site_settings'
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturnCallback(
				static function ( string $key ) {
					if ( 'active_plugins' === $key ) {
						return json_encode( array( 'active-plugin' ) );
					}
					return null;
				}
			);
		$settings_api->expects( $this->once() )
			->method( 'update_option_with_lock' )
			->willReturnCallback(
				static function ( string $key, callable $modifier ) {
					return $modifier( null );
				}
			);

		$service = $this->create_service( array( 'administrator' ), $settings_api );

		// 1. Update settings
		$update_request = new Request(
			'POST',
			'/api/v1/system/plugins/active-plugin/settings',
			array(),
			array( 'settings' => array( 'webhook_url' => 'https://example.com/hook' ) )
		);
		$update_request->set_route_params( array( 'slug' => 'active-plugin' ) );
		$res = $service->update_plugin_settings( $update_request );
		$this->assertSame( 'https://example.com/hook', $res['settings']['webhook_url'] );

		// 2. Dispatch action
		$action_request = new Request(
			'POST',
			'/api/v1/system/plugins/active-plugin/actions/sync_now',
			array(),
			array()
		);
		$action_request->set_route_params(
			array(
				'slug'     => 'active-plugin',
				'actionId' => 'sync_now',
			)
		);
		$action_res = $service->dispatch_plugin_action( $action_request );
		$this->assertTrue( $action_called );
		$this->assertTrue( $action_res['synced'] );
	}

	public function test_update_plugin_settings_rejects_unknown_keys(): void {
		$ext = Extensions::get_instance();
		$ext->register_settings(
			'active-plugin',
			array(
				'capability' => 'manage_site_settings',
				'fields'     => array(
					array(
						'key'  => 'allowed_key',
						'type' => 'string',
					),
				),
			)
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturn( json_encode( array( 'active-plugin' ) ) );

		$service = $this->create_service( array( 'administrator' ), $settings_api );

		$request = new Request(
			'POST',
			'/api/v1/system/plugins/active-plugin/settings',
			array(),
			array( 'settings' => array( 'unregistered_key' => 'malicious_value' ) )
		);
		$request->set_route_params( array( 'slug' => 'active-plugin' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'Setting key \'unregistered_key\' is not permitted' );

		$service->update_plugin_settings( $request );
	}

	public function test_update_plugin_settings_validates_field_types_and_select_options(): void {
		$ext = Extensions::get_instance();
		$ext->register_settings(
			'active-plugin',
			array(
				'capability' => 'manage_site_settings',
				'fields'     => array(
					array(
						'key'  => 'is_enabled',
						'type' => 'boolean',
					),
					array(
						'key'  => 'timeout_seconds',
						'type' => 'integer',
					),
					array(
						'key'     => 'mode',
						'type'    => 'select',
						'options' => array( 'fast', 'thorough' ),
					),
				),
			)
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturn( json_encode( array( 'active-plugin' ) ) );

		$service = $this->create_service( array( 'administrator' ), $settings_api );

		// 1. Invalid boolean type
		$req1 = new Request( 'POST', '/settings', array(), array( 'settings' => array( 'is_enabled' => 'not-a-bool' ) ) );
		$req1->set_route_params( array( 'slug' => 'active-plugin' ) );
		try {
			$service->update_plugin_settings( $req1 );
			$this->fail( 'Expected 400 ApiException for invalid boolean' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertStringContainsString( 'must be a boolean', $e->getMessage() );
		}

		// 2. Invalid select option
		$req2 = new Request( 'POST', '/settings', array(), array( 'settings' => array( 'mode' => 'invalid_choice' ) ) );
		$req2->set_route_params( array( 'slug' => 'active-plugin' ) );
		try {
			$service->update_plugin_settings( $req2 );
			$this->fail( 'Expected 400 ApiException for invalid select option' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertStringContainsString( 'Selected option for setting \'mode\' is not allowed', $e->getMessage() );
		}
	}

	public function test_duplicate_registrations_rejected(): void {
		$ext = Extensions::get_instance();

		// Duplicate Page
		$ext->register_page(
			array(
				'plugin_slug' => 'plugin-a',
				'page_id'     => 'page-1',
				'title'       => 'Title 1',
			)
		);
		try {
			$ext->register_page(
				array(
					'plugin_slug' => 'plugin-a',
					'page_id'     => 'page-1',
					'title'       => 'Title 2',
				)
			);
			$this->fail( 'Expected duplicate page registration to fail' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->getCode() );
		}

		// Duplicate Settings
		$ext->register_settings( 'plugin-a', array( 'fields' => array() ) );
		try {
			$ext->register_settings( 'plugin-a', array( 'fields' => array() ) );
			$this->fail( 'Expected duplicate settings registration to fail' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->getCode() );
		}

		// Duplicate Action
		$ext->register_action( 'plugin-a', 'action-1', static fn() => array() );
		try {
			$ext->register_action( 'plugin-a', 'action-1', static fn() => array() );
			$this->fail( 'Expected duplicate action registration to fail' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->getCode() );
		}

		// Duplicate UI Extension
		$ext->register_ui_extension(
			array(
				'id'          => 'ui-1',
				'plugin_slug' => 'plugin-a',
				'location'    => 'links_overview_actions',
				'label'       => 'UI 1',
				'action_id'   => 'action-1',
			)
		);
		try {
			$ext->register_ui_extension(
				array(
					'id'          => 'ui-1',
					'plugin_slug' => 'plugin-a',
					'location'    => 'links_overview_actions',
					'label'       => 'UI 1 Dupe',
					'action_id'   => 'action-1',
				)
			);
			$this->fail( 'Expected duplicate UI extension registration to fail' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->getCode() );
		}

		// Duplicate Asset
		$ext->register_asset( 'plugin-a', 'style.css', 'style' );
		try {
			$ext->register_asset( 'plugin-a', 'style.css', 'style' );
			$this->fail( 'Expected duplicate asset registration to fail' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->getCode() );
		}
	}

	public function test_namespace_spoofing_rejected(): void {
		$ext = Extensions::get_instance();
		$ext->set_current_plugin( 'genuine-plugin' );

		try {
			$ext->register_page(
				array(
					'plugin_slug' => 'victim-plugin',
					'page_id'     => 'admin',
					'title'       => 'Spoofed Page',
				)
			);
			$this->fail( 'Expected cross-namespace registration to fail' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertStringContainsString( 'cannot register extensions under namespace', $e->getMessage() );
		} finally {
			$ext->set_current_plugin( null );
		}
	}

	public function test_register_ui_extension_rejects_unsupported_location(): void {
		$ext = Extensions::get_instance();

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'Unsupported UI extension location' );

		$ext->register_ui_extension(
			array(
				'id'          => 'table-action',
				'plugin_slug' => 'plugin-a',
				'location'    => 'links_table_action',
				'label'       => 'Table Action',
				'action_id'   => 'some-action',
			)
		);
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
