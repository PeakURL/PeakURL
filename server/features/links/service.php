<?php
/**
 * Link domain service.
 *
 * Implements business logic for short URLs, access validation,
 * redirects, life-cycle events, and caching.
 *
 * @package PeakURL\Features\Links
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Links;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Scheduler\BackgroundRunner;
use PeakURL\Core\Scheduler\Scheduler;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Links\Access;
use PeakURL\Features\Links\Health\Checker;
use PeakURL\Features\Links\Lifecycle;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Captcha;
use PeakURL\Services\SocialPreview;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — Coordinates link operations, lifecycle hooks, and access control.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Link repository handler.
	 *
	 * @var Repository
	 * @since 1.0.0
	 */
	private Repository $repository;

	/**
	 * Link validation helper.
	 *
	 * @var Validator
	 * @since 1.0.0
	 */
	private Validator $validator;

	/**
	 * Settings data API.
	 *
	 * @var SettingsApi
	 * @since 1.0.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.0.0
	 */
	private AuthService $auth_service;

	/**
	 * Analytics domain service.
	 *
	 * @var AnalyticsService
	 * @since 1.0.0
	 */
	private AnalyticsService $analytics_service;

	/**
	 * Webhooks domain service.
	 *
	 * @var WebhooksService
	 * @since 1.0.0
	 */
	private WebhooksService $webhooks_service;

	/**
	 * Roles and capabilities registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Social preview metadata helper.
	 *
	 * @var SocialPreview
	 * @since 1.2.0
	 */
	private SocialPreview $social_preview;

	/**
	 * Canonical link creator domain service.
	 *
	 * @var Creator
	 * @since 1.7.0
	 */
	private Creator $creator;

	/**
	 * Public access domain service.
	 *
	 * @var Access
	 * @since 1.7.2
	 */
	private Access $access;

	/**
	 * Link lifecycle domain service.
	 *
	 * @var Lifecycle
	 * @since 1.7.2
	 */
	private Lifecycle $lifecycle;

	/**
	 * Link collection query domain service.
	 *
	 * @var LinkCollection
	 * @since 1.7.2
	 */
	private LinkCollection $collection;

	/**
	 * Destination health checker service.
	 *
	 * @var Checker
	 * @since 1.7.1
	 */
	private Checker $health_checker;

	/**
	 * Background job scheduler instance.
	 *
	 * @var Scheduler|null
	 * @since 1.7.1
	 */
	private ?Scheduler $scheduler = null;

	/**
	 * Create a new Link service instance.
	 *
	 * @param Repository           $data              Repository handler.
	 * @param Validator            $validator         Validator handler.
	 * @param SettingsApi          $settings_api      Settings data API.
	 * @param AuthService          $auth_service      Authentication domain service.
	 * @param AnalyticsService     $analytics_service Analytics domain service.
	 * @param WebhooksService      $webhooks_service  Webhooks domain service.
	 * @param SocialPreview        $social_preview    Social preview service.
	 * @param Captcha              $captcha           CAPTCHA service.
	 * @param Roles                $roles             Roles registry.
	 * @param Authorization        $authorization     Authorization helper.
	 * @param array<string, mixed> $config            Runtime config map.
	 * @param Checker              $health_checker    Destination health checker service.
	 * @since 1.0.0
	 */
	public function __construct(
		Repository $data,
		Validator $validator,
		SettingsApi $settings_api,
		AuthService $auth_service,
		AnalyticsService $analytics_service,
		WebhooksService $webhooks_service,
		SocialPreview $social_preview,
		Captcha $captcha,
		Roles $roles,
		Authorization $authorization,
		array $config,
		Checker $health_checker
	) {
		$this->repository        = $data;
		$this->validator         = $validator;
		$this->settings_api      = $settings_api;
		$this->auth_service      = $auth_service;
		$this->analytics_service = $analytics_service;
		$this->webhooks_service  = $webhooks_service;
		$this->social_preview    = $social_preview;
		$this->roles             = $roles;
		$this->authorization     = $authorization;
		$this->creator           = new Creator( $data, $validator, $social_preview );
		$this->health_checker    = $health_checker;
		$this->access            = new Access(
			$data,
			$validator,
			$analytics_service,
			$webhooks_service,
			$captcha,
			$config,
			fn( array $row ): array => $this->format_url( $row )
		);
		$this->lifecycle         = new Lifecycle(
			$data,
			$validator,
			$auth_service,
			$analytics_service,
			$webhooks_service,
			$social_preview,
			$roles,
			$authorization,
			fn( ?array $row ): array => $this->format_url( $row ),
			function ( string $link_id ): void {
				$this->schedule_health_check( $link_id );
			},
			fn( ?array $row ): ?array => $this->format_health( $row )
		);
		$this->collection        = new LinkCollection(
			$data,
			$auth_service,
			$analytics_service,
			fn( ?array $row ): array => $this->format_url( $row ),
			fn( ?array $row ): ?array => $this->format_health( $row )
		);
	}

	/**
	 * Find a URL row by ID, short code, or alias.
	 *
	 * @param string $id URL ID, short code, or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function find_url_row( string $id ): ?array {
		return $this->repository->find_url_row( $id );
	}

	/**
	 * Get the Repository instance.
	 *
	 * @return Repository
	 * @since 1.0.0
	 */
	public function get_data(): Repository {
		return $this->repository;
	}

	/**
	 * Get the Validator instance.
	 *
	 * @return Validator
	 * @since 1.0.0
	 */
	public function get_validator(): Validator {
		return $this->validator;
	}

	/**
	 * Background runner for immediate post-response dispatch.
	 *
	 * @var BackgroundRunner|null
	 * @since 1.7.1
	 */
	private ?BackgroundRunner $background_runner = null;

	/**
	 * Set the background job scheduler.
	 *
	 * @param Scheduler $scheduler Background scheduler instance.
	 * @return void
	 * @since 1.7.1
	 */
	public function set_scheduler( Scheduler $scheduler ): void {
		$this->scheduler = $scheduler;
	}

	/**
	 * Set the background runner instance for immediate async dispatch.
	 *
	 * @param BackgroundRunner|null $runner Background runner instance.
	 * @return void
	 * @since 1.7.1
	 */
	public function set_background_runner( ?BackgroundRunner $runner ): void {
		$this->background_runner = $runner;
	}

	/**
	 * Get the background job scheduler if configured.
	 *
	 * @return Scheduler|null
	 * @since 1.7.1
	 */
	public function get_scheduler(): ?Scheduler {
		return $this->scheduler;
	}

	/**
	 * Schedule a targeted background health check for a link.
	 *
	 * Uses PeakURL's database-backed Scheduled Jobs layer. Never makes an outbound
	 * network request synchronously from the create/update request path.
	 *
	 * @param string $link_id Link identifier.
	 * @return void
	 * @since 1.7.1
	 */
	public function schedule_health_check( string $link_id ): void {
		if ( null !== $this->scheduler ) {
			try {
				$target_id = $this->scheduler->enqueue_job(
					'peakurl_link_health_check',
					array( 'link_id' => $link_id )
				);

				if ( null !== $this->background_runner ) {
					$this->background_runner->enqueue_targeted_job( $target_id );
				}
			} catch ( \Throwable $e ) {
				// Background scheduling must never fail the link create/update request, but failure is observable.
				error_log( sprintf( 'PeakURL link health scheduling failed for link [%s]: %s', $link_id, $e->getMessage() ) );
				\do_action( 'peakurl_link_health_scheduling_failed', $link_id, $e->getMessage() );
			}
		}
	}

	/**
	 * List short URLs with pagination, sorting, and optional search.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Query parameters for pagination/sorting/search.
	 * @return array<string, mixed> Paginated URL list with meta.
	 * @since 1.0.0
	 */
	public function list_urls( Request $request, array $query ): array {
		return $this->collection->list_urls( $request, $query );
	}

	/**
	 * Export all accessible short URLs for the current user.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Optional sort/search parameters.
	 * @return array<string, mixed> Full accessible link export payload.
	 * @since 1.0.0
	 */
	public function export_urls( Request $request, array $query = array() ): array {
		return $this->collection->export_urls( $request, $query );
	}

	/**
	 * Find a single short URL by its ID.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @return array<string, mixed>|null Formatted URL row or null.
	 * @since 1.0.0
	 */
	public function find_url( Request $request, string $id ): ?array {
		$user = $this->auth_service->get_current_user( $request );
		$row  = $this->repository->find_url_row( $id );

		if ( $row ) {
			$this->authorization->validate_capability(
				$user,
				'view_links',
				__( 'You do not have permission to view this link.', 'peakurl' ),
			);
		}

		if ( ! $row ) {
			return null;
		}

		$formatted           = $this->format_url( $row );
		$health_row          = $this->repository->get_link_health( (string) $row['id'] );
		$formatted['health'] = $this->format_health( $health_row );

		return $formatted;
	}

	/**
	 * Format a link health database row into an API health payload.
	 *
	 * @param array<string, mixed>|null $row Raw database row.
	 * @return array<string, mixed>|null Formatted health payload or null.
	 * @since 1.7.1
	 */
	public function format_health( ?array $row ): ?array {
		if ( empty( $row ) || ! is_array( $row ) ) {
			return null;
		}

		$status         = (string) ( $row['status'] ?? '' );
		$valid_statuses = array(
			Checker::STATUS_HEALTHY,
			Checker::STATUS_SLOW,
			Checker::STATUS_UNREACHABLE,
			Checker::STATUS_DNS_ERROR,
			Checker::STATUS_TLS_ERROR,
			Checker::STATUS_TIMEOUT,
			Checker::STATUS_HTTP_ERROR,
			Checker::STATUS_REDIRECT_LOOP,
			Checker::STATUS_SSRF_BLOCKED,
		);

		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return null;
		}

		return array(
			'status'         => $status,
			'checkedAt'      => ! empty( $row['checked_at'] )
				? Date::to_iso( (string) $row['checked_at'] )
				: null,
			'responseCode'   => null !== ( $row['response_code'] ?? null )
				? (int) $row['response_code']
				: null,
			'responseTimeMs' => null !== ( $row['response_time_ms'] ?? null )
				? (int) $row['response_time_ms']
				: null,
			'errorMessage'   => ! empty( $row['error_message'] )
				? (string) $row['error_message']
				: null,
			'redirectCount'  => (int) ( $row['redirect_count'] ?? 0 ),
		);
	}

	/**
	 * Manually check destination health for a specific link.
	 *
	 * Enforces 'view_links' capability, probes the destination URL safely,
	 * persists the result snapshot to link_health, and returns the health payload.
	 * Never modifies urls.status or urls.destination_url.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Link ID or identifier.
	 * @return array<string, mixed> Formatted health payload.
	 *
	 * @throws ApiException When unauthenticated, unauthorized, or link not found.
	 * @since 1.7.1
	 */
	public function check_link_health( Request $request, string $id ): array {
		$user = $this->auth_service->get_current_user( $request );
		$row  = $this->repository->find_url_row( $id );

		if ( ! $row ) {
			throw new ApiException(
				__( 'That short link does not exist.', 'peakurl' ),
				404
			);
		}

		$this->authorization->validate_capability(
			$user,
			'view_links',
			__( 'You do not have permission to check link health.', 'peakurl' ),
		);

		$dest_url = trim( (string) ( $row['destination_url'] ?? '' ) );
		$result   = $this->health_checker->check( $dest_url );

		$current_row = $this->repository->find_url_row( (string) $row['id'] );
		if ( ! $current_row || 'active' !== (string) ( $current_row['status'] ?? '' ) ) {
			throw new ApiException(
				__( 'That short link does not exist or is no longer active.', 'peakurl' ),
				404
			);
		}

		$current_destination = trim( (string) ( $current_row['destination_url'] ?? '' ) );
		if ( $current_destination !== $dest_url ) {
			throw new ApiException(
				__( 'The link destination changed while the health check was running.', 'peakurl' ),
				409
			);
		}

		try {
			$previous_map    = $this->repository->get_link_health_by_ids( array( (string) $current_row['id'] ) );
			$previous_health = $previous_map[ (string) $current_row['id'] ] ?? null;
		} catch ( \Throwable $e ) {
			throw new ApiException(
				__( 'Could not record link health snapshot.', 'peakurl' ),
				500
			);
		}

		$now         = Date::now();
		$health_data = array(
			'link_id'          => (string) $current_row['id'],
			'status'           => (string) $result['status'],
			'checked_at'       => $now,
			'response_code'    => $result['response_code'],
			'response_time_ms' => $result['response_time_ms'],
			'error_message'    => $result['error_message'],
			'redirect_count'   => (int) ( $result['redirect_count'] ?? 0 ),
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		try {
			$saved = $this->repository->save_link_health( (string) $current_row['id'], $health_data );
		} catch ( \Throwable $e ) {
			$saved = false;
		}

		if ( ! $saved ) {
			throw new ApiException(
				__( 'Could not record link health snapshot.', 'peakurl' ),
				500
			);
		}

		$this->webhooks_service->dispatch_link_health_check(
			$current_row,
			$health_data,
			is_array( $previous_health ) ? $previous_health : null,
			$user
		);

		return $this->format_health( $health_data );
	}

	/**
	 * Return the destination redirect URL for a short code.
	 *
	 * @param string  $id      Short code or alias.
	 * @param Request $request Incoming HTTP request.
	 * @return string|null Destination URL or null.
	 * @since 1.0.0
	 */
	public function get_redirect_url(
		string $id,
		Request $request
	): ?string {
		return $this->access->get_redirect_url( $id, $request );
	}

	/**
	 * Return the public access state for a short link.
	 *
	 * @param string  $id      Short code or alias.
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Public access result.
	 * @since 1.0.0
	 */
	public function get_link_access(
		string $id,
		Request $request
	): array {
		return $this->access->get_link_access( $id, $request );
	}

	/**
	 * Create a new short URL.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Request body.
	 * @return array<string, mixed> Created URL payload.
	 *
	 * @throws ApiException On validation failure (422).
	 * @since 1.0.0
	 */
	public function create_url( Request $request, array $payload ): array {
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'create_links',
			__( 'You do not have permission to create links.', 'peakurl' ),
		);

		$payload = $this->filter_link_payload(
			'pre_create_link',
			$payload,
			$request,
			$user,
		);

		$raw_destination           = (string) ( $payload['destinationUrl'] ?? '' );
		$destination_url           = $this->validator->clean_destination( $raw_destination );
		$payload['destinationUrl'] = $destination_url;

		$social_image_file = $request->get_file( 'socialImage' );
		$social_image_url  = $this->validator->normalize_link_social_image_url(
			$payload['socialImageUrl'] ?? null,
			$this->social_preview,
		);
		$has_social_upload = $this->validator->has_link_upload( $social_image_file );

		if ( $has_social_upload && null !== $social_image_url ) {
			throw new ApiException(
				__(
					'Provide either socialImage or socialImageUrl, not both.',
					'peakurl',
				),
				422,
			);
		}

		$social_image_path = null;

		if ( $has_social_upload ) {
			try {
				$social_image_path          = $this->social_preview->save_link_image(
					Str::random_id(),
					$social_image_file,
					false,
					'',
				);
				$payload['socialImagePath'] = $social_image_path;
			} catch ( \RuntimeException $exception ) {
				throw new ApiException( $exception->getMessage(), 422 );
			}
		}

		try {
			$row = $this->creator->create_link_record( $payload, $user['id'] );
		} catch ( \Throwable $exception ) {
			if ( null !== $social_image_path ) {
				$this->social_preview->delete_link_image( $social_image_path );
			}
			throw $exception;
		}

		$link_id = (string) $row['id'];

		$this->schedule_health_check( $link_id );

		$url           = $this->format_url( $row );
		$url['health'] = null;

		$link_title = $this->get_link_title( $url );

		$this->record_link_activity(
			'link_created',
			'Created new link "' . $link_title . '"',
			$user,
			array(
				'id'         => $url['id'],
				'title'      => $url['title'] ?? '',
				'alias'      => $url['alias'] ?? '',
				'short_code' => $url['shortCode'] ?? '',
			),
			(string) $url['id']
		);

		/**
		 * Fires after a short link has been created.
		 *
		 * @since 1.2.2
		 *
		 * @param array<string, mixed> $url     Formatted link payload.
		 * @param Request              $request Incoming request.
		 * @param array<string, mixed> $user    Current user row.
		 */
		\do_action( 'link_created', $url, $request, $user );

		$this->webhooks_service->dispatch_link_event( 'link.created', $url, $user );

		return $url;
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
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'create_links',
			__( 'You do not have permission to create links.', 'peakurl' ),
		);

		if ( empty( $payload['urls'] ) || ! is_array( $payload['urls'] ) ) {
			throw new ApiException(
				__( 'The `urls` field is required and must be an array.', 'peakurl' ),
				422,
			);
		}

		$results = array();
		$errors  = array();

		foreach ( $payload['urls'] as $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = array(
					'destinationUrl' => '',
					'alias'          => null,
					'error'          => __( 'Invalid URL item payload.', 'peakurl' ),
				);
				continue;
			}

			try {
				$results[] = $this->create_url(
					$request,
					$item,
				);
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'destinationUrl' => (string) ( $item['destinationUrl'] ?? '' ),
					'alias'          => (string) ( $item['alias'] ?? '' ),
					'error'          => $e->getMessage(),
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
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $id      Short-URL row ID.
	 * @param array<string, mixed> $payload Partial update payload.
	 * @return array<string, mixed>|null Formatted URL or null if not found.
	 *
	 * @throws ApiException On validation failure (422).
	 * @since 1.0.0
	 */
	public function update_url(
		Request $request,
		string $id,
		array $payload
	): ?array {
		return $this->lifecycle->update_url( $request, $id, $payload );
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
	 * @since 1.0.0
	 */
	public function delete_url( Request $request, string $id, bool $force = false ): bool {
		return $this->lifecycle->delete_url( $request, $id, $force );
	}

	/**
	 * Restore a trashed short URL by ID.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @return array<string, mixed> Formatted restored URL item.
	 *
	 * @throws ApiException When the link is missing, already active, or unauthorized.
	 * @since 1.6.0
	 */
	public function restore_url( Request $request, string $id ): array {
		return $this->lifecycle->restore_url( $request, $id );
	}

	/**
	 * Bulk-delete or bulk-trash short URLs by an array of IDs.
	 *
	 * @param Request            $request Incoming HTTP request.
	 * @param array<int, string> $ids     Short-URL row IDs.
	 * @param bool               $force   Whether to force permanent deletion.
	 * @return int Number of rows processed.
	 * @since 1.0.0
	 */
	public function bulk_delete_urls( Request $request, array $ids, bool $force = false ): int {
		return $this->lifecycle->bulk_delete_urls( $request, $ids, $force );
	}

	/**
	 * Bulk-restore short URLs by an array of IDs.
	 *
	 * @param Request            $request Incoming HTTP request.
	 * @param array<int, string> $ids     Short-URL row IDs.
	 * @return int Number of rows restored.
	 * @since 1.6.0
	 */
	public function bulk_restore_urls( Request $request, array $ids ): int {
		return $this->lifecycle->bulk_restore_urls( $request, $ids );
	}

	/**
	 * Permanently empty all trashed short URLs for the current user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Number of rows permanently deleted.
	 * @since 1.6.0
	 */
	public function empty_trash( Request $request ): int {
		return $this->lifecycle->empty_trash( $request );
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
	 * @since 1.5.3
	 */
	public function clear_urls( Request $request, string $mode ): int {
		return $this->lifecycle->clear_urls( $request, $mode );
	}

	/**
	 * Return social sharing metadata for a public short link.
	 *
	 * @param string $id Short code or alias.
	 * @return array<string, mixed>|null Preview data or null.
	 * @since 1.2.0
	 */
	public function get_link_social_preview( string $id ): ?array {
		$code = $this->validator->sanitize_code( $id );

		if ( '' === $code ) {
			return null;
		}

		$url = $this->repository->find_link_access_row( $code );

		if ( ! $url || $this->validator->is_public_link_expired( $url ) ) {
			return null;
		}

		if ( 'active' !== (string) ( $url['status'] ?? 'active' ) ) {
			return null;
		}

		$formatted    = $this->format_url( $url );
		$site_name    = trim( (string) $this->settings_api->get_option( 'site_name' ) );
		$tagline      = trim( (string) $this->settings_api->get_option( 'site_tagline' ) );
		$site_tagline = '' !== $tagline ? $tagline : __( 'Shorten, track, and own every link - PeakURL', 'peakurl' );

		if ( '' === $site_name ) {
			$site_name = 'PeakURL';
		}

		return array(
			'link'    => $formatted,
			'preview' => $this->social_preview->get_link_preview(
				$url,
				(string) ( $formatted['shortUrl'] ?? '' ),
				$site_name,
				$site_tagline,
			),
		);
	}

	/**
	 * Count trashed links for the current user/scope.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Total trashed links.
	 * @since 1.6.0
	 */
	public function count_trashed_links( Request $request ): int {
		return $this->collection->count_trashed_links( $request );
	}

	/**
	 * Count expired links for the current user/scope.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Total expired links.
	 * @since 1.7.2
	 */
	public function count_expired_links( Request $request ): int {
		return $this->collection->count_expired_links( $request );
	}

	/**
	 * Invalidate object cache entries associated with a link.
	 *
	 * @param mixed $link Row array, short code string, or ID.
	 * @return void
	 * @since 1.6.0
	 */
	private function invalidate_link_cache( $link ): void {
		$this->repository->get_links_api()->invalidate_link_cache( $link );
	}

	/**
	 * Format a database row into an API-ready link item.
	 *
	 * @param array<string, mixed>|null $row Raw link row.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function format_url( ?array $row ): array {
		if ( ! $row ) {
			return array();
		}

		$site_url  = rtrim( \get_site_url(), '/' );
		$alias     = trim( (string) ( $row['alias'] ?? '' ) );
		$short_key = '' !== $alias
			? $alias
			: trim( (string) ( $row['short_code'] ?? $row['shortCode'] ?? '' ) );
		$short_url = (string) ( $row['shortUrl'] ?? '' );

		if ( '' === $short_url && '' !== $site_url && '' !== $short_key ) {
			$short_url = $site_url . '/' . ltrim( $short_key, '/' );
		}

		return array(
			'id'             => (string) ( $row['id'] ?? '' ),
			'userId'         => (string) ( $row['user_id'] ?? $row['userId'] ?? '' ),
			'shortCode'      => (string) ( $row['short_code'] ?? $row['shortCode'] ?? '' ),
			'alias'          => (string) ( $row['alias'] ?? '' ),
			'shortUrl'       => $short_url,
			'title'          => trim( (string) ( $row['title'] ?? '' ) ),
			'destinationUrl' => (string) ( $row['destination_url'] ?? $row['destinationUrl'] ?? '' ),
			'socialPreview'  => array(
				'title'            => trim( (string) ( $row['social_title'] ?? $row['socialPreview']['title'] ?? '' ) ),
				'description'      => trim( (string) ( $row['social_description'] ?? $row['socialPreview']['description'] ?? '' ) ),
				'imageUrl'         => '' !== trim( (string) ( $row['social_image_url'] ?? $row['socialPreview']['imageUrl'] ?? '' ) )
					? trim( (string) ( $row['social_image_url'] ?? $row['socialPreview']['imageUrl'] ) )
					: $this->social_preview->get_link_image_url(
						(string) ( $row['social_image_path'] ?? '' ),
					),
				'externalImageUrl' => '' !== trim( (string) ( $row['social_image_url'] ?? $row['socialPreview']['externalImageUrl'] ?? '' ) )
					? trim( (string) ( $row['social_image_url'] ?? $row['socialPreview']['externalImageUrl'] ) )
					: null,
			),
			'domain'         => null,
			'clicks'         => (int) ( $row['click_count'] ?? $row['clicks'] ?? 0 ),
			'uniqueClicks'   => (int) ( $row['unique_click_count'] ?? $row['uniqueClicks'] ?? 0 ),
			'status'         => ( 'active' === (string) ( $row['status'] ?? 'active' ) && $this->validator->is_public_link_expired( $row ) )
				? 'expired'
				: (string) ( $row['status'] ?? 'active' ),
			'hasPassword'    => ! empty( $row['hasPassword'] ) || '' !== trim(
				(string) ( $row['password_value'] ?? '' ),
			),
			'expiresAt'      => ! empty( $row['expires_at'] )
				? Date::to_iso( (string) $row['expires_at'] )
				: ( $row['expiresAt'] ?? null ),
			'utmSource'      => ! empty( $row['utm_source'] ) ? (string) $row['utm_source'] : ( $row['utmSource'] ?? null ),
			'utmMedium'      => ! empty( $row['utm_medium'] ) ? (string) $row['utm_medium'] : ( $row['utmMedium'] ?? null ),
			'utmCampaign'    => ! empty( $row['utm_campaign'] ) ? (string) $row['utm_campaign'] : ( $row['utmCampaign'] ?? null ),
			'utmTerm'        => ! empty( $row['utm_term'] ) ? (string) $row['utm_term'] : ( $row['utmTerm'] ?? null ),
			'utmContent'     => ! empty( $row['utm_content'] ) ? (string) $row['utm_content'] : ( $row['utmContent'] ?? null ),
			'createdAt'      => ! empty( $row['created_at'] )
				? Date::to_iso( (string) $row['created_at'] )
				: (string) ( $row['createdAt'] ?? Date::to_iso( Date::now() ) ),
			'updatedAt'      => ! empty( $row['updated_at'] )
				? Date::to_iso( (string) $row['updated_at'] )
				: (string) ( $row['updatedAt'] ?? Date::to_iso( Date::now() ) ),
		);
	}

	/**
	 * Format a list of raw URL rows into API-ready items.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw URL rows.
	 * @return array<int, array<string, mixed>> Formatted URL items.
	 * @since 1.0.0
	 */
	private function format_url_list( array $rows ): array {
		return array_map(
			fn( array $row ): array => $this->format_url( $row ),
			$rows,
		);
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
	 * @param string               $type    Activity type (e.g. 'link_created', 'link_trashed', 'link_deleted', 'link_restored').
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
	 * @since 1.0.4
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

	/**
	 * Apply a link payload filter hook.
	 *
	 * @param string               $hook_name Hook name.
	 * @param array<string, mixed> $payload   Input payload.
	 * @param mixed                ...$args   Additional parameters.
	 * @return array<string, mixed> Filtered payload.
	 * @since 1.2.2
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
	 * Automatically purge stale trashed links according to retention policy.
	 *
	 * @param int $days        Retention days (0 or negative means disabled).
	 * @param int $batch_limit Batch limit.
	 * @return int Number of purged links.
	 * @since 1.7.0
	 */
	public function purge_stale_trashed_links( int $days, int $batch_limit = 100 ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );
		$rows   = $this->repository->get_stale_trashed_links( $cutoff, $batch_limit );

		if ( empty( $rows ) ) {
			return 0;
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return 0;
		}

		$deleted_count = $this->repository->delete_links_permanently( $ids );

		$this->social_preview->delete_link_images(
			array_column( $rows, 'social_image_path' ),
		);

		foreach ( $rows as $deleted_row ) {
			$this->invalidate_link_cache( $deleted_row );
			\do_action( 'link_deleted', $deleted_row, null, null );
		}

		return $deleted_count;
	}

	/**
	 * Process and transition active links that have expired.
	 *
	 * @param int $batch_limit Maximum links to expire in one batch.
	 * @return int Number of expired links processed.
	 * @since 1.7.0
	 */
	public function expire_due_links( int $batch_limit = 100 ): int {
		$due_links = $this->repository->expire_due_links( $batch_limit );

		if ( empty( $due_links ) ) {
			return 0;
		}

		foreach ( $due_links as $link ) {
			$link['status'] = 'expired';
			$this->invalidate_link_cache( $link );
			\do_action( 'link_expired', $link );
			$this->webhooks_service->dispatch_link_event( 'link.expired', $this->format_url( $link ) );
		}

		return count( $due_links );
	}
}
