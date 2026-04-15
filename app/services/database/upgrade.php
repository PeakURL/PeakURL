<?php
/**
 * Database schema upgrade helpers.
 *
 * @package PeakURL\Services\Database
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Database;

use PeakURL\Database\RepairSpecs;
use PeakURL\Database\SchemaSpecs;
use PeakURL\Utils\Database;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Upgrade — schema upgrade and normalization flow.
 *
 * @since 1.0.14
 */
class Upgrade {

	/**
	 * Shared schema context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Absolute path to the canonical schema.sql file.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $schema_path;

	/**
	 * Create a new schema upgrade helper.
	 *
	 * @param Context $context     Shared schema context helper.
	 * @param string  $schema_path Absolute path to schema.sql.
	 * @since 1.0.14
	 */
	public function __construct( Context $context, string $schema_path ) {
		$this->context     = $context;
		$this->schema_path = $schema_path;
	}

	/**
	 * Apply the canonical schema file plus idempotent repair steps.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	public function upgrade( array &$changes ): void {
		$this->create_tables( $changes );
		$this->repair_tables( $changes );
		$this->normalize_ids( $changes );
		$this->remove_orphans( $changes );
		$this->repair_foreign_keys( $changes );
	}

	/**
	 * Create the base tables from schema.sql when needed.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function create_tables( array &$changes ): void {
		$missing_tables = $this->context->get_missing_tables( SchemaSpecs::managed_tables() );
		$schema         = file_get_contents( $this->schema_path );

		if ( false === $schema ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the bundled database schema file.', 'peakurl' ),
			);
		}

		$this->context->get_pdo()->exec(
			$this->context->get_connection()->prefix_schema( $schema ),
		);

		if ( ! empty( $missing_tables ) ) {
			$changes[] = sprintf(
				/* translators: %s is a comma-separated table list. */
				__( 'Created missing tables: %s.', 'peakurl' ),
				implode(
					', ',
					array_map(
						fn( string $table_name ): string => $this->context->get_table_name( $table_name ),
						$missing_tables,
					),
				),
			);
		}
	}

	/**
	 * Repair additive table fields, indexes, and table-specific storage rules.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function repair_tables( array &$changes ): void {
		$column_specs = SchemaSpecs::column_specs();
		$index_specs  = SchemaSpecs::index_specs();

		foreach ( SchemaSpecs::managed_tables() as $table_name ) {
			$this->add_missing_columns(
				$table_name,
				$column_specs[ $table_name ] ?? array(),
				$changes,
			);

			if ( 'api_keys' === $table_name ) {
				$this->repair_api_keys( $changes );
			}

			$this->add_missing_indexes(
				$table_name,
				$index_specs[ $table_name ] ?? array(),
				$changes,
			);
		}
	}

	/**
	 * Repair API key storage so the table matches the hashed-key schema.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function repair_api_keys( array &$changes ): void {
		$connection = $this->context->get_connection();

		if ( ! $connection->table_exists( 'api_keys' ) ) {
			return;
		}

		$table_name = $this->context->get_table_identifier( 'api_keys' );

		if ( $connection->column_exists( 'api_keys', 'key_value' ) ) {
			$this->context->get_pdo()->exec(
				'UPDATE ' . $table_name . '
				SET key_hash = COALESCE(NULLIF(key_hash, \'\'), SHA2(key_value, 256)),
					key_prefix = COALESCE(NULLIF(key_prefix, \'\'), LEFT(key_value, 16)),
					key_last_four = COALESCE(NULLIF(key_last_four, \'\'), RIGHT(key_value, 4))
				WHERE key_value IS NOT NULL
				AND key_value <> \'\'',
			);

			if ( $connection->index_exists( 'api_keys', 'uniq_api_keys_key_value' ) ) {
				$this->context->get_pdo()->exec(
					'ALTER TABLE ' . $table_name . ' DROP INDEX ' .
					Database::quote_identifier( 'uniq_api_keys_key_value' ),
				);
			}

			$this->context->get_pdo()->exec(
				'ALTER TABLE ' . $table_name . ' DROP COLUMN ' .
				Database::quote_identifier( 'key_value' ),
			);

			$changes[] = __( 'Migrated API keys to hashed storage.', 'peakurl' );
		}

		$missing_values = (int) $this->context->get_var(
			'SELECT COUNT(*)
			FROM api_keys
			WHERE key_hash IS NULL
			OR key_hash = \'\'
			OR key_prefix IS NULL
			OR key_prefix = \'\'
			OR key_last_four IS NULL
			OR key_last_four = \'\'',
		);

		if ( $missing_values > 0 ) {
			throw new \RuntimeException(
				__( 'PeakURL found API keys that could not be repaired to the hashed-key schema.', 'peakurl' ),
			);
		}

		if (
			$connection->column_allows_null( 'api_keys', 'key_hash' ) ||
			$connection->column_allows_null( 'api_keys', 'key_prefix' ) ||
			$connection->column_allows_null( 'api_keys', 'key_last_four' )
		) {
			$this->context->get_pdo()->exec(
				'ALTER TABLE ' . $table_name . '
				MODIFY COLUMN ' . Database::quote_identifier( 'key_hash' ) . ' CHAR(64) NOT NULL,
				MODIFY COLUMN ' . Database::quote_identifier( 'key_prefix' ) . ' VARCHAR(16) NOT NULL,
				MODIFY COLUMN ' . Database::quote_identifier( 'key_last_four' ) . ' CHAR(4) NOT NULL',
			);
			$changes[] = __( 'Repaired hashed API key column constraints.', 'peakurl' );
		}
	}

	/**
	 * Normalize prefixed string IDs to the opaque ID format used by PeakURL.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function normalize_ids( array &$changes ): void {
		foreach ( RepairSpecs::opaque_id_repairs() as $repair ) {
			$this->normalize_prefixed_ids(
				(string) $repair['table'],
				(string) $repair['prefix'],
				(string) $repair['change_label'],
				$changes,
			);
		}
	}

	/**
	 * Normalize prefixed row IDs for a specific table.
	 *
	 * @param string              $table_name Base table name.
	 * @param string              $prefix     Row ID prefix including underscore.
	 * @param string              $label      Human-readable repair label.
	 * @param array<int, string> &$changes    Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function normalize_prefixed_ids(
		string $table_name,
		string $prefix,
		string $label,
		array &$changes
	): void {
		$connection = $this->context->get_connection();

		if ( ! $connection->table_exists( $table_name ) ) {
			return;
		}

		$matching_ids = $this->context->get_ids_with_prefix( $table_name, $prefix );

		if ( empty( $matching_ids ) ) {
			return;
		}

		$table_identifier = $this->context->get_table_identifier( $table_name );
		$statement        = $this->context->get_pdo()->prepare(
			'UPDATE ' . $table_identifier . '
			SET id = :new_id
			WHERE id = :old_id'
		);
		$updated_count    = 0;

		foreach ( $matching_ids as $old_id ) {
			$new_id = substr( $old_id, strlen( $prefix ) );

			if (
				'' === $new_id ||
				$this->context->has_row_id( $table_name, $new_id, $old_id )
			) {
				$new_id = $this->context->generate_row_id( $table_name, $old_id );
			}

			if ( '' === $new_id || $new_id === $old_id ) {
				continue;
			}

			$statement->execute(
				array(
					'new_id' => $new_id,
					'old_id' => $old_id,
				),
			);

			if ( $statement->rowCount() > 0 ) {
				++$updated_count;
			}
		}

		if ( $updated_count > 0 ) {
			$changes[] = sprintf(
				/* translators: 1: repair label, 2: number of updated rows. */
				__( '%1$s %2$d rows were updated.', 'peakurl' ),
				$label,
				$updated_count,
			);
		}
	}

	/**
	 * Remove orphaned rows that block foreign key creation.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function remove_orphans( array &$changes ): void {
		foreach ( RepairSpecs::orphan_cleanup_queries() as $query ) {
			$affected_rows = (int) $this->context->get_pdo()->exec(
				$this->context->get_connection()->prefix_sql( (string) $query['sql'] ),
			);

			if ( $affected_rows > 0 ) {
				$changes[] = sprintf(
					/* translators: 1: cleanup label, 2: number of affected rows. */
					__( '%1$s %2$d rows were affected.', 'peakurl' ),
					(string) $query['label'],
					$affected_rows,
				);
			}
		}
	}

	/**
	 * Repair missing foreign keys after orphan cleanup.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function repair_foreign_keys( array &$changes ): void {
		foreach ( SchemaSpecs::foreign_key_specs() as $table_name => $specs ) {
			foreach ( $specs as $spec ) {
				$constraint_name      = (string) $spec['name'];
				$expected_delete_rule = $this->get_expected_delete_rule(
					(string) $spec['definition'],
				);

				if ( $this->context->has_constraint( $table_name, $constraint_name ) ) {
					$current_delete_rule = $this->context->get_delete_rule(
						$table_name,
						$constraint_name,
					);

					if (
						null === $expected_delete_rule ||
						$expected_delete_rule === $current_delete_rule
					) {
						continue;
					}

					$this->context->get_pdo()->exec(
						'ALTER TABLE ' . $this->context->get_table_identifier( $table_name ) .
						' DROP FOREIGN KEY ' .
						Database::quote_identifier(
							$this->context->get_constraint_name( $constraint_name )
						),
					);
					$this->context->get_pdo()->exec(
						'ALTER TABLE ' . $this->context->get_table_identifier( $table_name ) .
						' ADD CONSTRAINT ' .
						Database::quote_identifier(
							$this->context->get_constraint_name( $constraint_name )
						) .
						' ' .
						$this->context->get_connection()->prefix_sql( (string) $spec['definition'] ),
					);

					$changes[] = sprintf(
						/* translators: 1: prefixed table name, 2: foreign key name. */
						__( 'Updated the %2$s foreign key on the %1$s table.', 'peakurl' ),
						$this->context->get_table_name( $table_name ),
						$this->context->get_constraint_name( $constraint_name ),
					);

					continue;
				}

				$this->context->get_pdo()->exec(
					'ALTER TABLE ' . $this->context->get_table_identifier( $table_name ) .
					' ADD CONSTRAINT ' .
					Database::quote_identifier(
						$this->context->get_constraint_name( $constraint_name )
					) .
					' ' .
					$this->context->get_connection()->prefix_sql( (string) $spec['definition'] ),
				);

				$changes[] = sprintf(
					/* translators: 1: prefixed table name, 2: foreign key name. */
					__( 'Added the %2$s foreign key to the %1$s table.', 'peakurl' ),
					$this->context->get_table_name( $table_name ),
					$this->context->get_constraint_name( $constraint_name ),
				);
			}
		}
	}

	/**
	 * Add missing columns to a managed table.
	 *
	 * @param string                          $table_name Base table name.
	 * @param array<int, array<string, string>> $specs      Column specs.
	 * @param array<int, string>              $changes    Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function add_missing_columns(
		string $table_name,
		array $specs,
		array &$changes
	): void {
		$connection = $this->context->get_connection();

		if ( ! $connection->table_exists( $table_name ) ) {
			return;
		}

		foreach ( $specs as $spec ) {
			$column_name = (string) ( $spec['name'] ?? '' );

			if ( '' === $column_name || $connection->column_exists( $table_name, $column_name ) ) {
				continue;
			}

			$this->context->get_pdo()->exec(
				'ALTER TABLE ' . $this->context->get_table_identifier( $table_name ) .
				' ADD COLUMN ' . Database::quote_identifier( $column_name ) . ' ' .
				(string) $spec['definition'],
			);

			$changes[] = sprintf(
				/* translators: 1: column name, 2: prefixed table name. */
				__( 'Added the %1$s column to the %2$s table.', 'peakurl' ),
				$column_name,
				$this->context->get_table_name( $table_name ),
			);
		}
	}

	/**
	 * Add missing indexes to a managed table.
	 *
	 * @param string                          $table_name Base table name.
	 * @param array<int, array<string, string>> $specs      Index specs.
	 * @param array<int, string>              $changes    Applied repair labels.
	 * @return void
	 * @since 1.0.14
	 */
	private function add_missing_indexes(
		string $table_name,
		array $specs,
		array &$changes
	): void {
		$connection = $this->context->get_connection();

		if ( ! $connection->table_exists( $table_name ) ) {
			return;
		}

		foreach ( $specs as $spec ) {
			$index_name = (string) ( $spec['name'] ?? '' );

			if ( '' === $index_name || $connection->index_exists( $table_name, $index_name ) ) {
				continue;
			}

			$index_prefix = 'unique' === (string) ( $spec['type'] ?? 'index' )
				? 'ADD UNIQUE INDEX '
				: 'ADD INDEX ';

			$this->context->get_pdo()->exec(
				'ALTER TABLE ' . $this->context->get_table_identifier( $table_name ) . ' ' .
				$index_prefix .
				Database::quote_identifier( $index_name ) . ' ' .
				(string) $spec['columns'],
			);

			$changes[] = sprintf(
				/* translators: 1: index name, 2: prefixed table name. */
				__( 'Added the %1$s index to the %2$s table.', 'peakurl' ),
				$index_name,
				$this->context->get_table_name( $table_name ),
			);
		}
	}

	/**
	 * Parse the expected ON DELETE rule from a foreign-key definition.
	 *
	 * @param string $definition Raw foreign-key SQL definition.
	 * @return string|null
	 * @since 1.0.14
	 */
	private function get_expected_delete_rule( string $definition ): ?string {
		$matches = array();

		if ( 1 !== preg_match( '/ON DELETE\s+([A-Z ]+)/i', $definition, $matches ) ) {
			return null;
		}

		$normalized_rule = preg_replace(
			'/\s+/',
			' ',
			trim( (string) ( $matches[1] ?? '' ) ),
		);

		return strtoupper(
			is_string( $normalized_rule )
				? $normalized_rule
				: trim( (string) ( $matches[1] ?? '' ) ),
		);
	}
}
