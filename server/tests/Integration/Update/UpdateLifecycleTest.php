<?php
/**
 * Integration tests for the full update lifecycle and schema convergence.
 *
 * Verifies that:
 * 1. An existing installation with schema version 9 and missing new managed table (link_health)
 *    remains recognized as READY (established).
 * 2. Applying an update via SystemController -> SystemService -> UpdateManager -> Installer
 *    executes release file replacement and schema convergence to version 10 without mocking
 *    the updater or using reflection.
 * 3. The new managed table is created, Schema::is_current() is true, and installed_version is 1.7.1.
 * 4. Existing user and settings records remain intact.
 * 5. When schema repair fails during an update, previous release files are restored from backup,
 *    maintenance mode is disabled, temporary files are removed, installed_version remains 1.7.0,
 *    a safe generic HTTP 500 is returned without SQL leakage, and InstallationState::get_state() remains READY.
 *
 * @package PeakURL\Tests\Integration\Update
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Update;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\System\Controller as SystemController;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as DatabaseSchema;
use PeakURL\Services\Geoip;
use PeakURL\Services\I18n;
use PeakURL\Services\Install\InstallationState;
use PeakURL\Services\Install\Writer;
use PeakURL\Services\Mailer;
use PeakURL\Services\Update\Client as UpdateClient;
use PeakURL\Services\Update\Context as UpdateContext;
use PeakURL\Services\Update\Filesystem as UpdateFilesystem;
use PeakURL\Services\Update\Manager as UpdateManager;
use PDO;
use ZipArchive;

class UpdateLifecycleTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private SettingsApi $settings_api;
	private array $config;
	private string $isolated_prefix = 'pkupdt_';
	private string $temp_app_dir;
	private ?Environment $original_environment = null;
	private array $original_env                = array();

	protected function setUp(): void {
		parent::setUp();

		$this->original_environment = Environment::get_instance();
		$this->config               = Configuration::get_current();
		$this->connection           = Connection::get_instance( $this->config );
		$this->pdo                  = $this->connection->get_connection();

		foreach ( array( 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_PREFIX', 'DB_USERNAME', 'DB_PASSWORD' ) as $key ) {
			$this->original_env[ $key ] = $_ENV[ $key ] ?? ( $_SERVER[ $key ] ?? ( false !== getenv( $key ) ? getenv( $key ) : null ) );
		}

		$this->temp_app_dir = sys_get_temp_dir() . '/peakurl_test_update_' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->temp_app_dir, 0755, true );
		mkdir( $this->temp_app_dir . '/content', 0755, true );

		$source_sample = $this->original_environment->get_source_root() . '/config-sample.php';
		if ( file_exists( $source_sample ) ) {
			copy( $source_sample, $this->temp_app_dir . '/config-sample.php' );
		}

		$source_schema = $this->original_environment->get_database_schema_path();
		if ( file_exists( $source_schema ) ) {
			mkdir( $this->temp_app_dir . '/database', 0755, true );
			copy( $source_schema, $this->temp_app_dir . '/database/schema.sql' );
		}

		Environment::set_instance( new Environment( $this->temp_app_dir, false ) );

		$this->drop_isolated_tables();
	}

	protected function tearDown(): void {
		$this->drop_isolated_tables();

		Environment::set_instance( $this->original_environment );

		foreach ( $this->original_env as $key => $val ) {
			if ( null === $val ) {
				putenv( $key );
				unset( $_ENV[ $key ], $_SERVER[ $key ] );
			} else {
				putenv( "{$key}={$val}" );
				$_ENV[ $key ]    = $val;
				$_SERVER[ $key ] = $val;
			}
		}

		if ( is_dir( $this->temp_app_dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->temp_app_dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST,
			);
			foreach ( $iterator as $item ) {
				if ( $item->isLink() ) {
					unlink( $item->getPathname() );
				} elseif ( $item->isDir() ) {
					rmdir( $item->getPathname() );
				} else {
					unlink( $item->getPathname() );
				}
			}
			if ( is_dir( $this->temp_app_dir ) ) {
				rmdir( $this->temp_app_dir );
			}
		}

		parent::tearDown();
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

	private function write_isolated_config(): array {
		$runtime_config = array(
			Constants::DB_DATABASE => (string) $this->config[ Constants::DB_DATABASE ],
			Constants::DB_USERNAME => (string) $this->config[ Constants::DB_USERNAME ],
			Constants::DB_PASSWORD => (string) $this->config[ Constants::DB_PASSWORD ],
			Constants::DB_HOST     => (string) $this->config[ Constants::DB_HOST ],
			Constants::DB_PORT     => (int) $this->config[ Constants::DB_PORT ],
			Constants::DB_CHARSET  => (string) ( $this->config[ Constants::DB_CHARSET ] ?? 'utf8mb4' ),
			Constants::DB_PREFIX   => $this->isolated_prefix,
			Constants::SITE_URL    => 'https://example.test',
			Constants::AUTH_KEY    => bin2hex( random_bytes( 32 ) ),
			Constants::AUTH_SALT   => bin2hex( random_bytes( 32 ) ),
			Constants::ENV         => 'production',
			Constants::DEBUG       => false,
			Constants::VERSION     => '1.7.0',
			Constants::CONTENT_DIR => $this->temp_app_dir . '/content',
		);

		putenv( 'DB_PREFIX=' . $this->isolated_prefix );
		$_ENV['DB_PREFIX']    = $this->isolated_prefix;
		$_SERVER['DB_PREFIX'] = $this->isolated_prefix;

		$prepared = Writer::prepare_config_values( $runtime_config );
		Writer::write_config_file( $this->temp_app_dir, $prepared );

		return $runtime_config;
	}

	/**
	 * Seed established installation state simulating PeakURL 1.7.0 with DB schema 9.
	 */
	private function seed_schema_9_installation(): array {
		$isolated_config = $this->write_isolated_config();

		$settings_table = $this->isolated_prefix . 'settings';
		$users_table    = $this->isolated_prefix . 'users';

		$this->pdo->exec(
			"CREATE TABLE `{$settings_table}` (
				`setting_key` VARCHAR(191) NOT NULL PRIMARY KEY,
				`setting_value` LONGTEXT NULL,
				`autoload` TINYINT(1) NOT NULL DEFAULT 1,
				`updated_at` DATETIME NOT NULL
			)"
		);
		$this->pdo->exec(
			"CREATE TABLE `{$users_table}` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				`username` VARCHAR(120) NOT NULL UNIQUE,
				`email` VARCHAR(190) NOT NULL UNIQUE,
				`first_name` VARCHAR(120) NOT NULL,
				`last_name` VARCHAR(120) NOT NULL,
				`password_hash` VARCHAR(255) NOT NULL,
				`role` VARCHAR(32) NOT NULL DEFAULT 'editor',
				`created_at` DATETIME NOT NULL,
				`updated_at` DATETIME NOT NULL
			)"
		);

		$this->pdo->exec(
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `autoload`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', 1, NOW()),
				('installed_version', '1.7.0', 1, NOW()),
				('db_schema_version', '9', 1, NOW())"
		);

		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash_secret', 'admin', NOW(), NOW())"
		);

		// Seed initial release files on disk (simulating 1.7.0 installation).
		file_put_contents( $this->temp_app_dir . '/index.php', '<?php // PeakURL 1.7.0 index' );
		mkdir( $this->temp_app_dir . '/core', 0755, true );
		file_put_contents( $this->temp_app_dir . '/core/release-1.7.0.php', '<?php // PeakURL 1.7.0 file' );

		// Seed persistent content in content/.
		mkdir( $this->temp_app_dir . '/content/uploads', 0755, true );
		file_put_contents( $this->temp_app_dir . '/content/uploads/logo.png', 'logo-binary-data' );
		mkdir( $this->temp_app_dir . '/content/languages', 0755, true );
		file_put_contents( $this->temp_app_dir . '/content/languages/custom.json', '{"greeting": "custom"}' );

		return $isolated_config;
	}

	/**
	 * Create a real update zip package fixture.
	 */
	private function create_package_fixture(
		string $zip_path,
		string $index_content,
		string $core_content,
		?string $schema_content = null
	): string {
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'index.php', $index_content );
		$zip->addFromString( 'core/release-1.7.1.php', $core_content );
		$zip->addFromString( 'content/languages/en.json', '{"welcome": "PeakURL 1.7.1"}' );

		$schema = $schema_content ?? (string) file_get_contents( $this->original_environment->get_database_schema_path() );
		$zip->addFromString( 'database/schema.sql', $schema );

		$zip->close();

		$checksum = hash_file( 'sha256', $zip_path );
		$this->assertIsString( $checksum );

		return $checksum;
	}

	public function test_established_site_with_schema_9_remains_ready_and_needs_repair_lightweight(): void {
		$isolated_config = $this->seed_schema_9_installation();

		$link_health = $this->isolated_prefix . 'link_health';

		// 1. link_health does not exist before update.
		$stmt = $this->pdo->query( "SHOW TABLES LIKE '{$link_health}'" );
		$this->assertEmpty( $stmt->fetchAll() );

		// 2. InstallationState::get_state() MUST be READY.
		$this->assertSame( InstallationState::READY, InstallationState::get_state( $this->temp_app_dir ) );

		$connection     = new Connection( $isolated_config );
		$schema_service = new DatabaseSchema( $connection );

		// 3. needs_repair() is lightweight true; is_current() is false.
		$this->assertTrue( $schema_service->needs_repair() );
		$this->assertFalse( $schema_service->is_current() );
	}

	public function test_full_update_lifecycle_converges_schema_and_preserves_established_state(): void {
		$isolated_config = $this->seed_schema_9_installation();

		$link_health = $this->isolated_prefix . 'link_health';

		// Pre-update state.
		$this->assertSame( InstallationState::READY, InstallationState::get_state( $this->temp_app_dir ) );
		$this->assertStringContainsString( '1.7.0', file_get_contents( $this->temp_app_dir . '/index.php' ) );
		$this->assertFileExists( $this->temp_app_dir . '/core/release-1.7.0.php' );
		$this->assertFileDoesNotExist( $this->temp_app_dir . '/core/release-1.7.1.php' );

		// Build real 1.7.1 zip package fixture.
		$zip_file = $this->temp_app_dir . '/package_1.7.1.zip';
		$checksum = $this->create_package_fixture(
			$zip_file,
			'<?php // PeakURL 1.7.1 index',
			'<?php // PeakURL 1.7.1 file'
		);

		$manifest = array(
			'version'        => '1.7.1',
			'packageUrl'     => 'https://releases.peakurl.org/package/peakurl-1.7.1.zip',
			'checksumSha256' => $checksum,
			'minimumPhp'     => '7.4.0',
		);

		$connection   = new Connection( $isolated_config );
		$db           = new PeakURL_DB( $connection );
		$settings_api = new SettingsApi( $db );

		// Create real HTTP Client test double serving our local zip fixture and manifest.
		$client = new class( new UpdateContext( $isolated_config, new UpdateFilesystem() ), $zip_file, $manifest ) extends UpdateClient {
			private string $zip_path;
			private array $manifest;

			public function __construct( UpdateContext $context, string $zip_path, array $manifest ) {
				parent::__construct( $context );
				$this->zip_path = $zip_path;
				$this->manifest = $manifest;
			}

			public function get( string $url, string $accept, array $params = array() ): string {
				if ( 'application/zip' === $accept ) {
					return (string) file_get_contents( $this->zip_path );
				}

				return (string) json_encode( $this->manifest, JSON_THROW_ON_ERROR );
			}

			public function get_https_url( string $url, string $label ): string {
				return $url;
			}
		};

		// Real UpdateManager with real Installer, Filesystem, ReleaseFiles, Workspace.
		$update_manager = new UpdateManager(
			$isolated_config,
			$settings_api,
			$db,
			$client
		);

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'       => 1,
				'username' => 'admin',
				'email'    => 'admin@example.test',
				'role'     => 'admin',
			)
		);

		$schema_service = new DatabaseSchema( $connection );

		// Real SystemService (no mock, no subclass).
		$system_service = new SystemService(
			$db,
			$connection,
			$auth_service,
			$settings_api,
			$this->createMock( Geoip::class ),
			$this->createMock( Mailer::class ),
			$schema_service,
			$this->createMock( I18n::class ),
			new Roles(),
			new Authorization( new Roles() ),
			$isolated_config,
			null,
			$update_manager
		);

		// Real SystemController (no reflection, real controller invocation).
		$controller = new SystemController( $system_service );
		$request    = new Request( 'POST', '/api/v1/system/update/apply', array(), array() );

		// Execute update through controller.
		$response = $controller->update_apply( $request );

		// Step 1: Controller response assertions.
		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['success'] );
		$this->assertTrue( $response['body']['data']['applied'] );
		$this->assertSame( '1.7.1', $response['body']['data']['currentVersion'] );

		// Step 2: Filesystem assertions.
		// Release files were updated and retired file was removed.
		$this->assertStringContainsString( '1.7.1', file_get_contents( $this->temp_app_dir . '/index.php' ) );
		$this->assertFileExists( $this->temp_app_dir . '/core/release-1.7.1.php' );
		$this->assertFileDoesNotExist( $this->temp_app_dir . '/core/release-1.7.0.php' );

		// User content preserved.
		$this->assertFileExists( $this->temp_app_dir . '/content/uploads/logo.png' );
		$this->assertSame( 'logo-binary-data', file_get_contents( $this->temp_app_dir . '/content/uploads/logo.png' ) );
		$this->assertFileExists( $this->temp_app_dir . '/content/languages/custom.json' );

		// Packaged content synced.
		$this->assertFileExists( $this->temp_app_dir . '/content/languages/en.json' );

		// Maintenance mode disabled.
		$this->assertFileDoesNotExist( $this->temp_app_dir . '/.maintenance' );

		// Step 3: Database schema convergence assertions.
		$this->assertTrue( $schema_service->is_current() );
		$this->assertFalse( $schema_service->needs_repair() );

		$stmt_after = $this->pdo->query( "SHOW TABLES LIKE '{$link_health}'" );
		$this->assertNotEmpty( $stmt_after->fetchAll() );

		$recorded_version = (int) $this->pdo->query(
			"SELECT setting_value FROM `{$this->isolated_prefix}settings` WHERE setting_key = 'db_schema_version'"
		)->fetchColumn();
		$this->assertSame( 11, $recorded_version );

		// Step 4: Installed version and settings assertions.
		$installed_ver = $settings_api->get_option( 'installed_version' );
		$this->assertSame( '1.7.1', $installed_ver );
		$this->assertNull( $settings_api->get_option( 'update_last_error' ) );

		// Step 5: User data preserved.
		$users_table = $this->isolated_prefix . 'users';
		$user_row    = $this->pdo->query( "SELECT * FROM `{$users_table}` WHERE id = 1" )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 'admin', $user_row['username'] );
		$this->assertSame( 'admin@example.test', $user_row['email'] );

		// Step 6: Installation identity remains READY.
		$this->assertSame( InstallationState::READY, InstallationState::get_state( $this->temp_app_dir ) );
	}

	public function test_schema_failure_during_update_rolls_back_files_and_preserves_established_state(): void {
		$isolated_config = $this->seed_schema_9_installation();

		$link_health = $this->isolated_prefix . 'link_health';

		// Build real 1.7.1 zip package fixture with broken database schema to simulate migration failure.
		$zip_file = $this->temp_app_dir . '/package_1.7.1_fail.zip';
		$checksum = $this->create_package_fixture(
			$zip_file,
			'<?php // PeakURL 1.7.1 new index',
			'<?php // PeakURL 1.7.1 new file',
			'INVALID DDL SYNTAX CAUSING SCHEMA FAILURE;'
		);

		$manifest = array(
			'version'        => '1.7.1',
			'packageUrl'     => 'https://releases.peakurl.org/package/peakurl-1.7.1.zip',
			'checksumSha256' => $checksum,
			'minimumPhp'     => '7.4.0',
		);

		$connection   = new Connection( $isolated_config );
		$db           = new PeakURL_DB( $connection );
		$settings_api = new SettingsApi( $db );

		// Create client double.
		$client = new class( new UpdateContext( $isolated_config, new UpdateFilesystem() ), $zip_file, $manifest ) extends UpdateClient {
			private string $zip_path;
			private array $manifest;

			public function __construct( UpdateContext $context, string $zip_path, array $manifest ) {
				parent::__construct( $context );
				$this->zip_path = $zip_path;
				$this->manifest = $manifest;
			}

			public function get( string $url, string $accept, array $params = array() ): string {
				if ( 'application/zip' === $accept ) {
					return (string) file_get_contents( $this->zip_path );
				}

				return (string) json_encode( $this->manifest, JSON_THROW_ON_ERROR );
			}

			public function get_https_url( string $url, string $label ): string {
				return $url;
			}
		};

		$update_manager = new UpdateManager(
			$isolated_config,
			$settings_api,
			$db,
			$client
		);

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'       => 1,
				'username' => 'admin',
				'email'    => 'admin@example.test',
				'role'     => 'admin',
			)
		);

		$schema_service = new DatabaseSchema( $connection );

		$system_service = new SystemService(
			$db,
			$connection,
			$auth_service,
			$settings_api,
			$this->createMock( Geoip::class ),
			$this->createMock( Mailer::class ),
			$schema_service,
			$this->createMock( I18n::class ),
			new Roles(),
			new Authorization( new Roles() ),
			$isolated_config,
			null,
			$update_manager
		);

		$controller = new SystemController( $system_service );
		$request    = new Request( 'POST', '/api/v1/system/update/apply', array(), array() );

		// Execute update and expect ApiException.
		$exception_caught = null;
		try {
			$controller->update_apply( $request );
		} catch ( ApiException $e ) {
			$exception_caught = $e;
		}

		// 1. Must throw ApiException with HTTP 500.
		$this->assertInstanceOf( ApiException::class, $exception_caught );
		$this->assertSame( 500, $exception_caught->get_status() );

		// 2. Client-facing message must be generic and not leak raw SQL/DDL.
		$this->assertSame(
			'PeakURL could not complete the release update. Check the server logs and retry.',
			$exception_caught->getMessage()
		);
		$this->assertStringNotContainsString( 'INVALID DDL SYNTAX', $exception_caught->getMessage() );

		// 3. Detailed error is recorded in update_last_error for admin inspection.
		$last_error = $settings_api->get_option( 'update_last_error' );
		$this->assertNotNull( $last_error );
		$this->assertStringContainsString( 'INVALID DDL SYNTAX', $last_error );

		// 4. Release files were rolled back from backup to 1.7.0.
		$this->assertStringContainsString( '1.7.0', file_get_contents( $this->temp_app_dir . '/index.php' ) );
		$this->assertFileExists( $this->temp_app_dir . '/core/release-1.7.0.php' );
		$this->assertFileDoesNotExist( $this->temp_app_dir . '/core/release-1.7.1.php' );

		// 5. Backup directory was cleaned up during rollback (no orphaned backup material).
		$backup_dir = $this->temp_app_dir . '/content/updates/backups';
		if ( is_dir( $backup_dir ) ) {
			$backup_entries = array_diff( scandir( $backup_dir ) ?: array(), array( '.', '..' ) );
			$this->assertEmpty( $backup_entries );
		}

		// 6. Maintenance mode was disabled.
		$this->assertFileDoesNotExist( $this->temp_app_dir . '/.maintenance' );

		// 7. Installed version remains 1.7.0 in database settings.
		$this->assertSame( '1.7.0', $settings_api->get_option( 'installed_version' ) );

		// 8. Schema version remains 9 and link_health does not exist.
		$this->assertSame( '9', (string) $settings_api->get_option( 'db_schema_version' ) );
		$stmt_table = $this->pdo->query( "SHOW TABLES LIKE '{$link_health}'" );
		$this->assertEmpty( $stmt_table->fetchAll() );

		// 9. Site installation state strictly remains READY (not NOT_INSTALLED).
		$this->assertSame( InstallationState::READY, InstallationState::get_state( $this->temp_app_dir ) );
	}

	public function test_schema_failure_with_absent_installed_version_restores_absent_state(): void {
		$isolated_config = $this->seed_schema_9_installation();

		$settings_table = $this->isolated_prefix . 'settings';
		$link_health    = $this->isolated_prefix . 'link_health';

		$connection   = new Connection( $isolated_config );
		$db           = new PeakURL_DB( $connection );
		$settings_api = new SettingsApi( $db );

		// Simulate established installation where installed_version row was absent prior to update.
		$this->pdo->exec( "DELETE FROM `{$settings_table}` WHERE `setting_key` = 'installed_version'" );
		$this->assertNull( $settings_api->get_option( 'installed_version' ) );

		// Build package fixture with broken DDL to fail schema migration.
		$zip_file = $this->temp_app_dir . '/package_1.7.1_absent_fail.zip';
		$checksum = $this->create_package_fixture(
			$zip_file,
			'<?php // PeakURL 1.7.1 new index',
			'<?php // PeakURL 1.7.1 new file',
			'FAILING DDL FOR ABSENT VERSION TEST;'
		);

		$manifest = array(
			'version'        => '1.7.1',
			'packageUrl'     => 'https://releases.peakurl.org/package/peakurl-1.7.1.zip',
			'checksumSha256' => $checksum,
			'minimumPhp'     => '7.4.0',
		);

		$client = new class( new UpdateContext( $isolated_config, new UpdateFilesystem() ), $zip_file, $manifest ) extends UpdateClient {
			private string $zip_path;
			private array $manifest;

			public function __construct( UpdateContext $context, string $zip_path, array $manifest ) {
				parent::__construct( $context );
				$this->zip_path = $zip_path;
				$this->manifest = $manifest;
			}

			public function get( string $url, string $accept, array $params = array() ): string {
				if ( 'application/zip' === $accept ) {
					return (string) file_get_contents( $this->zip_path );
				}

				return (string) json_encode( $this->manifest, JSON_THROW_ON_ERROR );
			}

			public function get_https_url( string $url, string $label ): string {
				return $url;
			}
		};

		$update_manager = new UpdateManager(
			$isolated_config,
			$settings_api,
			$db,
			$client
		);

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'       => 1,
				'username' => 'admin',
				'email'    => 'admin@example.test',
				'role'     => 'admin',
			)
		);

		$schema_service = new DatabaseSchema( $connection );

		$system_service = new SystemService(
			$db,
			$connection,
			$auth_service,
			$settings_api,
			$this->createMock( Geoip::class ),
			$this->createMock( Mailer::class ),
			$schema_service,
			$this->createMock( I18n::class ),
			new Roles(),
			new Authorization( new Roles() ),
			$isolated_config,
			null,
			$update_manager
		);

		$controller = new SystemController( $system_service );
		$request    = new Request( 'POST', '/api/v1/system/update/apply', array(), array() );

		$exception_caught = null;
		try {
			$controller->update_apply( $request );
		} catch ( ApiException $e ) {
			$exception_caught = $e;
		}

		$this->assertInstanceOf( ApiException::class, $exception_caught );
		$this->assertSame( 500, $exception_caught->get_status() );

		// installed_version must be restored to its exact absent state (deleted, null).
		$this->assertNull( $settings_api->get_option( 'installed_version' ) );
		$stmt_row = $this->pdo->query( "SELECT setting_value FROM `{$settings_table}` WHERE setting_key = 'installed_version'" );
		$this->assertFalse( $stmt_row->fetch(), 'No installed_version row should exist in settings table.' );

		// Files rolled back and state remains READY.
		$this->assertStringContainsString( '1.7.0', file_get_contents( $this->temp_app_dir . '/index.php' ) );
		$this->assertFileExists( $this->temp_app_dir . '/core/release-1.7.0.php' );
		$this->assertFileDoesNotExist( $this->temp_app_dir . '/core/release-1.7.1.php' );
		$this->assertSame( InstallationState::READY, InstallationState::get_state( $this->temp_app_dir ) );
	}
}
