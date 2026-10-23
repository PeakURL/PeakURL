<?php
/**
 * Unit tests for authorization and role boundaries (QA-001 regression).
 *
 * @package PeakURL\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Errors\ApiException;

class AuthorizationTest extends TestCase {

	private Roles $roles;
	private Authorization $authorization;
	/** @var array<string, string> */
	private array $settings_store = array();

	protected function setUp(): void {
		parent::setUp();

		$this->settings_store = array();
		$settings_api         = new class( $this->settings_store ) extends SettingsApi {
			/** @var array<string, string> */
			private array $store;

			public function __construct( array &$store ) {
				$this->store = &$store;
			}

			public function get_option( string $name ): ?string {
				return $this->store[ $name ] ?? null;
			}

			public function update_option( string $name, string $value, string $updated_at = '', bool $autoload = true ): void {
				$this->store[ $name ] = (string) $value;
			}

			public function update_option_with_lock( string $name, callable $modifier ): ?string {
				$val     = $this->store[ $name ] ?? null;
				$new_val = $modifier( $val );
				if ( null === $new_val ) {
					unset( $this->store[ $name ] );
				} else {
					$this->store[ $name ] = (string) $new_val;
				}
				return $new_val;
			}
		};

		$this->roles         = new Roles( $settings_api );
		$this->authorization = new Authorization( $this->roles );
	}

	public function test_admin_role_has_manage_users_capability(): void {
		$admin_user = array(
			'id'   => '1',
			'role' => 'admin',
		);
		$this->assertTrue( $this->roles->has_capability( $admin_user, 'manage_users' ) );
		$this->assertTrue( $this->roles->has_capability( $admin_user, 'manage_mcp' ) );
		$this->assertTrue( $this->roles->is_admin( $admin_user ) );
	}

	public function test_editor_role_does_not_have_manage_users_capability(): void {
		$editor_user = array(
			'id'   => '2',
			'role' => 'editor',
		);
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'manage_users' ) );
		$this->assertFalse( $this->roles->is_admin( $editor_user ) );
	}

	public function test_require_admin_allows_admin_user(): void {
		$admin_user = array(
			'id'   => '1',
			'role' => 'admin',
		);
		// Should not throw
		$this->authorization->require_admin( $admin_user );
		$this->assertTrue( true );
	}

	public function test_require_admin_denies_editor_user(): void {
		$editor_user = array(
			'id'   => '2',
			'role' => 'editor',
		);
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->authorization->require_admin( $editor_user );
	}

	public function test_editor_can_view_and_create_links_but_cannot_manage_integrations(): void {
		$editor_user = array(
			'id'   => '2',
			'role' => 'editor',
		);
		$this->assertTrue( $this->roles->has_capability( $editor_user, 'create_links' ) );
		$this->assertTrue( $this->roles->has_capability( $editor_user, 'view_links' ) );
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'delete_links' ) );
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'empty_trash' ) );
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'manage_integrations' ) );
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'manage_mcp' ) );
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'manage_api_keys' ) );
	}

	public function test_admin_capabilities_are_immutable(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->roles->update_role_definition( 'admin', array( 'capabilities' => array( 'manage_mcp' => false ) ) );
	}

	public function test_admin_capabilities_cannot_be_reset(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->roles->reset_role_capabilities( 'admin' );
	}

	public function test_custom_roles_have_manage_mcp_false_by_default(): void {
		$custom_role = $this->roles->create_custom_role( 'analyst', 'Analyst', 'Analyst role' );
		$this->assertNotEmpty( $custom_role );

		$analyst_user = array(
			'id'   => '3',
			'role' => 'analyst',
		);
		$this->assertFalse( $this->roles->has_capability( $analyst_user, 'manage_mcp' ) );

		// Even if editor was previously granted manage_mcp, newly created custom roles still default to false.
		$this->roles->update_role_definition( 'editor', array( 'capabilities' => array( 'manage_mcp' => true ) ) );
		$this->roles->create_custom_role( 'manager', 'Manager', 'Manager role' );
		$manager_user = array(
			'id'   => '4',
			'role' => 'manager',
		);
		$this->assertFalse( $this->roles->has_capability( $manager_user, 'manage_mcp' ) );
	}

	public function test_manage_users_remains_admin_only_and_cannot_be_delegated(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->roles->update_role_definition( 'editor', array( 'capabilities' => array( 'manage_users' => true ) ) );
	}

	public function test_manage_mcp_can_be_delegated_to_editor_and_custom_roles(): void {
		$editor_user = array(
			'id'   => '2',
			'role' => 'editor',
		);
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'manage_mcp' ) );

		// Delegate to editor.
		$this->roles->update_role_definition( 'editor', array( 'capabilities' => array( 'manage_mcp' => true ) ) );
		$this->assertTrue( $this->roles->has_capability( $editor_user, 'manage_mcp' ) );

		// Create custom role and delegate.
		$this->roles->create_custom_role( 'operations', 'Operations', 'Ops Team' );
		$ops_user = array(
			'id'   => '5',
			'role' => 'operations',
		);
		$this->assertFalse( $this->roles->has_capability( $ops_user, 'manage_mcp' ) );

		$this->roles->update_role_definition( 'operations', array( 'capabilities' => array( 'manage_mcp' => true ) ) );
		$this->assertTrue( $this->roles->has_capability( $ops_user, 'manage_mcp' ) );

		// Revoke on custom role.
		$this->roles->update_role_definition( 'operations', array( 'capabilities' => array( 'manage_mcp' => false ) ) );
		$this->assertFalse( $this->roles->has_capability( $ops_user, 'manage_mcp' ) );
	}

	public function test_role_reset_restores_manage_mcp_disabled_default(): void {
		$editor_user = array(
			'id'   => '2',
			'role' => 'editor',
		);

		// Grant to editor.
		$this->roles->update_role_definition( 'editor', array( 'capabilities' => array( 'manage_mcp' => true ) ) );
		$this->assertTrue( $this->roles->has_capability( $editor_user, 'manage_mcp' ) );

		// Reset editor role.
		$this->roles->reset_role_capabilities( 'editor' );
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'manage_mcp' ) );

		// Custom role reset.
		$this->roles->create_custom_role( 'support', 'Support', 'Support Team' );
		$support_user = array(
			'id'   => '6',
			'role' => 'support',
		);
		$this->roles->update_role_definition( 'support', array( 'capabilities' => array( 'manage_mcp' => true ) ) );
		$this->assertTrue( $this->roles->has_capability( $support_user, 'manage_mcp' ) );

		$this->roles->reset_role_capabilities( 'support' );
		$this->assertFalse( $this->roles->has_capability( $support_user, 'manage_mcp' ) );
	}
}
