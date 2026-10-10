<?php
/**
 * Database schema upgrade helpers.
 *
 * @package PeakURL\Services\Database
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Database;

use PeakURL\Core\Config\Constants;
use PeakURL\Database\RepairSpecs;
use PeakURL\Database\SchemaSpecs;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;


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
		$this->backfill_installation_metadata( $changes );
	}

	/**
	 * Backfill canonical installation metadata if missing.
	 *
	 * Implements the idempotent backfill rules:
	 * - Case A: Both exist -> preserve both.
	 * - Case B: installed_at exists, installation_id missing -> preserve installed_at, generate UUID.
	 * - Case C: installation_id exists, installed_at missing -> preserve installation_id, set upgrade timestamp.
	 * - Case D: Both missing -> generate UUID, set upgrade timestamp.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.7.0
	 */
	private function backfill_installation_metadata( array &$changes ): void {
		$installed_at    = $this->context->get_option( 'installed_at' );
		$installation_id = $this->context->get_option( 'installation_id' );

		$has_installed_at    = is_string( $installed_at ) && '' !== trim( $installed_at );
		$has_installation_id = is_string( $installation_id ) && '' !== trim( $installation_id );

		// Case A: Both exist — preserve both without writing.
		if ( $has_installed_at && $has_installation_id ) {
			return;
		}

		$migration_timestamp = Date::now();

		// Case B & D: installation_id is missing.
		if ( ! $has_installation_id ) {
			$this->context->update_option( 'installation_id', Str::uuid(), false );
			$changes[] = __( 'Backfilled missing installation ID.', 'peakurl' );
		}

		// Case C & D: installed_at is missing.
		if ( ! $has_installed_at ) {
			$this->context->update_option( 'installed_at', $migration_timestamp, false );
			$changes[] = __( 'Backfilled missing installation timestamp.', 'peakurl' );
		}
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

			if ( 'webhooks' === $table_name ) {
				$this->repair_webhooks( $changes );
			}

			if ( 'cron_jobs' === $table_name ) {
				$this->repair_cron_jobs( $changes );
			}

			$this->add_missing_indexes(
				$table_name,
				$index_specs[ $table_name ] ?? array(),
				$changes,
			);
		}

		$this->drop_redundant_indexes( $changes );
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
					Sql::quote_identifier( 'uniq_api_keys_key_value' ),
				);
			}

			$this->context->get_pdo()->exec(
				'ALTER TABLE ' . $table_name . ' DROP COLUMN ' .
				Sql::quote_identifier( 'key_value' ),
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
				MODIFY COLUMN ' . Sql::quote_identifier( 'key_hash' ) . ' CHAR(64) NOT NULL,
				MODIFY COLUMN ' . Sql::quote_identifier( 'key_prefix' ) . ' VARCHAR(16) NOT NULL,
				MODIFY COLUMN ' . Sql::quote_identifier( 'key_last_four' ) . ' CHAR(4) NOT NULL',
			);
			$changes[] = __( 'Repaired hashed API key column constraints.', 'peakurl' );
		}
	}

	/**
	 * Populate missing webhook labels from endpoint hostnames.
	 *
	 * Reads any webhook rows where label is empty or null,
	 * derives an initial label from the endpoint hostname, and stores it.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.7.1
	 */
	private function repair_webhooks( array &$changes ): void {
		$connection = $this->context->get_connection();

		if ( ! $connection->table_exists( 'webhooks' ) ) {
			return;
		}

		$table_name = $this->context->get_table_identifier( 'webhooks' );
		$pdo        = $this->context->get_pdo();

		$database_name = (string) ( $connection->get_config()[ Constants::DB_DATABASE ] ?? '' );
		$wh_table_name = $this->context->get_table_name( 'webhooks' );
		$id_length     = (int) $this->context->get_var(
			'SELECT character_maximum_length
			FROM information_schema.columns
			WHERE table_schema = :table_schema
			AND table_name = :table_name
			AND column_name = \'id\'
			LIMIT 1',
			array(
				'table_schema' => $database_name,
				'table_name'   => $wh_table_name,
			)
		);

		if ( $id_length > 0 && $id_length < 64 ) {
			$wh_table  = $this->context->get_table_identifier( 'webhooks' );
			$del_table = $this->context->get_table_identifier( 'webhook_deliveries' );

			$pdo->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
			$pdo->exec( 'ALTER TABLE ' . $wh_table . ' MODIFY COLUMN ' . Sql::quote_identifier( 'id' ) . ' VARCHAR(64) NOT NULL' );
			if ( $connection->table_exists( 'webhook_deliveries' ) ) {
				$pdo->exec( 'ALTER TABLE ' . $del_table . ' MODIFY COLUMN ' . Sql::quote_identifier( 'id' ) . ' VARCHAR(64) NOT NULL' );
				$pdo->exec( 'ALTER TABLE ' . $del_table . ' MODIFY COLUMN ' . Sql::quote_identifier( 'webhook_id' ) . ' VARCHAR(64) NOT NULL' );
				$pdo->exec( 'ALTER TABLE ' . $del_table . ' MODIFY COLUMN ' . Sql::quote_identifier( 'event_id' ) . ' VARCHAR(64) NOT NULL DEFAULT \'\'' );
			}
			$pdo->exec( 'SET FOREIGN_KEY_CHECKS = 1' );
			$changes[] = __( 'Widened webhook identifier columns to VARCHAR(64).', 'peakurl' );
		}

		$statement      = $pdo->query(
			'SELECT id, url, label FROM ' . $table_name . " WHERE label IS NULL OR label = ''"
		);
		$unlabeled_rows = $statement ? $statement->fetchAll( \PDO::FETCH_ASSOC ) : array();

		if ( empty( $unlabeled_rows ) ) {
			return;
		}

		$update_statement = $pdo->prepare(
			'UPDATE ' . $table_name . ' SET label = :label WHERE id = :id'
		);

		$in_transaction = $pdo->inTransaction();
		if ( ! $in_transaction ) {
			$pdo->beginTransaction();
		}

		try {
			foreach ( $unlabeled_rows as $row ) {
				$id    = (string) ( $row['id'] ?? '' );
				$url   = (string) ( $row['url'] ?? '' );
				$host  = (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' );
				$label = '' !== trim( $host ) ? trim( $host ) : 'Webhook';

				$update_statement->execute(
					array(
						':label' => $label,
						':id'    => $id,
					)
				);
			}

			if ( ! $in_transaction ) {
				$pdo->commit();
			}

			$changes[] = sprintf(
				/* translators: %s: prefixed table name. */
				__( 'Populated missing labels for existing %s rows.', 'peakurl' ),
				$this->context->get_table_name( 'webhooks' )
			);
		} catch ( \Throwable $exception ) {
			if ( ! $in_transaction && $pdo->inTransaction() ) {
				$pdo->rollBack();
			}

			throw $exception;
		}
	}

	/**
	 * Repair cron_jobs table by purging obsolete scheduled background jobs.
	 *
	 * Purges the legacy 1.7.0 'peakurl_import_export' job identifier which was
	 * superseded in 1.7.1 by 'peakurl_import_export_cleanup'.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.7.1
	 */
	private function repair_cron_jobs( array &$changes ): void {
		$connection = $this->context->get_connection();

		if ( ! $connection->table_exists( 'cron_jobs' ) ) {
			return;
		}

		$table_name = $this->context->get_table_identifier( 'cron_jobs' );
		$pdo        = $this->context->get_pdo();

		$deleted = $pdo->exec(
			'DELETE FROM ' . $table_name . ' WHERE id = \'peakurl_import_export\''
		);

		if ( false !== $deleted && $deleted > 0 ) {
			$changes[] = __( 'Removed obsolete peakurl_import_export background job.', 'peakurl' );
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

		$matching_ids = $this->context->get_prefixed_ids( $table_name, $prefix );

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
				$this->context->row_id_exists( $table_name, $new_id, $old_id )
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
		foreach ( RepairSpecs::cleanup_queries() as $query ) {
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
				$expected_delete_rule = $this->parse_delete_rule(
					(string) $spec['definition'],
				);

				if ( $this->context->constraint_exists( $table_name, $constraint_name ) ) {
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
						Sql::quote_identifier(
							$this->context->get_constraint_name( $constraint_name )
						),
					);
					$this->context->get_pdo()->exec(
						'ALTER TABLE ' . $this->context->get_table_identifier( $table_name ) .
						' ADD CONSTRAINT ' .
						Sql::quote_identifier(
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
					Sql::quote_identifier(
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
				' ADD COLUMN ' . Sql::quote_identifier( $column_name ) . ' ' .
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
				Sql::quote_identifier( $index_name ) . ' ' .
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
	 * Verify that a redundant index has an existing covering replacement before dropping.
	 *
	 * Ensures safe schema convergence across interrupted upgrades and varying prior states.
	 *
	 * @param string     $table_name Base table name.
	 * @param string     $index_name Redundant index name.
	 * @param Connection $connection Database connection instance.
	 * @return bool True if a replacement index is confirmed present.
	 * @since 1.7.2
	 */
	private function is_index_safely_covered( string $table_name, string $index_name, Connection $connection ): bool {
		if ( 'idx_sessions_token_hash' === $index_name ) {
			$database_name = (string) ( $connection->get_config()[ Constants::DB_DATABASE ] ?? '' );
			// Must have a standalone single-column unique index on token_hash.
			$stmt = $this->context->get_pdo()->prepare(
				'SELECT COUNT(*) FROM (
					SELECT index_name
					FROM information_schema.statistics
					WHERE table_schema = :table_schema
					AND table_name = :table_name
					AND non_unique = 0
					GROUP BY index_name
					HAVING COUNT(*) = 1 AND MAX(CASE WHEN column_name = \'token_hash\' AND sub_part IS NULL THEN 1 ELSE 0 END) = 1
				) AS standalone_unique'
			);
			$stmt->execute(
				array(
					'table_schema' => $database_name,
					'table_name'   => $this->context->get_table_name( $table_name ),
				)
			);
			return (int) $stmt->fetchColumn() > 0;
		}

		if ( 'idx_sessions_user_id' === $index_name ) {
			$columns = $this->get_index_columns( $table_name, 'idx_sessions_user_active', $connection );
			return array( 'user_id', 'revoked_at', 'last_active_at' ) === $columns;
		}

		if ( 'idx_urls_user_id' === $index_name || 'idx_urls_user_status' === $index_name ) {
			$columns = $this->get_index_columns( $table_name, 'idx_urls_user_status_created', $connection );
			return array( 'user_id', 'status', 'created_at' ) === $columns;
		}

		if ( 'idx_clicks_url_id' === $index_name ) {
			$columns = $this->get_index_columns( $table_name, 'idx_clicks_url_clicked_at', $connection );
			return array( 'url_id', 'clicked_at' ) === $columns;
		}

		if ( 'idx_webhooks_user_id' === $index_name ) {
			$columns = $this->get_index_columns( $table_name, 'idx_webhooks_user_active', $connection );
			return array( 'user_id', 'is_active' ) === $columns;
		}

		return false;
	}

	/**
	 * Get the ordered list of fully-indexed column names for a specific table index.
	 *
	 * Prefix-indexed columns (where sub_part is not NULL) are rejected because partial column
	 * coverage does not provide full index coverage.
	 *
	 * @param string     $table_name Base table name.
	 * @param string     $index_name Index name.
	 * @param Connection $connection Database connection instance.
	 * @return array<int, string> Ordered column names, or empty if index has partial column prefixes.
	 * @since 1.7.2
	 */
	private function get_index_columns( string $table_name, string $index_name, Connection $connection ): array {
		$database_name = (string) ( $connection->get_config()[ Constants::DB_DATABASE ] ?? '' );
		$stmt          = $this->context->get_pdo()->prepare(
			'SELECT column_name, sub_part
			FROM information_schema.statistics
			WHERE table_schema = :table_schema
			AND table_name = :table_name
			AND index_name = :index_name
			ORDER BY seq_in_index ASC'
		);
		$stmt->execute(
			array(
				'table_schema' => $database_name,
				'table_name'   => $this->context->get_table_name( $table_name ),
				'index_name'   => $index_name,
			)
		);
		$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();
		foreach ( $rows as $row ) {
			$norm_row = array_change_key_case( $row, CASE_LOWER );
			if ( null !== $norm_row['sub_part'] ) {
				return array();
			}
			$columns[] = (string) $norm_row['column_name'];
		}

		return $columns;
	}


	/**
	 * Drop redundant indexes that duplicate unique constraints or composite left prefixes.
	 *
	 * Verified against replacement indexes before executing DROP to prevent leaving
	 * tables without required key coverage or breaking foreign-key cascades.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.7.2
	 */
	private function drop_redundant_indexes( array &$changes ): void {
		$redundant = array(
			'sessions' => array( 'idx_sessions_token_hash', 'idx_sessions_user_id' ),
			'urls'     => array( 'idx_urls_user_id', 'idx_urls_user_status' ),
			'clicks'   => array( 'idx_clicks_url_id' ),
			'webhooks' => array( 'idx_webhooks_user_id' ),
		);

		$connection = $this->context->get_connection();

		foreach ( $redundant as $table_name => $index_names ) {
			if ( ! $connection->table_exists( $table_name ) ) {
				continue;
			}

			foreach ( $index_names as $index_name ) {
				if ( ! $connection->index_exists( $table_name, $index_name ) ) {
					continue;
				}

				if ( ! $this->is_index_safely_covered( $table_name, $index_name, $connection ) ) {
					continue;
				}

				$this->context->get_pdo()->exec(
					'ALTER TABLE ' . $this->context->get_table_identifier( $table_name ) . ' DROP INDEX ' .
					Sql::quote_identifier( $index_name ),
				);
				$changes[] = sprintf(
					/* translators: 1: index name, 2: prefixed table name. */
					__( 'Removed redundant %1$s index from the %2$s table.', 'peakurl' ),
					$index_name,
					$this->context->get_table_name( $table_name ),
				);
			}
		}
	}



	/**
	 * Parse the expected ON DELETE rule from a foreign-key definition.
	 *
	 * @param string $definition Raw foreign-key SQL definition.
	 * @return string|null
	 * @since 1.0.14
	 */
	private function parse_delete_rule( string $definition ): ?string {
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
