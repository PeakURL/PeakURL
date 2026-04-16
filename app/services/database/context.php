<?php
/**
 * Shared database schema context helpers.
 *
 * @package PeakURL\Services\Database
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Database;

use PeakURL\Includes\Connection;
use PeakURL\Includes\Constants;
use PeakURL\Utils\Database;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Context — shared low-level schema and settings helpers.
 *
 * Keeps repetitive database access and schema-specific primitives out of the
 * public schema service so the status and upgrade flows can stay focused.
 *
 * @since 1.0.14
 */
class Context {

	/**
	 * Shared connection manager.
	 *
	 * @var Connection
	 * @since 1.0.14
	 */
	private Connection $connection;

	/**
	 * Create a new schema context helper.
	 *
	 * @param Connection $connection Shared connection manager.
	 * @since 1.0.14
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Return the shared connection manager.
	 *
	 * @return Connection
	 * @since 1.0.14
	 */
	public function get_connection(): Connection {
		return $this->connection;
	}

	/**
	 * Return the active database connection.
	 *
	 * @return \PDO
	 * @since 1.0.14
	 */
	public function get_pdo(): \PDO {
		return $this->connection->get_connection();
	}

	/**
	 * Return the list of missing managed tables.
	 *
	 * @param array<int, string> $managed_tables Base managed table names.
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	public function get_missing_tables( array $managed_tables ): array {
		$missing_tables = array();

		foreach ( $managed_tables as $table_name ) {
			if ( ! $this->connection->table_exists( $table_name ) ) {
				$missing_tables[] = $table_name;
			}
		}

		return $missing_tables;
	}

	/**
	 * Return a prefixed table identifier for direct DDL usage.
	 *
	 * @param string $table_name Base table name.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_table_identifier( string $table_name ): string {
		return Database::quote_identifier( $this->connection->table_name( $table_name ) );
	}

	/**
	 * Return the prefixed table name for a managed table.
	 *
	 * @param string $table_name Base table name.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_table_name( string $table_name ): string {
		return $this->connection->table_name( $table_name );
	}

	/**
	 * Return the current recorded schema version from settings.
	 *
	 * @return int
	 * @since 1.0.14
	 */
	public function get_recorded_version(): int {
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
	 * @since 1.0.14
	 */
	public function get_setting( string $setting_key ): ?string {
		return $this->connection->get_setting_value( $setting_key );
	}

	/**
	 * Insert or update a setting row directly.
	 *
	 * @param string $setting_key   Settings key.
	 * @param string $setting_value Settings value.
	 * @param bool   $autoload      Whether the row should autoload.
	 * @return void
	 * @since 1.0.14
	 */
	public function update_setting(
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
	 * @since 1.0.14
	 */
	public function delete_setting( string $setting_key ): void {
		if ( ! $this->connection->table_exists( 'settings' ) ) {
			return;
		}

		$statement = $this->connection->prepare(
			'DELETE FROM settings WHERE setting_key = :setting_key'
		);
		$statement->execute( array( 'setting_key' => $setting_key ) );
	}

	/**
	 * Execute a scalar query with optional parameters.
	 *
	 * @param string               $sql    SQL query string.
	 * @param array<string, mixed> $params Bound parameters.
	 * @return mixed
	 * @since 1.0.14
	 */
	public function get_var( string $sql, array $params = array() ): mixed {
		$statement = $this->connection->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchColumn();
	}

	/**
	 * Determine whether a specific foreign key constraint exists.
	 *
	 * @param string $table_name      Base table name.
	 * @param string $constraint_name Un-prefixed constraint name.
	 * @return bool
	 * @since 1.0.14
	 */
	public function constraint_exists(
		string $table_name,
		string $constraint_name
	): bool {
		return (int) $this->get_var(
			'SELECT COUNT(*)
			FROM information_schema.table_constraints
			WHERE constraint_schema = :table_schema
			AND table_name = :table_name
			AND constraint_name = :constraint_name',
			array(
				'table_schema'    => (string) $this->connection->get_config()['DB_DATABASE'],
				'table_name'      => $this->connection->table_name( $table_name ),
				'constraint_name' => $this->get_constraint_name( $constraint_name ),
			),
		) > 0;
	}

	/**
	 * Return the current ON DELETE rule for a foreign key when available.
	 *
	 * @param string $table_name      Base table name.
	 * @param string $constraint_name Un-prefixed constraint name.
	 * @return string|null
	 * @since 1.0.14
	 */
	public function get_delete_rule(
		string $table_name,
		string $constraint_name
	): ?string {
		$rule = $this->get_var(
			'SELECT delete_rule
			FROM information_schema.referential_constraints
			WHERE constraint_schema = :table_schema
			AND table_name = :table_name
			AND constraint_name = :constraint_name
			LIMIT 1',
			array(
				'table_schema'    => (string) $this->connection->get_config()['DB_DATABASE'],
				'table_name'      => $this->connection->table_name( $table_name ),
				'constraint_name' => $this->get_constraint_name( $constraint_name ),
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
	 * Prefix a foreign key constraint name like schema.sql does.
	 *
	 * @param string $constraint_name Un-prefixed constraint name.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_constraint_name( string $constraint_name ): string {
		return $this->connection->get_table_prefix() . $constraint_name;
	}

	/**
	 * Return matching row IDs for a managed table and ID prefix.
	 *
	 * @param string $table_name Base table name.
	 * @param string $prefix     Row ID prefix including underscore.
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	public function get_ids_with_prefix(
		string $table_name,
		string $prefix
	): array {
		if ( ! $this->connection->table_exists( $table_name ) ) {
			return array();
		}

		$table_identifier = $this->get_table_identifier( $table_name );
		$statement        = $this->get_pdo()->prepare(
			'SELECT id
			FROM ' . $table_identifier . '
			WHERE id LIKE :id_pattern ESCAPE \'\\\\\'
			ORDER BY id ASC'
		);
		$statement->execute(
			array(
				'id_pattern' => Database::esc_like( $prefix ) . '%',
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
	 * @since 1.0.14
	 */
	public function row_id_exists(
		string $table_name,
		string $id,
		?string $exclude_id = null
	): bool {
		$table_identifier = $this->get_table_identifier( $table_name );
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

		return false !== $this->get_var( $sql, $params );
	}

	/**
	 * Generate a unique row ID for a managed table.
	 *
	 * @param string      $table_name Base table name.
	 * @param string|null $exclude_id Existing row ID to exclude.
	 * @return string
	 * @since 1.0.14
	 */
	public function generate_row_id(
		string $table_name,
		?string $exclude_id = null
	): string {
		do {
			$id = bin2hex( random_bytes( 10 ) );
		} while ( $this->row_id_exists( $table_name, $id, $exclude_id ) );

		return $id;
	}
}
