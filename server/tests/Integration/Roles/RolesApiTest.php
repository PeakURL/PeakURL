<?php
/**
 * Roles and Capabilities API Integration Test.
 *
 * Verifies role definitions catalog, capability persistence in the settings
 * table, immutable protections on administrative recovery permissions, role
 * decoupling from individual capabilities, and HTTP endpoints.
 *
 * @package PeakURL\Tests\Integration\Roles
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Roles;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Roles\Controller as RolesController;
use PeakURL\Features\Roles\Service as RolesService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Tests\Integration\ProcessConcurrencyTrait;
use PeakURL\Tests\Integration\Users\UsersContractsTest;
use PDO;

/**
 * RolesApiTest — Integration tests for Issue #31 role and capability management.
 */
class RolesApiTest extends TestCase {

	use ProcessConcurrencyTrait;

	/**
	 * Test prefixes scheduled for cleanup on tearDown.
	 *
	 * @var array<int, string>
	 */
	private array $prefixes_to_clean = array();

	/**
	 * In-memory storage backing the SettingsApi mock.
	 *
	 * @var array<string, string>
	 */
	private array $stored_options = array();

	/**
	 * Settings API mock instance.
	 *
	 * @var SettingsApi
	 */
	private SettingsApi $settings_api;

	/**
	 * Roles registry under test.
	 *
	 * @var Roles
	 */
	private Roles $roles;

	/**
	 * Authorization service instance.
	 *
	 * @var Authorization
	 */
	private Authorization $authorization;

	/**
	 * Auth domain service mock.
	 *
	 * @var AuthService
	 */
	private AuthService $auth_service;

	/**
	 * Roles domain service under test.
	 *
	 * @var RolesService
	 */
	private RolesService $roles_service;

	/**
	 * Roles controller under test.
	 *
	 * @var RolesController
	 */
	private RolesController $roles_controller;

	/**
	 * Sample admin user row.
	 *
	 * @var array<string, mixed>
	 */
	private array $admin_user;

	/**
	 * Sample editor user row.
	 *
	 * @var array<string, mixed>
	 */
	private array $editor_user;

	/**
	 * Set up test fixtures before each test execution.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->stored_options = array();

		$this->settings_api = $this->createMock( SettingsApi::class );
		$this->settings_api->method( 'get_option' )
			->willReturnCallback(
				function ( string $key ) {
					return $this->stored_options[ $key ] ?? null;
				}
			);
		$this->settings_api->method( 'update_option' )
			->willReturnCallback(
				function ( string $key, $value ) {
					$this->stored_options[ $key ] = (string) $value;
					return true;
				}
			);
		$this->settings_api->method( 'delete_option' )
			->willReturnCallback(
				function ( string $key ) {
					unset( $this->stored_options[ $key ] );
					return true;
				}
			);
		$lock_callback = function ( string $key, callable $modifier ) {
			$current = $this->stored_options[ $key ] ?? null;
			$updated = $modifier( $current );
			if ( null === $updated ) {
				unset( $this->stored_options[ $key ] );
			} else {
				$this->stored_options[ $key ] = (string) $updated;
			}
			return $updated;
		};
		$this->settings_api->method( 'update_option_with_lock' )
			->willReturnCallback( $lock_callback );

		$this->roles         = new Roles( $this->settings_api );
		$this->authorization = new Authorization( $this->roles );

		$this->admin_user = array(
			'id'       => 'admin_user_01',
			'username' => 'admin',
			'email'    => 'admin@peakurl.org',
			'role'     => 'admin',
		);

		$this->editor_user = array(
			'id'       => 'editor_user_01',
			'username' => 'editor',
			'email'    => 'editor@peakurl.org',
			'role'     => 'editor',
		);

		$this->auth_service = $this->createMock( AuthService::class );

		$this->roles_service    = new RolesService( $this->roles, $this->auth_service );
		$this->roles_controller = new RolesController( $this->roles_service );
	}

	protected function tearDown(): void {
		try {
			if ( ! empty( $this->prefixes_to_clean ) ) {
				$base_config = Configuration::get_current();
				if ( UsersContractsTest::is_test_database_eligible( $base_config ) ) {
					$pdo = ( new Connection( $base_config ) )->get_connection();
					$this->clean_test_tables( $pdo, $this->prefixes_to_clean );
				}
				$this->prefixes_to_clean = array();
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Safely drops test tables matching isolated test prefixes.
	 *
	 * @param PDO                $pdo      Database connection.
	 * @param array<int, string> $prefixes Array of test prefixes to drop.
	 */
	private function clean_test_tables( PDO $pdo, array $prefixes ): void {
		$prev_fkey = $pdo->query( 'SELECT @@FOREIGN_KEY_CHECKS' )->fetchColumn();
		$pdo->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
		try {
			$tables = UsersContractsTest::get_schema_tables();
			foreach ( array_unique( $prefixes ) as $pfx ) {
				if ( ! preg_match( '/^test_rol_[a-f0-9]{8}_$/', $pfx ) ) {
					continue;
				}
				foreach ( $tables as $table ) {
					$pdo->exec( "DROP TABLE IF EXISTS `{$pfx}{$table}`" );
				}
			}
		} finally {
			$pdo->exec( 'SET FOREIGN_KEY_CHECKS = ' . (int) $prev_fkey );
		}
	}

	/**
	 * Set up an isolated database schema with unique table prefix for real database tests.
	 *
	 * @return array{0: Connection, 1: PeakURL_DB, 2: PDO, 3: string}
	 */
	private function setup_isolated_db(): array {
		$base_config = Configuration::get_current();
		if ( ! UsersContractsTest::is_test_database_eligible( $base_config ) ) {
			$this->markTestSkipped( 'Refusing to run isolated DB tests: environment or database name is not an approved test database configuration.' );
		}

		$isolated_prefix           = 'test_rol_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';
		$this->prefixes_to_clean[] = $isolated_prefix;
		$config                    = array_merge(
			$base_config,
			array(
				Constants::DB_PREFIX => $isolated_prefix,
			)
		);

		$connection = new Connection( $config );
		$db         = new PeakURL_DB( $connection );
		$pdo        = $connection->get_connection();

		try {
			$schema_file = dirname( __DIR__, 3 ) . '/database/schema.sql';
			$schema_sql  = (string) file_get_contents( $schema_file );
			$statements  = array_filter( array_map( 'trim', explode( ';', $schema_sql ) ) );
			foreach ( $statements as $stmt ) {
				if ( '' !== $stmt ) {
					$prefixed_stmt = preg_replace(
						'/(CREATE TABLE IF NOT EXISTS\s+)(`?)([a-zA-Z0-9_]+)(`?)/i',
						'${1}`' . $isolated_prefix . '${3}`',
						$stmt
					);
					$prefixed_stmt = preg_replace(
						'/(REFERENCES\s+)(`?)([a-zA-Z0-9_]+)(`?)/i',
						'${1}`' . $isolated_prefix . '${3}`',
						$prefixed_stmt
					);
					$prefixed_stmt = preg_replace(
						'/(CONSTRAINT\s+`?)([a-zA-Z0-9_]+)(`?)/i',
						'${1}' . $isolated_prefix . '${2}${3}',
						$prefixed_stmt
					);
					$pdo->exec( $prefixed_stmt );
				}
			}
		} catch ( \Throwable $e ) {
			$this->clean_test_tables( $pdo, array( $isolated_prefix ) );
			throw $e;
		}

		return array( $connection, $db, $pdo, $isolated_prefix );
	}

	/**
	 * Helper to create an HTTP request with specific method, path, and body.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Request path.
	 * @param array<string, mixed> $body   Request body parameters.
	 * @return Request
	 */
	private function make_request( string $method, string $path, array $body = array() ): Request {
		return new Request(
			$method,
			$path,
			array(),
			$body,
			array( 'CONTENT_TYPE' => 'application/json' ),
			array()
		);
	}

	/**
	 * Verify that default role definitions match shipped specifications without any stored configuration.
	 */
	public function test_default_role_definitions_match_shipped_spec_without_stored_config(): void {
		$definitions = $this->roles->get_role_definitions();

		$this->assertCount( 2, $definitions );

		$roles_by_id = array();
		foreach ( $definitions as $def ) {
			$roles_by_id[ $def['id'] ] = $def;
		}

		$this->assertArrayHasKey( 'admin', $roles_by_id );
		$this->assertArrayHasKey( 'editor', $roles_by_id );

		$admin = $roles_by_id['admin'];
		$this->assertSame( 'Administrator', $admin['name'] );
		$this->assertFalse( $admin['is_customized'] );
		$this->assertNotEmpty( $admin['capabilities'] );

		// Check admin capabilities defaults
		$admin_caps = array_column( $admin['capabilities'], 'enabled', 'id' );
		$this->assertTrue( $admin_caps['manage_users'] );
		$this->assertTrue( $admin_caps['manage_site_settings'] );
		$this->assertTrue( $admin_caps['create_links'] );
		$this->assertTrue( $admin_caps['delete_links'] );

		// Check editor capabilities defaults
		$editor = $roles_by_id['editor'];
		$this->assertSame( 'Editor', $editor['name'] );
		$this->assertFalse( $editor['is_customized'] );

		$editor_caps = array_column( $editor['capabilities'], 'enabled', 'id' );
		$this->assertFalse( $editor_caps['manage_users'] );
		$this->assertFalse( $editor_caps['manage_site_settings'] );
		$this->assertTrue( $editor_caps['create_links'] );
		$this->assertTrue( $editor_caps['view_links'] );
		$this->assertFalse( $editor_caps['delete_links'] );
	}

	/**
	 * Verify that an administrator can update editor capabilities and persist them to settings.
	 */
	public function test_admin_can_update_editor_capabilities_and_persist_to_settings(): void {
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->admin_user );

		$request = $this->make_request(
			'PUT',
			'/api/v1/roles/editor',
			array(
				'capabilities' => array(
					'manage_webhooks' => true,
					'delete_links'    => true,
				),
			)
		);

		$updated = $this->roles_service->update_role(
			$request,
			'editor',
			array(
				'capabilities' => array(
					'manage_webhooks' => true,
					'delete_links'    => true,
				),
			)
		);

		$this->assertSame( 'editor', $updated['id'] );
		$this->assertTrue( $updated['is_customized'] );

		// Check updated capability states
		$caps = array_column( $updated['capabilities'], 'enabled', 'id' );
		$this->assertTrue( $caps['manage_webhooks'] );
		$this->assertTrue( $caps['delete_links'] );
		$this->assertTrue( $caps['create_links'] ); // Preserves defaults

		// Verify persisted JSON in SettingsApi
		$this->assertArrayHasKey( Constants::SETTING_ROLE_CAPABILITIES, $this->stored_options );
		$stored = json_decode( $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ], true );
		$this->assertTrue( $stored['editor']['manage_webhooks'] );
		$this->assertTrue( $stored['editor']['delete_links'] );

		// Verify effective capability check directly on user
		$this->assertTrue( $this->roles->has_capability( $this->editor_user, 'manage_webhooks' ) );
		$this->assertTrue( $this->roles->has_capability( $this->editor_user, 'delete_links' ) );
	}

	/**
	 * Verify that attempting to grant admin-reserved capabilities to an editor is strictly rejected with 422.
	 */
	public function test_admin_reserved_capabilities_cannot_be_assigned_to_editors_throws_422(): void {
		$admin_reserved = array(
			'manage_users',
			'manage_site_settings',
			'manage_mail_delivery',
			'manage_location_data',
			'manage_performance',
			'manage_updates',
		);

		foreach ( $admin_reserved as $reserved_cap ) {
			try {
				$this->roles->update_role_capabilities(
					'editor',
					array(
						'capabilities' => array(
							$reserved_cap => true,
						),
					)
				);
				$this->fail( "Expected 422 ApiException when assigning admin-reserved capability '{$reserved_cap}' to editor" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode() );
				$this->assertStringContainsString( 'reserved for administrators', $e->getMessage() );
			}
		}
	}

	/**
	 * Verify that granting delegable capabilities to an editor does NOT make them an admin
	 * and does NOT bypass administrator-only endpoint guards.
	 */
	public function test_editor_granted_delegable_capabilities_does_not_become_admin_and_cannot_bypass_admin_checks(): void {
		// Customise editor to have delegable capabilities
		$this->roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'manage_webhooks' => true,
					'delete_links'    => true,
				),
			)
		);

		// Editor has the capability flags
		$this->assertTrue( $this->roles->has_capability( $this->editor_user, 'manage_webhooks' ) );
		$this->assertTrue( $this->roles->has_capability( $this->editor_user, 'delete_links' ) );

		// BUT Roles::is_admin MUST strictly remain FALSE
		$this->assertFalse( $this->roles->is_admin( $this->editor_user ) );
		$this->assertFalse( $this->authorization->is_admin( $this->editor_user ) );

		// Require admin throws 403 on editor
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->authorization->require_admin( $this->editor_user );
	}

	/**
	 * Verify that an editor cannot bypass owner-only record access even with high capabilities.
	 */
	public function test_editor_with_capabilities_cannot_bypass_owner_only_record_access(): void {
		$this->roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'edit_links' => true,
				),
			)
		);

		$owner_admin_id = 'admin_user_01';

		// Non-owner record check with $owner_only = true
		$can_access = $this->authorization->can_access_record(
			$this->editor_user,
			$owner_admin_id,
			'edit_links',
			true // owner_only
		);

		// Editor cannot access admin's link when owner_only is required
		$this->assertFalse( $can_access );

		// Conversely, real admin CAN access any record regardless of owner_only
		$admin_can_access = $this->authorization->can_access_record(
			$this->admin_user,
			'other_user_id',
			'edit_links',
			true
		);
		$this->assertTrue( $admin_can_access );
	}

	/**
	 * Verify that attempts to disable protected administrator capabilities are rejected with 422.
	 */
	public function test_cannot_disable_protected_admin_capabilities(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Protected administrator capabilities cannot be disabled.' );

		$this->roles->update_role_capabilities(
			'admin',
			array(
				'capabilities' => array(
					'manage_users' => false,
				),
			)
		);
	}

	/**
	 * Verify that unknown roles are rejected with 422.
	 */
	public function test_unknown_role_is_rejected_with_422(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Invalid role identifier.' );

		$this->roles->update_role_capabilities(
			'superuser',
			array(
				'capabilities' => array(
					'create_links' => true,
				),
			)
		);
	}

	/**
	 * Verify that unknown capability keys are rejected with 422.
	 */
	public function test_unknown_capability_is_rejected_with_422(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Unknown capability: arbitrary_hack' );

		$this->roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'arbitrary_hack' => true,
				),
			)
		);
	}

	/**
	 * Verify that non-boolean capability values are rejected with 422.
	 */
	public function test_non_boolean_capability_value_is_rejected_with_422(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Capability value for create_links must be a boolean.' );

		$this->roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'create_links' => 'yes',
				),
			)
		);
	}

	/**
	 * Verify that resetting a role to defaults restores shipped settings without affecting other roles.
	 */
	public function test_reset_role_capabilities_restores_defaults_without_affecting_other_roles(): void {
		// Customize editor and admin
		$this->roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);

		$this->roles->update_role_capabilities(
			'admin',
			array(
				'capabilities' => array(
					'manage_mail_delivery' => false, // Non-protected capability can be toggled
				),
			)
		);

		$editor_caps = $this->roles->capabilities_for_role( 'editor' );
		$this->assertTrue( $editor_caps['delete_links'] );

		$admin_caps = $this->roles->capabilities_for_role( 'admin' );
		$this->assertFalse( $admin_caps['manage_mail_delivery'] );

		// Reset only the editor role
		$reset_def = $this->roles->reset_role_capabilities( 'editor' );
		$this->assertFalse( $reset_def['is_customized'] );

		$editor_caps_after = $this->roles->capabilities_for_role( 'editor' );
		$this->assertFalse( $editor_caps_after['delete_links'] ); // Restored to default false

		// Admin customization remains intact
		$admin_caps_after = $this->roles->capabilities_for_role( 'admin' );
		$this->assertFalse( $admin_caps_after['manage_mail_delivery'] );
	}

	/**
	 * Verify that non-admin users receive 403 on all roles endpoints.
	 */
	public function test_non_admin_user_is_denied_access_to_roles_service_with_403(): void {
		$this->auth_service->method( 'get_admin_user' )
			->willThrowException( new ApiException( 'Administrator access required.', 403 ) );

		$request = $this->make_request( 'GET', '/api/v1/roles' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->roles_service->get_roles( $request );
	}

	/**
	 * Verify that controller endpoints return standard success JSON envelopes.
	 */
	public function test_controller_returns_standard_success_json_envelopes(): void {
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->admin_user );

		$index_request  = $this->make_request( 'GET', '/api/v1/roles' );
		$index_response = $this->roles_controller->index( $index_request );

		$this->assertSame( 200, $index_response['status'] );
		$this->assertTrue( $index_response['body']['success'] );
		$this->assertSame( 'Roles loaded.', $index_response['body']['message'] );
		$this->assertIsArray( $index_response['body']['data'] );
		$this->assertCount( 2, $index_response['body']['data'] );

		$update_request = $this->make_request(
			'PUT',
			'/api/v1/roles/editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);
		$update_request->set_route_params( array( 'role' => 'editor' ) );
		$update_response = $this->roles_controller->update( $update_request );

		$this->assertSame( 200, $update_response['status'] );
		$this->assertTrue( $update_response['body']['success'] );
		$this->assertSame( 'Role capabilities updated.', $update_response['body']['message'] );
		$this->assertSame( 'editor', $update_response['body']['data']['id'] );

		$reset_request = $this->make_request( 'POST', '/api/v1/roles/editor/reset' );
		$reset_request->set_route_params( array( 'role' => 'editor' ) );
		$reset_response = $this->roles_controller->reset( $reset_request );

		$this->assertSame( 200, $reset_response['status'] );
		$this->assertTrue( $reset_response['body']['success'] );
		$this->assertSame( 'Role capabilities reset to defaults.', $reset_response['body']['message'] );
		$this->assertFalse( $reset_response['body']['data']['is_customized'] );
	}

	/**
	 * Verify that partial delta updates to editor merge with defaults and preserve other capabilities.
	 */
	public function test_partial_delta_editor_update_merges_with_defaults(): void {
		$definition = $this->roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);

		$this->assertTrue( $definition['is_customized'] );
		$caps = $this->roles->capabilities_for_role( 'editor' );

		// Changed capability is updated
		$this->assertTrue( $caps['delete_links'] );
		// Unchanged shipped editor default remains true
		$this->assertTrue( $caps['create_links'] );
		$this->assertTrue( $caps['view_links'] );
		// Admin-reserved capability remains false for editor
		$this->assertFalse( $caps['manage_users'] );
	}

	/**
	 * Verify that forged admin-reserved capability key with false is rejected for editor with 422.
	 */
	public function test_forged_admin_reserved_capability_key_with_false_is_rejected_for_editor(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Capability "manage_users" is reserved for administrators and cannot be assigned to editors.' );

		$this->roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'manage_users' => false,
				),
			)
		);
	}

	/**
	 * Verify that missing settings storage fails explicitly with 500.
	 */
	public function test_missing_settings_storage_fails_explicitly_with_500(): void {
		$roles_without_settings = new Roles( null );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Settings storage is unavailable.' );

		$roles_without_settings->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);
	}

	/**
	 * Verify that corrupted or malformed stored JSON fails explicitly with 500 without wiping data.
	 */
	public function test_corrupted_stored_json_fails_explicitly_without_wiping_data(): void {
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = '{invalid: malformed json';

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Invalid or corrupted role capabilities configuration in storage.' );

		$this->roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);
	}

	/**
	 * Verify that valid stored customizations resolve accurately, while prohibited or
	 * inapplicable capability overrides in stored JSON fail strictly with 500 without wiping data.
	 */
	public function test_valid_stored_customization_resolves_accurately_and_invalid_overrides_throw_500(): void {
		// 1. Valid editor override: delete_links = true
		$valid_stored = array(
			'editor' => array(
				'delete_links' => true,
			),
		);
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = (string) json_encode( $valid_stored, JSON_THROW_ON_ERROR );

		$caps = $this->roles->capabilities_for_role( 'editor' );
		$this->assertTrue( $caps['delete_links'], 'Valid override delete_links must be true' );
		$this->assertFalse( $caps['manage_users'], 'Editor must never have manage_users' );
		$this->assertFalse( $caps['empty_trash'], 'Editor must never have empty_trash' );

		$this->assertTrue( $this->roles->has_capability( $this->editor_user, 'delete_links' ) );
		$this->assertFalse( $this->roles->has_capability( $this->editor_user, 'manage_users' ) );
		$this->assertFalse( $this->roles->has_capability( $this->editor_user, 'empty_trash' ) );

		// 2. Stored JSON with prohibited admin-only capability for editor throws 500 and preserves raw bytes
		$prohibited_stored = '{"editor":{"manage_users":true}}';
		$this->roles->clear_cache();
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = $prohibited_stored;

		try {
			$this->roles->capabilities_for_role( 'editor' );
			$this->fail( 'Expected 500 ApiException on capabilities_for_role with prohibited editor override' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}

		$this->assertSame( $prohibited_stored, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );
	}

	public function test_corrupt_role_json_fails_read_apis_with_500_without_wiping_data(): void {
		$corrupt_json = '{invalid_json_format';
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = $corrupt_json;

		// 1. get_stored_role_capabilities throws 500
		try {
			$this->roles->get_stored_role_capabilities();
			$this->fail( 'Expected 500 ApiException on get_stored_role_capabilities with corrupt JSON' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
			$this->assertStringContainsString( 'Invalid or corrupted role capabilities', $e->getMessage() );
		}

		// 2. capabilities_for_role throws 500
		try {
			$this->roles->capabilities_for_role( 'editor' );
			$this->fail( 'Expected 500 ApiException on capabilities_for_role with corrupt JSON' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}

		// 3. get_role_definitions throws 500
		try {
			$this->roles->get_role_definitions();
			$this->fail( 'Expected 500 ApiException on get_role_definitions with corrupt JSON' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}

		// 4. RolesService get_roles throws 500
		$this->auth_service->method( 'get_admin_user' )->willReturn( $this->admin_user );
		$req = $this->make_request( 'GET', '/api/v1/roles' );
		try {
			$this->roles_service->get_roles( $req );
			$this->fail( 'Expected 500 ApiException on get_roles with corrupt JSON' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}

		// 5. Verify the corrupted stored JSON was NOT wiped or replaced
		$this->assertSame( $corrupt_json, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );
	}

	public function test_corrupt_role_json_non_associative_and_invalid_types_throw_500(): void {
		$invalid_samples = array(
			'', // empty string (existing row with empty string is corrupt)
			"   \t\n  ", // whitespace string
			'[]', // empty list array
			'["admin", "editor"]', // list array
			'{"editor": []}', // empty list for role
			'{"editor": [true, false]}', // list of booleans for role
			'{"editor": null}', // null for role
			'{"editor": true}', // boolean for role
			'{"editor": 12345}', // number for role
			'{"editor": "string_not_array"}', // non-array role caps
			'{"unknown_role": {}}', // unknown role key
			'{"editor": {"nonexistent_cap": true}}', // unknown capability ID
			'{"editor": {"delete_links": "yes"}}', // non-boolean string
			'{"editor": {"delete_links": 1}}', // non-boolean number
			'{"editor": {"delete_links": null}}', // JSON null capability value
			'{"editor": {"manage_users": true}}', // prohibited Editor admin-only override
			'{"editor": {"empty_trash": true}}', // prohibited Editor admin-only override
			'{"admin": {"manage_roles": false}}', // protected administrator override disabled
			'{"admin": {"empty_trash": false}}', // protected administrator override disabled
			'null', // stored string 'null'
			'true', // scalar boolean
			'"just a string"', // scalar string
			'12345', // scalar number
			'{"editor":', // malformed syntax
		);

		foreach ( $invalid_samples as $sample ) {
			$this->roles->clear_cache();
			$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = $sample;

			try {
				$this->roles->get_stored_role_capabilities();
				$this->fail( "Expected 500 ApiException for sample: {$sample}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}

			// Must not wipe or overwrite data
			$this->assertSame( $sample, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );
		}
	}

	public function test_corrupt_stored_json_prevents_mutation_and_reset_preserving_original_bytes(): void {
		$corrupt_samples = array(
			'',
			"   \n  ",
			'["admin", "editor"]',
			'[]',
			'{"editor": [true]}',
			'{"editor": null}',
			'{"editor": {"delete_links": "yes"}}',
			'{"editor": {"manage_users": true}}',
			'{"admin": {"manage_roles": false}}',
			'{"editor":',
		);

		foreach ( $corrupt_samples as $sample ) {
			$this->roles->clear_cache();
			$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = $sample;

			// Update attempt must fail with 500 before writing
			try {
				$this->roles->update_role_capabilities(
					'editor',
					array( 'capabilities' => array( 'delete_links' => true ) )
				);
				$this->fail( "Expected update_role_capabilities to fail with 500 for sample: {$sample}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}

			// Original bytes untouched
			$this->assertSame( $sample, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );

			// Reset attempt must fail with 500 before writing
			try {
				$this->roles->reset_role_capabilities( 'editor' );
				$this->fail( "Expected reset_role_capabilities to fail with 500 for sample: {$sample}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}

			// Original bytes untouched
			$this->assertSame( $sample, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );
		}
	}

	public function test_parse_stored_role_capabilities_direct_contracts(): void {
		// Missing storage returns null (no overrides)
		$this->assertNull( $this->roles->parse_stored_role_capabilities( null ) );

		// Stored empty or whitespace strings must throw 500
		foreach ( array( '', ' ', "\t\n " ) as $empty_raw ) {
			try {
				$this->roles->parse_stored_role_capabilities( $empty_raw );
				$this->fail( "Expected 500 on empty raw storage: '{$empty_raw}'" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}
		}

		// Valid empty JSON object returns empty array
		$empty_obj = $this->roles->parse_stored_role_capabilities( '{}' );
		$this->assertSame( array(), $empty_obj );

		// Valid empty role object returns array with empty role map
		$empty_role = $this->roles->parse_stored_role_capabilities( '{"editor":{}}' );
		$this->assertSame( array( 'editor' => array() ), $empty_role );

		// Valid overrides return expected structure
		$valid_raw = json_encode(
			array(
				'editor' => array( 'delete_links' => true ),
				'admin'  => array( 'manage_mail_delivery' => false ),
			)
		);
		$parsed    = $this->roles->parse_stored_role_capabilities( (string) $valid_raw );
		$this->assertIsArray( $parsed );
		$this->assertTrue( $parsed['editor']['delete_links'] );
		$this->assertFalse( $parsed['admin']['manage_mail_delivery'] );
	}

	public function test_empty_trash_capability_is_strictly_admin_only_and_protected(): void {
		// 1. Catalog definition checks
		$catalog = Roles::CAPABILITIES_CATALOG;
		$this->assertArrayHasKey( 'empty_trash', $catalog );
		$this->assertSame( array( 'admin' ), $catalog['empty_trash']['applicable_roles'] );
		$this->assertArrayHasKey( 'admin', $catalog['empty_trash']['protected_roles'] );

		// 2. Role definitions endpoint contract
		$defs       = $this->roles->get_role_definitions();
		$editor_def = null;
		$admin_def  = null;
		foreach ( $defs as $def ) {
			if ( 'editor' === $def['id'] ) {
				$editor_def = $def;
			}
			if ( 'admin' === $def['id'] ) {
				$admin_def = $def;
			}
		}

		$this->assertNotNull( $editor_def );
		$this->assertNotNull( $admin_def );

		$editor_trash_cap = null;
		foreach ( $editor_def['capabilities'] as $cap ) {
			if ( 'empty_trash' === $cap['id'] ) {
				$editor_trash_cap = $cap;
			}
		}
		$this->assertNotNull( $editor_trash_cap );
		$this->assertFalse( $editor_trash_cap['applicable'] );
		$this->assertFalse( $editor_trash_cap['enabled'] );
		$this->assertFalse( $editor_trash_cap['protected'] );
		$this->assertNotNull( $editor_trash_cap['applicable_reason'] );

		$admin_trash_cap = null;
		foreach ( $admin_def['capabilities'] as $cap ) {
			if ( 'empty_trash' === $cap['id'] ) {
				$admin_trash_cap = $cap;
			}
		}
		$this->assertNotNull( $admin_trash_cap );
		$this->assertTrue( $admin_trash_cap['applicable'] );
		$this->assertTrue( $admin_trash_cap['enabled'] );
		$this->assertTrue( $admin_trash_cap['protected'] );

		// 3. Updating editor with empty_trash must throw 422
		try {
			$this->roles->update_role_capabilities(
				'editor',
				array( 'capabilities' => array( 'empty_trash' => true ) )
			);
			$this->fail( 'Expected 422 when attempting to assign empty_trash to editor' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertStringContainsString( 'reserved for administrators', $e->getMessage() );
		}

		try {
			$this->roles->update_role_capabilities(
				'editor',
				array( 'capabilities' => array( 'empty_trash' => false ) )
			);
			$this->fail( 'Expected 422 when attempting to assign empty_trash to editor even as false' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertStringContainsString( 'reserved for administrators', $e->getMessage() );
		}

		// 4. Updating admin with empty_trash => false must throw 422 (protected)
		try {
			$this->roles->update_role_capabilities(
				'admin',
				array( 'capabilities' => array( 'empty_trash' => false ) )
			);
			$this->fail( 'Expected 422 when attempting to disable protected empty_trash on admin' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertStringContainsString( 'Protected administrator capabilities cannot be disabled', $e->getMessage() );
		}

		// 5. Stored JSON with empty_trash on editor must throw 500 and preserve raw bytes
		$prohibited_stored = json_encode(
			array( 'editor' => array( 'empty_trash' => true ) )
		);
		$this->roles->clear_cache();
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = $prohibited_stored;
		try {
			$this->roles->capabilities_for_role( 'editor' );
			$this->fail( 'Expected 500 ApiException on capabilities_for_role with prohibited empty_trash override' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}
		$this->assertSame( $prohibited_stored, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );
	}

	public function test_absent_setting_storage_returns_shipped_defaults_cleanly(): void {
		unset( $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );
		$this->roles->clear_cache();

		$stored = $this->roles->get_stored_role_capabilities();
		$this->assertSame( array(), $stored );

		$editor_caps = $this->roles->capabilities_for_role( 'editor' );
		$this->assertTrue( $editor_caps['create_links'] );
		$this->assertFalse( $editor_caps['delete_links'] );

		$admin_caps = $this->roles->capabilities_for_role( 'admin' );
		$this->assertTrue( $admin_caps['manage_users'] );
	}

	public function test_sequential_real_db_first_write_role_capabilities_atomically_inserts_and_locks(): void {
		list( $connection, $db, $pdo, $prefix ) = $this->setup_isolated_db();

		$settings_api = new SettingsApi( $db );
		$roles        = new Roles( $settings_api );

		// Ensure row does not exist
		$existing = $settings_api->get_option( Constants::SETTING_ROLE_CAPABILITIES );
		$this->assertNull( $existing );

		// Update editor role capabilities on real DB
		$updated = $roles->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);

		$this->assertTrue( $updated['is_customized'] );

		// Row now exists in database with valid JSON
		$stored_raw = $settings_api->get_option( Constants::SETTING_ROLE_CAPABILITIES );
		$this->assertNotNull( $stored_raw );
		$decoded = json_decode( (string) $stored_raw, true );
		$this->assertTrue( $decoded['editor']['delete_links'] );
	}

	public function test_sequential_real_db_updates_different_roles_preserves_both(): void {
		list( $connection1, $db1, $pdo1, $prefix ) = $this->setup_isolated_db();

		$base_config  = Configuration::get_current();
		$conn2_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$connection2  = new Connection( $conn2_config );
		$db2          = new PeakURL_DB( $connection2 );

		$settings_api1 = new SettingsApi( $db1 );
		$settings_api2 = new SettingsApi( $db2 );

		$roles1 = new Roles( $settings_api1 );
		$roles2 = new Roles( $settings_api2 );

		// Connection 1 customizes Editor
		$roles1->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);

		// Connection 2 customizes Admin
		$roles2->update_role_capabilities(
			'admin',
			array(
				'capabilities' => array(
					'manage_mail_delivery' => false,
				),
			)
		);

		// Assert that BOTH customizations survived in the real database
		$roles1->clear_cache();
		$stored = $roles1->get_stored_role_capabilities();

		$this->assertArrayHasKey( 'editor', $stored );
		$this->assertArrayHasKey( 'admin', $stored );
		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertFalse( $stored['admin']['manage_mail_delivery'] );
	}

	public function test_sequential_real_db_updates_different_capabilities_same_role_preserves_both(): void {
		list( $connection1, $db1, $pdo1, $prefix ) = $this->setup_isolated_db();

		$base_config  = Configuration::get_current();
		$conn2_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$connection2  = new Connection( $conn2_config );
		$db2          = new PeakURL_DB( $connection2 );

		$settings_api1 = new SettingsApi( $db1 );
		$settings_api2 = new SettingsApi( $db2 );

		$roles1 = new Roles( $settings_api1 );
		$roles2 = new Roles( $settings_api2 );

		// Connection 1 enables delete_links
		$roles1->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);

		// Connection 2 enables manage_webhooks
		$roles2->update_role_capabilities(
			'editor',
			array(
				'capabilities' => array(
					'manage_webhooks' => true,
				),
			)
		);

		// Assert that BOTH capabilities survived on editor
		$roles1->clear_cache();
		$stored = $roles1->get_stored_role_capabilities();

		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertTrue( $stored['editor']['manage_webhooks'] );
	}

	public function test_sequential_real_db_reset_racing_with_update_serializes_cleanly(): void {
		list( $connection1, $db1, $pdo1, $prefix ) = $this->setup_isolated_db();

		$base_config  = Configuration::get_current();
		$conn2_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$connection2  = new Connection( $conn2_config );
		$db2          = new PeakURL_DB( $connection2 );

		$settings_api1 = new SettingsApi( $db1 );
		$settings_api2 = new SettingsApi( $db2 );

		$roles1 = new Roles( $settings_api1 );
		$roles2 = new Roles( $settings_api2 );

		// Initial state: customize both editor and admin
		$roles1->update_role_capabilities(
			'editor',
			array( 'capabilities' => array( 'delete_links' => true ) )
		);
		$roles1->update_role_capabilities(
			'admin',
			array( 'capabilities' => array( 'manage_mail_delivery' => false ) )
		);

		// Connection 1 resets editor
		$roles1->reset_role_capabilities( 'editor' );

		// Connection 2 updates admin manage_performance
		$roles2->update_role_capabilities(
			'admin',
			array( 'capabilities' => array( 'manage_performance' => false ) )
		);

		// Final check: editor is reset to defaults, admin has both customizations
		$roles1->clear_cache();
		$stored = $roles1->get_stored_role_capabilities();

		$this->assertArrayNotHasKey( 'editor', $stored );
		$this->assertArrayHasKey( 'admin', $stored );
		$this->assertFalse( $stored['admin']['manage_mail_delivery'] );
		$this->assertFalse( $stored['admin']['manage_performance'] );
	}

	public function test_real_db_concurrent_first_write_competing_insert_preserves_both(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				// Process A begins transaction and acquires exclusive lock on the newly inserted setting row
				$pdo->beginTransaction();
				$stmt = $pdo->prepare(
					"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
					'VALUES (:key, NULL, 1, NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()'
				);
				$stmt->execute( array( 'key' => Constants::SETTING_ROLE_CAPABILITIES ) );

				$lock_stmt = $pdo->prepare(
					"SELECT setting_value FROM `{$prefix}settings` WHERE setting_key = :key FOR UPDATE"
				);
				$lock_stmt->execute( array( 'key' => Constants::SETTING_ROLE_CAPABILITIES ) );
				$lock_stmt->fetch();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				// Wait for Process B to signal it is actively attempting the production service mutation
				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				// Harness verifies that Process B is genuinely blocked in MySQL InnoDB on the expected sessions and fixture table
				$contention_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}settings" );

				// Process A completes its editor customization while holding the lock
				$update_stmt = $pdo->prepare(
					"UPDATE `{$prefix}settings` SET setting_value = :val, updated_at = NOW() WHERE setting_key = :key"
				);
				$update_stmt->execute(
					array(
						'val' => json_encode( array( 'editor' => array( 'delete_links' => true ) ) ),
						'key' => Constants::SETTING_ROLE_CAPABILITIES,
					)
				);
				$pdo->commit();

				return array(
					'contention_observed' => $contention_observed,
				);
			},
			function ( $sock ) use ( $conn_config ) {
				$conn  = new Connection( $conn_config );
				$db    = new PeakURL_DB( $conn );
				$roles = new Roles( new SettingsApi( $db ) );

				// Wait until Process A has acquired exclusive row lock
				fgets( $sock );
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();

				// Signal ATTEMPTING with session ID immediately before invoking the production service method
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				// Invokes the real production API/service method; will block until Process A commits
				return $roles->update_role_capabilities(
					'admin',
					array( 'capabilities' => array( 'manage_mail_delivery' => false ) )
				);
			}
		);

		$this->assertSame( 'ok', $parent_res['status'], 'Parent update must succeed: ' . ( $parent_res['message'] ?? '' ) );
		$this->assertSame( 'ok', $child_res['status'], 'Child update must succeed: ' . ( $child_res['message'] ?? '' ) );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified in information_schema.innodb_trx.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ) );
		$stored      = $roles->get_stored_role_capabilities();

		$this->assertArrayHasKey( 'editor', $stored );
		$this->assertArrayHasKey( 'admin', $stored );
		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertFalse( $stored['admin']['manage_mail_delivery'] );
	}

	public function test_real_db_concurrent_updates_different_roles_overlapping_transactions(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '{\"editor\":{\"create_links\":true}}', 1, NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				$pdo->beginTransaction();
				$lock_stmt = $pdo->prepare(
					"SELECT setting_value FROM `{$prefix}settings` WHERE setting_key = :key FOR UPDATE"
				);
				$lock_stmt->execute( array( 'key' => Constants::SETTING_ROLE_CAPABILITIES ) );
				$lock_stmt->fetch();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				$contention_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}settings" );

				$update_stmt = $pdo->prepare(
					"UPDATE `{$prefix}settings` SET setting_value = :val, updated_at = NOW() WHERE setting_key = :key"
				);
				$update_stmt->execute(
					array(
						'val' => json_encode( array( 'editor' => array( 'delete_links' => true ) ) ),
						'key' => Constants::SETTING_ROLE_CAPABILITIES,
					)
				);
				$pdo->commit();

				return array( 'contention_observed' => $contention_observed );
			},
			function ( $sock ) use ( $conn_config ) {
				$conn  = new Connection( $conn_config );
				$db    = new PeakURL_DB( $conn );
				$roles = new Roles( new SettingsApi( $db ) );

				fgets( $sock ); // wait for LOCK_ACQUIRED
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				return $roles->update_role_capabilities(
					'admin',
					array( 'capabilities' => array( 'manage_performance' => false ) )
				);
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ) );
		$stored      = $roles->get_stored_role_capabilities();

		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertFalse( $stored['admin']['manage_performance'] );
	}

	public function test_real_db_concurrent_updates_different_capabilities_same_role_overlapping_transactions(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '{\"editor\":{\"create_links\":true}}', 1, NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				$pdo->beginTransaction();
				$lock_stmt = $pdo->prepare(
					"SELECT setting_value FROM `{$prefix}settings` WHERE setting_key = :key FOR UPDATE"
				);
				$lock_stmt->execute( array( 'key' => Constants::SETTING_ROLE_CAPABILITIES ) );
				$lock_stmt->fetch();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				$contention_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}settings" );

				$update_stmt = $pdo->prepare(
					"UPDATE `{$prefix}settings` SET setting_value = :val, updated_at = NOW() WHERE setting_key = :key"
				);
				$update_stmt->execute(
					array(
						'val' => json_encode( array( 'editor' => array( 'delete_links' => true ) ) ),
						'key' => Constants::SETTING_ROLE_CAPABILITIES,
					)
				);
				$pdo->commit();

				return array( 'contention_observed' => $contention_observed );
			},
			function ( $sock ) use ( $conn_config ) {
				$conn  = new Connection( $conn_config );
				$db    = new PeakURL_DB( $conn );
				$roles = new Roles( new SettingsApi( $db ) );

				fgets( $sock );
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				return $roles->update_role_capabilities(
					'editor',
					array( 'capabilities' => array( 'manage_webhooks' => true ) )
				);
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ) );
		$stored      = $roles->get_stored_role_capabilities();

		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertTrue( $stored['editor']['manage_webhooks'] );
	}

	public function test_real_db_concurrent_reset_colliding_with_update_overlapping_transactions(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_settings = new SettingsApi( $setup_db );
		$setup_roles    = new Roles( $setup_settings );
		$setup_roles->update_role_capabilities( 'editor', array( 'capabilities' => array( 'delete_links' => true ) ) );
		$setup_roles->update_role_capabilities( 'admin', array( 'capabilities' => array( 'manage_mail_delivery' => false ) ) );
		unset( $setup_roles, $setup_settings, $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				$pdo->beginTransaction();
				$lock_stmt = $pdo->prepare(
					"SELECT setting_value FROM `{$prefix}settings` WHERE setting_key = :key FOR UPDATE"
				);
				$lock_stmt->execute( array( 'key' => Constants::SETTING_ROLE_CAPABILITIES ) );
				$row = $lock_stmt->fetch();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				$contention_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}settings" );

				// Reset editor: decode existing, unset editor, update
				$current = json_decode( (string) ( $row['setting_value'] ?? '{}' ), true );
				unset( $current['editor'] );
				$update_stmt = $pdo->prepare(
					"UPDATE `{$prefix}settings` SET setting_value = :val, updated_at = NOW() WHERE setting_key = :key"
				);
				$update_stmt->execute(
					array(
						'val' => json_encode( $current ),
						'key' => Constants::SETTING_ROLE_CAPABILITIES,
					)
				);
				$pdo->commit();

				return array( 'contention_observed' => $contention_observed );
			},
			function ( $sock ) use ( $conn_config ) {
				$conn  = new Connection( $conn_config );
				$db    = new PeakURL_DB( $conn );
				$roles = new Roles( new SettingsApi( $db ) );

				fgets( $sock );
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				return $roles->update_role_capabilities(
					'admin',
					array( 'capabilities' => array( 'manage_location_data' => false ) )
				);
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ) );
		$stored      = $roles->get_stored_role_capabilities();

		$this->assertArrayNotHasKey( 'editor', $stored );
		$this->assertArrayHasKey( 'admin', $stored );
		$this->assertFalse( $stored['admin']['manage_mail_delivery'] );
		$this->assertFalse( $stored['admin']['manage_location_data'] );
	}

	public function test_observe_innodb_lock_wait_identifies_matching_session_and_rejects_unrelated_connection(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('test_diagnostic_key', 'initial', 1, NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				$pdo->beginTransaction();
				$lock_stmt = $pdo->prepare(
					"SELECT setting_value FROM `{$prefix}settings` WHERE setting_key = 'test_diagnostic_key' FOR UPDATE"
				);
				$lock_stmt->execute();
				$lock_stmt->fetch();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				$line           = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $line, 2 );
				}

				// Negative check 1: fake/unrelated waiting connection ID must return false
				$fake_waiter_observed = $this->observe_innodb_lock_wait( $pdo, 999999, $blocker_conn_id, "{$prefix}settings", 200 );

				// Negative check 2: fake/unrelated blocking connection ID must return false
				$fake_blocker_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, 999999, "{$prefix}settings", 200 );

				// Negative check 3: wrong fixture table must return false
				$wrong_table_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}nonexistent_table", 200 );

				// Positive check: actual waiting connection ID, blocking connection ID, and fixture table must be observed
				$actual_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}settings", 2000 );

				$pdo->rollBack();

				return array(
					'fake_waiter_observed'  => $fake_waiter_observed,
					'fake_blocker_observed' => $fake_blocker_observed,
					'wrong_table_observed'  => $wrong_table_observed,
					'actual_observed'       => $actual_observed,
				);
			},
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				fgets( $sock );
				$waiter_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				// Attempt update on the locked row; blocks until parent releases
				try {
					$pdo->beginTransaction();
					$update_stmt = $pdo->prepare(
						"UPDATE `{$prefix}settings` SET setting_value = 'updated' WHERE setting_key = 'test_diagnostic_key'"
					);
					$update_stmt->execute();
					$pdo->rollBack();
					return array( 'success' => true );
				} catch ( \Throwable $e ) {
					return array(
						'success' => false,
						'error'   => $e->getMessage(),
					);
				}
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertFalse( $parent_res['result']['fake_waiter_observed'], 'Observer must NOT match an unrelated waiting connection ID.' );
		$this->assertFalse( $parent_res['result']['fake_blocker_observed'], 'Observer must NOT match an unrelated blocking connection ID.' );
		$this->assertFalse( $parent_res['result']['wrong_table_observed'], 'Observer must NOT match a different fixture table.' );
		$this->assertTrue( $parent_res['result']['actual_observed'], 'Observer MUST confirm contention on the identified sessions and fixture table.' );
	}
}
