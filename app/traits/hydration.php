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
			'domain'         => null,
			'clicks'         => (int) ( $row['click_count'] ?? 0 ),
			'uniqueClicks'   => (int) ( $row['unique_click_count'] ?? 0 ),
			'status'         => (string) ( $row['status'] ?? 'active' ),
			'hasPassword'    => '' !== trim(
				(string) ( $row['password_value'] ?? '' ),
			),
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
		$actor    = $this->normalize_activity_person(
			array(
				'id'        => (string) ( $row['user_id'] ?? '' ),
				'firstName' => (string) ( $row['actor_first_name'] ?? '' ),
				'lastName'  => (string) ( $row['actor_last_name'] ?? '' ),
				'username'  => (string) ( $row['actor_username'] ?? '' ),
				'email'     => (string) ( $row['actor_email'] ?? '' ),
				'role'      => (string) ( $row['actor_role'] ?? '' ),
			),
		);

		return array(
			'id'        => (string) $row['id'],
			'type'      => (string) $row['type'],
			'message'   => (string) ( $row['message'] ?? '' ),
			'actor'     => $actor,
			'user'      => $this->normalize_activity_person(
				is_array( $metadata['user'] ?? null )
					? $metadata['user']
					: null,
			),
			'link'      => is_array( $metadata['link'] ?? null )
				? $metadata['link']
				: null,
			'location'  => is_array( $metadata['location'] ?? null )
				? $metadata['location']
				: null,
			'timestamp' => $this->to_iso( (string) $row['created_at'] ),
		);
	}

	/**
	 * Normalize lightweight activity person metadata for the UI.
	 *
	 * @param array<string, mixed>|null $person Raw person metadata.
	 * @return array<string, string|null>|null
	 * @since 1.0.4
	 */
	private function normalize_activity_person( ?array $person ): ?array {
		if ( ! is_array( $person ) ) {
			return null;
		}

		$id         = trim( (string) ( $person['id'] ?? '' ) );
		$first_name = trim( (string) ( $person['firstName'] ?? '' ) );
		$last_name  = trim( (string) ( $person['lastName'] ?? '' ) );
		$username   = trim( (string) ( $person['username'] ?? '' ) );
		$email      = trim( (string) ( $person['email'] ?? '' ) );
		$role       = trim( (string) ( $person['role'] ?? '' ) );

		if (
			'' === $id &&
			'' === $first_name &&
			'' === $last_name &&
			'' === $username &&
			'' === $email &&
			'' === $role
		) {
			return null;
		}

		return array(
			'id'        => '' !== $id ? $id : null,
			'firstName' => '' !== $first_name ? $first_name : null,
			'lastName'  => '' !== $last_name ? $last_name : null,
			'username'  => '' !== $username ? $username : null,
			'email'     => '' !== $email ? $email : null,
			'role'      => '' !== $role ? $role : null,
		);
	}
}
