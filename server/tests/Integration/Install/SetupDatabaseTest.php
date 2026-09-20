<?php
/**
 * Integration tests for setup-database CLI script and Initializer flow.
 *
 * @package PeakURL\Tests\Integration\Install
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Install;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Database\Connection;
use PDO;

class SetupDatabaseTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private array $config;
	private string $isolated_prefix = 'pktest_';

	protected function setUp(): void {
		parent::setUp();

		$this->config     = Configuration::get_current();
		$this->connection = Connection::get_instance( $this->config );
		$this->pdo        = $this->connection->get_connection();

		$this->drop_isolated_tables();
	}

	protected function tearDown(): void {
		$this->drop_isolated_tables();
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

	public function test_no_hard_coded_owner_defaults_exist_in_setup_script(): void {
		$script_path = defined( 'ABSPATH' )
			? ABSPATH . 'server/bin/setup-database.php'
			: dirname( __DIR__, 3 ) . '/bin/setup-database.php';

		$this->assertFileExists( $script_path );
		$script_contents = (string) file_get_contents( $script_path );

		$this->assertStringNotContainsString( 'admin12345', $script_contents );
		$this->assertStringNotContainsString( 'owner@example.com', $script_contents );
		$this->assertStringNotContainsString( 'CREATE DATABASE', $script_contents );
		$this->assertStringNotContainsString( 'new DatabaseSchema', $script_contents );
		$this->assertStringNotContainsString( "'admin'", $script_contents );
	}

	public function test_initializer_schema_and_bootstrap_path_is_used(): void {
		$script_path = defined( 'ABSPATH' )
			? ABSPATH . 'server/bin/setup-database.php'
			: dirname( __DIR__, 3 ) . '/bin/setup-database.php';

		$script_contents = (string) file_get_contents( $script_path );

		$this->assertStringContainsString( 'Initializer::initialize_schema', $script_contents );
		$this->assertStringContainsString( 'Initializer::bootstrap_site', $script_contents );
	}

	public function test_setup_database_with_explicit_owner_config_succeeds(): void {
		$result = $this->run_cli_script(
			array(
				'DB_HOST'                => (string) $this->config[ Constants::DB_HOST ],
				'DB_PORT'                => (string) $this->config[ Constants::DB_PORT ],
				'DB_DATABASE'            => (string) $this->config[ Constants::DB_DATABASE ],
				'DB_USERNAME'            => (string) $this->config[ Constants::DB_USERNAME ],
				'DB_PASSWORD'            => (string) $this->config[ Constants::DB_PASSWORD ],
				'DB_PREFIX'              => $this->isolated_prefix,
				'PEAKURL_DEV'            => 'true',
				'PEAKURL_OWNER_USERNAME' => 'testadmin',
				'PEAKURL_OWNER_EMAIL'    => 'testadmin@example.com',
				'PEAKURL_OWNER_PASSWORD' => 'SecurePass12345!',
				'PEAKURL_WORKSPACE_NAME' => 'TestWorkspace',
				'PEAKURL_WORKSPACE_SLUG' => 'testworkspace',
			)
		);

		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( 'Database ready: ' . (string) $this->config[ Constants::DB_DATABASE ], $result['stdout'] );

		// Verify table was created under the isolated prefix.
		$stmt = $this->pdo->query( "SHOW TABLES LIKE '{$this->isolated_prefix}users'" );
		$this->assertNotEmpty( $stmt->fetchAll() );
	}

	public function test_missing_owner_config_fails_cleanly(): void {
		$result = $this->run_cli_script(
			array(
				'DB_HOST'                => (string) $this->config[ Constants::DB_HOST ],
				'DB_PORT'                => (string) $this->config[ Constants::DB_PORT ],
				'DB_DATABASE'            => (string) $this->config[ Constants::DB_DATABASE ],
				'DB_USERNAME'            => (string) $this->config[ Constants::DB_USERNAME ],
				'DB_PASSWORD'            => (string) $this->config[ Constants::DB_PASSWORD ],
				'DB_PREFIX'              => $this->isolated_prefix,
				'PEAKURL_DEV'            => 'true',
				'PEAKURL_OWNER_USERNAME' => '',
				'PEAKURL_OWNER_EMAIL'    => '',
				'PEAKURL_OWNER_PASSWORD' => '',
			)
		);

		$this->assertSame( 1, $result['code'] );
		$this->assertStringContainsString( 'PeakURL is not installed yet', $result['stderr'] );
	}

	public function test_generator_still_resolves_canonical_version(): void {
		$version_path = ( defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 3 ) ) . '/.version';
		if ( ! file_exists( $version_path ) ) {
			$version_path = dirname( __DIR__, 4 ) . '/.version';
		}

		$this->assertFileExists( $version_path );
		$canonical_version = trim( (string) file_get_contents( $version_path ) );

		$generator_tag = \get_generator_tag();
		$this->assertSame( '<meta name="generator" content="PeakURL ' . $canonical_version . '">', $generator_tag );
	}

	/**
	 * Run the setup-database CLI script via a separate PHP process.
	 *
	 * @param array<string, string> $env_vars Environment variables to pass.
	 * @return array{code: int, stdout: string, stderr: string}
	 */
	private function run_cli_script( array $env_vars ): array {
		$php_binary  = PHP_BINARY;
		$script_path = defined( 'ABSPATH' )
			? ABSPATH . 'server/bin/setup-database.php'
			: dirname( __DIR__, 3 ) . '/bin/setup-database.php';

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$env = array_merge( (array) getenv(), $env_vars );

		$process = proc_open(
			escapeshellcmd( $php_binary ) . ' ' . escapeshellarg( $script_path ),
			$descriptors,
			$pipes,
			dirname( $script_path, 2 ),
			$env
		);

		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Failed to launch setup-database process.' );
		}

		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		return array(
			'code'   => $exit_code,
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}
}
