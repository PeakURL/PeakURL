<?php
/**
 * PeakURL database schema service.
 *
 * @package PeakURL\Services
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Includes\Connection;
use PeakURL\Includes\Constants;
use PeakURL\Utils\Database;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * DatabaseSchema — versioned schema inspection and upgrade service.
 *
 * Keeps schema reconciliation out of the lower-level connection wrapper so
 * installs, updates, and runtime bootstrap can all use one focused service.
 *
 * @since 1.0.3
 */
class DatabaseSchema {

	/**
	 * PeakURL-managed database tables.
	 *
	 * @var array<int, string>
	 * @since 1.0.3
	 */
	private const MANAGED_TABLES = array(
		'settings',
		'users',
		'api_keys',
		'sessions',
		'urls',
		'clicks',
		'audit_logs',
		'webhooks',
	);

	/**
	 * Shared connection manager.
	 *
	 * @var Connection
	 * @since 1.0.3
	 */
	private Connection $connection;

	/**
	 * Absolute path to the canonical schema.sql file.
	 *
	 * @var string
	 * @since 1.0.3
	 */
	private string $schema_path;

	/**
	 * Create a new database schema service.
	 *
	 * @param Connection  $connection  Shared connection manager.
	 * @param string|null $schema_path Optional schema.sql path override.
	 * @since 1.0.3
	 */
	public function __construct(
		Connection $connection,
		?string $schema_path = null
	) {
		$this->connection  = $connection;
		$this->schema_path = is_string( $schema_path ) && '' !== trim( $schema_path )
			? $schema_path
			: ABSPATH . 'app/database/schema.sql';
	}

	/**
	 * Return the current target schema version for this codebase.
	 *
	 * @return int
	 * @since 1.0.3
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
	 * @since 1.0.3
	 */
	public function is_current(): bool {
		if ( ! $this->connection->has_required_tables() ) {
			return false;
		}

		if ( '' !== trim( (string) $this->get_setting( Constants::SETTING_DB_SCHEMA_LAST_ERROR ) ) ) {
			return false;
		}

		if ( $this->has_pending_fast_path_repairs() ) {
			return false;
		}

		return $this->get_recorded_version() >= $this->get_target_version();
	}

	/**
	 * Ensure the database schema is current for the active codebase.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function ensure_current(): array {
		if ( $this->is_current() ) {
			return $this->build_status_payload(
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
	 * @since 1.0.3
	 */
	public function inspect(): array {
		return $this->build_status_payload(
			$this->collect_issues(),
			array(),
			false,
		);
	}

	/**
	 * Apply the canonical schema file plus idempotent repair steps.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function upgrade(): array {
		$changes = array();

		try {
			$this->ensure_base_schema( $changes );
			$this->repair_settings_table( $changes );
			$this->repair_users_table( $changes );
			$this->repair_api_keys_table( $changes );
			$this->repair_sessions_table( $changes );
			$this->repair_urls_table( $changes );
			$this->repair_clicks_table( $changes );
			$this->repair_audit_logs_table( $changes );
			$this->repair_webhooks_table( $changes );
			$this->repair_legacy_string_ids( $changes );
			$this->cleanup_orphans( $changes );
			$this->repair_foreign_keys( $changes );

			$remaining_issues = $this->collect_issues( false );

			if ( ! empty( $remaining_issues ) ) {
				throw new \RuntimeException(
					__( 'PeakURL could not fully repair the database schema.', 'peakurl' ),
				);
			}

			$upgraded_at = gmdate( 'Y-m-d H:i:s' );
			$this->upsert_setting(
				Constants::SETTING_DB_SCHEMA_VERSION,
				(string) $this->get_target_version(),
				false,
			);
			$this->upsert_setting(
				Constants::SETTING_DB_SCHEMA_LAST_UPGRADED_AT,
				$upgraded_at,
				false,
			);
			$this->delete_setting( Constants::SETTING_DB_SCHEMA_LAST_ERROR );

			$status = $this->inspect();

			$status['upgraded']  = true;
			$status['appliedAt'] = $this->to_iso_or_null( $upgraded_at );
			$status['changes']   = $changes;

			return $status;
		} catch ( \Throwable $exception ) {
			$this->upsert_setting(
				Constants::SETTING_DB_SCHEMA_LAST_ERROR,
				$exception->getMessage(),
				false,
			);

			throw $exception;
		}
	}

	/**
	 * Build a consistent schema status payload.
	 *
	 * @param array<int, array<string, string>> $issues   Current issue list.
	 * @param array<int, string>                $changes  Applied repair labels.
	 * @param bool                              $upgraded Whether this call upgraded the schema.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_status_payload(
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

		return array(
			'currentVersion'  => $this->get_recorded_version(),
			'targetVersion'   => $this->get_target_version(),
			'compatible'      => 0 === $error_count,
			'upgradeRequired' => $error_count > 0 || $warning_count > 0,
			'upToDate'        => 0 === $error_count &&
				0 === $warning_count &&
				$this->get_recorded_version() >= $this->get_target_version(),
			'errorCount'      => $error_count,
			'warningCount'    => $warning_count,
			'issues'          => array_values( $issues ),
			'issuesCount'     => count( $issues ),
			'missingTables'   => $this->missing_tables(),
			'lastUpgradedAt'  => $this->to_iso_or_null(
				$this->get_setting( Constants::SETTING_DB_SCHEMA_LAST_UPGRADED_AT )
			),
			'lastError'       => $this->get_setting(
				Constants::SETTING_DB_SCHEMA_LAST_ERROR
			),
			'upgraded'        => $upgraded,
			'changes'         => array_values( $changes ),
		);
	}

	/**
	 * Create the base tables from schema.sql when needed.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function ensure_base_schema( array &$changes ): void {
		$missing_tables = $this->missing_tables();
		$schema         = file_get_contents( $this->schema_path );

		if ( false === $schema ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the bundled database schema file.', 'peakurl' ),
			);
		}

		$this->connection->get_connection()->exec(
			$this->connection->prefix_schema( $schema ),
		);

		if ( ! empty( $missing_tables ) ) {
			$changes[] = sprintf(
				/* translators: %s is a comma-separated table list. */
				__( 'Created missing tables: %s.', 'peakurl' ),
				implode(
					', ',
					array_map(
						fn( string $table_name ): string => $this->table_label( $table_name ),
						$missing_tables,
					),
				),
			);
		}
	}

	/**
	 * Repair the settings table additions used by the current runtime.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_settings_table( array &$changes ): void {
		$this->ensure_columns(
			'settings',
			array(
				array(
					'name'       => 'autoload',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 1',
				),
				array(
					'name'       => 'updated_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			$changes,
		);
		$this->ensure_indexes(
			'settings',
			array(
				array(
					'name'    => 'idx_settings_autoload',
					'type'    => 'index',
					'columns' => '(autoload)',
				),
			),
			$changes,
		);
	}

	/**
	 * Repair additive users-table fields and indexes.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_users_table( array &$changes ): void {
		$this->ensure_columns(
			'users',
			array(
				array(
					'name'       => 'phone_number',
					'definition' => 'VARCHAR(40) DEFAULT NULL',
				),
				array(
					'name'       => 'company',
					'definition' => 'VARCHAR(190) DEFAULT NULL',
				),
				array(
					'name'       => 'job_title',
					'definition' => 'VARCHAR(190) DEFAULT NULL',
				),
				array(
					'name'       => 'bio',
					'definition' => 'TEXT DEFAULT NULL',
				),
				array(
					'name'       => 'role',
					'definition' => "VARCHAR(32) NOT NULL DEFAULT 'editor'",
				),
				array(
					'name'       => 'is_email_verified',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 0',
				),
				array(
					'name'       => 'email_verified_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'email_verification_token',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'email_verification_expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'password_reset_token',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'password_reset_expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'two_factor_enabled',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 0',
				),
				array(
					'name'       => 'two_factor_secret',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'two_factor_pending_secret',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'backup_codes_json',
					'definition' => 'LONGTEXT DEFAULT NULL',
				),
				array(
					'name'       => 'backup_codes_generated_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'last_login_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			$changes,
		);
		$this->ensure_indexes(
			'users',
			array(
				array(
					'name'    => 'idx_users_role',
					'type'    => 'index',
					'columns' => '(role)',
				),
			),
			$changes,
		);
	}

	/**
	 * Repair the api_keys table, including the legacy key_value migration.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_api_keys_table( array &$changes ): void {
		$this->ensure_columns(
			'api_keys',
			array(
				array(
					'name'       => 'key_hash',
					'definition' => 'CHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'key_prefix',
					'definition' => 'VARCHAR(16) DEFAULT NULL',
				),
				array(
					'name'       => 'key_last_four',
					'definition' => 'CHAR(4) DEFAULT NULL',
				),
				array(
					'name'       => 'created_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			$changes,
		);

		$this->repair_legacy_api_keys( $changes );

		$this->ensure_indexes(
			'api_keys',
			array(
				array(
					'name'    => 'uniq_api_keys_key_hash',
					'type'    => 'unique',
					'columns' => '(key_hash)',
				),
				array(
					'name'    => 'idx_api_keys_user_created_at',
					'type'    => 'index',
					'columns' => '(user_id, created_at)',
				),
			),
			$changes,
		);
	}

	/**
	 * Repair additive sessions-table fields and indexes.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_sessions_table( array &$changes ): void {
		$this->ensure_columns(
			'sessions',
			array(
				array(
					'name'       => 'browser',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'operating_system',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'device',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'revoked_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'revoked_reason',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
			),
			$changes,
		);
		$this->ensure_indexes(
			'sessions',
			array(
				array(
					'name'    => 'idx_sessions_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_sessions_token_hash',
					'type'    => 'index',
					'columns' => '(token_hash)',
				),
				array(
					'name'    => 'idx_sessions_user_active',
					'type'    => 'index',
					'columns' => '(user_id, revoked_at, last_active_at)',
				),
			),
			$changes,
		);
	}

	/**
	 * Repair additive urls-table fields and indexes.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_urls_table( array &$changes ): void {
		$this->ensure_columns(
			'urls',
			array(
				array(
					'name'       => 'title',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'password_value',
					'definition' => 'VARCHAR(255) DEFAULT NULL',
				),
				array(
					'name'       => 'expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'status',
					'definition' => "VARCHAR(32) NOT NULL DEFAULT 'active'",
				),
				array(
					'name'       => 'utm_source',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_medium',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_campaign',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_term',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_content',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
			),
			$changes,
		);
		$this->ensure_indexes(
			'urls',
			array(
				array(
					'name'    => 'idx_urls_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_urls_user_status',
					'type'    => 'index',
					'columns' => '(user_id, status)',
				),
				array(
					'name'    => 'idx_urls_status',
					'type'    => 'index',
					'columns' => '(status)',
				),
				array(
					'name'    => 'idx_urls_created_at',
					'type'    => 'index',
					'columns' => '(created_at)',
				),
			),
			$changes,
		);
	}

	/**
	 * Repair additive clicks-table fields and indexes.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_clicks_table( array &$changes ): void {
		$this->ensure_columns(
			'clicks',
			array(
				array(
					'name'       => 'visitor_hash',
					'definition' => 'CHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'country_code',
					'definition' => 'VARCHAR(8) DEFAULT NULL',
				),
				array(
					'name'       => 'country_name',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'city_name',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'device',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'browser',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'operating_system',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_name',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_domain',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_category',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_source',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_medium',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_campaign',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_term',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_content',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'user_agent',
					'definition' => 'TEXT DEFAULT NULL',
				),
			),
			$changes,
		);
		$this->ensure_indexes(
			'clicks',
			array(
				array(
					'name'    => 'idx_clicks_url_id',
					'type'    => 'index',
					'columns' => '(url_id)',
				),
				array(
					'name'    => 'idx_clicks_clicked_at',
					'type'    => 'index',
					'columns' => '(clicked_at)',
				),
				array(
					'name'    => 'idx_clicks_url_clicked_at',
					'type'    => 'index',
					'columns' => '(url_id, clicked_at)',
				),
			),
			$changes,
		);
	}

	/**
	 * Repair additive audit_logs-table fields and indexes.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_audit_logs_table( array &$changes ): void {
		$this->ensure_columns(
			'audit_logs',
			array(
				array(
					'name'       => 'link_id',
					'definition' => 'VARCHAR(40) DEFAULT NULL',
				),
				array(
					'name'       => 'metadata',
					'definition' => 'LONGTEXT DEFAULT NULL',
				),
			),
			$changes,
		);
		$this->ensure_indexes(
			'audit_logs',
			array(
				array(
					'name'    => 'idx_audit_logs_created_at',
					'type'    => 'index',
					'columns' => '(created_at)',
				),
				array(
					'name'    => 'idx_audit_logs_user_created_at',
					'type'    => 'index',
					'columns' => '(user_id, created_at)',
				),
				array(
					'name'    => 'idx_audit_logs_link_id',
					'type'    => 'index',
					'columns' => '(link_id)',
				),
			),
			$changes,
		);
	}

	/**
	 * Repair additive webhooks-table fields and indexes.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_webhooks_table( array &$changes ): void {
		$this->ensure_columns(
			'webhooks',
			array(
				array(
					'name'       => 'is_active',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 1',
				),
			),
			$changes,
		);
		$this->ensure_indexes(
			'webhooks',
			array(
				array(
					'name'    => 'idx_webhooks_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_webhooks_user_active',
					'type'    => 'index',
					'columns' => '(user_id, is_active)',
				),
			),
			$changes,
		);
	}

	/**
	 * Normalize legacy prefixed string IDs to the unprefixed format.
	 *
	 * Click and webhook rows historically stored IDs like `click_<hex>` and
	 * `webhook_<hex>`. PeakURL now uses plain opaque random IDs for all string
	 * primary keys, so existing installs are repaired into that same format.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_legacy_string_ids( array &$changes ): void {
		$this->repair_legacy_prefixed_ids_for_table(
			'clicks',
			'click_',
			__( 'Normalized legacy click IDs to the unprefixed format.', 'peakurl' ),
			$changes,
		);
		$this->repair_legacy_prefixed_ids_for_table(
			'webhooks',
			'webhook_',
			__( 'Normalized legacy webhook IDs to the unprefixed format.', 'peakurl' ),
			$changes,
		);
	}

	/**
	 * Normalize prefixed row IDs for a specific table.
	 *
	 * @param string              $table_name Base table name.
	 * @param string              $prefix     Legacy ID prefix including underscore.
	 * @param string              $label      Human-readable repair label.
	 * @param array<int, string> &$changes    Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_legacy_prefixed_ids_for_table(
		string $table_name,
		string $prefix,
		string $label,
		array &$changes
	): void {
		if ( ! $this->connection->table_exists( $table_name ) ) {
			return;
		}

		$legacy_ids = $this->legacy_prefixed_ids_for_table( $table_name, $prefix );

		if ( empty( $legacy_ids ) ) {
			return;
		}

		$table_identifier = $this->table_identifier( $table_name );
		$statement        = $this->connection->get_connection()->prepare(
			'UPDATE ' . $table_identifier . '
			SET id = :new_id
			WHERE id = :old_id'
		);
		$updated_count    = 0;

		foreach ( $legacy_ids as $legacy_id ) {
			$new_id = substr( $legacy_id, strlen( $prefix ) );

			if (
				'' === $new_id ||
				$this->table_row_id_exists( $table_name, $new_id, $legacy_id )
			) {
				$new_id = $this->generate_unique_table_row_id( $table_name, $legacy_id );
			}

			if ( '' === $new_id || $new_id === $legacy_id ) {
				continue;
			}

			$statement->execute(
				array(
					'new_id' => $new_id,
					'old_id' => $legacy_id,
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
	 * Migrate legacy api_keys data and remove obsolete artifacts.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_legacy_api_keys( array &$changes ): void {
		if ( ! $this->connection->table_exists( 'api_keys' ) ) {
			return;
		}

		$table_name = $this->table_identifier( 'api_keys' );

		if ( $this->connection->column_exists( 'api_keys', 'key_value' ) ) {
			$this->connection->get_connection()->exec(
				'UPDATE ' . $table_name . '
				SET key_hash = COALESCE(NULLIF(key_hash, \'\'), SHA2(key_value, 256)),
					key_prefix = COALESCE(NULLIF(key_prefix, \'\'), LEFT(key_value, 16)),
					key_last_four = COALESCE(NULLIF(key_last_four, \'\'), RIGHT(key_value, 4))
				WHERE key_value IS NOT NULL
				AND key_value <> \'\'',
			);

			if ( $this->connection->index_exists( 'api_keys', 'uniq_api_keys_key_value' ) ) {
				$this->connection->get_connection()->exec(
					'ALTER TABLE ' . $table_name . ' DROP INDEX ' .
					Database::quote_identifier( 'uniq_api_keys_key_value' ),
				);
			}

			$this->connection->get_connection()->exec(
				'ALTER TABLE ' . $table_name . ' DROP COLUMN ' .
				Database::quote_identifier( 'key_value' ),
			);

			$changes[] = __( 'Migrated legacy API keys to the hashed-key schema.', 'peakurl' );
		}

		$missing_values = (int) $this->query_value(
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
				__( 'PeakURL found API keys that could not be migrated to the hashed-key schema.', 'peakurl' ),
			);
		}

		if (
			$this->connection->column_allows_null( 'api_keys', 'key_hash' ) ||
			$this->connection->column_allows_null( 'api_keys', 'key_prefix' ) ||
			$this->connection->column_allows_null( 'api_keys', 'key_last_four' )
		) {
			$this->connection->get_connection()->exec(
				'ALTER TABLE ' . $table_name . '
				MODIFY COLUMN ' . Database::quote_identifier( 'key_hash' ) . ' CHAR(64) NOT NULL,
				MODIFY COLUMN ' . Database::quote_identifier( 'key_prefix' ) . ' VARCHAR(16) NOT NULL,
				MODIFY COLUMN ' . Database::quote_identifier( 'key_last_four' ) . ' CHAR(4) NOT NULL',
			);
			$changes[] = __( 'Repaired hashed API key column constraints.', 'peakurl' );
		}
	}

	/**
	 * Remove orphaned rows that block foreign key creation.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function cleanup_orphans( array &$changes ): void {
		$cleanup_queries = array(
			array(
				'label' => __( 'Removed orphaned API keys.', 'peakurl' ),
				'sql'   => 'DELETE ak
					FROM api_keys AS ak
					LEFT JOIN users AS u ON ak.user_id = u.id
					WHERE u.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned sessions.', 'peakurl' ),
				'sql'   => 'DELETE s
					FROM sessions AS s
					LEFT JOIN users AS u ON s.user_id = u.id
					WHERE u.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned URLs.', 'peakurl' ),
				'sql'   => 'DELETE url
					FROM urls AS url
					LEFT JOIN users AS u ON url.user_id = u.id
					WHERE u.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned clicks.', 'peakurl' ),
				'sql'   => 'DELETE c
					FROM clicks AS c
					LEFT JOIN urls AS url ON c.url_id = url.id
					WHERE url.id IS NULL',
			),
			array(
				'label' => __( 'Cleared orphaned audit-log users.', 'peakurl' ),
				'sql'   => 'UPDATE audit_logs AS al
					LEFT JOIN users AS u ON al.user_id = u.id
					SET al.user_id = NULL
					WHERE al.user_id IS NOT NULL
					AND u.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned audit-log links.', 'peakurl' ),
				'sql'   => 'DELETE al
					FROM audit_logs AS al
					LEFT JOIN urls AS url ON al.link_id = url.id
					WHERE al.link_id IS NOT NULL
					AND url.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned webhooks.', 'peakurl' ),
				'sql'   => 'DELETE w
					FROM webhooks AS w
					LEFT JOIN users AS u ON w.user_id = u.id
					WHERE u.id IS NULL',
			),
		);

		foreach ( $cleanup_queries as $query ) {
			$affected_rows = (int) $this->connection->get_connection()->exec(
				$this->connection->prefix_sql( (string) $query['sql'] ),
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
	 * Return the current legacy prefixed IDs for a managed table.
	 *
	 * @param string $table_name Base table name.
	 * @param string $prefix     Legacy ID prefix including underscore.
	 * @return array<int, string>
	 * @since 1.0.3
	 */
	private function legacy_prefixed_ids_for_table(
		string $table_name,
		string $prefix
	): array {
		if ( ! $this->connection->table_exists( $table_name ) ) {
			return array();
		}

		$table_identifier = $this->table_identifier( $table_name );
		$statement        = $this->connection->get_connection()->prepare(
			'SELECT id
			FROM ' . $table_identifier . '
			WHERE id LIKE :legacy_pattern ESCAPE \'\\\\\'
			ORDER BY id ASC'
		);
		$statement->execute(
			array(
				'legacy_pattern' => $this->escape_like_pattern( $prefix ) . '%',
			),
		);
		$results = $statement->fetchAll( \PDO::FETCH_COLUMN );

		return array_map(
			static fn( $value ): string => (string) $value,
			is_array( $results ) ? $results : array(),
		);
	}

	/**
	 * Determine whether a table row ID already exists.
	 *
	 * @param string      $table_name Base table name.
	 * @param string      $id         Candidate row ID.
	 * @param string|null $exclude_id Existing row ID to exclude.
	 * @return bool
	 * @since 1.0.3
	 */
	private function table_row_id_exists(
		string $table_name,
		string $id,
		?string $exclude_id = null
	): bool {
		$table_identifier = $this->table_identifier( $table_name );
		$sql              = 'SELECT 1
			FROM ' . $table_identifier . '
			WHERE id = :id';
		$params           = array(
			'id' => $id,
		);

		if ( null !== $exclude_id ) {
			$sql                 .= ' AND id <> :exclude_id';
			$params['exclude_id'] = $exclude_id;
		}

		$sql .= ' LIMIT 1';

		return false !== $this->query_value( $sql, $params );
	}

	/**
	 * Generate a unique unprefixed row ID for a managed table.
	 *
	 * @param string      $table_name Base table name.
	 * @param string|null $exclude_id Existing row ID to exclude.
	 * @return string
	 * @since 1.0.3
	 */
	private function generate_unique_table_row_id(
		string $table_name,
		?string $exclude_id = null
	): string {
		do {
			$id = bin2hex( random_bytes( 10 ) );
		} while ( $this->table_row_id_exists( $table_name, $id, $exclude_id ) );

		return $id;
	}

	/**
	 * Escape a SQL LIKE pattern for use with ESCAPE '\\'.
	 *
	 * @param string $value Raw pattern value.
	 * @return string
	 * @since 1.0.3
	 */
	private function escape_like_pattern( string $value ): string {
		return strtr(
			$value,
			array(
				'\\' => '\\\\',
				'%'  => '\%',
				'_'  => '\_',
			),
		);
	}

	/**
	 * Repair missing foreign keys after orphan cleanup.
	 *
	 * @param array<int, string> $changes Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function repair_foreign_keys( array &$changes ): void {
		foreach ( $this->foreign_key_specs() as $table_name => $specs ) {
			foreach ( $specs as $spec ) {
				$constraint_name      = (string) $spec['name'];
				$expected_delete_rule = $this->expected_delete_rule_from_definition(
					(string) $spec['definition'],
				);

				if ( $this->constraint_exists( $table_name, $constraint_name ) ) {
					$current_delete_rule = $this->constraint_delete_rule(
						$table_name,
						$constraint_name,
					);

					if (
						null === $expected_delete_rule ||
						$expected_delete_rule === $current_delete_rule
					) {
						continue;
					}

					$this->connection->get_connection()->exec(
						'ALTER TABLE ' . $this->table_identifier( $table_name ) .
						' DROP FOREIGN KEY ' .
						Database::quote_identifier(
							$this->prefixed_constraint_name( $constraint_name )
						),
					);
					$this->connection->get_connection()->exec(
						'ALTER TABLE ' . $this->table_identifier( $table_name ) .
						' ADD CONSTRAINT ' .
						Database::quote_identifier(
							$this->prefixed_constraint_name( $constraint_name )
						) .
						' ' .
						$this->connection->prefix_sql( (string) $spec['definition'] ),
					);

					$changes[] = sprintf(
						/* translators: 1: prefixed table name, 2: foreign key name. */
						__( 'Updated the %2$s foreign key on the %1$s table.', 'peakurl' ),
						$this->table_label( $table_name ),
						$this->prefixed_constraint_name( $constraint_name ),
					);

					continue;
				}

				$this->connection->get_connection()->exec(
					'ALTER TABLE ' . $this->table_identifier( $table_name ) .
					' ADD CONSTRAINT ' .
					Database::quote_identifier(
						$this->prefixed_constraint_name( $constraint_name )
					) .
					' ' .
					$this->connection->prefix_sql( (string) $spec['definition'] ),
				);

				$changes[] = sprintf(
					/* translators: 1: prefixed table name, 2: foreign key name. */
					__( 'Added the %2$s foreign key to the %1$s table.', 'peakurl' ),
					$this->table_label( $table_name ),
					$this->prefixed_constraint_name( $constraint_name ),
				);
			}
		}
	}

	/**
	 * Add missing columns to a managed table.
	 *
	 * @param string                     $table_name Base table name.
	 * @param array<int, array<string, string>> $specs     Column specs.
	 * @param array<int, string>         $changes    Applied repair labels.
	 * @return void
	 * @since 1.0.3
	 */
	private function ensure_columns(
		string $table_name,
		array $specs,
		array &$changes
	): void {
		if ( ! $this->connection->table_exists( $table_name ) ) {
			return;
		}

		foreach ( $specs as $spec ) {
			$column_name = (string) ( $spec['name'] ?? '' );

			if ( '' === $column_name || $this->connection->column_exists( $table_name, $column_name ) ) {
				continue;
			}

			$this->connection->get_connection()->exec(
				'ALTER TABLE ' . $this->table_identifier( $table_name ) .
				' ADD COLUMN ' . Database::quote_identifier( $column_name ) . ' ' .
				(string) $spec['definition'],
			);

			$changes[] = sprintf(
				/* translators: 1: column name, 2: prefixed table name. */
				__( 'Added the %1$s column to the %2$s table.', 'peakurl' ),
				$column_name,
				$this->table_label( $table_name ),
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
	 * @since 1.0.3
	 */
	private function ensure_indexes(
		string $table_name,
		array $specs,
		array &$changes
	): void {
		if ( ! $this->connection->table_exists( $table_name ) ) {
			return;
		}

		foreach ( $specs as $spec ) {
			$index_name = (string) ( $spec['name'] ?? '' );

			if ( '' === $index_name || $this->connection->index_exists( $table_name, $index_name ) ) {
				continue;
			}

			$prefix = 'unique' === (string) ( $spec['type'] ?? 'index' )
				? 'ADD UNIQUE INDEX '
				: 'ADD INDEX ';

			$this->connection->get_connection()->exec(
				'ALTER TABLE ' . $this->table_identifier( $table_name ) . ' ' .
				$prefix .
				Database::quote_identifier( $index_name ) . ' ' .
				(string) $spec['columns'],
			);

			$changes[] = sprintf(
				/* translators: 1: index name, 2: prefixed table name. */
				__( 'Added the %1$s index to the %2$s table.', 'peakurl' ),
				$index_name,
				$this->table_label( $table_name ),
			);
		}
	}

	/**
	 * Return a prefixed table identifier for direct DDL usage.
	 *
	 * @param string $table_name Base table name.
	 * @return string
	 * @since 1.0.3
	 */
	private function table_identifier( string $table_name ): string {
		return Database::quote_identifier( $this->connection->table_name( $table_name ) );
	}

	/**
	 * Return the human-readable label for a managed table.
	 *
	 * @param string $table_name Base table name.
	 * @return string
	 * @since 1.0.3
	 */
	private function table_label( string $table_name ): string {
		return $this->connection->table_name( $table_name );
	}

	/**
	 * Return the current recorded schema version from settings.
	 *
	 * @return int
	 * @since 1.0.3
	 */
	private function get_recorded_version(): int {
		$value = $this->get_setting( Constants::SETTING_DB_SCHEMA_VERSION );

		return is_string( $value ) && ctype_digit( $value )
			? (int) $value
			: 0;
	}

	/**
	 * Read a settings value directly from the settings table.
	 *
	 * @param string $setting_key Settings key.
	 * @return string|null
	 * @since 1.0.3
	 */
	private function get_setting( string $setting_key ): ?string {
		return $this->connection->get_setting_value( $setting_key );
	}

	/**
	 * Insert or update a setting row directly.
	 *
	 * @param string $setting_key   Settings key.
	 * @param string $setting_value Settings value.
	 * @param bool   $autoload      Whether the row should autoload.
	 * @return void
	 * @since 1.0.3
	 */
	private function upsert_setting(
		string $setting_key,
		string $setting_value,
		bool $autoload = false
	): void {
		if ( ! $this->connection->table_exists( 'settings' ) ) {
			return;
		}

		$statement = $this->connection->prepare(
			'INSERT INTO settings (setting_key, setting_value, autoload, updated_at)
			VALUES (:setting_key, :setting_value, :autoload, :updated_at)
			ON DUPLICATE KEY UPDATE
				setting_value = VALUES(setting_value),
				autoload = VALUES(autoload),
				updated_at = VALUES(updated_at)'
		);
		$statement->execute(
			array(
				'setting_key'   => $setting_key,
				'setting_value' => $setting_value,
				'autoload'      => $autoload ? 1 : 0,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
		);
	}

	/**
	 * Delete a single settings row when present.
	 *
	 * @param string $setting_key Settings key.
	 * @return void
	 * @since 1.0.3
	 */
	private function delete_setting( string $setting_key ): void {
		if ( ! $this->connection->table_exists( 'settings' ) ) {
			return;
		}

		$statement = $this->connection->prepare(
			'DELETE FROM settings WHERE setting_key = :setting_key'
		);
		$statement->execute( array( 'setting_key' => $setting_key ) );
	}

	/**
	 * Query a scalar value with optional parameters.
	 *
	 * @param string               $sql    SQL query string.
	 * @param array<string, mixed> $params Bound parameters.
	 * @return mixed
	 * @since 1.0.3
	 */
	private function query_value( string $sql, array $params = array() ): mixed {
		$statement = $this->connection->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchColumn();
	}

	/**
	 * Build a compact schema issue payload.
	 *
	 * @param string $id       Stable issue identifier.
	 * @param string $severity `error` or `warning`.
	 * @param string $label    Human-readable issue label.
	 * @return array<string, string>
	 * @since 1.0.3
	 */
	private function build_issue(
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
	 * has not changed, but legacy data still needs to be normalized.
	 *
	 * @return bool
	 * @since 1.0.3
	 */
	private function has_pending_fast_path_repairs(): bool {
		if (
			$this->connection->table_exists( 'api_keys' ) &&
			$this->connection->column_exists( 'api_keys', 'key_value' )
		) {
			return true;
		}

		if (
			$this->connection->table_exists( 'api_keys' ) &&
			(
				$this->connection->column_allows_null( 'api_keys', 'key_hash' ) ||
				$this->connection->column_allows_null( 'api_keys', 'key_prefix' ) ||
				$this->connection->column_allows_null( 'api_keys', 'key_last_four' )
			)
		) {
			return true;
		}

		if ( ! empty( $this->legacy_prefixed_ids_for_table( 'clicks', 'click_' ) ) ) {
			return true;
		}

		if ( ! empty( $this->legacy_prefixed_ids_for_table( 'webhooks', 'webhook_' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Return the list of currently-missing managed tables.
	 *
	 * @return array<int, string>
	 * @since 1.0.3
	 */
	private function missing_tables(): array {
		$missing = array();

		foreach ( self::MANAGED_TABLES as $table_name ) {
			if ( ! $this->connection->table_exists( $table_name ) ) {
				$missing[] = $table_name;
			}
		}

		return $missing;
	}

	/**
	 * Collect current schema issues with optional version mismatch reporting.
	 *
	 * @param bool $include_version_issue Whether to include the schema-version issue.
	 * @return array<int, array<string, string>>
	 * @since 1.0.3
	 */
	private function collect_issues( bool $include_version_issue = true ): array {
		$issues = array();

		foreach ( self::MANAGED_TABLES as $table_name ) {
			if ( ! $this->connection->table_exists( $table_name ) ) {
				$issues[] = $this->build_issue(
					'missing-table-' . $table_name,
					'error',
					sprintf(
						/* translators: %s is the prefixed database table name. */
						__( 'The %s table is missing.', 'peakurl' ),
						$this->table_label( $table_name ),
					),
				);
				continue;
			}

			foreach ( $this->missing_columns_for_table( $table_name ) as $column_name ) {
				$issues[] = $this->build_issue(
					'missing-column-' . $table_name . '-' . $column_name,
					'error',
					sprintf(
						/* translators: 1: prefixed table name, 2: column name. */
						__( 'The %1$s table is missing the %2$s column.', 'peakurl' ),
						$this->table_label( $table_name ),
						$column_name,
					),
				);
			}

			foreach ( $this->missing_indexes_for_table( $table_name ) as $index_name ) {
				$issues[] = $this->build_issue(
					'missing-index-' . $table_name . '-' . $index_name,
					'warning',
					sprintf(
						/* translators: 1: prefixed table name, 2: index name. */
						__( 'The %1$s table is missing the %2$s index.', 'peakurl' ),
						$this->table_label( $table_name ),
						$index_name,
					),
				);
			}

			foreach ( $this->missing_foreign_keys_for_table( $table_name ) as $constraint_name ) {
				$issues[] = $this->build_issue(
					'missing-foreign-key-' . $table_name . '-' . $constraint_name,
					'warning',
					sprintf(
						/* translators: 1: prefixed table name, 2: foreign key name. */
						__( 'The %1$s table is missing the %2$s foreign key.', 'peakurl' ),
						$this->table_label( $table_name ),
						$this->prefixed_constraint_name( $constraint_name ),
					),
				);
			}
		}

		if (
			$this->connection->table_exists( 'api_keys' ) &&
			$this->connection->column_exists( 'api_keys', 'key_value' )
		) {
			$issues[] = $this->build_issue(
				'legacy-api-keys-key-value',
				'error',
				__( 'The legacy api_keys.key_value column still needs to be migrated.', 'peakurl' ),
			);
		}

		if (
			$this->connection->table_exists( 'api_keys' ) &&
			(
				$this->connection->column_allows_null( 'api_keys', 'key_hash' ) ||
				$this->connection->column_allows_null( 'api_keys', 'key_prefix' ) ||
				$this->connection->column_allows_null( 'api_keys', 'key_last_four' )
			)
		) {
			$issues[] = $this->build_issue(
				'nullable-api-key-hash-columns',
				'error',
				__( 'The hashed API key columns still allow NULL values and need to be repaired.', 'peakurl' ),
			);
		}

		if ( ! empty( $this->legacy_prefixed_ids_for_table( 'clicks', 'click_' ) ) ) {
			$issues[] = $this->build_issue(
				'legacy-click-ids',
				'warning',
				__( 'The clicks table still contains legacy prefixed IDs and should be normalized.', 'peakurl' ),
			);
		}

		if ( ! empty( $this->legacy_prefixed_ids_for_table( 'webhooks', 'webhook_' ) ) ) {
			$issues[] = $this->build_issue(
				'legacy-webhook-ids',
				'warning',
				__( 'The webhooks table still contains legacy prefixed IDs and should be normalized.', 'peakurl' ),
			);
		}

		if ( $include_version_issue ) {
			$current_version = $this->get_recorded_version();
			$target_version  = $this->get_target_version();

			if ( $current_version < $target_version ) {
				$issues[] = $this->build_issue(
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
	 * Return the missing columns for a specific managed table.
	 *
	 * @param string $table_name Base table name.
	 * @return array<int, string>
	 * @since 1.0.3
	 */
	private function missing_columns_for_table( string $table_name ): array {
		$missing = array();

		foreach ( $this->column_specs()[ $table_name ] ?? array() as $spec ) {
			$column_name = (string) ( $spec['name'] ?? '' );

			if ( '' === $column_name || $this->connection->column_exists( $table_name, $column_name ) ) {
				continue;
			}

			$missing[] = $column_name;
		}

		return $missing;
	}

	/**
	 * Return the missing indexes for a specific managed table.
	 *
	 * @param string $table_name Base table name.
	 * @return array<int, string>
	 * @since 1.0.3
	 */
	private function missing_indexes_for_table( string $table_name ): array {
		$missing = array();

		foreach ( $this->index_specs()[ $table_name ] ?? array() as $spec ) {
			$index_name = (string) ( $spec['name'] ?? '' );

			if ( '' === $index_name || $this->connection->index_exists( $table_name, $index_name ) ) {
				continue;
			}

			$missing[] = $index_name;
		}

		return $missing;
	}

	/**
	 * Return the missing foreign keys for a specific managed table.
	 *
	 * @param string $table_name Base table name.
	 * @return array<int, string>
	 * @since 1.0.3
	 */
	private function missing_foreign_keys_for_table( string $table_name ): array {
		$missing = array();

		foreach ( $this->foreign_key_specs()[ $table_name ] ?? array() as $spec ) {
			$constraint_name = (string) ( $spec['name'] ?? '' );

			if ( '' === $constraint_name || $this->constraint_exists( $table_name, $constraint_name ) ) {
				continue;
			}

			$missing[] = $constraint_name;
		}

		return $missing;
	}

	/**
	 * Determine whether a specific foreign key constraint exists.
	 *
	 * @param string $table_name       Base table name.
	 * @param string $constraint_name  Un-prefixed constraint name.
	 * @return bool
	 * @since 1.0.3
	 */
	private function constraint_exists(
		string $table_name,
		string $constraint_name
	): bool {
		return (int) $this->query_value(
			'SELECT COUNT(*)
			FROM information_schema.table_constraints
			WHERE constraint_schema = :table_schema
			AND table_name = :table_name
			AND constraint_name = :constraint_name',
			array(
				'table_schema'    => (string) $this->connection->get_config()['DB_DATABASE'],
				'table_name'      => $this->connection->table_name( $table_name ),
				'constraint_name' => $this->prefixed_constraint_name( $constraint_name ),
			),
		) > 0;
	}

	/**
	 * Return the current ON DELETE rule for a foreign key when available.
	 *
	 * @param string $table_name      Base table name.
	 * @param string $constraint_name Un-prefixed constraint name.
	 * @return string|null
	 * @since 1.0.4
	 */
	private function constraint_delete_rule(
		string $table_name,
		string $constraint_name
	): ?string {
		$rule = $this->query_value(
			'SELECT delete_rule
			FROM information_schema.referential_constraints
			WHERE constraint_schema = :table_schema
			AND table_name = :table_name
			AND constraint_name = :constraint_name
			LIMIT 1',
			array(
				'table_schema'    => (string) $this->connection->get_config()['DB_DATABASE'],
				'table_name'      => $this->connection->table_name( $table_name ),
				'constraint_name' => $this->prefixed_constraint_name( $constraint_name ),
			),
		);

		if ( ! is_string( $rule ) || '' === trim( $rule ) ) {
			return null;
		}

		$normalized_rule = preg_replace( '/\s+/', ' ', trim( $rule ) );

		return strtoupper(
			is_string( $normalized_rule ) ? $normalized_rule : trim( $rule ),
		);
	}

	/**
	 * Parse the expected ON DELETE rule from a foreign-key definition.
	 *
	 * @param string $definition Raw foreign-key SQL definition.
	 * @return string|null
	 * @since 1.0.4
	 */
	private function expected_delete_rule_from_definition( string $definition ): ?string {
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

	/**
	 * Prefix a foreign key constraint name like schema.sql does.
	 *
	 * @param string $constraint_name Un-prefixed constraint name.
	 * @return string
	 * @since 1.0.3
	 */
	private function prefixed_constraint_name( string $constraint_name ): string {
		return $this->connection->get_table_prefix() . $constraint_name;
	}

	/**
	 * Convert a MySQL datetime string to an ISO 8601 string when valid.
	 *
	 * @param string|null $value Raw MySQL datetime string.
	 * @return string|null
	 * @since 1.0.3
	 */
	private function to_iso_or_null( ?string $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( DATE_ATOM, $timestamp );
	}

	/**
	 * Column repair specs keyed by table name.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 * @since 1.0.3
	 */
	private function column_specs(): array {
		return array(
			'settings'   => array(
				array(
					'name'       => 'autoload',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 1',
				),
				array(
					'name'       => 'updated_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			'users'      => array(
				array(
					'name'       => 'phone_number',
					'definition' => 'VARCHAR(40) DEFAULT NULL',
				),
				array(
					'name'       => 'company',
					'definition' => 'VARCHAR(190) DEFAULT NULL',
				),
				array(
					'name'       => 'job_title',
					'definition' => 'VARCHAR(190) DEFAULT NULL',
				),
				array(
					'name'       => 'bio',
					'definition' => 'TEXT DEFAULT NULL',
				),
				array(
					'name'       => 'role',
					'definition' => "VARCHAR(32) NOT NULL DEFAULT 'editor'",
				),
				array(
					'name'       => 'is_email_verified',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 0',
				),
				array(
					'name'       => 'email_verified_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'email_verification_token',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'email_verification_expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'password_reset_token',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'password_reset_expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'two_factor_enabled',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 0',
				),
				array(
					'name'       => 'two_factor_secret',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'two_factor_pending_secret',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'backup_codes_json',
					'definition' => 'LONGTEXT DEFAULT NULL',
				),
				array(
					'name'       => 'backup_codes_generated_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'last_login_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			'api_keys'   => array(
				array(
					'name'       => 'key_hash',
					'definition' => 'CHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'key_prefix',
					'definition' => 'VARCHAR(16) DEFAULT NULL',
				),
				array(
					'name'       => 'key_last_four',
					'definition' => 'CHAR(4) DEFAULT NULL',
				),
				array(
					'name'       => 'created_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			'sessions'   => array(
				array(
					'name'       => 'browser',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'operating_system',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'device',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'revoked_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'revoked_reason',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
			),
			'urls'       => array(
				array(
					'name'       => 'title',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'password_value',
					'definition' => 'VARCHAR(255) DEFAULT NULL',
				),
				array(
					'name'       => 'expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'status',
					'definition' => "VARCHAR(32) NOT NULL DEFAULT 'active'",
				),
				array(
					'name'       => 'utm_source',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_medium',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_campaign',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_term',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_content',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
			),
			'clicks'     => array(
				array(
					'name'       => 'visitor_hash',
					'definition' => 'CHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'country_code',
					'definition' => 'VARCHAR(8) DEFAULT NULL',
				),
				array(
					'name'       => 'country_name',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'city_name',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'device',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'browser',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'operating_system',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_name',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_domain',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_category',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_source',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_medium',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_campaign',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_term',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_content',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'user_agent',
					'definition' => 'TEXT DEFAULT NULL',
				),
			),
			'audit_logs' => array(
				array(
					'name'       => 'link_id',
					'definition' => 'VARCHAR(40) DEFAULT NULL',
				),
				array(
					'name'       => 'metadata',
					'definition' => 'LONGTEXT DEFAULT NULL',
				),
			),
			'webhooks'   => array(
				array(
					'name'       => 'is_active',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 1',
				),
			),
		);
	}

	/**
	 * Index repair specs keyed by table name.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 * @since 1.0.3
	 */
	private function index_specs(): array {
		return array(
			'settings'   => array(
				array(
					'name'    => 'idx_settings_autoload',
					'type'    => 'index',
					'columns' => '(autoload)',
				),
			),
			'users'      => array(
				array(
					'name'    => 'idx_users_role',
					'type'    => 'index',
					'columns' => '(role)',
				),
			),
			'api_keys'   => array(
				array(
					'name'    => 'uniq_api_keys_key_hash',
					'type'    => 'unique',
					'columns' => '(key_hash)',
				),
				array(
					'name'    => 'idx_api_keys_user_created_at',
					'type'    => 'index',
					'columns' => '(user_id, created_at)',
				),
			),
			'sessions'   => array(
				array(
					'name'    => 'idx_sessions_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_sessions_token_hash',
					'type'    => 'index',
					'columns' => '(token_hash)',
				),
				array(
					'name'    => 'idx_sessions_user_active',
					'type'    => 'index',
					'columns' => '(user_id, revoked_at, last_active_at)',
				),
			),
			'urls'       => array(
				array(
					'name'    => 'idx_urls_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_urls_user_status',
					'type'    => 'index',
					'columns' => '(user_id, status)',
				),
				array(
					'name'    => 'idx_urls_status',
					'type'    => 'index',
					'columns' => '(status)',
				),
				array(
					'name'    => 'idx_urls_created_at',
					'type'    => 'index',
					'columns' => '(created_at)',
				),
			),
			'clicks'     => array(
				array(
					'name'    => 'idx_clicks_url_id',
					'type'    => 'index',
					'columns' => '(url_id)',
				),
				array(
					'name'    => 'idx_clicks_clicked_at',
					'type'    => 'index',
					'columns' => '(clicked_at)',
				),
				array(
					'name'    => 'idx_clicks_url_clicked_at',
					'type'    => 'index',
					'columns' => '(url_id, clicked_at)',
				),
			),
			'audit_logs' => array(
				array(
					'name'    => 'idx_audit_logs_created_at',
					'type'    => 'index',
					'columns' => '(created_at)',
				),
				array(
					'name'    => 'idx_audit_logs_user_created_at',
					'type'    => 'index',
					'columns' => '(user_id, created_at)',
				),
				array(
					'name'    => 'idx_audit_logs_link_id',
					'type'    => 'index',
					'columns' => '(link_id)',
				),
			),
			'webhooks'   => array(
				array(
					'name'    => 'idx_webhooks_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_webhooks_user_active',
					'type'    => 'index',
					'columns' => '(user_id, is_active)',
				),
			),
		);
	}

	/**
	 * Foreign key repair specs keyed by table name.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 * @since 1.0.3
	 */
	private function foreign_key_specs(): array {
		return array(
			'api_keys'   => array(
				array(
					'name'       => 'fk_api_keys_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
			),
			'sessions'   => array(
				array(
					'name'       => 'fk_sessions_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
			),
			'urls'       => array(
				array(
					'name'       => 'fk_urls_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
			),
			'clicks'     => array(
				array(
					'name'       => 'fk_clicks_url_id',
					'definition' => 'FOREIGN KEY (url_id) REFERENCES urls (id) ON DELETE CASCADE',
				),
			),
			'audit_logs' => array(
				array(
					'name'       => 'fk_audit_logs_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
				array(
					'name'       => 'fk_audit_logs_link_id',
					'definition' => 'FOREIGN KEY (link_id) REFERENCES urls (id) ON DELETE CASCADE',
				),
			),
			'webhooks'   => array(
				array(
					'name'       => 'fk_webhooks_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
			),
		);
	}
}
