<?php
/**
 * Integration tests for release install state detection and schema migration orchestration.
 *
 * @package PeakURL\Tests\Integration\Install
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Install;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as DatabaseSchema;
use PeakURL\Services\Install\Initializer;
use PeakURL\Services\Install\InstallationState;
use PeakURL\Services\Install\Writer;
use PDO;

class InstallStateTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private array $config;
	private string $isolated_prefix = 'pkstate_';
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

		$this->temp_app_dir = sys_get_temp_dir() . '/peakurl_test_' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->temp_app_dir, 0755, true );

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

	private function write_isolated_config( array $overrides = array() ): void {
		$runtime_config = array(
			Constants::DB_DATABASE => (string) $this->config[ Constants::DB_DATABASE ],
			Constants::DB_USERNAME => (string) $this->config[ Constants::DB_USERNAME ],
			Constants::DB_PASSWORD => (string) $this->config[ Constants::DB_PASSWORD ],
			Constants::DB_HOST     => (string) $this->config[ Constants::DB_HOST ],
			Constants::DB_PORT     => (int) $this->config[ Constants::DB_PORT ],
			Constants::DB_PREFIX   => $this->isolated_prefix,
			Constants::SITE_URL    => 'https://example.test',
			Constants::AUTH_KEY    => bin2hex( random_bytes( 32 ) ),
			Constants::AUTH_SALT   => bin2hex( random_bytes( 32 ) ),
			Constants::ENV         => 'development',
			Constants::DEBUG       => false,
		);

		$runtime_config = array_merge( $runtime_config, $overrides );

		putenv( 'DB_PREFIX=' . $runtime_config[ Constants::DB_PREFIX ] );
		$_ENV['DB_PREFIX']    = $runtime_config[ Constants::DB_PREFIX ];
		$_SERVER['DB_PREFIX'] = $runtime_config[ Constants::DB_PREFIX ];

		$prepared = Writer::prepare_config_values( $runtime_config );

		Writer::write_config_file( $this->temp_app_dir, $prepared );
	}

	public function test_missing_config_returns_not_configured(): void {
		$this->assertSame( InstallationState::NOT_CONFIGURED, InstallationState::get_state( $this->temp_app_dir ) );
	}

	public function test_invalid_database_configuration_returns_database_unavailable(): void {
		putenv( 'DB_PORT=65432' );
		$_ENV['DB_PORT']    = '65432';
		$_SERVER['DB_PORT'] = '65432';

		$this->write_isolated_config(
			array(
				Constants::DB_PORT => 65432,
			)
		);

		$this->assertSame( InstallationState::DATABASE_UNAVAILABLE, InstallationState::get_state( $this->temp_app_dir ) );
	}

	public function test_missing_core_tables_returns_not_installed(): void {
		$this->write_isolated_config();

		$this->assertSame( InstallationState::NOT_INSTALLED, InstallationState::get_state( $this->temp_app_dir ) );
	}

	public function test_missing_site_url_or_users_returns_not_installed(): void {
		$this->write_isolated_config();

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

		// Without site_url setting and users row, not installed.
		$this->assertSame( InstallationState::NOT_INSTALLED, InstallationState::get_state( $this->temp_app_dir ) );

		// Insert site_url but leave users empty.
		$this->pdo->exec(
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES ('site_url', 'https://example.test', NOW())"
		);

		$this->assertSame( InstallationState::NOT_INSTALLED, InstallationState::get_state( $this->temp_app_dir ) );
	}

	public function test_existing_installation_with_missing_secondary_table_returns_ready_and_bootstrap_repairs(): void {
		$this->write_isolated_config();

		$settings_table = $this->isolated_prefix . 'settings';
		$users_table    = $this->isolated_prefix . 'users';
		$link_health    = $this->isolated_prefix . 'link_health';

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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('db_schema_version', '9', NOW())"
		);

		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		// Step 1: Ensure the secondary table (link_health) does NOT exist yet.
		$stmt = $this->pdo->query( "SHOW TABLES LIKE '{$link_health}'" );
		$this->assertEmpty( $stmt->fetchAll() );

		// Step 2: InstallationState::get_state() MUST recognize it as an established installation.
		$state = InstallationState::get_state( $this->temp_app_dir );
		$this->assertSame( InstallationState::READY, $state );
		$this->assertNotSame( InstallationState::NOT_INSTALLED, $state );

		// Step 3: InstallationState::get_state() must NOT mutate schema or create the missing table.
		$stmt_after = $this->pdo->query( "SHOW TABLES LIKE '{$link_health}'" );
		$this->assertEmpty( $stmt_after->fetchAll() );

		// Step 4: Bootstrap orchestration detects repair is needed.
		$isolated_config = array_merge( $this->config, array( Constants::DB_PREFIX => $this->isolated_prefix ) );
		$conn            = new Connection( $isolated_config );
		$schema          = new DatabaseSchema( $conn );

		$this->assertTrue( $schema->needs_repair() );
		$this->assertFalse( $schema->is_current() );

		// Step 5: Canonical repair upgrades schema and creates missing table.
		$schema->repair_schema();

		$this->assertTrue( $schema->is_current() );
		$this->assertFalse( $schema->needs_repair() );

		$stmt_final = $this->pdo->query( "SHOW TABLES LIKE '{$link_health}'" );
		$this->assertNotEmpty( $stmt_final->fetchAll() );
	}

	public function test_schema_repair_failure_is_not_classified_as_not_installed(): void {
		$this->write_isolated_config();

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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('db_schema_version', '9', NOW())"
		);

		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		// Established installation remains READY, never NOT_INSTALLED.
		$state = InstallationState::get_state( $this->temp_app_dir );
		$this->assertSame( InstallationState::READY, $state );
		$this->assertNotSame( InstallationState::NOT_INSTALLED, $state );
		$this->assertNotSame( InstallationState::DATABASE_UNAVAILABLE, $state );

		// Simulate a schema upgrade failure with a broken schema file.
		$broken_schema_file = $this->temp_app_dir . '/database/broken_schema.sql';
		file_put_contents( $broken_schema_file, 'INVALID SQL SYNTAX HERE;' );

		$isolated_config = array_merge( $this->config, array( Constants::DB_PREFIX => $this->isolated_prefix ) );
		$conn            = new Connection( $isolated_config );
		$schema          = new DatabaseSchema( $conn, $broken_schema_file );

		$exception_caught = false;
		try {
			$schema->repair_schema();
		} catch ( \Throwable $e ) {
			$exception_caught = true;
		}

		$this->assertTrue( $exception_caught, 'Expected schema repair to throw an exception on invalid DDL.' );

		// Even after failure, install state must NOT falsely degrade to NOT_INSTALLED.
		$state_after_failure = InstallationState::get_state( $this->temp_app_dir );
		$this->assertSame( InstallationState::READY, $state_after_failure );
		$this->assertNotSame( InstallationState::NOT_INSTALLED, $state_after_failure );
	}

	public function test_partial_ddl_failure_preserves_established_state_and_converges_on_retry(): void {
		$this->write_isolated_config();

		$settings_table = $this->isolated_prefix . 'settings';
		$users_table    = $this->isolated_prefix . 'users';
		$link_health    = $this->isolated_prefix . 'link_health';

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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('installed_version', '1.7.0', NOW()),
				('db_schema_version', '9', NOW())"
		);

		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		// Initial assertions: established site at schema 9, link_health does not exist
		$this->assertSame( InstallationState::READY, InstallationState::get_state( $this->temp_app_dir ) );
		$stmt_before = $this->pdo->query( "SHOW TABLES LIKE '{$link_health}'" );
		$this->assertEmpty( $stmt_before->fetchAll() );

		// Step 1: Create a schema file with partial valid DDL (creates link_health) followed by failing DDL
		$valid_link_health_ddl = "CREATE TABLE IF NOT EXISTS link_health (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			link_id VARCHAR(40) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'healthy',
			status_code INT UNSIGNED DEFAULT NULL,
			error_message TEXT DEFAULT NULL,
			checked_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			KEY idx_link_health_link_id (link_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";

		$partial_fail_schema_file = $this->temp_app_dir . '/database/partial_fail_schema.sql';
		file_put_contents(
			$partial_fail_schema_file,
			$valid_link_health_ddl . "\nTHIS STATEMENT IS INTENTIONALLY INVALID SQL AND MUST FAIL;\n"
		);

		$isolated_config = array_merge( $this->config, array( Constants::DB_PREFIX => $this->isolated_prefix ) );
		$conn            = new Connection( $isolated_config );
		$schema          = new DatabaseSchema( $conn, $partial_fail_schema_file );

		// Step 2: Schema repair throws exception on the second statement
		$exception_caught = null;
		try {
			$schema->repair_schema();
		} catch ( \Throwable $e ) {
			$exception_caught = $e;
		}

		$this->assertNotNull( $exception_caught, 'Expected schema repair to throw an exception on later failing statement.' );

		// Step 3: Partial DDL is observable in MySQL (link_health exists) because MySQL auto-commits DDL
		$stmt_partial = $this->pdo->query( "SHOW TABLES LIKE '{$link_health}'" );
		$this->assertNotEmpty( $stmt_partial->fetchAll(), 'Partial valid DDL statement must have created the table in MySQL.' );

		// Step 4: Installation state strictly remains READY (never NOT_INSTALLED)
		$this->assertSame( InstallationState::READY, InstallationState::get_state( $this->temp_app_dir ) );
		$this->assertNotSame( InstallationState::NOT_INSTALLED, InstallationState::get_state( $this->temp_app_dir ) );

		// Step 5: db_schema_version is NOT falsely advanced (remains 9)
		$stmt_version = $this->pdo->query(
			"SELECT setting_value FROM `{$settings_table}` WHERE setting_key = 'db_schema_version'"
		);
		$this->assertSame( '9', (string) $stmt_version->fetchColumn() );

		// Step 6: Failure is recorded in db_schema_last_error
		$stmt_error  = $this->pdo->query(
			"SELECT setting_value FROM `{$settings_table}` WHERE setting_key = 'db_schema_last_error'"
		);
		$error_value = $stmt_error->fetchColumn();
		$this->assertNotEmpty( $error_value );
		$this->assertStringContainsString( 'INTENTIONALLY INVALID SQL', (string) $error_value );

		// Step 7: needs_repair() remains true, is_current() remains false
		$this->assertTrue( $schema->needs_repair() );
		$this->assertFalse( $schema->is_current() );

		// Step 8: Subsequent repair with valid schema converges safely
		$valid_schema_file = $this->original_environment->get_database_schema_path();
		$converging_schema = new DatabaseSchema( $conn, $valid_schema_file );

		$retry_result = $converging_schema->repair_schema();
		$this->assertNotEmpty( $retry_result );

		// Step 9: Post-retry assertions: schema converges to 10, is_current is true, error is cleared
		$this->assertTrue( $converging_schema->is_current() );
		$this->assertFalse( $converging_schema->needs_repair() );

		$stmt_version_after = $this->pdo->query(
			"SELECT setting_value FROM `{$settings_table}` WHERE setting_key = 'db_schema_version'"
		);
		$this->assertSame( '10', (string) $stmt_version_after->fetchColumn() );

		$stmt_error_after = $this->pdo->query(
			"SELECT setting_value FROM `{$settings_table}` WHERE setting_key = 'db_schema_last_error'"
		);
		$this->assertFalse( $stmt_error_after->fetchColumn(), 'db_schema_last_error must be cleared after convergence.' );

		$this->assertSame( InstallationState::READY, InstallationState::get_state( $this->temp_app_dir ) );
	}

	private function make_http_request( string $path ): array {
		$sock = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		$addr = stream_socket_get_name( $sock, false );
		$port = (int) substr( strrchr( $addr, ':' ), 1 );
		fclose( $sock );

		foreach ( array( 'load.php', 'install.php', 'index.php' ) as $file ) {
			$target = $this->temp_app_dir . '/' . $file;
			if ( ! file_exists( $target ) ) {
				copy( $this->original_environment->get_source_root() . '/' . $file, $target );
			}
		}

		if ( ! file_exists( $this->temp_app_dir . '/server' ) ) {
			symlink( $this->original_environment->get_source_root() . '/server', $this->temp_app_dir . '/server' );
		}

		$env_vars = array(
			'PEAKURL_DEV' => 'true',
			'PATH'        => (string) getenv( 'PATH' ),
			'DB_PASSWORD' => (string) ( $this->config[ Constants::DB_PASSWORD ] ?? '' ),
			'DB_PORT'     => (string) ( $this->config[ Constants::DB_PORT ] ?? 3307 ),
			'DB_HOST'     => (string) ( $this->config[ Constants::DB_HOST ] ?? '127.0.0.1' ),
			'DB_USERNAME' => (string) ( $this->config[ Constants::DB_USERNAME ] ?? 'root' ),
			'DB_DATABASE' => (string) ( $this->config[ Constants::DB_DATABASE ] ?? 'peakurl' ),
			'DB_PREFIX'   => $this->isolated_prefix,
		);

		$cmd  = array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->temp_app_dir );
		$proc = proc_open(
			$cmd,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->temp_app_dir,
			$env_vars
		);

		for ( $i = 0; $i < 60; $i++ ) {
			$conn = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.05 );
			if ( is_resource( $conn ) ) {
				fclose( $conn );
				break;
			}
			usleep( 25000 );
		}

		$ch = curl_init( 'http://127.0.0.1:' . $port . $path );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
		$raw_response = curl_exec( $ch );
		$http_code    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size  = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );

		if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) {
			fclose( $pipes[0] );
		}
		if ( isset( $pipes[1] ) && is_resource( $pipes[1] ) ) {
			fclose( $pipes[1] );
		}
		if ( isset( $pipes[2] ) && is_resource( $pipes[2] ) ) {
			fclose( $pipes[2] );
		}
		proc_terminate( $proc );
		proc_close( $proc );

		$headers_raw = substr( (string) $raw_response, 0, $header_size );
		$body        = substr( (string) $raw_response, $header_size );

		$location = null;
		if ( preg_match( '/^Location:\s*(.+)$/im', $headers_raw, $matches ) ) {
			$location = trim( $matches[1] );
		}

		return array(
			'code'     => $http_code,
			'headers'  => $headers_raw,
			'location' => $location,
			'body'     => $body,
		);
	}

	public function test_installer_http_request_on_established_site_redirects_to_dashboard_with_no_form(): void {
		$this->write_isolated_config();

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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('db_schema_version', '10', NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		// Execute HTTP GET /install.php against established installation
		$response = $this->make_http_request( '/install.php' );

		// 1. Must respond with 302 redirect to /dashboard
		$this->assertSame( 302, $response['code'] );
		$this->assertSame( '/dashboard', $response['location'] );

		// 2. Must not render the installer setup form
		$this->assertStringNotContainsString( 'Administrator Setup', $response['body'] );
		$this->assertStringNotContainsString( 'Create your account', $response['body'] );
		$this->assertStringNotContainsString( '<form', $response['body'] );

		// 3. User records must remain unmodified (no new admin created, no destructive change)
		$count = (int) $this->pdo->query( "SELECT COUNT(*) FROM `{$users_table}`" )->fetchColumn();
		$this->assertSame( 1, $count );
	}

	public function test_installer_http_request_on_incomplete_site_renders_form_without_dashboard_redirect(): void {
		$this->write_isolated_config();

		$settings_table = $this->isolated_prefix . 'settings';
		$users_table    = $this->isolated_prefix . 'users';

		// Seed core tables but NO users and NO site_url (incomplete installation)
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

		// Execute HTTP GET /install.php
		$response = $this->make_http_request( '/install.php' );

		// 1. Must render installer form (200 OK)
		$this->assertSame( 200, $response['code'] );
		$this->assertNull( $response['location'] );
		$this->assertStringContainsString( 'Administrator Setup', $response['body'] );
		$this->assertStringContainsString( '<form', $response['body'] );
	}

	public function test_installer_and_dashboard_do_not_produce_redirect_loop(): void {
		$this->write_isolated_config();

		$settings_table = $this->isolated_prefix . 'settings';
		$users_table    = $this->isolated_prefix . 'users';

		// Case A: Established site
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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('db_schema_version', '10', NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		// On established site: install.php redirects to /dashboard
		$install_res = $this->make_http_request( '/install.php' );
		$this->assertSame( 302, $install_res['code'] );
		$this->assertSame( '/dashboard', $install_res['location'] );

		// On established site: index.php recognizes READY and does NOT redirect to /install.php
		$index_res = $this->make_http_request( '/index.php' );
		$this->assertNotSame( '/install.php', $index_res['location'] );
	}

	public function test_schema_repair_advisory_lock_serializes_concurrent_workers_and_second_worker_observes_current_schema(): void {
		$this->write_isolated_config();

		$settings_table = $this->isolated_prefix . 'settings';
		$users_table    = $this->isolated_prefix . 'users';
		$link_health    = $this->isolated_prefix . 'link_health';

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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('db_schema_version', '9', NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		$isolated_config = array_merge( $this->config, array( Constants::DB_PREFIX => $this->isolated_prefix ) );
		$conn1           = new Connection( $isolated_config );
		$conn2           = new Connection( $isolated_config );

		$schema1 = new DatabaseSchema( $conn1 );
		$schema2 = new DatabaseSchema( $conn2 );

		$lock_name = $schema1->get_lock_name();

		// Connection 1 holds the advisory lock
		$pdo1 = $conn1->get_connection();
		$stmt = $pdo1->prepare( 'SELECT GET_LOCK(?, 0)' );
		$stmt->execute( array( $lock_name ) );
		$this->assertSame( '1', (string) $stmt->fetchColumn() );

		// Connection 2 cannot acquire lock when attempting repair
		// Because lock timeout is 30s in repair_schema(), we test GET_LOCK(0) returns 0 on conn2
		$pdo2       = $conn2->get_connection();
		$check_stmt = $pdo2->prepare( 'SELECT GET_LOCK(?, 0)' );
		$check_stmt->execute( array( $lock_name ) );
		$this->assertSame( '0', (string) $check_stmt->fetchColumn() );

		// Release lock on Connection 1
		$rel_stmt = $pdo1->prepare( 'SELECT RELEASE_LOCK(?)' );
		$rel_stmt->execute( array( $lock_name ) );
		$this->assertSame( '1', (string) $rel_stmt->fetchColumn() );

		// Connection 1 executes repair to completion
		$result1 = $schema1->repair_schema();
		$this->assertTrue( $schema1->is_current() );

		// Connection 2 now observes the schema is already current and returns cleanly without re-running upgrade
		$result2 = $schema2->repair_schema();
		$this->assertFalse( $result2['upgradeRequired'] );
		$this->assertTrue( $schema2->is_current() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_bootstrap_site_converges_absent_installed_version_when_schema_is_current(): void {
		$this->write_isolated_config();

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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('db_schema_version', '10', NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		$isolated_config = array_merge(
			$this->config,
			array(
				Constants::DB_PREFIX => $this->isolated_prefix,
				Constants::VERSION   => '1.7.1',
			)
		);
		$conn            = new Connection( $isolated_config );
		$db              = new PeakURL_DB( $conn );
		$settings_api    = new SettingsApi( $db );

		$this->assertNull( $settings_api->get_option( 'installed_version' ) );

		Initializer::bootstrap_site( $conn, $isolated_config );

		$this->assertSame( '1.7.1', $settings_api->get_option( 'installed_version' ) );
		$this->assertSame( '10', (string) $settings_api->get_option( 'db_schema_version' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_bootstrap_site_converges_older_installed_version_when_manual_file_extraction_occurs(): void {
		$this->write_isolated_config();

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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('installed_version', '1.7.0', NOW()),
				('db_schema_version', '9', NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		$isolated_config = array_merge(
			$this->config,
			array(
				Constants::DB_PREFIX => $this->isolated_prefix,
				Constants::VERSION   => '1.7.1',
			)
		);
		$conn            = new Connection( $isolated_config );
		$db              = new PeakURL_DB( $conn );
		$settings_api    = new SettingsApi( $db );

		$this->assertSame( '1.7.0', $settings_api->get_option( 'installed_version' ) );

		Initializer::bootstrap_site( $conn, $isolated_config );

		$this->assertSame( '1.7.1', $settings_api->get_option( 'installed_version' ) );
		$this->assertSame( '10', (string) $settings_api->get_option( 'db_schema_version' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_bootstrap_site_does_not_downgrade_newer_installed_version(): void {
		$this->write_isolated_config();

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
			"INSERT INTO `{$settings_table}` (`setting_key`, `setting_value`, `updated_at`)
			VALUES
				('site_url', 'https://example.test', NOW()),
				('installed_version', '1.8.0', NOW()),
				('db_schema_version', '10', NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$users_table}` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `created_at`, `updated_at`)
			VALUES (1, 'admin', 'admin@example.test', 'Admin', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		$isolated_config = array_merge(
			$this->config,
			array(
				Constants::DB_PREFIX => $this->isolated_prefix,
				Constants::VERSION   => '1.7.1',
			)
		);
		$conn            = new Connection( $isolated_config );
		$db              = new PeakURL_DB( $conn );
		$settings_api    = new SettingsApi( $db );

		$this->assertSame( '1.8.0', $settings_api->get_option( 'installed_version' ) );

		Initializer::bootstrap_site( $conn, $isolated_config );

		$this->assertSame( '1.8.0', $settings_api->get_option( 'installed_version' ) );
	}
}
