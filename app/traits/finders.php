<?php
/**
 * Data store finder trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;
use PeakURL\Services\Mailer;
use PeakURL\Services\SetupConfig;
use PeakURL\Services\Update;
use PeakURL\Utils\Query;
use PeakURL\Utils\Security;
use PeakURL\Utils\Visitor;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * FindersTrait — common row lookup helpers for Store.
 *
 * @since 1.0.0
 */
trait FindersTrait {

	/**
	 * Find a URL row by ID, short code, or alias.
	 *
	 * @param string $id URL ID, short code, or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	private function find_url_row( string $id ): ?array {
		return $this->links_api->get_link_by_identifier( $id );
	}

	/**
	 * Find a public-facing URL row by short code or alias.
	 *
	 * @param string $id Short code or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	private function find_public_url_row( string $id ): ?array {
		$normalized_id = $this->sanitize_code( $id );

		if ( '' === $normalized_id ) {
			return null;
		}

		$row = $this->links_api->get_public_link_by_code(
			$normalized_id,
			$this->now(),
		);

		return $row ? $row : null;
	}

	/**
	 * Find a public-facing URL row without status or expiry filtering.
	 *
	 * Used by the public redirect flow so password, expiry, and status rules
	 * can be applied after the row is loaded.
	 *
	 * @param string $id Short code or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	private function find_public_access_url_row( string $id ): ?array {
		$normalized_id = $this->sanitize_code( $id );

		if ( '' === $normalized_id ) {
			return null;
		}

		$row = $this->links_api->get_public_link_access_row( $normalized_id );

		return $row ? $row : null;
	}

	/**
	 * Find a user row by primary ID.
	 *
	 * @param string $id User ID.
	 * @return array<string, mixed>|null User row or null.
	 * @since 1.0.0
	 */
	private function find_user_row_by_id( string $id ): ?array {
		return $this->users_api->get_user( $id );
	}

	/**
	 * Find a user row by email address.
	 *
	 * @param string $email Email to look up.
	 * @return array<string, mixed>|null User row or null.
	 * @since 1.0.0
	 */
	private function find_user_row_by_email( string $email ): ?array {
		return $this->users_api->get_user_by_email( $email );
	}

	/**
	 * Find a user row by username.
	 *
	 * @param string $username Username to look up.
	 * @return array<string, mixed>|null User row or null.
	 * @since 1.0.0
	 */
	private function find_user_row_by_username( string $username ): ?array {
		return $this->users_api->get_user_by_username( $username );
	}

	/**
	 * Retrieve the workspace owner.
	 *
	 * @return array<string, mixed>|null Owner user row or null.
	 * @since 1.0.0
	 */
	private function get_workspace_owner_row(): ?array {
		return $this->users_api->get_workspace_owner_user();
	}
}
