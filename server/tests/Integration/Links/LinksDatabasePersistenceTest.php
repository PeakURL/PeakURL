<?php
/**
 * Real Database Persistence and Destructive Operations Integration Tests.
 *
 * Verifies real database persistence mutations against MySQL (peakurl_test):
 * 1. Link creation persists all expected columns in database table.
 * 2. Link update mutates database columns.
 * 3. Link trashing and restoration transition persisted status ('trashed' <-> 'active').
 * 4. Permanent deletion completely removes row from database table.
 * 5. Bulk operations mutate database state for targeted rows only.
 * 6. Real authenticated Empty Trash permanently removes only trashed rows from database.
 * 7. Real authenticated Delete All permanently removes all accessible rows from database.
 * 8. Unauthorized calls reject with 401/403 and produce zero database mutations.
 *
 * @package PeakURL\Tests\Integration\Links
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Links;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Application;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Configuration;
use PeakURL\Services\Database\Connection;
use PeakURL\Http\Request;
use PeakURL\Http\Router;
use PeakURL\Http\JsonResponse;
use PeakURL\Core\Errors\ApiException;
use PDO;

class LinksDatabasePersistenceTest extends TestCase {

	private PDO $pdo;
	private Application $app;
	private Router $router;
	private string $admin_token  = 'test_admin_db_token_12345';
	private string $editor_token = 'test_editor_db_token_12345';
	private string $test_prefix;

	protected function setUp(): void {
		parent::setUp();

		\remove_all_filters( 'site_url' );

		$config     = Configuration::get_current();
		$connection = Connection::get_instance( $config );
		$this->pdo  = $connection->get_connection();

		$this->test_prefix = 'test-db-' . bin2hex( random_bytes( 4 ) ) . '-';

		// Ensure database schema tables exist
		$schema_file = dirname( __DIR__, 3 ) . '/database/schema.sql';
		if ( ! file_exists( $schema_file ) ) {
			$schema_file = dirname( __DIR__, 2 ) . '/database/schema.sql';
		}
		if ( file_exists( $schema_file ) ) {
			$schema_sql = (string) file_get_contents( $schema_file );
			$this->pdo->exec( $connection->prefix_schema( $schema_sql ) );
		}

		// Ensure admin user (id: 1) exists in peakurl_users
		$this->pdo->exec(
			"INSERT INTO peakurl_users (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (1, 'admin', 'admin@example.com', 'Admin', 'User', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())
			ON DUPLICATE KEY UPDATE role = 'admin'"
		);

		// Ensure editor user (id: 9999) exists in peakurl_users
		$this->pdo->exec(
			"INSERT INTO peakurl_users (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (9999, 'test_editor', 'editor@example.com', 'Editor', 'User', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'editor', 1, NOW(), NOW())
			ON DUPLICATE KEY UPDATE role = 'editor'"
		);

		// Seed API keys for admin and editor
		$admin_key_hash  = hash( 'sha256', $this->admin_token );
		$editor_key_hash = hash( 'sha256', $this->editor_token );
		$this->pdo->exec( "DELETE FROM peakurl_api_keys WHERE id IN ('test_admin_key_id', 'test_editor_key_id')" );
		$this->pdo->exec(
			"INSERT INTO peakurl_api_keys (id, user_id, label, key_hash, key_prefix, key_last_four, created_at)
			VALUES
			('test_admin_key_id', 1, 'Admin Test Key', '{$admin_key_hash}', 'test_admin_db', '2345', NOW()),
			('test_editor_key_id', 9999, 'Editor Test Key', '{$editor_key_hash}', 'test_editor_db', '2345', NOW())"
		);

		$this->app    = new Application( $connection, $config );
		$this->router = $this->app->get_router();
	}

	protected function tearDown(): void {
		if ( isset( $this->pdo ) && ! empty( $this->test_prefix ) ) {
			$stmt = $this->pdo->prepare( 'DELETE FROM peakurl_urls WHERE alias LIKE :prefix1 OR short_code LIKE :prefix2' );
			$stmt->execute(
				array(
					'prefix1' => $this->test_prefix . '%',
					'prefix2' => $this->test_prefix . '%',
				)
			);
			$this->pdo->exec( "DELETE FROM peakurl_api_keys WHERE id IN ('test_admin_key_id', 'test_editor_key_id')" );
		}

		\remove_all_filters( 'site_url' );
		parent::tearDown();
	}

	private function dispatch( Request $request ): array {
		try {
			return $this->router->dispatch( $request );
		} catch ( ApiException $e ) {
			return JsonResponse::error(
				$e->getMessage(),
				$e->get_status(),
				$e->get_data()
			);
		}
	}

	private function admin_request( string $method, string $path, array $query = array(), array $body = array() ): Request {
		$headers = array(
			'HTTP_AUTHORIZATION' => 'Bearer ' . $this->admin_token,
			'HTTP_ORIGIN'        => 'https://peakurl.dev',
		);
		return new Request( $method, $path, $query, $body, array(), array(), $headers );
	}

	private function editor_request( string $method, string $path, array $query = array(), array $body = array() ): Request {
		$headers = array(
			'HTTP_AUTHORIZATION' => 'Bearer ' . $this->editor_token,
			'HTTP_ORIGIN'        => 'https://peakurl.dev',
		);
		return new Request( $method, $path, $query, $body, array(), array(), $headers );
	}

	public function test_link_creation_persists_row_in_database(): void {
		$alias = $this->test_prefix . 'create';
		$req   = $this->admin_request(
			'POST',
			'/api/v1/urls',
			array(),
			array(
				'destinationUrl' => 'https://example.com/created-target',
				'alias'          => $alias,
				'title'          => 'Persisted Link Title',
			)
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 201, $res['status'] );

		// Query database directly to prove row exists with expected columns
		$stmt = $this->pdo->prepare( 'SELECT * FROM peakurl_urls WHERE alias = :alias LIMIT 1' );
		$stmt->execute( array( 'alias' => $alias ) );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertIsArray( $row, 'Row must exist in peakurl_urls database table.' );
		$this->assertSame( $alias, $row['alias'] );
		$this->assertSame( 'https://example.com/created-target', $row['destination_url'] );
		$this->assertSame( 'Persisted Link Title', $row['title'] );
		$this->assertSame( 'active', $row['status'] );
	}

	public function test_link_update_persists_mutated_fields(): void {
		$alias      = $this->test_prefix . 'update';
		$create_res = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/before-update',
					'alias'          => $alias,
					'title'          => 'Initial Title',
				)
			)
		);
		$link_id    = $create_res['body']['data']['id'];

		$update_res = $this->dispatch(
			$this->admin_request(
				'PUT',
				'/api/v1/urls/' . $link_id,
				array(),
				array(
					'destinationUrl' => 'https://example.com/after-update',
					'title'          => 'Updated Persisted Title',
				)
			)
		);
		$this->assertSame( 200, $update_res['status'] );

		// Query database directly to prove mutated values were saved
		$stmt = $this->pdo->prepare( 'SELECT * FROM peakurl_urls WHERE id = :id LIMIT 1' );
		$stmt->execute( array( 'id' => $link_id ) );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( 'https://example.com/after-update', $row['destination_url'] );
		$this->assertSame( 'Updated Persisted Title', $row['title'] );
	}
	public function test_link_trash_and_restore_cycle_mutates_database_status(): void {
		$alias      = $this->test_prefix . 'cycle';
		$create_res = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/cycle',
					'alias'          => $alias,
				)
			)
		);
		$link_id    = $create_res['body']['data']['id'];

		// Trash the link
		$trash_res = $this->dispatch( $this->admin_request( 'DELETE', '/api/v1/urls/' . $link_id ) );
		$this->assertSame( 200, $trash_res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $link_id ) );
		$this->assertSame( 'trashed', $stmt->fetchColumn(), 'Database status must be trashed.' );

		// Restore the link
		$restore_res = $this->dispatch( $this->admin_request( 'POST', '/api/v1/urls/' . $link_id . '/restore' ) );
		$this->assertSame( 200, $restore_res['status'] );

		$stmt->execute( array( 'id' => $link_id ) );
		$this->assertSame( 'active', $stmt->fetchColumn(), 'Database status must be restored to active.' );
	}

	public function test_link_permanent_deletion_removes_database_row(): void {
		$alias      = $this->test_prefix . 'perm';
		$create_res = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/perm',
					'alias'          => $alias,
				)
			)
		);
		$link_id    = $create_res['body']['data']['id'];

		// Trash first
		$this->dispatch( $this->admin_request( 'DELETE', '/api/v1/urls/' . $link_id ) );

		// Permanent delete
		$delete_res = $this->dispatch( $this->admin_request( 'DELETE', '/api/v1/urls/' . $link_id ) );
		$this->assertSame( 200, $delete_res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $link_id ) );
		$this->assertSame( 0, (int) $stmt->fetchColumn(), 'Permanently deleted row must not exist in database.' );
	}

	public function test_bulk_trash_and_bulk_restore_mutates_database_status(): void {
		$alias1 = $this->test_prefix . 'bulk1';
		$alias2 = $this->test_prefix . 'bulk2';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/b1',
					'alias'          => $alias1,
				)
			)
		);
		$res2 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/b2',
					'alias'          => $alias2,
				)
			)
		);

		$id1 = $res1['body']['data']['id'];
		$id2 = $res2['body']['data']['id'];

		// Bulk trash
		$bulk_trash_res = $this->dispatch( $this->admin_request( 'DELETE', '/api/v1/urls/bulk', array(), array( 'ids' => array( $id1, $id2 ) ) ) );
		$this->assertSame( 200, $bulk_trash_res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "trashed"' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Both records must have status = trashed in database.' );

		// Bulk restore
		$bulk_restore_res = $this->dispatch( $this->admin_request( 'POST', '/api/v1/urls/restore', array(), array( 'ids' => array( $id1, $id2 ) ) ) );
		$this->assertSame( 200, $bulk_restore_res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "active"' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Both records must have status = active in database.' );
	}
	public function test_empty_trash_real_request_boundary_permanently_removes_trashed_links_only(): void {
		$active_alias  = $this->test_prefix . 'active_keep';
		$trashed_alias = $this->test_prefix . 'trashed_remove';

		$res_active  = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/keep',
					'alias'          => $active_alias,
				)
			)
		);
		$res_trashed = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/trash',
					'alias'          => $trashed_alias,
				)
			)
		);

		$active_id  = $res_active['body']['data']['id'];
		$trashed_id = $res_trashed['body']['data']['id'];

		// Trash the second link
		$this->dispatch( $this->admin_request( 'DELETE', '/api/v1/urls/' . $trashed_id ) );

		// Real empty trash request at the HTTP API boundary
		$empty_trash_req = $this->admin_request( 'DELETE', '/api/v1/urls/trash' );
		$empty_trash_res = $this->dispatch( $empty_trash_req );

		$this->assertSame( 200, $empty_trash_res['status'] );

		// Verify database state: Trashed row permanently removed
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $trashed_id ) );
		$this->assertSame( 0, (int) $stmt->fetchColumn(), 'Empty Trash must permanently remove trashed row from database.' );

		// Verify database state: Active row remains intact in database
		$stmt->execute( array( 'id' => $active_id ) );
		$this->assertSame( 1, (int) $stmt->fetchColumn(), 'Empty Trash must leave active links intact in database.' );
	}

	public function test_delete_all_real_request_boundary_permanently_removes_all_accessible_links(): void {
		$alias1 = $this->test_prefix . 'delall_1';
		$alias2 = $this->test_prefix . 'delall_2';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/d1',
					'alias'          => $alias1,
				)
			)
		);
		$res2 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/d2',
					'alias'          => $alias2,
				)
			)
		);

		$id1 = $res1['body']['data']['id'];
		$id2 = $res2['body']['data']['id'];

		// Real Delete All request at the HTTP API boundary
		$del_all_req = $this->admin_request( 'DELETE', '/api/v1/urls' );
		$del_all_res = $this->dispatch( $del_all_req );

		$this->assertSame( 200, $del_all_res['status'] );

		// Verify database state: rows permanently removed
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2)' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 0, (int) $stmt->fetchColumn(), 'Delete All must permanently remove rows from database.' );
	}

	public function test_unauthorized_user_denied_destructive_operations_with_zero_database_mutation(): void {
		$alias = $this->test_prefix . 'negative_unauth';
		$res   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/safe',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		// Unauthenticated Delete All request
		$unauth_del_all = new Request( 'DELETE', '/api/v1/urls', array(), array() );
		$unauth_res     = $this->dispatch( $unauth_del_all );
		$this->assertSame( 401, $unauth_res['status'] );

		// Unauthenticated Empty Trash request
		$unauth_empty_trash = new Request( 'DELETE', '/api/v1/urls/trash', array(), array() );
		$unauth_trash_res   = $this->dispatch( $unauth_empty_trash );
		$this->assertSame( 401, $unauth_trash_res['status'] );

		// Critical security property: zero database mutation occurred
		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 'active', $stmt->fetchColumn(), 'Unauthorized attempt must leave database completely unmutated.' );
	}
	public function test_editor_can_delete_own_link_in_database(): void {
		$alias = $this->test_prefix . 'ed-own-del';
		$res   = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ed-own',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		$del_res = $this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls/' . $id ) );
		$this->assertSame( 200, $del_res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 'trashed', $stmt->fetchColumn(), 'Editor must be able to trash own link.' );
	}

	public function test_editor_cannot_delete_admin_owned_link_in_database(): void {
		$alias = $this->test_prefix . 'admin-safe-del';
		$res   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/admin-target',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		// Editor attempts to delete admin-owned link
		$del_res = $this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls/' . $id ) );
		$this->assertSame( 403, $del_res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 'active', $stmt->fetchColumn(), 'Admin link must remain active and untrashed.' );
	}

	public function test_admin_can_delete_editor_owned_link_in_database(): void {
		$alias = $this->test_prefix . 'ed-admin-can-del';
		$res   = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ed-target',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		// Admin deletes editor-owned link
		$del_res = $this->dispatch( $this->admin_request( 'DELETE', '/api/v1/urls/' . $id ) );
		$this->assertSame( 200, $del_res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 'trashed', $stmt->fetchColumn(), 'Admin must be able to trash editor link.' );
	}
	public function test_editor_bulk_delete_mixed_ownership_mutates_only_own_links_in_database(): void {
		$admin_alias = $this->test_prefix . 'mix-admin';
		$ed_alias    = $this->test_prefix . 'mix-ed';

		$res_admin = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/adm',
					'alias'          => $admin_alias,
				)
			)
		);
		$res_ed    = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ed',
					'alias'          => $ed_alias,
				)
			)
		);

		$admin_id = $res_admin['body']['data']['id'];
		$ed_id    = $res_ed['body']['data']['id'];

		// Editor attempts bulk delete of both IDs
		$bulk_res = $this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls/bulk', array(), array( 'ids' => array( $admin_id, $ed_id ) ) ) );
		$this->assertSame( 200, $bulk_res['status'] );
		$this->assertSame( 1, $bulk_res['body']['data']['deletedCount'], 'Only Editor-owned link should be deleted.' );

		// Check database directly: Editor link is trashed
		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $ed_id ) );
		$this->assertSame( 'trashed', $stmt->fetchColumn() );

		// Check database directly: Admin link remains ACTIVE and untouched
		$stmt->execute( array( 'id' => $admin_id ) );
		$this->assertSame( 'active', $stmt->fetchColumn(), 'Admin link must remain active and untrashed.' );
	}

	public function test_editor_delete_all_removes_only_own_accessible_links_in_database(): void {
		$admin_alias = $this->test_prefix . 'delall-admin';
		$ed1_alias   = $this->test_prefix . 'delall-ed1';
		$ed2_alias   = $this->test_prefix . 'delall-ed2';

		$res_admin = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/adm',
					'alias'          => $admin_alias,
				)
			)
		);
		$res_ed1   = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ed1',
					'alias'          => $ed1_alias,
				)
			)
		);
		$res_ed2   = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ed2',
					'alias'          => $ed2_alias,
				)
			)
		);

		$admin_id = $res_admin['body']['data']['id'];
		$ed1_id   = $res_ed1['body']['data']['id'];
		$ed2_id   = $res_ed2['body']['data']['id'];

		// Editor calls Delete All
		$del_all_res = $this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls' ) );
		$this->assertSame( 200, $del_all_res['status'] );
		$this->assertSame( 2, $del_all_res['body']['data']['deletedCount'] );

		// Editor's links are permanently removed
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2)' );
		$stmt->execute(
			array(
				'id1' => $ed1_id,
				'id2' => $ed2_id,
			)
		);
		$this->assertSame( 0, (int) $stmt->fetchColumn(), 'Editor links must be permanently removed.' );

		// Admin's link remains INTACT in database
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id = :id AND status = "active"' );
		$stmt->execute( array( 'id' => $admin_id ) );
		$this->assertSame( 1, (int) $stmt->fetchColumn(), 'Admin link must survive Editor Delete All.' );
	}

	public function test_editor_denied_empty_trash_with_zero_database_mutation(): void {
		$admin_alias = $this->test_prefix . 'trash-adm';
		$ed_alias    = $this->test_prefix . 'trash-ed';

		$res_admin = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/adm',
					'alias'          => $admin_alias,
				)
			)
		);
		$res_ed    = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ed',
					'alias'          => $ed_alias,
				)
			)
		);

		$admin_id = $res_admin['body']['data']['id'];
		$ed_id    = $res_ed['body']['data']['id'];

		// Trash both
		$this->dispatch( $this->admin_request( 'DELETE', '/api/v1/urls/' . $admin_id ) );
		$this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls/' . $ed_id ) );

		// Editor calls Empty Trash -> must be denied with 403 Forbidden
		$empty_res = $this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls/trash' ) );
		$this->assertSame( 403, $empty_res['status'] );

		// Both trashed links must remain in database
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "trashed"' );
		$stmt->execute(
			array(
				'id1' => $admin_id,
				'id2' => $ed_id,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Empty Trash denial must produce zero database mutations.' );
	}

	public function test_editor_cannot_edit_other_user_link_in_database(): void {
		$alias   = $this->test_prefix . 'edit-admin';
		$res     = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/initial',
					'title'          => 'Original',
					'alias'          => $alias,
				)
			)
		);
		$link_id = $res['body']['data']['id'];

		// Editor attempts to edit admin-owned link
		$edit_res = $this->dispatch(
			$this->editor_request(
				'PUT',
				'/api/v1/urls/' . $link_id,
				array(),
				array(
					'destinationUrl' => 'https://example.com/hijack',
					'title'          => 'Hijacked',
				)
			)
		);
		$this->assertSame( 403, $edit_res['status'] );

		// Direct database assertion: original values untouched
		$stmt = $this->pdo->prepare( 'SELECT destination_url, title FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $link_id ) );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( 'https://example.com/initial', $row['destination_url'] );
		$this->assertSame( 'Original', $row['title'] );
	}
}
