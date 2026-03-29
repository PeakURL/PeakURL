<?php
/**
 * PeakURL database wrapper.
 *
 * Provides a small query API over the prefixed PDO connection so the
 * rest of the data layer can use one consistent interface, similar in
 * spirit to how WordPress centralises SQL access through wpdb.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * PeakURL_DB — wpdb-style query helper for the data layer.
 *
 * Wraps the lower-level Database connection manager and exposes a
 * reusable API for prepared queries, writes, scalar lookups, and
 * transaction control.
 *
 * @since 1.0.0
 */
class PeakURL_DB {

	/**
	 * Connection and table-prefix manager.
	 *
	 * @var Database
	 * @since 1.0.0
	 */
	private Database $database;

	/**
	 * Shared PDO connection instance.
	 *
	 * @var PDO
	 * @since 1.0.0
	 */
	private PDO $connection;

	/**
	 * Create a new PeakURL_DB wrapper.
	 *
	 * @param Database $database Initialised database manager.
	 * @since 1.0.0
	 */
	public function __construct( Database $database ) {
		$this->database   = $database;
		$this->connection = $database->get_connection();
	}

	/**
	 * Get the shared PDO connection.
	 *
	 * @return PDO Active PDO connection.
	 * @since 1.0.0
	 */
	public function get_connection(): PDO {
		return $this->connection;
	}

	/**
	 * Prepare a prefixed SQL statement.
	 *
	 * @param string $sql Raw SQL string.
	 * @return PDOStatement Prepared statement.
	 * @since 1.0.0
	 */
	public function prepare( string $sql ): PDOStatement {
		return $this->database->prepare( $sql );
	}

	/**
	 * Execute a write query.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Named parameters.
	 * @return void
	 * @since 1.0.0
	 */
	public function query( string $sql, array $params = array() ): int {
		$statement = $this->prepare( $sql );
		$statement->execute( $params );

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
		$statement = $this->prepare( $sql );
		$statement->execute( $params );
		$row = $statement->fetch();

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
		$statement = $this->prepare( $sql );
		$statement->execute( $params );
		$rows = $statement->fetchAll();

		return is_array( $rows ) ? $rows : array();
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
		$statement = $this->prepare( $sql );
		$statement->execute( $params );

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
		return $this->database->table_exists( $table_name );
	}

	/**
	 * Return the full prefixed table name.
	 *
	 * @param string $table_name Base table name.
	 * @return string Prefixed table name.
	 * @since 1.0.0
	 */
	public function table_name( string $table_name ): string {
		return $this->database->table_name( $table_name );
	}
}
