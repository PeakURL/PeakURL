<?php
/**
 * Canonical link creation domain service.
 *
 * Implements core creation invariants, short code generation,
 * title/alias normalization, UTM persistence, and cache invalidation.
 *
 * @package PeakURL\Features\Links
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Links;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Services\SocialPreview;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Creator — Handles the shared canonical link creation lifecycle.
 *
 * @since 1.7.0
 */
class Creator {

	/**
	 * Link repository handler.
	 *
	 * @var Repository
	 * @since 1.7.0
	 */
	private Repository $repository;

	/**
	 * Link validation helper.
	 *
	 * @var Validator
	 * @since 1.7.0
	 */
	private Validator $validator;

	/**
	 * Settings data API.
	 *
	 * @var SettingsApi
	 * @since 1.7.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Social preview metadata helper.
	 *
	 * @var SocialPreview
	 * @since 1.7.0
	 */
	private SocialPreview $social_preview;

	/**
	 * Create a new Link Creator instance.
	 *
	 * @param Repository    $repository     Repository handler.
	 * @param Validator     $validator      Validator handler.
	 * @param SettingsApi   $settings_api   Settings data API.
	 * @param SocialPreview $social_preview Social preview helper.
	 * @since 1.7.0
	 */
	public function __construct(
		Repository $repository,
		Validator $validator,
		SettingsApi $settings_api,
		SocialPreview $social_preview
	) {
		$this->repository     = $repository;
		$this->validator      = $validator;
		$this->settings_api   = $settings_api;
		$this->social_preview = $social_preview;
	}

	/**
	 * Generate a unique 6-character random hex short code.
	 *
	 * @return string Unique short code.
	 * @since 1.7.0
	 */
	public function generate_short_code(): string {
		do {
			$code = substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		} while ( $this->repository->short_code_exists( $code ) );

		return $code;
	}

	/**
	 * Format an alias or custom title for link display.
	 *
	 * @param string $title             Raw title from payload.
	 * @param string $alias             Final alias or short code.
	 * @param bool   $uses_custom_alias Whether a custom alias was specified.
	 * @return string Decoded and normalized title.
	 * @since 1.7.0
	 */
	public function get_url_title( string $title, string $alias, bool $uses_custom_alias ): string {
		$normalized_title = trim( $title );

		if ( '' !== $normalized_title ) {
			return trim( html_entity_decode( $normalized_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		if ( $uses_custom_alias ) {
			return $this->format_alias_title( $alias );
		}

		return '';
	}

	/**
	 * Format an alias as a default title.
	 *
	 * @param string $alias Final stored alias or short code.
	 * @return string Formatted title string.
	 * @since 1.7.0
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

		return ucfirst( $alias );
	}

	/**
	 * Create and persist a canonical link record in the database.
	 *
	 * @param array<string, mixed> $payload Canonical link payload.
	 * @param int|string           $user_id Owner user identifier.
	 * @return array<string, mixed> Created and formatted URL payload.
	 *
	 * @throws ApiException On validation failure.
	 * @since 1.7.0
	 */
	public function create_link_record( array $payload, $user_id ): array {
		$raw_destination = (string) ( $payload['destinationUrl'] ?? '' );
		$destination_url = $this->validator->clean_destination( $raw_destination );

		$raw_alias         = (string) ( $payload['alias'] ?? '' );
		$alias             = $this->validator->sanitize_code( $raw_alias );
		$uses_custom_alias = '' !== $alias;

		if ( '' === $alias ) {
			$alias = $this->generate_short_code();
		}

		$this->validator->validate_alias(
			$alias,
			'',
			fn( string $code ): bool => $this->repository->short_code_exists( $code ),
		);

		$title = $this->get_url_title(
			(string) ( $payload['title'] ?? '' ),
			$alias,
			$uses_custom_alias,
		);

		$id                = Str::random_id();
		$now               = Date::now();
		$password          = $this->validator->sanitize_link_password(
			(string) ( $payload['password'] ?? '' ),
		);
		$social_preview    = $this->validator->normalize_link_social_preview(
			$payload,
			$this->social_preview,
		);
		$social_image_path = (string) ( $payload['socialImagePath'] ?? '' );
		$social_image_path = '' !== $social_image_path ? $social_image_path : null;
		$social_image_url  = $this->validator->normalize_link_social_image_url(
			$payload['socialImageUrl'] ?? null,
			$this->social_preview,
		);

		$utm_source   = ! empty( $payload['utmSource'] ) ? trim( (string) $payload['utmSource'] ) : null;
		$utm_medium   = ! empty( $payload['utmMedium'] ) ? trim( (string) $payload['utmMedium'] ) : null;
		$utm_campaign = ! empty( $payload['utmCampaign'] ) ? trim( (string) $payload['utmCampaign'] ) : null;
		$utm_term     = ! empty( $payload['utmTerm'] ) ? trim( (string) $payload['utmTerm'] ) : null;
		$utm_content  = ! empty( $payload['utmContent'] ) ? trim( (string) $payload['utmContent'] ) : null;

		$this->repository->insert_url(
			array(
				'id'                 => $id,
				'user_id'            => $user_id,
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
				'utm_source'         => $utm_source,
				'utm_medium'         => $utm_medium,
				'utm_campaign'       => $utm_campaign,
				'utm_term'           => $utm_term,
				'utm_content'        => $utm_content,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
		);

		$row = $this->repository->find_url_row( $id );
		$url = $this->format_url( $row );

		$this->invalidate_link_cache( (string) ( $url['shortCode'] ?? '' ) );
		$this->invalidate_link_cache( (string) ( $url['alias'] ?? '' ) );
		$this->invalidate_link_cache( (string) ( $url['id'] ?? '' ) );

		return $url;
	}

	/**
	 * Invalidate link cache for a specific key.
	 *
	 * @param string $key Cache key.
	 * @return void
	 * @since 1.7.0
	 */
	public function invalidate_link_cache( string $key ): void {
		if ( '' !== $key ) {
			$this->repository->get_links_api()->invalidate_link_cache( $key );
		}
	}

	/**
	 * Format a database row into an API-ready link item.
	 *
	 * @param array<string, mixed>|null $row Raw link row.
	 * @return array<string, mixed> Formatted link payload.
	 * @since 1.7.0
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
			'userId'         => (string) ( $row['user_id'] ?? '' ),
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
}
