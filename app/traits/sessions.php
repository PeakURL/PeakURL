<?php
/**
 * Data store session helpers trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Utils\Security;
use PeakURL\Utils\Visitor;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SessionsTrait — session and API-token authentication helpers.
 *
 * @since 1.0.0
 */
trait SessionsTrait {

	/**
	 * Resolve the current user from API key or session cookie.
	 *
	 * @param Request $request        Incoming HTTP request.
	 * @param bool    $allow_fallback Reserved for future use (currently ignored).
	 * @return array<string, mixed>|null Hydrated user or null.
	 * @since 1.0.0
	 */
	private function resolve_current_user(
		Request $request,
		bool $allow_fallback
	): ?array {
		unset( $allow_fallback );
		$api_user = $this->resolve_api_key_user( $request );

		if ( $api_user ) {
			return $api_user;
		}

		$session = $this->find_session_by_request( $request );

		if ( $session ) {
			$this->touch_session( (string) $session['id'] );
			$user = $this->find_user_row_by_id( (string) $session['user_id'] );
			return $user ? $this->hydrate_user( $user, $request ) : null;
		}

		return null;
	}

	/**
	 * Authenticate a request by its API key header.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>|null Hydrated user or null if no valid key.
	 * @since 1.0.0
	 */
	private function resolve_api_key_user( Request $request ): ?array {
		$authorization = trim(
			(string) $request->get_header( 'Authorization', '' ),
		);

		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
			return null;
		}

		$token = trim( (string) ( $matches[1] ?? '' ) );

		if ( '' === $token ) {
			return null;
		}

		$row = $this->query_one(
			'SELECT u.*
            FROM api_keys k
            INNER JOIN users u ON u.id = k.user_id
            WHERE k.key_hash = :key_hash
            LIMIT 1',
			array( 'key_hash' => $this->hash_api_key( $token ) ),
		);

		return $row ? $this->hydrate_user( $row ) : null;
	}

	/**
	 * Look up a session row by the session cookie on the request.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>|null Session row or null.
	 * @since 1.0.0
	 */
	private function find_session_by_request( Request $request ): ?array {
		$this->prune_stale_sessions();

		$cookie_name = (string) $this->config[ Constants::CONFIG_SESSION_COOKIE_NAME ];
		$token       = $this->crypto_service->verify_session_token(
			trim( (string) $request->get_cookie( $cookie_name, '' ) )
		);

		if ( null === $token || '' === $token ) {
			return null;
		}

		return $this->query_one(
			'SELECT * FROM sessions
            WHERE token_hash = :token_hash
            AND revoked_at IS NULL
            AND last_active_at >= :active_since
            LIMIT 1',
			array(
				'token_hash'   => hash( 'sha256', $token ),
				'active_since' => $this->session_active_since(),
			),
		);
	}

	/**
	 * Create a new session row and set the session cookie.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $user_id User row ID.
	 * @since 1.0.0
	 */
	private function create_session_for_user(
		Request $request,
		string $user_id
	): void {
		$this->prune_stale_sessions();

		if ( ! $this->crypto_service->is_configured() ) {
			$this->crypto_service->persist_auth_keys( ABSPATH . 'app' );
			$this->config         = RuntimeConfig::bootstrap( ABSPATH . 'app' );
			$this->crypto_service = new Crypto( $this->config );
		}

		$raw_token = bin2hex( random_bytes( 32 ) );
		$metadata  = Visitor::parse_user_agent( $request->get_user_agent() );
		$row       = array(
			'id'               => $this->generate_random_id(),
			'user_id'          => $user_id,
			'token_hash'       => hash( 'sha256', $raw_token ),
			'user_agent'       => $request->get_user_agent(),
			'ip_address'       => $request->get_ip_address(),
			'browser'          => $metadata['browser'],
			'operating_system' => $metadata['os'],
			'device'           => $metadata['device'],
			'created_at'       => $this->now(),
			'last_active_at'   => $this->now(),
		);

		$this->db->insert( 'sessions', $row );

		$request->queue_cookie(
			(string) $this->config[ Constants::CONFIG_SESSION_COOKIE_NAME ],
			$this->crypto_service->sign_session_token( $raw_token ),
			Security::session_cookie_options(
				$this->config,
				$request,
				array(
					'max-age' => (int) $this->config[ Constants::CONFIG_SESSION_LIFETIME ],
					'expires' => gmdate(
						'D, d M Y H:i:s T',
						time() + (int) $this->config[ Constants::CONFIG_SESSION_LIFETIME ],
					),
				)
			),
		);
	}

	/**
	 * Bump the `last_active_at` timestamp on a session.
	 *
	 * @param string $session_id Session row ID.
	 * @since 1.0.0
	 */
	private function touch_session( string $session_id ): void {
		$this->db->update(
			'sessions',
			array(
				'last_active_at' => $this->now(),
			),
			array(
				'id' => $session_id,
			),
		);
	}

	/**
	 * List active sessions for a user, marking the current one.
	 *
	 * @param string  $user_id User row ID.
	 * @param Request $request Incoming request (to identify the current session).
	 * @return array<int, array<string, mixed>> Session list.
	 * @since 1.0.0
	 */
	private function list_user_sessions(
		string $user_id,
		Request $request
	): array {
		$this->prune_stale_sessions();

		$current_session = $this->find_session_by_request( $request );
		$rows            = $this->query_all(
			'SELECT *
			FROM sessions
			WHERE user_id = :user_id
			AND revoked_at IS NULL
			AND last_active_at >= :active_since
			ORDER BY last_active_at DESC',
			array(
				'user_id'      => $user_id,
				'active_since' => $this->session_active_since(),
			),
		);

		return array_map(
			fn( array $row ): array => array(
				'id'           => (string) $row['id'],
				'device'       => (string) ( $row['device'] ?? '' ),
				'browser'      => (string) ( $row['browser'] ?? '' ),
				'os'           => (string) ( $row['operating_system'] ?? '' ),
				'ipAddress'    => (string) ( $row['ip_address'] ?? '' ),
				'lastActiveAt' => $this->to_iso(
					(string) $row['last_active_at'],
				),
				'createdAt'    => $this->to_iso( (string) $row['created_at'] ),
				'revokedAt'    => null,
				'isCurrent'    => $current_session
					? $row['id'] === $current_session['id']
					: false,
			),
			$rows,
		);
	}

	/**
	 * Delete expired sessions and leftover revoked rows once per request.
	 *
	 * @return void
	 * @since 1.0.3
	 */
	private function prune_stale_sessions(): void {
		static $pruned = false;

		if ( $pruned ) {
			return;
		}

		$this->db->query(
			'DELETE FROM sessions
			WHERE revoked_at IS NOT NULL
			OR last_active_at < :active_since',
			array(
				'active_since' => $this->session_active_since(),
			),
		);

		$pruned = true;
	}
}
