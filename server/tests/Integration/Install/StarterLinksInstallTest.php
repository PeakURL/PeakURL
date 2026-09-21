<?php
/**
 * Integration tests for starter links initialization on installation.
 *
 * @package PeakURL\Tests\Integration\Install
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Install;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as DatabaseSchema;
use PeakURL\Services\Install\Initializer;
use PeakURL\Utils\Date;
use PDO;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class StarterLinksInstallTest extends TestCase {

	private PDO $pdo;
	private array $base_config;
	private string $isolated_prefix;

	protected function setUp(): void {
		parent::setUp();

		$this->base_config     = Configuration::get_current();
		$this->isolated_prefix = 'stlink_' . bin2hex( random_bytes( 4 ) ) . '_';
		$connection            = Connection::get_instance( $this->base_config );
		$this->pdo             = $connection->get_connection();

		$this->drop_isolated_tables();
	}

	protected function tearDown(): void {
		$this->drop_isolated_tables();
		parent::tearDown();
	}

	private function get_isolated_config(): array {
		return array_merge(
			$this->base_config,
			array(
				Constants::DB_PREFIX        => $this->isolated_prefix,
				Constants::OWNER_USERNAME   => 'starteradmin',
				Constants::OWNER_EMAIL      => 'starteradmin@example.com',
				Constants::OWNER_PASSWORD   => 'StarterPass12345!',
				Constants::OWNER_FIRST_NAME => 'Starter',
				Constants::OWNER_LAST_NAME  => 'Admin',
				Constants::WORKSPACE_NAME   => 'Starter Site',
				Constants::WORKSPACE_SLUG   => 'starter-site',
			)
		);
	}

	private function drop_isolated_tables(): void {
		$stmt   = $this->pdo->query( "SHOW TABLES LIKE '{$this->isolated_prefix}%'" );
		$tables = $stmt->fetchAll( PDO::FETCH_COLUMN );

		if ( ! empty( $tables ) ) {
			$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
			foreach ( $tables as $table ) {
				$this->pdo->exec( "DROP TABLE IF EXISTS `{$table}`" );
			}
			$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 1' );
		}
	}

	public function test_fresh_install_initializes_exactly_three_starter_links(): void {
		$config       = $this->get_isolated_config();
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );

		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		$db         = new PeakURL_DB( $connection, $this->isolated_prefix );
		$links_stmt = $this->pdo->query(
			"SELECT * FROM {$this->isolated_prefix}urls ORDER BY created_at ASC, id ASC"
		);
		$links      = $links_stmt->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 3, $links, 'Fresh install must initialize exactly three starter links.' );

		$owner_stmt = $this->pdo->query(
			"SELECT id FROM {$this->isolated_prefix}users WHERE role = 'admin' LIMIT 1"
		);
		$owner_id   = (int) $owner_stmt->fetchColumn();
		$this->assertGreaterThan( 0, $owner_id );

		$expected = array(
			array(
				'title'    => 'Welcome to PeakURL',
				'url'      => 'https://peakurl.org/?utm_source=peakurl&utm_medium=installation&utm_campaign=welcome',
				'campaign' => 'welcome',
			),
			array(
				'title'    => 'PeakURL Documentation',
				'url'      => 'https://peakurl.org/docs/?utm_source=peakurl&utm_medium=installation&utm_campaign=documentation',
				'campaign' => 'documentation',
			),
			array(
				'title'    => 'PeakURL Blog',
				'url'      => 'https://peakurl.org/blog/?utm_source=peakurl&utm_medium=installation&utm_campaign=blog',
				'campaign' => 'blog',
			),
		);

		foreach ( $expected as $item ) {
			$match = null;
			foreach ( $links as $link ) {
				if ( $link['title'] === $item['title'] ) {
					$match = $link;
					break;
				}
			}

			$this->assertNotNull( $match, "Starter link with title '{$item['title']}' must exist." );
			$this->assertSame( $item['title'], $match['title'] );
			$this->assertSame( $item['url'], $match['destination_url'] );
			$this->assertNotEmpty( $match['short_code'] );
			$this->assertNotEmpty( $match['alias'] );
			$this->assertSame( $match['short_code'], $match['alias'] );
			$this->assertNotContains( $match['alias'], array( 'welcome', 'docs', 'blog', 'founder' ) );
			$this->assertSame( (string) $owner_id, (string) $match['user_id'] );
			$this->assertSame( 'active', $match['status'] );
			$this->assertNotEmpty( $match['id'] );

			// Structured UTM metadata assertions
			$this->assertSame( 'peakurl', $match['utm_source'] );
			$this->assertSame( 'installation', $match['utm_medium'] );
			$this->assertSame( $item['campaign'], $match['utm_campaign'] );
			$this->assertNull( $match['utm_term'] );
			$this->assertNull( $match['utm_content'] );
		}

		$aliases = array_column( $links, 'alias' );
		$this->assertCount( 3, $aliases );
		$this->assertSame( array_unique( $aliases ), $aliases, 'Starter link aliases must be unique.' );
		foreach ( $aliases as $alias ) {
			$this->assertNotEmpty( $alias );
			$this->assertNotContains( $alias, array( 'welcome', 'docs', 'blog', 'founder' ) );
		}

		$settings_api   = new SettingsApi( $db );
		$initialized_at = $settings_api->get_option( 'starter_links_initialized_at' );
		$this->assertNotNull( $initialized_at, 'starter_links_initialized_at option must be recorded in settings.' );
	}

	public function test_repeated_bootstrap_is_idempotent(): void {
		$config       = $this->get_isolated_config();
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );

		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		$db    = new PeakURL_DB( $connection, $this->isolated_prefix );
		$owner = $db->get_row_by( 'users', array( 'role' => 'admin' ) );
		$this->assertIsArray( $owner );

		// 1. Second bootstrap call in the same process.
		Initializer::bootstrap_site( $connection, $config );

		// 2. Directly invoke initialize_starter_links again to verify method-level idempotence.
		Initializer::initialize_starter_links( $db, $owner );

		$count_stmt = $this->pdo->query( "SELECT COUNT(*) FROM {$this->isolated_prefix}urls" );
		$total      = (int) $count_stmt->fetchColumn();

		$this->assertSame( 3, $total, 'Repeated bootstrap or direct initialize calls must not create duplicate starter links.' );

		$aliases_stmt = $this->pdo->query( "SELECT alias FROM {$this->isolated_prefix}urls" );
		$aliases      = $aliases_stmt->fetchAll( PDO::FETCH_COLUMN );
		$this->assertCount( 3, $aliases );
		$this->assertSame( array_unique( $aliases ), $aliases );
	}

	public function test_upgrade_and_schema_repair_do_not_seed_starter_links(): void {
		$config       = $this->get_isolated_config();
		$runtime_root = Environment::get_instance()->get_runtime_root();

		// 1. Create empty schema tables.
		Initializer::initialize_schema( $config, $runtime_root );

		// 2. Pre-create an admin user as would exist in an already-installed site being upgraded.
		$now = Date::now();
		$this->pdo->exec(
			"INSERT INTO {$this->isolated_prefix}users
			(username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES ('existingadmin', 'existing@example.com', 'Existing', 'Admin', 'hash123', 'admin', 1, '{$now}', '{$now}')"
		);

		// 3. Run schema repair / upgrade service.
		$connection     = new Connection( $config );
		$schema_path    = Environment::get_instance()->get_database_schema_path();
		$schema_service = new DatabaseSchema( $connection, $schema_path );
		$schema_service->repair_schema();

		// 4. Run bootstrap_site on the existing site.
		Initializer::bootstrap_site( $connection, $config );

		$count_stmt = $this->pdo->query( "SELECT COUNT(*) FROM {$this->isolated_prefix}urls" );
		$total      = (int) $count_stmt->fetchColumn();

		$this->assertSame( 0, $total, 'Upgrade and schema repair on existing installations must not create starter links.' );
	}

	public function test_deleted_starter_links_are_not_recreated(): void {
		$config       = $this->get_isolated_config();
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );
		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		$db    = new PeakURL_DB( $connection, $this->isolated_prefix );
		$owner = $db->get_row_by( 'users', array( 'role' => 'admin' ) );
		$this->assertIsArray( $owner );

		// User deletes all starter links.
		$this->pdo->exec( "DELETE FROM {$this->isolated_prefix}urls" );
		$count_before = (int) $this->pdo->query( "SELECT COUNT(*) FROM {$this->isolated_prefix}urls" )->fetchColumn();
		$this->assertSame( 0, $count_before );

		// Subsequent initialize invocation must NOT recreate them.
		Initializer::initialize_starter_links( $db, $owner );

		$count_after = (int) $this->pdo->query( "SELECT COUNT(*) FROM {$this->isolated_prefix}urls" )->fetchColumn();
		$this->assertSame( 0, $count_after, 'Deleted starter links must never be recreated.' );
	}

	public function test_starter_links_are_ordinary_links_editable_and_deletable(): void {
		$config       = $this->get_isolated_config();
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );
		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		$db         = new PeakURL_DB( $connection, $this->isolated_prefix );
		$links_api  = new LinksApi( $db );
		$repository = new LinksRepository( $db, $links_api );

		// Fetch the 'Welcome to PeakURL' starter link via domain repository.
		$link = $db->get_row(
			"SELECT * FROM {$this->isolated_prefix}urls WHERE title = 'Welcome to PeakURL' LIMIT 1"
		);
		$this->assertIsArray( $link );
		$link_id = (string) $link['id'];

		// 1. Edit the link: change title and destination URL through domain repository.
		$updated_title = 'Welcome to PeakURL (Customized)';
		$updated_dest  = 'https://peakurl.org/welcome-custom';
		$repository->update_url_fields(
			$link_id,
			array( 'title = :title', 'destination_url = :destination_url', 'updated_at = :updated_at' ),
			array(
				'title'           => $updated_title,
				'destination_url' => $updated_dest,
				'updated_at'      => Date::now(),
			)
		);

		$refreshed = $repository->find_url_row( $link_id );
		$this->assertNotNull( $refreshed );
		$this->assertSame( $updated_title, $refreshed['title'] );
		$this->assertSame( $updated_dest, $refreshed['destination_url'] );

		// 2. Trash the link through domain repository method.
		$trashed_result = $repository->trash_url( $link_id, Date::now() );
		$this->assertTrue( $trashed_result );

		$trashed = $repository->find_url_row( $link_id );
		$this->assertNotNull( $trashed );
		$this->assertSame( 'trashed', $trashed['status'] );

		// 3. Permanently delete the link through domain repository method.
		$deleted_result = $repository->delete_url_permanent( $link_id );
		$this->assertTrue( $deleted_result );

		$deleted = $repository->find_url_row( $link_id );
		$this->assertNull( $deleted, 'Starter link must be permanently deletable through domain repository.' );
	}
}
