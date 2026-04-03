<?php
/**
 * PeakURL database wrapper.
 *
 * Provides a small query API over the prefixed \PDO connection so the
 * rest of the data layer can use one consistent interface, similar in
 * spirit to how WordPress centralises SQL access through wpdb.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Includes;

use PeakURL\Utils\Database;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * PeakURL_DB — wpdb-style query helper for the data layer.
 *
 * Wraps the lower-level Connection manager and exposes a
 * reusable API for prepared queries, writes, scalar lookups, and
 * transaction control.
 *
 * @since 1.0.0
 */
class PeakURL_DB {

	/**
	 * Connection and table-prefix manager.
	 *
	 * @var Connection
	 * @since 1.0.0
	 */
	private Connection $connection_manager;

	/**
	 * Shared \PDO connection instance.
	 *
	 * @var \PDO
	 * @since 1.0.0
	 */
	private \PDO $connection;

	/**
	 * Create a new PeakURL_DB wrapper.
	 *
	 * @param Connection $connection_manager Initialised connection manager.
	 * @since 1.0.0
	 */
	public function __construct( Connection $connection_manager ) {
		$this->connection_manager = $connection_manager;
		$this->connection         = $connection_manager->get_connection();
	}

	/**
	 * Get the shared \PDO connection.
	 *
	 * @return \PDO Active \PDO connection.
	 * @since 1.0.0
	 */
	public function get_connection(): \PDO {
		return $this->connection;
	}

	/**
	 * Prepare a prefixed SQL statement.
	 *
	 * @param string $sql Raw SQL string.
	 * @return \PDOStatement Prepared statement.
	 * @since 1.0.0
	 */
	public function prepare( string $sql ): \PDOStatement {
		return $this->connection_manager->prepare( $sql );
	}

	/**
	 * Escape a LIKE fragment so user input stays literal.
	 *
	 * @param string $text Raw search fragment.
	 * @return string Escaped fragment safe for LIKE patterns.
	 * @since 1.0.1
	 */
	public function esc_like( string $text ): string {
		return Database::esc_like( $text );
	}

	/**
	 * Prepare named placeholders for an `IN (...)` value list.
	 *
	 * @param array<int, mixed> $values Array of values to bind.
	 * @param string            $prefix Placeholder prefix.
	 * @return array{sql: string, params: array<string, mixed>}
	 * @since 1.0.1
	 */
	public function in_placeholders(
		array $values,
		string $prefix = 'value'
	): array {
		return Database::in_placeholders( $values, $prefix );
	}

	/**
	 * Fetch a single row from a managed table using simple conditions.
	 *
	 * @param string               $table_name Base table name without prefix.
	 * @param array<string, mixed> $where      Equality-based WHERE conditions.
	 * @param array<int, string>   $columns    Columns to fetch.
	 * @param array<string, string> $order_by  Optional ORDER BY columns.
	 * @return array<string, mixed>|null First row or null.
	 * @since 1.0.1
	 */
	public function get_row_by(
		string $table_name,
		array $where,
		array $columns = array( '*' ),
		array $order_by = array()
	): ?array {
		$where_clause = Database::where_equals( $where, 'where' );

		return $this->get_row(
			'SELECT ' . Database::select_columns( $columns ) .
			' FROM ' . $this->table_identifier( $table_name ) .
			' WHERE ' . $where_clause['sql'] .
			Database::order_by( $order_by ) .
			' LIMIT 1',
			$where_clause['params'],
		);
	}

	/**
	 * Fetch multiple rows from a managed table using simple conditions.
	 *
	 * @param string               $table_name Base table name without prefix.
	 * @param array<string, mixed> $where      Equality-based WHERE conditions.
	 * @param array<int, string>   $columns    Columns to fetch.
	 * @param array<string, string> $order_by  Optional ORDER BY columns.
	 * @return array<int, array<string, mixed>> Result rows.
	 * @since 1.0.1
	 */
	public function get_results_by(
		string $table_name,
		array $where = array(),
		array $columns = array( '*' ),
		array $order_by = array()
	): array {
		$sql    = 'SELECT ' . Database::select_columns( $columns ) .
			' FROM ' . $this->table_identifier( $table_name );
		$params = array();

		if ( ! empty( $where ) ) {
			$where_clause = Database::where_equals( $where, 'where' );
			$sql         .= ' WHERE ' . $where_clause['sql'];
			$params       = $where_clause['params'];
		}

		$sql .= Database::order_by( $order_by );

		return $this->get_results( $sql, $params );
	}

	/**
	 * Fetch the first column from each row in a managed table query.
	 *
	 * @param string               $table_name Base table name without prefix.
	 * @param string               $column_name Column to return.
	 * @param array<string, mixed> $where       Equality-based WHERE conditions.
	 * @param array<string, string> $order_by   Optional ORDER BY columns.
	 * @return array<int, mixed> Column values in query order.
	 * @since 1.0.1
	 */
	public function get_col_by(
		string $table_name,
		string $column_name,
		array $where = array(),
		array $order_by = array()
	): array {
		$sql    = 'SELECT ' . Database::quote_identifier( $column_name ) .
			' FROM ' . $this->table_identifier( $table_name );
		$params = array();

		if ( ! empty( $where ) ) {
			$where_clause = Database::where_equals( $where, 'where' );
			$sql         .= ' WHERE ' . $where_clause['sql'];
			$params       = $where_clause['params'];
		}

		return $this->get_col(
			$sql . Database::order_by( $order_by ),
			$params,
		);
	}

	/**
	 * Fetch the first column from the first row in a managed table query.
	 *
	 * @param string               $table_name Base table name without prefix.
	 * @param string               $column_name Column to return.
	 * @param array<string, mixed> $where       Equality-based WHERE conditions.
	 * @return mixed Scalar value or false when empty.
	 * @since 1.0.1
	 */
	public function get_var_by(
		string $table_name,
		string $column_name,
		array $where
	) {
		$where_clause = Database::where_equals( $where, 'where' );

		return $this->get_var(
			'SELECT ' . Database::quote_identifier( $column_name ) .
			' FROM ' . $this->table_identifier( $table_name ) .
			' WHERE ' . $where_clause['sql'] .
			' LIMIT 1',
			$where_clause['params'],
		);
	}

	/**
	 * Count rows in a managed table using simple conditions.
	 *
	 * @param string               $table_name Base table name without prefix.
	 * @param array<string, mixed> $where      Equality-based WHERE conditions.
	 * @return int Matching row count.
	 * @since 1.0.1
	 */
	public function count( string $table_name, array $where = array() ): int {
		$sql    = 'SELECT COUNT(*) FROM ' . $this->table_identifier( $table_name );
		$params = array();

		if ( ! empty( $where ) ) {
			$where_clause = Database::where_equals( $where, 'where' );
			$sql         .= ' WHERE ' . $where_clause['sql'];
			$params       = $where_clause['params'];
		}

		return (int) $this->get_var( $sql, $params );
	}

	/**
	 * Insert or update a row using MySQL's duplicate-key handling.
	 *
	 * @param string               $table_name     Base table name without prefix.
	 * @param array<string, mixed> $data           Column-value pairs to insert.
	 * @param array<int, string>   $update_columns Columns to update on conflict.
	 * @return int Number of affected rows.
	 * @since 1.0.1
	 */
	public function upsert(
		string $table_name,
		array $data,
		array $update_columns
	): int {
		$insert_clause = Database::insert_values( $data, 'insert' );
		$assignments   = array();

		foreach ( $update_columns as $column_name ) {
			$column_name   = Database::sanitize_identifier(
				(string) $column_name,
				'upsert column',
			);
			$assignments[] = Database::quote_identifier( $column_name ) .
				' = VALUES(' .
				Database::quote_identifier( $column_name ) .
				')';
		}

		if ( empty( $assignments ) ) {
			throw new \InvalidArgumentException(
				'PeakURL upsert columns cannot be empty.',
			);
		}

		return $this->query(
			'INSERT INTO ' . $this->table_identifier( $table_name ) .
			' (' . $insert_clause['columns'] . ')' .
			' VALUES (' . $insert_clause['values'] . ')' .
			' ON DUPLICATE KEY UPDATE ' . implode( ', ', $assignments ),
			$insert_clause['params'],
		);
	}

	/**
	 * Fetch rows from a managed table where one column matches an IN list.
	 *
	 * @param string               $table_name   Base table name without prefix.
	 * @param string               $column_name  Column to compare in the IN list.
	 * @param array<int, mixed>    $values       Values for the IN list.
	 * @param array<int, string>   $columns      Columns to fetch.
	 * @param array<string, mixed> $where        Additional equality conditions.
	 * @param array<string, string> $order_by    Optional ORDER BY columns.
	 * @return array<int, array<string, mixed>> Result rows.
	 * @since 1.0.1
	 */
	public function get_results_where_in(
		string $table_name,
		string $column_name,
		array $values,
		array $columns = array( '*' ),
		array $where = array(),
		array $order_by = array()
	): array {
		if ( empty( $values ) ) {
			return array();
		}

		list( $sql, $params ) = $this->build_where_in_query(
			$table_name,
			$column_name,
			$values,
			$where,
			'SELECT ' . Database::select_columns( $columns ),
		);

		return $this->get_results(
			$sql . Database::order_by( $order_by ),
			$params,
		);
	}

	/**
	 * Fetch the first column from rows where one column matches an IN list.
	 *
	 * @param string               $table_name      Base table name without prefix.
	 * @param string               $select_column   Column to return.
	 * @param string               $filter_column   Column to compare in the IN list.
	 * @param array<int, mixed>    $values          Values for the IN list.
	 * @param array<string, mixed> $where           Additional equality conditions.
	 * @param array<string, string> $order_by       Optional ORDER BY columns.
	 * @return array<int, mixed> Column values in query order.
	 * @since 1.0.1
	 */
	public function get_col_where_in(
		string $table_name,
		string $select_column,
		string $filter_column,
		array $values,
		array $where = array(),
		array $order_by = array()
	): array {
		if ( empty( $values ) ) {
			return array();
		}

		list( $sql, $params ) = $this->build_where_in_query(
			$table_name,
			$filter_column,
			$values,
			$where,
			'SELECT ' . Database::quote_identifier( $select_column ),
		);

		return $this->get_col(
			$sql . Database::order_by( $order_by ),
			$params,
		);
	}

	/**
	 * Delete rows where one column matches an IN list.
	 *
	 * @param string               $table_name   Base table name without prefix.
	 * @param string               $column_name  Column to compare in the IN list.
	 * @param array<int, mixed>    $values       Values for the IN list.
	 * @param array<string, mixed> $where        Additional equality conditions.
	 * @return int Number of affected rows.
	 * @since 1.0.1
	 */
	public function delete_where_in(
		string $table_name,
		string $column_name,
		array $values,
		array $where = array()
	): int {
		if ( empty( $values ) ) {
			return 0;
		}

		list( $sql, $params ) = $this->build_where_in_query(
			$table_name,
			$column_name,
			$values,
			$where,
			'DELETE',
		);

		return $this->query( $sql, $params );
	}

	/**
	 * Insert a row into a managed table.
	 *
	 * Mirrors the role of `wpdb::insert()` for the PeakURL data layer.
	 *
	 * @param string               $table_name Base table name without prefix.
	 * @param array<string, mixed> $data       Column-value pairs to insert.
	 * @return int Number of affected rows.
	 * @since 1.0.1
	 */
	public function insert( string $table_name, array $data ): int {
		$insert_clause = Database::insert_values(
			$data,
			'insert',
		);

		return $this->query(
			'INSERT INTO ' . $this->table_identifier( $table_name ) .
			' (' . $insert_clause['columns'] . ')' .
			' VALUES (' . $insert_clause['values'] . ')',
			$insert_clause['params'],
		);
	}

	/**
	 * Update rows in a managed table using equality-based conditions.
	 *
	 * Mirrors the role of `wpdb::update()` for the PeakURL data layer.
	 *
	 * @param string               $table_name Base table name without prefix.
	 * @param array<string, mixed> $data       Column-value pairs to update.
	 * @param array<string, mixed> $where      Equality-based WHERE conditions.
	 * @return int Number of affected rows.
	 * @since 1.0.1
	 */
	public function update(
		string $table_name,
		array $data,
		array $where
	): int {
		if ( empty( $data ) ) {
			throw new \InvalidArgumentException(
				'PeakURL update data cannot be empty.',
			);
		}

		$set_clause   = Database::set_values(
			$data,
			'set',
		);
		$where_clause = Database::where_equals(
			$where,
			'where',
		);

		return $this->query(
			'UPDATE ' . $this->table_identifier( $table_name ) .
			' SET ' . $set_clause['sql'] .
			' WHERE ' . $where_clause['sql'],
			array_merge( $set_clause['params'], $where_clause['params'] ),
		);
	}

	/**
	 * Delete rows from a managed table using equality-based conditions.
	 *
	 * Mirrors the role of `wpdb::delete()` for the PeakURL data layer.
	 *
	 * @param string               $table_name Base table name without prefix.
	 * @param array<string, mixed> $where      Equality-based WHERE conditions.
	 * @return int Number of affected rows.
	 * @since 1.0.1
	 */
	public function delete( string $table_name, array $where ): int {
		$where_clause = Database::where_equals(
			$where,
			'where',
		);

		return $this->query(
			'DELETE FROM ' . $this->table_identifier( $table_name ) .
			' WHERE ' . $where_clause['sql'],
			$where_clause['params'],
		);
	}

	/**
	 * Execute a write query.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Named parameters.
	 * @return int Number of affected rows.
	 * @since 1.0.0
	 */
	public function query( string $sql, array $params = array() ): int {
		$statement = $this->execute_statement( $sql, $params );

		return $statement->rowCount();
	}

	/**
	 * Execute a query and return a single row.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Named parameters.
	 * @return array<string, mixed>|null First row or null.
	 * @since 1.0.0
	 */
	public function get_row(
		string $sql,
		array $params = array()
	): ?array {
		$statement = $this->execute_statement( $sql, $params );
		$row       = $statement->fetch();

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Execute a query and return all rows.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Named parameters.
	 * @return array<int, array<string, mixed>> Result rows.
	 * @since 1.0.0
	 */
	public function get_results(
		string $sql,
		array $params = array()
	): array {
		$statement = $this->execute_statement( $sql, $params );
		$rows      = $statement->fetchAll();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Execute a query and return the first column from every row.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Named parameters.
	 * @return array<int, mixed> Column values in query order.
	 * @since 1.0.1
	 */
	public function get_col(
		string $sql,
		array $params = array()
	): array {
		$statement = $this->execute_statement( $sql, $params );
		$rows      = $statement->fetchAll( \PDO::FETCH_COLUMN );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Execute a query and return the first column from the first row.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Named parameters.
	 * @return mixed Scalar value or false when empty.
	 * @since 1.0.0
	 */
	public function get_var(
		string $sql,
		array $params = array()
	) {
		$statement = $this->execute_statement( $sql, $params );

		return $statement->fetchColumn();
	}

	/**
	 * Start a transaction when one is not already active.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function begin_transaction(): void {
		if ( ! $this->connection->inTransaction() ) {
			$this->connection->beginTransaction();
		}
	}

	/**
	 * Commit the active transaction.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function commit(): void {
		if ( $this->connection->inTransaction() ) {
			$this->connection->commit();
		}
	}

	/**
	 * Roll back the active transaction.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function roll_back(): void {
		if ( $this->connection->inTransaction() ) {
			$this->connection->rollBack();
		}
	}

	/**
	 * Determine whether a transaction is active.
	 *
	 * @return bool True when a transaction is open.
	 * @since 1.0.0
	 */
	public function in_transaction(): bool {
		return $this->connection->inTransaction();
	}

	/**
	 * Get the last inserted auto-increment ID.
	 *
	 * @return string Last insert ID.
	 * @since 1.0.0
	 */
	public function insert_id(): string {
		return (string) $this->connection->lastInsertId();
	}

	/**
	 * Check whether a PeakURL table exists.
	 *
	 * @param string $table_name Base table name.
	 * @return bool True when the table exists.
	 * @since 1.0.0
	 */
	public function table_exists( string $table_name ): bool {
		return $this->connection_manager->table_exists( $table_name );
	}

	/**
	 * Return the full prefixed table name.
	 *
	 * @param string $table_name Base table name.
	 * @return string Prefixed table name.
	 * @since 1.0.0
	 */
	public function table_name( string $table_name ): string {
		return $this->connection_manager->table_name( $table_name );
	}

	/**
	 * Quote a managed table name for direct SQL generation.
	 *
	 * @param string $table_name Base table name without prefix.
	 * @return string Backtick-quoted table identifier.
	 * @since 1.0.1
	 */
	private function table_identifier( string $table_name ): string {
		return Database::quote_identifier(
			$this->table_name( $table_name ),
		);
	}

	/**
	 * Build a base query with an `IN (...)` condition plus optional filters.
	 *
	 * @param string               $table_name   Base table name without prefix.
	 * @param string               $column_name  Column to compare in the IN list.
	 * @param array<int, mixed>    $values       Values for the IN list.
	 * @param array<string, mixed> $where        Additional equality conditions.
	 * @param string               $verb         SQL prefix such as SELECT or DELETE.
	 * @return array{0: string, 1: array<string, mixed>}
	 * @since 1.0.1
	 */
	private function build_where_in_query(
		string $table_name,
		string $column_name,
		array $values,
		array $where,
		string $verb
	): array {
		$in_clause  = Database::where_in(
			$column_name,
			$values,
			$column_name,
		);
		$sql        = $verb . ' FROM ' . $this->table_identifier( $table_name );
		$conditions = array( $in_clause['sql'] );
		$params     = $in_clause['params'];

		if ( ! empty( $where ) ) {
			$where_clause = Database::where_equals( $where, 'where' );
			$conditions[] = $where_clause['sql'];
			$params       = array_merge( $params, $where_clause['params'] );
		}

		$sql .= ' WHERE ' . implode( ' AND ', $conditions );

		return array( $sql, $params );
	}

	/**
	 * Prepare and execute a statement in one place.
	 *
	 * Keeping statement execution inside the wrapper makes it easier to expand
	 * query safety rules later without touching every data module.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Named or positional parameters.
	 * @return \PDOStatement Executed statement.
	 * @since 1.0.1
	 */
	private function execute_statement(
		string $sql,
		array $params = array()
	): \PDOStatement {
		$statement = $this->prepare( $sql );
		$statement->execute( $params );

		return $statement;
	}
}
