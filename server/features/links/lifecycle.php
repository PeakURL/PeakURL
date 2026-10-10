<?php
/**
 * Link lifecycle domain service.
 *
 * Implements link mutation operations: update, trash, restore,
 * permanent deletion, bulk lifecycle operations, and related
 * lifecycle events and cache invalidation.
 *
 * @package PeakURL\Features\Links
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Links;

use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\Query;
use PeakURL\Services\SocialPreview;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Lifecycle — Handles link mutation, status transitions, and bulk lifecycle actions.
 *
 * @since 1.7.2
 */
class Lifecycle {

	/**
	 * Link repository handler.
	 *
	 * @var Repository
	 * @since 1.7.2
	 */
	private Repository $repository;

	/**
	 * Link validation helper.
	 *
	 * @var Validator
	 * @since 1.7.2
	 */
	private Validator $validator;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.7.2
	 */
	private AuthService $auth_service;

	/**
	 * Analytics domain service.
	 *
	 * @var AnalyticsService
	 * @since 1.7.2
	 */
	private AnalyticsService $analytics_service;

	/**
	 * Webhooks domain service.
	 *
	 * @var WebhooksService
	 * @since 1.7.2
	 */
	private WebhooksService $webhooks_service;

	/**
	 * Social preview metadata helper.
	 *
	 * @var SocialPreview
	 * @since 1.7.2
	 */
	private SocialPreview $social_preview;

	/**
	 * Roles and capabilities registry.
	 *
	 * @var Roles
	 * @since 1.7.2
	 */
	private Roles $roles;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.7.2
	 */
	private Authorization $authorization;

	/**
	 * Link formatter callback for API payloads.
	 *
	 * @var callable
	 * @since 1.7.2
	 */
	private $link_formatter;

	/**
	 * Optional targeted health check scheduler callback.
	 *
	 * @var callable|null
	 * @since 1.7.2
	 */
	private $health_scheduler;

	/**
	 * Optional health formatter callback.
	 *
	 * @var callable|null
	 * @since 1.7.2
	 */
	private $health_formatter;

	/**
	 * Create a new Lifecycle instance.
	 *
	 * @param Repository        $repository        Repository handler.
	 * @param Validator         $validator         Validator handler.
	 * @param AuthService       $auth_service      Authentication domain service.
	 * @param AnalyticsService  $analytics_service Analytics domain service.
	 * @param WebhooksService   $webhooks_service  Webhooks domain service.
	 * @param SocialPreview     $social_preview    Social preview helper.
	 * @param Roles             $roles             Roles registry.
	 * @param Authorization     $authorization     Authorization helper.
	 * @param callable          $link_formatter    Link formatter callback.
	 * @param callable|null     $health_scheduler  Optional targeted health check scheduler callback.
	 * @param callable|null     $health_formatter  Optional health payload formatter callback.
	 * @since 1.7.2
	 */
	public function __construct(
		Repository $repository,
		Validator $validator,
		AuthService $auth_service,
		AnalyticsService $analytics_service,
		WebhooksService $webhooks_service,
		SocialPreview $social_preview,
		Roles $roles,
		Authorization $authorization,
		callable $link_formatter,
		?callable $health_scheduler = null,
		?callable $health_formatter = null
	) {
		$this->repository        = $repository;
		$this->validator         = $validator;
		$this->auth_service      = $auth_service;
		$this->analytics_service = $analytics_service;
		$this->webhooks_service  = $webhooks_service;
		$this->social_preview    = $social_preview;
		$this->roles             = $roles;
		$this->authorization     = $authorization;
		$this->link_formatter    = $link_formatter;
		$this->health_scheduler  = $health_scheduler;
		$this->health_formatter  = $health_formatter;
	}

	/**
	 * Update an existing short URL.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $id      Short-URL row ID.
	 * @param array<string, mixed> $payload Partial update payload.
	 * @return array<string, mixed>|null Formatted URL or null if not found.
	 *
	 * @throws ApiException On validation failure (422) or unauthorized (403).
	 * @since 1.7.2
	 */
	public function update_url(
		Request $request,
		string $id,
		array $payload
	): ?array {
		$user     = $this->auth_service->get_current_user( $request );
		$existing = $this->repository->get_link_by_id( $id );

		if ( ! $existing ) {
			return null;
		}

		$this->authorization->validate_capability(
			$user,
			'edit_links',
			__( 'You do not have permission to edit links.', 'peakurl' ),
		);

		$owner_id = (string) ( $existing['user_id'] ?? '' );
		$is_owner = (string) ( $user['id'] ?? '' ) === $owner_id;
		$is_admin = $this->roles->is_admin( $user );

		if ( ! $is_admin && ! $is_owner ) {
			$owner_role = $this->repository->get_user_role( $owner_id );
			if ( 'admin' !== $owner_role ) {
				throw new ApiException(
					__( 'You do not have permission to edit this link.', 'peakurl' ),
					403,
				);
			}
		}

		$payload = $this->filter_link_payload(
			'pre_update_link',
			$payload,
			$id,
			$existing,
			$request,
			$user,
		);

		$destination_changed = false;

		if ( array_key_exists( 'destinationUrl', $payload ) ) {
			$cleaned_dest = $this->validator->clean_destination( $payload['destinationUrl'] );
			$current_dest = (string) ( $existing['destination_url'] ?? '' );
			if ( $cleaned_dest !== $current_dest ) {
				$destination_changed = true;
			}
			$payload['destinationUrl'] = $cleaned_dest;
		}

		$updates = array();
		$params  = array();

		$field_map = array(
			'title'          => 'title',
			'destinationUrl' => 'destination_url',
			'status'         => 'status',
		);

		foreach ( $field_map as $input_key => $column ) {
			if ( ! array_key_exists( $input_key, $payload ) ) {
				continue;
			}

			$value = $payload[ $input_key ];

			if ( 'title' === $input_key ) {
				$value = is_string( $value )
					? trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) )
					: $value;
			}

			if ( 'destinationUrl' === $input_key ) {
				$value = $this->validator->clean_destination( $value );
			}

			if ( 'status' === $input_key ) {
				$value = $this->validator->normalize_url_status( (string) $value );
			}

			$updates[]         = $column . ' = :' . $column;
			$params[ $column ] = is_string( $value ) ? trim( $value ) : $value;
		}

		$social_preview = $this->validator->normalize_link_social_preview(
			$payload,
			$this->social_preview,
			false,
		);

		foreach (
			array(
				'social_title'       => 'title',
				'social_description' => 'description',
			) as $column => $value_key
		) {
			if ( ! array_key_exists( $column, $social_preview['columns'] ) ) {
				continue;
			}

			$updates[]         = $column . ' = :' . $column;
			$params[ $column ] = $social_preview[ $value_key ] ?? null;
		}

		$social_image_file       = $request->get_file( 'socialImage' );
		$has_social_image_upload = $this->validator->has_link_upload( $social_image_file );
		$has_social_image_url    = array_key_exists( 'socialImageUrl', $payload );
		$remove_social_image     = ! empty( $payload['removeSocialImage'] );
		$delete_social_image     = '';

		$social_image_url = $has_social_image_url
			? $this->validator->normalize_link_social_image_url(
				$payload['socialImageUrl'],
				$this->social_preview,
			)
			: null;

		if (
			$has_social_image_upload &&
			$has_social_image_url &&
			null !== $social_image_url
		) {
			throw new ApiException(
				__(
					'Provide either socialImage or socialImageUrl, not both.',
					'peakurl',
				),
				422,
			);
		}

		if ( $remove_social_image ) {
			try {
				$updates[]                   = 'social_image_path = :social_image_path';
				$params['social_image_path'] = $this->social_preview->save_link_image(
					$id,
					null,
					true,
					(string) ( $existing['social_image_path'] ?? '' ),
				);
			} catch ( \RuntimeException $exception ) {
				throw new ApiException( $exception->getMessage(), 422 );
			}

			$updates[]                  = 'social_image_url = :social_image_url';
			$params['social_image_url'] = null;
		} elseif ( $has_social_image_upload ) {
			try {
				$updates[]                   = 'social_image_path = :social_image_path';
				$params['social_image_path'] = $this->social_preview->save_link_image(
					$id,
					$social_image_file,
					false,
					(string) ( $existing['social_image_path'] ?? '' ),
				);
			} catch ( \RuntimeException $exception ) {
				throw new ApiException( $exception->getMessage(), 422 );
			}

			$updates[]                  = 'social_image_url = :social_image_url';
			$params['social_image_url'] = null;
		} elseif ( $has_social_image_url ) {
			$updates[]                  = 'social_image_url = :social_image_url';
			$params['social_image_url'] = $social_image_url;

			if ( null !== $social_image_url ) {
				$delete_social_image = trim(
					(string) ( $existing['social_image_path'] ?? '' ),
				);

				$updates[]                   = 'social_image_path = :social_image_path';
				$params['social_image_path'] = null;
			}
		}

		$clear_password = ! empty( $payload['clearPassword'] );

		if ( $clear_password ) {
			$updates[] = 'password_value = NULL';
		} elseif ( array_key_exists( 'password', $payload ) ) {
			$password = $this->validator->sanitize_link_password(
				$payload['password'],
			);

			if ( '' !== $password ) {
				$updates[]                = 'password_value = :password_value';
				$params['password_value'] = $this->validator->hash_link_password(
					$password,
				);
			}
		}

		if ( array_key_exists( 'expiresAt', $payload ) ) {
			$updates[]            = 'expires_at = :expires_at';
			$params['expires_at'] = $this->validator->normalize_datetime(
				$payload['expiresAt'],
			);
		}

		if (
			array_key_exists( 'alias', $payload ) &&
			'' !== trim( (string) $payload['alias'] )
		) {
			$alias = $this->validator->sanitize_code( (string) $payload['alias'] );

			$this->validator->validate_alias(
				$alias,
				(string) $existing['alias'],
				fn( string $code ): bool => $this->repository->short_code_exists( $code ),
			);

			$updates[]            = 'alias = :alias';
			$updates[]            = 'short_code = :short_code';
			$params['alias']      = $alias;
			$params['short_code'] = $alias;
		}

		if ( empty( $updates ) ) {
			return ( $this->link_formatter )( $this->repository->find_url_row( $id ) );
		}

		$updates[]            = 'updated_at = :updated_at';
		$params['updated_at'] = Date::now();

		$this->repository->update_url_fields( $id, $updates, $params );

		if ( '' !== $delete_social_image ) {
			$this->social_preview->delete_link_image(
				$delete_social_image,
			);
		}

		$updated_row = $this->repository->find_url_row( $id );

		if ( $destination_changed ) {
			// Invalidate/clear old health snapshot from link_health.
			$this->repository->delete_link_health( $id );

			// Asynchronously schedule targeted health check without blocking user response.
			if ( null !== $this->health_scheduler ) {
				( $this->health_scheduler )( $id );
			}
		}

		$this->record_link_activity(
			'link_updated',
			'Updated link ' . ( $params['alias'] ?? $existing['alias'] ) . '.',
			$user,
			$updated_row ? $updated_row : $existing,
			$id
		);

		$url = ( $this->link_formatter )( $updated_row );

		if ( $destination_changed ) {
			$url['health'] = null;
		} else {
			$health_row    = $this->repository->get_link_health( $id );
			$url['health'] = null !== $this->health_formatter
				? ( $this->health_formatter )( $health_row )
				: null;
		}

		$this->invalidate_link_cache( $existing );
		$this->invalidate_link_cache( $updated_row );

		/**
		 * Fires after a short link has been updated.
		 *
		 * @since 1.2.2
		 *
		 * @param array<string, mixed> $url      Formatted link payload.
		 * @param array<string, mixed> $previous Previous database row.
		 * @param Request              $request  Incoming request.
		 * @param array<string, mixed> $user     Current user row.
		 */
		\do_action( 'link_updated', $url, $existing, $request, $user );

		$formatted_existing = ( $this->link_formatter )( $existing );
		$formatted_url      = ( $this->link_formatter )( $url );
		$this->webhooks_service->dispatch_link_event( 'link.updated', $formatted_url, $user, $formatted_existing );

		$prev_status = (string) ( $existing['status'] ?? 'active' );
		$new_status  = (string) ( $formatted_url['status'] ?? 'active' );

		if ( $prev_status !== $new_status ) {
			if ( 'active' === $new_status && in_array( $prev_status, array( 'inactive', 'paused' ), true ) ) {
				$this->webhooks_service->dispatch_link_event( 'link.activated', $formatted_url, $user, $formatted_existing );
			} elseif ( in_array( $new_status, array( 'inactive', 'paused' ), true ) && 'active' === $prev_status ) {
				$this->webhooks_service->dispatch_link_event( 'link.deactivated', $formatted_url, $user, $formatted_existing );
			} elseif ( 'expired' === $new_status && 'expired' !== $prev_status ) {
				$this->webhooks_service->dispatch_link_event( 'link.expired', $formatted_url, $user, $formatted_existing );
			}
		}

		return $url;
	}


	/**
	 * Delete a short URL by ID.
	 *
	 * Moves to trash or permanently deletes if force is passed or already trashed.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @param bool    $force   Whether to force permanent deletion.
	 * @return bool True if a row was updated or deleted.
	 * @since 1.7.2
	 */
	public function delete_url( Request $request, string $id, bool $force = false ): bool {
		$user = $this->auth_service->get_current_user( $request );
		$row  = $this->repository->get_link_by_id( $id );

		if ( ! $row ) {
			return false;
		}

		$is_trashed = 'trashed' === (string) ( $row['status'] ?? 'active' );
		$permanent  = $force || $is_trashed;
		$owner_id   = (string) ( $row['user_id'] ?? '' );
		$is_owner   = (string) ( $user['id'] ?? '' ) === $owner_id;
		$is_admin   = $this->roles->is_admin( $user );

		if ( $permanent ) {
			$this->authorization->validate_capability(
				$user,
				'delete_links',
				__( 'You do not have permission to permanently delete links.', 'peakurl' ),
			);
		} else {
			$this->authorization->validate_capability(
				$user,
				'trash_links',
				__( 'You do not have permission to delete links.', 'peakurl' ),
			);

			if ( ! $is_admin && ! $is_owner ) {
				throw new ApiException(
					__( 'You do not have permission to move this link to trash.', 'peakurl' ),
					403,
				);
			}
		}

		if ( ! $permanent ) {
			\do_action( 'pre_trash_link', $row, $request, $user );

			$this->trash_link( $row, Date::now(), $user, $request );

			return true;
		}

		\do_action( 'pre_delete_link', $row, $request, $user );

		$link_title = $this->get_link_title( $row, $id );
		$this->record_link_activity(
			'link_deleted',
			'Permanently deleted link "' . $link_title . '"',
			$user,
			$row,
			null
		);

		$deleted = $this->repository->delete_url_permanently( $id );

		if ( $deleted ) {
			$this->invalidate_link_cache( $row );

			$this->social_preview->delete_link_image(
				(string) ( $row['social_image_path'] ?? '' ),
			);

			\do_action( 'link_deleted', $row, $request, $user );

			$this->webhooks_service->dispatch_link_event( 'link.deleted', ( $this->link_formatter )( $row ), $user );
		}

		return $deleted;
	}

	/**
	 * Restore a trashed short URL by ID.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @return array<string, mixed> Formatted restored URL item.
	 *
	 * @throws ApiException When the link is missing, already active, or unauthorized.
	 * @since 1.7.2
	 */
	public function restore_url( Request $request, string $id ): array {
		$user = $this->auth_service->get_current_user( $request );
		$row  = $this->repository->get_link_by_id( $id );

		if ( ! $row ) {
			throw new ApiException(
				__( 'That short link does not exist.', 'peakurl' ),
				404,
			);
		}

		if ( 'active' === ( $row['status'] ?? '' ) ) {
			throw new ApiException(
				__( 'This link is already active.', 'peakurl' ),
				400,
			);
		}

		$this->authorization->validate_capability(
			$user,
			'edit_links',
			__( 'You do not have permission to restore this link.', 'peakurl' ),
		);

		$owner_id = (string) ( $row['user_id'] ?? '' );
		$is_owner = (string) ( $user['id'] ?? '' ) === $owner_id;
		$is_admin = $this->roles->is_admin( $user );

		if ( ! $is_admin && ! $is_owner ) {
			throw new ApiException(
				__( 'You do not have permission to restore this link.', 'peakurl' ),
				403,
			);
		}

		return $this->restore_link( $row, Date::now(), $user, $request );
	}


	/**
	 * Bulk-delete or bulk-trash short URLs by an array of IDs.
	 *
	 * @param Request            $request Incoming HTTP request.
	 * @param array<int, string> $ids     Short-URL row IDs.
	 * @param bool               $force   Whether to force permanent deletion.
	 * @return int Number of rows processed.
	 * @since 1.7.2
	 */
	public function bulk_delete_urls( Request $request, array $ids, bool $force = false ): int {
		$user = $this->auth_service->get_current_user( $request );
		$ids  = Query::string_ids( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		if ( $force ) {
			$this->authorization->validate_capability(
				$user,
				'delete_links',
				__( 'You do not have permission to permanently delete links.', 'peakurl' ),
			);
		} else {
			$this->authorization->validate_capability(
				$user,
				'trash_links',
				__( 'You do not have permission to delete links.', 'peakurl' ),
			);
		}

		$is_admin    = $this->roles->is_admin( $user );
		$allowed_ids = $ids;

		if ( ! $is_admin ) {
			$allowed_ids = $this->repository->get_owned_link_ids(
				$ids,
				(string) $user['id'],
			);
		}

		if ( empty( $allowed_ids ) ) {
			return 0;
		}

		$target_rows = $this->repository->get_links_by_ids( $allowed_ids );

		if ( empty( $target_rows ) ) {
			return 0;
		}

		$all_trashed = true;
		foreach ( $target_rows as $target_row ) {
			if ( 'trashed' !== (string) ( $target_row['status'] ?? 'active' ) ) {
				$all_trashed = false;
				break;
			}
		}

		$permanent = $force || $all_trashed;

		if ( $permanent && ! $force ) {
			$this->authorization->validate_capability(
				$user,
				'delete_links',
				__( 'You do not have permission to permanently delete links.', 'peakurl' ),
			);
		}

		if ( ! $permanent ) {
			$now         = Date::now();
			$trashed_ids = array();

			foreach ( $target_rows as $row ) {
				$row_id = (string) ( $row['id'] ?? '' );
				if ( '' === $row_id ) {
					continue;
				}

				$this->trash_link( $row, $now, $user, $request );
				$trashed_ids[] = $row_id;
			}

			return count( $trashed_ids );
		}

		return $this->delete_links_permanently( $target_rows, $allowed_ids, $user, $request );
	}

	/**
	 * Bulk-restore short URLs by an array of IDs.
	 *
	 * @param Request            $request Incoming HTTP request.
	 * @param array<int, string> $ids     Short-URL row IDs.
	 * @return int Number of rows restored.
	 * @since 1.7.2
	 */
	public function bulk_restore_urls( Request $request, array $ids ): int {
		$user = $this->auth_service->get_current_user( $request );
		$ids  = Query::string_ids( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$this->authorization->validate_capability(
			$user,
			'edit_links',
			__( 'You do not have permission to restore links.', 'peakurl' ),
		);

		$is_admin    = $this->roles->is_admin( $user );
		$allowed_ids = $ids;

		if ( ! $is_admin ) {
			$allowed_ids = $this->repository->get_owned_link_ids(
				$ids,
				(string) $user['id'],
			);
		}

		if ( empty( $allowed_ids ) ) {
			return 0;
		}

		$target_rows = $this->repository->get_links_by_ids( $allowed_ids );

		if ( empty( $target_rows ) ) {
			return 0;
		}

		$now          = Date::now();
		$restored_ids = array();

		foreach ( $target_rows as $row ) {
			$row_id = (string) ( $row['id'] ?? '' );
			if ( '' === $row_id || 'trashed' !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}

			$this->restore_link( $row, $now, $user, $request );
			$restored_ids[] = $row_id;
		}

		return count( $restored_ids );
	}


	/**
	 * Permanently empty all trashed short URLs for the current user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Number of rows permanently deleted.
	 * @since 1.7.2
	 */
	public function empty_trash( Request $request ): int {
		$user = $this->auth_service->get_current_user( $request );

		$this->authorization->validate_capability(
			$user,
			'empty_trash',
			__( 'You do not have permission to empty trash.', 'peakurl' ),
		);

		$rows = $this->repository->get_trashed_links( $user );

		if ( empty( $rows ) ) {
			return 0;
		}

		$ids = array_map( 'strval', array_column( $rows, 'id' ) );

		return $this->delete_links_permanently( $rows, $ids, $user, $request );
	}

	/**
	 * Permanently delete or trash all accessible short URLs for the current user.
	 *
	 * Requires 'trash_links' capability when mode is 'trash', and 'delete_links'
	 * capability when mode is 'permanent'.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $mode    Operation mode: 'trash' or 'permanent'.
	 * @return int Number of affected URLs.
	 * @throws ApiException When unauthenticated, unauthorized for the mode, or mode is invalid.
	 * @since 1.7.2
	 */
	public function clear_urls( Request $request, string $mode ): int {
		$user = $this->auth_service->get_current_user( $request );

		if ( ! in_array( $mode, array( 'trash', 'permanent' ), true ) ) {
			throw new ApiException(
				__( 'Invalid delete mode.', 'peakurl' ),
				400
			);
		}

		if ( 'permanent' === $mode ) {
			$this->authorization->validate_capability(
				$user,
				'delete_links',
				__( 'You do not have permission to permanently delete links.', 'peakurl' ),
			);
		} else {
			$this->authorization->validate_capability(
				$user,
				'trash_links',
				__( 'You do not have permission to delete links.', 'peakurl' ),
			);
		}

		$is_admin  = $this->roles->is_admin( $user );
		$permanent = 'permanent' === $mode;

		if ( $permanent ) {
			$rows = $this->repository->get_accessible_links( $user );

			if ( empty( $rows ) ) {
				return 0;
			}

			$ids = array_map( 'strval', array_column( $rows, 'id' ) );

			return $this->delete_links_permanently( $rows, $ids, $user, $request );
		}

		$rows = $this->repository->get_accessible_links(
			$user,
			function ( array $u, array &$conditions, array &$params, string $table_alias ) use ( $is_admin ) {
				if ( ! $is_admin ) {
					$conditions[]             = $table_alias . '.user_id = :filter_user_id';
					$params['filter_user_id'] = (string) ( $u['id'] ?? '' );
				}
				$conditions[] = $table_alias . ".status = 'active'";
			}
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$now         = Date::now();
		$trashed_ids = array();

		foreach ( $rows as $row ) {
			$row_id = (string) ( $row['id'] ?? '' );
			if ( '' === $row_id ) {
				continue;
			}

			$this->trash_link( $row, $now, $user, $request );
			$trashed_ids[] = $row_id;
		}

		return count( $trashed_ids );
	}

	/**
	 * Transition a single link to trashed status and trigger standard lifecycle side effects.
	 *
	 * @param array<string, mixed> $row     Link database row.
	 * @param string               $now     Timestamp string.
	 * @param array<string, mixed> $user    Acting user.
	 * @param Request              $request Incoming HTTP request.
	 * @return void
	 * @since 1.7.2
	 */
	private function trash_link( array $row, string $now, array $user, Request $request ): void {
		$row_id     = (string) ( $row['id'] ?? '' );
		$link_title = $this->get_link_title( $row, $row_id );

		$this->repository->trash_url( $row_id, $now );

		$this->record_link_activity(
			'link_trashed',
			'Moved link "' . $link_title . '" to trash',
			$user,
			$row,
			$row_id
		);

		$this->invalidate_link_cache( $row );

		\do_action( 'link_trashed', $row, $request, $user );

		$this->webhooks_service->dispatch_link_event( 'link.deleted', ( $this->link_formatter )( $row ), $user );
	}

	/**
	 * Transition a single link to active restored status and trigger standard lifecycle side effects.
	 *
	 * @param array<string, mixed> $row     Link database row.
	 * @param string               $now     Timestamp string.
	 * @param array<string, mixed> $user    Acting user.
	 * @param Request              $request Incoming HTTP request.
	 * @return array<string, mixed> Formatted restored link payload.
	 * @since 1.7.2
	 */
	private function restore_link( array &$row, string $now, array $user, Request $request ): array {
		$row_id            = (string) ( $row['id'] ?? '' );
		$row['status']     = 'active';
		$row['updated_at'] = $now;
		$link_title        = $this->get_link_title( $row, $row_id );

		$this->repository->restore_url( $row_id, $now );

		$this->record_link_activity(
			'link_restored',
			'Restored link "' . $link_title . '"',
			$user,
			$row,
			$row_id
		);

		$this->invalidate_link_cache( $row );

		\do_action( 'link_restored', $row, $request, $user );

		$formatted_url = ( $this->link_formatter )( $row );
		$this->webhooks_service->dispatch_link_event( 'link.restored', $formatted_url, $user );

		return $formatted_url;
	}

	/**
	 * Permanently delete multiple links and clean up assets and cache with standard lifecycle side effects.
	 *
	 * @param array<int, array<string, mixed>> $rows    Target link rows.
	 * @param array<int, string>               $ids     Target link IDs.
	 * @param array<string, mixed>             $user    Acting user.
	 * @param Request                          $request Incoming HTTP request.
	 * @return int Number of permanently deleted rows.
	 * @since 1.7.2
	 */
	private function delete_links_permanently(
		array $rows,
		array $ids,
		array $user,
		Request $request
	): int {
		foreach ( $rows as $deleted_row ) {
			$link_title = $this->get_link_title( $deleted_row, (string) ( $deleted_row['id'] ?? '' ) );

			$this->record_link_activity(
				'link_deleted',
				'Permanently deleted link "' . $link_title . '"',
				$user,
				$deleted_row,
				null
			);
		}

		$deleted_count = $this->repository->delete_links_permanently( $ids );

		$this->social_preview->delete_link_images(
			array_column( $rows, 'social_image_path' ),
		);

		foreach ( $rows as $deleted_row ) {
			$this->invalidate_link_cache( $deleted_row );

			\do_action( 'link_deleted', $deleted_row, $request, $user );

			$this->webhooks_service->dispatch_link_event( 'link.deleted', ( $this->link_formatter )( $deleted_row ), $user );
		}

		return $deleted_count;
	}

	/**
	 * Invalidate object cache entries associated with a link.
	 *
	 * @param mixed $link Row array, short code string, or ID.
	 * @return void
	 * @since 1.7.2
	 */
	private function invalidate_link_cache( $link ): void {
		$this->repository->get_links_api()->invalidate_link_cache( $link );
	}

	/**
	 * Apply a link payload filter hook.
	 *
	 * @param string               $hook_name Hook name.
	 * @param array<string, mixed> $payload   Input payload.
	 * @param mixed                ...$args   Additional parameters.
	 * @return array<string, mixed> Filtered payload.
	 * @since 1.7.2
	 */
	private function filter_link_payload(
		string $hook_name,
		array $payload,
		...$args
	): array {
		$filtered = \apply_filters( $hook_name, $payload, ...$args );

		return is_array( $filtered ) ? $filtered : $payload;
	}

	/**
	 * Get the fallback display title for a link in activity logs.
	 *
	 * @param array<string, mixed> $link     Link row or formatted link array.
	 * @param string               $fallback Fallback identifier when alias or code is absent.
	 * @return string Display title.
	 * @since 1.7.2
	 */
	private function get_link_title( array $link, string $fallback = '' ): string {
		if ( ! empty( $link['title'] ) ) {
			return (string) $link['title'];
		}

		$identifier = (string) ( $link['alias'] ?? $link['short_code'] ?? $link['shortCode'] ?? $fallback );

		return '/' . $identifier;
	}

	/**
	 * Record a link lifecycle activity event.
	 *
	 * @param string               $type    Activity type (e.g. 'link_updated', 'link_trashed', 'link_deleted', 'link_restored').
	 * @param string               $message Activity description message.
	 * @param array<string, mixed> $user    Acting user array.
	 * @param array<string, mixed> $link    Link database row or payload.
	 * @param string|null          $link_id Target link ID (or null for permanent deletion).
	 * @return void
	 * @since 1.7.2
	 */
	private function record_link_activity(
		string $type,
		string $message,
		array $user,
		array $link,
		?string $link_id = null
	): void {
		$this->analytics_service->record_activity(
			$type,
			$message,
			(string) ( $user['id'] ?? '' ),
			$link_id,
			array(
				'link' => $this->get_link_activity_meta( $link ),
			)
		);
	}

	/**
	 * Return rich link metadata snapshot for audit-log payloads.
	 *
	 * @param array<string, mixed> $link Raw link row or partial row.
	 * @return array<string, mixed>
	 * @since 1.7.2
	 */
	private function get_link_activity_meta( array $link ): array {
		$title = trim( html_entity_decode( (string) ( $link['title'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$code  = trim(
			(string) ( $link['alias'] ?? ( $link['short_code'] ?? '' ) ),
		);
		$alias = trim( (string) ( $link['alias'] ?? '' ) );
		$dest  = trim( (string) ( $link['destination_url'] ?? '' ) );

		return array(
			'id'                => (string) ( $link['id'] ?? '' ),
			'title'             => '' !== $title ? $title : null,
			'shortCode'         => '' !== $code ? $code : null,
			'alias'             => '' !== $alias ? $alias : null,
			'destinationUrl'    => '' !== $dest ? $dest : null,
			'status'            => (string) ( $link['status'] ?? 'active' ),
			'utmSource'         => ! empty( $link['utm_source'] ) ? (string) $link['utm_source'] : null,
			'utmMedium'         => ! empty( $link['utm_medium'] ) ? (string) $link['utm_medium'] : null,
			'utmCampaign'       => ! empty( $link['utm_campaign'] ) ? (string) $link['utm_campaign'] : null,
			'utmTerm'           => ! empty( $link['utm_term'] ) ? (string) $link['utm_term'] : null,
			'utmContent'        => ! empty( $link['utm_content'] ) ? (string) $link['utm_content'] : null,
			'passwordProtected' => ! empty( $link['password_value'] ),
			'expiresAt'         => ! empty( $link['expires_at'] ) ? (string) $link['expires_at'] : null,
			'socialTitle'       => ! empty( $link['social_title'] ) ? (string) $link['social_title'] : null,
			'socialDescription' => ! empty( $link['social_description'] ) ? (string) $link['social_description'] : null,
			'socialImagePath'   => ! empty( $link['social_image_path'] ) ? (string) $link['social_image_path'] : null,
			'socialImageUrl'    => ! empty( $link['social_image_url'] ) ? (string) $link['social_image_url'] : null,
		);
	}
}
