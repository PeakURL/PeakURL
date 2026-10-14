<?php
/**
 * Integration tests for the PeakURL Authorization Matrix and role enforcement.
 *
 * @package PeakURL\Tests\Integration\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;

class AuthorizationMatrixTest extends TestCase {

	private Roles $roles;
	private Authorization $auth;

	protected function setUp(): void {
		parent::setUp();
		$this->roles = new Roles();
		$this->auth  = new Authorization( $this->roles );
	}

	public function test_admin_has_complete_administrative_capabilities(): void {
		$admin = array(
			'id'   => 'user-admin-1',
			'role' => 'admin',
		);

		$expected_admin_caps = array(
			'manage_users',
			'manage_site_settings',
			'manage_mail_delivery',
			'manage_location_data',
			'manage_performance',
			'manage_updates',
			'manage_integrations',
			'manage_api_keys',
			'create_links',
			'view_links',
			'edit_links',
			'edit_any_link',
			'trash_links',
			'delete_links',
			'delete_any_link',
			'empty_trash',
			'view_analytics',
		);

		foreach ( $expected_admin_caps as $cap ) {
			$this->assertTrue(
				$this->roles->has_capability( $admin, $cap ),
				"Admin must possess capability: {$cap}"
			);
			// require_capability should not throw
			$this->auth->require_capability( $admin, $cap );
		}

		$this->assertTrue( $this->roles->is_admin( $admin ) );
	}

	public function test_editor_is_denied_administrative_capabilities(): void {
		$editor = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);

		$denied_caps = array(
			'manage_users',
			'manage_site_settings',
			'manage_mail_delivery',
			'manage_location_data',
			'manage_performance',
			'manage_updates',
			'manage_integrations',
			'manage_api_keys',
			'edit_any_link',
			'delete_links',
			'delete_any_link',
			'empty_trash',
		);

		foreach ( $denied_caps as $cap ) {
			$this->assertFalse(
				$this->roles->has_capability( $editor, $cap ),
				"Editor must NOT possess capability: {$cap}"
			);

			try {
				$this->auth->require_capability( $editor, $cap );
				$this->fail( "require_capability should have thrown ApiException 403 for {$cap}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
		}

		$this->assertFalse( $this->roles->is_admin( $editor ) );
	}

	public function test_editor_has_content_and_profile_capabilities(): void {
		$editor = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);

		$allowed_caps = array(
			'create_links',
			'view_links',
			'edit_links',
			'trash_links',
			'view_analytics',
		);

		foreach ( $allowed_caps as $cap ) {
			$this->assertTrue(
				$this->roles->has_capability( $editor, $cap ),
				"Editor must possess capability: {$cap}"
			);
		}

		$this->assertTrue( $this->auth->can_view_links( $editor ) );
		$this->assertTrue( $this->auth->can_view_analytics( $editor ) );
		$this->assertFalse( $this->roles->has_capability( $editor, 'delete_links' ) );
		$this->assertFalse( $this->roles->has_capability( $editor, 'empty_trash' ) );
	}

	public function test_record_access_respects_ownership_and_global_capability(): void {
		$editor = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);
		$admin  = array(
			'id'   => 'admin-1',
			'role' => 'admin',
		);

		// When Editor has trash_links and owns the record -> allowed
		$this->assertTrue(
			$this->auth->can_access_record( $editor, 'user-editor-1', 'trash_links', true )
		);

		// When Editor has trash_links but does NOT own the record and action is owner_only -> denied
		$this->assertFalse(
			$this->auth->can_access_record( $editor, 'other-user-99', 'trash_links', true )
		);

		// When Admin performs action -> allowed even if not owner
		$this->assertTrue(
			$this->auth->can_access_record( $admin, 'other-user-99', 'trash_links', true )
		);

		// When user does NOT have capability -> denied
		$this->assertFalse(
			$this->auth->can_access_record( $editor, 'other-user-99', 'manage_integrations' )
		);
	}

	public function test_validate_role_change_prevents_removing_last_admin(): void {
		// Demoting the only admin must throw 422
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'You cannot demote the only remaining admin account.' );

		$this->auth->validate_role_change(
			'admin-1',
			'admin',
			'editor',
			'admin-1',
			fn(): int => 1
		);
	}

	public function test_validate_role_change_prevents_deleting_last_admin(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'At least one admin account must remain on the site.' );

		$this->auth->validate_role_change(
			'admin-1',
			'admin',
			'deleted',
			'admin-1',
			fn(): int => 1
		);
	}

	public function test_validate_role_change_allows_demoting_when_multiple_admins_exist(): void {
		// When admin_count > 1, no exception should be thrown
		$this->auth->validate_role_change(
			'admin-2',
			'admin',
			'editor',
			'admin-1',
			fn(): int => 2
		);
		$this->assertTrue( true );
	}

	public function test_validate_role_change_fails_safely_when_admin_count_check_is_null(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'At least one admin account must remain on the site.' );

		$this->auth->validate_role_change(
			'admin-2',
			'admin',
			'editor',
			'admin-1',
			null
		);
	}

	public function test_validate_role_change_prevents_self_demotion_even_when_multiple_admins_exist(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'You cannot demote your own administrator account.' );

		$this->auth->validate_role_change(
			'admin-1',
			'admin',
			'editor',
			'admin-1',
			fn(): int => 2
		);
	}

	public function test_validate_role_change_prevents_self_deletion_even_when_multiple_admins_exist(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'You cannot delete the current user.' );

		$this->auth->validate_role_change(
			'admin-1',
			'admin',
			'deleted',
			'admin-1',
			fn(): int => 2
		);
	}

	public function test_validate_role_change_allows_deleting_when_multiple_admins_exist(): void {
		$this->auth->validate_role_change(
			'admin-2',
			'admin',
			'deleted',
			'admin-1',
			fn(): int => 2
		);
		$this->assertTrue( true );
	}

	public function test_validate_role_change_allows_non_admin_changes_without_admin_count_check(): void {
		$this->auth->validate_role_change(
			'editor-1',
			'editor',
			'deleted',
			'admin-1',
			null
		);
		$this->auth->validate_role_change(
			'editor-1',
			'editor',
			'admin',
			'admin-1',
			null
		);
		$this->assertTrue( true );
	}

	public function test_admin_is_immutable_superuser_unconditionally_and_ignores_tampered_storage(): void {
		$tampered_stored = array(
			'admin'  => array(
				'manage_performance'   => false,
				'manage_mail_delivery' => false,
				'manage_location_data' => false,
				'manage_updates'       => false,
			),
			'editor' => '{invalid_corrupted_payload',
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( Constants::SETTING_ROLE_CAPABILITIES )
			->willReturn( (string) json_encode( $tampered_stored ) );

		$roles = new Roles( $settings_api );
		$auth  = new Authorization( $roles );

		$admin = array(
			'id'   => 'user-admin-1',
			'role' => 'admin',
		);

		// Admin identity check still succeeds (they are an admin user)
		$this->assertTrue( $roles->is_admin( $admin ) );
		$auth->require_admin( $admin );

		// Administrator is an immutable superuser: has all catalog capabilities unconditionally
		$all_catalog_caps = array(
			'manage_users',
			'manage_site_settings',
			'manage_mail_delivery',
			'manage_location_data',
			'manage_performance',
			'manage_updates',
			'manage_integrations',
			'manage_api_keys',
			'create_links',
			'view_links',
			'edit_links',
			'edit_any_link',
			'trash_links',
			'delete_links',
			'delete_any_link',
			'empty_trash',
			'view_analytics',
		);

		foreach ( $all_catalog_caps as $cap ) {
			$this->assertTrue(
				$roles->has_capability( $admin, $cap ),
				"Admin must possess capability '{$cap}' unconditionally"
			);
			// require_capability must never throw for admin
			$auth->require_capability( $admin, $cap );
		}
	}

	public function test_editor_can_be_granted_site_and_integrations_capabilities_while_retaining_admin_isolation(): void {
		$delegated_caps = array(
			'editor' => array(
				'manage_site_settings' => true,
				'manage_mail_delivery' => true,
				'manage_location_data' => true,
				'manage_performance'   => true,
				'manage_updates'       => true,
				'manage_integrations'  => true,
			),
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( Constants::SETTING_ROLE_CAPABILITIES )
			->willReturn( (string) json_encode( $delegated_caps ) );

		$roles = new Roles( $settings_api );
		$auth  = new Authorization( $roles );

		$editor = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);

		// Editor has the delegated Site & Integrations capabilities
		$site_and_integrations = array(
			'manage_site_settings',
			'manage_mail_delivery',
			'manage_location_data',
			'manage_performance',
			'manage_updates',
			'manage_integrations',
		);

		foreach ( $site_and_integrations as $cap ) {
			$this->assertTrue(
				$roles->has_capability( $editor, $cap ),
				"Editor must possess delegated capability '{$cap}'"
			);
			$auth->require_capability( $editor, $cap );
		}

		// BUT Editor must strictly NOT be recognized as administrator
		$this->assertFalse( $roles->is_admin( $editor ) );
		$this->assertFalse( $auth->is_admin( $editor ) );

		try {
			$auth->require_admin( $editor );
			$this->fail( 'require_admin must throw 403 for editor even with all Site & Integrations capabilities' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertSame( 'Administrator access required.', $e->getMessage() );
		}

		// Admin-reserved capabilities remain strictly false for editor
		$this->assertFalse( $roles->has_capability( $editor, 'manage_users' ) );
		$this->assertFalse( $roles->has_capability( $editor, 'empty_trash' ) );
	}

	public function test_editor_cannot_access_admin_reserved_capabilities_even_if_forged_in_storage(): void {
		$forged_stored = array(
			'editor' => array(
				'manage_users' => true,
				'empty_trash'  => true,
			),
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( Constants::SETTING_ROLE_CAPABILITIES )
			->willReturn( (string) json_encode( $forged_stored ) );

		$roles = new Roles( $settings_api );
		$auth  = new Authorization( $roles );

		$editor = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);

		// Editor must NOT be recognized as admin
		$this->assertFalse( $roles->is_admin( $editor ) );
		$this->assertFalse( $auth->is_admin( $editor ) );

		try {
			$auth->require_admin( $editor );
			$this->fail( 'require_admin must throw 403 for editor' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// Reserved capabilities forged in storage must be rejected with 500 fail-closed
		try {
			$roles->capabilities_for_role( 'editor' );
			$this->fail( 'capabilities_for_role must reject forged reserved admin capabilities with 500' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}

		try {
			$roles->has_capability( $editor, 'manage_users' );
			$this->fail( 'has_capability must reject forged reserved admin capabilities with 500' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}
	}

	public function test_stored_manage_profile_fails_closed_with_500(): void {
		$profile_disabled = array(
			'editor' => array(
				'manage_profile' => false,
			),
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( Constants::SETTING_ROLE_CAPABILITIES )
			->willReturn( (string) json_encode( $profile_disabled ) );

		$roles = new Roles( $settings_api );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );
		$this->expectExceptionMessage( 'Invalid or corrupted role capabilities configuration in storage.' );

		$roles->capabilities_for_role( 'editor' );
	}
}
