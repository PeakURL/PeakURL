<?php
/**
 * Data store links trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Utils\Query;
use PeakURL\Utils\Security;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * LinksTrait — short-link CRUD methods for Store.
 *
 * @since 1.0.0
 */
trait LinksTrait {

	/**
	 * List short URLs with pagination, sorting, and optional search.
	 *
	 * Editors see only their own links; admins see all.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Query parameters for pagination/sorting/search.
	 * @return array<string, mixed> Paginated URL list with meta.
	 * @since 1.0.0
	 */
	public function list_urls( Request $request, array $query ): array {
		$pagination = Query::pagination( $query );
		$page       = $pagination['page'];
		$limit      = $pagination['limit'];
		$offset     = $pagination['offset'];
		$listing    = $this->build_url_listing_query( $request, $query );
		$count      = $this->count_url_listing_rows(
			$listing['where'],
			$listing['params'],
		);
		$rows       = $this->query_url_listing_rows(
			$listing['where'],
			$listing['params'],
			$listing['sortBy'],
			$listing['sortOrder'],
			$limit,
			$offset,
		);

		return array(
			'items' => $this->hydrate_url_listing_rows( $rows ),
			'meta'  => array(
				'page'       => $page,
				'limit'      => $limit,
				'totalItems' => $count,
				'totalPages' => max( 1, (int) ceil( $count / $limit ) ),
			),
		);
	}

	/**
	 * Export all accessible short URLs for the current user.
	 *
	 * Editors receive only their own links. Admins receive the full site
	 * export. Sorting and search can still be applied through the query map.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Optional sort/search parameters.
	 * @return array<string, mixed> Full accessible link export payload.
	 * @since 1.0.0
	 */
	public function export_urls( Request $request, array $query = array() ): array {
		$listing = $this->build_url_listing_query( $request, $query );
		$rows    = $this->query_url_listing_rows(
			$listing['where'],
			$listing['params'],
			$listing['sortBy'],
			$listing['sortOrder'],
		);
		$items   = $this->hydrate_url_listing_rows( $rows );

		return array(
			'items' => $items,
			'meta'  => array(
				'totalItems' => count( $items ),
			),
		);
	}

	/**
	 * Find a single short URL by its ID.
	 *
	 * @param Request $request Incoming HTTP request (for ownership check).
	 * @param string  $id      Short-URL row ID.
	 * @return array<string, mixed>|null Hydrated URL row or null.
	 * @since 1.0.0
	 */
	public function find_url( Request $request, string $id ): ?array {
		$user = $this->get_current_user( $request );
		$row  = $this->find_url_row( $id );

		if ( $row ) {
			$this->assert_record_access(
				$user,
				(string) ( $row['user_id'] ?? '' ),
				'view_own_links',
				'view_all_links',
				__( 'You do not have permission to view this link.', 'peakurl' ),
			);
		}

		return $row ? $this->hydrate_url_row( $row ) : null;
	}

	/**
	 * Resolve a short code to its redirect destination.
	 *
	 * Looks up the public URL row, records a click event, and
	 * returns the destination URL. Returns null for expired or
	 * inactive links.
	 *
	 * @param string  $id      Short code or alias.
	 * @param Request $request Incoming HTTP request (for click analytics).
	 * @return string|null Destination URL or null.
	 * @since 1.0.0
	 */
	public function resolve_redirect_destination(
		string $id,
		Request $request
	): ?string {
		$result = $this->resolve_public_link_access( $id, $request );

		return 'redirect' === $result['status']
			? (string) $result['location']
			: null;
	}

	/**
	 * Resolve the public access state for a short link.
	 *
	 * Determines whether the link should redirect immediately, prompt for a
	 * password, or stop because it is expired, inactive, or missing.
	 *
	 * @param string  $id      Short code or alias.
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Public access result.
	 * @since 1.0.0
	 */
	public function resolve_public_link_access(
		string $id,
		Request $request
	): array {
		$url = $this->find_public_access_url_row( $id );

		if ( ! $url ) {
			return array(
				'status' => 'not_found',
				'url'    => null,
			);
		}

		if ( $this->is_public_link_expired( $url ) ) {
			return array(
				'status' => 'expired',
				'url'    => $url,
			);
		}

		if ( 'active' !== (string) ( $url['status'] ?? 'active' ) ) {
			return array(
				'status' => 'unavailable',
				'url'    => $url,
			);
		}

		if ( $this->public_link_requires_password( $url ) ) {
			$cookie_name     = $this->public_link_access_cookie_name( $url );
			$expected_cookie = $this->public_link_access_cookie_value( $url );
			$cookie_value    = (string) $request->get_cookie( $cookie_name, '' );

			if (
				'' !== $cookie_value &&
				hash_equals( $expected_cookie, $cookie_value )
			) {
				$this->record_click( $url, $request );

				return array(
					'status'   => 'redirect',
					'url'      => $url,
					'location' => (string) $url['destination_url'],
				);
			}

			$password_attempt = trim(
				(string) $request->get_body_param( 'link_password', '' ),
			);

			if ( 'POST' === $request->get_method() ) {
				if ( '' === $password_attempt ) {
					return array(
						'status'  => 'password_required',
						'url'     => $url,
						'message' => 'Enter the password to open this link.',
					);
				}

				if (
					hash_equals(
						(string) ( $url['password_value'] ?? '' ),
						$password_attempt,
					)
				) {
					$request->queue_cookie(
						$cookie_name,
						$expected_cookie,
						$this->public_link_access_cookie_options(
							$request,
							$url,
						),
					);
					$this->record_click( $url, $request, true );

					return array(
						'status'   => 'redirect',
						'url'      => $url,
						'location' => (string) $url['destination_url'],
					);
				}

				return array(
					'status'  => 'password_invalid',
					'url'     => $url,
					'message' => 'The password for this link is incorrect.',
				);
			}

			return array(
				'status' => 'password_required',
				'url'    => $url,
			);
		}

		$this->record_click( $url, $request );

		return array(
			'status'   => 'redirect',
			'url'      => $url,
			'location' => (string) $url['destination_url'],
		);
	}

	/**
	 * Create a new short URL.
	 *
	 * Validates the destination URL, optional custom short code,
	 * UTM parameters, expiry, and password protection. Records an
	 * activity event on success.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Creation payload.
	 * @return array<string, mixed> Hydrated URL row.
	 *
	 * @throws ApiException On validation failure (422).
	 * @since 1.0.0
	 */
	public function create_url( Request $request, array $payload ): array {
		$user            = $this->assert_request_capability(
			$request,
			'create_links',
			'You do not have permission to create links.',
		);
		$destination_url = trim( (string) ( $payload['destinationUrl'] ?? '' ) );

		if (
			'' === $destination_url ||
			! filter_var( $destination_url, FILTER_VALIDATE_URL )
		) {
			throw new ApiException(
				__( 'A valid destination URL is required.', 'peakurl' ),
				422,
			);
		}

		$alias             = $this->sanitize_code(
			(string) ( $payload['alias'] ?? '' ),
		);
		$uses_custom_alias = '' !== $alias;

		if ( '' === $alias ) {
			$alias = $this->generate_short_code();
		}

		if ( $this->is_reserved_short_code( $alias ) ) {
			throw new ApiException(
				__( 'That short code is reserved by the application.', 'peakurl' ),
				422,
			);
		}

		if ( $this->short_code_exists( $alias ) ) {
			throw new ApiException( __( 'That short code is already in use.', 'peakurl' ), 422 );
		}

		$title = $this->resolve_new_url_title(
			$payload['title'] ?? '',
			$alias,
			$uses_custom_alias,
		);

		$id  = $this->generate_random_id();
		$now = $this->now();

		$this->db->insert(
			'urls',
			array(
				'id'              => $id,
				'user_id'         => $user['id'],
				'short_code'      => $alias,
				'alias'           => $alias,
				'title'           => '' !== $title ? $title : null,
				'destination_url' => $destination_url,
				'password_value'  =>
					'' !== trim( (string) ( $payload['password'] ?? '' ) )
						? trim( (string) $payload['password'] )
						: null,
				'expires_at'      => $this->normalize_datetime_value(
					$payload['expiresAt'] ?? null,
				),
				'status'          => $this->normalize_url_status(
					(string) ( $payload['status'] ?? 'active' ),
				),
				'utm_source'      => $this->nullable_string(
					$payload['utmSource'] ?? null,
				),
				'utm_medium'      => $this->nullable_string(
					$payload['utmMedium'] ?? null,
				),
				'utm_campaign'    => $this->nullable_string(
					$payload['utmCampaign'] ?? null,
				),
				'utm_term'        => $this->nullable_string(
					$payload['utmTerm'] ?? null,
				),
				'utm_content'     => $this->nullable_string(
					$payload['utmContent'] ?? null,
				),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
		);

		$this->record_activity(
			'link_created',
			null,
			(string) $user['id'],
			$id,
			array(
				'link' => array(
					'title'     => '' !== $title ? $title : null,
					'shortCode' => $alias,
				),
			),
		);

		return $this->hydrate_url_row( $this->find_url_row( $id ) );
	}

	/**
	 * Resolve the stored title for a newly created link.
	 *
	 * Custom aliases keep the existing PeakURL behavior of using the alias as
	 * the default title when the user does not enter one. Auto-generated short
	 * codes remain untitled so the UI can render the localized fallback label.
	 *
	 * @param mixed  $title             Raw request title value.
	 * @param string $alias             Final stored alias / short code.
	 * @param bool   $uses_custom_alias Whether the alias came from user input.
	 * @return string Normalized title value.
	 * @since 1.0.3
	 */
	private function resolve_new_url_title(
		$title,
		string $alias,
		bool $uses_custom_alias
	): string {
		$normalized_title = trim( (string) $title );

		if ( '' !== $normalized_title ) {
			return $normalized_title;
		}

		if ( $uses_custom_alias ) {
			return ucfirst( $alias );
		}

		return '';
	}

	/**
	 * Determine whether a public link is expired.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return bool True when the link is expired.
	 * @since 1.0.0
	 */
	private function is_public_link_expired( array $url ): bool {
		if ( 'expired' === (string) ( $url['status'] ?? '' ) ) {
			return true;
		}

		$expires_at = (string) ( $url['expires_at'] ?? '' );

		if ( '' === $expires_at ) {
			return false;
		}

		return $expires_at <= $this->now();
	}

	/**
	 * Determine whether a public link requires a password challenge.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return bool True when password protection is enabled.
	 * @since 1.0.0
	 */
	private function public_link_requires_password( array $url ): bool {
		return '' !== trim( (string) ( $url['password_value'] ?? '' ) );
	}

	/**
	 * Build the cookie name used for password-authorised public links.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return string Cookie name.
	 * @since 1.0.0
	 */
	private function public_link_access_cookie_name( array $url ): string {
		return 'peakurl_link_access_' . (string) ( $url['id'] ?? '' );
	}

	/**
	 * Build the cookie value used for password-authorised public links.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return string Cookie value hash.
	 * @since 1.0.0
	 */
	private function public_link_access_cookie_value( array $url ): string {
		return hash(
			'sha256',
			(string) ( $url['id'] ?? '' ) . '|' . (string) ( $url['password_value'] ?? '' ),
		);
	}

	/**
	 * Build cookie options for password-authorised public links.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $url     Raw URL row.
	 * @return array<string, mixed> Cookie options.
	 * @since 1.0.0
	 */
	private function public_link_access_cookie_options(
		Request $request,
		array $url
	): array {
		$options = Security::session_cookie_options(
			$this->config,
			$request,
			array(
				'samesite' => 'Lax',
			),
		);
		$max_age = 30 * 24 * 60 * 60;

		$expires_at = (string) ( $url['expires_at'] ?? '' );

		if ( '' !== $expires_at ) {
			$expires_timestamp = strtotime( $expires_at . ' UTC' );

			if ( false !== $expires_timestamp ) {
				$max_age = max( 60, $expires_timestamp - time() );
			}
		}

		$options['max-age'] = $max_age;
		$options['expires'] = gmdate( 'D, d M Y H:i:s T', time() + $max_age );

		return $options;
	}

	/**
	 * Bulk-create short URLs from an array of payloads.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Body with `urls` array.
	 * @return array<string, mixed> Result with created URLs and error count.
	 *
	 * @throws ApiException When the `urls` key is missing or empty (422).
	 * @since 1.0.0
	 */
	public function bulk_create_urls( Request $request, array $payload ): array {
		$this->assert_admin_request( $request );

		$entries = is_array( $payload['urls'] ?? null ) ? $payload['urls'] : array();
		$results = array();
		$errors  = array();

		foreach ( $entries as $entry ) {
			try {
				$results[] = $this->create_url(
					$request,
					is_array( $entry ) ? $entry : array(),
				);
			} catch ( ApiException $exception ) {
				$errors[] = array(
					'destinationUrl' => $entry['destinationUrl'] ?? '',
					'alias'          => $entry['alias'] ?? null,
					'error'          => $exception->getMessage(),
				);
			}
		}

		return array(
			'results' => $results,
			'errors'  => $errors,
		);
	}

	/**
	 * Update an existing short URL.
	 *
	 * Supports partial updates of destination, short code, title,
	 * status, UTM parameters, tags, expiry, and password.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $id      Short-URL row ID.
	 * @param array<string, mixed> $payload Partial update payload.
	 * @return array<string, mixed>|null Hydrated URL or null if not found.
	 *
	 * @throws ApiException On validation failure (422).
	 * @since 1.0.0
	 */
	public function update_url(
		Request $request,
		string $id,
		array $payload
	): ?array {
		$user     = $this->get_current_user( $request );
		$existing = $this->db->get_row_by(
			'urls',
			array( 'id' => $id ),
		);

		if ( ! $existing ) {
			return null;
		}

		$this->assert_record_access(
			$user,
			(string) ( $existing['user_id'] ?? '' ),
			'edit_own_links',
			'edit_all_links',
			__( 'You do not have permission to edit this link.', 'peakurl' ),
		);

		$updates = array();
		$params  = array( 'id' => $id );

		$field_map = array(
			'title'          => 'title',
			'destinationUrl' => 'destination_url',
			'password'       => 'password_value',
			'status'         => 'status',
		);

		foreach ( $field_map as $input_key => $column ) {
			if ( ! array_key_exists( $input_key, $payload ) ) {
				continue;
			}

			$value = $payload[ $input_key ];

			if ( 'destinationUrl' === $input_key ) {
				$value = trim( (string) $value );

				if ( '' === $value || ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
					throw new ApiException(
						__( 'A valid destination URL is required.', 'peakurl' ),
						422,
					);
				}
			}

			if ( 'status' === $input_key ) {
				$value = $this->normalize_url_status( (string) $value );
			}

			$updates[]         = $column . ' = :' . $column;
			$params[ $column ] = is_string( $value ) ? trim( $value ) : $value;
		}

		if ( array_key_exists( 'expiresAt', $payload ) ) {
			$updates[]            = 'expires_at = :expires_at';
			$params['expires_at'] = $this->normalize_datetime_value(
				$payload['expiresAt'],
			);
		}

		if (
			array_key_exists( 'alias', $payload ) &&
			'' !== trim( (string) $payload['alias'] )
		) {
			$alias = $this->sanitize_code( (string) $payload['alias'] );

			if ( $this->is_reserved_short_code( $alias ) ) {
				throw new ApiException(
					__( 'That short code is reserved by the application.', 'peakurl' ),
					422,
				);
			}

			if (
				$alias !== $existing['alias'] &&
				$this->short_code_exists( $alias )
			) {
				throw new ApiException(
					__( 'That short code is already in use.', 'peakurl' ),
					422,
				);
			}

			$updates[]            = 'alias = :alias';
			$updates[]            = 'short_code = :short_code';
			$params['alias']      = $alias;
			$params['short_code'] = $alias;
		}

		if ( empty( $updates ) ) {
			return $this->hydrate_url_row( $this->find_url_row( $id ) );
		}

		$updates[]            = 'updated_at = :updated_at';
		$params['updated_at'] = $this->now();

		$this->execute(
			'UPDATE urls SET ' . implode( ', ', $updates ) . ' WHERE id = :id',
			$params,
		);

		$this->record_activity(
			'link_updated',
			'Updated link ' . ( $params['alias'] ?? $existing['alias'] ) . '.',
			(string) $user['id'],
			$id,
		);

		return $this->hydrate_url_row( $this->find_url_row( $id ) );
	}

	/**
	 * Delete a short URL by ID.
	 *
	 * Also removes associated clicks and activity records.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @return bool True if a row was deleted.
	 * @since 1.0.0
	 */
	public function delete_url( Request $request, string $id ): bool {
		$user = $this->get_current_user( $request );
		$row  = $this->db->get_row_by(
			'urls',
			array( 'id' => $id ),
			array( 'id', 'user_id' ),
		);

		if ( ! $row ) {
			return false;
		}

		$this->assert_record_access(
			$user,
			(string) ( $row['user_id'] ?? '' ),
			'delete_own_links',
			'delete_all_links',
			__( 'You do not have permission to delete this link.', 'peakurl' ),
		);

		$this->db->begin_transaction();

		try {
			$this->db->delete(
				'audit_logs',
				array(
					'link_id' => $id,
				),
			);

			$deleted = $this->db->delete(
				'urls',
				array(
					'id' => $id,
				),
			) > 0;

			$this->db->commit();
		} catch ( \Throwable $exception ) {
			if ( $this->db->in_transaction() ) {
				$this->db->roll_back();
			}

			throw $exception;
		}

		return $deleted;
	}

	/**
	 * Bulk-delete short URLs by an array of IDs.
	 *
	 * @param Request            $request Incoming HTTP request.
	 * @param array<int, string> $ids     Short-URL row IDs.
	 * @return int Number of rows deleted.
	 * @since 1.0.0
	 */
	public function bulk_delete_urls( Request $request, array $ids ): int {
		$user = $this->get_current_user( $request );
		$ids  = Query::string_ids( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$allowed_ids = $ids;

		if ( ! $this->roles->user_can( $user, 'delete_all_links' ) ) {
			if ( ! $this->roles->user_can( $user, 'delete_own_links' ) ) {
				throw new ApiException(
					__( 'You do not have permission to delete links.', 'peakurl' ),
					403,
				);
			}

			$allowed_ids = array_map(
				'strval',
				$this->db->get_col_where_in(
					'urls',
					'id',
					'id',
					$ids,
					array(
						'user_id' => (string) $user['id'],
					),
				),
			);
		}

		if ( empty( $allowed_ids ) ) {
			return 0;
		}

		$this->db->begin_transaction();

		try {
			$this->db->delete_where_in(
				'audit_logs',
				'link_id',
				$allowed_ids,
			);

			$deleted_count = $this->db->delete_where_in(
				'urls',
				'id',
				$allowed_ids,
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
	 * Build shared query parts for URL listing and export requests.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Raw query parameters.
	 * @return array<string, mixed> Prepared SQL fragments and parameters.
	 * @since 1.0.0
	 */
	private function build_url_listing_query( Request $request, array $query ): array {
		$user       = $this->get_current_user( $request );
		$search     = trim( (string) ( $query['search'] ?? '' ) );
		$sort_by    = Query::sort_column(
			$this->get_url_listing_sort_map(),
			$query['sortBy'] ?? 'createdAt',
			'u.created_at',
		);
		$sort_order = Query::sort_direction(
			$query['sortOrder'] ?? 'desc',
		);
		$conditions = array();
		$params     = array();

		if ( '' !== $search ) {
			$conditions[]                 = '(
	                u.title LIKE :search_title ESCAPE \'\\\\\'
	                OR u.alias LIKE :search_alias ESCAPE \'\\\\\'
	                OR u.short_code LIKE :search_short_code ESCAPE \'\\\\\'
	                OR u.destination_url LIKE :search_destination ESCAPE \'\\\\\'
	            )';
			$search_like                  = '%' . $this->db->esc_like( $search ) . '%';
			$params['search_title']       = $search_like;
			$params['search_alias']       = $search_like;
			$params['search_short_code']  = $search_like;
			$params['search_destination'] = $search_like;
		}

		$this->add_link_visibility_scope( $user, $conditions, $params, 'u' );

		return array(
			'where'     => ! empty( $conditions )
				? 'WHERE ' . implode( ' AND ', $conditions )
				: '',
			'params'    => $params,
			'sortBy'    => $sort_by,
			'sortOrder' => $sort_order,
		);
	}

	/**
	 * Count rows for a prepared URL listing query.
	 *
	 * @param string               $where  Prepared WHERE clause.
	 * @param array<string, mixed> $params Query parameters.
	 * @return int Total matching rows.
	 * @since 1.0.0
	 */
	private function count_url_listing_rows( string $where, array $params ): int {
		return (int) $this->query_value(
			'SELECT COUNT(*)
	            FROM urls u
	            ' . $where,
			$params,
		);
	}

	/**
	 * Query URL rows for a prepared listing/export request.
	 *
	 * @param string               $where      Prepared WHERE clause.
	 * @param array<string, mixed> $params     Query parameters.
	 * @param string               $sort_by    Safe SQL sort column.
	 * @param string               $sort_order Safe SQL sort direction.
	 * @param int|null             $limit      Optional LIMIT value.
	 * @param int|null             $offset     Optional OFFSET value.
	 * @return array<int, array<string, mixed>> Raw URL rows with click stats.
	 * @since 1.0.0
	 */
	private function query_url_listing_rows(
		string $where,
		array $params,
		string $sort_by,
		string $sort_order,
		?int $limit = null,
		?int $offset = null
	): array {
		$sql = 'SELECT
	                u.*,
	                COALESCE(stats.clicks, 0) AS click_count,
	                COALESCE(stats.unique_clicks, 0) AS unique_click_count
	            FROM urls u
	            LEFT JOIN (
	                SELECT
	                    url_id,
	                    COUNT(*) AS clicks,
	                    COUNT(DISTINCT COALESCE(NULLIF(visitor_hash, \'\'), id)) AS unique_clicks
	                FROM clicks
	                GROUP BY url_id
	            ) stats ON stats.url_id = u.id
	            ' .
			$where .
			Query::order_by_clause( $sort_by, $sort_order );

		if ( null !== $limit && null !== $offset ) {
			$sql .= Query::limit_offset_clause( $limit, $offset );
		}

		return $this->query_all( $sql, $params );
	}

	/**
	 * Hydrate a list of raw URL rows into API-ready items.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw URL rows.
	 * @return array<int, array<string, mixed>> Hydrated URL items.
	 * @since 1.0.0
	 */
	private function hydrate_url_listing_rows( array $rows ): array {
		return array_map(
			fn( array $row ): array => $this->hydrate_url_row( $row ),
			$rows,
		);
	}

	/**
	 * Return the allowed URL list sort keys.
	 *
	 * @return array<string, string> API sort keys mapped to SQL columns.
	 * @since 1.0.0
	 */
	private function get_url_listing_sort_map(): array {
		return array(
			'createdAt'    => 'u.created_at',
			'updatedAt'    => 'u.updated_at',
			'title'        => 'u.title',
			'clicks'       => 'click_count',
			'uniqueClicks' => 'unique_click_count',
			'status'       => 'u.status',
			'shortCode'    => 'u.short_code',
		);
	}
}
