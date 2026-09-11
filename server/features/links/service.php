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
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Security\Security;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Captcha;
use PeakURL\Services\SocialPreview;
use PeakURL\Utils\Date;
use PeakURL\Services\Database\Query;
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
	private Repository $data;

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
	 * CAPTCHA verification service.
	 *
	 * @var Captcha
	 * @since 1.2.0
	 */
	private Captcha $captcha;

	/**
	 * Runtime configuration values.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

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
		array $config
	) {
		$this->data              = $data;
		$this->validator         = $validator;
		$this->settings_api      = $settings_api;
		$this->auth_service      = $auth_service;
		$this->analytics_service = $analytics_service;
		$this->webhooks_service  = $webhooks_service;
		$this->social_preview    = $social_preview;
		$this->captcha           = $captcha;
		$this->roles             = $roles;
		$this->authorization     = $authorization;
		$this->config            = $config;
	}

	/**
	 * Find a URL row by ID, short code, or alias.
	 *
	 * @param string $id URL ID, short code, or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function find_url_row( string $id ): ?array {
		return $this->data->find_url_row( $id );
	}

	/**
	 * Get the Repository instance.
	 *
	 * @return Repository
	 * @since 1.0.0
	 */
	public function get_data(): Repository {
		return $this->data;
	}

	/**
	 * Get the Repository instance.
	 *
	 * @return Repository
	 * @since 1.6.3
	 */
	public function get_repository(): Repository {
		return $this->data;
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
	 * List short URLs with pagination, sorting, and optional search.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Query parameters for pagination/sorting/search.
	 * @return array<string, mixed> Paginated URL list with meta.
	 * @since 1.0.0
	 */
	public function list_urls( Request $request, array $query ): array {
		$pagination = Query::pagination( $query, 25 );
		$page       = $pagination['page'];
		$limit      = $pagination['limit'];
		$offset     = $pagination['offset'];

		$user    = $this->auth_service->get_current_user( $request );
		$listing = $this->data->prepare_url_listing_query(
			$user,
			$query,
			fn( array $u, array &$c, array &$p, string $a ) => $this->authorization->scope_link_visibility( $u, $c, $p, $a ),
			fn( string $r, string $f, string $t ) => $this->analytics_service->get_link_stats_period( $r, $f, $t ),
		);

		$count      = $this->data->count_url_listing_rows(
			$listing['where'],
			$listing['params'],
		);
		$aggregates = $this->aggregate_url_listing_stats(
			$query,
			$listing['where'],
			$listing['params'],
			$listing['statsParams'],
		);
		$rows       = $this->data->query_url_listing_rows(
			$listing['where'],
			$listing['params'],
			$listing['sortBy'],
			$listing['sortOrder'],
			$limit,
			$offset,
			$listing['statsParams'],
		);

		$meta = array(
			'page'         => $page,
			'limit'        => $limit,
			'totalItems'   => $count,
			'totalPages'   => max( 1, (int) ceil( $count / $limit ) ),
			'totalClicks'  => $aggregates['totalClicks'],
			'uniqueClicks' => $aggregates['uniqueClicks'],
			'activeLinks'  => $aggregates['activeLinks'],
			'trashedLinks' => $this->count_trashed_links( $request ),
		);

		if ( isset( $aggregates['lastPeriodTotalClicks'] ) ) {
			$meta['lastPeriodTotalClicks']  = $aggregates['lastPeriodTotalClicks'];
			$meta['lastPeriodUniqueClicks'] = $aggregates['lastPeriodUniqueClicks'];
		}

		return array(
			'items' => $this->format_url_list( $rows ),
			'meta'  => $meta,
		);
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
		$user    = $this->auth_service->get_current_user( $request );
		$listing = $this->data->prepare_url_listing_query(
			$user,
			$query,
			fn( array $u, array &$c, array &$p, string $a ) => $this->authorization->scope_link_visibility( $u, $c, $p, $a ),
			fn( string $r, string $f, string $t ) => $this->analytics_service->get_link_stats_period( $r, $f, $t ),
		);
		$rows    = $this->data->query_url_listing_rows(
			$listing['where'],
			$listing['params'],
			$listing['sortBy'],
			$listing['sortOrder'],
			null,
			null,
			$listing['statsParams'],
		);
		$items   = $this->format_url_list( $rows );

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
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @return array<string, mixed>|null Formatted URL row or null.
	 * @since 1.0.0
	 */
	public function find_url( Request $request, string $id ): ?array {
		$user = $this->auth_service->get_current_user( $request );
		$row  = $this->data->find_url_row( $id );

		if ( $row ) {
			$this->authorization->validate_record_access(
				$user,
				(string) ( $row['user_id'] ?? '' ),
				'view_own_links',
				'view_all_links',
				__( 'You do not have permission to view this link.', 'peakurl' ),
			);
		}

		return $row ? $this->format_url( $row ) : null;
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
		$result = $this->get_link_access( $id, $request );

		return 'redirect' === $result['status']
			? (string) $result['location']
			: null;
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
		$code = $this->validator->sanitize_code( $id );

		if ( '' === $code ) {
			return array(
				'status' => 'not_found',
				'url'    => null,
			);
		}

		$url = $this->data->find_link_access_row( $code );

		if ( ! $url ) {
			return array(
				'status' => 'not_found',
				'url'    => null,
			);
		}

		if ( $this->validator->is_public_link_expired( $url ) ) {
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

		$allow_non_get_hit = false;
		$captcha_access    = $this->get_link_captcha_access( $url, $request );
		$captcha_protected = ! empty( $captcha_access['protected'] );

		if ( 'passed' === $captcha_access['status'] ) {
			$allow_non_get_hit = true;
		} elseif ( 'open' !== $captcha_access['status'] ) {
			return $captcha_access;
		}

		if ( ! empty( $url['password_value'] ) ) {
			$cookie_name     = $this->link_cookie_name( $url );
			$expected_cookie = $this->link_cookie_value( $url );
			$cookie_value    = (string) $request->get_cookie( $cookie_name, '' );

			if (
				'' !== $cookie_value &&
				hash_equals( $expected_cookie, $cookie_value )
			) {
				$this->analytics_service->record_click( $url, $request, $allow_non_get_hit );

				return array(
					'status'           => 'redirect',
					'url'              => $url,
					'location'         => (string) $url['destination_url'],
					'captchaProtected' => $captcha_protected,
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
						'message' => __( 'Enter the password to open this link.', 'peakurl' ),
					);
				}

				if ( $this->validator->link_password_matches( $url, $password_attempt ) ) {
					$request->queue_cookie(
						$cookie_name,
						$expected_cookie,
						$this->link_cookie_options(
							$request,
							$url,
						),
					);
					$this->analytics_service->record_click( $url, $request, true );

					return array(
						'status'           => 'redirect',
						'url'              => $url,
						'location'         => (string) $url['destination_url'],
						'captchaProtected' => $captcha_protected,
					);
				}

				return array(
					'status'  => 'password_invalid',
					'url'     => $url,
					'message' => __( 'The password for this link is incorrect.', 'peakurl' ),
				);
			}

			return array(
				'status' => 'password_required',
				'url'    => $url,
			);
		}

		$this->analytics_service->record_click( $url, $request, $allow_non_get_hit );

		return array(
			'status'           => 'redirect',
			'url'              => $url,
			'location'         => (string) $url['destination_url'],
			'captchaProtected' => $captcha_protected,
		);
	}

	/**
	 * Create a new short URL.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Creation payload.
	 * @return array<string, mixed> Formatted created URL.
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

		$payload         = $this->filter_link_payload(
			'pre_create_link',
			$payload,
			$request,
			$user,
		);
		$destination_url = $this->validator->clean_destination(
			$payload['destinationUrl'] ?? '',
		);

		$alias             = $this->validator->sanitize_code(
			(string) ( $payload['alias'] ?? '' ),
		);
		$uses_custom_alias = '' !== $alias;

		if ( '' === $alias ) {
			$alias = $this->generate_short_code();
		}

		$this->validator->validate_alias(
			$alias,
			'',
			fn( string $code ): bool => $this->data->short_code_exists( $code ),
		);

		$title = $this->get_url_title(
			$payload['title'] ?? '',
			$alias,
			$uses_custom_alias,
		);

		$id                = Str::random_id();
		$now               = Date::now();
		$password          = $this->validator->sanitize_link_password(
			$payload['password'] ?? '',
		);
		$social_preview    = $this->validator->normalize_link_social_preview(
			$payload,
			$this->social_preview,
		);
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
				$social_image_path = $this->social_preview->save_link_image(
					$id,
					$social_image_file,
					false,
					'',
				);
			} catch ( \RuntimeException $exception ) {
				throw new ApiException( $exception->getMessage(), 422 );
			}
		}

		try {
			$this->data->insert_url(
				array(
					'id'                 => $id,
					'user_id'            => $user['id'],
					'short_code'         => $alias,
					'alias'              => $alias,
					'title'              => '' !== $title ? $title : null,
					'destination_url'    => $destination_url,
					'social_title'       => $social_preview['title'],
					'social_description' => $social_preview['description'],
					'social_image_path'  => $social_image_path,
					'social_image_url'   => $social_image_url,
					'password_value'     => '' !== $password
						? $this->validator->hash_link_password( $password )
						: null,
					'expires_at'         => $this->validator->normalize_datetime(
						$payload['expiresAt'] ?? null,
					),
					'status'             => $this->validator->normalize_url_status(
						(string) ( $payload['status'] ?? 'active' ),
					),
					'utm_source'         => ! empty( $payload['utmSource'] ) ? trim( (string) $payload['utmSource'] ) : null,
					'utm_medium'         => ! empty( $payload['utmMedium'] ) ? trim( (string) $payload['utmMedium'] ) : null,
					'utm_campaign'       => ! empty( $payload['utmCampaign'] ) ? trim( (string) $payload['utmCampaign'] ) : null,
					'utm_term'           => ! empty( $payload['utmTerm'] ) ? trim( (string) $payload['utmTerm'] ) : null,
					'utm_content'        => ! empty( $payload['utmContent'] ) ? trim( (string) $payload['utmContent'] ) : null,
					'created_at'         => $now,
					'updated_at'         => $now,
				),
			);
		} catch ( \Throwable $exception ) {
			$this->social_preview->delete_link_image( $social_image_path );
			throw $exception;
		}

		$link_title = ! empty( $title ) ? $title : '/' . $alias;

		$this->analytics_service->record_activity(
			'link_created',
			'Created new link "' . $link_title . '"',
			(string) $user['id'],
			$id,
			array(
				'link' => $this->get_link_activity_meta(
					array(
						'id'         => $id,
						'title'      => $title,
						'alias'      => $alias,
						'short_code' => $alias,
					),
				),
			),
		);

		$url = $this->format_url( $this->data->find_url_row( $id ) );

		$this->invalidate_link_cache( (string) ( $url['shortCode'] ?? '' ) );
		$this->invalidate_link_cache( (string) ( $url['alias'] ?? '' ) );
		$this->invalidate_link_cache( (string) ( $url['id'] ?? '' ) );

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
		if ( empty( $payload['urls'] ) || ! is_array( $payload['urls'] ) ) {
			throw new ApiException(
				__( 'The `urls` field is required and must be an array.', 'peakurl' ),
				422,
			);
		}

		$results = array();
		$errors  = 0;

		foreach ( $payload['urls'] as $item ) {
			if ( ! is_array( $item ) ) {
				++$errors;
				continue;
			}

			try {
				$results[] = $this->create_url(
					$request,
					$item,
				);
			} catch ( \Throwable $e ) {
				++$errors;
			}
		}

		return array(
			'created' => $results,
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
		$user     = $this->auth_service->get_current_user( $request );
		$existing = $this->data->get_link_by_id( $id );

		if ( ! $existing ) {
			return null;
		}

		$this->authorization->validate_record_access(
			$user,
			(string) ( $existing['user_id'] ?? '' ),
			'edit_own_links',
			'edit_all_links',
			__( 'You do not have permission to edit this link.', 'peakurl' ),
		);
		$payload = $this->filter_link_payload(
			'pre_update_link',
			$payload,
			$id,
			$existing,
			$request,
			$user,
		);

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
				fn( string $code ): bool => $this->data->short_code_exists( $code ),
			);

			$updates[]            = 'alias = :alias';
			$updates[]            = 'short_code = :short_code';
			$params['alias']      = $alias;
			$params['short_code'] = $alias;
		}

		if ( empty( $updates ) ) {
			return $this->format_url( $this->data->find_url_row( $id ) );
		}

		$updates[]            = 'updated_at = :updated_at';
		$params['updated_at'] = Date::now();

		$this->data->update_url_fields( $id, $updates, $params );

		if ( '' !== $delete_social_image ) {
			$this->social_preview->delete_link_image(
				$delete_social_image,
			);
		}

		$updated_row = $this->data->find_url_row( $id );

		$this->analytics_service->record_activity(
			'link_updated',
			'Updated link ' . ( $params['alias'] ?? $existing['alias'] ) . '.',
			(string) $user['id'],
			$id,
			array(
				'link' => $this->get_link_activity_meta(
					$updated_row ? $updated_row : $existing,
				),
			),
		);

		$url = $this->format_url( $updated_row );

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

		$this->webhooks_service->dispatch_link_event( 'link.updated', $url, $user, $existing );

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
	 * @since 1.0.0
	 */
	public function delete_url( Request $request, string $id, bool $force = false ): bool {
		$user = $this->auth_service->get_current_user( $request );
		$row  = $this->data->get_link_by_id( $id );

		if ( ! $row ) {
			return false;
		}

		$this->authorization->validate_record_access(
			$user,
			(string) ( $row['user_id'] ?? '' ),
			'delete_own_links',
			'delete_all_links',
			__( 'You do not have permission to delete this link.', 'peakurl' ),
		);

		$is_trashed = 'trashed' === (string) ( $row['status'] ?? 'active' );
		$permanent  = $force || $is_trashed;
		$link_title = ! empty( $row['title'] )
			? (string) $row['title']
			: '/' . (string) ( $row['alias'] ?? $row['short_code'] ?? $id );

		if ( ! $permanent ) {
			\do_action( 'pre_trash_link', $row, $request, $user );

			$updated = $this->data->trash_url( $id, Date::now() );

			if ( $updated ) {
				$this->invalidate_link_cache( $row );

				$this->analytics_service->record_activity(
					'link_trashed',
					'Moved link "' . $link_title . '" to trash',
					(string) $user['id'],
					$id,
					array(
						'link' => $this->get_link_activity_meta( $row ),
					),
				);

				\do_action( 'link_trashed', $row, $request, $user );

				$this->webhooks_service->dispatch_link_event( 'link.deleted', $row, $user );
			}

			return $updated;
		}

		\do_action( 'pre_delete_link', $row, $request, $user );

		$this->analytics_service->record_activity(
			'link_deleted',
			'Permanently deleted link "' . $link_title . '"',
			(string) $user['id'],
			null,
			array(
				'link' => $this->get_link_activity_meta( $row ),
			),
		);

		$deleted = $this->data->delete_url_permanent( $id );

		if ( $deleted ) {
			$this->invalidate_link_cache( $row );

			$this->social_preview->delete_link_image(
				(string) ( $row['social_image_path'] ?? '' ),
			);

			\do_action( 'link_deleted', $row, $request, $user );

			$this->webhooks_service->dispatch_link_event( 'link.deleted', $row, $user );
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
	 * @since 1.6.0
	 */
	public function restore_url( Request $request, string $id ): array {
		$user = $this->auth_service->get_current_user( $request );
		$row  = $this->data->get_link_by_id( $id );

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

		$this->authorization->validate_record_access(
			$user,
			(string) ( $row['user_id'] ?? '' ),
			'edit_own_links',
			'edit_all_links',
			__( 'You do not have permission to restore this link.', 'peakurl' ),
		);

		$now = Date::now();
		$this->data->restore_url( $id, $now );

		$row['status']     = 'active';
		$row['updated_at'] = $now;
		$link_title        = ! empty( $row['title'] )
			? (string) $row['title']
			: '/' . (string) ( $row['alias'] ?? $row['short_code'] ?? $id );

		$this->analytics_service->record_activity(
			'link_restored',
			'Restored link "' . $link_title . '"',
			(string) $user['id'],
			$id,
			array(
				'link' => $this->get_link_activity_meta( $row ),
			),
		);

		$this->invalidate_link_cache( $row );

		\do_action( 'link_restored', $row, $request, $user );

		return $this->format_url( $row );
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
		$user = $this->auth_service->get_current_user( $request );
		$ids  = Query::string_ids( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$allowed_ids = $ids;

		if ( ! $this->roles->has_capability( $user, 'delete_all_links' ) ) {
			if ( ! $this->roles->has_capability( $user, 'delete_own_links' ) ) {
				throw new ApiException(
					__( 'You do not have permission to delete links.', 'peakurl' ),
					403,
				);
			}

			$allowed_ids = $this->data->get_allowed_ids_for_user(
				$ids,
				(string) $user['id'],
			);
		}

		if ( empty( $allowed_ids ) ) {
			return 0;
		}

		$target_rows = $this->data->get_links_by_ids( $allowed_ids );

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

		if ( ! $permanent ) {
			$now         = Date::now();
			$trashed_ids = array();

			foreach ( $target_rows as $row ) {
				$row_id = (string) ( $row['id'] ?? '' );
				if ( '' === $row_id ) {
					continue;
				}

				$this->data->trash_url( $row_id, $now );

				$link_title = ! empty( $row['title'] )
					? (string) $row['title']
					: '/' . (string) ( $row['alias'] ?? $row['short_code'] ?? $row_id );

				$this->analytics_service->record_activity(
					'link_trashed',
					'Moved link "' . $link_title . '" to trash',
					(string) $user['id'],
					$row_id,
					array(
						'link' => $this->get_link_activity_meta( $row ),
					),
				);

				$trashed_ids[] = $row_id;
				$this->invalidate_link_cache( $row );

				\do_action( 'link_trashed', $row, $request, $user );

				$this->webhooks_service->dispatch_link_event( 'link.deleted', $row, $user );
			}

			return count( $trashed_ids );
		}

		foreach ( $target_rows as $deleted_row ) {
			$link_title = ! empty( $deleted_row['title'] )
				? (string) $deleted_row['title']
				: '/' . (string) ( $deleted_row['alias'] ?? $deleted_row['short_code'] ?? $deleted_row['id'] );

			$this->analytics_service->record_activity(
				'link_deleted',
				'Permanently deleted link "' . $link_title . '"',
				(string) $user['id'],
				null,
				array(
					'link' => $this->get_link_activity_meta( $deleted_row ),
				),
			);
		}

		$deleted_count = $this->data->bulk_delete_permanent( $allowed_ids );

		$this->social_preview->delete_link_images(
			array_column( $target_rows, 'social_image_path' ),
		);

		foreach ( $target_rows as $deleted_row ) {
			$this->invalidate_link_cache( $deleted_row );

			\do_action( 'link_deleted', $deleted_row, $request, $user );

			$this->webhooks_service->dispatch_link_event( 'link.deleted', $deleted_row, $user );
		}

		return $deleted_count;
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
		$user = $this->auth_service->get_current_user( $request );
		$ids  = Query::string_ids( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$allowed_ids = $ids;

		if ( ! $this->roles->has_capability( $user, 'edit_all_links' ) ) {
			if ( ! $this->roles->has_capability( $user, 'edit_own_links' ) ) {
				throw new ApiException(
					__( 'You do not have permission to restore links.', 'peakurl' ),
					403,
				);
			}

			$allowed_ids = $this->data->get_allowed_ids_for_user(
				$ids,
				(string) $user['id'],
			);
		}

		if ( empty( $allowed_ids ) ) {
			return 0;
		}

		$target_rows = $this->data->get_links_by_ids( $allowed_ids );

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

			$this->data->restore_url( $row_id, $now );

			$link_title = ! empty( $row['title'] )
				? (string) $row['title']
				: '/' . (string) ( $row['alias'] ?? $row['short_code'] ?? $row_id );

			$this->analytics_service->record_activity(
				'link_restored',
				'Restored link "' . $link_title . '"',
				(string) $user['id'],
				$row_id,
				array(
					'link' => $this->get_link_activity_meta( $row ),
				),
			);

			$restored_ids[] = $row_id;
			$this->invalidate_link_cache( $row );

			\do_action( 'link_restored', $row, $request, $user );
		}

		return count( $restored_ids );
	}

	/**
	 * Permanently empty all trashed short URLs for the current user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Number of rows permanently deleted.
	 * @since 1.6.0
	 */
	public function empty_trash( Request $request ): int {
		$user = $this->auth_service->get_current_user( $request );

		$this->authorization->validate_capability(
			$user,
			'delete_own_links',
			__( 'You do not have permission to empty trash.', 'peakurl' ),
		);

		$rows = $this->data->get_all_trashed_links(
			$user,
			fn( array $u, array &$c, array &$p, string $a ) => $this->authorization->scope_link_visibility( $u, $c, $p, $a ),
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$ids = array_map( 'strval', array_column( $rows, 'id' ) );

		foreach ( $rows as $deleted_row ) {
			$link_title = ! empty( $deleted_row['title'] )
				? (string) $deleted_row['title']
				: '/' . (string) ( $deleted_row['alias'] ?? $deleted_row['short_code'] ?? $deleted_row['id'] );

			$this->analytics_service->record_activity(
				'link_deleted',
				'Permanently deleted link "' . $link_title . '"',
				(string) $user['id'],
				null,
				array(
					'link' => $this->get_link_activity_meta( $deleted_row ),
				),
			);
		}

		$deleted_count = $this->data->bulk_delete_permanent( $ids );

		$this->social_preview->delete_link_images(
			array_column( $rows, 'social_image_path' ),
		);

		foreach ( $rows as $deleted_row ) {
			$this->invalidate_link_cache( $deleted_row );

			\do_action( 'link_deleted', $deleted_row, $request, $user );

			$this->webhooks_service->dispatch_link_event( 'link.deleted', $deleted_row, $user );
		}

		return $deleted_count;
	}

	/**
	 * Permanently delete all accessible short URLs for the current user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Number of deleted URLs.
	 * @since 1.5.3
	 */
	public function clear_urls( Request $request ): int {
		$user = $this->auth_service->get_current_user( $request );

		$this->authorization->validate_capability(
			$user,
			'delete_own_links',
			__( 'You do not have permission to delete links.', 'peakurl' ),
		);

		$rows = $this->data->get_all_accessible_links(
			$user,
			fn( array $u, array &$c, array &$p, string $a ) => $this->authorization->scope_link_visibility( $u, $c, $p, $a ),
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$ids = array_map( 'strval', array_column( $rows, 'id' ) );

		foreach ( $rows as $deleted_row ) {
			$link_title = ! empty( $deleted_row['title'] )
				? (string) $deleted_row['title']
				: '/' . (string) ( $deleted_row['alias'] ?? $deleted_row['short_code'] ?? $deleted_row['id'] );

			$this->analytics_service->record_activity(
				'link_deleted',
				'Permanently deleted link "' . $link_title . '"',
				(string) $user['id'],
				null,
				array(
					'link' => $this->get_link_activity_meta( $deleted_row ),
				),
			);
		}

		$deleted_count = $this->data->bulk_delete_permanent( $ids );

		$this->social_preview->delete_link_images(
			array_column( $rows, 'social_image_path' ),
		);

		foreach ( $rows as $deleted_row ) {
			$this->invalidate_link_cache( $deleted_row );

			\do_action( 'link_deleted', $deleted_row, $request, $user );

			$this->webhooks_service->dispatch_link_event( 'link.deleted', $deleted_row, $user );
		}

		return $deleted_count;
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

		$url = $this->data->find_link_access_row( $code );

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
		$user = $this->auth_service->get_current_user( $request );

		return $this->data->count_trashed_links(
			$user,
			fn( array $u, array &$c, array &$p, string $a ) => $this->authorization->scope_link_visibility( $u, $c, $p, $a ),
		);
	}

	/**
	 * Invalidate object cache entries associated with a link.
	 *
	 * @param mixed $link Row array, short code string, or ID.
	 * @return void
	 * @since 1.6.0
	 */
	private function invalidate_link_cache( $link ): void {
		$this->data->get_links_api()->invalidate_link_cache( $link );
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

		static $site_url = null;

		if ( null === $site_url ) {
			$site_url = rtrim( \get_site_url(), '/' );
		}

		$alias     = trim( (string) ( $row['alias'] ?? '' ) );
		$short_key = '' !== $alias
			? $alias
			: trim( (string) ( $row['short_code'] ?? '' ) );
		$short_url = '';

		if ( '' !== $site_url && '' !== $short_key ) {
			$short_url = $site_url . '/' . ltrim( $short_key, '/' );
		}

		return array(
			'id'             => (string) $row['id'],
			'shortCode'      => (string) $row['short_code'],
			'alias'          => (string) $row['alias'],
			'shortUrl'       => $short_url,
			'title'          => trim( (string) ( $row['title'] ?? '' ) ),
			'destinationUrl' => (string) $row['destination_url'],
			'socialPreview'  => array(
				'title'            => trim( (string) ( $row['social_title'] ?? '' ) ),
				'description'      => trim( (string) ( $row['social_description'] ?? '' ) ),
				'imageUrl'         => '' !== trim( (string) ( $row['social_image_url'] ?? '' ) )
					? trim( (string) $row['social_image_url'] )
					: $this->social_preview->get_link_image_url(
						(string) ( $row['social_image_path'] ?? '' ),
					),
				'externalImageUrl' => '' !== trim( (string) ( $row['social_image_url'] ?? '' ) )
					? trim( (string) $row['social_image_url'] )
					: null,
			),
			'domain'         => null,
			'clicks'         => (int) ( $row['click_count'] ?? 0 ),
			'uniqueClicks'   => (int) ( $row['unique_click_count'] ?? 0 ),
			'status'         => (string) ( $row['status'] ?? 'active' ),
			'hasPassword'    => '' !== trim(
				(string) ( $row['password_value'] ?? '' ),
			),
			'expiresAt'      => $row['expires_at']
				? Date::to_iso( (string) $row['expires_at'] )
				: null,
			'createdAt'      => Date::to_iso( (string) $row['created_at'] ),
			'updatedAt'      => Date::to_iso( (string) $row['updated_at'] ),
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
	 * Calculate total stats for the current listing query.
	 *
	 * @param array<string, mixed>  $query        Raw query parameters.
	 * @param string                $where        Prepared WHERE clause.
	 * @param array<string, mixed>  $params       Query parameters.
	 * @param array<string, string> $stats_params Optional click-stat query bounds.
	 * @return array<string, int>
	 * @since 1.5.2
	 */
	private function aggregate_url_listing_stats(
		array $query,
		string $where,
		array $params,
		array $stats_params
	): array {
		$aggregates = $this->data->aggregate_link_stats(
			$where,
			$params,
			$stats_params,
		);

		$range = trim( (string) ( $query['range'] ?? '' ) );
		if ( in_array( $range, array( '24h', '7d', '30d' ), true ) ) {
			$days        = '24h' === $range ? 1 : ( '30d' === $range ? 30 : 7 );
			$period      = $this->analytics_service->get_analytics_period( $days );
			$last_period = $this->analytics_service->get_last_month_period( $period, $days );

			$last_stats_params = array(
				'stats_start_at' => $last_period['start_at'],
				'stats_end_at'   => $last_period['end_at'],
			);

			$last_stats = $this->data->aggregate_link_clicks(
				$where,
				$params,
				$last_stats_params,
			);

			$aggregates['lastPeriodTotalClicks']  = $last_stats['totalClicks'];
			$aggregates['lastPeriodUniqueClicks'] = $last_stats['uniqueClicks'];
		}

		return $aggregates;
	}

	/**
	 * Return the stored title for a new link.
	 *
	 * @param mixed  $title             Raw request title value.
	 * @param string $alias             Final stored alias / short code.
	 * @param bool   $uses_custom_alias Whether the alias came from user input.
	 * @return string Normalized title value.
	 * @since 1.0.3
	 */
	private function get_url_title(
		$title,
		string $alias,
		bool $uses_custom_alias
	): string {
		$normalized_title = trim( (string) $title );

		if ( '' !== $normalized_title ) {
			return trim( html_entity_decode( $normalized_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		if ( $uses_custom_alias ) {
			return $this->format_alias_title( $alias );
		}

		return '';
	}

	/**
	 * Get a default title from a custom alias.
	 *
	 * @param string $alias Final stored alias / short code.
	 * @return string
	 * @since 1.0.14
	 */
	private function format_alias_title( string $alias ): string {
		if ( '' === $alias ) {
			return '';
		}

		if (
			function_exists( 'mb_substr' ) &&
			function_exists( 'mb_strtoupper' )
		) {
			return mb_strtoupper(
				mb_substr( $alias, 0, 1, 'UTF-8' ),
				'UTF-8'
			) . mb_substr( $alias, 1, null, 'UTF-8' );
		}

		return strtoupper( substr( $alias, 0, 1 ) ) . substr( $alias, 1 );
	}

	/**
	 * Generate a unique random 6-character short code.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function generate_short_code(): string {
		do {
			$code = substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		} while ( $this->data->short_code_exists( $code ) );

		return $code;
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
	 * Verify CAPTCHA status for public redirect requests.
	 *
	 * @param array<string, mixed> $url     Raw URL database row.
	 * @param Request              $request Incoming HTTP request.
	 * @return array<string, mixed> Access state for redirect handler.
	 * @since 1.2.0
	 */
	private function get_link_captcha_access(
		array $url,
		Request $request
	): array {
		$challenge = $this->captcha->get_challenge();

		if ( null === $challenge ) {
			return array(
				'status'    => 'open',
				'protected' => false,
			);
		}

		$cookie_name     = $this->link_captcha_cookie_name( $url );
		$expected_cookie = $this->link_captcha_cookie_value( $url, $challenge );
		$cookie_value    = (string) $request->get_cookie( $cookie_name, '' );

		if (
			'' !== $cookie_value &&
			hash_equals( $expected_cookie, $cookie_value )
		) {
			return array(
				'status'    => 'open',
				'protected' => true,
			);
		}

		if ( 'POST' !== $request->get_method() ) {
			return array(
				'status'    => 'captcha_required',
				'url'       => $url,
				'challenge' => $challenge,
				'protected' => true,
			);
		}

		$token = trim(
			(string) $request->get_body_param(
				(string) $challenge['responseField'],
				'',
			),
		);

		if ( '' === $token ) {
			return array(
				'status'    => 'captcha_required',
				'url'       => $url,
				'challenge' => $challenge,
				'protected' => true,
				'message'   => __( 'Complete the verification to open this link.', 'peakurl' ),
			);
		}

		if (
			! $this->captcha->verify_token(
				$token,
				$request->get_ip_address(),
			)
		) {
			return array(
				'status'    => 'captcha_invalid',
				'url'       => $url,
				'challenge' => $challenge,
				'protected' => true,
				'message'   => __( 'Verification failed. Please try again.', 'peakurl' ),
			);
		}

		$request->queue_cookie(
			$cookie_name,
			$expected_cookie,
			$this->link_captcha_cookie_options( $request, $url ),
		);

		return array(
			'status'    => 'passed',
			'protected' => true,
		);
	}

	/**
	 * Get the cookie name used for password-protected link access.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return string
	 * @since 1.0.0
	 */
	private function link_cookie_name( array $url ): string {
		return 'peakurl_link_access_' . (string) ( $url['id'] ?? '' );
	}

	/**
	 * Get the cookie value hash for password-authorized links.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return string
	 * @since 1.0.0
	 */
	private function link_cookie_value( array $url ): string {
		return hash(
			'sha256',
			(string) ( $url['id'] ?? '' ) . '|' . (string) ( $url['password_value'] ?? '' ),
		);
	}

	/**
	 * Get cookie options for password-authorized public links.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $url     Raw URL row.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function link_cookie_options(
		Request $request,
		array $url
	): array {
		return $this->link_access_cookie_options(
			$request,
			$url,
			30 * 24 * 60 * 60,
		);
	}

	/**
	 * Get the cookie name used after successful CAPTCHA verification.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return string
	 * @since 1.2.0
	 */
	private function link_captcha_cookie_name( array $url ): string {
		return 'peakurl_link_captcha_' . (string) ( $url['id'] ?? '' );
	}

	/**
	 * Get the signed cookie value for CAPTCHA verification.
	 *
	 * @param array<string, mixed>  $url       Raw URL row.
	 * @param array<string, string> $challenge Challenge details.
	 * @return string
	 * @since 1.2.0
	 */
	private function link_captcha_cookie_value(
		array $url,
		array $challenge
	): string {
		$payload = implode(
			'|',
			array(
				(string) ( $url['id'] ?? '' ),
				(string) ( $url['updated_at'] ?? '' ),
				(string) ( $challenge['provider'] ?? '' ),
				(string) ( $challenge['siteKey'] ?? '' ),
			),
		);
		$secret  = trim(
			(string) ( $this->config[ Constants::AUTH_SALT ] ?? '' ),
		);

		if ( '' === $secret ) {
			$secret = trim(
				(string) ( $this->config[ Constants::AUTH_KEY ] ?? '' ),
			);
		}

		return '' === $secret
			? hash( 'sha256', $payload )
			: hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Get cookie options for CAPTCHA-verified links.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $url     Raw URL row.
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	private function link_captcha_cookie_options(
		Request $request,
		array $url
	): array {
		return $this->link_access_cookie_options(
			$request,
			$url,
			12 * 60 * 60,
		);
	}

	/**
	 * Build shared cookie options for link access challenges.
	 *
	 * @param Request              $request         Incoming HTTP request.
	 * @param array<string, mixed> $url             Raw URL row.
	 * @param int                  $default_max_age Default max age in seconds.
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	private function link_access_cookie_options(
		Request $request,
		array $url,
		int $default_max_age
	): array {
		$options = Security::session_cookie_options(
			$this->config,
			$request,
			array(
				'samesite' => 'Lax',
			),
		);
		$max_age = $default_max_age;

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
}
