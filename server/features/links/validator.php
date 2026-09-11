<?php
/**
 * Link input validation and sanitization.
 *
 * @package PeakURL\Features\Links
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Links;

use PeakURL\Core\Errors\ApiException;
use PeakURL\Services\SocialPreview;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Validator — Link validation and input normalization rules.
 *
 * @since 1.0.0
 */
class Validator {

	/**
	 * Clean and validate a destination URL.
	 *
	 * @param mixed $value Raw destination URL value.
	 * @return string Valid destination URL.
	 *
	 * @throws ApiException When the URL is missing or invalid.
	 * @since 1.0.0
	 */
	public function clean_destination( $value ): string {
		$value = trim( (string) $value );

		if ( '' !== $value && filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return $value;
		}

		throw new ApiException(
			__( 'A valid destination URL is required.', 'peakurl' ),
			422,
		);
	}

	/**
	 * Sanitise a short code or alias while preserving letter case.
	 *
	 * @param string $value Raw code input.
	 * @return string Sanitised code.
	 * @since 1.0.0
	 */
	public function sanitize_code( string $value ): string {
		$sanitized = preg_replace( '/[^A-Za-z0-9-]/', '', trim( $value ) );

		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * Determine whether a code conflicts with reserved application routes.
	 *
	 * @param string $code Code to test.
	 * @return bool True when reserved.
	 * @since 1.0.0
	 */
	public function is_reserved_code( string $code ): bool {
		return in_array(
			strtolower( trim( $code ) ),
			array( 'api', 'dashboard', 'login' ),
			true,
		);
	}

	/**
	 * Validate a short-code alias before saving it.
	 *
	 * @param string        $alias         Sanitized alias.
	 * @param string        $current_alias Current alias when updating a link.
	 * @param callable|null $exists_check  Callback returning true if short code already exists.
	 * @return void
	 *
	 * @throws ApiException When the alias is reserved or already used.
	 * @since 1.0.0
	 */
	public function validate_alias(
		string $alias,
		string $current_alias = '',
		?callable $exists_check = null
	): void {
		if ( $this->is_reserved_code( $alias ) ) {
			throw new ApiException(
				__( 'That short code is reserved by the application.', 'peakurl' ),
				422,
			);
		}

		if ( $alias === $current_alias ) {
			return;
		}

		if ( null !== $exists_check && true === $exists_check( $alias ) ) {
			throw new ApiException( __( 'That short code is already in use.', 'peakurl' ), 422 );
		}
	}

	/**
	 * Sanitize a link password attempt or configured password.
	 *
	 * @param mixed $value Raw password input.
	 * @return string Trimmed password string.
	 * @since 1.0.0
	 */
	public function sanitize_link_password( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Check whether a submitted password matches the stored link hash.
	 *
	 * @param array<string, mixed> $url                URL database row.
	 * @param string               $submitted_password Submitted candidate password.
	 * @return bool True if password matches.
	 * @since 1.0.0
	 */
	public function link_password_matches(
		array $url,
		string $submitted_password
	): bool {
		$hash = (string) ( $url['password_value'] ?? '' );

		if ( '' === $hash || '' === $submitted_password ) {
			return false;
		}

		return $this->verify_link_password( $submitted_password, $hash );
	}

	/**
	 * Hash a protected-link password for database storage.
	 *
	 * @param string $password Raw password.
	 * @return string
	 *
	 * @throws \RuntimeException When the password cannot be hashed.
	 * @since 1.0.3
	 */
	public function hash_link_password( string $password ): string {
		$hash = password_hash( $password, PASSWORD_DEFAULT );

		if ( false === $hash ) {
			throw new \RuntimeException(
				__( 'PeakURL could not hash the protected-link password.', 'peakurl' ),
			);
		}

		return $hash;
	}

	/**
	 * Verify a protected-link password against stored secret material.
	 *
	 * @param string $password      Raw password input.
	 * @param string $stored_secret Stored password value.
	 * @return bool
	 * @since 1.0.3
	 */
	public function verify_link_password(
		string $password,
		string $stored_secret
	): bool {
		$stored_secret = trim( $stored_secret );

		if ( '' === $password || '' === $stored_secret ) {
			return false;
		}

		return password_verify( $password, $stored_secret );
	}

	/**
	 * Determine whether a public link is expired.
	 *
	 * @param array<string, mixed> $url URL database row.
	 * @return bool True if the link expiration timestamp is in the past.
	 * @since 1.0.0
	 */
	public function is_public_link_expired( array $url ): bool {
		$expires_at = (string) ( $url['expires_at'] ?? '' );

		if ( '' === $expires_at ) {
			return false;
		}

		$expires_timestamp = strtotime( $expires_at );

		return false !== $expires_timestamp && $expires_timestamp <= time();
	}

	/**
	 * Normalise a mixed datetime value into a MySQL-compatible string.
	 *
	 * @param mixed $value Raw datetime input.
	 * @return string|null Formatted datetime or null.
	 *
	 * @throws ApiException When an invalid date string is provided.
	 * @since 1.0.0
	 */
	public function normalize_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$timestamp = strtotime( $value );

			if ( false === $timestamp ) {
				throw new ApiException( __( 'Invalid date value provided.', 'peakurl' ), 422 );
			}

			return gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		throw new ApiException( __( 'Invalid date value provided.', 'peakurl' ), 422 );
	}

	/**
	 * Normalise a URL status string to a known enum value.
	 *
	 * @param string $status Raw status input.
	 * @return string Normalised URL status.
	 * @since 1.0.0
	 */
	public function normalize_url_status( string $status ): string {
		$status = sanitize_key( $status );

		if (
			! in_array(
				$status,
				array( 'active', 'inactive', 'paused', 'archived', 'expired' ),
				true,
			)
		) {
			return 'active';
		}

		return $status;
	}

	/**
	 * Normalize social preview fields from a link payload.
	 *
	 * @param array<string, mixed> $payload        Submitted link payload.
	 * @param SocialPreview        $social_preview Social preview service.
	 * @param bool                 $include_missing Whether missing keys should be normalized as null.
	 * @return array<string, mixed> Normalized preview data.
	 *
	 * @throws ApiException When a submitted preview title/description is invalid.
	 * @since 1.2.0
	 */
	public function normalize_link_social_preview(
		array $payload,
		SocialPreview $social_preview,
		bool $include_missing = true
	): array {
		$field_map = array(
			'socialTitle'       => array(
				'value'  => 'title',
				'column' => 'social_title',
			),
			'socialDescription' => array(
				'value'  => 'description',
				'column' => 'social_description',
			),
		);
		$columns   = array();
		$values    = array(
			'title'       => null,
			'description' => null,
			'columns'     => array(),
		);

		foreach ( $field_map as $input_key => $meta ) {
			if ( ! $include_missing && ! array_key_exists( $input_key, $payload ) ) {
				continue;
			}

			$value_key                  = $meta['value'];
			$columns[ $meta['column'] ] = true;
			$value                      = $payload[ $input_key ] ?? null;

			try {
				if ( 'title' === $value_key ) {
					$values[ $value_key ] = $social_preview->normalize_title( $value );
				} else {
					$values[ $value_key ] = $social_preview->normalize_description( $value );
				}
			} catch ( \RuntimeException $exception ) {
				throw new ApiException( $exception->getMessage(), 422 );
			}
		}

		$values['columns'] = $columns;

		return $values;
	}

	/**
	 * Normalize an external per-link social preview image URL.
	 *
	 * @param mixed         $value          Submitted external image URL.
	 * @param SocialPreview $social_preview Social preview service.
	 * @return string|null Normalized URL or null when empty.
	 *
	 * @throws ApiException When the submitted URL is invalid.
	 * @since 1.2.0
	 */
	public function normalize_link_social_image_url(
		$value,
		SocialPreview $social_preview
	): ?string {
		try {
			return $social_preview->normalize_image_url( $value );
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}
	}

	/**
	 * Return whether an uploaded file payload should update link media.
	 *
	 * @param array<string, mixed>|null $file Uploaded file data.
	 * @return bool True if a valid file upload was present.
	 * @since 1.2.0
	 */
	public function has_link_upload( ?array $file ): bool {
		return is_array( $file ) &&
			array_key_exists( 'error', $file ) &&
			UPLOAD_ERR_NO_FILE !== (int) $file['error'];
	}
}
