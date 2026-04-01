<?php
/**
 * Shared query sanitization helpers.
 *
 * Provides static utility methods for pagination, sorting, and
 * SQL clause construction used throughout the data layer.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Utils;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Query — stateless helpers for building safe SQL fragments.
 *
 * All methods are static; the class is never instantiated.
 *
 * @since 1.0.0
 */
class Query {

	/**
	 * Derive page, limit, and offset values from a query-parameter map.
	 *
	 * Clamps limit between 1 and `$max_limit`, and ensures page >= 1.
	 *
	 * @param array<string, mixed> $query         Raw query parameters (e.g. from Request).
	 * @param int                  $default_limit Rows per page when `limit` is absent.
	 * @param int                  $max_limit     Upper bound for the limit value.
	 * @return array<string, int>  Associative array with keys `page`, `limit`, `offset`.
	 * @since 1.0.0
	 */
	public static function pagination(
		array $query,
		int $default_limit = 25,
		int $max_limit = 100
	): array {
		$page  = max( 1, (int) ( $query['page'] ?? 1 ) );
		$limit = max(
			1,
			min( $max_limit, (int) ( $query['limit'] ?? $default_limit ) ),
		);

		return array(
			'page'   => $page,
			'limit'  => $limit,
			'offset' => ( $page - 1 ) * $limit,
		);
	}

	/**
	 * Resolve a sort column from a whitelist map.
	 *
	 * Looks up the requested value in `$sort_map`. Returns the mapped
	 * column name or the `$fallback` when the value is not recognized.
	 *
	 * @param array<string, string> $sort_map Allowed sort keys → SQL column names.
	 * @param mixed                 $value    Raw sort-key value from the request.
	 * @param string                $fallback Column to use when `$value` is invalid.
	 * @return string Safe SQL column identifier.
	 * @since 1.0.0
	 */
	public static function sort_column(
		array $sort_map,
		$value,
		string $fallback
	): string {
		$requested = (string) $value;
		return $sort_map[ $requested ] ?? $fallback;
	}

	/**
	 * Normalize a sort direction to `ASC` or `DESC`.
	 *
	 * @param mixed  $value    Raw direction value (e.g. `"asc"` or `"desc"`).
	 * @param string $fallback Direction used when `$value` is not `"asc"`.
	 * @return string `'ASC'` or uppercase `$fallback`.
	 * @since 1.0.0
	 */
	public static function sort_direction(
		$value,
		string $fallback = 'DESC'
	): string {
		return 'asc' === strtolower( (string) $value )
			? 'ASC'
			: strtoupper( $fallback );
	}

	/**
	 * Build an `ORDER BY` SQL clause.
	 *
	 * @param string $column    Safe column identifier.
	 * @param string $direction `ASC` or `DESC`.
	 * @return string SQL fragment including a leading space.
	 * @since 1.0.0
	 */
	public static function order_by_clause(
		string $column,
		string $direction
	): string {
		return ' ORDER BY ' . $column . ' ' . $direction;
	}

	/**
	 * Build a `LIMIT … OFFSET …` SQL clause.
	 *
	 * Clamps limit to >= 1 and offset to >= 0 for safety.
	 *
	 * @param int $limit  Maximum rows to return.
	 * @param int $offset Number of rows to skip.
	 * @return string SQL fragment including a leading space.
	 * @since 1.0.0
	 */
	public static function limit_offset_clause( int $limit, int $offset ): string {
		return ' LIMIT ' . max( 1, $limit ) . ' OFFSET ' . max( 0, $offset );
	}

	/**
	 * Sanitize an array of values into unique, non-empty string IDs.
	 *
	 * Trims each value, discards blanks, removes duplicates, and
	 * re-indexes the resulting array.
	 *
	 * @param array<int, mixed> $values Raw ID values.
	 * @return array<int, string> Cleaned, unique string IDs.
	 * @since 1.0.0
	 */
	public static function string_ids( array $values ): array {
		$items = array();

		foreach ( $values as $value ) {
			$item = trim( (string) $value );

			if ( '' === $item ) {
				continue;
			}

			$items[] = $item;
		}

		return array_values( array_unique( $items ) );
	}
}
