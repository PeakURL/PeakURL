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
use PeakURL\Api\UsersApi;
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
	 * Users API mock instance.
	 *
	 * @var UsersApi
	 */
	private UsersApi $users_api;

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

		$this->users_api     = $this->createMock( UsersApi::class );
		$this->roles         = new Roles( $this->settings_api, $this->users_api );
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

		$this->roles_service    = new RolesService( $this->roles, $this->auth_service, $this->users_api );
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
		$this->assertTrue( $admin['is_builtin'] );
		$this->assertFalse( $admin['is_configurable'] );
		$this->assertFalse( $admin['is_customized'] );
		$this->assertSame( array(), $admin['capabilities'] );

		// Check admin capabilities defaults via capabilities_for_role
		$admin_caps = $this->roles->capabilities_for_role( 'admin' );
		$this->assertTrue( $admin_caps['manage_users'] );
		$this->assertTrue( $admin_caps['manage_site_settings'] );
		$this->assertTrue( $admin_caps['create_links'] );
		$this->assertTrue( $admin_caps['delete_links'] );

		// Check editor capabilities defaults
		$editor = $roles_by_id['editor'];
		$this->assertSame( 'Editor', $editor['name'] );
		$this->assertTrue( $editor['is_builtin'] );
		$this->assertTrue( $editor['is_configurable'] );
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
					'manage_integrations' => true,
					'delete_links'        => true,
				),
			)
		);

		$updated = $this->roles_service->update_role(
			$request,
			'editor',
			array(
				'capabilities' => array(
					'manage_integrations' => true,
					'delete_links'        => true,
				),
			)
		);

		$this->assertSame( 'editor', $updated['id'] );
		$this->assertTrue( $updated['is_customized'] );

		// Check updated capability states
		$caps = array_column( $updated['capabilities'], 'enabled', 'id' );
		$this->assertTrue( $caps['manage_integrations'] );
		$this->assertTrue( $caps['delete_links'] );
		$this->assertTrue( $caps['create_links'] ); // Preserves defaults

		// Verify persisted JSON in SettingsApi
		$this->assertArrayHasKey( Constants::SETTING_ROLE_CAPABILITIES, $this->stored_options );
		$stored = json_decode( $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ], true );
		$this->assertTrue( $stored['editor']['manage_integrations'] );
		$this->assertTrue( $stored['editor']['delete_links'] );

		// Verify effective capability check directly on user
		$this->assertTrue( $this->roles->has_capability( $this->editor_user, 'manage_integrations' ) );
		$this->assertTrue( $this->roles->has_capability( $this->editor_user, 'delete_links' ) );
	}

	/**
	 * Verify that attempting to grant admin-reserved capabilities to an editor is strictly rejected with 422.
	 */
	public function test_admin_reserved_capabilities_cannot_be_assigned_to_editors_throws_422(): void {
		$admin_reserved = array(
			'manage_users',
			'empty_trash',
		);

		foreach ( $admin_reserved as $reserved_cap ) {
			try {
				$this->roles->update_role_definition(
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
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'manage_integrations' => true,
					'delete_links'        => true,
				),
			)
		);

		// Editor has the capability flags
		$this->assertTrue( $this->roles->has_capability( $this->editor_user, 'manage_integrations' ) );
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
		$this->roles->update_role_definition(
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
	 * Verify that administrator capabilities are immutable and attempts to update or reset them are rejected with 403.
	 */
	public function test_admin_capabilities_are_immutable_and_cannot_be_modified_or_reset(): void {
		try {
			$this->roles->update_role_definition(
				'admin',
				array(
					'capabilities' => array(
						'manage_users' => false,
					),
				)
			);
			$this->fail( 'Expected 403 ApiException when attempting to update admin capabilities' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertStringContainsString( 'Administrator capabilities are immutable', $e->getMessage() );
		}

		try {
			$this->roles->reset_role_capabilities( 'admin' );
			$this->fail( 'Expected 403 ApiException when attempting to reset admin capabilities' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertStringContainsString( 'Administrator capabilities are immutable', $e->getMessage() );
		}
	}

	/**
	 * Verify that unknown roles are rejected with 422.
	 */
	public function test_unknown_role_is_rejected_with_422(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Invalid role identifier.' );

		$this->roles->update_role_definition(
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

		$this->roles->update_role_definition(
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

		$this->roles->update_role_definition(
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
		// Customize editor
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);

		// Create and customize a custom role
		$this->roles->create_custom_role( 'analyst', 'Analyst', 'Data analyst' );

		$this->roles->update_role_definition(
			'analyst',
			array(
				'capabilities' => array(
					'manage_performance' => true,
				),
			)
		);

		$editor_caps = $this->roles->capabilities_for_role( 'editor' );
		$this->assertTrue( $editor_caps['delete_links'] );

		$analyst_caps = $this->roles->capabilities_for_role( 'analyst' );
		$this->assertTrue( $analyst_caps['manage_performance'] );

		// Reset only the editor role
		$reset_def = $this->roles->reset_role_capabilities( 'editor' );
		$this->assertFalse( $reset_def['is_customized'] );

		$editor_caps_after = $this->roles->capabilities_for_role( 'editor' );
		$this->assertFalse( $editor_caps_after['delete_links'] ); // Restored to default false

		// Analyst customization remains intact
		$analyst_caps_after = $this->roles->capabilities_for_role( 'analyst' );
		$this->assertTrue( $analyst_caps_after['manage_performance'] );
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
		$definition = $this->roles->update_role_definition(
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

		$this->roles->update_role_definition(
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

		$roles_without_settings->update_role_definition(
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

		$this->roles->update_role_definition(
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

		// 1. get_role_settings throws 500
		try {
			$this->roles->get_role_settings();
			$this->fail( 'Expected 500 ApiException on get_role_settings with corrupt JSON' );
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
				$this->roles->get_role_settings();
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
				$this->roles->update_role_definition(
					'editor',
					array( 'capabilities' => array( 'delete_links' => true ) )
				);
				$this->fail( "Expected update_role_definition to fail with 500 for sample: {$sample}" );
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

	public function test_corrupt_role_json_rejects_json_arrays_for_capabilities_and_initial_capabilities(): void {
		$array_samples = array(
			'{"custom_roles": {"analyst": {"name": "Analyst", "initial_capabilities": [], "capabilities": {}}}}',
			'{"custom_roles": {"analyst": {"name": "Analyst", "initial_capabilities": {}, "capabilities": []}}}',
			'{"custom_roles": {"analyst": {"name": "Analyst", "initial_capabilities": [true], "capabilities": {}}}}',
			'{"custom_roles": {"analyst": {"name": "Analyst", "initial_capabilities": {}, "capabilities": [true]}}}',
			'{"custom_roles": {"analyst": {"name": "Analyst", "initial_capabilities": null, "capabilities": {}}}}',
			'{"custom_roles": {"analyst": {"name": "Analyst", "initial_capabilities": {}, "capabilities": null}}}',
			'{"custom_roles": []}',
			'{"custom_roles": {"analyst": []}}',
			'{"editor": []}',
			'{"admin": {}}',
			'{"custom_roles": {"analyst": {"name": "Analyst", "initial_capabilities": {}, "capabilities": {}, "extra_key": "forbidden"}}}',
		);

		foreach ( $array_samples as $sample ) {
			$this->roles->clear_cache();
			$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = $sample;

			// Parse must reject with 500
			try {
				$this->roles->parse_role_settings( $sample );
				$this->fail( "Expected parse_role_settings to fail with 500 for array sample: {$sample}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}

			// Read API must fail with 500
			try {
				$this->roles->get_role_settings();
				$this->fail( "Expected get_role_settings to fail with 500 for array sample: {$sample}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}

			// Mutations must fail with 500 and preserve stored bytes
			try {
				$this->roles->create_custom_role( 'new_role', 'New Role' );
				$this->fail( "Expected create_custom_role to fail with 500 for sample: {$sample}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}
			$this->assertSame( $sample, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );

			try {
				$this->roles->update_role_definition( 'editor', array( 'capabilities' => array( 'delete_links' => true ) ) );
				$this->fail( "Expected update_role_definition to fail with 500 for sample: {$sample}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}
			$this->assertSame( $sample, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );

			try {
				$this->roles->reset_role_capabilities( 'editor' );
				$this->fail( "Expected reset_role_capabilities to fail with 500 for sample: {$sample}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}
			$this->assertSame( $sample, $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] );

			// Administrator superuser privileges remain intact
			$this->assertTrue( $this->roles->has_capability( $this->admin_user, 'manage_users' ) );
			$this->assertTrue( $this->roles->has_capability( $this->admin_user, 'empty_trash' ) );
			$this->assertTrue( $this->roles->is_admin( $this->admin_user ) );
		}
	}

	public function test_parse_role_settings_direct_contracts(): void {
		// Missing storage returns null (no overrides)
		$this->assertNull( $this->roles->parse_role_settings( null ) );

		// Stored empty or whitespace strings must throw 500
		foreach ( array( '', ' ', "\t\n " ) as $empty_raw ) {
			try {
				$this->roles->parse_role_settings( $empty_raw );
				$this->fail( "Expected 500 on empty raw storage: '{$empty_raw}'" );
			} catch ( ApiException $e ) {
				$this->assertSame( 500, $e->getCode() );
			}
		}

		// Valid empty JSON object returns empty array
		$empty_obj = $this->roles->parse_role_settings( '{}' );
		$this->assertSame( array(), $empty_obj );

		// Valid empty role object returns array with empty role map
		$empty_role = $this->roles->parse_role_settings( '{"editor":{}}' );
		$this->assertSame( array( 'editor' => array() ), $empty_role );

		// Valid overrides return expected structure
		$valid_raw = json_encode(
			array(
				'editor'       => array( 'delete_links' => true ),
				'custom_roles' => array(
					'analyst' => array(
						'name'                 => 'Analyst',
						'description'          => 'Data analyst',
						'initial_capabilities' => array( 'view_analytics' => true ),
						'capabilities'         => array( 'view_analytics' => true ),
					),
				),
			)
		);
		$parsed    = $this->roles->parse_role_settings( (string) $valid_raw );
		$this->assertIsArray( $parsed );
		$this->assertTrue( $parsed['editor']['delete_links'] );
		$this->assertSame( 'Analyst', $parsed['custom_roles']['analyst']['name'] );
	}

	public function test_empty_trash_capability_is_strictly_admin_only_and_protected(): void {
		// 1. Catalog definition checks
		$catalog = Roles::CAPABILITIES_CATALOG;
		$this->assertArrayHasKey( 'empty_trash', $catalog );
		$this->assertSame( array( 'admin' ), $catalog['empty_trash']['applicable_roles'] );

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

		// Admin definition is non-configurable with empty capabilities array
		$this->assertFalse( $admin_def['is_configurable'] );
		$this->assertSame( array(), $admin_def['capabilities'] );

		// Effective capability check for admin is always true
		$this->assertTrue( $this->roles->has_capability( $this->admin_user, 'empty_trash' ) );

		// 3. Updating editor with empty_trash must throw 422
		try {
			$this->roles->update_role_definition(
				'editor',
				array( 'capabilities' => array( 'empty_trash' => true ) )
			);
			$this->fail( 'Expected 422 when attempting to assign empty_trash to editor' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertStringContainsString( 'reserved for administrators', $e->getMessage() );
		}

		try {
			$this->roles->update_role_definition(
				'editor',
				array( 'capabilities' => array( 'empty_trash' => false ) )
			);
			$this->fail( 'Expected 422 when attempting to assign empty_trash to editor even as false' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertStringContainsString( 'reserved for administrators', $e->getMessage() );
		}

		// 4. Updating admin throws 403 (immutable superuser)
		try {
			$this->roles->update_role_definition(
				'admin',
				array( 'capabilities' => array( 'empty_trash' => false ) )
			);
			$this->fail( 'Expected 403 when attempting to update admin capabilities' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertStringContainsString( 'Administrator capabilities are immutable', $e->getMessage() );
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

		$stored = $this->roles->get_role_settings();
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
		$updated = $roles->update_role_definition(
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

		// Connection 1 creates custom role analyst
		$roles1->create_custom_role( 'analyst', 'Analyst' );

		// Connection 1 customizes Editor
		$roles1->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);

		// Connection 2 customizes Analyst
		$roles2->update_role_definition(
			'analyst',
			array(
				'capabilities' => array(
					'manage_performance' => true,
				),
			)
		);

		// Assert that BOTH customizations survived in the real database
		$roles1->clear_cache();
		$stored = $roles1->get_role_settings();

		$this->assertArrayHasKey( 'editor', $stored );
		$this->assertArrayHasKey( 'custom_roles', $stored );
		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertTrue( $stored['custom_roles']['analyst']['capabilities']['manage_performance'] );
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
		$roles1->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => true,
				),
			)
		);

		// Connection 2 enables manage_integrations
		$roles2->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'manage_integrations' => true,
				),
			)
		);

		// Assert that BOTH capabilities survived on editor
		$roles1->clear_cache();
		$stored = $roles1->get_role_settings();

		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertTrue( $stored['editor']['manage_integrations'] );
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

		// Initial state: create custom role analyst, customize both editor and analyst
		$roles1->create_custom_role( 'analyst', 'Analyst' );
		$roles1->update_role_definition(
			'editor',
			array( 'capabilities' => array( 'delete_links' => true ) )
		);
		$roles1->update_role_definition(
			'analyst',
			array( 'capabilities' => array( 'manage_mail_delivery' => true ) )
		);

		// Connection 1 resets editor
		$roles1->reset_role_capabilities( 'editor' );

		// Connection 2 updates analyst manage_performance
		$roles2->update_role_definition(
			'analyst',
			array( 'capabilities' => array( 'manage_performance' => true ) )
		);

		// Final check: editor is reset to defaults, analyst has both customizations
		$roles1->clear_cache();
		$stored = $roles1->get_role_settings();

		$this->assertArrayNotHasKey( 'editor', $stored );
		$this->assertArrayHasKey( 'custom_roles', $stored );
		$this->assertTrue( $stored['custom_roles']['analyst']['capabilities']['manage_mail_delivery'] );
		$this->assertTrue( $stored['custom_roles']['analyst']['capabilities']['manage_performance'] );
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
				return $roles->update_role_definition(
					'editor',
					array( 'capabilities' => array( 'manage_integrations' => true ) )
				);
			}
		);

		$this->assertSame( 'ok', $parent_res['status'], 'Parent update must succeed: ' . ( $parent_res['message'] ?? '' ) );
		$this->assertSame( 'ok', $child_res['status'], 'Child update must succeed: ' . ( $child_res['message'] ?? '' ) );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified in information_schema.innodb_trx.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ) );
		$stored      = $roles->get_role_settings();

		$this->assertArrayHasKey( 'editor', $stored );
		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertTrue( $stored['editor']['manage_integrations'] );
	}

	public function test_real_db_concurrent_updates_different_roles_overlapping_transactions(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '{\"editor\":{\"create_links\":true},\"custom_roles\":{\"analyst\":{\"name\":\"Analyst\",\"initial_capabilities\":{\"create_links\":true},\"capabilities\":{\"create_links\":true}}}}', 1, NOW())"
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
						'val' => json_encode(
							array(
								'editor'       => array( 'delete_links' => true ),
								'custom_roles' => array(
									'analyst' => array(
										'name'         => 'Analyst',
										'initial_capabilities' => array( 'create_links' => true ),
										'capabilities' => array( 'create_links' => true ),
									),
								),
							)
						),
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

				return $roles->update_role_definition(
					'analyst',
					array( 'capabilities' => array( 'manage_performance' => true ) )
				);
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ) );
		$stored      = $roles->get_role_settings();

		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertTrue( $stored['custom_roles']['analyst']['capabilities']['manage_performance'] );
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

				return $roles->update_role_definition(
					'editor',
					array( 'capabilities' => array( 'manage_integrations' => true ) )
				);
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ) );
		$stored      = $roles->get_role_settings();

		$this->assertTrue( $stored['editor']['delete_links'] );
		$this->assertTrue( $stored['editor']['manage_integrations'] );
	}

	public function test_real_db_concurrent_reset_colliding_with_update_overlapping_transactions(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_settings = new SettingsApi( $setup_db );
		$setup_roles    = new Roles( $setup_settings );
		$setup_roles->create_custom_role( 'analyst', 'Analyst' );
		$setup_roles->update_role_definition( 'editor', array( 'capabilities' => array( 'delete_links' => true ) ) );
		$setup_roles->update_role_definition( 'analyst', array( 'capabilities' => array( 'manage_mail_delivery' => true ) ) );
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

				return $roles->update_role_definition(
					'analyst',
					array( 'capabilities' => array( 'manage_location_data' => true ) )
				);
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ) );
		$stored      = $roles->get_role_settings();

		$this->assertArrayNotHasKey( 'editor', $stored );
		$this->assertArrayHasKey( 'custom_roles', $stored );
		$this->assertTrue( $stored['custom_roles']['analyst']['capabilities']['manage_mail_delivery'] );
		$this->assertTrue( $stored['custom_roles']['analyst']['capabilities']['manage_location_data'] );
	}

	public function test_real_db_concurrent_custom_role_creation_inherits_locked_committed_editor_state(): void {
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
						'val' => json_encode(
							array(
								'editor' => array(
									'delete_links'         => true,
									'manage_mail_delivery' => true,
								),
							)
						),
						'key' => Constants::SETTING_ROLE_CAPABILITIES,
					)
				);
				$pdo->commit();

				return array(
					'contention_observed' => $contention_observed,
				);
			},
			function ( $sock ) use ( $conn_config ) {
				$conn         = new Connection( $conn_config );
				$db           = new PeakURL_DB( $conn );
				$settings_api = new SettingsApi( $db );
				$users_api    = new UsersApi( $db );
				$roles        = new Roles( $settings_api, $users_api );

				fgets( $sock );
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();

				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				return $roles->create_custom_role( 'analyst', 'Analyst' );
			}
		);

		$this->assertSame( 'ok', $parent_res['status'], 'Parent update must succeed: ' . ( $parent_res['message'] ?? '' ) );
		$this->assertSame( 'ok', $child_res['status'], 'Child role creation must succeed: ' . ( $child_res['message'] ?? '' ) );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'InnoDB lock contention must be verified in information_schema.innodb_trx.' );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ), new UsersApi( $verify_db ) );

		$stored = $roles->get_role_settings();
		$this->assertArrayHasKey( 'custom_roles', $stored );
		$this->assertArrayHasKey( 'analyst', $stored['custom_roles'] );

		$analyst_initial = $stored['custom_roles']['analyst']['initial_capabilities'];
		$analyst_caps    = $roles->capabilities_for_role( 'analyst' );

		$this->assertTrue( $analyst_initial['delete_links'], 'Must inherit committed delete_links' );
		$this->assertTrue( $analyst_initial['manage_mail_delivery'], 'Must inherit committed manage_mail_delivery' );
		$this->assertTrue( $analyst_caps['delete_links'] );
		$this->assertTrue( $analyst_caps['manage_mail_delivery'] );

		$roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'delete_links' => false,
				),
			)
		);
		$roles->clear_cache();
		$analyst_caps_after = $roles->capabilities_for_role( 'analyst' );
		$this->assertTrue( $analyst_caps_after['delete_links'], 'Custom role must remain independent of subsequent Editor changes' );

		$roles->update_role_definition(
			'analyst',
			array(
				'capabilities' => array(
					'delete_links' => false,
				),
			)
		);
		$roles->clear_cache();
		$this->assertFalse( $roles->capabilities_for_role( 'analyst' )['delete_links'] );

		$roles->reset_role_capabilities( 'analyst' );
		$roles->clear_cache();
		$this->assertTrue( $roles->capabilities_for_role( 'analyst' )['delete_links'], 'Reset must restore to creation snapshot' );
	}

	public function test_real_db_concurrent_custom_role_creation_duplicate_id_serialized_with_conflict(): void {
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

				$raw                    = $pdo->query( "SELECT setting_value FROM `{$prefix}settings` WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" )->fetchColumn();
				$stored                 = json_decode( (string) $raw, true );
				$stored['custom_roles'] = array(
					'analyst' => array(
						'name'                 => 'Analyst A',
						'description'          => 'Created by A',
						'initial_capabilities' => array( 'create_links' => true ),
						'capabilities'         => array( 'create_links' => true ),
					),
				);
				$update_stmt            = $pdo->prepare( "UPDATE `{$prefix}settings` SET setting_value = :val, updated_at = NOW() WHERE setting_key = :key" );
				$update_stmt->execute(
					array(
						'val' => json_encode( $stored ),
						'key' => Constants::SETTING_ROLE_CAPABILITIES,
					)
				);
				$pdo->commit();

				return array(
					'contention_observed' => $contention_observed,
				);
			},
			function ( $sock ) use ( $conn_config ) {
				$conn         = new Connection( $conn_config );
				$db           = new PeakURL_DB( $conn );
				$settings_api = new SettingsApi( $db );
				$users_api    = new UsersApi( $db );
				$roles        = new Roles( $settings_api, $users_api );

				fgets( $sock );
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();

				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				try {
					$roles->create_custom_role( 'analyst', 'Analyst B' );
					return array( 'status' => 'unexpected_success' );
				} catch ( ApiException $e ) {
					return array(
						'status'  => 'conflict',
						'code'    => $e->getCode(),
						'message' => $e->getMessage(),
					);
				}
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'] );
		$this->assertSame( 'conflict', $child_res['result']['status'] );
		$this->assertSame( 409, $child_res['result']['code'] );
		$this->assertStringContainsString( 'already exists', $child_res['result']['message'] );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ), new UsersApi( $verify_db ) );
		$stored      = $roles->get_role_settings();
		$this->assertSame( 'Analyst A', $stored['custom_roles']['analyst']['name'] );
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

	/**
	 * Verify that custom role creation clones current effective editor snapshot and modifies independently.
	 */
	public function test_custom_role_creation_clones_editor_snapshot_and_modifies_independently(): void {
		// 1. Customize editor first
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'manage_integrations' => true,
					'delete_links'        => true,
				),
			)
		);

		// 2. Create custom role 'support'
		$created = $this->roles->create_custom_role( 'support', 'Support Specialist', 'Customer support tier' );
		$this->assertSame( 'support', $created['id'] );
		$this->assertSame( 'Support Specialist', $created['name'] );
		$this->assertFalse( $created['is_builtin'] );
		$this->assertTrue( $created['is_configurable'] );
		$this->assertFalse( $created['is_customized'] );

		// Verify support inherited editor snapshot
		$support_caps = $this->roles->capabilities_for_role( 'support' );
		$this->assertTrue( $support_caps['manage_integrations'] );
		$this->assertTrue( $support_caps['delete_links'] );
		$this->assertTrue( $support_caps['create_links'] );
		$this->assertFalse( $support_caps['manage_users'] );

		// 3. Mutate editor AFTER cloning: support must NOT change
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'manage_integrations' => false,
				),
			)
		);
		$this->assertFalse( $this->roles->capabilities_for_role( 'editor' )['manage_integrations'] );
		$this->assertTrue( $this->roles->capabilities_for_role( 'support' )['manage_integrations'] );

		// 4. Mutate support: editor must NOT change
		$this->roles->update_role_definition(
			'support',
			array(
				'capabilities' => array(
					'manage_site_settings' => true,
				),
			)
		);
		$this->assertTrue( $this->roles->capabilities_for_role( 'support' )['manage_site_settings'] );
		$this->assertFalse( $this->roles->capabilities_for_role( 'editor' )['manage_site_settings'] );
	}

	/**
	 * Verify that resetting a custom role restores its creation-time snapshot, not current editor.
	 */
	public function test_custom_role_reset_restores_creation_snapshot_not_current_editor(): void {
		// Editor has manage_integrations = true at clone time
		$this->roles->update_role_definition(
			'editor',
			array( 'capabilities' => array( 'manage_integrations' => true ) )
		);

		$this->roles->create_custom_role( 'support', 'Support Specialist' );

		// Editor changes afterwards to manage_integrations = false
		$this->roles->update_role_definition(
			'editor',
			array( 'capabilities' => array( 'manage_integrations' => false ) )
		);

		// Support is modified
		$this->roles->update_role_definition(
			'support',
			array(
				'capabilities' => array(
					'create_links'         => false,
					'manage_site_settings' => true,
				),
			)
		);

		$support_caps = $this->roles->capabilities_for_role( 'support' );
		$this->assertFalse( $support_caps['create_links'] );
		$this->assertTrue( $support_caps['manage_site_settings'] );

		// Reset support role
		$reset_def = $this->roles->reset_role_capabilities( 'support' );
		$this->assertFalse( $reset_def['is_customized'] );

		$support_caps_after = $this->roles->capabilities_for_role( 'support' );
		// Restored to its creation snapshot: create_links = true, manage_integrations = true, manage_site_settings = false
		$this->assertTrue( $support_caps_after['create_links'] );
		$this->assertTrue( $support_caps_after['manage_integrations'] );
		$this->assertFalse( $support_caps_after['manage_site_settings'] );
	}

	/**
	 * Verify custom role creation validation: slug length, reserved names, regex, and duplicate rejection.
	 */
	public function test_custom_role_creation_validates_slug_and_reserved_names(): void {
		// Reserved names
		foreach ( array( 'admin', 'editor' ) as $reserved ) {
			try {
				$this->roles->create_custom_role( $reserved, 'Reserved Name' );
				$this->fail( "Expected 422 for reserved role ID: {$reserved}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode() );
			}
		}

		// Slug too long (> 32 chars)
		try {
			$this->roles->create_custom_role( str_repeat( 'a', 33 ), 'Long Role' );
			$this->fail( 'Expected 422 for role ID exceeding 32 characters' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
		}

		// Empty or whitespace name
		try {
			$this->roles->create_custom_role( 'valid_slug', '   ' );
			$this->fail( 'Expected 422 for empty role name' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
		}

		// Create valid role
		$this->roles->create_custom_role( 'valid_slug', 'Valid Role' );

		// Duplicate slug -> 409
		try {
			$this->roles->create_custom_role( 'valid_slug', 'Duplicate Role' );
			$this->fail( 'Expected 409 for duplicate role ID' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->getCode() );
			$this->assertStringContainsString( 'already exists', $e->getMessage() );
		}
	}

	/**
	 * Verify that custom role deletion is prevented with 409 when users are assigned to that role.
	 */
	public function test_custom_role_deletion_prevented_when_users_assigned_throws_409(): void {
		$this->roles->create_custom_role( 'content_manager', 'Content Manager' );

		$this->users_api->method( 'count_role_assignments_for_update' )
			->with( 'content_manager' )
			->willReturn( 3 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 409 );
		$this->expectExceptionMessage( 'Cannot delete role "Content Manager" because it is assigned to 3 user(s).' );

		$this->roles->delete_custom_role( 'content_manager' );
	}

	/**
	 * Verify custom role deletion succeeds when zero users are assigned, while builtin roles cannot be deleted.
	 */
	public function test_custom_role_deletion_succeeds_when_zero_users_assigned(): void {
		// Builtin roles cannot be deleted
		foreach ( array( 'admin', 'editor' ) as $builtin ) {
			try {
				$this->roles->delete_custom_role( $builtin );
				$this->fail( "Expected 403 when attempting to delete builtin role: {$builtin}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
		}

		// Nonexistent role -> 404
		try {
			$this->roles->delete_custom_role( 'nonexistent' );
			$this->fail( 'Expected 404 for deleting nonexistent custom role' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->getCode() );
		}

		// Create custom role
		$this->roles->create_custom_role( 'temp_role', 'Temporary Role' );
		$this->assertTrue( $this->roles->is_custom_role( 'temp_role' ) );

		$this->users_api->method( 'count_role_assignments_for_update' )
			->with( 'temp_role' )
			->willReturn( 0 );

		$this->roles->delete_custom_role( 'temp_role' );
		$this->assertFalse( $this->roles->is_custom_role( 'temp_role' ) );
	}

	/**
	 * Verify that custom role deletion fails closed with 500 when UsersApi dependency is unavailable.
	 */
	public function test_delete_custom_role_fails_closed_when_users_api_unavailable(): void {
		$roles_without_users = new Roles( $this->settings_api, null );
		$this->roles->create_custom_role( 'orphaned_check', 'Orphaned Check' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Required storage or user services are unavailable.' );

		$roles_without_users->delete_custom_role( 'orphaned_check' );
	}

	/**
	 * Verify that administrator superuser fast path bypasses corrupted settings storage for known capabilities.
	 */
	public function test_immutable_admin_superuser_fast_path_bypasses_corrupt_storage(): void {
		$this->roles->clear_cache();
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = '{invalid_corrupted_json_syntax';

		// Canonical admin possesses catalog capabilities unconditionally without throwing 500
		$this->assertTrue( $this->roles->has_capability( $this->admin_user, 'manage_users' ) );
		$this->assertTrue( $this->roles->has_capability( $this->admin_user, 'manage_site_settings' ) );
		$this->assertTrue( $this->roles->has_capability( $this->admin_user, 'create_links' ) );

		// Non-admin user evaluating capabilities against corrupted storage throws 500 fail-closed
		try {
			$this->roles->has_capability( $this->editor_user, 'create_links' );
			$this->fail( 'Expected 500 ApiException for non-admin user on corrupted storage' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}
	}

	/**
	 * Verify all six Site & Integrations capabilities are delegable to Editor.
	 */
	public function test_all_six_site_and_integrations_capabilities_delegable_to_editor(): void {
		$six_caps = array(
			'manage_site_settings' => true,
			'manage_mail_delivery' => true,
			'manage_location_data' => true,
			'manage_performance'   => true,
			'manage_updates'       => true,
			'manage_integrations'  => true,
		);

		$updated = $this->roles->update_role_definition(
			'editor',
			array( 'capabilities' => $six_caps )
		);

		$this->assertTrue( $updated['is_customized'] );

		foreach ( $six_caps as $cap => $val ) {
			$this->assertTrue(
				$this->roles->has_capability( $this->editor_user, $cap ),
				"Editor must have capability {$cap}"
			);
		}

		// Editor must still remain non-admin
		$this->assertFalse( $this->roles->is_admin( $this->editor_user ) );
	}

	/**
	 * Verify RolesController create, update metadata, and delete endpoints.
	 */
	public function test_roles_controller_create_update_metadata_and_delete_endpoints(): void {
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->admin_user );

		// 1. Create custom role
		$create_req = $this->make_request(
			'POST',
			'/api/v1/roles',
			array(
				'id'          => 'author',
				'name'        => 'Author',
				'description' => 'Author role',
			)
		);
		$create_res = $this->roles_controller->create( $create_req );

		$this->assertSame( 201, $create_res['status'] );
		$this->assertTrue( $create_res['body']['success'] );
		$this->assertSame( 'author', $create_res['body']['data']['id'] );
		$this->assertSame( 'Author', $create_res['body']['data']['name'] );

		// 2. Update role metadata via PATCH
		$patch_req = $this->make_request(
			'PATCH',
			'/api/v1/roles/author',
			array(
				'name'        => 'Senior Author',
				'description' => 'Updated author description',
			)
		);
		$patch_req->set_route_params( array( 'role' => 'author' ) );
		$patch_res = $this->roles_controller->update( $patch_req );

		$this->assertSame( 200, $patch_res['status'] );
		$this->assertTrue( $patch_res['body']['success'] );
		$this->assertSame( 'Senior Author', $patch_res['body']['data']['name'] );
		$this->assertSame( 'Updated author description', $patch_res['body']['data']['description'] );

		// 3. Delete custom role
		$this->users_api->method( 'count_users_by_role' )
			->with( 'author' )
			->willReturn( 0 );

		$delete_req = $this->make_request( 'DELETE', '/api/v1/roles/author' );
		$delete_req->set_route_params( array( 'role' => 'author' ) );
		$delete_res = $this->roles_controller->delete( $delete_req );

		$this->assertSame( 200, $delete_res['status'] );
		$this->assertTrue( $delete_res['body']['success'] );
		$this->assertSame( 'Custom role deleted successfully.', $delete_res['body']['message'] );
		$this->assertSame( 'author', $delete_res['body']['data']['id'] );
	}

	/**
	 * Verify that canonical lowercase snake_case identifiers are strictly enforced.
	 *
	 * Accepted:
	 * - support, content_manager, tier_2_support, a, analyst2, exactly 32 chars.
	 * Rejected:
	 * - Numeric only: 0, 01, 123
	 * - Leading digits: 2fa_reviewer
	 * - Hyphens: content-manager
	 * - Uppercase: Content_Manager
	 * - Consecutive underscores: content__manager
	 * - Leading/trailing underscores: _editor, editor_
	 * - Symbols/spaces: content manager, cont.ent, ana!lyst
	 * - Too long (> 32 characters)
	 * - Reserved names: admin, editor
	 */
	public function test_canonical_role_id_contract_accepted_and_rejected(): void {
		// 1. Accepted identifiers
		$accepted = array(
			'support',
			'content_manager',
			'tier_2_support',
			'a',
			'analyst2',
			's_' . str_repeat( 'b', 30 ), // exactly 32 chars
		);

		foreach ( $accepted as $valid_id ) {
			$this->assertTrue(
				Roles::is_valid_role_id( $valid_id ),
				"Expected valid role ID: {$valid_id}"
			);
		}

		// 2. Rejected identifiers
		$rejected = array(
			array( '0', 'numeric only 0' ),
			array( '01', 'numeric leading zero' ),
			array( '123', 'numeric multiple digits' ),
			array( '2fa_reviewer', 'starts with digit' ),
			array( 'content-manager', 'contains hyphens' ),
			array( 'Content_Manager', 'uppercase letters' ),
			array( 'content__manager', 'consecutive underscores' ),
			array( '_editor', 'leading underscore' ),
			array( 'editor_', 'trailing underscore' ),
			array( '_', 'single underscore' ),
			array( 'content manager', 'contains space' ),
			array( ' content_manager', 'leading space' ),
			array( 'content_manager ', 'trailing space' ),
			array( 'cont.ent', 'contains dot' ),
			array( 'ana!lyst', 'contains exclamation' ),
			array( '', 'empty string' ),
			array( 'a' . str_repeat( 'b', 32 ), '33 characters (exceeds 32)' ),
			array( "admin\n", 'trailing newline on reserved admin' ),
			array( "editor\n", 'trailing newline on reserved editor' ),
			array( "content_manager\n", 'trailing newline on valid name' ),
			array( "content_manager\r", 'trailing carriage return' ),
			array( "content_manager\r\n", 'trailing CRLF' ),
			array( "content\nmanager", 'internal newline' ),
		);

		foreach ( $rejected as $entry ) {
			$invalid_id = (string) $entry[0];
			$reason     = (string) $entry[1];

			$this->assertFalse(
				Roles::is_valid_role_id( $invalid_id ),
				"Expected rejected role ID ({$reason}): '{$invalid_id}'"
			);

			// Must fail on creation
			try {
				$this->roles->create_custom_role( $invalid_id, 'Test Role' );
				$this->fail( "Expected 422 for invalid role ID ({$reason}): '{$invalid_id}'" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode(), "Expected HTTP 422 for '{$invalid_id}'" );
			}
		}

		// Reserved names
		foreach ( array( 'admin', 'editor' ) as $reserved ) {
			try {
				$this->roles->create_custom_role( $reserved, 'Reserved Name' );
				$this->fail( "Expected 422 for reserved role ID: {$reserved}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode() );
				$this->assertSame( 'The role ID is reserved.', $e->getMessage() );
			}
		}
	}

	/**
	 * Verify that malformed role IDs are rejected on user assignment without silent coercion.
	 *
	 * When 'analyst' exists, 'ana!lyst' must NOT be coerced to 'analyst' via sanitize_key().
	 * Hyphenated 'content-manager' must NOT be rewritten to 'content_manager'.
	 */
	public function test_malformed_role_id_rejected_without_silent_coercion(): void {
		// Create registered custom roles
		$this->roles->create_custom_role( 'analyst', 'Analyst' );
		$this->roles->create_custom_role( 'content_manager', 'Content Manager' );

		// 1. is_registered_role must strictly reject non-canonical inputs
		$this->assertTrue( $this->roles->is_registered_role( 'analyst' ) );
		$this->assertTrue( $this->roles->is_registered_role( 'content_manager' ) );

		$this->assertFalse( $this->roles->is_registered_role( 'ana!lyst' ) );
		$this->assertFalse( $this->roles->is_registered_role( 'content-manager' ) );
		$this->assertFalse( $this->roles->is_registered_role( '0' ) );
		$this->assertFalse( $this->roles->is_registered_role( 'Analyst' ) );

		// 2. is_custom_role must strictly reject non-canonical inputs
		$this->assertTrue( $this->roles->is_custom_role( 'analyst' ) );
		$this->assertFalse( $this->roles->is_custom_role( 'ana!lyst' ) );
		$this->assertFalse( $this->roles->is_custom_role( 'content-manager' ) );

		// 3. capabilities_for_role must fail closed for invalid role IDs
		$caps = $this->roles->capabilities_for_role( 'ana!lyst' );
		foreach ( $caps as $cap => $granted ) {
			$this->assertFalse( $granted, "Expected false for capability {$cap} on malformed role" );
		}

		// 4. get_role_definition must throw 404 for invalid role IDs without sanitization
		try {
			$this->roles->get_role_definition( 'ana!lyst' );
			$this->fail( 'Expected 404 for malformed role ID' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->getCode() );
		}

		// 5. update_role_definition must throw 422 for malformed role IDs without sanitization
		try {
			$this->roles->update_role_definition( 'ana!lyst', array( 'name' => 'Hacked' ) );
			$this->fail( 'Expected 422 for malformed role ID on update' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
		}

		// 6. reset_role_capabilities must throw 422 for malformed role IDs
		try {
			$this->roles->reset_role_capabilities( 'ana!lyst' );
			$this->fail( 'Expected 422 for malformed role ID on reset' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
		}

		// 7. delete_custom_role must throw 404 for malformed role IDs
		try {
			$this->roles->delete_custom_role( 'ana!lyst' );
			$this->fail( 'Expected 404 for malformed role ID on delete' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->getCode() );
		}
	}

	/**
	 * Verify JSON integrity: deleting the last custom role while Editor overrides remain
	 * omits custom_roles entirely (never emitting custom_roles: []), and round-trips cleanly.
	 */
	public function test_json_integrity_last_role_deleted_with_retained_editor_overrides(): void {
		// 1. Modify Editor capabilities to persist an override
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array( 'delete_links' => true ),
			)
		);

		// Verify editor override is saved
		$stored_raw = $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] ?? null;
		$this->assertNotNull( $stored_raw );
		$stored_obj = json_decode( $stored_raw );
		$this->assertTrue( $stored_obj->editor instanceof \stdClass );
		$this->assertTrue( $stored_obj->editor->delete_links );

		// 2. Create a custom role
		$this->roles->create_custom_role( 'reviewer', 'Reviewer', 'Link reviewer' );

		// Verify custom_roles is present as an object
		$stored_raw = $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] ?? null;
		$stored_obj = json_decode( $stored_raw );
		$this->assertTrue( $stored_obj->custom_roles instanceof \stdClass );
		$this->assertTrue( isset( $stored_obj->custom_roles->reviewer ) );

		// 3. Delete the only custom role (mock 0 assigned users)
		$this->users_api->method( 'count_role_assignments_for_update' )
			->with( 'reviewer' )
			->willReturn( 0 );

		$this->roles->delete_custom_role( 'reviewer' );

		// 4. Inspect persisted JSON
		$final_raw = $this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] ?? null;
		$this->assertNotNull( $final_raw );

		// Must NOT contain "custom_roles": []
		$this->assertStringNotContainsString( '"custom_roles":[]', $final_raw );
		$this->assertStringNotContainsString( '"custom_roles": []', $final_raw );

		$final_obj = json_decode( $final_raw );
		$this->assertTrue( $final_obj instanceof \stdClass );
		$this->assertTrue( $final_obj->editor instanceof \stdClass );
		$this->assertTrue( $final_obj->editor->delete_links );
		$this->assertFalse( property_exists( $final_obj, 'custom_roles' ), 'custom_roles must be omitted when empty' );

		// 5. parse_role_settings must succeed without 500
		$parsed = $this->roles->parse_role_settings( $final_raw );
		$this->assertIsArray( $parsed );
		$this->assertArrayHasKey( 'editor', $parsed );
		$this->assertTrue( $parsed['editor']['delete_links'] );
		$this->assertArrayNotHasKey( 'custom_roles', $parsed );

		// 6. get_role_definitions must return admin and editor cleanly
		$defs = $this->roles->get_role_definitions();
		$this->assertCount( 2, $defs );
		$this->assertSame( 'admin', $defs[0]['id'] );
		$this->assertSame( 'editor', $defs[1]['id'] );
		$this->assertTrue( $defs[1]['is_customized'] );
	}

	/**
	 * Verify that empty capability maps serialize as JSON objects ({}), never JSON lists ([]).
	 */
	public function test_empty_capability_maps_serialize_as_objects_never_arrays(): void {
		$custom_role_data = array(
			'name'                 => 'Minimal Role',
			'description'          => 'Role with empty capability maps',
			'initial_capabilities' => array(),
			'capabilities'         => array(),
		);

		$settings = array(
			'custom_roles' => array(
				'minimal' => $custom_role_data,
			),
		);

		$json = Roles::serialize_role_settings( $settings );
		$this->assertNotNull( $json );

		// Assert JSON string has {} for maps, not []
		$this->assertStringContainsString( '"initial_capabilities":{}', $json );
		$this->assertStringContainsString( '"capabilities":{}', $json );

		// Decode and verify PHP object types
		$decoded = json_decode( $json );
		$this->assertTrue( $decoded->custom_roles instanceof \stdClass );
		$this->assertTrue( $decoded->custom_roles->minimal instanceof \stdClass );
		$this->assertTrue( $decoded->custom_roles->minimal->initial_capabilities instanceof \stdClass );
		$this->assertTrue( $decoded->custom_roles->minimal->capabilities instanceof \stdClass );

		// parse_role_settings must accept it cleanly
		$parsed = $this->roles->parse_role_settings( $json );
		$this->assertIsArray( $parsed );
		$this->assertArrayHasKey( 'custom_roles', $parsed );
		$this->assertSame( array(), $parsed['custom_roles']['minimal']['capabilities'] );
	}

	/**
	 * Verify that redundant hardcoded Administrator capability definition is removed
	 * and Administrator capabilities are evaluated dynamically across all catalog items.
	 */
	public function test_admin_capabilities_immutable_without_redundant_hardcoded_map(): void {
		// ROLE_CAPABILITIES must only have 'editor'
		$this->assertArrayNotHasKey( 'admin', Roles::ROLE_CAPABILITIES );
		$this->assertArrayHasKey( 'editor', Roles::ROLE_CAPABILITIES );

		// Admin capabilities grant every capability in the catalog
		$admin_caps = $this->roles->capabilities_for_role( 'admin' );
		$this->assertCount( count( Roles::CAPABILITIES_CATALOG ), $admin_caps );
		foreach ( $admin_caps as $cap => $granted ) {
			$this->assertTrue( $granted, "Admin must have {$cap} granted" );
		}

		// has_capability works for admin
		$admin_user = array( 'role' => 'admin' );
		$this->assertTrue( $this->roles->has_capability( $admin_user, 'manage_users' ) );
		$this->assertTrue( $this->roles->has_capability( $admin_user, 'empty_trash' ) );
		$this->assertTrue( $this->roles->has_capability( $admin_user, 'create_links' ) );

		// Unknown capability is NOT granted even to admin
		$this->assertFalse( $this->roles->has_capability( $admin_user, 'nonexistent_superpower' ) );

		// Corrupt or missing role settings JSON does not affect admin
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = '{corrupt: json!!!';
		$this->assertTrue( $this->roles->has_capability( $admin_user, 'manage_users' ) );
		$admin_caps_corrupt = $this->roles->capabilities_for_role( 'admin' );
		$this->assertTrue( $admin_caps_corrupt['manage_users'] );
	}

	/**
	 * Verify custom role display name and description preserve Unicode, spaces, and punctuation.
	 */
	public function test_custom_role_metadata_preserves_unicode_and_punctuation(): void {
		$unicode_name = 'QA / Ops (EMEA & APAC) — £100 🚀 日本語';
		$unicode_desc = 'Specialists & Analysts with UTF-8: äöü, € and punctuation.';

		$created = $this->roles->create_custom_role(
			'qa_ops',
			$unicode_name,
			$unicode_desc
		);

		$this->assertSame( 'qa_ops', $created['id'] );
		$this->assertSame( $unicode_name, $created['name'] );
		$this->assertSame( $unicode_desc, $created['description'] );

		// Fetch definition
		$def = $this->roles->get_role_definition( 'qa_ops' );
		$this->assertSame( $unicode_name, $def['name'] );
		$this->assertSame( $unicode_desc, $def['description'] );

		// Get role name helper
		$this->assertSame( $unicode_name, $this->roles->get_role_name( 'qa_ops' ) );

		// Update name with different Unicode text; ID must remain unchanged
		$new_name = 'Content & Editorial — UK 🇬🇧';
		$updated  = $this->roles->update_role_definition(
			'qa_ops',
			array( 'name' => $new_name )
		);

		$this->assertSame( 'qa_ops', $updated['id'] );
		$this->assertSame( $new_name, $updated['name'] );
		$this->assertSame( $unicode_desc, $updated['description'] );
	}

	/**
	 * Verify that newline-suffixed role IDs are rejected, non-mutating, and do not bypass reserved checks.
	 */
	public function test_newline_suffixed_role_ids_rejected_and_non_mutating(): void {
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->admin_user );

		// 1. POST /roles with "admin\n" returns 422 and creates no role
		$req = $this->make_request(
			'POST',
			'/api/v1/roles',
			array(
				'id'   => "admin\n",
				'name' => 'Fake Admin',
			)
		);

		try {
			$this->roles_controller->create( $req );
			$this->fail( 'Expected 422 for role ID with trailing newline' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
		}

		// 2. is_registered_role does not resolve "admin\n" or "content_manager\n"
		$this->assertFalse( $this->roles->is_registered_role( "admin\n" ) );
		$this->assertFalse( $this->roles->is_registered_role( "content_manager\n" ) );

		// 3. Stored custom-role key with newline is rejected by parse_role_settings with 500
		$corrupt_json = '{"custom_roles":{"analyst\n":{"name":"Analyst","description":"","initial_capabilities":{},"capabilities":{}}}}';
		try {
			$this->roles->parse_role_settings( $corrupt_json );
			$this->fail( 'Expected 500 for stored role key containing trailing newline' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
			$this->assertSame( 'Invalid or corrupted role capabilities configuration in storage.', $e->getMessage() );
		}

		// 4. Admin capabilities evaluation remains fully operational regardless of corrupt non-admin storage
		$admin_caps = $this->roles->capabilities_for_role( 'admin' );
		$this->assertTrue( $admin_caps['manage_users'] );
		$this->assertTrue( $admin_caps['empty_trash'] );

		// 5. Non-mutating: definitions only contain built-ins
		$defs = $this->roles->get_role_definitions();
		$this->assertCount( 2, $defs );
		$this->assertSame( 'admin', $defs[0]['id'] );
		$this->assertSame( 'editor', $defs[1]['id'] );
	}

	/**
	 * Verify strict JSON types for POST /api/v1/roles (id, name, description).
	 */
	public function test_strict_request_json_types_for_create_role(): void {
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->admin_user );

		// 1. Invalid 'id' types (missing, null, 123, true, [], {})
		$invalid_ids = array(
			array( null, 'null id' ),
			array( 123, 'integer id' ),
			array( true, 'boolean id' ),
			array( array(), 'array id' ),
		);

		foreach ( $invalid_ids as $entry ) {
			$val    = $entry[0];
			$reason = $entry[1];

			$payload = array(
				'id'   => $val,
				'name' => 'Valid Name',
			);
			$req     = $this->make_request( 'POST', '/api/v1/roles', $payload );

			try {
				$this->roles_controller->create( $req );
				$this->fail( "Expected 422 for {$reason}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode(), "Expected 422 for {$reason}" );
				$this->assertSame( 'Role ID must be a string.', $e->getMessage() );
			}
		}

		// Missing 'id'
		$req_missing_id = $this->make_request( 'POST', '/api/v1/roles', array( 'name' => 'Valid Name' ) );
		try {
			$this->roles_controller->create( $req_missing_id );
			$this->fail( 'Expected 422 for missing id' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Role ID must be a string.', $e->getMessage() );
		}

		// 2. Invalid 'name' types (missing, null, 123, false, [], {}, "   ")
		$invalid_names = array(
			array( null, 'null name', 'Role name must be a string.' ),
			array( 123, 'integer name', 'Role name must be a string.' ),
			array( false, 'boolean name', 'Role name must be a string.' ),
			array( array(), 'array name', 'Role name must be a string.' ),
			array( '   ', 'whitespace-only name', 'Role name must be between 1 and 100 characters.' ),
		);

		foreach ( $invalid_names as $entry ) {
			$val     = $entry[0];
			$reason  = $entry[1];
			$msg_exp = $entry[2];

			$payload = array(
				'id'   => 'valid_slug',
				'name' => $val,
			);
			$req     = $this->make_request( 'POST', '/api/v1/roles', $payload );

			try {
				$this->roles_controller->create( $req );
				$this->fail( "Expected 422 for {$reason}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode(), "Expected 422 for {$reason}" );
				$this->assertSame( $msg_exp, $e->getMessage() );
			}
		}

		// Missing 'name'
		$req_missing_name = $this->make_request( 'POST', '/api/v1/roles', array( 'id' => 'valid_slug' ) );
		try {
			$this->roles_controller->create( $req_missing_name );
			$this->fail( 'Expected 422 for missing name' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Role name must be a string.', $e->getMessage() );
		}

		// 3. Invalid 'description' types (null, number, boolean, array, object)
		$invalid_descs = array(
			array( null, 'null description' ),
			array( 123, 'integer description' ),
			array( true, 'boolean description' ),
			array( array(), 'array description' ),
		);

		foreach ( $invalid_descs as $entry ) {
			$val    = $entry[0];
			$reason = $entry[1];

			$payload = array(
				'id'          => 'valid_slug',
				'name'        => 'Valid Name',
				'description' => $val,
			);
			$req     = $this->make_request( 'POST', '/api/v1/roles', $payload );

			try {
				$this->roles_controller->create( $req );
				$this->fail( "Expected 422 for {$reason}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode(), "Expected 422 for {$reason}" );
				$this->assertSame( 'Role description must be a string.', $e->getMessage() );
			}
		}

		// Verify non-mutating: definitions still contain only built-in roles
		$defs = $this->roles->get_role_definitions();
		$this->assertCount( 2, $defs );
		$this->assertSame( 'admin', $defs[0]['id'] );
		$this->assertSame( 'editor', $defs[1]['id'] );

		// 4. Valid create requests (omitted description, empty string description, Unicode string)
		// Omitted description
		$req_ok1 = $this->make_request(
			'POST',
			'/api/v1/roles',
			array(
				'id'   => 'omitted_desc',
				'name' => 'Omitted Desc Role',
			)
		);
		$res_ok1 = $this->roles_controller->create( $req_ok1 );
		$this->assertSame( 201, $res_ok1['status'] );
		$this->assertSame( '', $res_ok1['body']['data']['description'] );

		// Empty string description
		$req_ok2 = $this->make_request(
			'POST',
			'/api/v1/roles',
			array(
				'id'          => 'empty_desc',
				'name'        => 'Empty Desc Role',
				'description' => '',
			)
		);
		$res_ok2 = $this->roles_controller->create( $req_ok2 );
		$this->assertSame( 201, $res_ok2['status'] );
		$this->assertSame( '', $res_ok2['body']['data']['description'] );

		// Unicode display name and description
		$unicode_name = 'Finance & Operations — UK 🇬🇧';
		$unicode_desc = 'Manages £ & € financial links and reports.';
		$req_ok3      = $this->make_request(
			'POST',
			'/api/v1/roles',
			array(
				'id'          => 'finance_uk',
				'name'        => $unicode_name,
				'description' => $unicode_desc,
			)
		);
		$res_ok3      = $this->roles_controller->create( $req_ok3 );
		$this->assertSame( 201, $res_ok3['status'] );
		$this->assertSame( $unicode_name, $res_ok3['body']['data']['name'] );
		$this->assertSame( $unicode_desc, $res_ok3['body']['data']['description'] );
	}

	/**
	 * Verify strict JSON types for PUT/PATCH /api/v1/roles/{role} (name, description).
	 */
	public function test_strict_request_json_types_for_update_role(): void {
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->admin_user );

		// Create a custom role to target
		$this->roles->create_custom_role( 'target_role', 'Original Name', 'Original Description' );

		// 1. Invalid 'name' types on update (null, bool, number, array)
		$invalid_names = array(
			array( null, 'null name', 'Role name must be a string.' ),
			array( 123, 'integer name', 'Role name must be a string.' ),
			array( false, 'boolean name', 'Role name must be a string.' ),
			array( array(), 'array name', 'Role name must be a string.' ),
			array( '   ', 'whitespace-only name', 'Role name must be between 1 and 100 characters.' ),
		);

		foreach ( $invalid_names as $entry ) {
			$val     = $entry[0];
			$reason  = $entry[1];
			$msg_exp = $entry[2];

			$req = $this->make_request(
				'PATCH',
				'/api/v1/roles/target_role',
				array( 'name' => $val )
			);
			$req->set_route_params( array( 'role' => 'target_role' ) );

			try {
				$this->roles_controller->update( $req );
				$this->fail( "Expected 422 for update with {$reason}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode(), "Expected 422 for update with {$reason}" );
				$this->assertSame( $msg_exp, $e->getMessage() );
			}
		}

		// 2. Invalid 'description' types on update (null, bool, number, array)
		$invalid_descs = array(
			array( null, 'null description' ),
			array( 123, 'integer description' ),
			array( true, 'boolean description' ),
			array( array(), 'array description' ),
		);

		foreach ( $invalid_descs as $entry ) {
			$val    = $entry[0];
			$reason = $entry[1];

			$req = $this->make_request(
				'PATCH',
				'/api/v1/roles/target_role',
				array( 'description' => $val )
			);
			$req->set_route_params( array( 'role' => 'target_role' ) );

			try {
				$this->roles_controller->update( $req );
				$this->fail( "Expected 422 for update with {$reason}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode(), "Expected 422 for update with {$reason}" );
				$this->assertSame( 'Role description must be a string.', $e->getMessage() );
			}
		}

		// 3. Non-mutating verification: role still has original name and description
		$def = $this->roles->get_role_definition( 'target_role' );
		$this->assertSame( 'Original Name', $def['name'] );
		$this->assertSame( 'Original Description', $def['description'] );

		// 4. Update with omitted name or description leaves that field unchanged
		$req_update_name = $this->make_request(
			'PATCH',
			'/api/v1/roles/target_role',
			array( 'name' => 'Updated Name Only' )
		);
		$req_update_name->set_route_params( array( 'role' => 'target_role' ) );
		$res_name = $this->roles_controller->update( $req_update_name );
		$this->assertSame( 200, $res_name['status'] );
		$this->assertSame( 'Updated Name Only', $res_name['body']['data']['name'] );
		$this->assertSame( 'Original Description', $res_name['body']['data']['description'] );

		$req_update_desc = $this->make_request(
			'PATCH',
			'/api/v1/roles/target_role',
			array( 'description' => 'Updated Description Only' )
		);
		$req_update_desc->set_route_params( array( 'role' => 'target_role' ) );
		$res_desc = $this->roles_controller->update( $req_update_desc );
		$this->assertSame( 200, $res_desc['status'] );
		$this->assertSame( 'Updated Name Only', $res_desc['body']['data']['name'] );
		$this->assertSame( 'Updated Description Only', $res_desc['body']['data']['description'] );

		// 5. Updating editor name or description is rejected with 422
		$req_editor_name = $this->make_request(
			'PATCH',
			'/api/v1/roles/editor',
			array( 'name' => 'Renamed Editor' )
		);
		$req_editor_name->set_route_params( array( 'role' => 'editor' ) );
		try {
			$this->roles_controller->update( $req_editor_name );
			$this->fail( 'Expected 422 when attempting to rename editor' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Built-in role name cannot be modified.', $e->getMessage() );
		}

		// 6. Updating admin is rejected with 403
		$req_admin_update = $this->make_request(
			'PATCH',
			'/api/v1/roles/admin',
			array( 'name' => 'Renamed Admin' )
		);
		$req_admin_update->set_route_params( array( 'role' => 'admin' ) );
		try {
			$this->roles_controller->update( $req_admin_update );
			$this->fail( 'Expected 403 when attempting to update admin' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertSame( 'Administrator capabilities are immutable.', $e->getMessage() );
		}
	}

	public function test_stored_retired_manage_profile_throws_500_and_fails_closed(): void {
		$raw_with_profile = array(
			'editor' => array(
				'manage_profile' => true,
				'create_links'   => true,
			),
		);
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = (string) json_encode( $raw_with_profile );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Invalid or corrupted role capabilities configuration in storage.' );

		$this->roles->get_role_settings();
	}

	public function test_stored_retired_manage_webhooks_throws_500_and_fails_closed(): void {
		$raw_with_webhooks = array(
			'editor' => array(
				'manage_webhooks' => true,
				'create_links'    => true,
			),
		);
		$this->stored_options[ Constants::SETTING_ROLE_CAPABILITIES ] = (string) json_encode( $raw_with_webhooks );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Invalid or corrupted role capabilities configuration in storage.' );

		$this->roles->get_role_settings();
	}

	public function test_update_role_definition_rejects_manage_webhooks_and_manage_profile_with_422(): void {
		$req_webhooks = $this->make_request(
			'PUT',
			'/api/v1/roles/editor',
			array(
				'capabilities' => array(
					'manage_webhooks' => true,
				),
			)
		);
		$req_webhooks->set_route_params( array( 'role' => 'editor' ) );

		try {
			$this->roles_controller->update( $req_webhooks );
			$this->fail( 'Expected 422 for manage_webhooks capability' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Unknown capability: manage_webhooks', $e->getMessage() );
		}

		$req_profile = $this->make_request(
			'PUT',
			'/api/v1/roles/editor',
			array(
				'capabilities' => array(
					'manage_profile' => true,
				),
			)
		);
		$req_profile->set_route_params( array( 'role' => 'editor' ) );

		try {
			$this->roles_controller->update( $req_profile );
			$this->fail( 'Expected 422 for manage_profile capability' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Unknown capability: manage_profile', $e->getMessage() );
		}
	}
}
