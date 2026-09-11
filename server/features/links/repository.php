<?php
/**
 * Link persistence and data access queries.
 *
 * @package PeakURL\Features\Links
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Links;

use PeakURL\Api\LinksApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Query;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Repository — Link database operations, query builders, and row lookups.
 *
 * @since 1.0.0
 */
class Repository {

	/**
	 * Database wrapper instance.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Low-level links query API.
	 *
	 * @var LinksApi
	 * @since 1.0.0
	 */
	private LinksApi $links_api;

	/**
	 * Authorization service.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Create a new Link Data query handler.
	 *
	 * @param PeakURL_DB         $db            Database wrapper.
	 * @param LinksApi           $links_api     Low-level links query helper.
	 * @param Authorization|null $authorization Optional authorization helper.
	 * @since 1.0.0
	 */
	public function __construct(
		PeakURL_DB $db,
		LinksApi $links_api,
		?Authorization $authorization = null
	) {
		$this->db            = $db;
		$this->links_api     = $links_api;
		$this->authorization = $authorization ?? new Authorization();
	}

	/**
	 * Get the database wrapper instance.
	 *
	 * @return PeakURL_DB
	 * @since 1.0.0
	 */
	public function get_db(): PeakURL_DB {
		return $this->db;
	}

	/**
	 * Get the low-level links query API instance.
	 *
	 * @return LinksApi
	 * @since 1.0.0
	 */
	public function get_links_api(): LinksApi {
		return $this->links_api;
	}

	/**
	 * Find a URL row by primary ID, short code, or alias with click aggregates.
	 *
	 * @param string $id URL ID, short code, or alias.
	 * @return array<string, mixed>|null Formatted database row or null.
	 * @since 1.0.0
	 */
	public function find_url_row( string $id ): ?array {
		return $this->links_api->get_link_by_identifier( $id );
	}

	/**
	 * Find raw link row by primary ID directly without joining click stats.
	 *
	 * @param string $id Link ID.
	 * @return array<string, mixed>|null Link row or null.
	 * @since 1.0.0
	 */
	public function get_link_by_id( string $id ): ?array {
		return $this->db->get_row_by(
			'urls',
			array( 'id' => $id ),
		);
	}

	/**
	 * Find a public-facing URL row by short code or alias without status/expiry filtering.
	 *
	 * @param string $code Short code or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function find_link_access_row( string $code ): ?array {
		return $this->links_api->get_link_access_row( $code );
	}

	/**
	 * Find a public-facing URL row by short code or alias with active status and expiry check.
	 *
	 * @param string $code Short code or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function find_public_url_row( string $code ): ?array {
		return $this->links_api->get_public_link_by_code( $code, \PeakURL\Utils\Date::now() );
	}

	/**
	 * Determine whether a short code or alias is already stored in the links table.
	 *
	 * @param string $code Short code or alias to check.
	 * @return bool True if already taken.
	 * @since 1.0.0
	 */
	public function short_code_exists( string $code ): bool {
		return $this->links_api->short_code_exists( $code );
	}

	/**
	 * Apply user access and ownership filters to URL query conditions.
	 *
	 * @param array<string, mixed>      $user        Current user.
	 * @param array<int, string>        $conditions  SQL conditions array.
	 * @param array<string, string|int> $params      Bound parameter array.
	 * @param string                    $table_alias URL table alias.
	 * @return void
	 *
	 * @throws ApiException When the user cannot view links.
	 * @since 1.0.0
	 */
	public function apply_user_filter(
		array $user,
		array &$conditions,
		array &$params,
		string $table_alias = 'u'
	): void {
		if ( $this->authorization->can_view_all_links( $user ) ) {
			return;
		}

		if ( $this->authorization->can_view_own_links( $user ) ) {
			$conditions[]             = $table_alias . '.user_id = :filter_user_id';
			$params['filter_user_id'] = (string) ( $user['id'] ?? '' );
			return;
		}

		throw new ApiException(
			__( 'You do not have permission to view links.', 'peakurl' ),
			403,
		);
	}

	/**
	 * Prepare shared listing query fragments and bound parameters.
	 *
	 * @param array<string, mixed> $user            Current authenticated user row.
	 * @param array<string, mixed> $query           Query parameters.
	 * @param callable|null        $filter_callback Optional callback to apply user filter.
	 * @param callable             $period_resolver Callback to resolve analytics bounds.
	 * @return array<string, mixed> Prepared query details.
	 * @since 1.0.0
	 */
	public function prepare_url_listing_query(
		array $user,
		array $query,
		?callable $filter_callback,
		callable $period_resolver
	): array {
		$search       = trim( (string) ( $query['search'] ?? '' ) );
		$sort_by      = Query::sort_column(
			$this->get_url_sort_map(),
			$query['sortBy'] ?? 'createdAt',
			'u.created_at',
		);
		$sort_order   = Query::sort_direction(
			$query['sortOrder'] ?? 'desc',
		);
		$conditions   = array();
		$params       = array();
		$stats_params = $this->get_url_listing_stats_params( $query, $period_resolver );

		if ( '' !== $search ) {
			$conditions[]                 = '(
	                u.title LIKE :search_title ESCAPE \'\\\\\'
	                OR LOWER(u.alias) LIKE :search_alias ESCAPE \'\\\\\'
	                OR LOWER(u.short_code) LIKE :search_short_code ESCAPE \'\\\\\'
	                OR u.destination_url LIKE :search_destination ESCAPE \'\\\\\'
	            )';
			$search_like                  = '%' . $this->db->esc_like( $search ) . '%';
			$search_code_like             = '%' .
				strtolower( $this->db->esc_like( $search ) ) .
				'%';
			$params['search_title']       = $search_like;
			$params['search_alias']       = $search_code_like;
			$params['search_short_code']  = $search_code_like;
			$params['search_destination'] = $search_like;
		}

		$status = trim( (string) ( $query['status'] ?? '' ) );

		if ( 'trashed' === $status ) {
			$conditions[]            = 'u.status = :status_filter';
			$params['status_filter'] = 'trashed';
		} elseif ( 'active' === $status ) {
			$conditions[]            = 'u.status = :status_filter';
			$params['status_filter'] = 'active';
		} elseif ( 'inactive' === $status ) {
			$conditions[]            = 'u.status = :status_filter';
			$params['status_filter'] = 'inactive';
		} elseif ( 'all' === $status || '' === $status ) {
			$conditions[]                    = 'u.status != :status_filter_exclude';
			$params['status_filter_exclude'] = 'trashed';
		}

		if ( null !== $filter_callback ) {
			$filter_callback( $user, $conditions, $params, 'u' );
		} else {
			$this->apply_user_filter( $user, $conditions, $params, 'u' );
		}

		return array(
			'where'       => ! empty( $conditions )
				? 'WHERE ' . implode( ' AND ', $conditions )
				: '',
			'params'      => $params,
			'statsParams' => $stats_params,
			'sortBy'      => $sort_by,
			'sortOrder'   => $sort_order,
		);
	}

	/**
	 * Resolve optional click-stat query bounds for link listing.
	 *
	 * @param array<string, mixed> $query           Raw listing query parameters.
	 * @param callable             $period_resolver Callback to resolve date period bounds.
	 * @return array<string, string> Bound parameters for click stats.
	 * @since 1.2.1
	 */
	public function get_url_listing_stats_params(
		array $query,
		callable $period_resolver
	): array {
		$range = trim( (string) ( $query['range'] ?? '' ) );

		if ( '' === $range ) {
			return array();
		}

		$period = $period_resolver(
			$range,
			(string) ( $query['from'] ?? '' ),
			(string) ( $query['to'] ?? '' ),
		);
		$params = array();

		if ( null !== ( $period['start_at'] ?? null ) ) {
			$params['stats_start_at'] = (string) $period['start_at'];
		}

		if ( null !== ( $period['end_at'] ?? null ) ) {
			$params['stats_end_at'] = (string) $period['end_at'];
		}

		return $params;
	}

	/**
	 * Return the allowed URL list sort keys.
	 *
	 * @return array<string, string> API sort keys mapped to SQL columns.
	 * @since 1.0.0
	 */
	public function get_url_sort_map(): array {
		return array(
			'createdAt'    => 'u.created_at',
			'updatedAt'    => 'u.updated_at',
			'title'        => 'u.title',
			'clicks'       => 'click_count',
			'uniqueClicks' => 'unique_click_count',
			'status'       => 'u.status',
			'shortCode'    => 'u.short_code',
			'alias'        => 'u.alias',
		);
	}

	/**
	 * Count matching link rows for a listing query.
	 *
	 * @param string               $where  Prepared WHERE clause.
	 * @param array<string, mixed> $params Bound parameters.
	 * @return int Total matching rows.
	 * @since 1.0.0
	 */
	public function count_url_listing_rows( string $where, array $params ): int {
		return $this->links_api->count_links_for_listing( $where, $params );
	}

	/**
	 * Query URL rows with click stats for a listing or export request.
	 *
	 * @param string                $where        Prepared WHERE clause.
	 * @param array<string, mixed>  $params       Bound parameters.
	 * @param string                $sort_by      Safe sort column.
	 * @param string                $sort_order   Safe sort direction.
	 * @param int|null              $limit        Optional limit.
	 * @param int|null              $offset       Optional offset.
	 * @param array<string, string> $stats_params Bound click-stat period parameters.
	 * @return array<int, array<string, mixed>> Link rows.
	 * @since 1.0.0
	 */
	public function query_url_listing_rows(
		string $where,
		array $params,
		string $sort_by,
		string $sort_order,
		?int $limit = null,
		?int $offset = null,
		array $stats_params = array()
	): array {
		return $this->links_api->query_link_rows(
			$where,
			$params,
			$sort_by,
			$sort_order,
			$limit,
			$offset,
			$stats_params,
		);
	}

	/**
	 * Calculate total click aggregates for a listing query.
	 *
	 * @param string                $where        Prepared WHERE clause.
	 * @param array<string, mixed>  $params       Query parameters.
	 * @param array<string, string> $stats_params Bound click-stat parameters.
	 * @return array<string, int> Click aggregates.
	 * @since 1.5.2
	 */
	public function aggregate_link_stats(
		string $where,
		array $params,
		array $stats_params
	): array {
		return $this->links_api->aggregate_link_stats(
			$where,
			$params,
			$stats_params,
		);
	}

	/**
	 * Calculate total clicks for a listing query within a date window.
	 *
	 * @param string                $where             Prepared WHERE clause.
	 * @param array<string, mixed>  $params            Query parameters.
	 * @param array<string, string> $last_stats_params Period boundaries.
	 * @return array<string, int> Click counts.
	 * @since 1.5.2
	 */
	public function aggregate_link_clicks(
		string $where,
		array $params,
		array $last_stats_params
	): array {
		return $this->links_api->aggregate_link_clicks(
			$where,
			$params,
			$last_stats_params,
		);
	}

	/**
	 * Count trashed links for the current user.
	 *
	 * @param array<string, mixed> $user            Current user row.
	 * @param callable|null        $filter_callback Optional user filter callback.
	 * @return int Number of trashed links.
	 * @since 1.6.0
	 */
	public function count_trashed_links(
		array $user,
		?callable $filter_callback = null
	): int {
		$conditions = array( "u.status = 'trashed'" );
		$params     = array();

		if ( null !== $filter_callback ) {
			$filter_callback( $user, $conditions, $params, 'u' );
		} else {
			$this->apply_user_filter( $user, $conditions, $params, 'u' );
		}

		return (int) $this->db->get_var(
			'SELECT COUNT(*) FROM urls u WHERE ' . implode( ' AND ', $conditions ),
			$params,
		);
	}

	/**
	 * Insert a new short URL row.
	 *
	 * @param array<string, mixed> $data Row data to insert.
	 * @return void
	 * @since 1.0.0
	 */
	public function insert_url( array $data ): void {
		$this->db->insert( 'urls', $data );
	}

	/**
	 * Execute an update on an existing short URL row.
	 *
	 * @param string               $id      URL ID.
	 * @param array<int, string>   $updates SQL fragment updates.
	 * @param array<string, mixed> $params  Bound parameter map.
	 * @return void
	 * @since 1.0.0
	 */
	public function update_url_fields(
		string $id,
		array $updates,
		array $params
	): void {
		$params['id'] = $id;

		$this->db->query(
			'UPDATE urls SET ' . implode( ', ', $updates ) . ' WHERE id = :id',
			$params,
		);
	}

	/**
	 * Soft delete a short URL by marking it as trashed.
	 *
	 * @param string $id         URL ID.
	 * @param string $updated_at Timestamp string.
	 * @return bool True if row was updated.
	 * @since 1.0.0
	 */
	public function trash_url( string $id, string $updated_at ): bool {
		return $this->db->update(
			'urls',
			array(
				'status'     => 'trashed',
				'updated_at' => $updated_at,
			),
			array( 'id' => $id ),
		) > 0;
	}

	/**
	 * Restore a trashed short URL by marking it as active.
	 *
	 * @param string $id         URL ID.
	 * @param string $updated_at Timestamp string.
	 * @return bool True if row was updated.
	 * @since 1.6.0
	 */
	public function restore_url( string $id, string $updated_at ): bool {
		return $this->db->update(
			'urls',
			array(
				'status'     => 'active',
				'updated_at' => $updated_at,
			),
			array( 'id' => $id ),
		) > 0;
	}

	/**
	 * Permanently delete a short URL row and related click data.
	 *
	 * @param string $id URL row ID.
	 * @return bool True if row was deleted.
	 * @since 1.0.0
	 */
	public function delete_url_permanent( string $id ): bool {
		$this->db->begin_transaction();

		try {
			$this->db->update(
				'audit_logs',
				array( 'link_id' => null ),
				array( 'link_id' => $id ),
			);

			$this->db->delete(
				'clicks',
				array( 'url_id' => $id ),
			);

			$deleted = $this->db->delete(
				'urls',
				array( 'id' => $id ),
			) > 0;

			$this->db->commit();

			return $deleted;
		} catch ( \Throwable $exception ) {
			if ( $this->db->in_transaction() ) {
				$this->db->roll_back();
			}

			throw $exception;
		}
	}

	/**
	 * Find a subset of IDs owned by the given user.
	 *
	 * @param array<int, string> $ids     Target URL IDs.
	 * @param string             $user_id User primary ID.
	 * @return array<int, string> Allowed IDs.
	 * @since 1.0.0
	 */
	public function get_allowed_ids_for_user( array $ids, string $user_id ): array {
		return array_map(
			'strval',
			$this->db->get_col_where_in(
				'urls',
				'id',
				'id',
				$ids,
				array( 'user_id' => $user_id ),
			),
		);
	}

	/**
	 * Retrieve multiple URL rows by their IDs.
	 *
	 * @param array<int, string> $ids List of URL IDs.
	 * @return array<int, array<string, mixed>> Matching URL rows.
	 * @since 1.0.0
	 */
	public function get_links_by_ids( array $ids ): array {
		return $this->db->get_results_where_in(
			'urls',
			'id',
			$ids,
		);
	}

	/**
	 * Permanently delete multiple URL rows and cascade audit/clicks.
	 *
	 * @param array<int, string> $ids List of URL IDs to delete.
	 * @return int Number of rows deleted.
	 * @since 1.0.0
	 */
	public function bulk_delete_permanent( array $ids ): int {
		$this->db->begin_transaction();

		try {
			$this->db->update_where_in(
				'audit_logs',
				array( 'link_id' => null ),
				'link_id',
				$ids,
			);

			$this->db->delete_where_in(
				'clicks',
				'url_id',
				$ids,
			);

			$deleted_count = $this->db->delete_where_in(
				'urls',
				'id',
				$ids,
			);

			$this->db->commit();

			return $deleted_count;
		} catch ( \Throwable $exception ) {
			if ( $this->db->in_transaction() ) {
				$this->db->roll_back();
			}

			throw $exception;
		}
	}

	/**
	 * Retrieve all trashed links accessible to the current user.
	 *
	 * @param array<string, mixed> $user            Current user row.
	 * @param callable|null        $filter_callback Optional user filter callback.
	 * @return array<int, array<string, mixed>> Trashed URL rows.
	 * @since 1.6.0
	 */
	public function get_all_trashed_links(
		array $user,
		?callable $filter_callback = null
	): array {
		$conditions = array( "u.status = 'trashed'" );
		$params     = array();

		if ( null !== $filter_callback ) {
			$filter_callback( $user, $conditions, $params, 'u' );
		} else {
			$this->apply_user_filter( $user, $conditions, $params, 'u' );
		}

		return $this->db->get_results(
			'SELECT u.* FROM urls u WHERE ' . implode( ' AND ', $conditions ),
			$params,
		);
	}

	/**
	 * Retrieve all links accessible to the current user for clearing.
	 *
	 * @param array<string, mixed> $user            Current user row.
	 * @param callable|null        $filter_callback Optional user filter callback.
	 * @return array<int, array<string, mixed>> All URL rows.
	 * @since 1.5.3
	 */
	public function get_all_accessible_links(
		array $user,
		?callable $filter_callback = null
	): array {
		$conditions = array();
		$params     = array();

		if ( null !== $filter_callback ) {
			$filter_callback( $user, $conditions, $params, 'u' );
		} else {
			$this->apply_user_filter( $user, $conditions, $params, 'u' );
		}

		$where = ! empty( $conditions )
			? 'WHERE ' . implode( ' AND ', $conditions )
			: '';

		return $this->db->get_results(
			'SELECT u.* FROM urls u ' . $where,
			$params,
		);
	}
}
