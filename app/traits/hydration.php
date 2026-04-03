<?php
/**
 * Data store hydration trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * HydrationTrait — API-ready row mappers for Store.
 *
 * @since 1.0.0
 */
trait HydrationTrait {

	/**
	 * Hydrate a raw user database row into an API-ready user array.
	 *
	 * @param array<string, mixed>|null $row     Raw user row from the database.
	 * @param Request|null              $request Optional request for session context.
	 * @return array<string, mixed> Hydrated user profile.
	 * @since 1.0.0
	 */
	private function hydrate_user( ?array $row, ?Request $request = null ): array {
		if ( ! $row ) {
			return array();
		}

		$api_keys = array();

		if (
			$request &&
			$this->roles->user_can( $row, 'manage_api_keys' )
		) {
			$api_keys = $this->list_api_keys( (string) $row['id'] );
		}

		return array(
			'id'              => (string) $row['id'],
			'firstName'       => (string) ( $row['first_name'] ?? '' ),
			'lastName'        => (string) ( $row['last_name'] ?? '' ),
			'username'        => (string) ( $row['username'] ?? '' ),
			'email'           => (string) ( $row['email'] ?? '' ),
			'phoneNumber'     => (string) ( $row['phone_number'] ?? '' ),
			'company'         => (string) ( $row['company'] ?? '' ),
			'jobTitle'        => (string) ( $row['job_title'] ?? '' ),
			'bio'             => (string) ( $row['bio'] ?? '' ),
			'role'            => (string) ( $row['role'] ?? 'editor' ),
			'capabilities'    => $this->roles->capabilities_for_role(
				(string) ( $row['role'] ?? 'editor' ),
			),
			'isEmailVerified' => ! empty( $row['is_email_verified'] ),
			'emailVerifiedAt' => $row['email_verified_at']
				? $this->to_iso( (string) $row['email_verified_at'] )
				: null,
			'apiKey'          => null,
			'apiKeys'         => $api_keys,
			'createdAt'       => $this->to_iso( (string) $row['created_at'] ),
			'updatedAt'       => $this->to_iso( (string) $row['updated_at'] ),
			'security'        => $request
				? array(
					'sessions' => $this->list_user_sessions(
						(string) $row['id'],
						$request,
					),
				)
				: null,
		);
	}

	/**
	 * Hydrate a raw URL database row into an API-ready array.
	 *
	 * @param array<string, mixed>|null $row Raw URL row from the database.
	 * @return array<string, mixed> Hydrated URL data.
	 * @since 1.0.0
	 */
	private function hydrate_url_row( ?array $row ): array {
		if ( ! $row ) {
			return array();
		}

		return array(
			'id'             => (string) $row['id'],
			'shortCode'      => (string) $row['short_code'],
			'alias'          => (string) $row['alias'],
			'title'          => (string) ( $row['title'] ?? '' ),
			'destinationUrl' => (string) $row['destination_url'],
			'domain'         => null,
			'clicks'         => (int) ( $row['click_count'] ?? 0 ),
			'uniqueClicks'   => (int) ( $row['unique_click_count'] ?? 0 ),
			'status'         => (string) ( $row['status'] ?? 'active' ),
			'password'       => (string) ( $row['password_value'] ?? '' ),
			'expiresAt'      => $row['expires_at']
				? $this->to_iso( (string) $row['expires_at'] )
				: null,
			'createdAt'      => $this->to_iso( (string) $row['created_at'] ),
			'updatedAt'      => $this->to_iso( (string) $row['updated_at'] ),
		);
	}

	/**
	 * Hydrate a raw audit-log row into an API-ready activity item.
	 *
	 * @param array<string, mixed> $row Raw audit log row.
	 * @return array<string, mixed> Hydrated activity item.
	 * @since 1.0.0
	 */
	private function hydrate_activity_row( array $row ): array {
		$metadata = $this->decode_json( (string) ( $row['metadata'] ?? '{}' ) );

		return array(
			'id'        => (string) $row['id'],
			'type'      => (string) $row['type'],
			'message'   => (string) ( $row['message'] ?? '' ),
			'link'      => is_array( $metadata['link'] ?? null )
				? $metadata['link']
				: null,
			'location'  => is_array( $metadata['location'] ?? null )
				? $metadata['location']
				: null,
			'timestamp' => $this->to_iso( (string) $row['created_at'] ),
		);
	}
}
