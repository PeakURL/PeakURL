<?php
/**
 * Integration tests for Users Controller and Service contracts and business rules.
 *
 * @package PeakURL\Tests\Integration\Users
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Users;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Users\Controller as UsersController;
use PeakURL\Features\Users\Service as UsersService;
use PeakURL\Api\UsersApi;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Users\Validator as UsersValidator;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Services\SocialPreview;
use PeakURL\Http\Request;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Database\Connection;
use PeakURL\Tests\Integration\ProcessConcurrencyTrait;
use PDO;
use ReflectionClass;

class UsersContractsTest extends TestCase {

	use ProcessConcurrencyTrait;

	/**
	 * Database prefixes to drop during teardown.
	 *
	 * @var array<int, string>
	 */
	private array $prefixes_to_clean = array();

	/**
	 * Known tables created by schema.sql.
	 *
	 * @var array<int, string>
	 */
	/**
	 * Canonical tables created by schema.sql.
	 *
	 * @var array<int, string>
	 */
	private static array $known_tables = array(
		'settings',
		'users',
		'api_keys',
		'sessions',
		'urls',
		'clicks',
		'audit_logs',
		'webhooks',
		'cron_jobs',
		'cron_runs',
		'webhook_deliveries',
		'link_health',
	);

	/**
	 * Extract exact table names from schema.sql to guarantee 100% complete cleanup.
	 *
	 * @return array<int, string>
	 */
	public static function get_schema_tables(): array {
		$schema_file = dirname( __DIR__, 3 ) . '/database/schema.sql';
		if ( file_exists( $schema_file ) ) {
			$schema_sql = (string) file_get_contents( $schema_file );
			preg_match_all( '/CREATE TABLE IF NOT EXISTS\s+`?([a-zA-Z0-9_]+)`?/i', $schema_sql, $matches );
			if ( ! empty( $matches[1] ) ) {
				return array_values( array_unique( $matches[1] ) );
			}
		}
		return self::$known_tables;
	}

	/**
	 * Check whether the database configuration and environment represent an authorized test database.
	 *
	 * Requires an active testing environment (PEAKURL_ENV === 'testing') AND a dedicated,
	 * strictly constrained test database name. Rejects environment-only flags, substring
	 * false positives (e.g. 'contest_production', 'contest', 'testing_prod'), and production names.
	 *
	 * Permitted database patterns:
	 * - Exact 'peakurl_test' (the canonical test database)
	 * - Dedicated test databases starting with 'test_' or ending with '_test' (e.g. 'test_peakurl', 'isolated_test')
	 *
	 * @param array<string, mixed> $config Database configuration array.
	 * @return bool True if safe and approved for test schema operations.
	 */
	public static function is_test_database_eligible( array $config ): bool {
		$env = (string) ( getenv( 'PEAKURL_ENV' ) ?: ( $_ENV['PEAKURL_ENV'] ?? '' ) );
		if ( 'testing' !== $env ) {
			return false;
		}

		$db_name = strtolower( trim( (string) ( $config[ Constants::DB_DATABASE ] ?? '' ) ) );
		if ( '' === $db_name ) {
			return false;
		}

		if ( str_contains( $db_name, 'prod' ) || str_contains( $db_name, 'live' ) || str_contains( $db_name, 'contest' ) ) {
			return false;
		}

		if ( 'peakurl_test' === $db_name ) {
			return true;
		}

		return (bool) preg_match( '/^(test_[a-zA-Z0-9_]+|[a-zA-Z0-9_]+_test)$/', $db_name );
	}

	/**
	 * Safely drops test tables matching isolated test prefixes.
	 *
	 * Enforces strict prefix verification (must match /^test_usr_[a-f0-9]{8}_$/)
	 * and only drops tables present in the canonical schema tables list.
	 * Preserves and restores the session's previous FOREIGN_KEY_CHECKS setting in finally.
	 *
	 * @param PDO                $pdo      Database connection.
	 * @param array<int, string> $prefixes Array of test prefixes to drop.
	 */
	private function clean_test_tables( PDO $pdo, array $prefixes ): void {
		$prev_fkey = $pdo->query( 'SELECT @@FOREIGN_KEY_CHECKS' )->fetchColumn();
		$pdo->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
		try {
			$tables = self::get_schema_tables();
			foreach ( array_unique( $prefixes ) as $pfx ) {
				if ( ! preg_match( '/^test_usr_[a-f0-9]{8}_$/', $pfx ) ) {
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

	protected function tearDown(): void {
		try {
			if ( ! empty( $this->prefixes_to_clean ) ) {
				$base_config = Configuration::get_current();
				if ( ! self::is_test_database_eligible( $base_config ) ) {
					$this->prefixes_to_clean = array();
					return;
				}

				$pdo = ( new Connection( $base_config ) )->get_connection();
				$this->clean_test_tables( $pdo, $this->prefixes_to_clean );
				$this->prefixes_to_clean = array();
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Helper to create a configured UsersService instance with all 10 mandatory dependencies.
	 *
	 * @param PeakURL_DB             $db                Database instance.
	 * @param UsersApi               $users_api         Users API instance.
	 * @param AuthService            $auth_service      Auth service instance.
	 * @param AnalyticsService|null  $analytics_service Analytics service instance.
	 * @param UsersValidator|null    $validator         Validator instance.
	 * @param Roles|null             $roles             Roles registry.
	 * @param Authorization|null     $authorization     Authorization service.
	 * @param SocialPreview|null     $social_preview    Social preview service.
	 * @param WebhooksService|null   $webhooks_service  Webhooks service.
	 * @param LinksApi|null          $links_api         Links API instance.
	 * @return UsersService
	 */
	private function create_users_service(
		PeakURL_DB $db,
		UsersApi $users_api,
		AuthService $auth_service,
		?AnalyticsService $analytics_service = null,
		?UsersValidator $validator = null,
		?Roles $roles = null,
		?Authorization $authorization = null,
		?SocialPreview $social_preview = null,
		?WebhooksService $webhooks_service = null,
		?LinksApi $links_api = null
	): UsersService {
		$roles             = $roles ?? new Roles();
		$authorization     = $authorization ?? new Authorization( $roles );
		$analytics_service = $analytics_service ?? $this->createMock( AnalyticsService::class );
		$validator         = $validator ?? new UsersValidator();
		$social_preview    = $social_preview ?? $this->createMock( SocialPreview::class );
		$webhooks_service  = $webhooks_service ?? $this->createMock( WebhooksService::class );
		$links_api         = $links_api ?? $this->createMock( LinksApi::class );

		return new UsersService(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service,
			$links_api
		);
	}

	public function test_controller_declares_standard_rest_actions(): void {
		$ref = new ReflectionClass( UsersController::class );

		$expected_methods = array(
			'index',
			'create',
			'me',
			'update_me',
			'update',
			'delete',
		);

		foreach ( $expected_methods as $method_name ) {
			$this->assertTrue(
				$ref->hasMethod( $method_name ),
				"UsersController must implement method: {$method_name}"
			);
			$method = $ref->getMethod( $method_name );
			$params = $method->getParameters();
			$this->assertGreaterThanOrEqual( 1, count( $params ) );
			$this->assertSame( Request::class, $params[0]->getType()->getName() );
		}
	}

	public function test_update_current_user_strictly_rejects_username_modification(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );

		$auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '1',
				'username' => 'original_username',
				'role'     => 'admin',
			)
		);

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview
		);

		$request = new Request( 'PUT', '/api/v1/users/me', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Username cannot be changed.' );

		$service->update_current_user(
			$request,
			array(
				'username' => 'attempted_new_username',
			)
		);
	}

	public function test_delete_user_prevents_self_deletion(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => 'user-1',
				'username' => 'admin_user',
				'role'     => 'admin',
			)
		);

		$users_api->method( 'get_user_by_username' )->with( 'admin_user' )->willReturn(
			array(
				'id'       => 'user-1',
				'username' => 'admin_user',
				'role'     => 'admin',
			)
		);

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview
		);

		$request = new Request( 'DELETE', '/api/v1/users/admin_user', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'You cannot delete the current user.' );

		$service->delete_user_by_username( $request, 'admin_user' );
	}

	public function test_delete_user_returns_false_when_user_not_found(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => 'user-1',
				'username' => 'admin_user',
				'role'     => 'admin',
			)
		);

		$users_api->method( 'get_user_by_username' )->with( 'non_existent' )->willReturn( null );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview
		);

		$request = new Request( 'DELETE', '/api/v1/users/non_existent', array(), array() );
		$result  = $service->delete_user_by_username( $request, 'non_existent' );

		$this->assertFalse( $result );
	}

	public function test_create_user_normalizes_username_to_lowercase(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = new UsersValidator();
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => 'admin-1',
				'username' => 'admin',
				'role'     => 'admin',
			)
		);

		$auth_service->method( 'email_in_use' )->willReturn( false );
		$auth_service->method( 'username_in_use' )->willReturn( false );

		$captured_insert = null;
		$db->method( 'insert' )->willReturnCallback(
			function ( $table, $data ) use ( &$captured_insert ) {
				$captured_insert = $data;
				return 1;
			}
		);
		$db->method( 'insert_id' )->willReturn( '42' );

		$users_api->method( 'get_user' )->willReturn(
			array(
				'id'         => '42',
				'username'   => 'john-doe',
				'email'      => 'john@example.com',
				'first_name' => 'John',
				'last_name'  => 'Doe',
				'role'       => 'editor',
			)
		);

		$auth_service->method( 'format_user' )->willReturn(
			array(
				'id'       => '42',
				'username' => 'john-doe',
			)
		);

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview
		);

		$request = new Request( 'POST', '/api/v1/users', array(), array() );
		$result  = $service->create_user(
			$request,
			array(
				'firstName' => 'John',
				'lastName'  => 'Doe',
				'username'  => 'John-Doe',
				'email'     => 'john@example.com',
				'password'  => 'password123',
			)
		);

		$this->assertNotNull( $captured_insert );
		$this->assertSame( 'john-doe', $captured_insert['username'] );
		$this->assertSame( 'john-doe', $result['username'] );
	}

	public function test_get_all_users_fetches_via_users_api_and_formats_rows(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => 'admin-1',
				'username' => 'admin',
				'role'     => 'admin',
			)
		);
		$auth_service->method( 'format_user' )->willReturnCallback(
			fn( array $row ): array => $row
		);

		$raw_users = array(
			array(
				'id'         => '1',
				'username'   => 'admin',
				'email'      => 'admin@example.com',
				'first_name' => 'Admin',
				'last_name'  => 'User',
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
			),
			array(
				'id'         => '2',
				'username'   => 'editor',
				'email'      => 'editor@example.com',
				'first_name' => 'Editor',
				'last_name'  => 'User',
				'role'       => 'editor',
				'created_at' => '2026-01-02 00:00:00',
			),
		);

		$users_api->expects( $this->once() )
			->method( 'list_users' )
			->willReturn( $raw_users );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview
		);

		$request = new Request( 'GET', '/api/v1/users', array(), array() );
		$result  = $service->get_all_users( $request );

		$this->assertCount( 2, $result );
		$this->assertSame( 'admin', $result[0]['username'] );
		$this->assertSame( 'editor', $result[1]['username'] );
	}

	/**
	 * Set up an isolated database schema with unique table prefix for real database tests.
	 *
	 * @return array{0: Connection, 1: PeakURL_DB, 2: PDO, 3: string}
	 */
	private function setup_isolated_db(): array {
		$base_config = Configuration::get_current();
		if ( ! self::is_test_database_eligible( $base_config ) ) {
			$this->markTestSkipped( 'Refusing to run isolated DB tests: environment or database name is not an approved test database configuration.' );
		}

		$isolated_prefix           = 'test_usr_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';
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
	 * Configure a mock PeakURL_DB with stateful in_transaction tracking.
	 *
	 * @param PeakURL_DB $db Mock database instance.
	 * @return void
	 */
	private function bind_stateful_transactions( PeakURL_DB $db ): void {
		$in_tx = false;
		$db->method( 'in_transaction' )->willReturnCallback( static fn(): bool => $in_tx );
		$db->method( 'begin_transaction' )->willReturnCallback(
			static function () use ( &$in_tx ): bool {
				$in_tx = true;
				return true;
			}
		);
		$db->method( 'commit' )->willReturnCallback(
			static function () use ( &$in_tx ): bool {
				$in_tx = false;
				return true;
			}
		);
		$db->method( 'roll_back' )->willReturnCallback(
			static function () use ( &$in_tx ): bool {
				$in_tx = false;
				return true;
			}
		);
	}

	public function test_delete_user_by_username_succeeds_with_two_admins_transfers_links_and_cleans_credentials(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );
		$links_api         = $this->createMock( LinksApi::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		$target_user  = array(
			'id'       => 'admin-2',
			'username' => 'admin_two',
			'role'     => 'admin',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'admin_two' )->willReturn( $target_user );

		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'admin-2' ) )
			->willReturn(
				array(
					'admin-1' => $acting_admin,
					'admin-2' => $target_user,
				)
			);

		$users_api->expects( $this->once() )
			->method( 'count_admin_users_for_update' )
			->willReturn( 2 );

		$db->expects( $this->once() )
			->method( 'get_results_by' )
			->with( 'urls', array( 'user_id' => 'admin-2' ), array( 'id', 'short_code', 'alias' ) )
			->willReturn(
				array(
					array(
						'id'         => 'url-1',
						'short_code' => 'link1',
						'alias'      => '',
					),
				)
			);

		$db->expects( $this->once() )
			->method( 'update' )
			->with( 'urls', array( 'user_id' => 'admin-1' ), array( 'user_id' => 'admin-2' ) )
			->willReturn( 1 );

		$links_api->expects( $this->once() )
			->method( 'invalidate_link_cache' )
			->with(
				array(
					'id'         => 'url-1',
					'short_code' => 'link1',
					'alias'      => '',
				)
			);

		$delete_calls = array();
		$db->expects( $this->exactly( 3 ) )
			->method( 'delete' )
			->willReturnCallback(
				function ( $table, $conditions ) use ( &$delete_calls ) {
					$delete_calls[] = array(
						'table'      => $table,
						'conditions' => $conditions,
					);
					return 1;
				}
			);

		$webhooks_service->expects( $this->once() )
			->method( 'dispatch_user_event' )
			->with( 'user.deleted', $target_user );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service,
			$links_api
		);

		$request = new Request( 'DELETE', '/api/v1/users/admin_two', array(), array() );
		$result  = $service->delete_user_by_username( $request, 'admin_two' );

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				array(
					'table'      => 'sessions',
					'conditions' => array( 'user_id' => 'admin-2' ),
				),
				array(
					'table'      => 'api_keys',
					'conditions' => array( 'user_id' => 'admin-2' ),
				),
				array(
					'table'      => 'users',
					'conditions' => array( 'id' => 'admin-2' ),
				),
			),
			$delete_calls
		);
	}

	public function test_delete_user_by_username_fails_when_only_one_admin_remains(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		$target_user  = array(
			'id'       => 'admin-2',
			'username' => 'admin_two',
			'role'     => 'admin',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'admin_two' )->willReturn( $target_user );

		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'admin-2' ) )
			->willReturn(
				array(
					'admin-1' => $acting_admin,
					'admin-2' => $target_user,
				)
			);

		$users_api->expects( $this->once() )
			->method( 'count_admin_users_for_update' )
			->willReturn( 1 );

		$db->expects( $this->never() )->method( 'delete' );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		$request = new Request( 'DELETE', '/api/v1/users/admin_two', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'At least one admin account must remain on the site.' );

		$service->delete_user_by_username( $request, 'admin_two' );
	}

	public function test_update_user_by_username_allows_demoting_admin_when_multiple_admins_exist(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = new UsersValidator();
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		$target_user  = array(
			'id'         => 'admin-2',
			'username'   => 'admin_two',
			'email'      => 'admin2@example.com',
			'first_name' => 'Admin',
			'last_name'  => 'Two',
			'role'       => 'admin',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'admin_two' )->willReturn( $target_user );
		$users_api->method( 'get_user' )->with( 'admin-2' )->willReturn(
			array_merge( $target_user, array( 'role' => 'editor' ) )
		);
		$auth_service->method( 'format_user' )->willReturnCallback( fn( array $u ): array => $u );

		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'admin-2' ) )
			->willReturn(
				array(
					'admin-1' => $acting_admin,
					'admin-2' => $target_user,
				)
			);

		$users_api->expects( $this->once() )
			->method( 'count_admin_users_for_update' )
			->willReturn( 2 );

		$db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->callback( fn( array $d ): bool => 'editor' === $d['role'] ),
				array( 'id' => 'admin-2' )
			)
			->willReturn( 1 );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		$request = new Request( 'PUT', '/api/v1/users/admin_two', array(), array() );
		$result  = $service->update_user_by_username(
			$request,
			'admin_two',
			array( 'role' => 'editor' )
		);

		$this->assertSame( 'editor', $result['role'] );
	}

	public function test_update_user_by_username_prevents_demoting_last_admin(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = new UsersValidator();
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		$target_user  = array(
			'id'         => 'admin-2',
			'username'   => 'admin_two',
			'email'      => 'admin2@example.com',
			'first_name' => 'Admin',
			'last_name'  => 'Two',
			'role'       => 'admin',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'admin_two' )->willReturn( $target_user );

		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'admin-2' ) )
			->willReturn(
				array(
					'admin-1' => $acting_admin,
					'admin-2' => $target_user,
				)
			);

		$users_api->expects( $this->once() )
			->method( 'count_admin_users_for_update' )
			->willReturn( 1 );

		$db->expects( $this->never() )->method( 'update' );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		$request = new Request( 'PUT', '/api/v1/users/admin_two', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'At least one admin account must remain on the site.' );

		$service->update_user_by_username(
			$request,
			'admin_two',
			array( 'role' => 'editor' )
		);
	}

	public function test_update_user_by_username_prevents_self_demotion_even_when_multiple_admins(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = new UsersValidator();
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'         => 'admin-1',
			'username'   => 'admin_one',
			'email'      => 'admin1@example.com',
			'first_name' => 'Admin',
			'last_name'  => 'One',
			'role'       => 'admin',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'admin_one' )->willReturn( $acting_admin );

		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'admin-1' ) )
			->willReturn( array( 'admin-1' => $acting_admin ) );

		$users_api->expects( $this->once() )
			->method( 'count_admin_users_for_update' )
			->willReturn( 2 );

		$db->expects( $this->never() )->method( 'update' );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		$request = new Request( 'PUT', '/api/v1/users/admin_one', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'You cannot demote your own administrator account.' );

		$service->update_user_by_username(
			$request,
			'admin_one',
			array( 'role' => 'editor' )
		);
	}

	public function test_delete_user_by_username_allows_deleting_editor_without_last_admin_restriction(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		$editor_user  = array(
			'id'       => 'editor-1',
			'username' => 'editor_user',
			'role'     => 'editor',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'editor_user' )->willReturn( $editor_user );

		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'editor-1' ) )
			->willReturn(
				array(
					'admin-1'  => $acting_admin,
					'editor-1' => $editor_user,
				)
			);

		$users_api->expects( $this->never() )->method( 'count_admin_users' );

		$db->method( 'get_results_by' )->willReturn( array() );
		$db->method( 'delete' )->willReturn( 1 );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		$request = new Request( 'DELETE', '/api/v1/users/editor_user', array(), array() );
		$result  = $service->delete_user_by_username( $request, 'editor_user' );

		$this->assertTrue( $result );
	}

	public function test_delete_user_rolls_back_when_link_transfer_fails(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		$target_user  = array(
			'id'       => 'editor-1',
			'username' => 'editor_one',
			'role'     => 'editor',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'editor_one' )->willReturn( $target_user );

		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'editor-1' ) )
			->willReturn(
				array(
					'admin-1'  => $acting_admin,
					'editor-1' => $target_user,
				)
			);

		$db->expects( $this->once() )
			->method( 'get_results_by' )
			->willThrowException( new \RuntimeException( 'Database disk failure' ) );

		$db->expects( $this->never() )->method( 'delete' );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		$request = new Request( 'DELETE', '/api/v1/users/editor_one', array(), array() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Database disk failure' );

		$service->delete_user_by_username( $request, 'editor_one' );
	}

	public function test_real_db_delete_admin_with_two_admins_transfers_links_and_preserves_analytics(): void {
		list( $connection, $db, $pdo, $prefix ) = $this->setup_isolated_db();

		$pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(90001, 'admin1', 'admin1@example.com', 'Admin', 'One', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(90002, 'admin2', 'admin2@example.com', 'Admin', 'Two', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$pdo->exec(
			"INSERT INTO `{$prefix}urls` (id, user_id, destination_url, short_code, alias, title, created_at, updated_at)
			VALUES ('url_test_1', 90002, 'https://example.com/dest', 'dest123', 'alias_dest123', 'Test Destination', NOW(), NOW())"
		);

		$pdo->exec(
			"INSERT INTO `{$prefix}clicks` (id, url_id, ip_address, country_name, city_name, clicked_at)
			VALUES ('click_test_1', 'url_test_1', '127.0.0.1', 'US', 'New York', NOW())"
		);

		$sess_hash = hash( 'sha256', 'token_123' );
		$pdo->exec(
			"INSERT INTO `{$prefix}sessions` (id, user_id, token_hash, last_active_at, created_at)
			VALUES ('sess_1', 90002, '{$sess_hash}', NOW(), NOW())"
		);
		$key_hash = hash( 'sha256', 'key_123' );
		$pdo->exec(
			"INSERT INTO `{$prefix}api_keys` (id, user_id, label, key_hash, key_prefix, key_last_four, created_at)
			VALUES ('key_1', 90002, 'Test Key', '{$key_hash}', 'pk_', '1234', NOW())"
		);

		$users_api         = new UsersApi( $db );
		$links_api         = new LinksApi( $db );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '90001',
				'username' => 'admin1',
				'role'     => 'admin',
			)
		);

		$webhooks_service->expects( $this->once() )
			->method( 'dispatch_user_event' )
			->with( 'user.deleted', $this->callback( fn( array $u ): bool => 'admin2' === $u['username'] ) );

		$service = new UsersService(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			new UsersValidator(),
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service,
			$links_api
		);

		$request = new Request( 'DELETE', '/api/v1/users/admin2', array(), array() );
		$result  = $service->delete_user_by_username( $request, 'admin2' );

		$this->assertTrue( $result );

		$stmt = $pdo->query( "SELECT COUNT(*) FROM `{$prefix}users` WHERE id = 90002" );
		$this->assertSame( 0, (int) $stmt->fetchColumn() );

		$this->assertSame( 1, $users_api->count_admin_users() );

		$stmt = $pdo->query( "SELECT user_id, short_code, destination_url FROM `{$prefix}urls` WHERE id = 'url_test_1'" );
		$row  = $stmt->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( '90001', (string) $row['user_id'] );
		$this->assertSame( 'dest123', $row['short_code'] );
		$this->assertSame( 'https://example.com/dest', $row['destination_url'] );

		$stmt = $pdo->query( "SELECT COUNT(*) FROM `{$prefix}clicks` WHERE url_id = 'url_test_1'" );
		$this->assertSame( 1, (int) $stmt->fetchColumn() );

		$stmt = $pdo->query( "SELECT COUNT(*) FROM `{$prefix}sessions` WHERE user_id = 90002" );
		$this->assertSame( 0, (int) $stmt->fetchColumn() );
		$stmt = $pdo->query( "SELECT COUNT(*) FROM `{$prefix}api_keys` WHERE user_id = 90002" );
		$this->assertSame( 0, (int) $stmt->fetchColumn() );
	}

	public function test_real_db_delete_last_admin_fails_and_leaves_user_intact(): void {
		list( $connection, $db, $pdo, $prefix ) = $this->setup_isolated_db();

		$pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(90001, 'admin1', 'admin1@example.com', 'Admin', 'One', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$users_api         = new UsersApi( $db );
		$links_api         = new LinksApi( $db );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '90001',
				'username' => 'admin1',
				'role'     => 'admin',
			)
		);

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			new UsersValidator(),
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service,
			$links_api
		);

		$request = new Request( 'DELETE', '/api/v1/users/admin1', array(), array() );

		try {
			$service->delete_user_by_username( $request, 'admin1' );
			$this->fail( 'Expected ApiException 422 for self-deletion of last admin' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'You cannot delete the current user.', $e->getMessage() );
		}

		$stmt = $pdo->query( "SELECT COUNT(*) FROM `{$prefix}users` WHERE id = 90001" );
		$this->assertSame( 1, (int) $stmt->fetchColumn() );
	}

	public function test_real_db_admin_disappearing_before_lock_fails_safely_and_leaves_data_intact(): void {
		list( $connection, $db, $pdo, $prefix ) = $this->setup_isolated_db();

		$pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(90001, 'admin1', 'admin1@example.com', 'Admin', 'One', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$users_api         = new UsersApi( $db );
		$links_api         = new LinksApi( $db );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		// Acting admin was returned initially as 99999 but does not exist in DB when locked
		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '99999',
				'username' => 'disappeared_admin',
				'role'     => 'admin',
			)
		);

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			new UsersValidator(),
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service,
			$links_api
		);

		$request = new Request( 'DELETE', '/api/v1/users/admin1', array(), array() );

		try {
			$service->delete_user_by_username( $request, 'admin1' );
			$this->fail( 'Expected ApiException 403 when acting admin does not exist under lock' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
			$this->assertSame( 'Administrator access required.', $e->getMessage() );
		}

		$stmt = $pdo->query( "SELECT COUNT(*) FROM `{$prefix}users` WHERE id = 90001" );
		$this->assertSame( 1, (int) $stmt->fetchColumn() );
	}

	public function test_real_db_target_user_disappearing_before_lock_returns_false_and_rolls_back(): void {
		list( $connection, $db, $pdo, $prefix ) = $this->setup_isolated_db();

		$pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(90001, 'admin1', 'admin1@example.com', 'Admin', 'One', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$users_api         = new UsersApi( $db );
		$links_api         = new LinksApi( $db );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '90001',
				'username' => 'admin1',
				'role'     => 'admin',
			)
		);

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			new UsersValidator(),
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service,
			$links_api
		);

		// Target user 'nonexistent' does not exist in DB
		$request = new Request( 'DELETE', '/api/v1/users/nonexistent', array(), array() );
		$result  = $service->delete_user_by_username( $request, 'nonexistent' );

		$this->assertFalse( $result );
	}

	public function test_sequential_real_db_admin_removal_serialization(): void {
		list( $connection, $db, $pdo1, $prefix ) = $this->setup_isolated_db();

		$pdo1->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(90001, 'admin1', 'admin1@example.com', 'Admin', 'One', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(90002, 'admin2', 'admin2@example.com', 'Admin', 'Two', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$base_config  = Configuration::get_current();
		$conn2_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$connection2  = new Connection( $conn2_config );
		$db2          = new PeakURL_DB( $connection2 );
		$pdo2         = $connection2->get_connection();
		$pdo2->exec( 'SET SESSION innodb_lock_wait_timeout = 1' );

		$users_api1 = new UsersApi( $db );
		$users_api2 = new UsersApi( $db2 );

		// Tx 1 acquires exclusive row locks on all admin users
		$db->begin_transaction();
		$count1 = $users_api1->count_admin_users_for_update();
		$this->assertSame( 2, $count1 );

		// Tx 2 attempts to lock admin users while Tx 1 holds locks -> must block and time out
		$db2->begin_transaction();
		$timed_out = false;
		try {
			$users_api2->count_admin_users_for_update();
		} catch ( \PDOException $e ) {
			if ( str_contains( $e->getMessage(), '1205' ) || str_contains( strtolower( $e->getMessage() ), 'lock wait timeout' ) ) {
				$timed_out = true;
			}
		}
		$db2->roll_back();

		$this->assertTrue( $timed_out, 'Second transaction must be blocked by row-level locks on admin users' );

		// Simulate completion of Tx 1: Admin 2 is removed, then Tx 1 commits
		$pdo1->exec( "DELETE FROM `{$prefix}users` WHERE id = 90002" );
		$db->commit();

		// Now Tx 2 runs after Tx 1 committed: it sees exactly 1 admin remaining under row locks
		$db2->begin_transaction();
		$count2 = $users_api2->count_admin_users_for_update();
		$this->assertSame( 1, $count2 );

		// Attempting to delete Admin 1 with only 1 admin remaining is safely rejected
		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'At least one admin account must remain on the site.' );

		try {
			$authorization->validate_role_change(
				'90001',
				'admin',
				'deleted',
				'other_caller',
				fn(): int => $count2
			);
		} finally {
			$db2->roll_back();
		}
	}

	public function test_is_test_database_eligible_rejects_misleading_names_and_env_only(): void {
		$orig_env = getenv( 'PEAKURL_ENV' );
		try {
			putenv( 'PEAKURL_ENV=testing' );
			$_ENV['PEAKURL_ENV'] = 'testing';

			// Misleading substring 'contest_production' contains 'test' but is NOT an eligible test DB
			$this->assertFalse( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'contest_production' ) ) );
			$this->assertFalse( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'contest' ) ) );
			$this->assertFalse( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'peakurl' ) ) );
			$this->assertFalse( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'latest_release' ) ) );
			$this->assertFalse( self::is_test_database_eligible( array( Constants::DB_DATABASE => '' ) ) );

			// Allowed test databases
			$this->assertTrue( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'peakurl_test' ) ) );
			$this->assertTrue( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'test_peakurl' ) ) );
			$this->assertTrue( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'isolated_test' ) ) );

			// Non-testing environment MUST NOT authorize test schema changes even with a valid test DB name
			putenv( 'PEAKURL_ENV=production' );
			$_ENV['PEAKURL_ENV'] = 'production';
			$this->assertFalse( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'peakurl_test' ) ) );
			$this->assertFalse( self::is_test_database_eligible( array( Constants::DB_DATABASE => 'test_peakurl' ) ) );
		} finally {
			if ( false !== $orig_env ) {
				putenv( "PEAKURL_ENV={$orig_env}" );
				$_ENV['PEAKURL_ENV'] = $orig_env;
			} else {
				putenv( 'PEAKURL_ENV' );
				unset( $_ENV['PEAKURL_ENV'] );
			}
		}
	}

	public function test_lock_users_orders_numeric_ids_ascending(): void {
		$db           = $this->createMock( PeakURL_DB::class );
		$locked_order = array();

		$db->method( 'get_row' )
			->willReturnCallback(
				function ( string $query, array $params ) use ( &$locked_order ) {
					$locked_order[] = $params['id'];
					return array(
						'id'   => $params['id'],
						'role' => 'admin',
					);
				}
			);

		$users_api = new UsersApi( $db );
		// Pass IDs in non-sorted order including multi-digit strings (100, 2, 10)
		$result = $users_api->lock_users( array( '100', '2', '10', '2' ) );

		// Lexicographical sort would yield ['10', '100', '2'], but numeric sort must yield ['2', '10', '100']
		$this->assertSame( array( '2', '10', '100' ), $locked_order );
		$this->assertArrayHasKey( '2', $result );
		$this->assertArrayHasKey( '10', $result );
		$this->assertArrayHasKey( '100', $result );
	}

	public function test_update_user_prevents_stale_role_demotion_of_concurrently_promoted_admin(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		// Pre-transaction snapshot: role is 'editor'
		$stale_target_snapshot = array(
			'id'       => 'user-2',
			'username' => 'target_user',
			'role'     => 'editor',
		);
		// Locked authoritative row: concurrently promoted to 'admin' before lock was acquired
		$locked_authoritative_admin = array(
			'id'       => 'user-2',
			'username' => 'target_user',
			'role'     => 'admin',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'target_user' )->willReturn( $stale_target_snapshot );

		// lock_users is invoked because role is supplied in payload, even though payload role matches stale snapshot
		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'user-2' ) )
			->willReturn(
				array(
					'admin-1' => $acting_admin,
					'user-2'  => $locked_authoritative_admin,
				)
			);

		// Exactly 1 admin remains if target is demoted
		$users_api->expects( $this->once() )
			->method( 'count_admin_users_for_update' )
			->willReturn( 1 );

		$db->expects( $this->never() )->method( 'update' );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		// Payload requests role 'editor', matching stale snapshot but demoting the locked admin
		$request = new Request( 'PUT', '/api/v1/users/target_user', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'At least one admin account must remain on the site.' );

		$service->update_user_by_username(
			$request,
			'target_user',
			array( 'role' => 'editor' )
		);
	}

	public function test_update_user_under_lock_handles_disappearing_target_and_unauthorized_acting_admin(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		$target_user  = array(
			'id'       => 'user-2',
			'username' => 'target_user',
			'role'     => 'editor',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'target_user' )->willReturn( $target_user );

		// Target disappeared between pre-check and lock
		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'user-2' ) )
			->willReturn(
				array(
					'admin-1' => $acting_admin,
					'user-2'  => null,
				)
			);

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		$request = new Request( 'PUT', '/api/v1/users/target_user', array(), array() );

		try {
			$service->update_user_by_username( $request, 'target_user', array( 'role' => 'admin' ) );
			$this->fail( 'Expected 404 ApiException when target user disappears before lock' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertSame( 'User not found.', $e->getMessage() );
		}
	}

	public function test_update_user_noop_role_request_does_not_trigger_unnecessary_role_mutation(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );
		$webhooks_service  = $this->createMock( WebhooksService::class );

		$this->bind_stateful_transactions( $db );

		$acting_admin = array(
			'id'       => 'admin-1',
			'username' => 'admin_one',
			'role'     => 'admin',
		);
		$editor_user  = array(
			'id'         => 'editor-2',
			'username'   => 'editor_two',
			'email'      => 'editor2@example.com',
			'first_name' => 'Editor',
			'last_name'  => 'Two',
			'role'       => 'editor',
		);

		$auth_service->method( 'get_admin_user' )->willReturn( $acting_admin );
		$users_api->method( 'get_user_by_username' )->with( 'editor_two' )->willReturn( $editor_user );
		$users_api->method( 'get_user' )->with( 'editor-2' )->willReturn( $editor_user );
		$auth_service->method( 'format_user' )->willReturnCallback( fn( array $u ): array => $u );

		// Locked state confirms target is indeed still 'editor'
		$users_api->method( 'lock_users' )
			->with( array( 'admin-1', 'editor-2' ) )
			->willReturn(
				array(
					'admin-1'  => $acting_admin,
					'editor-2' => $editor_user,
				)
			);

		// Since role does not actually change, count_admin_users_for_update must NOT be called
		$users_api->expects( $this->never() )->method( 'count_admin_users_for_update' );

		$db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->callback( fn( array $d ): bool => 'editor' === $d['role'] ),
				array( 'id' => 'editor-2' )
			)
			->willReturn( 1 );

		$service = $this->create_users_service(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview,
			$webhooks_service
		);

		$request = new Request( 'PUT', '/api/v1/users/editor_two', array(), array() );
		$result  = $service->update_user_by_username(
			$request,
			'editor_two',
			array( 'role' => 'editor' )
		);

		$this->assertSame( 'editor', $result['role'] );
	}

	public function test_clean_test_tables_drops_all_schema_tables_including_api_keys_and_preserves_foreign_key_checks(): void {
		$base_config = Configuration::get_current();
		if ( ! self::is_test_database_eligible( $base_config ) ) {
			$this->markTestSkipped( 'Refusing to run DB cleanup tests: environment is not an approved test database.' );
		}

		$pdo         = ( new Connection( $base_config ) )->get_connection();
		$test_prefix = 'test_usr_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';
		$tables      = self::get_schema_tables();
		$this->assertContains( 'api_keys', $tables );

		// Set FOREIGN_KEY_CHECKS to 1 explicitly
		$pdo->exec( 'SET FOREIGN_KEY_CHECKS = 1' );
		$initial_fk = $pdo->query( 'SELECT @@FOREIGN_KEY_CHECKS' )->fetchColumn();
		$this->assertSame( 1, (int) $initial_fk );

		// Create all 12 schema tables under $test_prefix
		foreach ( $tables as $table ) {
			$pdo->exec( "CREATE TABLE IF NOT EXISTS `{$test_prefix}{$table}` (id INT PRIMARY KEY)" );
		}

		// Also create an unrelated non-fixture table
		$unrelated_table = 'unrelated_safe_table_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		$pdo->exec( "CREATE TABLE IF NOT EXISTS `{$unrelated_table}` (id INT PRIMARY KEY)" );

		try {
			// Assert that test tables exist
			$created_count = 0;
			foreach ( $tables as $table ) {
				$check = $pdo->query( "SHOW TABLES LIKE '{$test_prefix}{$table}'" )->fetchColumn();
				if ( false !== $check && null !== $check ) {
					++$created_count;
				}
			}
			$this->assertSame( count( $tables ), $created_count );

			// Perform clean_test_tables
			$this->clean_test_tables( $pdo, array( $test_prefix ) );

			// Assert ALL test tables including api_keys are dropped
			foreach ( $tables as $table ) {
				$check = $pdo->query( "SHOW TABLES LIKE '{$test_prefix}{$table}'" )->fetchColumn();
				$this->assertFalse( $check, "Table {$test_prefix}{$table} must be dropped" );
			}

			// Assert unrelated table survives
			$unrelated_check = $pdo->query( "SHOW TABLES LIKE '{$unrelated_table}'" )->fetchColumn();
			$this->assertNotEmpty( $unrelated_check, 'Unrelated table must survive clean_test_tables' );

			// Assert FOREIGN_KEY_CHECKS was restored to initial value
			$final_fk = $pdo->query( 'SELECT @@FOREIGN_KEY_CHECKS' )->fetchColumn();
			$this->assertSame( (int) $initial_fk, (int) $final_fk, 'FOREIGN_KEY_CHECKS must be restored to previous session value' );
		} finally {
			$pdo->exec( "DROP TABLE IF EXISTS `{$unrelated_table}`" );
		}
	}

	public function test_sequential_real_db_admin_deletions_service_level_preserves_last_admin_invariant(): void {
		list( $connection1, $db1, $pdo1, $prefix ) = $this->setup_isolated_db();

		// Insert 2 admins and a short link owned by admin2
		$pdo1->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(95001, 'admin_alpha', 'alpha@example.com', 'Alpha', 'Admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(95002, 'admin_beta', 'beta@example.com', 'Beta', 'Admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$pdo1->exec(
			"INSERT INTO `{$prefix}urls` (id, user_id, short_code, alias, destination_url, title, status, created_at, updated_at)
			VALUES
			('url_beta_95002', '95002', 'betalink', 'betalink', 'https://example.com/target', 'Beta Link', 'active', NOW(), NOW())"
		);

		// Second independent connection to the same isolated test schema
		$base_config  = Configuration::get_current();
		$conn2_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$connection2  = new Connection( $conn2_config );
		$db2          = new PeakURL_DB( $connection2 );

		// Set up services for connection 1 (acting as admin_alpha) and connection 2 (acting as admin_beta)
		$auth1 = $this->createMock( AuthService::class );
		$auth1->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '95001',
				'username' => 'admin_alpha',
				'role'     => 'admin',
			)
		);
		$auth1->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '95001',
				'username' => 'admin_alpha',
				'role'     => 'admin',
			)
		);

		$auth2 = $this->createMock( AuthService::class );
		$auth2->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '95002',
				'username' => 'admin_beta',
				'role'     => 'admin',
			)
		);
		$auth2->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '95002',
				'username' => 'admin_beta',
				'role'     => 'admin',
			)
		);

		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$validator     = new UsersValidator();

		$service1 = $this->create_users_service(
			$db1,
			new UsersApi( $db1 ),
			$auth1,
			null,
			$validator,
			$roles,
			$authorization
		);

		$service2 = $this->create_users_service(
			$db2,
			new UsersApi( $db2 ),
			$auth2,
			null,
			$validator,
			$roles,
			$authorization
		);

		$req1 = new Request( 'DELETE', '/api/v1/users/admin_beta', array(), array() );
		$req2 = new Request( 'DELETE', '/api/v1/users/admin_alpha', array(), array() );

		// Operation 1 deletes admin_beta
		$deleted1 = $service1->delete_user_by_username( $req1, 'admin_beta' );
		$this->assertTrue( $deleted1, 'First deletion of admin_beta should succeed' );

		// Verify the link previously owned by admin_beta was transferred to acting admin_alpha
		$transferred_link_owner = $db1->get_var(
			"SELECT user_id FROM `{$prefix}urls` WHERE id = 'url_beta_95002'"
		);
		$this->assertSame( '95001', (string) $transferred_link_owner, 'Ownership must be transferred to acting admin' );

		// Now Operation 2 attempts to delete admin_alpha via service 2 (where admin_beta was already deleted)
		$op2_failed_safely = false;
		try {
			$service2->delete_user_by_username( $req2, 'admin_alpha' );
		} catch ( ApiException $e ) {
			// Either 403 (acting admin no longer exists / not admin) or 422 (cannot delete last admin)
			$op2_failed_safely = in_array( $e->getCode(), array( 403, 422 ), true );
		}

		$this->assertTrue( $op2_failed_safely, 'Second deletion must be safely rejected by service-level checks' );

		// INVARIANT: At least one administrator remains active in the database
		$surviving_admin_count = (int) $db1->get_var(
			"SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'admin'"
		);
		$this->assertGreaterThanOrEqual( 1, $surviving_admin_count, 'Last administrator invariant must hold: >= 1 admin in DB' );
		$this->assertSame( 1, $surviving_admin_count );

		$surviving_admin = $db1->get_row( "SELECT username, role FROM `{$prefix}users` WHERE id = 95001" );
		$this->assertSame( 'admin_alpha', $surviving_admin['username'] );
		$this->assertSame( 'admin', $surviving_admin['role'] );
	}

	public function test_sequential_real_db_admin_demotion_service_level_preserves_last_admin_invariant(): void {
		list( $connection1, $db1, $pdo1, $prefix ) = $this->setup_isolated_db();

		$pdo1->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(96001, 'admin_one', 'one@example.com', 'Admin', 'One', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(96002, 'admin_two', 'two@example.com', 'Admin', 'Two', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$base_config  = Configuration::get_current();
		$conn2_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$connection2  = new Connection( $conn2_config );
		$db2          = new PeakURL_DB( $connection2 );

		$auth1 = $this->createMock( AuthService::class );
		$auth1->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '96001',
				'username' => 'admin_one',
				'role'     => 'admin',
			)
		);
		$auth1->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '96001',
				'username' => 'admin_one',
				'role'     => 'admin',
			)
		);
		$auth1->method( 'format_user' )->willReturnCallback( fn( array $u ): array => $u );

		$auth2 = $this->createMock( AuthService::class );
		$auth2->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '96002',
				'username' => 'admin_two',
				'role'     => 'admin',
			)
		);
		$auth2->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '96002',
				'username' => 'admin_two',
				'role'     => 'admin',
			)
		);
		$auth2->method( 'format_user' )->willReturnCallback( fn( array $u ): array => $u );

		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$validator     = new UsersValidator();

		$service1 = $this->create_users_service(
			$db1,
			new UsersApi( $db1 ),
			$auth1,
			null,
			$validator,
			$roles,
			$authorization
		);

		$service2 = $this->create_users_service(
			$db2,
			new UsersApi( $db2 ),
			$auth2,
			null,
			$validator,
			$roles,
			$authorization
		);

		$req1 = new Request( 'PUT', '/api/v1/users/admin_two', array(), array() );
		$req2 = new Request( 'PUT', '/api/v1/users/admin_one', array(), array() );

		// Admin One demotes Admin Two to editor
		$res1 = $service1->update_user_by_username( $req1, 'admin_two', array( 'role' => 'editor' ) );
		$this->assertSame( 'editor', $res1['role'] );

		// Now Admin Two attempts to demote Admin One (the last remaining admin)
		$op2_failed_safely = false;
		try {
			$service2->update_user_by_username( $req2, 'admin_one', array( 'role' => 'editor' ) );
		} catch ( ApiException $e ) {
			// Either 403 (Admin Two is now an editor!) or 422 (cannot demote last admin)
			$op2_failed_safely = in_array( $e->getCode(), array( 403, 422 ), true );
		}

		$this->assertTrue( $op2_failed_safely, 'Second demotion must fail safely' );

		// INVARIANT: At least one admin account remains on the site
		$admins_count = (int) $db1->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'admin'" );
		$this->assertSame( 1, $admins_count, 'Exactly 1 admin must remain' );
	}

	public function test_user_deletion_under_competing_sql_deletion_preserves_last_admin_invariant(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(95001, 'admin_alpha', 'alpha@example.com', 'Alpha', 'Admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(95002, 'admin_beta', 'beta@example.com', 'Beta', 'Admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}urls` (id, user_id, short_code, alias, destination_url, title, status, created_at, updated_at)
			VALUES
			('url_beta_95002', '95002', 'betalink', 'betalink', 'https://example.com/target', 'Beta Link', 'active', NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		$auth_alpha = $this->createMock( AuthService::class );
		$auth_alpha->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '95001',
				'username' => 'admin_alpha',
				'role'     => 'admin',
			)
		);
		$auth_alpha->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '95001',
				'username' => 'admin_alpha',
				'role'     => 'admin',
			)
		);

		$auth_beta = $this->createMock( AuthService::class );
		$auth_beta->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '95002',
				'username' => 'admin_beta',
				'role'     => 'admin',
			)
		);
		$auth_beta->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '95002',
				'username' => 'admin_beta',
				'role'     => 'admin',
			)
		);

		$analytics_mock = $this->createMock( AnalyticsService::class );
		$social_mock    = $this->createMock( SocialPreview::class );
		$webhooks_mock  = $this->createMock( WebhooksService::class );
		$links_mock     = $this->createMock( LinksApi::class );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				// Process A (direct SQL lock holder) acquires exclusive row lock on both admin users (in sorted order: 95001, 95002)
				$pdo->beginTransaction();
				$stmt = $pdo->prepare( "SELECT * FROM `{$prefix}users` WHERE id IN ('95001', '95002') ORDER BY id ASC FOR UPDATE" );
				$stmt->execute();
				$stmt->fetchAll();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				// Wait for Process B to signal it is actively attempting the service deletion
				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				// Harness verifies that Process B is genuinely blocked in MySQL InnoDB on the expected sessions
				$contention_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}users" );

				// Process A deletes admin_beta (95002) and updates link ownership to 95001
				$pdo->exec( "UPDATE `{$prefix}urls` SET user_id = '95001' WHERE user_id = '95002'" );
				$pdo->exec( "DELETE FROM `{$prefix}users` WHERE id = '95002'" );
				$pdo->commit();

				return array(
					'contention_observed' => $contention_observed,
				);
			},
			function ( $sock ) use ( $conn_config, $auth_beta, $analytics_mock, $social_mock, $webhooks_mock, $links_mock ) {
				$conn          = new Connection( $conn_config );
				$db            = new PeakURL_DB( $conn );
				$users_api     = new UsersApi( $db );
				$roles         = new Roles();
				$authorization = new Authorization( $roles );
				$validator     = new UsersValidator();
				$service       = new UsersService(
					$db,
					$users_api,
					$auth_beta,
					$analytics_mock,
					$validator,
					$roles,
					$authorization,
					$social_mock,
					$webhooks_mock,
					$links_mock
				);

				// Wait until Process A has acquired exclusive row lock
				fgets( $sock );
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();

				// Signal ATTEMPTING immediately before invoking the production service method
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				$req = new Request( 'DELETE', '/api/v1/users/admin_alpha', array(), array() );
				try {
					$deleted = $service->delete_user_by_username( $req, 'admin_alpha' );
					return array( 'deleted' => $deleted );
				} catch ( ApiException $e ) {
					return array(
						'deleted' => false,
						'code'    => $e->getCode(),
						'message' => $e->getMessage(),
					);
				}
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified in performance_schema.data_lock_waits.' );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertFalse( $child_res['result']['deleted'], 'Child deletion must fail because acting user was deleted or only 1 administrator remains under contention.' );
		$this->assertTrue( in_array( $child_res['result']['code'] ?? 0, array( 403, 422 ), true ), 'Child deletion must receive 403 or 422 authorization/administrator protection.' );

		// Assert authoritative database end-state: exactly 1 administrator survives
		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );

		$surviving_admins = (int) $verify_db->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'admin'" );
		$this->assertSame( 1, $surviving_admins, 'Last administrator invariant must hold: exactly 1 admin remains.' );

		// The short link must be preserved and owned by the surviving admin
		$link_owner         = (string) $verify_db->get_var( "SELECT user_id FROM `{$prefix}urls` WHERE id = 'url_beta_95002'" );
		$surviving_admin_id = (string) $verify_db->get_var( "SELECT id FROM `{$prefix}users` WHERE role = 'admin'" );
		$this->assertSame( $surviving_admin_id, $link_owner, 'Preserved links must be transferred to surviving administrator.' );
	}

	public function test_user_demotion_under_competing_sql_demotion_preserves_last_admin_invariant(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(96001, 'admin_one', 'one@example.com', 'Admin', 'One', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(96002, 'admin_two', 'two@example.com', 'Admin', 'Two', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		$auth2 = $this->createMock( AuthService::class );
		$auth2->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '96002',
				'username' => 'admin_two',
				'role'     => 'admin',
			)
		);
		$auth2->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '96002',
				'username' => 'admin_two',
				'role'     => 'admin',
			)
		);
		$auth2->method( 'format_user' )->willReturnCallback( fn( array $u ): array => $u );

		$analytics_mock = $this->createMock( AnalyticsService::class );
		$social_mock    = $this->createMock( SocialPreview::class );
		$webhooks_mock  = $this->createMock( WebhooksService::class );
		$links_mock     = $this->createMock( LinksApi::class );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				// Process A (direct SQL lock holder) acquires exclusive row lock on both admin users (96001, 96002)
				$pdo->beginTransaction();
				$stmt = $pdo->prepare( "SELECT * FROM `{$prefix}users` WHERE id IN ('96001', '96002') ORDER BY id ASC FOR UPDATE" );
				$stmt->execute();
				$stmt->fetchAll();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				// Wait for Process B to signal it is actively attempting demotion
				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				// Verify observable lock contention in InnoDB data_lock_waits
				$contention_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}users" );

				// Process A demotes admin_two to editor
				$pdo->exec( "UPDATE `{$prefix}users` SET role = 'editor' WHERE id = '96002'" );
				$pdo->commit();

				return array( 'contention_observed' => $contention_observed );
			},
			function ( $sock ) use ( $conn_config, $auth2, $analytics_mock, $social_mock, $webhooks_mock, $links_mock ) {
				$conn          = new Connection( $conn_config );
				$db            = new PeakURL_DB( $conn );
				$users_api     = new UsersApi( $db );
				$roles         = new Roles();
				$authorization = new Authorization( $roles );
				$validator     = new UsersValidator();
				$service       = new UsersService(
					$db,
					$users_api,
					$auth2,
					$analytics_mock,
					$validator,
					$roles,
					$authorization,
					$social_mock,
					$webhooks_mock,
					$links_mock
				);

				fgets( $sock ); // wait for LOCK_ACQUIRED
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				$req = new Request( 'PUT', '/api/v1/users/admin_one', array(), array() );
				try {
					$res = $service->update_user_by_username( $req, 'admin_one', array( 'role' => 'editor' ) );
					return array(
						'demoted' => true,
						'result'  => $res,
					);
				} catch ( ApiException $e ) {
					return array(
						'demoted' => false,
						'code'    => $e->getCode(),
						'message' => $e->getMessage(),
					);
				}
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertFalse( $child_res['result']['demoted'], 'Child demotion must fail because user was demoted or only 1 admin remains.' );
		$this->assertTrue( in_array( $child_res['result']['code'] ?? 0, array( 403, 422 ), true ), 'Child must receive 403 or 422 last administrator / authorization protection.' );

		$verify_conn   = new Connection( $conn_config );
		$verify_db     = new PeakURL_DB( $verify_conn );
		$admins_count  = (int) $verify_db->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'admin'" );
		$editors_count = (int) $verify_db->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'editor'" );

		$this->assertSame( 1, $admins_count, 'Exactly 1 admin must remain in database.' );
		$this->assertSame( 1, $editors_count, 'Exactly 1 editor must be present.' );
	}

	public function test_user_demotion_under_competing_sql_deletion_preserves_last_admin_invariant(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(97001, 'admin_x', 'x@example.com', 'Admin', 'X', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(97002, 'admin_y', 'y@example.com', 'Admin', 'Y', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		$auth_y = $this->createMock( AuthService::class );
		$auth_y->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '97002',
				'username' => 'admin_y',
				'role'     => 'admin',
			)
		);
		$auth_y->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '97002',
				'username' => 'admin_y',
				'role'     => 'admin',
			)
		);
		$auth_y->method( 'format_user' )->willReturnCallback( fn( array $u ): array => $u );

		$analytics_mock = $this->createMock( AnalyticsService::class );
		$social_mock    = $this->createMock( SocialPreview::class );
		$webhooks_mock  = $this->createMock( WebhooksService::class );
		$links_mock     = $this->createMock( LinksApi::class );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				// Process A (direct SQL lock holder) acquires exclusive row lock on both admin users (97001, 97002)
				$pdo->beginTransaction();
				$stmt = $pdo->prepare( "SELECT * FROM `{$prefix}users` WHERE id IN ('97001', '97002') ORDER BY id ASC FOR UPDATE" );
				$stmt->execute();
				$stmt->fetchAll();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				$contention_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}users" );

				// Process A deletes admin_y (97002)
				$pdo->exec( "DELETE FROM `{$prefix}users` WHERE id = '97002'" );
				$pdo->commit();

				return array( 'contention_observed' => $contention_observed );
			},
			function ( $sock ) use ( $conn_config, $auth_y, $analytics_mock, $social_mock, $webhooks_mock, $links_mock ) {
				$conn          = new Connection( $conn_config );
				$db            = new PeakURL_DB( $conn );
				$users_api     = new UsersApi( $db );
				$roles         = new Roles();
				$authorization = new Authorization( $roles );
				$validator     = new UsersValidator();
				$service       = new UsersService(
					$db,
					$users_api,
					$auth_y,
					$analytics_mock,
					$validator,
					$roles,
					$authorization,
					$social_mock,
					$webhooks_mock,
					$links_mock
				);

				fgets( $sock ); // wait for LOCK_ACQUIRED
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				// Admin Y attempts to demote Admin X
				$req = new Request( 'PUT', '/api/v1/users/admin_x', array(), array() );
				try {
					$res = $service->update_user_by_username( $req, 'admin_x', array( 'role' => 'editor' ) );
					return array(
						'demoted' => true,
						'result'  => $res,
					);
				} catch ( ApiException $e ) {
					return array(
						'demoted' => false,
						'code'    => $e->getCode(),
						'message' => $e->getMessage(),
					);
				}
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertFalse( $child_res['result']['demoted'], 'Child demotion must fail because admin_y was deleted under contention.' );

		$verify_conn  = new Connection( $conn_config );
		$verify_db    = new PeakURL_DB( $verify_conn );
		$admins_count = (int) $verify_db->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'admin'" );
		$this->assertSame( 1, $admins_count, 'At least one admin must remain in the database.' );
	}

	public function test_user_deletion_waits_for_locked_admin_row_and_succeeds_after_release(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(98001, 'admin_a', 'a@example.com', 'Admin', 'A', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(98002, 'admin_b', 'b@example.com', 'Admin', 'B', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(98003, 'admin_c', 'c@example.com', 'Admin', 'C', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		$auth_a = $this->createMock( AuthService::class );
		$auth_a->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '98001',
				'username' => 'admin_a',
				'role'     => 'admin',
			)
		);
		$auth_a->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '98001',
				'username' => 'admin_a',
				'role'     => 'admin',
			)
		);

		$analytics_mock = $this->createMock( AnalyticsService::class );
		$social_mock    = $this->createMock( SocialPreview::class );
		$webhooks_mock  = $this->createMock( WebhooksService::class );
		$links_mock     = $this->createMock( LinksApi::class );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $prefix ) {
				$conn = new Connection( $conn_config );
				$pdo  = $conn->get_connection();

				// Process A (direct SQL lock holder) acquires exclusive row lock on admin_b (98002)
				$pdo->beginTransaction();
				$stmt = $pdo->prepare( "SELECT * FROM `{$prefix}users` WHERE id = '98002' FOR UPDATE" );
				$stmt->execute();
				$stmt->fetch();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				$contention_observed = $this->observe_innodb_lock_wait( $pdo, (int) $waiter_conn_id, $blocker_conn_id, "{$prefix}users" );

				// Release the lock so Child can proceed
				$pdo->rollBack();

				return array( 'contention_observed' => $contention_observed );
			},
			function ( $sock ) use ( $conn_config, $auth_a, $analytics_mock, $social_mock, $webhooks_mock, $links_mock ) {
				$conn          = new Connection( $conn_config );
				$db            = new PeakURL_DB( $conn );
				$users_api     = new UsersApi( $db );
				$roles         = new Roles();
				$authorization = new Authorization( $roles );
				$validator     = new UsersValidator();
				$service       = new UsersService(
					$db,
					$users_api,
					$auth_a,
					$analytics_mock,
					$validator,
					$roles,
					$authorization,
					$social_mock,
					$webhooks_mock,
					$links_mock
				);

				fgets( $sock ); // wait for LOCK_ACQUIRED
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				// Admin A deletes Admin B
				$req = new Request( 'DELETE', '/api/v1/users/admin_b', array(), array() );
				return $service->delete_user_by_username( $req, 'admin_b' );
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'Observable InnoDB lock contention must be verified.' );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertTrue( $child_res['result'], 'Child deletion of admin_b must succeed.' );

		$verify_conn  = new Connection( $conn_config );
		$verify_db    = new PeakURL_DB( $verify_conn );
		$admins_count = (int) $verify_db->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'admin'" );

		$this->assertSame( 2, $admins_count, 'Exactly 2 admins (A and C) must remain in the database.' );
	}

	public function test_genuine_service_vs_service_concurrent_admin_deletions_guarantee_single_admin_survivor(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES
			(99001, 'admin_prime', 'prime@example.com', 'Admin', 'Prime', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW()),
			(99002, 'admin_peer', 'peer@example.com', 'Admin', 'Peer', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())"
		);

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}urls` (id, user_id, short_code, alias, destination_url, title, status, created_at, updated_at)
			VALUES
			('url_peer_99002', '99002', 'peerlink', 'peerlink', 'https://example.com/peer', 'Peer Link', 'active', NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config = Configuration::get_current();
		$conn_config = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );

		$auth_prime = $this->createMock( AuthService::class );
		$auth_prime->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '99001',
				'username' => 'admin_prime',
				'role'     => 'admin',
			)
		);
		$auth_prime->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '99001',
				'username' => 'admin_prime',
				'role'     => 'admin',
			)
		);

		$auth_peer = $this->createMock( AuthService::class );
		$auth_peer->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '99002',
				'username' => 'admin_peer',
				'role'     => 'admin',
			)
		);
		$auth_peer->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '99002',
				'username' => 'admin_peer',
				'role'     => 'admin',
			)
		);

		$social_mock   = $this->createMock( SocialPreview::class );
		$webhooks_mock = $this->createMock( WebhooksService::class );
		$links_mock    = $this->createMock( LinksApi::class );

		list( $parent_res, $child_res ) = $this->run_concurrent_test_processes(
			function ( $sock ) use ( $conn_config, $auth_prime, $social_mock, $webhooks_mock, $links_mock, $prefix ) {
				$conn          = new Connection( $conn_config );
				$db            = new PeakURL_DB( $conn );
				$pdo           = $conn->get_connection();
				$users_api     = new UsersApi( $db );
				$roles         = new Roles();
				$authorization = new Authorization( $roles );
				$validator     = new UsersValidator();

				$contention_observed = false;
				$analytics_mock      = $this->createMock( AnalyticsService::class );
				$analytics_mock->method( 'record_activity' )->willReturnCallback(
					function () use ( $sock, $pdo, $prefix, &$contention_observed ) {
						$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
						fwrite( $sock, "WORKER1_LOCKED:{$blocker_conn_id}\n" );
						fflush( $sock );

						$waiter_line    = trim( (string) fgets( $sock ) );
						$waiter_conn_id = 0;
						if ( false !== strpos( $waiter_line, ':' ) ) {
							list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
						}

						$contention_observed = $this->observe_innodb_lock_wait(
							$pdo,
							(int) $waiter_conn_id,
							$blocker_conn_id,
							"{$prefix}users"
						);
					}
				);

				$service = new UsersService(
					$db,
					$users_api,
					$auth_prime,
					$analytics_mock,
					$validator,
					$roles,
					$authorization,
					$social_mock,
					$webhooks_mock,
					$links_mock
				);

				$req     = new Request( 'DELETE', '/api/v1/users/admin_peer', array(), array() );
				$deleted = $service->delete_user_by_username( $req, 'admin_peer' );

				return array(
					'deleted'             => $deleted,
					'contention_observed' => $contention_observed,
				);
			},
			function ( $sock ) use ( $conn_config, $auth_peer, $social_mock, $webhooks_mock, $links_mock ) {
				$conn          = new Connection( $conn_config );
				$db            = new PeakURL_DB( $conn );
				$users_api     = new UsersApi( $db );
				$roles         = new Roles();
				$authorization = new Authorization( $roles );
				$validator     = new UsersValidator();
				$analytics     = $this->createMock( AnalyticsService::class );

				$service = new UsersService(
					$db,
					$users_api,
					$auth_peer,
					$analytics,
					$validator,
					$roles,
					$authorization,
					$social_mock,
					$webhooks_mock,
					$links_mock
				);

				// Wait until Worker 1 acquires exclusive row locks and reaches record_activity inside its transaction
				fgets( $sock );
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				// Worker 2 calls genuine UsersService method attempting to delete admin_prime; blocks on row locks
				$req = new Request( 'DELETE', '/api/v1/users/admin_prime', array(), array() );
				try {
					$deleted = $service->delete_user_by_username( $req, 'admin_prime' );
					return array( 'deleted' => $deleted );
				} catch ( ApiException $e ) {
					return array(
						'deleted' => false,
						'code'    => $e->getCode(),
						'message' => $e->getMessage(),
					);
				}
			}
		);

		$this->assertSame( 'ok', $parent_res['status'], 'Worker 1 must complete: ' . ( $parent_res['message'] ?? '' ) );
		$this->assertTrue( $parent_res['result']['deleted'], 'Worker 1 service deletion of admin_peer must succeed.' );
		$this->assertTrue( $parent_res['result']['contention_observed'], 'InnoDB row lock contention must be verified between service sessions.' );
		$this->assertSame( 'ok', $child_res['status'], 'Worker 2 must complete safely: ' . ( $child_res['message'] ?? '' ) );
		$this->assertFalse( $child_res['result']['deleted'], 'Worker 2 deletion must fail because acting user was deleted by Worker 1.' );
		$this->assertSame( 403, $child_res['result']['code'], 'Worker 2 must receive 403 Administrator access required.' );

		$verify_conn      = new Connection( $conn_config );
		$verify_db        = new PeakURL_DB( $verify_conn );
		$surviving_admins = (int) $verify_db->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'admin'" );
		$this->assertSame( 1, $surviving_admins, 'Exactly 1 admin must remain in the database under genuine service-vs-service concurrency.' );

		$transferred_owner = (string) $verify_db->get_var( "SELECT user_id FROM `{$prefix}urls` WHERE id = 'url_peer_99002'" );
		$this->assertSame( '99001', $transferred_owner, 'Links owned by deleted admin must be transferred to surviving admin.' );
	}

	public function test_user_lifecycle_creation_and_retrieval_with_custom_role(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$custom_roles_data = array(
			'custom_roles' => array(
				'support_lead' => array(
					'name'                 => 'Support Lead',
					'description'          => 'Customer support lead role',
					'initial_capabilities' => array(
						'view_links'   => true,
						'create_links' => true,
						'edit_links'   => true,
					),
					'capabilities'         => array(
						'view_links'   => true,
						'create_links' => true,
						'edit_links'   => true,
					),
				),
			),
		);
		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $custom_roles_data ) . "', 1, NOW())"
		);

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at) " .
			"VALUES (88001, 'acting_admin', 'acting_admin@example.com', 'Super', 'Admin', 'hash', 'admin', 1, NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config   = Configuration::get_current();
		$conn_config   = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$conn          = new Connection( $conn_config );
		$db            = new PeakURL_DB( $conn );
		$settings_api  = new SettingsApi( $db );
		$users_api     = new UsersApi( $db );
		$roles         = new Roles( $settings_api, $users_api );
		$authorization = new Authorization( $roles );

		$auth_mock = $this->createMock( AuthService::class );
		$auth_mock->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '88001',
				'username' => 'acting_admin',
				'role'     => 'admin',
			)
		);
		$auth_mock->method( 'email_in_use' )->willReturn( false );
		$auth_mock->method( 'username_in_use' )->willReturn( false );
		$auth_mock->method( 'format_user' )->willReturnCallback(
			function ( ?array $row ) use ( $roles ) {
				if ( ! $row ) {
					return array();
				}
				return array(
					'id'           => (string) $row['id'],
					'username'     => (string) $row['username'],
					'email'        => (string) $row['email'],
					'role'         => (string) $row['role'],
					'roleName'     => $roles->get_role_name( (string) $row['role'] ),
					'displayName'  => $row['display_name'] ?? null,
					'capabilities' => $roles->capabilities_for_role( (string) $row['role'] ),
				);
			}
		);

		$users_service = $this->create_users_service(
			$db,
			$users_api,
			$auth_mock,
			null,
			null,
			$roles,
			$authorization
		);

		$created = $users_service->create_user(
			new Request( 'POST', '/api/v1/users', array(), array() ),
			array(
				'username'    => 'support_agent_jane',
				'email'       => 'jane@support.example.com',
				'password'    => 'SecurePass123!#',
				'firstName'   => 'Jane',
				'lastName'    => 'Doe',
				'displayName' => 'Support Jane',
				'role'        => 'support_lead',
			)
		);

		$this->assertSame( 'support_lead', $created['role'] );
		$this->assertSame( 'Support Jane', $created['displayName'] );
		$this->assertSame( 'Support Lead', $created['roleName'] );
		$this->assertTrue( $created['capabilities']['create_links'] );
		$this->assertTrue( $created['capabilities']['edit_links'] );
		$this->assertFalse( $created['capabilities']['delete_links'] );
		$this->assertFalse( $created['capabilities']['manage_users'] );

		$fetched = $users_api->get_user( (string) $created['id'] );
		$this->assertNotNull( $fetched );
		$this->assertSame( 'support_lead', $fetched['role'] );
		$this->assertSame( 'Support Jane', $fetched['display_name'] );

		$formatted = $users_service->format_user( $fetched );
		$this->assertSame( 'support_lead', $formatted['role'] );
		$this->assertSame( 'Support Lead', $formatted['roleName'] );
		$this->assertSame( 'Support Jane', $formatted['displayName'] );
		$this->assertSame( $created['capabilities'], $formatted['capabilities'] );
	}

	public function test_user_lifecycle_role_transitions_and_rejection_of_invalid_or_deleted_roles(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$custom_roles_data = array(
			'custom_roles' => array(
				'analyst' => array(
					'name'                 => 'Analyst Role',
					'description'          => 'Analyst',
					'initial_capabilities' => array( 'view_links' => true ),
					'capabilities'         => array( 'view_links' => true ),
				),
				'manager' => array(
					'name'                 => 'Manager Role',
					'description'          => 'Manager',
					'initial_capabilities' => array(
						'view_links'   => true,
						'create_links' => true,
					),
					'capabilities'         => array(
						'view_links'   => true,
						'create_links' => true,
					),
				),
			),
		);
		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $custom_roles_data ) . "', 1, NOW())"
		);

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at) " .
			'VALUES ' .
			"(88010, 'admin_super', 'admin_super@example.com', 'Super', 'Admin', 'hash', 'admin', 1, NOW(), NOW()), " .
			"(88011, 'target_member', 'target_member@example.com', 'Target', 'Member', 'hash', 'editor', 1, NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config   = Configuration::get_current();
		$conn_config   = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$conn          = new Connection( $conn_config );
		$db            = new PeakURL_DB( $conn );
		$settings_api  = new SettingsApi( $db );
		$users_api     = new UsersApi( $db );
		$roles         = new Roles( $settings_api, $users_api );
		$authorization = new Authorization( $roles );

		$auth_mock = $this->createMock( AuthService::class );
		$auth_mock->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '88010',
				'username' => 'admin_super',
				'role'     => 'admin',
			)
		);
		$auth_mock->method( 'format_user' )->willReturnCallback(
			function ( ?array $row ) use ( $roles ) {
				if ( ! $row ) {
					return array();
				}
				return array(
					'id'       => (string) $row['id'],
					'username' => (string) $row['username'],
					'role'     => (string) $row['role'],
					'roleName' => $roles->get_role_name( (string) $row['role'] ),
				);
			}
		);

		$users_service = $this->create_users_service(
			$db,
			$users_api,
			$auth_mock,
			null,
			null,
			$roles,
			$authorization
		);

		$req = new Request( 'PUT', '/api/v1/users/target_member', array(), array() );

		// Transition 1: Editor -> Custom ('analyst')
		$res1 = $users_service->update_user_by_username( $req, 'target_member', array( 'role' => 'analyst' ) );
		$this->assertSame( 'analyst', $res1['role'] );
		$db_user1 = $users_api->get_user( '88011' );
		$this->assertSame( 'analyst', $db_user1['role'] );

		// Transition 2: Custom A ('analyst') -> Custom B ('manager')
		$res2 = $users_service->update_user_by_username( $req, 'target_member', array( 'role' => 'manager' ) );
		$this->assertSame( 'manager', $res2['role'] );
		$db_user2 = $users_api->get_user( '88011' );
		$this->assertSame( 'manager', $db_user2['role'] );

		// Transition 3: Custom B ('manager') -> Built-in ('editor')
		$res3 = $users_service->update_user_by_username( $req, 'target_member', array( 'role' => 'editor' ) );
		$this->assertSame( 'editor', $res3['role'] );
		$db_user3 = $users_api->get_user( '88011' );
		$this->assertSame( 'editor', $db_user3['role'] );

		// Rejection 1: Unknown role ID -> 422, no coercion
		try {
			$users_service->update_user_by_username( $req, 'target_member', array( 'role' => 'ghost_role' ) );
			$this->fail( 'Unknown role must throw 422 ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
		}
		$db_user_after_invalid = $users_api->get_user( '88011' );
		$this->assertSame( 'editor', $db_user_after_invalid['role'], 'Role must not be modified or coerced.' );

		// Rejection 2: Disallowed character syntax -> 422
		try {
			$users_service->update_user_by_username( $req, 'target_member', array( 'role' => 'bad!role' ) );
			$this->fail( 'Invalid role syntax must throw 422 ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
		}

		// Rejection 3: Nonexistent role in create_user -> 422
		try {
			$users_service->create_user(
				new Request( 'POST', '/api/v1/users', array(), array() ),
				array(
					'username'  => 'orphan_user',
					'email'     => 'orphan@example.com',
					'password'  => 'ValidPass123!#',
					'firstName' => 'Orphan',
					'lastName'  => 'User',
					'role'      => 'deleted_role_xyz',
				)
			);
			$this->fail( 'Create user with nonexistent role must throw 422 ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
		}
	}

	public function test_custom_role_with_all_delegable_capabilities_cannot_manage_users_or_empty_trash(): void {
		$settings_api = $this->createMock( SettingsApi::class );
		$users_api    = $this->createMock( UsersApi::class );

		$all_delegable = array();
		foreach ( Roles::CAPABILITIES_CATALOG as $cap_id => $meta ) {
			if ( in_array( 'editor', $meta['applicable_roles'] ?? array(), true ) ) {
				$all_delegable[ $cap_id ] = true;
			}
		}

		$settings_doc = array(
			'custom_roles' => array(
				'super_custom' => array(
					'name'                 => 'Super Custom',
					'description'          => 'Role with all delegable capabilities',
					'initial_capabilities' => $all_delegable,
					'capabilities'         => $all_delegable,
				),
			),
		);

		$settings_api->method( 'get_option' )->willReturn( json_encode( $settings_doc ) );

		$roles         = new Roles( $settings_api, $users_api );
		$authorization = new Authorization( $roles );

		$caps = $roles->capabilities_for_role( 'super_custom' );
		$this->assertFalse( $caps['manage_users'] ?? false, 'Non-admin role must never inherit manage_users.' );
		$this->assertFalse( $caps['empty_trash'] ?? false, 'Non-admin role must never inherit empty_trash.' );

		$custom_user = array(
			'id'   => '100',
			'role' => 'super_custom',
		);

		$this->assertFalse( $authorization->has_capability( $custom_user, 'manage_users' ) );
		$this->assertFalse( $authorization->has_capability( $custom_user, 'empty_trash' ) );

		try {
			$authorization->validate_capability( $custom_user, 'manage_users' );
			$this->fail( 'Must throw 403 on manage_users.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		try {
			$authorization->validate_capability( $custom_user, 'empty_trash' );
			$this->fail( 'Must throw 403 on empty_trash.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// Sole admin cannot be demoted to super_custom
		try {
			$authorization->validate_role_change(
				'1',
				'admin',
				'super_custom',
				'1',
				fn(): int => 1
			);
			$this->fail( 'Must prevent demoting last admin.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertStringContainsString( 'cannot demote the only remaining admin account', $e->getMessage() );
		}

		// Admin cannot self-demote even if multiple admins exist
		try {
			$authorization->validate_role_change(
				'1',
				'admin',
				'super_custom',
				'1',
				fn(): int => 2
			);
			$this->fail( 'Must prevent admin self-demotion.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertStringContainsString( 'cannot demote your own administrator account', $e->getMessage() );
		}

		// Corrupted settings containing admin-only capabilities for a custom role are strictly rejected
		$corrupted_settings_api = $this->createMock( SettingsApi::class );
		$corrupted_doc          = array(
			'custom_roles' => array(
				'hacked_role' => array(
					'name'                 => 'Hacked Role',
					'description'          => 'Tries to gain manage_users',
					'initial_capabilities' => array( 'view_links' => true ),
					'capabilities'         => array(
						'view_links'   => true,
						'manage_users' => true,
					),
				),
			),
		);
		$corrupted_settings_api->method( 'get_option' )->willReturn( json_encode( $corrupted_doc ) );
		$corrupted_roles = new Roles( $corrupted_settings_api, $users_api );

		try {
			$corrupted_roles->get_role_settings();
			$this->fail( 'Corrupted settings granting admin capability must fail with 500.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->getCode() );
			$this->assertStringContainsString( 'corrupted', $e->getMessage() );
		}

		// Administrator privileges remain immutable superuser even with corrupted custom roles in storage
		$admin_caps = $corrupted_roles->capabilities_for_role( 'admin' );
		$this->assertTrue( $admin_caps['manage_users'] );
		$this->assertTrue( $admin_caps['empty_trash'] );
		$this->assertTrue( ( new Authorization( $corrupted_roles ) )->has_capability( array( 'role' => 'admin' ), 'manage_users' ) );
	}

	public function test_own_profile_mutation_is_inherent_self_service_on_editor_and_custom_roles(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$custom_roles_data = array(
			'custom_roles' => array(
				'restricted_agent' => array(
					'name'                 => 'Restricted Agent',
					'description'          => 'Custom role without extra privileges',
					'initial_capabilities' => array(
						'view_links' => true,
					),
					'capabilities'         => array(
						'view_links' => true,
					),
				),
				'full_agent'       => array(
					'name'                 => 'Full Agent',
					'description'          => 'Full Agent role',
					'initial_capabilities' => array(
						'view_links'   => true,
						'create_links' => true,
					),
					'capabilities'         => array(
						'view_links'   => true,
						'create_links' => true,
					),
				),
			),
		);
		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $custom_roles_data ) . "', 1, NOW())"
		);

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, bio, password_hash, role, is_email_verified, created_at, updated_at) " .
			'VALUES ' .
			"(88020, 'restricted_u', 'restricted@example.com', 'Restricted', 'User', 'Initial Bio', 'hash', 'restricted_agent', 1, NOW(), NOW()), " .
			"(88021, 'full_u', 'full@example.com', 'Full', 'User', 'Initial Bio', 'hash', 'full_agent', 1, NOW(), NOW()), " .
			"(88022, 'editor_u', 'editor@example.com', 'Editor', 'User', 'Initial Bio', 'hash', 'editor', 1, NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config   = Configuration::get_current();
		$conn_config   = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$conn          = new Connection( $conn_config );
		$db            = new PeakURL_DB( $conn );
		$settings_api  = new SettingsApi( $db );
		$users_api     = new UsersApi( $db );
		$roles         = new Roles( $settings_api, $users_api );
		$authorization = new Authorization( $roles );

		$current_user_state = array();
		$auth_mock          = $this->createMock( AuthService::class );
		$auth_mock->method( 'get_current_user' )->willReturnCallback(
			function () use ( &$current_user_state ) {
				return $current_user_state;
			}
		);
		$auth_mock->method( 'format_user' )->willReturnCallback(
			function ( ?array $row ) {
				if ( ! $row ) {
					return array();
				}
				return array(
					'id'       => (string) $row['id'],
					'username' => (string) $row['username'],
					'bio'      => (string) ( $row['bio'] ?? '' ),
					'role'     => (string) $row['role'],
				);
			}
		);

		$users_service = $this->create_users_service(
			$db,
			$users_api,
			$auth_mock,
			null,
			null,
			$roles,
			$authorization
		);

		$req = new Request( 'PUT', '/api/v1/users/me', array(), array() );

		// 1. Restricted agent can update own profile
		$current_user_state = array(
			'id'       => '88020',
			'username' => 'restricted_u',
			'email'    => 'restricted@example.com',
			'role'     => 'restricted_agent',
		);
		$updated_restricted = $users_service->update_current_user( $req, array( 'bio' => 'Restricted Updated Bio' ) );
		$this->assertSame( 'Restricted Updated Bio', $updated_restricted['bio'] );
		$db_restricted = $users_api->get_user( '88020' );
		$this->assertSame( 'Restricted Updated Bio', $db_restricted['bio'] );

		// Read-only profile view is still safe and accessible
		$profile = $users_service->get_current_profile( $req );
		$this->assertSame( 'restricted_u', $profile['username'] );

		// 2. Full agent succeeds
		$current_user_state = array(
			'id'       => '88021',
			'username' => 'full_u',
			'email'    => 'full@example.com',
			'role'     => 'full_agent',
		);
		$updated_full       = $users_service->update_current_user( $req, array( 'bio' => 'Updated Full Bio' ) );
		$this->assertSame( 'Updated Full Bio', $updated_full['bio'] );
		$db_full = $users_api->get_user( '88021' );
		$this->assertSame( 'Updated Full Bio', $db_full['bio'] );

		// 3. Editor succeeds
		$current_user_state = array(
			'id'       => '88022',
			'username' => 'editor_u',
			'email'    => 'editor@example.com',
			'role'     => 'editor',
		);
		$updated_editor     = $users_service->update_current_user( $req, array( 'bio' => 'Editor New Bio' ) );
		$this->assertSame( 'Editor New Bio', $updated_editor['bio'] );
		$db_editor = $users_api->get_user( '88022' );
		$this->assertSame( 'Editor New Bio', $db_editor['bio'] );
	}

	public function test_real_db_concurrent_role_assignment_wins_lock_blocks_role_deletion_with_409(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$custom_roles_data = array(
			'custom_roles' => array(
				'reviewer' => array(
					'name'                 => 'Reviewer Role',
					'description'          => 'Reviewer',
					'initial_capabilities' => array( 'view_links' => true ),
					'capabilities'         => array( 'view_links' => true ),
				),
			),
		);
		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $custom_roles_data ) . "', 1, NOW())"
		);

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at) " .
			"VALUES (88031, 'admin_assigner', 'admin_assigner@example.com', 'Admin', 'Assigner', 'hash', 'admin', 1, NOW(), NOW())"
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

				$contention_observed = $this->observe_innodb_lock_wait(
					$pdo,
					(int) $waiter_conn_id,
					$blocker_conn_id,
					"{$prefix}settings"
				);

				$insert_stmt = $pdo->prepare(
					"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at) " .
					"VALUES (88032, 'assigned_user', 'assigned@example.com', 'Assigned', 'User', 'hash', 'reviewer', 1, NOW(), NOW())"
				);
				$insert_stmt->execute();
				$pdo->commit();

				return array(
					'assigned'            => true,
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
					$roles->delete_custom_role( 'reviewer' );
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
		$this->assertTrue( $parent_res['result']['assigned'] );
		$this->assertTrue( $parent_res['result']['contention_observed'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertSame( 'conflict', $child_res['result']['status'] );
		$this->assertSame( 409, $child_res['result']['code'] );
		$this->assertStringContainsString( 'assigned to 1 user(s)', $child_res['result']['message'] );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ), new UsersApi( $verify_db ) );
		$this->assertTrue( $roles->is_custom_role( 'reviewer' ), 'Role must remain intact in settings after deletion conflict.' );
		$user_count = (int) $verify_db->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE role = 'reviewer'" );
		$this->assertSame( 1, $user_count, 'Assigned user must remain in users table.' );
	}

	public function test_real_db_concurrent_role_deletion_wins_lock_blocks_assignment_with_422(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$custom_roles_data = array(
			'custom_roles' => array(
				'ephemeral_role' => array(
					'name'                 => 'Ephemeral Role',
					'description'          => 'Role to be deleted concurrently',
					'initial_capabilities' => array( 'view_links' => true ),
					'capabilities'         => array( 'view_links' => true ),
				),
			),
		);
		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $custom_roles_data ) . "', 1, NOW())"
		);

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at) " .
			"VALUES (88041, 'admin_creator', 'admin_creator@example.com', 'Admin', 'Creator', 'hash', 'admin', 1, NOW(), NOW())"
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
				$raw = $lock_stmt->fetchColumn();

				$blocker_conn_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "LOCK_ACQUIRED:{$blocker_conn_id}\n" );
				fflush( $sock );

				$waiter_line    = trim( (string) fgets( $sock ) );
				$waiter_conn_id = 0;
				if ( false !== strpos( $waiter_line, ':' ) ) {
					list( $sig, $waiter_conn_id ) = explode( ':', $waiter_line, 2 );
				}

				$contention_observed = $this->observe_innodb_lock_wait(
					$pdo,
					(int) $waiter_conn_id,
					$blocker_conn_id,
					"{$prefix}settings"
				);

				$stored = json_decode( (string) $raw, true );
				unset( $stored['custom_roles']['ephemeral_role'] );
				if ( empty( $stored['custom_roles'] ) ) {
					$del_stmt = $pdo->prepare(
						"DELETE FROM `{$prefix}settings` WHERE setting_key = :key"
					);
					$del_stmt->execute( array( 'key' => Constants::SETTING_ROLE_CAPABILITIES ) );
				} else {
					$update_stmt = $pdo->prepare(
						"UPDATE `{$prefix}settings` SET setting_value = :val, updated_at = NOW() WHERE setting_key = :key"
					);
					$update_stmt->execute(
						array(
							'val' => json_encode( $stored ),
							'key' => Constants::SETTING_ROLE_CAPABILITIES,
						)
					);
				}
				$pdo->commit();

				return array(
					'deleted'             => true,
					'contention_observed' => $contention_observed,
				);
			},
			function ( $sock ) use ( $conn_config ) {
				$conn          = new Connection( $conn_config );
				$db            = new PeakURL_DB( $conn );
				$settings_api  = new SettingsApi( $db );
				$users_api     = new UsersApi( $db );
				$roles         = new Roles( $settings_api, $users_api );
				$authorization = new Authorization( $roles );

				$auth_mock = $this->createMock( AuthService::class );
				$auth_mock->method( 'get_admin_user' )->willReturn(
					array(
						'id'       => '88041',
						'username' => 'admin_creator',
						'role'     => 'admin',
					)
				);
				$auth_mock->method( 'email_in_use' )->willReturn( false );
				$auth_mock->method( 'username_in_use' )->willReturn( false );

				$validator     = new UsersValidator();
				$analytics     = $this->createMock( AnalyticsService::class );
				$social_mock   = $this->createMock( SocialPreview::class );
				$webhooks_mock = $this->createMock( WebhooksService::class );
				$links_mock    = $this->createMock( LinksApi::class );

				$service = new UsersService(
					$db,
					$users_api,
					$auth_mock,
					$analytics,
					$validator,
					$roles,
					$authorization,
					$social_mock,
					$webhooks_mock,
					$links_mock
				);

				fgets( $sock );
				$waiter_conn_id = (int) $conn->get_connection()->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
				fwrite( $sock, "ATTEMPTING:{$waiter_conn_id}\n" );
				fflush( $sock );

				try {
					$service->create_user(
						new Request( 'POST', '/api/v1/users', array(), array() ),
						array(
							'username'  => 'candidate_user',
							'email'     => 'candidate@example.com',
							'password'  => 'ValidPass123!#',
							'firstName' => 'Candidate',
							'lastName'  => 'User',
							'role'      => 'ephemeral_role',
						)
					);
					return array( 'status' => 'unexpected_success' );
				} catch ( ApiException $e ) {
					return array(
						'status'  => 'rejected',
						'code'    => $e->getCode(),
						'message' => $e->getMessage(),
					);
				}
			}
		);

		$this->assertSame( 'ok', $parent_res['status'] );
		$this->assertTrue( $parent_res['result']['deleted'] );
		$this->assertTrue( $parent_res['result']['contention_observed'] );
		$this->assertSame( 'ok', $child_res['status'] );
		$this->assertSame( 'rejected', $child_res['result']['status'] );
		$this->assertSame( 422, $child_res['result']['code'] );
		$this->assertStringContainsString( 'no longer exists', $child_res['result']['message'] );

		$verify_conn = new Connection( $conn_config );
		$verify_db   = new PeakURL_DB( $verify_conn );
		$roles       = new Roles( new SettingsApi( $verify_db ), new UsersApi( $verify_db ) );
		$this->assertFalse( $roles->is_custom_role( 'ephemeral_role' ), 'Role must be gone from settings.' );
		$created_count = (int) $verify_db->get_var( "SELECT COUNT(*) FROM `{$prefix}users` WHERE username = 'candidate_user'" );
		$this->assertSame( 0, $created_count, 'No candidate user must be created.' );
	}

	/**
	 * Verify that create_user and update_user_by_username reject malformed role IDs with 422
	 * without silently coercing them via sanitize_key().
	 */
	public function test_user_endpoints_reject_malformed_role_id_without_coercion(): void {
		list( $setup_connection, $setup_db, $setup_pdo, $prefix ) = $this->setup_isolated_db();

		$custom_roles_data = array(
			'custom_roles' => array(
				'analyst' => array(
					'name'                 => 'Analyst',
					'description'          => 'Data Analyst',
					'initial_capabilities' => array( 'view_analytics' => true ),
					'capabilities'         => array( 'view_analytics' => true ),
				),
			),
		);
		$setup_pdo->exec(
			"INSERT INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) " .
			"VALUES ('" . Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $custom_roles_data ) . "', 1, NOW())"
		);

		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at) " .
			"VALUES (89001, 'acting_admin', 'acting_admin@example.com', 'Super', 'Admin', 'hash', 'admin', 1, NOW(), NOW())"
		);
		$setup_pdo->exec(
			"INSERT INTO `{$prefix}users` (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at) " .
			"VALUES (89002, 'target_user', 'target_user@example.com', 'Target', 'User', 'hash', 'editor', 1, NOW(), NOW())"
		);
		unset( $setup_connection, $setup_db, $setup_pdo );

		$base_config   = Configuration::get_current();
		$conn_config   = array_merge( $base_config, array( Constants::DB_PREFIX => $prefix ) );
		$conn          = new Connection( $conn_config );
		$db            = new PeakURL_DB( $conn );
		$settings_api  = new SettingsApi( $db );
		$users_api     = new UsersApi( $db );
		$roles         = new Roles( $settings_api, $users_api );
		$authorization = new Authorization( $roles );

		$auth_mock = $this->createMock( AuthService::class );
		$auth_mock->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => '89001',
				'username' => 'acting_admin',
				'role'     => 'admin',
			)
		);
		$auth_mock->method( 'username_in_use' )->willReturn( false );
		$auth_mock->method( 'email_in_use' )->willReturn( false );

		$analytics_mock = $this->createMock( \PeakURL\Features\Analytics\Service::class );
		$social_mock    = $this->createMock( \PeakURL\Services\SocialPreview::class );
		$webhooks_mock  = $this->createMock( \PeakURL\Features\Webhooks\Service::class );
		$links_api_mock = $this->createMock( \PeakURL\Api\LinksApi::class );

		$service = new UsersService(
			$db,
			$users_api,
			$auth_mock,
			$analytics_mock,
			new UsersValidator(),
			$roles,
			$authorization,
			$social_mock,
			$webhooks_mock,
			$links_api_mock
		);

		$request = new Request( 'POST', '/api/v1/users', array(), array() );

		// 1. Attempt user creation with 'ana!lyst' (must NOT coerce to 'analyst')
		try {
			$service->create_user(
				$request,
				array(
					'username'  => 'new_analyst',
					'email'     => 'new_analyst@example.com',
					'password'  => 'ValidPass123!',
					'firstName' => 'New',
					'lastName'  => 'Analyst',
					'role'      => 'ana!lyst',
				)
			);
			$this->fail( 'Expected 422 for malformed role ID on user creation' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
		}

		// 2. Attempt user creation with 'content-manager' (hyphen)
		try {
			$service->create_user(
				$request,
				array(
					'username'  => 'new_cm',
					'email'     => 'new_cm@example.com',
					'password'  => 'ValidPass123!',
					'firstName' => 'New',
					'lastName'  => 'CM',
					'role'      => 'content-manager',
				)
			);
			$this->fail( 'Expected 422 for hyphenated role ID on user creation' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
		}

		// 3. Attempt user creation with 'admin\n' (trailing newline must NOT match 'admin')
		try {
			$service->create_user(
				$request,
				array(
					'username'  => 'fake_admin',
					'email'     => 'fake_admin@example.com',
					'password'  => 'ValidPass123!',
					'firstName' => 'Fake',
					'lastName'  => 'Admin',
					'role'      => "admin\n",
				)
			);
			$this->fail( 'Expected 422 for newline-suffixed role ID on user creation' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
		}

		// 4. Attempt user creation with non-string roles (null, integer, array)
		foreach ( array( null, 123, array( 'admin' ) ) as $invalid_role_type ) {
			try {
				$service->create_user(
					$request,
					array(
						'username'  => 'type_test_user',
						'email'     => 'type_test@example.com',
						'password'  => 'ValidPass123!',
						'firstName' => 'Type',
						'lastName'  => 'Test',
						'role'      => $invalid_role_type,
					)
				);
				$this->fail( 'Expected 422 for non-string role on user creation' );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode() );
				$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
			}
		}

		// 5. Attempt user update with 'ana!lyst'
		try {
			$update_req = new Request( 'PUT', '/api/v1/users/target_user', array(), array() );
			$service->update_user_by_username(
				$update_req,
				'target_user',
				array( 'role' => 'ana!lyst' )
			);
			$this->fail( 'Expected 422 for malformed role ID on user update' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
		}

		// 6. Attempt user update with 'admin\n'
		try {
			$update_req = new Request( 'PUT', '/api/v1/users/target_user', array(), array() );
			$service->update_user_by_username(
				$update_req,
				'target_user',
				array( 'role' => "admin\n" )
			);
			$this->fail( 'Expected 422 for newline-suffixed role ID on user update' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
		}

		// 7. Attempt user update with non-string role (null, integer, array)
		foreach ( array( null, 123, array( 'admin' ) ) as $invalid_role_type ) {
			try {
				$update_req = new Request( 'PUT', '/api/v1/users/target_user', array(), array() );
				$service->update_user_by_username(
					$update_req,
					'target_user',
					array( 'role' => $invalid_role_type )
				);
				$this->fail( 'Expected 422 for non-string role on user update' );
			} catch ( ApiException $e ) {
				$this->assertSame( 422, $e->getCode() );
				$this->assertSame( 'Invalid role identifier.', $e->getMessage() );
			}
		}

		// Verify target user's role is still 'editor' (untouched)
		$user_row = $users_api->get_user( '89002' );
		$this->assertSame( 'editor', $user_row['role'] );
	}
}
