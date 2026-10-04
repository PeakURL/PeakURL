<?php
/**
 * PeakURL database schema service.
 *
 * @package PeakURL\Services\Database
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Database;

use PeakURL\Services\Database\Connection;
use PeakURL\Core\Config\Constants;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Schema — versioned schema inspection and upgrade service.
 *
 * Keeps schema reconciliation out of the lower-level connection wrapper so
 * installs, updates, and runtime bootstrap can all use one focused service.
 *
 * @since 1.0.14
 */
class Schema {

	/**
	 * Shared schema context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Schema status helper.
	 *
	 * @var Status
	 * @since 1.0.14
	 */
	private Status $status;

	/**
	 * Schema upgrade helper.
	 *
	 * @var Upgrade
	 * @since 1.0.14
	 */
	private Upgrade $upgrade_service;

	/**
	 * Create a new database schema service.
	 *
	 * @param Connection  $connection  Shared connection manager.
	 * @param string|null $schema_path Optional schema.sql path override.
	 * @since 1.0.14
	 */
	public function __construct(
		Connection $connection,
		?string $schema_path = null
	) {
		$default_schema_file = \PeakURL\Core\Config\Environment::get_instance()->get_database_schema_path();

		$schema_file = is_string( $schema_path ) && '' !== trim( $schema_path )
			? $schema_path
			: $default_schema_file;

		$this->context         = new Context( $connection );
		$this->status          = new Status( $this->context );
		$this->upgrade_service = new Upgrade( $this->context, $schema_file );
	}

	/**
	 * Return the current target schema version for this codebase.
	 *
	 * @return int
	 * @since 1.0.14
	 */
	public function get_target_version(): int {
		return Constants::DB_SCHEMA_VERSION;
	}

	/**
	 * Determine whether the installed schema is already current and structurally compatible.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_current(): bool {
		return $this->status->is_current( $this->get_target_version() );
	}

	/**
	 * Determine whether the database schema needs repair or upgrade.
	 *
	 * Uses a lightweight version and error check suitable for runtime bootstrap
	 * to determine whether full schema reconciliation is necessary.
	 *
	 * @return bool
	 * @since 1.7.1
	 */
	public function needs_repair(): bool {
		return $this->status->needs_repair( $this->get_target_version() );
	}

	/**
	 * Get the advisory lock name for schema repairs.
	 *
	 * Scoped to the database name and table prefix to prevent cross-installation contention.
	 *
	 * @return string
	 * @since 1.7.1
	 */
	public function get_lock_name(): string {
		$connection = $this->context->get_connection();
		$db_name    = (string) ( $connection->get_config()[ Constants::DB_DATABASE ] ?? '' );
		$prefix     = $connection->get_table_prefix();

		return 'peakurl_schema_repair_' . md5( $db_name . ':' . $prefix );
	}

	/**
	 * Repair the database schema so it matches the active codebase.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function repair_schema(): array {
		if ( $this->is_current() ) {
			return $this->status->get_payload(
				$this->get_target_version(),
				array(),
				array(),
				false,
			);
		}

		$lock_name = $this->get_lock_name();
		$pdo       = $this->context->get_pdo();
		$acquired  = false;

		try {
			$stmt = $pdo->prepare( 'SELECT GET_LOCK(?, 30)' );
			$stmt->execute( array( $lock_name ) );
			$acquired = '1' === (string) $stmt->fetchColumn();
		} catch ( \Throwable $lock_exception ) {
			$acquired = false;
		}

		try {
			if ( $this->is_current() ) {
				return $this->status->get_payload(
					$this->get_target_version(),
					array(),
					array(),
					false,
				);
			}

			if ( ! $acquired ) {
				throw new \RuntimeException(
					__( 'PeakURL could not acquire the database schema repair lock.', 'peakurl' ),
				);
			}

			return $this->upgrade();
		} finally {
			if ( $acquired ) {
				try {
					$stmt = $pdo->prepare( 'SELECT RELEASE_LOCK(?)' );
					$stmt->execute( array( $lock_name ) );
				} catch ( \Throwable $unlock_exception ) {
					// Advisory locks are released automatically on connection close.
				}
			}
		}
	}

	/**
	 * Inspect the current database schema state in detail.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function inspect(): array {
		return $this->status->get_status( $this->get_target_version() );
	}

	/**
	 * Apply the canonical schema file plus idempotent repair steps.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function upgrade(): array {
		$changes = array();

		try {
			$this->upgrade_service->upgrade( $changes );

			$remaining_issues = $this->status->get_issues(
				$this->get_target_version(),
				false,
			);

			if ( ! empty( $remaining_issues ) ) {
				throw new \RuntimeException(
					__( 'PeakURL could not fully repair the database schema.', 'peakurl' ),
				);
			}

			$upgraded_at = gmdate( 'Y-m-d H:i:s' );
			$this->context->update_option(
				Constants::SETTING_DB_SCHEMA_VERSION,
				(string) $this->get_target_version(),
				false,
			);
			$this->context->update_option(
				Constants::SETTING_DB_SCHEMA_LAST_UPGRADED_AT,
				$upgraded_at,
				false,
			);
			$this->context->delete_option( Constants::SETTING_DB_SCHEMA_LAST_ERROR );

			$status = $this->inspect();

			$status['upgraded']  = true;
			$status['appliedAt'] = Date::mysql_to_rfc3339( $upgraded_at );
			$status['changes']   = $changes;

			return $status;
		} catch ( \Throwable $exception ) {
			$this->context->update_option(
				Constants::SETTING_DB_SCHEMA_LAST_ERROR,
				$exception->getMessage(),
				false,
			);

			throw $exception;
		}
	}
}
