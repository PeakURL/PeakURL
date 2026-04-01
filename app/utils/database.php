<?php
/**
 * Shared database utility helpers.
 *
 * Provides reusable SQL helper methods for the PeakURL data layer so query
 * safety rules and identifier handling stay in one place.
 *
 * @package PeakURL\Data
 * @since 1.0.1
 */

declare(strict_types=1);

namespace PeakURL\Utils;

use InvalidArgumentException;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Database — shared SQL helper methods for the data layer.
 *
 * These helpers centralize SQL-adjacent concerns such as escaping `LIKE`
 * fragments, preparing `IN (...)` placeholder lists, quoting identifiers,
 * and building simple assignment and where clauses for CRUD helpers.
 *
 * @since 1.0.1
 */
class Database {

	/**
	 * Prepare a quoted column list for a SELECT query.
	 *
	 * @param array<int, string> $columns Column names or `*`.
	 * @return string SQL-ready select list.
	 * @since 1.0.1
	 */
	public static function select_columns( array $columns ): string {
		if ( empty( $columns ) ) {
			return '*';
		}

		$select_columns = array();

		foreach ( $columns as $column_name ) {
			$column_name = trim( (string) $column_name );

			if ( '*' === $column_name ) {
				return '*';
			}

			$select_columns[] = self::quote_identifier( $column_name );
		}

		return empty( $select_columns )
			? '*'
			: implode( ', ', $select_columns );
	}

	/**
	 * Escape a LIKE fragment so user input stays literal.
	 *
	 * Mirrors the intent of `wpdb::esc_like()` by escaping wildcard characters
	 * before the caller wraps the value in `%...%`.
	 *
	 * @param string $text Raw search fragment.
	 * @return string Escaped fragment safe for LIKE patterns.
	 * @since 1.0.1
	 */
	public static function esc_like( string $text ): string {
		return str_replace(
			array( '\\', '%', '_' ),
			array( '\\\\', '\\%', '\\_' ),
			$text,
		);
	}

	/**
	 * Sanitize an internal SQL identifier such as a table, column, or index name.
	 *
	 * PeakURL-managed identifiers should contain only letters, numbers, and
	 * underscores. This keeps any interpolated identifier fragments predictable
	 * and rejects unsafe characters early.
	 *
	 * @param string $identifier Raw identifier.
	 * @param string $context    Human-readable context label.
	 * @return string Sanitized identifier.
	 * @since 1.0.1
	 */
	public static function sanitize_identifier(
		string $identifier,
		string $context = 'identifier'
	): string {
		$identifier = trim( str_replace( '`', '', $identifier ) );

		if ( '' === $identifier ) {
			return '';
		}

		$sanitized = preg_replace( '/[^A-Za-z0-9_]/', '', $identifier );
		$sanitized = is_string( $sanitized ) ? $sanitized : '';

		if ( '' === $sanitized ) {
			throw new \InvalidArgumentException(
				sprintf( 'PeakURL received an invalid %s.', $context ),
			);
		}

		return $sanitized;
	}

	/**
	 * Quote a SQL identifier after sanitising it.
	 *
	 * @param string $identifier Raw table or column name.
	 * @return string Backtick-quoted identifier.
	 * @since 1.0.1
	 */
	public static function quote_identifier( string $identifier ): string {
		$identifier = self::sanitize_identifier( $identifier );

		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Prepare named placeholders for an `IN (...)` value list.
	 *
	 * This keeps higher-level store code readable when a query needs a dynamic
	 * number of bound values, similar in spirit to how WordPress centralises
	 * query preparation inside `wpdb`.
	 *
	 * @param array<int, mixed> $values Array of values to bind.
	 * @param string            $prefix Placeholder prefix, e.g. `setting_key`.
	 * @return array{sql: string, params: array<string, mixed>}
	 * @since 1.0.1
	 */
	public static function in_placeholders(
		array $values,
		string $prefix = 'value'
	): array {
		$prefix       = self::sanitize_identifier(
			$prefix,
			'placeholder prefix',
		);
		$placeholders = array();
		$params       = array();

		foreach ( array_values( $values ) as $index => $value ) {
			$key            = $prefix . '_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $value;
		}

		return array(
			'sql'    => implode( ', ', $placeholders ),
			'params' => $params,
		);
	}

	/**
	 * Prepare a simple `WHERE ... IN (...)` condition.
	 *
	 * @param string $column_name Column name to compare.
	 * @param array<int, mixed> $values Values to bind.
	 * @param string $prefix Placeholder prefix.
	 * @return array{sql: string, params: array<string, mixed>}
	 * @since 1.0.1
	 */
	public static function where_in(
		string $column_name,
		array $values,
		string $prefix = 'value'
	): array {
		$column_name = self::sanitize_identifier(
			$column_name,
			'where column',
		);
		$in_clause   = self::in_placeholders( $values, $prefix );

		return array(
			'sql'    => self::quote_identifier( $column_name ) . ' IN (' . $in_clause['sql'] . ')',
			'params' => $in_clause['params'],
		);
	}

	/**
	 * Prepare INSERT columns, placeholders, and values.
	 *
	 * @param array<string, mixed> $data         Column-value pairs.
	 * @param string               $param_prefix Parameter prefix.
	 * @return array{columns: string, values: string, params: array<string, mixed>}
	 * @since 1.0.1
	 */
	public static function insert_values(
		array $data,
		string $param_prefix
	): array {
		if ( empty( $data ) ) {
			throw new \InvalidArgumentException(
				'PeakURL insert data cannot be empty.',
			);
		}

		$columns      = array();
		$placeholders = array();
		$params       = array();

		foreach ( $data as $column_name => $value ) {
			$column_name               = self::sanitize_identifier(
				(string) $column_name,
				'column name',
			);
			$parameter_name            = $param_prefix . '_' . $column_name;
			$columns[]                 = self::quote_identifier( $column_name );
			$placeholders[]            = ':' . $parameter_name;
			$params[ $parameter_name ] = $value;
		}

		return array(
			'columns' => implode( ', ', $columns ),
			'values'  => implode( ', ', $placeholders ),
			'params'  => $params,
		);
	}

	/**
	 * Prepare `column = :value` pairs for UPDATE queries.
	 *
	 * @param array<string, mixed> $data         Column-value pairs.
	 * @param string               $param_prefix Parameter prefix.
	 * @return array{sql: string, params: array<string, mixed>}
	 * @since 1.0.1
	 */
	public static function set_values(
		array $data,
		string $param_prefix
	): array {
		$assignments = array();
		$params      = array();

		foreach ( $data as $column_name => $value ) {
			$column_name               = self::sanitize_identifier(
				(string) $column_name,
				'column name',
			);
			$parameter_name            = $param_prefix . '_' . $column_name;
			$assignments[]             =
				self::quote_identifier( $column_name ) .
				' = :' .
				$parameter_name;
			$params[ $parameter_name ] = $value;
		}

		return array(
			'sql'    => implode( ', ', $assignments ),
			'params' => $params,
		);
	}

	/**
	 * Prepare a simple equality-based WHERE condition list.
	 *
	 * @param array<string, mixed> $where        Column-value conditions.
	 * @param string               $param_prefix Parameter prefix.
	 * @return array{sql: string, params: array<string, mixed>}
	 * @since 1.0.1
	 */
	public static function where_equals(
		array $where,
		string $param_prefix
	): array {
		if ( empty( $where ) ) {
			throw new \InvalidArgumentException(
				'PeakURL where conditions cannot be empty.',
			);
		}

		$clauses = array();
		$params  = array();

		foreach ( $where as $column_name => $value ) {
			$column_name = self::sanitize_identifier(
				(string) $column_name,
				'where column',
			);

			if ( null === $value ) {
				$clauses[] = self::quote_identifier( $column_name ) . ' IS NULL';
				continue;
			}

			$parameter_name            = $param_prefix . '_' . $column_name;
			$clauses[]                 =
				self::quote_identifier( $column_name ) .
				' = :' .
				$parameter_name;
			$params[ $parameter_name ] = $value;
		}

		return array(
			'sql'    => implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * Prepare a safe ORDER BY fragment from column-direction pairs.
	 *
	 * @param array<string, string> $order_by Column-direction map.
	 * @return string SQL-ready ORDER BY clause or empty string.
	 * @since 1.0.1
	 */
	public static function order_by( array $order_by ): string {
		if ( empty( $order_by ) ) {
			return '';
		}

		$clauses = array();

		foreach ( $order_by as $column_name => $direction ) {
			$column_name = self::sanitize_identifier(
				(string) $column_name,
				'order by column',
			);
			$direction   = strtoupper( trim( (string) $direction ) );
			$direction   = 'ASC' === $direction ? 'ASC' : 'DESC';
			$clauses[]   = self::quote_identifier( $column_name ) . ' ' . $direction;
		}

		return empty( $clauses )
			? ''
			: ' ORDER BY ' . implode( ', ', $clauses );
	}
}
