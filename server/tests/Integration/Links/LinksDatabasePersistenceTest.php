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
use PeakURL\Features\Links\Health\Checker;
use PeakURL\Features\Links\Health\Context;
use PeakURL\Features\Links\Health\Probe;
use PeakURL\Features\Links\Health\Resolver;
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

		// Ensure another editor user (id: 8888) exists in peakurl_users
		$this->pdo->exec(
			"INSERT INTO peakurl_users (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (8888, 'other_editor', 'other_editor@example.com', 'Other', 'Editor', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'editor', 1, NOW(), NOW())
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

		$resolver            = new Resolver( static fn() => array( '93.184.216.34' ) );
		$probe               = new Probe(
			new Context( $config ),
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 50,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$mock_health_checker = new Checker( $resolver, $probe );

		$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );

		$this->app    = new Application( $connection, $config, $mock_health_checker );
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
			$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );
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

	public function test_repository_trash_url_direct_execution(): void {
		$alias      = $this->test_prefix . 'repo_trash';
		$create_res = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/direct-repo-trash',
					'alias'          => $alias,
				)
			)
		);

		$link_id = $create_res['body']['data']['id'];

		$config     = Configuration::get_current();
		$connection = Connection::get_instance( $config );
		$db         = new \PeakURL\Services\Database\PeakURL_DB( $connection );
		$roles      = new \PeakURL\Core\Auth\Roles();
		$auth       = new \PeakURL\Core\Auth\Authorization( $roles );
		$links_api  = new \PeakURL\Api\LinksApi( $db );
		$repository = new \PeakURL\Features\Links\Repository( $db, $links_api, $auth );

		$now = \PeakURL\Utils\Date::now();

		// First call: transitions active -> trashed, returns true.
		$trashed = $repository->trash_url( $link_id, $now );
		$this->assertTrue( $trashed, 'Repository::trash_url() must return true when active row is updated.' );

		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $link_id ) );
		$this->assertSame( 'trashed', $stmt->fetchColumn() );

		// Second call: row is already trashed, returns false.
		$re_trashed = $repository->trash_url( $link_id, $now );
		$this->assertFalse( $re_trashed, 'Repository::trash_url() must return false when row is already trashed.' );
	}

	public function test_expire_due_links_full_and_zero_updates(): void {
		$config     = Configuration::get_current();
		$connection = Connection::get_instance( $config );
		$db         = new \PeakURL\Services\Database\PeakURL_DB( $connection );
		$roles      = new \PeakURL\Core\Auth\Roles();
		$auth       = new \PeakURL\Core\Auth\Authorization( $roles );
		$links_api  = new \PeakURL\Api\LinksApi( $db );
		$repository = new \PeakURL\Features\Links\Repository( $db, $links_api, $auth );

		$past_time = '2020-01-01 00:00:00';
		$now       = \PeakURL\Utils\Date::now();

		$id1 = bin2hex( random_bytes( 10 ) );
		$id2 = bin2hex( random_bytes( 10 ) );

		$insert_stmt = $this->pdo->prepare(
			'INSERT INTO peakurl_urls (id, user_id, short_code, alias, destination_url, title, status, expires_at, created_at, updated_at)
			VALUES (:id, 1, :code, :alias, :dest, :title, :status, :expires_at, :created_at, :updated_at)'
		);

		$insert_stmt->execute(
			array(
				'id'         => $id1,
				'code'       => $this->test_prefix . 'exp1',
				'alias'      => $this->test_prefix . 'exp1',
				'dest'       => 'https://example.com/exp1',
				'title'      => 'Exp 1',
				'status'     => 'active',
				'expires_at' => $past_time,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$insert_stmt->execute(
			array(
				'id'         => $id2,
				'code'       => $this->test_prefix . 'exp2',
				'alias'      => $this->test_prefix . 'exp2',
				'dest'       => 'https://example.com/exp2',
				'title'      => 'Exp 2',
				'status'     => 'active',
				'expires_at' => $past_time,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		// Full update: both id1 and id2 are active and due.
		$expired     = $repository->expire_due_links( 100 );
		$expired_ids = array_column( $expired, 'id' );
		$this->assertContains( $id1, $expired_ids, 'Full update must include id1' );
		$this->assertContains( $id2, $expired_ids, 'Full update must include id2' );

		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id1 OR id = :id2 ORDER BY id ASC' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$statuses = $stmt->fetchAll( \PDO::FETCH_COLUMN );
		$this->assertSame( array( 'expired', 'expired' ), $statuses );

		// Zero update on immediate re-run: both are now expired, neither should be re-expired.
		$re_run     = $repository->expire_due_links( 100 );
		$re_run_ids = array_column( $re_run, 'id' );
		$this->assertNotContains( $id1, $re_run_ids );
		$this->assertNotContains( $id2, $re_run_ids );
	}

	public function test_expire_due_links_skips_link_modified_between_selection_and_update(): void {
		$config     = Configuration::get_current();
		$connection = Connection::get_instance( $config );
		$roles      = new \PeakURL\Core\Auth\Roles();
		$auth       = new \PeakURL\Core\Auth\Authorization( $roles );

		$past_time = '2020-01-01 00:00:00';
		$now       = \PeakURL\Utils\Date::now();

		$id1 = bin2hex( random_bytes( 10 ) );
		$id2 = bin2hex( random_bytes( 10 ) );

		$insert_stmt = $this->pdo->prepare(
			'INSERT INTO peakurl_urls (id, user_id, short_code, alias, destination_url, title, status, expires_at, created_at, updated_at)
			VALUES (:id, 1, :code, :alias, :dest, :title, :status, :expires_at, :created_at, :updated_at)'
		);

		$insert_stmt->execute(
			array(
				'id'         => $id1,
				'code'       => $this->test_prefix . 'conc1',
				'alias'      => $this->test_prefix . 'conc1',
				'dest'       => 'https://example.com/conc1',
				'title'      => 'Conc 1',
				'status'     => 'active',
				'expires_at' => $past_time,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$insert_stmt->execute(
			array(
				'id'         => $id2,
				'code'       => $this->test_prefix . 'conc2',
				'alias'      => $this->test_prefix . 'conc2',
				'dest'       => 'https://example.com/conc2',
				'title'      => 'Conc 2',
				'status'     => 'active',
				'expires_at' => $past_time,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		// Intercepting DB wrapper: after candidate links are selected, before updating id2,
		// an out-of-band query changes id2's status in the database to 'trashed'.
		$raw_pdo        = $this->pdo;
		$interceptor_db = new class( $connection, $raw_pdo, $id2 ) extends \PeakURL\Services\Database\PeakURL_DB {
			private \PDO $pdo;
			private string $target_id;
			private bool $intercepted = false;

			public function __construct( $connection, \PDO $pdo, string $target_id ) {
				parent::__construct( $connection );
				$this->pdo       = $pdo;
				$this->target_id = $target_id;
			}

			public function update( string $table, array $data, array $where = array() ): int {
				if ( ! $this->intercepted && 'urls' === $table ) {
					$this->intercepted = true;
					$stmt              = $this->pdo->prepare( 'UPDATE peakurl_urls SET status = :status WHERE id = :id' );
					$stmt->execute(
						array(
							'status' => 'trashed',
							'id'     => $this->target_id,
						)
					);
				}
				return parent::update( $table, $data, $where );
			}
		};

		$links_api  = new \PeakURL\Api\LinksApi( $interceptor_db );
		$repository = new \PeakURL\Features\Links\Repository( $interceptor_db, $links_api, $auth );

		$expired_links = $repository->expire_due_links( 100 );
		$expired_ids   = array_column( $expired_links, 'id' );

		// id1 was still 'active' when update executed, so it must be transitioned and returned.
		$this->assertContains( $id1, $expired_ids, 'id1 must be transitioned to expired' );

		// id2 had its status changed to 'trashed' between selection and update,
		// so its conditional UPDATE (WHERE status = 'active') affected 0 rows and was skipped.
		$this->assertNotContains( $id2, $expired_ids, 'id2 must be skipped because status was modified after selection' );

		// Verify database state: id1 is 'expired', id2 remains 'trashed'.
		$check_stmt = $this->pdo->prepare( 'SELECT id, status FROM peakurl_urls WHERE id IN (:id1, :id2)' );
		$check_stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$rows = $check_stmt->fetchAll( \PDO::FETCH_KEY_PAIR );
		$this->assertSame( 'expired', $rows[ $id1 ] );
		$this->assertSame( 'trashed', $rows[ $id2 ] );
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
		$del_all_req = $this->admin_request( 'DELETE', '/api/v1/urls', array(), array( 'mode' => 'permanent' ) );
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
		$unauth_del_all = new Request( 'DELETE', '/api/v1/urls', array(), array( 'mode' => 'trash' ) );
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

		// Editor attempts bulk delete of both IDs (mixed ownership) -> rejected as whole with 403 Forbidden
		$bulk_res = $this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls/bulk', array(), array( 'ids' => array( $admin_id, $ed_id ) ) ) );
		$this->assertSame( 403, $bulk_res['status'], 'Mixed-ownership bulk delete batch must be rejected with 403.' );

		// Check database directly: both links remain ACTIVE and untouched
		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $ed_id ) );
		$this->assertSame( 'active', $stmt->fetchColumn(), 'Editor link must remain active after 403 batch rejection.' );

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
		$del_all_res = $this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls', array(), array( 'mode' => 'trash' ) ) );
		$this->assertSame( 200, $del_all_res['status'] );
		$this->assertSame( 2, $del_all_res['body']['data']['affectedCount'] );

		// Editor's active links are moved to TRASH (NOT permanently removed)
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "trashed"' );
		$stmt->execute(
			array(
				'id1' => $ed1_id,
				'id2' => $ed2_id,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Editor active links must be moved to trash, not permanently deleted.' );

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

	public function test_editor_can_view_administrator_created_link_in_database(): void {
		$admin_alias = $this->test_prefix . 'view-admin';
		$res_admin   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/adm-view',
					'title'          => 'Admin Created Link',
					'alias'          => $admin_alias,
				)
			)
		);
		$admin_id    = $res_admin['body']['data']['id'];

		// Editor can view administrator-created link
		$view_res = $this->dispatch( $this->editor_request( 'GET', '/api/v1/urls/' . $admin_id ) );
		$this->assertSame( 200, $view_res['status'] );
		$this->assertSame( 'Admin Created Link', $view_res['body']['data']['title'] );
	}

	public function test_editor_can_edit_administrator_created_link_in_database(): void {
		$alias   = $this->test_prefix . 'edit-admin-ok';
		$res     = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/initial',
					'title'          => 'Original Admin Title',
					'alias'          => $alias,
				)
			)
		);
		$link_id = $res['body']['data']['id'];

		// Editor attempts to edit admin-created link without edit_any_link -> 403 Forbidden
		$denied_res = $this->dispatch(
			$this->editor_request(
				'PUT',
				'/api/v1/urls/' . $link_id,
				array(),
				array(
					'destinationUrl' => 'https://example.com/editor-updated',
					'title'          => 'Editor Updated Title',
				)
			)
		);
		$this->assertSame( 403, $denied_res['status'] );

		// Grant edit_any_link to editor via admin roles endpoint
		$grant_res = $this->dispatch(
			$this->admin_request(
				'PUT',
				'/api/v1/roles/editor',
				array(),
				array(
					'capabilities' => array(
						'edit_any_link' => true,
					),
				)
			)
		);
		$this->assertSame( 200, $grant_res['status'] );

		// Editor edits admin-created link with edit_any_link -> permitted
		$edit_res = $this->dispatch(
			$this->editor_request(
				'PUT',
				'/api/v1/urls/' . $link_id,
				array(),
				array(
					'destinationUrl' => 'https://example.com/editor-updated',
					'title'          => 'Editor Updated Title',
				)
			)
		);
		$this->assertSame( 200, $edit_res['status'] );

		// Direct database assertion: values updated and user_id ownership preserved
		$stmt = $this->pdo->prepare( 'SELECT destination_url, title, user_id FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $link_id ) );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( 'https://example.com/editor-updated', $row['destination_url'] );
		$this->assertSame( 'Editor Updated Title', $row['title'] );
		$this->assertSame( '1', (string) $row['user_id'], 'Ownership must remain with the Administrator.' );

		// Reset editor role back to defaults
		$this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/roles/editor/reset',
				array(),
				array()
			)
		);
	}

	public function test_editor_cannot_edit_other_editor_link_in_database(): void {
		$alias = $this->test_prefix . 'other-ed-link';

		// Seed link owned by another editor (user_id: 8888)
		$link_id = bin2hex( random_bytes( 16 ) );
		$stmt    = $this->pdo->prepare(
			'INSERT INTO peakurl_urls (id, user_id, short_code, alias, destination_url, title, status, created_at, updated_at)
			VALUES (:id, 8888, :code, :alias, "https://example.com/other-ed", "Other Editor Link", "active", NOW(), NOW())'
		);
		$stmt->execute(
			array(
				'id'    => $link_id,
				'code'  => $alias,
				'alias' => $alias,
			)
		);

		// Editor (user_id: 9999) attempts to edit another editor's link -> denied
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

		$stmt = $this->pdo->prepare( 'SELECT destination_url, title FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $link_id ) );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( 'https://example.com/other-ed', $row['destination_url'] );
		$this->assertSame( 'Other Editor Link', $row['title'] );
	}

	public function test_editor_cannot_permanently_delete_trashed_link_in_database(): void {
		$alias = $this->test_prefix . 'ed-trashed-perm';
		$res   = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ed-perm-test',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		// Editor moves own link to trash
		$this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls/' . $id ) );

		// Editor attempts permanent deletion of trashed link -> must be denied 403
		$del_perm_res = $this->dispatch( $this->editor_request( 'DELETE', '/api/v1/urls/' . $id ) );
		$this->assertSame( 403, $del_perm_res['status'] );

		// Database assertion: link remains intact in database as trashed
		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 'trashed', $stmt->fetchColumn(), 'Editor must not be able to permanently delete trashed links.' );
	}

	public function test_bulk_create_and_export_urls_with_full_metadata(): void {
		$alias1 = $this->test_prefix . 'bulk-full-1';
		$alias2 = $this->test_prefix . 'bulk-full-2';

		$bulk_req = $this->admin_request(
			'POST',
			'/api/v1/urls/bulk',
			array(),
			array(
				'urls' => array(
					array(
						'destinationUrl'    => 'https://example.com/promo',
						'alias'             => $alias1,
						'title'             => 'Promo Link',
						'status'            => 'active',
						'password'          => 'bulksecret123',
						'expiresAt'         => '2030-12-31',
						'socialTitle'       => 'Promo Social Title',
						'socialDescription' => 'Promo Social Description',
						'socialImageUrl'    => 'https://example.com/promo.png',
						'utmSource'         => 'newsletter',
						'utmMedium'         => 'email',
						'utmCampaign'       => 'summer_sale',
						'utmTerm'           => 'discount',
						'utmContent'        => 'banner_link',
					),
					array(
						'destinationUrl'    => 'https://example.com/blog',
						'alias'             => $alias2,
						'title'             => 'Blog Link',
						'status'            => 'inactive',
						'socialTitle'       => 'Blog Social Title',
						'socialDescription' => 'Blog Social Description',
						'socialImageUrl'    => 'https://example.com/blog.png',
						'utmSource'         => 'twitter',
						'utmMedium'         => 'social',
						'utmCampaign'       => 'launch',
					),
				),
			)
		);

		$bulk_res = $this->dispatch( $bulk_req );
		$this->assertSame( 200, $bulk_res['status'] );
		$this->assertCount( 2, $bulk_res['body']['data']['results'] );
		$this->assertCount( 0, $bulk_res['body']['data']['errors'] );

		// Verify database persistence directly
		$stmt = $this->pdo->prepare(
			'SELECT * FROM peakurl_urls WHERE alias IN (:alias1, :alias2) ORDER BY alias ASC'
		);
		$stmt->execute(
			array(
				'alias1' => $alias1,
				'alias2' => $alias2,
			)
		);
		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
		$this->assertCount( 2, $rows );

		$row1 = $rows[0];
		$this->assertSame( $alias1, $row1['alias'] );
		$this->assertSame( 'active', $row1['status'] );
		$this->assertSame( 'Promo Social Title', $row1['social_title'] );
		$this->assertSame( 'Promo Social Description', $row1['social_description'] );
		$this->assertSame( 'https://example.com/promo.png', $row1['social_image_url'] );
		$this->assertSame( 'newsletter', $row1['utm_source'] );
		$this->assertSame( 'email', $row1['utm_medium'] );
		$this->assertSame( 'summer_sale', $row1['utm_campaign'] );
		$this->assertSame( 'discount', $row1['utm_term'] );
		$this->assertSame( 'banner_link', $row1['utm_content'] );
		$this->assertNotEmpty( $row1['password_value'] );
		$this->assertNotSame( 'bulksecret123', $row1['password_value'], 'Password must be hashed at rest.' );

		$row2 = $rows[1];
		$this->assertSame( $alias2, $row2['alias'] );
		$this->assertSame( 'inactive', $row2['status'] );
		$this->assertSame( 'Blog Social Title', $row2['social_title'] );
		$this->assertSame( 'Blog Social Description', $row2['social_description'] );
		$this->assertSame( 'https://example.com/blog.png', $row2['social_image_url'] );
		$this->assertSame( 'twitter', $row2['utm_source'] );
		$this->assertSame( 'social', $row2['utm_medium'] );
		$this->assertSame( 'launch', $row2['utm_campaign'] );

		// Verify export API returns all full metadata
		$export_res = $this->dispatch( $this->admin_request( 'GET', '/api/v1/urls/export' ) );
		$this->assertSame( 200, $export_res['status'] );

		$items_by_alias = array();
		foreach ( $export_res['body']['data']['items'] as $item ) {
			$items_by_alias[ $item['alias'] ] = $item;
		}

		$this->assertArrayHasKey( $alias1, $items_by_alias );
		$exp1 = $items_by_alias[ $alias1 ];
		$this->assertSame( 'active', $exp1['status'] );
		$this->assertSame( 'Promo Social Title', $exp1['socialPreview']['title'] );
		$this->assertSame( 'Promo Social Description', $exp1['socialPreview']['description'] );
		$this->assertSame( 'https://example.com/promo.png', $exp1['socialPreview']['imageUrl'] );
		$this->assertSame( 'newsletter', $exp1['utmSource'] );
		$this->assertSame( 'email', $exp1['utmMedium'] );
		$this->assertSame( 'summer_sale', $exp1['utmCampaign'] );
		$this->assertSame( 'discount', $exp1['utmTerm'] );
		$this->assertSame( 'banner_link', $exp1['utmContent'] );

		$this->assertArrayHasKey( $alias2, $items_by_alias );
		$exp2 = $items_by_alias[ $alias2 ];
		$this->assertSame( 'inactive', $exp2['status'] );
		$this->assertSame( 'Blog Social Title', $exp2['socialPreview']['title'] );
		$this->assertSame( 'Blog Social Description', $exp2['socialPreview']['description'] );
		$this->assertSame( 'https://example.com/blog.png', $exp2['socialPreview']['imageUrl'] );
		$this->assertSame( 'twitter', $exp2['utmSource'] );
		$this->assertSame( 'social', $exp2['utmMedium'] );
		$this->assertSame( 'launch', $exp2['utmCampaign'] );
	}

	public function test_bulk_restore_mixed_ownership_fails_with_403_and_mutates_zero_rows(): void {
		$alias1 = $this->test_prefix . 'restore_own';
		$alias2 = $this->test_prefix . 'restore_other';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ro1',
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
					'destinationUrl' => 'https://example.com/ro2',
					'alias'          => $alias2,
				)
			)
		);

		$id1 = $res1['body']['data']['id'];
		$id2 = $res2['body']['data']['id'];

		// Assign link 1 to editor (9999) and link 2 to other editor (8888), both trashed
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'trashed' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888, status = 'trashed' WHERE id = '{$id2}'" );

		// Editor (9999) attempts to bulk restore both links
		$req = $this->editor_request(
			'POST',
			'/api/v1/urls/restore',
			array(),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 403, $res['status'] );

		// Verify zero rows were restored in the database
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "trashed"' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Both records must remain trashed in database after 403 denial.' );
	}

	public function test_bulk_delete_trashed_links_succeeds_when_user_has_delete_links_even_without_trash_links(): void {
		// Grant editor delete_links=true and revoke trash_links=false in settings
		$editor_caps = array(
			'editor' => array(
				'trash_links'  => false,
				'delete_links' => true,
				'view_links'   => true,
				'create_links' => true,
				'edit_links'   => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, autoload, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $editor_caps ) . "', 1, NOW())
			ON DUPLICATE KEY UPDATE setting_value = '" . json_encode( $editor_caps ) . "'"
		);

		$alias = $this->test_prefix . 'trash_del';
		$res   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/td1',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		// Assign to editor (9999) and set status to trashed
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'trashed' WHERE id = '{$id}'" );

		// Call bulk delete without force=true; because link is already trashed, effective action is permanent delete
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array(),
			array( 'ids' => array( $id ) )
		);

		$del_res = $this->dispatch( $req );
		$this->assertSame( 200, $del_res['status'] );

		// Verify record is permanently removed from database table
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 0, (int) $stmt->fetchColumn(), 'Record must be permanently deleted from database table.' );

		// Cleanup setting
		$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );
	}

	public function test_bulk_delete_mixed_ownership_fails_with_403_and_mutates_zero_rows(): void {
		$alias1 = $this->test_prefix . 'del_own';
		$alias2 = $this->test_prefix . 'del_other';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/do1',
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
					'destinationUrl' => 'https://example.com/do2',
					'alias'          => $alias2,
				)
			)
		);

		$id1 = $res1['body']['data']['id'];
		$id2 = $res2['body']['data']['id'];

		// Assign link 1 to editor (9999) and link 2 to other editor (8888), both active
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'active' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888, status = 'active' WHERE id = '{$id2}'" );

		// Editor (9999) attempts to bulk trash both links
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array(),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 403, $res['status'] );

		// Verify zero rows were mutated in the database
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "active"' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Both records must remain active in database after 403 denial.' );
	}

	public function test_bulk_delete_without_force_fails_with_403_when_editor_has_delete_links_but_not_trash_links(): void {
		$editor_caps = array(
			'editor' => array(
				'trash_links'  => false,
				'delete_links' => true,
				'view_links'   => true,
				'create_links' => true,
				'edit_links'   => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, autoload, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $editor_caps ) . "', 1, NOW())
			ON DUPLICATE KEY UPDATE setting_value = '" . json_encode( $editor_caps ) . "'"
		);

		$alias = $this->test_prefix . 'no_trash_act';
		$res   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/nta',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'active' WHERE id = '{$id}'" );

		// Editor attempts bulk delete without force on active link -> 403 Forbidden because trash_links=false
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array(),
			array( 'ids' => array( $id ) )
		);

		$del_res = $this->dispatch( $req );
		$this->assertSame( 403, $del_res['status'] );

		// Verify record remains ACTIVE and untouched in database table
		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 'active', $stmt->fetchColumn(), 'Record must remain active when user lacks trash_links.' );

		$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );
	}

	public function test_bulk_delete_with_force_succeeds_when_editor_has_delete_links_even_without_trash_links(): void {
		$editor_caps = array(
			'editor' => array(
				'trash_links'  => false,
				'delete_links' => true,
				'view_links'   => true,
				'create_links' => true,
				'edit_links'   => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, autoload, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $editor_caps ) . "', 1, NOW())
			ON DUPLICATE KEY UPDATE setting_value = '" . json_encode( $editor_caps ) . "'"
		);

		$alias = $this->test_prefix . 'force_act';
		$res   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/fa',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'active' WHERE id = '{$id}'" );

		// Editor calls bulk delete with force=true on active link -> succeeds because delete_links=true
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array( 'force' => 'true' ),
			array( 'ids' => array( $id ) )
		);

		$del_res = $this->dispatch( $req );
		$this->assertSame( 200, $del_res['status'] );

		// Verify record is permanently removed from database table
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 0, (int) $stmt->fetchColumn(), 'Record must be permanently deleted from database table.' );

		$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );
	}

	public function test_bulk_delete_trashed_links_without_force_fails_with_403_when_editor_has_trash_links_but_not_delete_links(): void {
		// Default editor role has trash_links=true and delete_links=false
		$alias = $this->test_prefix . 'trash_no_del';
		$res   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/tnd',
					'alias'          => $alias,
				)
			)
		);
		$id    = $res['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'trashed' WHERE id = '{$id}'" );

		// Bulk delete on trashed links without force is permanent deletion -> requires delete_links -> 403 Forbidden
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array(),
			array( 'ids' => array( $id ) )
		);

		$del_res = $this->dispatch( $req );
		$this->assertSame( 403, $del_res['status'] );

		// Verify record remains in database as trashed
		$stmt = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 'trashed', $stmt->fetchColumn(), 'Record must remain trashed in database after 403 denial.' );
	}

	public function test_bulk_delete_with_force_fails_with_403_when_mixed_ownership_and_user_lacks_delete_any_link(): void {
		$editor_caps = array(
			'editor' => array(
				'delete_links'    => true,
				'delete_any_link' => false,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, autoload, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $editor_caps ) . "', 1, NOW())
			ON DUPLICATE KEY UPDATE setting_value = '" . json_encode( $editor_caps ) . "'"
		);

		$alias1 = $this->test_prefix . 'force_mix1';
		$alias2 = $this->test_prefix . 'force_mix2';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/fm1',
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
					'destinationUrl' => 'https://example.com/fm2',
					'alias'          => $alias2,
				)
			)
		);
		$id1  = $res1['body']['data']['id'];
		$id2  = $res2['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'active' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888, status = 'active' WHERE id = '{$id2}'" );

		// Force permanent delete with mixed ownership without delete_any_link -> 403 Forbidden
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array( 'force' => 'true' ),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 403, $res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2)' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Both rows must remain untouched.' );

		$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );
	}

	public function test_bulk_delete_with_force_succeeds_when_mixed_ownership_and_user_has_delete_any_link(): void {
		$editor_caps = array(
			'editor' => array(
				'delete_links'    => true,
				'delete_any_link' => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, autoload, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $editor_caps ) . "', 1, NOW())
			ON DUPLICATE KEY UPDATE setting_value = '" . json_encode( $editor_caps ) . "'"
		);

		$alias1 = $this->test_prefix . 'force_any1';
		$alias2 = $this->test_prefix . 'force_any2';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/fa1',
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
					'destinationUrl' => 'https://example.com/fa2',
					'alias'          => $alias2,
				)
			)
		);
		$id1  = $res1['body']['data']['id'];
		$id2  = $res2['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'active' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888, status = 'active' WHERE id = '{$id2}'" );

		// Force permanent delete with mixed ownership with delete_any_link -> 200 OK
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array( 'force' => 'true' ),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 200, $res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2)' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 0, (int) $stmt->fetchColumn(), 'Both rows must be permanently deleted from database.' );

		$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );
	}

	public function test_bulk_delete_without_force_fails_with_403_when_mixed_ownership_even_with_delete_any_link(): void {
		$editor_caps = array(
			'editor' => array(
				'trash_links'     => true,
				'delete_links'    => true,
				'delete_any_link' => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, autoload, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $editor_caps ) . "', 1, NOW())
			ON DUPLICATE KEY UPDATE setting_value = '" . json_encode( $editor_caps ) . "'"
		);

		$alias1 = $this->test_prefix . 'trash_cross1';
		$alias2 = $this->test_prefix . 'trash_cross2';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/tc1',
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
					'destinationUrl' => 'https://example.com/tc2',
					'alias'          => $alias2,
				)
			)
		);
		$id1  = $res1['body']['data']['id'];
		$id2  = $res2['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'active' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888, status = 'active' WHERE id = '{$id2}'" );

		// Trash operation across accounts is forbidden for non-admins even with delete_any_link -> 403 Forbidden
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array(),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 403, $res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "active"' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Both rows must remain active and untrashed.' );

		$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );
	}

	public function test_bulk_restore_mixed_ownership_fails_with_403_even_with_edit_any_link(): void {
		$editor_caps = array(
			'editor' => array(
				'edit_links'    => true,
				'edit_any_link' => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, autoload, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $editor_caps ) . "', 1, NOW())
			ON DUPLICATE KEY UPDATE setting_value = '" . json_encode( $editor_caps ) . "'"
		);

		$alias1 = $this->test_prefix . 'rest_cross1';
		$alias2 = $this->test_prefix . 'rest_cross2';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/rc1',
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
					'destinationUrl' => 'https://example.com/rc2',
					'alias'          => $alias2,
				)
			)
		);
		$id1  = $res1['body']['data']['id'];
		$id2  = $res2['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'trashed' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888, status = 'trashed' WHERE id = '{$id2}'" );

		// Restore across accounts is forbidden for non-admins even with edit_any_link -> 403 Forbidden
		$req = $this->editor_request(
			'POST',
			'/api/v1/urls/restore',
			array(),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 403, $res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "trashed"' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Both rows must remain trashed in database.' );

		$this->pdo->exec( "DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'" );
	}

	public function test_administrator_bulk_delete_across_multiple_owners_succeeds(): void {
		$alias1 = $this->test_prefix . 'adm_multi1';
		$alias2 = $this->test_prefix . 'adm_multi2';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/am1',
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
					'destinationUrl' => 'https://example.com/am2',
					'alias'          => $alias2,
				)
			)
		);
		$id1  = $res1['body']['data']['id'];
		$id2  = $res2['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'active' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888, status = 'active' WHERE id = '{$id2}'" );

		// Admin bulk trash multiple owners -> succeeds
		$req = $this->admin_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array(),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 200, $res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2) AND status = "trashed"' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 2, (int) $stmt->fetchColumn(), 'Admin must trash both links regardless of owner.' );
	}

	public function test_bulk_delete_mixed_active_and_trashed_links_without_force_fails_with_400(): void {
		$alias1 = $this->test_prefix . 'mix_act';
		$alias2 = $this->test_prefix . 'mix_trsh';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/ma',
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
					'destinationUrl' => 'https://example.com/mt',
					'alias'          => $alias2,
				)
			)
		);
		$id1  = $res1['body']['data']['id'];
		$id2  = $res2['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'active' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 9999, status = 'trashed' WHERE id = '{$id2}'" );

		// Mixed active and trashed selection without force -> 400 Bad Request
		$req = $this->editor_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array(),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 400, $res['status'] );

		// Verify zero mutation
		$stmt1 = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt1->execute( array( 'id' => $id1 ) );
		$this->assertSame( 'active', $stmt1->fetchColumn(), 'Active link must remain active.' );

		$stmt2 = $this->pdo->prepare( 'SELECT status FROM peakurl_urls WHERE id = :id' );
		$stmt2->execute( array( 'id' => $id2 ) );
		$this->assertSame( 'trashed', $stmt2->fetchColumn(), 'Trashed link must remain trashed.' );
	}

	public function test_bulk_delete_mixed_active_and_trashed_links_with_force_succeeds(): void {
		$alias1 = $this->test_prefix . 'mix_f_act';
		$alias2 = $this->test_prefix . 'mix_f_trsh';

		$res1 = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/mfa',
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
					'destinationUrl' => 'https://example.com/mft',
					'alias'          => $alias2,
				)
			)
		);
		$id1  = $res1['body']['data']['id'];
		$id2  = $res2['body']['data']['id'];

		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 1, status = 'active' WHERE id = '{$id1}'" );
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 1, status = 'trashed' WHERE id = '{$id2}'" );

		// Mixed active and trashed selection with force=true -> permanent delete of all
		$req = $this->admin_request(
			'DELETE',
			'/api/v1/urls/bulk',
			array( 'force' => 'true' ),
			array( 'ids' => array( $id1, $id2 ) )
		);

		$res = $this->dispatch( $req );
		$this->assertSame( 200, $res['status'] );

		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM peakurl_urls WHERE id IN (:id1, :id2)' );
		$stmt->execute(
			array(
				'id1' => $id1,
				'id2' => $id2,
			)
		);
		$this->assertSame( 0, (int) $stmt->fetchColumn(), 'Both rows must be permanently deleted.' );
	}

	public function test_analytics_matrix_cross_account_isolation_and_redaction(): void {
		// 1. Seed 3 links across 3 owners:
		// Link A: Admin A (id: 1)
		$alias_a = $this->test_prefix . 'analytics_a';
		$res_a   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/dest-a',
					'alias'          => $alias_a,
					'title'          => 'Link A Admin',
				)
			)
		);
		$id_a    = $res_a['body']['data']['id'];

		// Link B: Editor B (id: 9999)
		$alias_b = $this->test_prefix . 'analytics_b';
		$res_b   = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/dest-b',
					'alias'          => $alias_b,
					'title'          => 'Link B Editor',
				)
			)
		);
		$id_b    = $res_b['body']['data']['id'];

		// Link C: Other Editor / Custom Role C (id: 8888)
		$alias_c = $this->test_prefix . 'analytics_c';
		$res_c   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/dest-c',
					'alias'          => $alias_c,
					'title'          => 'Link C Other',
				)
			)
		);
		$id_c    = $res_c['body']['data']['id'];
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888 WHERE id = '{$id_c}'" );

		// 2. Insert clicks into peakurl_clicks:
		// Link A: 12 total clicks, 8 unique visitors
		for ( $i = 1; $i <= 8; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_a_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_a}', NOW() - INTERVAL {$i} MINUTE, '{$vis}')"
			);
		}
		for ( $i = 1; $i <= 4; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_a_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_a}', NOW() - INTERVAL {$i} HOUR, '{$vis}')"
			);
		}

		// Link B: 3 total clicks, 2 unique visitors
		for ( $i = 1; $i <= 2; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_b_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_b}', NOW() - INTERVAL {$i} MINUTE, '{$vis}')"
			);
		}
		$click_id = bin2hex( random_bytes( 16 ) );
		$vis      = hash( 'sha256', 'vis_b_1' );
		$this->pdo->exec(
			"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
			VALUES ('{$click_id}', '{$id_b}', NOW() - INTERVAL 1 HOUR, '{$vis}')"
		);

		// Link C: 7 total clicks, 5 unique visitors
		for ( $i = 1; $i <= 5; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_c_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_c}', NOW() - INTERVAL {$i} MINUTE, '{$vis}')"
			);
		}
		for ( $i = 1; $i <= 2; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_c_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_c}', NOW() - INTERVAL {$i} HOUR, '{$vis}')"
			);
		}

		// 3. Admin Request: Sees all metrics in list, detail, and export; aggregate metadata includes site-wide totals
		$admin_list_req = $this->admin_request( 'GET', '/api/v1/urls', array( 'search' => $this->test_prefix ) );
		$admin_list_res = $this->dispatch( $admin_list_req );
		$this->assertSame( 200, $admin_list_res['status'] );
		$admin_items = array_column( $admin_list_res['body']['data']['items'], null, 'id' );

		$this->assertSame( 12, $admin_items[ $id_a ]['clicks'] );
		$this->assertSame( 8, $admin_items[ $id_a ]['uniqueClicks'] );
		$this->assertSame( 3, $admin_items[ $id_b ]['clicks'] );
		$this->assertSame( 2, $admin_items[ $id_b ]['uniqueClicks'] );
		$this->assertSame( 7, $admin_items[ $id_c ]['clicks'] );
		$this->assertSame( 5, $admin_items[ $id_c ]['uniqueClicks'] );

		$this->assertSame( 22, $admin_list_res['body']['data']['meta']['totalClicks'] );
		$this->assertSame( 15, $admin_list_res['body']['data']['meta']['uniqueClicks'] );
		$this->assertSame( 3, $admin_list_res['body']['data']['meta']['activeLinks'] );

		// Admin can sort by clicks and uniqueClicks
		$admin_sort_clicks = $this->dispatch(
			$this->admin_request(
				'GET',
				'/api/v1/urls',
				array(
					'search'    => $this->test_prefix,
					'sortBy'    => 'clicks',
					'sortOrder' => 'desc',
				)
			)
		);
		$this->assertSame( 200, $admin_sort_clicks['status'] );
		$this->assertSame( $id_a, $admin_sort_clicks['body']['data']['items'][0]['id'] );

		$admin_sort_uniq = $this->dispatch(
			$this->admin_request(
				'GET',
				'/api/v1/urls',
				array(
					'search'    => $this->test_prefix,
					'sortBy'    => 'uniqueClicks',
					'sortOrder' => 'desc',
				)
			)
		);
		$this->assertSame( 200, $admin_sort_uniq['status'] );
		$this->assertSame( $id_a, $admin_sort_uniq['body']['data']['items'][0]['id'] );

		// 4. Editor B Request (view_links=true, view_analytics=true):
		// Sees all 3 inventory rows, but ONLY B's metrics are numeric. Link A and Link C have clicks=null and uniqueClicks=null.
		$editor_list_req = $this->editor_request( 'GET', '/api/v1/urls', array( 'search' => $this->test_prefix ) );
		$editor_list_res = $this->dispatch( $editor_list_req );
		$this->assertSame( 200, $editor_list_res['status'] );
		$editor_items = array_column( $editor_list_res['body']['data']['items'], null, 'id' );

		// Non-owned Link A: clicks and uniqueClicks are null
		$this->assertNull( $editor_items[ $id_a ]['clicks'] );
		$this->assertNull( $editor_items[ $id_a ]['uniqueClicks'] );

		// Non-owned Link C: clicks and uniqueClicks are null
		$this->assertNull( $editor_items[ $id_c ]['clicks'] );
		$this->assertNull( $editor_items[ $id_c ]['uniqueClicks'] );

		// Owned Link B: metrics retained
		$this->assertSame( 3, $editor_items[ $id_b ]['clicks'] );
		$this->assertSame( 2, $editor_items[ $id_b ]['uniqueClicks'] );

		// Aggregates for Editor B: ONLY Link B's clicks (3 total, 2 unique), while general inventory count is site-wide (3 active)
		$this->assertSame( 3, $editor_list_res['body']['data']['meta']['totalClicks'] );
		$this->assertSame( 2, $editor_list_res['body']['data']['meta']['uniqueClicks'] );
		$this->assertSame( 3, $editor_list_res['body']['data']['meta']['activeLinks'] );

		// 5. Editor B Export Request:
		$editor_export_res = $this->dispatch( $this->editor_request( 'GET', '/api/v1/urls/export', array( 'search' => $this->test_prefix ) ) );
		$this->assertSame( 200, $editor_export_res['status'] );
		$export_items = array_column( $editor_export_res['body']['data']['items'], null, 'id' );

		$this->assertNull( $export_items[ $id_a ]['clicks'] );
		$this->assertNull( $export_items[ $id_a ]['uniqueClicks'] );
		$this->assertNull( $export_items[ $id_c ]['clicks'] );
		$this->assertNull( $export_items[ $id_c ]['uniqueClicks'] );
		$this->assertSame( 3, $export_items[ $id_b ]['clicks'] );
		$this->assertSame( 2, $export_items[ $id_b ]['uniqueClicks'] );

		// 6. Editor B Single-Link Details:
		$detail_a = $this->dispatch( $this->editor_request( 'GET', "/api/v1/urls/{$id_a}" ) );
		$this->assertSame( 200, $detail_a['status'] );
		$this->assertNull( $detail_a['body']['data']['clicks'] );
		$this->assertNull( $detail_a['body']['data']['uniqueClicks'] );

		$detail_c = $this->dispatch( $this->editor_request( 'GET', "/api/v1/urls/{$id_c}" ) );
		$this->assertSame( 200, $detail_c['status'] );
		$this->assertNull( $detail_c['body']['data']['clicks'] );
		$this->assertNull( $detail_c['body']['data']['uniqueClicks'] );

		$detail_b = $this->dispatch( $this->editor_request( 'GET', "/api/v1/urls/{$id_b}" ) );
		$this->assertSame( 200, $detail_b['status'] );
		$this->assertSame( 3, $detail_b['body']['data']['clicks'] );
		$this->assertSame( 2, $detail_b['body']['data']['uniqueClicks'] );

		// 7. Editor B Click Sorting Rejection:
		$sort_clicks_res = $this->dispatch(
			$this->editor_request(
				'GET',
				'/api/v1/urls',
				array(
					'search' => $this->test_prefix,
					'sortBy' => 'clicks',
				)
			)
		);
		$this->assertSame( 403, $sort_clicks_res['status'] );

		$sort_uniq_res = $this->dispatch(
			$this->editor_request(
				'GET',
				'/api/v1/urls',
				array(
					'search' => $this->test_prefix,
					'sortBy' => 'uniqueClicks',
				)
			)
		);
		$this->assertSame( 403, $sort_uniq_res['status'] );

		$export_sort_clicks = $this->dispatch(
			$this->editor_request(
				'GET',
				'/api/v1/urls/export',
				array(
					'search' => $this->test_prefix,
					'sortBy' => 'clicks',
				)
			)
		);
		$this->assertSame( 403, $export_sort_clicks['status'] );

		$export_sort_uniq = $this->dispatch(
			$this->editor_request(
				'GET',
				'/api/v1/urls/export',
				array(
					'search' => $this->test_prefix,
					'sortBy' => 'uniqueClicks',
				)
			)
		);
		$this->assertSame( 403, $export_sort_uniq['status'] );

		// Ordinary sorts work fine
		$sort_created_res = $this->dispatch(
			$this->editor_request(
				'GET',
				'/api/v1/urls',
				array(
					'search' => $this->test_prefix,
					'sortBy' => 'createdAt',
				)
			)
		);
		$this->assertSame( 200, $sort_created_res['status'] );

		// 8. Direct Stats Endpoint /api/v1/analytics/url/:id/stats and /location:
		// Non-owned Link A -> 403
		$stats_a_res = $this->dispatch( $this->editor_request( 'GET', "/api/v1/analytics/url/{$id_a}/stats" ) );
		$this->assertSame( 403, $stats_a_res['status'] );

		$loc_a_res = $this->dispatch( $this->editor_request( 'GET', "/api/v1/analytics/url/{$id_a}/location" ) );
		$this->assertSame( 403, $loc_a_res['status'] );

		// Non-owned Link C -> 403
		$stats_c_res = $this->dispatch( $this->editor_request( 'GET', "/api/v1/analytics/url/{$id_c}/stats" ) );
		$this->assertSame( 403, $stats_c_res['status'] );

		// Owned Link B -> 200 OK
		$stats_b_res = $this->dispatch( $this->editor_request( 'GET', "/api/v1/analytics/url/{$id_b}/stats" ) );
		$this->assertSame( 200, $stats_b_res['status'] );
		$this->assertSame( 3, $stats_b_res['body']['data']['totalClicks'] );

		$loc_b_res = $this->dispatch( $this->editor_request( 'GET', "/api/v1/analytics/url/{$id_b}/location" ) );
		$this->assertSame( 200, $loc_b_res['status'] );

		// Admin requesting Link B stats and location -> 200 OK
		$admin_stats_b = $this->dispatch( $this->admin_request( 'GET', "/api/v1/analytics/url/{$id_b}/stats" ) );
		$this->assertSame( 200, $admin_stats_b['status'] );
		$this->assertSame( 3, $admin_stats_b['body']['data']['totalClicks'] );

		$admin_loc_b = $this->dispatch( $this->admin_request( 'GET', "/api/v1/analytics/url/{$id_b}/location" ) );
		$this->assertSame( 200, $admin_loc_b['status'] );

		// 9. Non-admin with view_analytics=false:
		$no_analytics_caps = array(
			'editor' => array(
				'view_analytics' => false,
				'view_links'     => true,
				'create_links'   => true,
				'edit_links'     => true,
				'trash_links'    => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $no_analytics_caps ) . "', NOW())
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
		);

		// Fresh app instance to pick up settings
		$config              = Configuration::get_current();
		$connection          = Connection::get_instance( $config );
		$resolver            = new Resolver( static fn() => array( '93.184.216.34' ) );
		$probe               = new Probe(
			new Context( $config ),
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 50,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$mock_health_checker = new Checker( $resolver, $probe );
		$app                 = new Application( $connection, $config, $mock_health_checker );
		$this->router        = $app->get_router();

		$no_analytics_list = $this->dispatch( $this->editor_request( 'GET', '/api/v1/urls', array( 'search' => $this->test_prefix ) ) );
		$this->assertSame( 200, $no_analytics_list['status'] );
		$this->assertNull( $no_analytics_list['body']['data']['meta']['totalClicks'] );
		$this->assertNull( $no_analytics_list['body']['data']['meta']['uniqueClicks'] );
		$this->assertSame( 3, $no_analytics_list['body']['data']['meta']['activeLinks'] );

		foreach ( $no_analytics_list['body']['data']['items'] as $item ) {
			$this->assertNull( $item['clicks'] );
			$this->assertNull( $item['uniqueClicks'] );
		}

		// Direct stats for owned Link B is now 403
		$stats_b_no_analytics = $this->dispatch( $this->editor_request( 'GET', "/api/v1/analytics/url/{$id_b}/stats" ) );
		$this->assertSame( 403, $stats_b_no_analytics['status'] );
	}

	public function test_mutation_responses_redact_analytics_metrics_for_unauthorized_actors(): void {
		// Clean up any custom role capabilities to start from baseline
		$this->pdo->exec(
			"DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'"
		);

		// Helper to re-instantiate application to pick up database/settings changes
		$boot_app = function (): Router {
			$config              = Configuration::get_current();
			$connection          = Connection::get_instance( $config );
			$resolver            = new Resolver( static fn() => array( '93.184.216.34' ) );
			$probe               = new Probe(
				new Context( $config ),
				static fn() => array(
					'response_code' => 200,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				)
			);
			$mock_health_checker = new Checker( $resolver, $probe );
			$app                 = new Application( $connection, $config, $mock_health_checker );
			return $app->get_router();
		};

		$this->router = $boot_app();

		// 1. Seed links with distinct owners:
		// Link B: owned by Editor (9999)
		$alias_b = $this->test_prefix . 'mut_b';
		$res_b   = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/dest-mut-b',
					'alias'          => $alias_b,
					'title'          => 'Mutation Test Link B',
				)
			)
		);
		$this->assertSame( 201, $res_b['status'] );
		$id_b = $res_b['body']['data']['id'];

		// Link C: owned by Other Editor (8888)
		$alias_c = $this->test_prefix . 'mut_c';
		$res_c   = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/dest-mut-c',
					'alias'          => $alias_c,
					'title'          => 'Mutation Test Link C',
				)
			)
		);
		$this->assertSame( 201, $res_c['status'] );
		$id_c = $res_c['body']['data']['id'];
		$this->pdo->exec( "UPDATE peakurl_urls SET user_id = 8888 WHERE id = '{$id_c}'" );

		// Seed clicks in peakurl_clicks with distinguishable values:
		// Link B: 5 clicks, 3 unique visitors
		for ( $i = 1; $i <= 3; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_mut_b_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_b}', NOW() - INTERVAL {$i} MINUTE, '{$vis}')"
			);
		}
		for ( $i = 1; $i <= 2; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_mut_b_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_b}', NOW() - INTERVAL {$i} HOUR, '{$vis}')"
			);
		}

		// Link C: 8 clicks, 4 unique visitors
		for ( $i = 1; $i <= 4; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_mut_c_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_c}', NOW() - INTERVAL {$i} MINUTE, '{$vis}')"
			);
		}
		for ( $i = 1; $i <= 4; $i++ ) {
			$click_id = bin2hex( random_bytes( 16 ) );
			$vis      = hash( 'sha256', "vis_mut_c_{$i}" );
			$this->pdo->exec(
				"INSERT INTO peakurl_clicks (id, url_id, clicked_at, visitor_hash)
				VALUES ('{$click_id}', '{$id_c}', NOW() - INTERVAL {$i} HOUR, '{$vis}')"
			);
		}

		// 2. Baseline update: Editor updates own Link B with default capabilities (view_analytics=true):
		// Should succeed (200) and return numeric metrics (5 clicks, 3 uniqueClicks)
		$update_b_res = $this->dispatch(
			$this->editor_request(
				'PUT',
				"/api/v1/urls/{$id_b}",
				array(),
				array( 'title' => 'Updated B by Owner' )
			)
		);
		$this->assertSame( 200, $update_b_res['status'] );
		$this->assertSame( 'Updated B by Owner', $update_b_res['body']['data']['title'] );
		$this->assertSame( 5, $update_b_res['body']['data']['clicks'] );
		$this->assertSame( 3, $update_b_res['body']['data']['uniqueClicks'] );

		// Admin updates Link B: returns numeric metrics
		$admin_update_b = $this->dispatch(
			$this->admin_request(
				'PUT',
				"/api/v1/urls/{$id_b}",
				array(),
				array( 'title' => 'Updated B by Admin' )
			)
		);
		$this->assertSame( 200, $admin_update_b['status'] );
		$this->assertSame( 5, $admin_update_b['body']['data']['clicks'] );
		$this->assertSame( 3, $admin_update_b['body']['data']['uniqueClicks'] );

		// 3. Non-admin with view_analytics=false updates own link:
		$no_analytics_caps = array(
			'editor' => array(
				'view_analytics' => false,
				'view_links'     => true,
				'create_links'   => true,
				'edit_links'     => true,
				'trash_links'    => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $no_analytics_caps ) . "', NOW())
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
		);
		$this->router = $boot_app();

		$update_b_no_analytics = $this->dispatch(
			$this->editor_request(
				'PUT',
				"/api/v1/urls/{$id_b}",
				array(),
				array( 'title' => 'Updated B without Analytics' )
			)
		);
		$this->assertSame( 200, $update_b_no_analytics['status'] );
		$this->assertSame( 'Updated B without Analytics', $update_b_no_analytics['body']['data']['title'] );
		$this->assertNull( $update_b_no_analytics['body']['data']['clicks'] );
		$this->assertNull( $update_b_no_analytics['body']['data']['uniqueClicks'] );

		// Also test empty updates (no fields changed) returns null metrics when unauthorized
		$empty_update_res = $this->dispatch(
			$this->editor_request(
				'PUT',
				"/api/v1/urls/{$id_b}",
				array(),
				array()
			)
		);
		$this->assertSame( 200, $empty_update_res['status'] );
		$this->assertNull( $empty_update_res['body']['data']['clicks'] );
		$this->assertNull( $empty_update_res['body']['data']['uniqueClicks'] );

		// 4. Non-admin with edit_any_link=true and view_analytics=true updates another account's link (Link C owned by 8888):
		$cross_edit_caps = array(
			'editor' => array(
				'view_analytics' => true,
				'view_links'     => true,
				'create_links'   => true,
				'edit_links'     => true,
				'edit_any_link'  => true,
				'trash_links'    => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $cross_edit_caps ) . "', NOW())
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
		);
		$this->router = $boot_app();

		$cross_update_res = $this->dispatch(
			$this->editor_request(
				'PUT',
				"/api/v1/urls/{$id_c}",
				array(),
				array( 'title' => 'Updated C by Cross Editor' )
			)
		);
		$this->assertSame( 200, $cross_update_res['status'] );
		$this->assertSame( 'Updated C by Cross Editor', $cross_update_res['body']['data']['title'] );
		// Editor has view_analytics=true BUT does NOT own Link C -> metrics must be REDACTED (null)
		$this->assertNull( $cross_update_res['body']['data']['clicks'] );
		$this->assertNull( $cross_update_res['body']['data']['uniqueClicks'] );

		// Cross editor updating Admin-owned Link A:
		$alias_a        = $this->test_prefix . 'mut_a';
		$res_a          = $this->dispatch(
			$this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/dest-mut-a',
					'alias'          => $alias_a,
					'title'          => 'Mutation Test Link A',
				)
			)
		);
		$id_a           = $res_a['body']['data']['id'];
		$cross_update_a = $this->dispatch(
			$this->editor_request(
				'PUT',
				"/api/v1/urls/{$id_a}",
				array(),
				array( 'title' => 'Updated Admin Link A by Editor' )
			)
		);
		$this->assertSame( 200, $cross_update_a['status'] );
		$this->assertNull( $cross_update_a['body']['data']['clicks'] );
		$this->assertNull( $cross_update_a['body']['data']['uniqueClicks'] );

		// 5. Restore own link with view_analytics=false:
		// First trash Link B
		$this->pdo->exec( "UPDATE peakurl_urls SET status = 'trashed' WHERE id = '{$id_b}'" );

		// Restore as Editor with view_analytics=false
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $no_analytics_caps ) . "', NOW())
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
		);
		$this->router = $boot_app();

		$restore_res = $this->dispatch(
			$this->editor_request(
				'POST',
				"/api/v1/urls/{$id_b}/restore"
			)
		);
		$this->assertSame( 200, $restore_res['status'] );
		$this->assertSame( 'active', $restore_res['body']['data']['status'] );
		$this->assertNull( $restore_res['body']['data']['clicks'] );
		$this->assertNull( $restore_res['body']['data']['uniqueClicks'] );

		// Trash Link B again and restore as Admin: Admin receives numeric metrics
		$this->pdo->exec( "UPDATE peakurl_urls SET status = 'trashed' WHERE id = '{$id_b}'" );
		$admin_restore = $this->dispatch(
			$this->admin_request(
				'POST',
				"/api/v1/urls/{$id_b}/restore"
			)
		);
		$this->assertSame( 200, $admin_restore['status'] );
		$this->assertSame( 'active', $admin_restore['body']['data']['status'] );
		$this->assertSame( 5, $admin_restore['body']['data']['clicks'] );
		$this->assertSame( 3, $admin_restore['body']['data']['uniqueClicks'] );

		// 5b. Activity log restoration delegates through restore_url and protects metrics:
		$this->pdo->exec( "UPDATE peakurl_urls SET status = 'trashed' WHERE id = '{$id_b}'" );
		$act_id    = bin2hex( random_bytes( 16 ) );
		$link_meta = array(
			'id'             => $id_b,
			'destinationUrl' => 'https://example.com/dest-mut-b',
			'title'          => 'Mutation Test Link B',
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_audit_logs (id, user_id, type, message, link_id, metadata, created_at)
			VALUES ('{$act_id}', 9999, 'link.deleted', 'Link deleted', '{$id_b}', '" . json_encode( array( 'link' => $link_meta ) ) . "', NOW())"
		);

		// Non-admin with view_analytics=false restores from activity log: metrics are null
		$act_restore_editor = $this->dispatch(
			$this->editor_request(
				'POST',
				"/api/v1/analytics/activity/{$act_id}/restore"
			)
		);
		$this->assertSame( 200, $act_restore_editor['status'] );
		$this->assertSame( 'active', $act_restore_editor['body']['data']['status'] );
		$this->assertNull( $act_restore_editor['body']['data']['clicks'] );
		$this->assertNull( $act_restore_editor['body']['data']['uniqueClicks'] );

		// Trash Link B again, Admin restores from activity log: metrics are preserved
		$this->pdo->exec( "UPDATE peakurl_urls SET status = 'trashed' WHERE id = '{$id_b}'" );
		$act_restore_admin = $this->dispatch(
			$this->admin_request(
				'POST',
				"/api/v1/analytics/activity/{$act_id}/restore"
			)
		);
		$this->assertSame( 200, $act_restore_admin['status'] );
		$this->assertSame( 'active', $act_restore_admin['body']['data']['status'] );
		$this->assertSame( 5, $act_restore_admin['body']['data']['clicks'] );
		$this->assertSame( 3, $act_restore_admin['body']['data']['uniqueClicks'] );

		// 6. Create and bulk-create links with view_analytics=false:
		$create_res = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://example.com/created-no-analytics',
					'alias'          => $this->test_prefix . 'new_no_analytics',
				)
			)
		);
		$this->assertSame( 201, $create_res['status'] );
		$this->assertNull( $create_res['body']['data']['clicks'] );
		$this->assertNull( $create_res['body']['data']['uniqueClicks'] );

		$bulk_create_res = $this->dispatch(
			$this->editor_request(
				'POST',
				'/api/v1/urls/bulk',
				array(),
				array(
					'urls' => array(
						array(
							'destinationUrl' => 'https://example.com/bulk-no-analytics-1',
							'alias'          => $this->test_prefix . 'bulk_na_1',
						),
						array(
							'destinationUrl' => 'https://example.com/bulk-no-analytics-2',
							'alias'          => $this->test_prefix . 'bulk_na_2',
						),
					),
				)
			)
		);
		$this->assertSame( 200, $bulk_create_res['status'] );
		$this->assertCount( 2, $bulk_create_res['body']['data']['results'] );
		foreach ( $bulk_create_res['body']['data']['results'] as $created_item ) {
			$this->assertNull( $created_item['clicks'] );
			$this->assertNull( $created_item['uniqueClicks'] );
		}

		// 7. Verify blocked mutations fail as expected (redaction does not bypass authorization)
		// Editor without edit_links trying to update:
		$no_edit_caps = array(
			'editor' => array(
				'view_links'     => true,
				'create_links'   => true,
				'edit_links'     => false,
				'view_analytics' => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO peakurl_settings (setting_key, setting_value, updated_at)
			VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $no_edit_caps ) . "', NOW())
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
		);
		$this->router = $boot_app();

		$blocked_update = $this->dispatch(
			$this->editor_request(
				'PUT',
				"/api/v1/urls/{$id_b}",
				array(),
				array( 'title' => 'Attempted Unauthorized Update' )
			)
		);
		$this->assertSame( 403, $blocked_update['status'] );

		// Clean up settings to restore clean baseline
		$this->pdo->exec(
			"DELETE FROM peakurl_settings WHERE setting_key = '" . Constants::SETTING_ROLE_CAPABILITIES . "'"
		);
	}
}
