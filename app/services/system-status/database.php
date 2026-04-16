<?php
/**
 * Database status details builder.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Database — build the database section of the system-status payload.
 *
 * @since 1.0.14
 */
class Database {

	/**
	 * Shared system-status context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new database status helper.
	 *
	 * @param Context $context Shared system-status context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Build database status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function build(): array {
		$config          = $this->context->get_config();
		$metadata        = $this->query_metadata();
		$schema_status   = $this->context->get_database_schema()->inspect();
		$version         = trim( (string) ( $metadata['version'] ?? '' ) );
		$version_comment = trim( (string) ( $metadata['versionComment'] ?? '' ) );

		return array(
			'connected'             => true,
			'host'                  => (string) ( $config['DB_HOST'] ?? '' ),
			'port'                  => (int) ( $config['DB_PORT'] ?? 0 ),
			'name'                  => (string) ( $config['DB_DATABASE'] ?? '' ),
			'charset'               => (string) ( $config['DB_CHARSET'] ?? 'utf8mb4' ),
			'prefix'                => (string) ( $config['DB_PREFIX'] ?? '' ),
			'version'               => $version,
			'versionComment'        => $version_comment,
			'serverType'            => $this->detect_server_type(
				$version,
				$version_comment,
			),
			'schemaVersion'         => (int) ( $schema_status['currentVersion'] ?? 0 ),
			'requiredSchemaVersion' => (int) ( $schema_status['targetVersion'] ?? 0 ),
			'schemaCompatible'      => ! empty( $schema_status['compatible'] ),
			'schemaUpgradeRequired' => ! empty(
				$schema_status['upgradeRequired']
			),
			'schemaIssuesCount'     => (int) ( $schema_status['issuesCount'] ?? 0 ),
			'schemaLastUpgradedAt'  => (string) ( $schema_status['lastUpgradedAt'] ?? '' ),
			'schemaLastError'       => (string) ( $schema_status['lastError'] ?? '' ),
		);
	}

	/**
	 * Query basic database server metadata.
	 *
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	private function query_metadata(): array {
		try {
			$statement = $this->context->get_db()->get_connection()->query(
				'SELECT VERSION() AS version, @@version_comment AS version_comment'
			);
			$row       = $statement instanceof \PDOStatement
				? $statement->fetch( \PDO::FETCH_ASSOC )
				: false;

			if ( is_array( $row ) ) {
				return array(
					'version'        => trim( (string) ( $row['version'] ?? '' ) ),
					'versionComment' => trim(
						(string) ( $row['version_comment'] ?? '' )
					),
				);
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return array();
		}

		return array();
	}

	/**
	 * Detect the database server family.
	 *
	 * @param string $version         Version string.
	 * @param string $version_comment Version comment.
	 * @return string
	 * @since 1.0.14
	 */
	private function detect_server_type(
		string $version,
		string $version_comment
	): string {
		$haystack = strtolower( $version . ' ' . $version_comment );

		if ( false !== strpos( $haystack, 'mariadb' ) ) {
			return 'MariaDB';
		}

		if ( '' !== trim( $version ) || '' !== trim( $version_comment ) ) {
			return 'MySQL';
		}

		return 'Unknown';
	}
}
