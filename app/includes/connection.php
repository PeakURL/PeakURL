<?php
/**
 * \PDO connection factory.
 *
 * Provides a lazy-initialised, singleton \PDO connection and table-prefix
 * utilities for MySQL / MariaDB.
 *
 * @package PeakURL\Includes
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Includes;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Connection manager and schema helpers.
 *
 * @since 1.0.0
 */
class Connection {

	/**
	 * Canonical list of table names managed by PeakURL.
	 *
	 * @var array<int, string>
	 * @since 1.0.0
	 */
	private const TABLE_NAMES = array(
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
	 * Merged runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/** @var \PDO|null Lazy-initialised \PDO instance. */
	private ?\PDO $connection = null;

	/**
	 * Create a new Connection instance.
	 *
	 * @param array<string, mixed> $config Merged runtime configuration.
	 * @since 1.0.0
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Return the shared \PDO connection, creating it on first access.
	 *
	 * The connection uses native prepares, UTC timezone, and exception
	 * error mode.
	 *
	 * @return \PDO Active database connection.
	 * @since 1.0.0
	 */
	public function get_connection(): \PDO {
		if ( $this->connection instanceof \PDO ) {
			return $this->connection;
		}

		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			(string) $this->config['DB_HOST'],
			(int) $this->config['DB_PORT'],
			(string) $this->config['DB_DATABASE'],
			(string) $this->config['DB_CHARSET'],
		);

		$this->connection = new \PDO(
			$dsn,
			(string) $this->config['DB_USERNAME'],
			(string) $this->config['DB_PASSWORD'],
			array(
				\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
				\PDO::ATTR_EMULATE_PREPARES   => false,
			),
		);
		$this->connection->exec( "SET time_zone = '+00:00'" );

		return $this->connection;
	}

	/**
	 * Get the configured database table prefix.
	 *
	 * @return string The prefix string (may be empty).
	 * @since 1.0.0
	 */
	public function get_table_prefix(): string {
		return (string) ( $this->config['DB_PREFIX'] ?? '' );
	}

	/**
	 * Return the full table name including the configured prefix.
	 *
	 * @param string $table_name Base table name without prefix.
	 * @return string Prefixed table name.
	 * @since 1.0.0
	 */
	public function table_name( string $table_name ): string {
		$prefix = $this->get_table_prefix();

		if ( '' === $prefix ) {
			return $table_name;
		}

		return $prefix . $table_name;
	}

	/**
	 * Prepare a \PDO statement with table-prefix substitution.
	 *
	 * @param string $sql Raw SQL with un-prefixed table names.
	 * @return \PDOStatement Ready-to-execute statement.
	 * @since 1.0.0
	 */
	public function prepare( string $sql ): \PDOStatement {
		return $this->get_connection()->prepare( $this->prefix_sql( $sql ) );
	}

	/**
	 * Replace bare table names in SQL with their prefixed equivalents.
	 *
	 * @param string $sql Raw SQL string.
	 * @return string SQL with prefixed table names.
	 * @since 1.0.0
	 */
	public function prefix_sql( string $sql ): string {
		$prefix = $this->get_table_prefix();

		if ( '' === $prefix ) {
			return $sql;
		}

		foreach ( self::TABLE_NAMES as $table_name ) {
			$sql = $this->replace_table_identifier(
				$sql,
				$table_name,
				$this->table_name( $table_name ),
			);
		}

		return $sql;
	}

	/**
	 * Prefix table names and CONSTRAINT identifiers in a DDL schema string.
	 *
	 * @param string $schema Raw DDL statements.
	 * @return string Prefixed DDL.
	 * @since 1.0.0
	 */
	public function prefix_schema( string $schema ): string {
		$prefix = $this->get_table_prefix();

		if ( '' === $prefix ) {
			return $schema;
		}

		$schema = $this->prefix_sql( $schema );
		$result = preg_replace_callback(
			'/\bCONSTRAINT\s+([A-Za-z0-9_]+)/i',
			static function ( array $matches ) use ( $prefix ): string {
				return 'CONSTRAINT ' . $prefix . $matches[1];
			},
			$schema,
		);

		return is_string( $result ) ? $result : $schema;
	}

	/**
	 * Check whether a column exists on a managed table.
	 *
	 * @param string $table_name  Base table name (without prefix).
	 * @param string $column_name Column name.
	 * @return bool True when the column exists.
	 * @since 1.0.0
	 */
	public function column_exists(
		string $table_name,
		string $column_name
	): bool {
		return (int) $this->query_value(
			'SELECT COUNT(*)
			FROM information_schema.columns
			WHERE table_schema = :table_schema
			AND table_name = :table_name
			AND column_name = :column_name',
			array(
				'table_schema' => (string) $this->config['DB_DATABASE'],
				'table_name'   => $this->table_name( $table_name ),
				'column_name'  => $column_name,
			),
		) > 0;
	}

	/**
	 * Check whether an index exists on a managed table.
	 *
	 * @param string $table_name Base table name (without prefix).
	 * @param string $index_name Index name.
	 * @return bool True when the index exists.
	 * @since 1.0.0
	 */
	public function index_exists( string $table_name, string $index_name ): bool {
		return (int) $this->query_value(
			'SELECT COUNT(*)
			FROM information_schema.statistics
			WHERE table_schema = :table_schema
			AND table_name = :table_name
			AND index_name = :index_name',
			array(
				'table_schema' => (string) $this->config['DB_DATABASE'],
				'table_name'   => $this->table_name( $table_name ),
				'index_name'   => $index_name,
			),
		) > 0;
	}

	/**
	 * Check whether a column is currently nullable.
	 *
	 * @param string $table_name  Base table name (without prefix).
	 * @param string $column_name Column name.
	 * @return bool True when the column allows NULL values.
	 * @since 1.0.1
	 */
	public function column_allows_null(
		string $table_name,
		string $column_name
	): bool {
		$result = $this->query_value(
			'SELECT is_nullable
			FROM information_schema.columns
			WHERE table_schema = :table_schema
			AND table_name = :table_name
			AND column_name = :column_name
			LIMIT 1',
			array(
				'table_schema' => (string) $this->config['DB_DATABASE'],
				'table_name'   => $this->table_name( $table_name ),
				'column_name'  => $column_name,
			),
		);

		return 'YES' === strtoupper( (string) $result );
	}

	/**
	 * Check whether a table exists in the current database.
	 *
	 * @param string $table_name Base table name (without prefix).
	 * @return bool True when the table is present.
	 * @since 1.0.0
	 */
	public function table_exists( string $table_name ): bool {
		return (int) $this->query_value(
			'SELECT COUNT(*)
			FROM information_schema.tables
			WHERE table_schema = :table_schema
			AND table_name = :table_name',
			array(
				'table_schema' => (string) $this->config['DB_DATABASE'],
				'table_name'   => $this->table_name( $table_name ),
			),
		) > 0;
	}

	/**
	 * Verify that every table in TABLE_NAMES exists in the database.
	 *
	 * @return bool True when all required tables are present.
	 * @since 1.0.0
	 */
	public function has_required_tables(): bool {
		foreach ( self::TABLE_NAMES as $table_name ) {
			if ( ! $this->table_exists( $table_name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a table contains at least one row.
	 *
	 * @param string $table_name Base table name (without prefix).
	 * @return bool True when the table exists and has at least one row.
	 * @since 1.0.0
	 */
	public function table_has_rows( string $table_name ): bool {
		if ( ! $this->table_exists( $table_name ) ) {
			return false;
		}

		$identifier = str_replace( '`', '``', $this->table_name( $table_name ) );
		$sql        = sprintf(
			'SELECT EXISTS(SELECT 1 FROM `%s` LIMIT 1)',
			$identifier,
		);

		return (int) $this->query_value( $sql ) > 0;
	}

	/**
	 * Check whether a settings row has a non-empty value.
	 *
	 * @param string $setting_key The setting_key column value.
	 * @return bool True when the setting exists and is non-blank.
	 * @since 1.0.0
	 */
	public function setting_has_value( string $setting_key ): bool {
		$value = $this->get_setting_value( $setting_key );

		return is_string( $value ) ? '' !== trim( $value ) : ! empty( $value );
	}

	/**
	 * Retrieve a single setting value from the settings table.
	 *
	 * @param string $setting_key The setting_key column value.
	 * @return string|null The setting value, or null when not found.
	 * @since 1.0.0
	 */
	public function get_setting_value( string $setting_key ): ?string {
		if ( ! $this->table_exists( 'settings' ) ) {
			return null;
		}

		$value = $this->query_value(
			'SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1',
			array( 'setting_key' => $setting_key ),
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Return the raw configuration array used to initialise the connection.
	 *
	 * @return array<string, mixed> Configuration map.
	 * @since 1.0.0
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Replace a table identifier only in real SQL table-name positions.
	 *
	 * @param string $subject     SQL string.
	 * @param string $identifier  Bare table identifier to find.
	 * @param string $replacement Prefixed identifier.
	 * @return string Updated SQL.
	 * @since 1.0.0
	 */
	private function replace_table_identifier(
		string $subject,
		string $identifier,
		string $replacement
	): string {
		$pattern = sprintf(
			'/\b(' .
			'FROM' .
			'|JOIN' .
			'|INTO' .
			'|UPDATE' .
			'|REFERENCES' .
			'|DESCRIBE' .
			'|DESC' .
			'|TABLE(?:\s+IF\s+(?:NOT\s+)?EXISTS)?' .
			')(\s+)(`?)%s(`?)(?=\b|\s|\(|,)/i',
			preg_quote( $identifier, '/' ),
		);
		$result  = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $replacement ): string {
				return $matches[1] . $matches[2] . $replacement;
			},
			$subject,
		);

		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Execute a query and return the first column of the first row.
	 *
	 * @param string               $sql    SQL with optional named placeholders.
	 * @param array<string, mixed> $params Bind parameters.
	 * @return mixed Scalar column value.
	 * @since 1.0.0
	 */
	private function query_value( string $sql, array $params = array() ): mixed {
		$statement = $this->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchColumn();
	}
}
