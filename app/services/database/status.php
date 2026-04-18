<?php
/**
 * Database schema status helpers.
 *
 * @package PeakURL\Services\Database
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Database;

use PeakURL\Database\RepairSpecs;
use PeakURL\Database\SchemaSpecs;
use PeakURL\Includes\Constants;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Status — schema status and issue collection.
 *
 * @since 1.0.14
 */
class Status {

	/**
	 * Shared schema context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new schema status helper.
	 *
	 * @param Context $context Shared schema context helper.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Determine whether the installed schema is already current.
	 *
	 * Uses a fast path suitable for runtime bootstrap on every request.
	 *
	 * @param int $target_version Current codebase schema version.
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_current( int $target_version ): bool {
		if ( ! empty( $this->get_missing_tables() ) ) {
			return false;
		}

		if ( '' !== trim( (string) $this->context->get_option( Constants::SETTING_DB_SCHEMA_LAST_ERROR ) ) ) {
			return false;
		}

		if ( $this->repairs_pending() ) {
			return false;
		}

		return $this->context->get_recorded_version() >= $target_version;
	}

	/**
	 * Inspect the current database schema state in detail.
	 *
	 * @param int $target_version Current codebase schema version.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_status( int $target_version ): array {
		return $this->get_payload(
			$target_version,
			$this->get_issues( $target_version ),
			array(),
			false,
		);
	}

	/**
	 * Build a consistent schema status payload.
	 *
	 * @param int                              $target_version Current codebase schema version.
	 * @param array<int, array<string, string>> $issues         Current issue list.
	 * @param array<int, string>                $changes        Applied repair labels.
	 * @param bool                              $upgraded       Whether this call upgraded the schema.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_payload(
		int $target_version,
		array $issues,
		array $changes,
		bool $upgraded
	): array {
		$error_count   = 0;
		$warning_count = 0;

		foreach ( $issues as $issue ) {
			$severity = (string) ( $issue['severity'] ?? '' );

			if ( 'error' === $severity ) {
				++$error_count;
				continue;
			}

			if ( 'warning' === $severity ) {
				++$warning_count;
			}
		}

		$current_version = $this->context->get_recorded_version();

		return array(
			'currentVersion'  => $current_version,
			'targetVersion'   => $target_version,
			'compatible'      => 0 === $error_count,
			'upgradeRequired' => $error_count > 0 || $warning_count > 0,
			'upToDate'        => 0 === $error_count &&
				0 === $warning_count &&
				$current_version >= $target_version,
			'errorCount'      => $error_count,
			'warningCount'    => $warning_count,
			'issues'          => array_values( $issues ),
			'issuesCount'     => count( $issues ),
			'missingTables'   => $this->get_missing_tables(),
			'lastUpgradedAt'  => Date::mysql_to_rfc3339(
				$this->context->get_option( Constants::SETTING_DB_SCHEMA_LAST_UPGRADED_AT )
			),
			'lastError'       => $this->context->get_option(
				Constants::SETTING_DB_SCHEMA_LAST_ERROR
			),
			'upgraded'        => $upgraded,
			'changes'         => array_values( $changes ),
		);
	}

	/**
	 * Return the list of currently-missing managed tables.
	 *
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	public function get_missing_tables(): array {
		return $this->context->get_missing_tables( SchemaSpecs::managed_tables() );
	}

	/**
	 * Collect current schema issues with optional version mismatch reporting.
	 *
	 * @param int  $target_version        Current codebase schema version.
	 * @param bool $include_version_issue Whether to include the schema-version issue.
	 * @return array<int, array<string, string>>
	 * @since 1.0.14
	 */
	public function get_issues(
		int $target_version,
		bool $include_version_issue = true
	): array {
		$issues     = array();
		$connection = $this->context->get_connection();

		foreach ( SchemaSpecs::managed_tables() as $table_name ) {
			if ( ! $connection->table_exists( $table_name ) ) {
				$issues[] = $this->make_issue(
					'missing-table-' . $table_name,
					'error',
					sprintf(
						/* translators: %s is the prefixed database table name. */
						__( 'The %s table is missing.', 'peakurl' ),
						$this->context->get_table_name( $table_name ),
					),
				);
				continue;
			}

			foreach ( $this->get_missing_columns( $table_name ) as $column_name ) {
				$issues[] = $this->make_issue(
					'missing-column-' . $table_name . '-' . $column_name,
					'error',
					sprintf(
						/* translators: 1: prefixed table name, 2: column name. */
						__( 'The %1$s table is missing the %2$s column.', 'peakurl' ),
						$this->context->get_table_name( $table_name ),
						$column_name,
					),
				);
			}

			foreach ( $this->get_missing_indexes( $table_name ) as $index_name ) {
				$issues[] = $this->make_issue(
					'missing-index-' . $table_name . '-' . $index_name,
					'warning',
					sprintf(
						/* translators: 1: prefixed table name, 2: index name. */
						__( 'The %1$s table is missing the %2$s index.', 'peakurl' ),
						$this->context->get_table_name( $table_name ),
						$index_name,
					),
				);
			}

			foreach ( $this->get_missing_constraints( $table_name ) as $constraint_name ) {
				$issues[] = $this->make_issue(
					'missing-foreign-key-' . $table_name . '-' . $constraint_name,
					'warning',
					sprintf(
						/* translators: 1: prefixed table name, 2: foreign key name. */
						__( 'The %1$s table is missing the %2$s foreign key.', 'peakurl' ),
						$this->context->get_table_name( $table_name ),
						$this->context->get_constraint_name( $constraint_name ),
					),
				);
			}
		}

		if (
			$connection->table_exists( 'api_keys' ) &&
			$connection->column_exists( 'api_keys', 'key_value' )
		) {
			$issues[] = $this->make_issue(
				'api-keys-plain-text-storage',
				'error',
				__( 'The api_keys table still contains plain-text key storage and needs to be repaired.', 'peakurl' ),
			);
		}

		if (
			$connection->table_exists( 'api_keys' ) &&
			(
				$connection->column_allows_null( 'api_keys', 'key_hash' ) ||
				$connection->column_allows_null( 'api_keys', 'key_prefix' ) ||
				$connection->column_allows_null( 'api_keys', 'key_last_four' )
			)
		) {
			$issues[] = $this->make_issue(
				'api-key-hash-columns-allow-null',
				'error',
				__( 'The hashed API key columns still allow NULL values and need to be repaired.', 'peakurl' ),
			);
		}

		foreach ( RepairSpecs::opaque_id_repairs() as $repair ) {
			if ( empty( $this->context->get_ids_with_prefix( (string) $repair['table'], (string) $repair['prefix'] ) ) ) {
				continue;
			}

			$issues[] = $this->make_issue(
				(string) $repair['issue_id'],
				'warning',
				(string) $repair['issue_label'],
			);
		}

		if ( $include_version_issue ) {
			$current_version = $this->context->get_recorded_version();

			if ( $current_version < $target_version ) {
				$issues[] = $this->make_issue(
					'outdated-schema-version',
					'warning',
					sprintf(
						/* translators: 1: current DB schema version, 2: required DB schema version. */
						__( 'The database schema version %1$d is behind the required version %2$d.', 'peakurl' ),
						$current_version,
						$target_version,
					),
				);
			}
		}

		return $issues;
	}

	/**
	 * Build a compact schema issue payload.
	 *
	 * @param string $id       Stable issue identifier.
	 * @param string $severity `error` or `warning`.
	 * @param string $label    Human-readable issue label.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	private function make_issue(
		string $id,
		string $severity,
		string $label
	): array {
		return array(
			'id'       => $id,
			'severity' => $severity,
			'label'    => $label,
		);
	}

	/**
	 * Determine whether known non-versioned repairs are still pending.
	 *
	 * These checks keep runtime bootstrap honest when the schema version itself
	 * has not changed, but existing data still needs to be normalized.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	private function repairs_pending(): bool {
		$connection = $this->context->get_connection();

		if (
			$connection->table_exists( 'api_keys' ) &&
			$connection->column_exists( 'api_keys', 'key_value' )
		) {
			return true;
		}

		if (
			$connection->table_exists( 'api_keys' ) &&
			(
				$connection->column_allows_null( 'api_keys', 'key_hash' ) ||
				$connection->column_allows_null( 'api_keys', 'key_prefix' ) ||
				$connection->column_allows_null( 'api_keys', 'key_last_four' )
			)
		) {
			return true;
		}

		foreach ( RepairSpecs::opaque_id_repairs() as $repair ) {
			if ( ! empty( $this->context->get_ids_with_prefix( (string) $repair['table'], (string) $repair['prefix'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the missing columns for a specific managed table.
	 *
	 * @param string $table_name Base table name.
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	private function get_missing_columns( string $table_name ): array {
		$missing_columns = array();

		foreach ( SchemaSpecs::column_specs()[ $table_name ] ?? array() as $spec ) {
			$column_name = (string) ( $spec['name'] ?? '' );

			if (
				'' === $column_name ||
				$this->context->get_connection()->column_exists( $table_name, $column_name )
			) {
				continue;
			}

			$missing_columns[] = $column_name;
		}

		return $missing_columns;
	}

	/**
	 * Return the missing indexes for a specific managed table.
	 *
	 * @param string $table_name Base table name.
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	private function get_missing_indexes( string $table_name ): array {
		$missing_indexes = array();

		foreach ( SchemaSpecs::index_specs()[ $table_name ] ?? array() as $spec ) {
			$index_name = (string) ( $spec['name'] ?? '' );

			if (
				'' === $index_name ||
				$this->context->get_connection()->index_exists( $table_name, $index_name )
			) {
				continue;
			}

			$missing_indexes[] = $index_name;
		}

		return $missing_indexes;
	}

	/**
	 * Return the missing foreign keys for a specific managed table.
	 *
	 * @param string $table_name Base table name.
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	private function get_missing_constraints( string $table_name ): array {
		$missing_constraints = array();

		foreach ( SchemaSpecs::foreign_key_specs()[ $table_name ] ?? array() as $spec ) {
			$constraint_name = (string) ( $spec['name'] ?? '' );

			if ( '' === $constraint_name || $this->context->constraint_exists( $table_name, $constraint_name ) ) {
				continue;
			}

			$missing_constraints[] = $constraint_name;
		}

		return $missing_constraints;
	}
}
