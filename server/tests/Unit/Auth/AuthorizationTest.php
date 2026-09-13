<?php
/**
 * Unit tests for authorization and role boundaries (QA-001 regression).
 *
 * @package PeakURL\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Errors\ApiException;

class AuthorizationTest extends TestCase {

	private Roles $roles;
	private Authorization $authorization;

	protected function setUp(): void {
		parent::setUp();
		$this->roles         = new Roles();
		$this->authorization = new Authorization( $this->roles );
	}

	public function test_admin_role_has_manage_users_capability(): void {
		$admin_user = array(
			'id'   => '1',
			'role' => 'admin',
		);
		$this->assertTrue( $this->roles->has_capability( $admin_user, 'manage_users' ) );
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

	public function test_editor_can_view_and_create_links_but_cannot_manage_webhooks(): void {
		$editor_user = array(
			'id'   => '2',
			'role' => 'editor',
		);
		$this->assertTrue( $this->roles->has_capability( $editor_user, 'create_links' ) );
		$this->assertTrue( $this->roles->has_capability( $editor_user, 'view_all_links' ) );
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'manage_webhooks' ) );
		$this->assertFalse( $this->roles->has_capability( $editor_user, 'manage_api_keys' ) );
	}
}
