<?php
/**
 * Webhooks domain service.
 *
 * Coordinates webhook subscription persistence, payload delivery, HMAC signing,
 * and event dispatching.
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — Webhooks management and event delivery engine.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.0.0
	 */
	private AuthService $auth_service;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Webhook input validator.
	 *
	 * @var Validator
	 * @since 1.0.0
	 */
	private Validator $validator;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * In-memory cache of active webhook rows for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 * @since 1.0.0
	 */
	private ?array $active_webhooks_cache = null;

	/**
	 * Create a new Webhooks Service instance.
	 *
	 * @param PeakURL_DB           $db            Shared database wrapper.
	 * @param Validator            $validator     Webhook input validator.
	 * @param AuthService          $auth_service  Authentication domain service.
	 * @param Roles                $roles         Roles registry.
	 * @param Authorization        $authorization Shared authorization helper.
	 * @param array<string, mixed> $config        Runtime config map.
	 * @since 1.0.0
	 */
	public function __construct(
		PeakURL_DB $db,
		Validator $validator,
		AuthService $auth_service,
		Roles $roles,
		Authorization $authorization,
		array $config
	) {
		$this->db            = $db;
		$this->validator     = $validator;
		$this->auth_service  = $auth_service;
		$this->roles         = $roles;
		$this->authorization = $authorization;
		$this->config        = $config;
	}

	/**
	 * List all webhooks for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<int, array<string, mixed>> Webhook rows.
	 * @since 1.0.0
	 */
	public function list_webhooks( Request $request ): array {
		$user = $this->get_webhook_user( $request );
		$rows = $this->db->get_results_by(
			'webhooks',
			array( 'user_id' => $user['id'] ),
			array( '*' ),
			array( 'created_at' => 'DESC' ),
		);

		return array_map(
			fn( array $row ): array => $this->format_webhook( $row ),
			$rows,
		);
	}

	/**
	 * Register a new webhook endpoint.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Body with `url` and `events`.
	 * @return array<string, mixed> Created webhook record.
	 *
	 * @throws ApiException On validation failure (422).
	 * @since 1.0.0
	 */
	public function create_webhook( Request $request, array $payload ): array {
		$user      = $this->get_webhook_user( $request );
		$validated = $this->validator->validate_create( $payload );

		$row = array(
			'id'         => Str::random_id(),
			'user_id'    => $user['id'],
			'url'        => $validated['url'],
			'events'     => peakurl_json_encode( $validated['events'] ),
			'secret'     => 'whsec_' . bin2hex( random_bytes( 18 ) ),
			'is_active'  => 1,
			'created_at' => Date::now(),
			'updated_at' => Date::now(),
		);

		$this->db->insert( 'webhooks', $row );
		$this->clear_active_webhooks_cache();

		return $this->format_webhook( $row, true );
	}

	/**
	 * Update an existing webhook registration.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $id      Webhook row ID.
	 * @param array<string, mixed> $payload Updated webhook fields (url, events, isActive).
	 * @return array<string, mixed> Updated webhook record.
	 *
	 * @throws ApiException On validation failure or missing webhook.
	 * @since 1.0.0
	 */
	public function update_webhook( Request $request, string $id, array $payload ): array {
		$user    = $this->get_webhook_user( $request );
		$webhook = $this->db->get_row_by(
			'webhooks',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		);

		if ( ! $webhook && $this->roles->has_capability( $user, 'manage_webhooks' ) ) {
			$webhook = $this->db->get_row_by(
				'webhooks',
				array( 'id' => $id ),
			);
		}

		if ( ! $webhook ) {
			throw new ApiException( __( 'Webhook not found.', 'peakurl' ), 404 );
		}

		$updates = $this->validator->validate_update( $payload );

		if ( array_key_exists( 'events', $updates ) ) {
			$updates['events'] = peakurl_json_encode( $updates['events'] );
		}

		if ( ! empty( $updates ) ) {
			$updates['updated_at'] = Date::now();
			$this->db->update( 'webhooks', $updates, array( 'id' => $id ) );
			$webhook = array_merge( $webhook, $updates );
			$this->clear_active_webhooks_cache();
		}

		return $this->format_webhook( $webhook );
	}

	/**
	 * Delete a webhook by ID.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook row ID.
	 * @return bool True if a webhook was deleted.
	 * @since 1.0.0
	 */
	public function delete_webhook( Request $request, string $id ): bool {
		$user = $this->get_webhook_user( $request );

		$deleted = $this->db->delete(
			'webhooks',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		) > 0;

		if ( $deleted ) {
			$this->clear_active_webhooks_cache();
		}

		return $deleted;
	}

	/**
	 * Send a test webhook ping to verify receiver endpoint connectivity.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook row ID.
	 * @return array<string, mixed> Delivery outcome details.
	 *
	 * @throws ApiException When the webhook does not exist.
	 * @since 1.0.0
	 */
	public function test_webhook( Request $request, string $id ): array {
		$user    = $this->get_webhook_user( $request );
		$webhook = $this->db->get_row_by(
			'webhooks',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		);

		if ( ! $webhook && $this->roles->has_capability( $user, 'manage_webhooks' ) ) {
			$webhook = $this->db->get_row_by(
				'webhooks',
				array( 'id' => $id ),
			);
		}

		if ( ! $webhook ) {
			throw new ApiException( __( 'Webhook not found.', 'peakurl' ), 404 );
		}

		$events          = $this->decode_json_array( (string) ( $webhook['events'] ?? '[]' ) );
		$requested_event = trim( (string) $request->get_body_param( 'event', '' ) );
		$test_event      = '' !== $requested_event && in_array( $requested_event, $events, true )
			? $requested_event
			: ( ! empty( $events ) ? $events[0] : 'link.created' );

		$site_url  = $this->get_webhook_site_url();
		$test_link = array(
			'id'                => 'lnk_' . bin2hex( random_bytes( 8 ) ),
			'alias'             => 'summer-promo',
			'shortUrl'          => rtrim( $site_url, '/' ) . '/summer-promo',
			'destinationUrl'    => 'https://example.com/store/summer',
			'title'             => 'Summer Sale 2026',
			'status'            => 'active',
			'hasPassword'       => true,
			'expiresAt'         => gmdate( 'Y-12-31\T23:59:59\Z' ),
			'hasOpenGraph'      => true,
			'socialTitle'       => 'Huge Summer Sale!',
			'socialDescription' => 'Get 50% off all items.',
			'socialImageUrl'    => 'https://example.com/images/promo.jpg',
			'utmSource'         => 'newsletter',
			'utmMedium'         => 'email',
			'utmCampaign'       => 'summer_blast',
			'utmTerm'           => '',
			'utmContent'        => 'header_link',
			'createdAt'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'updatedAt'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		$test_previous = array(
			'alias'          => 'summer-promo-old',
			'shortUrl'       => rtrim( $site_url, '/' ) . '/summer-promo-old',
			'destinationUrl' => 'https://example.com/store/summer-old',
			'title'          => 'Summer Sale 2025',
		);

		$test_click = array(
			'visitor_hash'      => hash( 'sha256', 'test-visitor' ),
			'ip_address'        => $request->get_ip_address() ?? '192.168.1.1',
			'country_name'      => 'United States',
			'country_code'      => 'US',
			'city_name'         => 'Austin',
			'device'            => 'desktop',
			'browser'           => 'Chrome',
			'operating_system'  => 'macOS',
			'referrer_name'     => 'Hacker News',
			'referrer_domain'   => 'news.ycombinator.com',
			'referrer_category' => 'Social',
			'utm_source'        => 'hackernews',
			'utm_medium'        => 'social',
			'utm_campaign'      => 'launch',
			'utm_term'          => '',
			'utm_content'       => '',
			'user_agent'        => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Safari/537.36',
			'clicked_at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		$test_data = $this->prepare_link_event_payload(
			$test_event,
			$test_link,
			$user,
			$test_previous,
			$test_click,
		);

		$event_id = Str::random_id();
		$now_ts   = time();
		$payload  = array(
			'success'    => true,
			'statusCode' => 200,
			'message'    => 'Webhook event dispatched.',
			'event'      => $test_event,
			'id'         => $event_id,
			'timestamp'  => $now_ts,
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
			'data'       => $test_data,
		);

		return $this->send_webhook_payload( $webhook, $payload, 5.0 );
	}

	/**
	 * Retrieve all active webhook records, utilizing per-request in-memory cache.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	private function get_active_webhooks(): array {
		if ( null !== $this->active_webhooks_cache ) {
			return $this->active_webhooks_cache;
		}

		$this->active_webhooks_cache = $this->db->get_results(
			'SELECT w.*, u.role AS user_role
			FROM webhooks w
			LEFT JOIN users u ON u.id = w.user_id
			WHERE w.is_active = 1'
		);

		return $this->active_webhooks_cache;
	}

	/**
	 * Clear the active webhooks in-memory cache.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function clear_active_webhooks_cache(): void {
		$this->active_webhooks_cache = null;
	}

	/**
	 * Filter active webhooks subscribed to a specific event and authorized for a user.
	 *
	 * @param string          $event   Webhook event identifier.
	 * @param int|string|null $user_id Owner or actor user ID.
	 * @return array<int, array<string, mixed>> Matching webhook rows.
	 * @since 1.0.0
	 */
	private function get_subscribed_webhooks( string $event, int|string|null $user_id ): array {
		$webhooks = $this->get_active_webhooks();
		if ( empty( $webhooks ) ) {
			return array();
		}

		$matching = array();
		foreach ( $webhooks as $webhook ) {
			$subscribed = $this->decode_json_array(
				(string) ( $webhook['events'] ?? '[]' ),
			);

			if ( ! in_array( $event, $subscribed, true ) ) {
				continue;
			}

			$is_admin = 'admin' === (string) ( $webhook['user_role'] ?? '' );
			$is_owner = null !== $user_id && (string) $webhook['user_id'] === (string) $user_id;

			if ( ! $is_admin && ! $is_owner ) {
				continue;
			}

			$matching[] = $webhook;
		}

		return $matching;
	}

	/**
	 * Dispatch a webhook event to all subscribed and eligible webhook endpoints.
	 *
	 * @param int|string|null      $user_id User ID associated with the link event.
	 * @param string               $event   Event identifier.
	 * @param array<string, mixed> $data    Event payload data block.
	 * @param bool                 $fast    Whether to enforce tight latency limits.
	 * @return array<int, array<string, mixed>> Delivery results per webhook.
	 * @since 1.0.0
	 */
	public function dispatch_webhook_event(
		int|string|null $user_id,
		string $event,
		array $data,
		bool $fast = false
	): array {
		try {
			$matching_webhooks = $this->get_subscribed_webhooks( $event, $user_id );

			if ( empty( $matching_webhooks ) ) {
				return array();
			}

			return $this->dispatch_webhook_event_to_targets( $matching_webhooks, $event, $data, $fast );
		} catch ( \Throwable $exception ) {
			error_log( 'PeakURL Webhook Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Dispatch a prepared event payload to matching webhook targets.
	 *
	 * @param array<int, array<string, mixed>> $targets Subscribed webhook rows.
	 * @param string                           $event   Webhook event identifier.
	 * @param array<string, mixed>             $data    Event payload data block.
	 * @param bool                             $fast    Whether to enforce tight latency limits.
	 * @return array<int, array<string, mixed>> Delivery results per webhook.
	 * @since 1.0.0
	 */
	private function dispatch_webhook_event_to_targets(
		array $targets,
		string $event,
		array $data,
		bool $fast = false
	): array {
		$now_ts   = time();
		$now_iso  = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );
		$event_id = Str::random_id();

		$payload = array(
			'success'    => true,
			'statusCode' => 200,
			'message'    => 'Webhook event dispatched.',
			'event'      => $event,
			'id'         => $event_id,
			'timestamp'  => $now_ts,
			'created_at' => $now_iso,
			'data'       => $data,
		);

		$timeout = $fast ? 1.5 : 3.0;

		if ( count( $targets ) > 1 && function_exists( 'curl_multi_init' ) ) {
			return $this->post_webhooks_parallel( $targets, $payload, $timeout );
		}

		$results = array();
		foreach ( $targets as $webhook ) {
			$results[] = $this->send_webhook_payload( $webhook, $payload, $timeout );
		}

		return $results;
	}

	/**
	 * Build payload and dispatch a link lifecycle or click event to webhooks.
	 *
	 * @param string                    $event     Webhook event identifier.
	 * @param array<string, mixed>      $link_data Formatted link array or database row.
	 * @param array<string, mixed>|null $user      Current user row or null.
	 * @param array<string, mixed>|null $previous  Previous link state for updates.
	 * @param array<string, mixed>|null $click     Recorded click details for click events.
	 * @return array<int, array<string, mixed>> Delivery results.
	 * @since 1.0.0
	 */
	public function dispatch_link_event(
		string $event,
		array $link_data,
		?array $user = null,
		?array $previous = null,
		?array $click = null
	): array {
		try {
			$owner_id = $user['id'] ?? $link_data['user_id'] ?? null;
			$targets  = $this->get_subscribed_webhooks( $event, $owner_id );

			if ( empty( $targets ) ) {
				return array();
			}

			$data = $this->prepare_link_event_payload(
				$event,
				$link_data,
				$user,
				$previous,
				$click,
			);

			return $this->dispatch_webhook_event_to_targets(
				$targets,
				$event,
				$data,
				'link.clicked' === $event,
			);
		} catch ( \Throwable $exception ) {
			error_log( 'PeakURL Webhook Link Event Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Prepare normalized event data block conforming to PeakURL webhook schemas.
	 *
	 * @param string                    $event     Webhook event identifier.
	 * @param array<string, mixed>      $link_data Link array or database row.
	 * @param array<string, mixed>|null $user      Current user row or null.
	 * @param array<string, mixed>|null $previous  Previous state for link updates.
	 * @param array<string, mixed>|null $click     Recorded click details.
	 * @return array<string, mixed> Normalized payload data.
	 * @since 1.0.0
	 */
	public function prepare_link_event_payload(
		string $event,
		array $link_data,
		?array $user = null,
		?array $previous = null,
		?array $click = null
	): array {
		$short_code = (string) ( $link_data['shortCode'] ?? $link_data['short_code'] ?? $link_data['alias'] ?? '' );
		$id         = (string) ( $link_data['id'] ?? '' );
		$dest_url   = (string) ( $link_data['destinationUrl'] ?? $link_data['destination_url'] ?? $link_data['url'] ?? '' );

		if ( '' !== $id && ( ! array_key_exists( 'utm_source', $link_data ) && ! array_key_exists( 'utmSource', $link_data ) ) ) {
			try {
				$db_row = $this->db->get_row_by( 'urls', array( 'id' => $id ) );
				if ( $db_row && is_array( $db_row ) ) {
					$link_data = array_merge( $db_row, $link_data );
					if ( '' === $short_code ) {
						$short_code = (string) ( $db_row['alias'] ?? $db_row['short_code'] ?? '' );
					}
					if ( '' === $dest_url ) {
						$dest_url = (string) ( $db_row['destination_url'] ?? '' );
					}
				}
			} catch ( \Throwable $e ) {
				// Non-fatal database lookup fallback.
			}
		}

		$raw_title  = trim( (string) ( $link_data['title'] ?? '' ) );
		$meta_title = trim( (string) ( $link_data['meta_title'] ?? $link_data['social_title'] ?? ( isset( $link_data['socialPreview']['title'] ) ? $link_data['socialPreview']['title'] : '' ) ) );
		$title      = '' !== $raw_title ? $raw_title : ( '' !== $meta_title ? $meta_title : 'Untitled' );

		$short_url = (string) ( $link_data['shortUrl'] ?? $link_data['short_url'] ?? '' );

		if ( '' === $short_url && '' !== $short_code ) {
			$short_url = $this->get_webhook_site_url( rawurlencode( $short_code ) );
		}

		$user_data = array(
			'id'        => 0,
			'username'  => '',
			'name'      => '',
			'firstName' => '',
			'lastName'  => '',
			'email'     => '',
			'role'      => '',
			'company'   => '',
			'jobTitle'  => '',
		);

		if ( ! empty( $user ) && is_array( $user ) ) {
			$display_name = trim( (string) ( $user['display_name'] ?? $user['displayName'] ?? $user['name'] ?? $user['username'] ?? '' ) );
			$username     = (string) ( $user['username'] ?? '' );
			$user_data    = array(
				'id'        => (int) ( $user['id'] ?? 0 ),
				'username'  => $username,
				'name'      => '' !== $display_name ? $display_name : $username,
				'firstName' => (string) ( $user['first_name'] ?? $user['firstName'] ?? '' ),
				'lastName'  => (string) ( $user['last_name'] ?? $user['lastName'] ?? '' ),
				'email'     => (string) ( $user['email'] ?? '' ),
				'role'      => (string) ( $user['role'] ?? 'admin' ),
				'company'   => (string) ( $user['company'] ?? '' ),
				'jobTitle'  => (string) ( $user['job_title'] ?? $user['jobTitle'] ?? '' ),
			);
		} elseif ( ! empty( $link_data['user_id'] ) || ! empty( $link_data['userId'] ) ) {
			$lookup_user_id = $link_data['user_id'] ?? $link_data['userId'];
			try {
				$user_row = $this->db->get_row_by(
					'users',
					array( 'id' => $lookup_user_id ),
					array( 'id', 'username', 'display_name', 'email', 'first_name', 'last_name', 'role', 'company', 'job_title' ),
				);
				if ( $user_row && is_array( $user_row ) ) {
					$display_name = trim( (string) ( $user_row['display_name'] ?? '' ) );
					$username     = (string) ( $user_row['username'] ?? '' );
					$user_data    = array(
						'id'        => (int) $user_row['id'],
						'username'  => $username,
						'name'      => '' !== $display_name ? $display_name : $username,
						'firstName' => (string) ( $user_row['first_name'] ?? '' ),
						'lastName'  => (string) ( $user_row['last_name'] ?? '' ),
						'email'     => (string) ( $user_row['email'] ?? '' ),
						'role'      => (string) ( $user_row['role'] ?? 'admin' ),
						'company'   => (string) ( $user_row['company'] ?? '' ),
						'jobTitle'  => (string) ( $user_row['job_title'] ?? '' ),
					);
				}
			} catch ( \Throwable $e ) {
				// Non-fatal user lookup fallback.
			}
		}

		if ( 'link.deleted' === $event ) {
			return array(
				'id'             => $id,
				'alias'          => $short_code,
				'shortUrl'       => $short_url,
				'destinationUrl' => $dest_url,
				'title'          => $title,
				'user'           => $user_data,
				'deletedAt'      => Date::to_iso( Date::now() ),
			);
		}

		if ( 'link.clicked' === $event ) {
			return array(
				'id'               => $id,
				'alias'            => $short_code,
				'shortUrl'         => $short_url,
				'destinationUrl'   => $dest_url,
				'title'            => $title,
				'visitorHash'      => (string) ( $click['visitor_hash'] ?? $click['visitorHash'] ?? '' ),
				'ip'               => (string) ( $click['ip_address'] ?? $click['ip'] ?? '' ),
				'country'          => (string) ( $click['country_name'] ?? $click['country'] ?? '' ),
				'countryCode'      => (string) ( $click['country_code'] ?? $click['countryCode'] ?? '' ),
				'city'             => (string) ( $click['city_name'] ?? $click['city'] ?? '' ),
				'device'           => (string) ( $click['device'] ?? '' ),
				'browser'          => (string) ( $click['browser'] ?? '' ),
				'os'               => (string) ( $click['operating_system'] ?? $click['os'] ?? '' ),
				'referrerName'     => (string) ( $click['referrer_name'] ?? $click['referrerName'] ?? '' ),
				'referrerDomain'   => (string) ( $click['referrer_domain'] ?? $click['referrerDomain'] ?? '' ),
				'referrerCategory' => (string) ( $click['referrer_category'] ?? $click['referrerCategory'] ?? '' ),
				'utmSource'        => (string) ( $click['utm_source'] ?? $click['utmSource'] ?? '' ),
				'utmMedium'        => (string) ( $click['utm_medium'] ?? $click['utmMedium'] ?? '' ),
				'utmCampaign'      => (string) ( $click['utm_campaign'] ?? $click['utmCampaign'] ?? '' ),
				'utmTerm'          => (string) ( $click['utm_term'] ?? $click['utmTerm'] ?? '' ),
				'utmContent'       => (string) ( $click['utm_content'] ?? $click['utmContent'] ?? '' ),
				'userAgent'        => (string) ( $click['user_agent'] ?? $click['userAgent'] ?? '' ),
				'clickedAt'        => ! empty( $click['clicked_at'] ?? $click['clickedAt'] ) ? Date::to_iso( (string) ( $click['clicked_at'] ?? $click['clickedAt'] ) ) : Date::to_iso( Date::now() ),
				'user'             => $user_data,
			);
		}

		$social_title = (string) ( $link_data['socialTitle'] ?? $link_data['social_title'] ?? ( isset( $link_data['socialPreview']['title'] ) ? $link_data['socialPreview']['title'] : '' ) );
		$social_desc  = (string) ( $link_data['socialDescription'] ?? $link_data['social_description'] ?? ( isset( $link_data['socialPreview']['description'] ) ? $link_data['socialPreview']['description'] : '' ) );
		$social_img   = (string) ( $link_data['socialImageUrl'] ?? $link_data['social_image_url'] ?? ( isset( $link_data['socialPreview']['imageUrl'] ) ? $link_data['socialPreview']['imageUrl'] : '' ) );
		$has_og       = ! empty( $link_data['hasOpenGraph'] ) || '' !== $social_title || '' !== $social_desc || '' !== $social_img || ! empty( $link_data['social_image_path'] );

		$has_password = ! empty( $link_data['hasPassword'] ) || ( ! empty( $link_data['password_value'] ) && '' !== trim( (string) $link_data['password_value'] ) );
		$expires_at   = $link_data['expiresAt'] ?? ( ! empty( $link_data['expires_at'] ) ? Date::to_iso( (string) $link_data['expires_at'] ) : null );
		$created_at   = $link_data['createdAt'] ?? ( ! empty( $link_data['created_at'] ) ? Date::to_iso( (string) $link_data['created_at'] ) : Date::to_iso( Date::now() ) );
		$updated_at   = $link_data['updatedAt'] ?? ( ! empty( $link_data['updated_at'] ) ? Date::to_iso( (string) $link_data['updated_at'] ) : Date::to_iso( Date::now() ) );

		$data = array(
			'id'                => $id,
			'alias'             => $short_code,
			'shortUrl'          => $short_url,
			'destinationUrl'    => $dest_url,
			'title'             => $title,
			'status'            => (string) ( $link_data['status'] ?? 'active' ),
			'hasPassword'       => $has_password,
			'expiresAt'         => $expires_at,
			'hasOpenGraph'      => $has_og,
			'socialTitle'       => $social_title,
			'socialDescription' => $social_desc,
			'socialImageUrl'    => $social_img,
			'utmSource'         => (string) ( $link_data['utmSource'] ?? $link_data['utm_source'] ?? '' ),
			'utmMedium'         => (string) ( $link_data['utmMedium'] ?? $link_data['utm_medium'] ?? '' ),
			'utmCampaign'       => (string) ( $link_data['utmCampaign'] ?? $link_data['utm_campaign'] ?? '' ),
			'utmTerm'           => (string) ( $link_data['utmTerm'] ?? $link_data['utm_term'] ?? '' ),
			'utmContent'        => (string) ( $link_data['utmContent'] ?? $link_data['utm_content'] ?? '' ),
			'user'              => $user_data,
			'createdAt'         => $created_at,
			'updatedAt'         => $updated_at,
		);

		if ( 'link.created' === $event ) {
			return $data;
		}

		if ( 'link.updated' === $event && ! empty( $previous ) && is_array( $previous ) ) {
			$prev_code = (string) ( $previous['alias'] ?? $previous['shortCode'] ?? $previous['short_code'] ?? '' );
			$prev_dest = (string) ( $previous['destinationUrl'] ?? $previous['destination_url'] ?? $previous['url'] ?? '' );
			$prev_url  = (string) ( $previous['shortUrl'] ?? $previous['short_url'] ?? '' );
			if ( '' === $prev_url && '' !== $prev_code ) {
				$prev_url = $this->get_webhook_site_url( rawurlencode( $prev_code ) );
			}
			$raw_prev_title  = trim( (string) ( $previous['title'] ?? '' ) );
			$meta_prev_title = trim( (string) ( $previous['meta_title'] ?? $previous['social_title'] ?? '' ) );
			$prev_title      = '' !== $raw_prev_title ? $raw_prev_title : ( '' !== $meta_prev_title ? $meta_prev_title : 'Untitled' );

			$data['previous'] = array(
				'alias'          => $prev_code,
				'shortUrl'       => $prev_url,
				'destinationUrl' => $prev_dest,
				'title'          => $prev_title,
			);
		}

		return $data;
	}

	/**
	 * Backward compatibility alias for prepare_link_event_payload.
	 *
	 * @param string                    $event     Webhook event identifier.
	 * @param array<string, mixed>      $link_data Link array or database row.
	 * @param array<string, mixed>|null $user      Current user row or null.
	 * @param array<string, mixed>|null $previous  Previous state for link updates.
	 * @param array<string, mixed>|null $click     Recorded click details.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function build_link_event_data(
		string $event,
		array $link_data,
		?array $user = null,
		?array $previous = null,
		?array $click = null
	): array {
		return $this->prepare_link_event_payload( $event, $link_data, $user, $previous, $click );
	}

	/**
	 * Send an HTTP POST webhook payload to a registered endpoint.
	 *
	 * @param array<string, mixed> $webhook Webhook row.
	 * @param array<string, mixed> $payload Structured webhook event payload.
	 * @param float                $timeout Request timeout in seconds.
	 * @return array<string, mixed> Delivery outcome details.
	 * @since 1.0.0
	 */
	public function send_webhook_payload(
		array $webhook,
		array $payload,
		float $timeout = 2.5
	): array {
		$url = trim( (string) ( $webhook['url'] ?? '' ) );

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'webhookId'  => (string) ( $webhook['id'] ?? '' ),
				'url'        => $url,
				'statusCode' => 0,
				'success'    => false,
				'error'      => 'Invalid webhook URL.',
				'durationMs' => 0,
			);
		}

		$json_body = json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $json_body ) {
			$json_body = (string) json_encode( $payload );
		}

		$secret    = (string) ( $webhook['secret'] ?? '' );
		$signature = hash_hmac( 'sha256', $json_body, $secret );
		$timestamp = (int) ( $payload['timestamp'] ?? time() );
		$event     = (string) ( $payload['event'] ?? '' );
		$event_id  = (string) ( $payload['id'] ?? '' );
		$headers   = $this->prepare_webhook_headers( $event, $timestamp, $signature, $event_id );

		$start_time = microtime( true );

		if ( function_exists( 'curl_init' ) ) {
			$result = $this->post_webhook_with_curl( $url, $json_body, $headers, $timeout );
		} else {
			$result = $this->post_webhook_with_stream( $url, $json_body, $headers, $timeout );
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		return array(
			'webhookId'  => (string) ( $webhook['id'] ?? '' ),
			'url'        => $url,
			'statusCode' => $result['statusCode'],
			'success'    => $result['statusCode'] >= 200 && $result['statusCode'] < 300,
			'error'      => $result['error'],
			'durationMs' => $duration_ms,
			'response'   => $result['response'],
		);
	}

	/**
	 * Build a masked signing-secret preview for dashboard display.
	 *
	 * @param string $secret Stored webhook signing secret.
	 * @return string Masked secret preview.
	 * @since 1.0.0
	 */
	public function mask_webhook_secret( string $secret ): string {
		$prefix = substr( $secret, 0, 10 );

		if ( '' === $prefix ) {
			return '••••••••••••••••••••••••';
		}

		return $prefix . str_repeat( '•', 18 );
	}

	/**
	 * Prepare standardized webhook delivery HTTP headers.
	 *
	 * @param string $event       Event identifier.
	 * @param int    $timestamp   UNIX timestamp.
	 * @param string $signature   HMAC-SHA256 signature hash.
	 * @param string $delivery_id Delivery tracking ID.
	 * @return array<int, string> Formatted HTTP headers.
	 * @since 1.0.0
	 */
	private function prepare_webhook_headers(
		string $event,
		int $timestamp,
		string $signature,
		string $delivery_id
	): array {
		return array(
			'Content-Type: application/json; charset=utf-8',
			'X-PeakURL-Event: ' . $event,
			'X-PeakURL-Timestamp: ' . $timestamp,
			'X-PeakURL-Signature: ' . $signature,
			'X-Hub-Signature-256: sha256=' . $signature,
			'X-PeakURL-Delivery: ' . $delivery_id,
			'User-Agent: PeakURL-Webhook/1.0 (+https://peakurl.org)',
		);
	}

	/**
	 * Deliver a webhook event to multiple endpoints concurrently via cURL multi.
	 *
	 * @param array<int, array<string, mixed>> $webhooks Subscribed webhook rows.
	 * @param array<string, mixed>             $payload  Structured event payload.
	 * @param float                            $timeout  Global timeout budget in seconds.
	 * @return array<int, array<string, mixed>> Delivery results.
	 * @since 1.0.0
	 */
	private function post_webhooks_parallel(
		array $webhooks,
		array $payload,
		float $timeout
	): array {
		$mh         = curl_multi_init();
		$handles    = array();
		$start_time = microtime( true );

		$json_body = json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( false === $json_body ) {
			$json_body = (string) json_encode( $payload );
		}
		$timestamp = (int) ( $payload['timestamp'] ?? time() );
		$event     = (string) ( $payload['event'] ?? '' );
		$event_id  = (string) ( $payload['id'] ?? '' );

		$timeout_ms = (int) max( 100, round( $timeout * 1000 ) );
		$connect_ms = (int) min( $timeout_ms, 1500 );

		foreach ( $webhooks as $webhook ) {
			$url = trim( (string) ( $webhook['url'] ?? '' ) );
			if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			$secret    = (string) ( $webhook['secret'] ?? '' );
			$signature = hash_hmac( 'sha256', $json_body, $secret );
			$headers   = $this->prepare_webhook_headers( $event, $timestamp, $signature, $event_id );

			$ch = curl_init( $url );
			if ( false === $ch ) {
				continue;
			}

			curl_setopt_array(
				$ch,
				array(
					CURLOPT_POST              => true,
					CURLOPT_POSTFIELDS        => $json_body,
					CURLOPT_HTTPHEADER        => $headers,
					CURLOPT_RETURNTRANSFER    => true,
					CURLOPT_NOSIGNAL          => 1,
					CURLOPT_CONNECTTIMEOUT_MS => $connect_ms,
					CURLOPT_TIMEOUT_MS        => $timeout_ms,
					CURLOPT_FOLLOWLOCATION    => false,
					CURLOPT_MAXREDIRS         => 0,
					CURLOPT_SSL_VERIFYPEER    => true,
					CURLOPT_SSL_VERIFYHOST    => 2,
				)
			);

			curl_multi_add_handle( $mh, $ch );
			$handles[] = array(
				'ch'      => $ch,
				'webhook' => $webhook,
			);
		}

		if ( empty( $handles ) ) {
			curl_multi_close( $mh );
			return array();
		}

		$active = null;
		do {
			$mrc = curl_multi_exec( $mh, $active );
		} while ( CURLM_CALL_MULTI_PERFORM === $mrc );

		while ( $active && CURLM_OK === $mrc ) {
			$select = curl_multi_select( $mh, 0.2 );
			if ( $select >= 0 ) {
				do {
					$mrc = curl_multi_exec( $mh, $active );
				} while ( CURLM_CALL_MULTI_PERFORM === $mrc );
			} elseif ( -1 === $select ) {
				usleep( 5000 );
			}
			if ( ( microtime( true ) - $start_time ) >= $timeout ) {
				break;
			}
		}

		$results     = array();
		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		foreach ( $handles as $item ) {
			$ch      = $item['ch'];
			$webhook = $item['webhook'];

			$response  = curl_multi_getcontent( $ch );
			$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error     = curl_error( $ch );

			$results[] = array(
				'webhookId'  => (string) ( $webhook['id'] ?? '' ),
				'url'        => (string) ( $webhook['url'] ?? '' ),
				'statusCode' => $http_code,
				'success'    => $http_code >= 200 && $http_code < 300,
				'error'      => '' !== $error ? $error : null,
				'durationMs' => $duration_ms,
				'response'   => is_string( $response ) ? substr( $response, 0, 1000 ) : '',
			);

			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );
		}

		curl_multi_close( $mh );

		return $results;
	}

	/**
	 * POST the webhook payload using cURL.
	 *
	 * @param string             $url     Target destination URL.
	 * @param string             $body    Raw JSON payload.
	 * @param array<int, string> $headers HTTP headers.
	 * @param float              $timeout Timeout in seconds.
	 * @return array<string, mixed> Outcome with statusCode and error.
	 * @since 1.0.0
	 */
	private function post_webhook_with_curl(
		string $url,
		string $body,
		array $headers,
		float $timeout
	): array {
		$ch = curl_init( $url );

		if ( false === $ch ) {
			return array(
				'statusCode' => 0,
				'error'      => 'Failed to initialize cURL.',
				'response'   => '',
			);
		}

		$timeout_ms = (int) max( 100, round( $timeout * 1000 ) );
		$connect_ms = (int) min( $timeout_ms, 1500 );

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST              => true,
				CURLOPT_POSTFIELDS        => $body,
				CURLOPT_HTTPHEADER        => $headers,
				CURLOPT_RETURNTRANSFER    => true,
				CURLOPT_NOSIGNAL          => 1,
				CURLOPT_CONNECTTIMEOUT_MS => $connect_ms,
				CURLOPT_TIMEOUT_MS        => $timeout_ms,
				CURLOPT_FOLLOWLOCATION    => false,
				CURLOPT_MAXREDIRS         => 0,
				CURLOPT_SSL_VERIFYPEER    => true,
				CURLOPT_SSL_VERIFYHOST    => 2,
			)
		);

		$response  = curl_exec( $ch );
		$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error     = curl_error( $ch );

		curl_close( $ch );

		return array(
			'statusCode' => $http_code,
			'error'      => '' !== $error ? $error : null,
			'response'   => is_string( $response ) ? substr( $response, 0, 1000 ) : '',
		);
	}

	/**
	 * POST the webhook payload using PHP streams fallback.
	 *
	 * @param string             $url     Target destination URL.
	 * @param string             $body    Raw JSON payload.
	 * @param array<int, string> $headers HTTP headers.
	 * @param float              $timeout Timeout in seconds.
	 * @return array<string, mixed> Outcome with statusCode and error.
	 * @since 1.0.0
	 */
	private function post_webhook_with_stream(
		string $url,
		string $body,
		array $headers,
		float $timeout
	): array {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'POST',
					'header'        => implode( "\r\n", $headers ),
					'content'       => $body,
					'timeout'       => $timeout,
					'ignore_errors' => true,
				),
				'ssl'  => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
			)
		);

		$error_message = null;
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$error_message ): bool {
				$error_message = $errstr;
				return true;
			}
		);

		$response = @file_get_contents( $url, false, $context );
		restore_error_handler();

		$status_code      = 0;
		$response_headers = function_exists( 'http_get_last_response_headers' )
			? http_get_last_response_headers()
			: ( $http_response_header ?? array() );

		if ( ! empty( $response_headers ) && is_array( $response_headers ) ) {
			if ( preg_match( '#HTTP/\S+\s+(\d{3})#', $response_headers[0] ?? '', $matches ) ) {
				$status_code = (int) $matches[1];
			}
		}

		return array(
			'statusCode' => $status_code,
			'error'      => $error_message,
			'response'   => is_string( $response ) ? substr( $response, 0, 1000 ) : '',
		);
	}

	/**
	 * Return the current webhook user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	public function get_webhook_user( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_webhooks',
			'You do not have permission to manage webhooks.',
		);

		return $user;
	}

	/**
	 * Format a webhook row for API responses.
	 *
	 * @param array<string, mixed> $row         Raw webhook row.
	 * @param bool                 $show_secret Whether to include the raw secret.
	 * @return array<string, mixed> Webhook response payload.
	 * @since 1.0.0
	 */
	private function format_webhook( array $row, bool $show_secret = false ): array {
		$webhook = array(
			'id'     => (string) $row['id'],
			'url'    => (string) $row['url'],
			'events' => $this->decode_json_array(
				(string) ( $row['events'] ?? '[]' ),
			),
		);

		if ( $show_secret ) {
			$webhook['secret'] = (string) ( $row['secret'] ?? '' );
		}

		return array_merge(
			$webhook,
			array(
				'secretHint' => $this->mask_webhook_secret(
					(string) ( $row['secret'] ?? '' ),
				),
				'isActive'   => ! empty( $row['is_active'] ),
				'createdAt'  => Date::to_iso( (string) $row['created_at'] ),
			),
		);
	}

	/**
	 * Safely resolve a canonical site URL path for webhook payloads.
	 *
	 * @param string $path Relative path to append.
	 * @return string Canonical site URL.
	 * @since 1.0.0
	 */
	private function get_webhook_site_url( string $path = '' ): string {
		try {
			return \get_site_url( $path );
		} catch ( \Throwable $e ) {
			$base = ! empty( $this->config[ Constants::SITE_URL ] )
				? (string) $this->config[ Constants::SITE_URL ]
				: 'https://peakurl.dev';
			return '' !== $path ? rtrim( $base, '/' ) . '/' . ltrim( $path, '/' ) : rtrim( $base, '/' );
		}
	}
	/**
	 * Safely decode a JSON string into an array.
	 *
	 * @param string $json JSON string.
	 * @return array<mixed> Decoded array.
	 * @since 1.0.0
	 */
	private function decode_json_array( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
