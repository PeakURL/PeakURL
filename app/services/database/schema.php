<?php
/**
 * PeakURL database schema service.
 *
 * @package PeakURL\Services\Database
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Database;

use PeakURL\Includes\Connection;
use PeakURL\Includes\Constants;

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
		$resolved_schema_path = is_string( $schema_path ) && '' !== trim( $schema_path )
			? $schema_path
			: ABSPATH . 'app/database/schema.sql';

		$this->context         = new Context( $connection );
		$this->status          = new Status( $this->context );
		$this->upgrade_service = new Upgrade( $this->context, $resolved_schema_path );
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
	 * Determine whether the installed schema is already current.
	 *
	 * Uses a fast path suitable for runtime bootstrap on every request.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_current(): bool {
		return $this->status->is_current( $this->get_target_version() );
	}

	/**
	 * Ensure the database schema is current for the active codebase.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function ensure_current(): array {
		if ( $this->is_current() ) {
			return $this->status->get_payload(
				$this->get_target_version(),
				array(),
				array(),
				false,
			);
		}

		return $this->upgrade();
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
			$this->context->update_setting(
				Constants::SETTING_DB_SCHEMA_VERSION,
				(string) $this->get_target_version(),
				false,
			);
			$this->context->update_setting(
				Constants::SETTING_DB_SCHEMA_LAST_UPGRADED_AT,
				$upgraded_at,
				false,
			);
			$this->context->delete_setting( Constants::SETTING_DB_SCHEMA_LAST_ERROR );

			$status = $this->inspect();

			$status['upgraded']  = true;
			$status['appliedAt'] = \peakurl_mysql_to_rfc3339( $upgraded_at );
			$status['changes']   = $changes;

			return $status;
		} catch ( \Throwable $exception ) {
			$this->context->update_setting(
				Constants::SETTING_DB_SCHEMA_LAST_ERROR,
				$exception->getMessage(),
				false,
			);

			throw $exception;
		}
	}
}
