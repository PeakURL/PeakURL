<?php
/**
 * Integration tests for the PeakURL Authorization Matrix and role enforcement.
 *
 * @package PeakURL\Tests\Integration\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
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
			'manage_webhooks',
			'manage_api_keys',
			'manage_profile',
			'create_links',
			'view_links',
			'edit_links',
			'trash_links',
			'delete_links',
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
			'manage_webhooks',
			'manage_api_keys',
			'delete_links',
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
			'manage_profile',
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
			$this->auth->can_access_record( $editor, 'other-user-99', 'manage_webhooks' )
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
}
