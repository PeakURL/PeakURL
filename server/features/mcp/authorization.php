<?php
/**
 * Model Context Protocol (MCP) authorization and OAuth service.
 *
 * Implements RFC 7636 PKCE S256, OAuth 2.1 code exchange, bearer token
 * verification, scope validation, and grant revocation with atomic row-locked
 * storage.
 *
 * @package PeakURL\Features\Mcp
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Mcp;

use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Roles;
use PeakURL\Http\Request;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Authorization — OAuth and credential manager for PeakURL MCP Server.
 *
 * @since 1.8.0
 */
class Authorization {

	/**
	 * Settings API dependency.
	 *
	 * @var SettingsApi
	 * @since 1.8.0
	 */
	private readonly SettingsApi $settings;

	/**
	 * Users API dependency.
	 *
	 * @var UsersApi
	 * @since 1.8.0
	 */
	private readonly UsersApi $users;

	/**
	 * Roles service dependency.
	 *
	 * @var Roles
	 * @since 1.8.0
	 */
	private readonly Roles $roles;

	/**
	 * Runtime configuration.
	 *
	 * @var array<string, mixed>
	 * @since 1.8.0
	 */
	private readonly array $config;

	/**
	 * Create a new MCP Authorization instance.
	 *
	 * @param SettingsApi          $settings Settings API.
	 * @param UsersApi             $users    Users API.
	 * @param Roles                $roles    Roles service.
	 * @param array<string, mixed> $config   Runtime configuration.
	 * @since 1.8.0
	 */
	public function __construct(
		SettingsApi $settings,
		UsersApi $users,
		Roles $roles,
		array $config
	) {
		$this->settings = $settings;
		$this->users    = $users;
		$this->roles    = $roles;
		$this->config   = $config;
	}

	/**
	 * Check whether MCP server is globally enabled.
	 *
	 * @return bool True if MCP is enabled.
	 * @since 1.8.0
	 */
	public function is_enabled(): bool {
		$val = $this->settings->get_option( Constants::SETTING_MCP_ENABLED );
		if ( null === $val ) {
			return false;
		}

		return 'true' === $val || '1' === $val || true === $val;
	}

	/**
	 * Check whether MCP write tools are enabled.
	 *
	 * @return bool True if write tools are enabled.
	 * @since 1.8.0
	 */
	public function is_write_enabled(): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$val = $this->settings->get_option( Constants::SETTING_MCP_WRITE_ENABLED );
		if ( null === $val ) {
			return false;
		}

		return 'true' === $val || '1' === $val || true === $val;
	}

	/**
	 * Enable or disable MCP server globally.
	 *
	 * @param bool $enabled Target state.
	 * @return void
	 * @since 1.8.0
	 */
	public function set_enabled( bool $enabled ): void {
		$this->settings->update_option( Constants::SETTING_MCP_ENABLED, $enabled ? 'true' : 'false', Date::now() );
	}

	/**
	 * Enable or disable MCP write tools.
	 *
	 * @param bool $enabled Target state.
	 * @return void
	 * @since 1.8.0
	 */
	public function set_write_enabled( bool $enabled ): void {
		$this->settings->update_option( Constants::SETTING_MCP_WRITE_ENABLED, $enabled ? 'true' : 'false', Date::now() );
	}

	/**
	 * Get the list of allowed OAuth redirect URIs.
	 *
	 * @return array<int, string> Allowed redirect URI list.
	 * @since 1.8.0
	 */
	public function get_allowed_redirect_uris(): array {
		$raw = $this->settings->get_option( Constants::SETTING_MCP_ALLOWED_REDIRECT_URIS );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( 'strval', $decoded ) ) );
			}
		}

		return Constants::DEFAULT_ALLOWED_REDIRECT_URIS;
	}

	/**
	 * Check whether a hostname is a local loopback address.
	 *
	 * Supports localhost, 127.0.0.1, and [::1] loopback targets.
	 *
	 * @param string $host Hostname or IP.
	 * @return bool True if loopback.
	 * @since 1.8.0
	 */
	public static function is_loopback_host( string $host ): bool {
		$normalized = strtolower( trim( $host, '[]' ) );
		return 'localhost' === $normalized
			|| '127.0.0.1' === $normalized
			|| '::1' === $normalized;
	}

	/**
	 * Validate the structure, protocol, and components of a redirect URI.
	 *
	 * Enforces RFC 6749 Section 3.1.2 and RFC 8252 security rules:
	 * - Absolute URI with scheme and host
	 * - No fragment identifiers (#)
	 * - No userinfo credentials (@)
	 * - HTTPS required for remote callback endpoints
	 * - HTTP permitted exclusively for local loopback callbacks
	 * - Valid port range (1–65535) if specified
	 *
	 * @param string      $uri           Candidate redirect URI.
	 * @param string|null $error_message Optional output variable receiving reason on failure.
	 * @return bool True if structurally valid and compliant with policy.
	 * @since 1.8.0
	 */
	public function validate_redirect_uri_structure( string $uri, ?string &$error_message = null ): bool {
		$clean = trim( $uri );
		if ( '' === $clean ) {
			$error_message = __( 'Redirect URI cannot be empty.', 'peakurl' );
			return false;
		}

		if ( str_contains( $clean, '#' ) ) {
			$error_message = sprintf(
				/* translators: %s is the redirect URI */
				__( 'Redirect URI cannot contain a fragment identifier: "%s".', 'peakurl' ),
				$clean
			);
			return false;
		}

		$parsed = parse_url( $clean );
		if ( false === $parsed || ! is_array( $parsed ) ) {
			$error_message = sprintf(
				/* translators: %s is the redirect URI */
				__( 'Invalid redirect URI: "%s".', 'peakurl' ),
				$clean
			);
			return false;
		}

		if ( isset( $parsed['fragment'] ) ) {
			$error_message = sprintf(
				/* translators: %s is the redirect URI */
				__( 'Redirect URI cannot contain a fragment identifier: "%s".', 'peakurl' ),
				$clean
			);
			return false;
		}

		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) || str_contains( $parsed['host'] ?? '', '@' ) ) {
			$error_message = sprintf(
				/* translators: %s is the redirect URI */
				__( 'Redirect URI cannot contain user information: "%s".', 'peakurl' ),
				$clean
			);
			return false;
		}

		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		$host   = (string) ( $parsed['host'] ?? '' );

		if ( '' === $scheme || '' === $host ) {
			$error_message = sprintf(
				/* translators: %s is the redirect URI */
				__( 'Redirect URI must be an absolute URL with scheme and host: "%s".', 'peakurl' ),
				$clean
			);
			return false;
		}

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$error_message = sprintf(
				/* translators: %s is the redirect URI */
				__( 'Unsupported protocol for redirect URI: "%s". Only HTTP and HTTPS are permitted.', 'peakurl' ),
				$clean
			);
			return false;
		}

		if ( isset( $parsed['port'] ) ) {
			$port = (int) $parsed['port'];
			if ( $port < 1 || $port > 65535 ) {
				$error_message = sprintf(
					/* translators: %s is the redirect URI */
					__( 'Invalid port for redirect URI: "%s".', 'peakurl' ),
					$clean
				);
				return false;
			}
		}

		$is_loopback = self::is_loopback_host( $host );

		if ( 'http' === $scheme && ! $is_loopback ) {
			$error_message = sprintf(
				/* translators: %s is the redirect URI */
				__( 'Remote redirect URIs must use HTTPS: "%s".', 'peakurl' ),
				$clean
			);
			return false;
		}

		return true;
	}

	/**
	 * Update the list of allowed OAuth redirect URIs.
	 *
	 * @param array<int, string> $uris Array of allowed URIs.
	 * @return void
	 * @since 1.8.0
	 */
	public function set_allowed_redirect_uris( array $uris ): void {
		$clean = array();
		foreach ( $uris as $uri ) {
			$trimmed = trim( (string) $uri );
			if ( $this->validate_redirect_uri_structure( $trimmed ) ) {
				$clean[] = $trimmed;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		$this->settings->update_option( Constants::SETTING_MCP_ALLOWED_REDIRECT_URIS, (string) json_encode( $clean ), Date::now() );
	}

	/**
	 * Validate a requested redirect URI against the allowlist and supported MCP client patterns.
	 *
	 * Validates against the administrative allowlist and well-known MCP client schemes
	 * including ChatGPT Custom GPT/Connector callbacks and local IDE loopback ports.
	 *
	 * @param string $uri Requested redirect URI.
	 * @return bool True if the URI is allowed.
	 * @since 1.8.0
	 */
	public function validate_redirect_uri( string $uri ): bool {
		$clean = trim( $uri );
		if ( ! $this->validate_redirect_uri_structure( $clean ) ) {
			return false;
		}

		$allowed = $this->get_allowed_redirect_uris();

		// Exact match against configured allowlist.
		if ( in_array( $clean, $allowed, true ) ) {
			return true;
		}

		// Loopback callbacks for local developer clients (RFC 8252 Section 7.3).
		$parsed = parse_url( $clean );
		if ( is_array( $parsed ) && isset( $parsed['host'] ) ) {
			return self::is_loopback_host( (string) $parsed['host'] );
		}

		return false;
	}

	/**
	 * Create an authorization transaction binding all request parameters server-side.
	 *
	 * Generates a single-use transaction ID and CSRF token for the consent screen.
	 *
	 * @param string             $user_id               Authenticated user ID.
	 * @param string             $client_id             Client identifier.
	 * @param string             $redirect_uri          Validated callback URI.
	 * @param string             $code_challenge        PKCE code challenge.
	 * @param string             $code_challenge_method Must be 'S256'.
	 * @param array<int, string> $scopes                Requested scopes.
	 * @param string             $state                 Client state string.
	 * @return array{transaction_id: string, csrf_token: string} Generated tokens.
	 *
	 * @throws \InvalidArgumentException When parameters are invalid.
	 * @since 1.8.0
	 */
	public function create_authorization_transaction(
		string $user_id,
		string $client_id,
		string $redirect_uri,
		string $code_challenge,
		string $code_challenge_method,
		array $scopes,
		string $state
	): array {
		if ( 'S256' !== $code_challenge_method ) {
			throw new \InvalidArgumentException( 'Only PKCE code_challenge_method=S256 is supported.' );
		}

		if ( ! $this->validate_redirect_uri( $redirect_uri ) ) {
			throw new \InvalidArgumentException( 'Redirect URI is not allowed.' );
		}

		$transaction_id = bin2hex( random_bytes( 24 ) );
		$csrf_token     = bin2hex( random_bytes( 24 ) );
		$now            = gmdate( 'Y-m-d H:i:s' );
		$expires_at     = gmdate( 'Y-m-d H:i:s', time() + Constants::AUTH_CODE_LIFETIME );

		$entry = array(
			'transaction_id'        => $transaction_id,
			'csrf_token_hash'       => hash( 'sha256', $csrf_token ),
			'user_id'               => $user_id,
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'scopes'                => array_values( array_intersect( $scopes, Constants::ALL_SCOPES ) ),
			'state'                 => $state,
			'expires_at'            => $expires_at,
		);

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_AUTH_TRANSACTIONS,
			static function ( ?string $raw ) use ( $entry, $now ): string {
				$txs = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$txs = array_values(
							array_filter(
								$decoded,
								static fn( $item ) => is_array( $item ) && ( $item['expires_at'] ?? '' ) > $now
							)
						);
					}
				}
				$txs[] = $entry;
				return (string) json_encode( $txs );
			}
		);

		return array(
			'transaction_id' => $transaction_id,
			'csrf_token'     => $csrf_token,
		);
	}

	/**
	 * Consume and validate an authorization transaction atomically.
	 *
	 * Enforces single-use redemption, CSRF token verification, and user session match.
	 *
	 * @param string $transaction_id Transaction identifier.
	 * @param string $csrf_token     CSRF token submitted with the form.
	 * @param string $user_id        Currently authenticated user ID.
	 * @return array<string, mixed> Validated transaction data.
	 *
	 * @throws \InvalidArgumentException When the transaction is invalid, expired, or unauthorized.
	 * @since 1.8.0
	 */
	public function consume_authorization_transaction(
		string $transaction_id,
		string $csrf_token,
		string $user_id
	): array {
		if ( '' === trim( $transaction_id ) || '' === trim( $csrf_token ) ) {
			throw new \InvalidArgumentException( 'Missing transaction credentials or CSRF token.' );
		}

		$csrf_hash = hash( 'sha256', $csrf_token );
		$now       = gmdate( 'Y-m-d H:i:s' );
		$matched   = null;

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_AUTH_TRANSACTIONS,
			function ( ?string $raw ) use ( $transaction_id, $csrf_hash, $user_id, $now, &$matched ): string {
				if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
					return '[]';
				}

				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					return '[]';
				}

				$remaining = array();
				foreach ( $decoded as $item ) {
					if ( ! is_array( $item ) || ( $item['expires_at'] ?? '' ) <= $now ) {
						continue;
					}

					if (
						( $item['transaction_id'] ?? '' ) === $transaction_id &&
						hash_equals( (string) ( $item['csrf_token_hash'] ?? '' ), $csrf_hash ) &&
						(string) ( $item['user_id'] ?? '' ) === $user_id
					) {
						$matched = $item;
						// Single-use: do not retain in remaining transactions.
						continue;
					}

					$remaining[] = $item;
				}

				return (string) json_encode( array_values( $remaining ) );
			}
		);

		if ( null === $matched ) {
			throw new \InvalidArgumentException( 'Invalid, expired, or unauthorized authorization transaction.' );
		}

		return $matched;
	}

	/**
	 * Create an OAuth authorization code with atomic storage.
	 *
	 * @param string      $user_id               User ID granting access.
	 * @param string      $client_id             Client identifier.
	 * @param string      $redirect_uri          Validated callback URI.
	 * @param string      $code_challenge        PKCE code challenge.
	 * @param string      $code_challenge_method Must be 'S256'.
	 * @param array<int, string> $scopes         Requested scopes.
	 * @param string|null $client_name           Human-readable client display name.
	 * @return string Plain-text authorization code.
	 *
	 * @throws \InvalidArgumentException When inputs are invalid.
	 * @since 1.8.0
	 */
	public function create_authorization_code(
		string $user_id,
		string $client_id,
		string $redirect_uri,
		string $code_challenge,
		string $code_challenge_method,
		array $scopes,
		?string $client_name = null
	): string {
		if ( 'S256' !== $code_challenge_method ) {
			throw new \InvalidArgumentException( 'Only PKCE code_challenge_method=S256 is supported.' );
		}

		if ( ! $this->validate_redirect_uri( $redirect_uri ) ) {
			throw new \InvalidArgumentException( 'Redirect URI is not allowed.' );
		}

		$plain_code = bin2hex( random_bytes( 32 ) );
		$code_hash  = hash( 'sha256', $plain_code );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + Constants::AUTH_CODE_LIFETIME );
		$now        = gmdate( 'Y-m-d H:i:s' );

		$new_entry = array(
			'code_hash'             => $code_hash,
			'user_id'               => $user_id,
			'client_id'             => $client_id,
			'client_name'           => $client_name ? $client_name : $client_id,
			'redirect_uri'          => $redirect_uri,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'scopes'                => array_values( array_intersect( $scopes, Constants::ALL_SCOPES ) ),
			'expires_at'            => $expires_at,
		);

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_AUTH_CODES,
			static function ( ?string $raw ) use ( $new_entry, $now ): string {
				$codes = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$codes = array_values(
							array_filter(
								$decoded,
								static fn( $item ) => is_array( $item ) && ( $item['expires_at'] ?? '' ) > $now
							)
						);
					}
				}
				$codes[] = $new_entry;
				return (string) json_encode( $codes );
			}
		);

		return $plain_code;
	}

	/**
	 * Exchange an authorization code for an access token and refresh token atomically.
	 *
	 * Enforces single-use consumption and PKCE S256 verification under database lock.
	 *
	 * @param string $code          Plain-text authorization code.
	 * @param string $code_verifier PKCE code verifier.
	 * @param string $redirect_uri  Callback URI (must match original).
	 * @param string $client_id     Client ID (must match original).
	 * @return array<string, mixed> OAuth token response.
	 *
	 * @throws \InvalidArgumentException When verification fails.
	 * @since 1.8.0
	 */
	public function exchange_authorization_code(
		string $code,
		string $code_verifier,
		string $redirect_uri,
		string $client_id
	): array {
		$code_hash = hash( 'sha256', trim( $code ) );
		$matched   = null;
		$now       = gmdate( 'Y-m-d H:i:s' );

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_AUTH_CODES,
			static function ( ?string $raw ) use ( $code_hash, &$matched, $now ): string {
				$codes = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$codes = $decoded;
					}
				}

				$remaining = array();
				foreach ( $codes as $entry ) {
					if ( is_array( $entry ) && ( $entry['code_hash'] ?? '' ) === $code_hash ) {
						$matched = $entry;
					} elseif ( is_array( $entry ) && ( $entry['expires_at'] ?? '' ) > $now ) {
						$remaining[] = $entry;
					}
				}

				return (string) json_encode( array_values( $remaining ) );
			}
		);

		if ( ! $matched ) {
			throw new \InvalidArgumentException( 'Invalid or expired authorization code.' );
		}

		if ( ( $matched['expires_at'] ?? '' ) <= $now ) {
			throw new \InvalidArgumentException( 'Authorization code has expired.' );
		}

		if ( ( $matched['client_id'] ?? '' ) !== $client_id ) {
			throw new \InvalidArgumentException( 'Client ID does not match the authorization code.' );
		}

		if ( ( $matched['redirect_uri'] ?? '' ) !== $redirect_uri ) {
			throw new \InvalidArgumentException( 'Redirect URI does not match the authorization code.' );
		}

		// Verify PKCE S256 challenge.
		if ( ! $this->verify_pkce_s256( $code_verifier, (string) $matched['code_challenge'] ) ) {
			throw new \InvalidArgumentException( 'PKCE code_verifier challenge verification failed.' );
		}

		// User must still exist.
		$user = $this->users->get_user( (string) $matched['user_id'] );
		if ( ! $user ) {
			throw new \InvalidArgumentException( 'User associated with authorization code no longer exists.' );
		}

		return $this->issue_token_pair(
			(string) $matched['user_id'],
			(string) $matched['client_id'],
			(string) ( $matched['client_name'] ?? $matched['client_id'] ),
			(array) $matched['scopes']
		);
	}

	/**
	 * Refresh an existing grant with a refresh token atomically.
	 *
	 * Performs refresh token rotation and replay prevention under database lock.
	 *
	 * @param string      $refresh_token Plain-text refresh token.
	 * @param string|null $client_id     Optional client ID to match.
	 * @return array<string, mixed> New token pair response.
	 *
	 * @throws \InvalidArgumentException When the refresh token is invalid or expired.
	 * @since 1.8.0
	 */
	public function refresh_token( string $refresh_token, ?string $client_id = null ): array {
		$refresh_hash = hash( 'sha256', trim( $refresh_token ) );
		$now          = gmdate( 'Y-m-d H:i:s' );
		$result       = null;
		$error_msg    = null;

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_CONNECTIONS,
			function ( ?string $raw ) use ( $refresh_hash, $client_id, $now, &$result, &$error_msg ): ?string {
				$connections = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$connections = $decoded;
					}
				}

				$matched_idx = -1;
				foreach ( $connections as $idx => $conn ) {
					if (
						isset( $conn['refresh_token_hash'] ) &&
						hash_equals( (string) $conn['refresh_token_hash'], $refresh_hash )
					) {
						$matched_idx = $idx;
						break;
					}
				}

				if ( -1 === $matched_idx ) {
					$error_msg = 'Invalid refresh token.';
					return $raw;
				}

				$conn = $connections[ $matched_idx ];

				if ( ! empty( $conn['revoked_at'] ) ) {
					$error_msg = 'Grant has been revoked.';
					return $raw;
				}

				if ( ! empty( $conn['refresh_expires_at'] ) && $conn['refresh_expires_at'] <= $now ) {
					$error_msg = 'Refresh token has expired.';
					return $raw;
				}

				if ( null !== $client_id && ( $conn['client_id'] ?? '' ) !== $client_id ) {
					$error_msg = 'Client ID does not match the grant.';
					return $raw;
				}

				// Rotate token pair atomically.
				$new_access_token   = bin2hex( random_bytes( 32 ) );
				$new_refresh_token  = bin2hex( random_bytes( 32 ) );
				$access_expires_at  = gmdate( 'Y-m-d H:i:s', time() + Constants::ACCESS_TOKEN_LIFETIME );
				$refresh_expires_at = gmdate( 'Y-m-d H:i:s', time() + Constants::REFRESH_TOKEN_LIFETIME );

				$connections[ $matched_idx ]['token_hash']         = hash( 'sha256', $new_access_token );
				$connections[ $matched_idx ]['refresh_token_hash'] = hash( 'sha256', $new_refresh_token );
				$connections[ $matched_idx ]['expires_at']         = $access_expires_at;
				$connections[ $matched_idx ]['refresh_expires_at'] = $refresh_expires_at;
				$connections[ $matched_idx ]['last_used_at']       = $now;

				$result = array(
					'access_token'  => $new_access_token,
					'token_type'    => 'Bearer',
					'expires_in'    => Constants::ACCESS_TOKEN_LIFETIME,
					'refresh_token' => $new_refresh_token,
					'scope'         => implode( ' ', (array) ( $conn['scopes'] ?? array() ) ),
				);

				return (string) json_encode( array_values( $connections ) );
			}
		);

		if ( null !== $error_msg ) {
			throw new \InvalidArgumentException( $error_msg );
		}

		if ( ! is_array( $result ) ) {
			throw new \InvalidArgumentException( 'Unable to refresh token.' );
		}

		return $result;
	}

	/**
	 * Revoke a token (access token or refresh token) under atomic lock.
	 *
	 * @param string $token Plain-text token.
	 * @return bool True if a matching token was revoked.
	 * @since 1.8.0
	 */
	public function revoke_token( string $token ): bool {
		$token_hash = hash( 'sha256', trim( $token ) );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$found      = false;

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_CONNECTIONS,
			static function ( ?string $raw ) use ( $token_hash, $now, &$found ): ?string {
				$connections = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$connections = $decoded;
					}
				}

				foreach ( $connections as $idx => $conn ) {
					$match_access  = isset( $conn['token_hash'] ) && hash_equals( (string) $conn['token_hash'], $token_hash );
					$match_refresh = isset( $conn['refresh_token_hash'] ) && hash_equals( (string) $conn['refresh_token_hash'], $token_hash );

					if ( $match_access || $match_refresh ) {
						$connections[ $idx ]['revoked_at'] = $now;
						$found                             = true;
					}
				}

				return (string) json_encode( array_values( $connections ) );
			}
		);

		return $found;
	}

	/**
	 * Revoke a connection grant by its ID under atomic lock.
	 *
	 * @param string $connection_id Connection grant ID.
	 * @return bool True if revoked.
	 * @since 1.8.0
	 */
	public function revoke_connection( string $connection_id ): bool {
		$now   = gmdate( 'Y-m-d H:i:s' );
		$found = false;

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_CONNECTIONS,
			static function ( ?string $raw ) use ( $connection_id, $now, &$found ): ?string {
				$connections = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$connections = $decoded;
					}
				}

				foreach ( $connections as $idx => $conn ) {
					if ( ( $conn['id'] ?? '' ) === $connection_id && empty( $conn['revoked_at'] ) ) {
						$connections[ $idx ]['revoked_at'] = $now;
						$found                             = true;
					}
				}

				return (string) json_encode( array_values( $connections ) );
			}
		);

		return $found;
	}

	/**
	 * Revoke all active MCP client connections under atomic lock.
	 *
	 * @return int Number of active connections revoked.
	 * @since 1.8.0
	 */
	public function revoke_all_connections(): int {
		$now     = gmdate( 'Y-m-d H:i:s' );
		$revoked = 0;

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_CONNECTIONS,
			static function ( ?string $raw ) use ( $now, &$revoked ): ?string {
				$connections = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$connections = $decoded;
					}
				}

				foreach ( $connections as $idx => $conn ) {
					if ( empty( $conn['revoked_at'] ) ) {
						$connections[ $idx ]['revoked_at'] = $now;
						++$revoked;
					}
				}

				return (string) json_encode( array_values( $connections ) );
			}
		);

		return $revoked;
	}

	/**
	 * List all active and past MCP client connections for dashboard display.
	 *
	 * Redacts all secret material.
	 *
	 * @return array<int, array<string, mixed>> List of connection payloads.
	 * @since 1.8.0
	 */
	public function list_connections(): array {
		$connections = $this->get_stored_connections();
		$list        = array();

		foreach ( $connections as $conn ) {
			$user = $this->users->get_user( (string) $conn['user_id'] );

			$list[] = array(
				'id'          => $conn['id'],
				'clientId'    => $conn['client_id'],
				'clientName'  => $conn['client_name'] ?? $conn['client_id'],
				'userId'      => $conn['user_id'],
				'username'    => $user['username'] ?? 'unknown',
				'displayName' => $user['display_name'] ?? ( $user['username'] ?? 'User' ),
				'scopes'      => $conn['scopes'],
				'createdAt'   => Date::to_iso( $conn['created_at'] ),
				'lastUsedAt'  => ! empty( $conn['last_used_at'] ) ? Date::to_iso( $conn['last_used_at'] ) : null,
				'expiresAt'   => ! empty( $conn['expires_at'] ) ? Date::to_iso( $conn['expires_at'] ) : null,
				'revokedAt'   => ! empty( $conn['revoked_at'] ) ? Date::to_iso( $conn['revoked_at'] ) : null,
				'isActive'    => empty( $conn['revoked_at'] ),
			);
		}

		return $list;
	}

	/**
	 * Authenticate an incoming MCP request via Bearer token.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>|null Auth context or null if not authenticated.
	 * @since 1.8.0
	 */
	public function authenticate_request( Request $request ): ?array {
		$auth_header = trim( (string) $request->get_header( 'Authorization', '' ) );

		if ( '' === $auth_header || ! preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return null;
		}

		$token      = trim( $matches[1] );
		$token_hash = hash( 'sha256', $token );
		$now        = gmdate( 'Y-m-d H:i:s' );

		$connections = $this->get_stored_connections();
		$matched_idx = -1;

		foreach ( $connections as $idx => $conn ) {
			if (
				isset( $conn['token_hash'] ) &&
				hash_equals( (string) $conn['token_hash'], $token_hash )
			) {
				$matched_idx = $idx;
				break;
			}
		}

		if ( -1 === $matched_idx ) {
			return null;
		}

		$conn = $connections[ $matched_idx ];

		// Check if grant was revoked.
		if ( ! empty( $conn['revoked_at'] ) ) {
			return null;
		}

		// Check token expiration.
		if ( ! empty( $conn['expires_at'] ) && $conn['expires_at'] <= $now ) {
			return null;
		}

		// Load user.
		$user = $this->users->get_user( (string) $conn['user_id'] );
		if ( ! $user ) {
			return null;
		}

		// Touch last_used_at safely under database lock without overwriting other state.
		$this->touch_connection_usage( (string) $conn['id'], $now, $conn['last_used_at'] ?? null );

		return array(
			'user'       => $user,
			'connection' => $conn,
			'scopes'     => $conn['scopes'],
		);
	}

	/**
	 * Determine whether an authenticated context has permission to execute a tool.
	 *
	 * Evaluates: feature_enabled ∩ grant_active ∩ token_scopes ∩ role_capability ∩ write_policy.
	 *
	 * @param array<string, mixed> $auth_context Authenticated context.
	 * @param array<string, mixed> $tool_def     Tool definition from Registry.
	 * @return bool True if permitted.
	 * @since 1.8.0
	 */
	public function can_call_tool( array $auth_context, array $tool_def ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		// If tool is a write tool, write mode must be enabled globally.
		if ( ! empty( $tool_def['is_write'] ) && ! $this->is_write_enabled() ) {
			return false;
		}

		// Check OAuth scope.
		if ( ! empty( $tool_def['scope'] ) ) {
			$granted_scopes = $auth_context['scopes'] ?? array();
			if ( ! in_array( $tool_def['scope'], $granted_scopes, true ) ) {
				return false;
			}
		}

		// Check user capability via role registry.
		if ( ! empty( $tool_def['capability'] ) ) {
			$user = $auth_context['user'] ?? array();
			if ( ! $this->roles->has_capability( $user, $tool_def['capability'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Verify PKCE code_verifier against code_challenge using S256.
	 *
	 * @param string $verifier  Plain-text code verifier.
	 * @param string $challenge Expected base64url-encoded SHA-256 challenge.
	 * @return bool True if valid.
	 * @since 1.8.0
	 */
	private function verify_pkce_s256( string $verifier, string $challenge ): bool {
		$hash       = hash( 'sha256', $verifier, true );
		$calculated = rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );

		return hash_equals( $challenge, $calculated );
	}

	/**
	 * Issue a new access token and refresh token pair and persist the grant atomically.
	 *
	 * @param string             $user_id     User ID.
	 * @param string             $client_id   Client ID.
	 * @param string             $client_name Display name.
	 * @param array<int, string> $scopes      Granted scopes.
	 * @return array<string, mixed> OAuth response payload.
	 * @since 1.8.0
	 */
	private function issue_token_pair(
		string $user_id,
		string $client_id,
		string $client_name,
		array $scopes
	): array {
		$access_token       = bin2hex( random_bytes( 32 ) );
		$refresh_token      = bin2hex( random_bytes( 32 ) );
		$connection_id      = Str::random_id();
		$now                = gmdate( 'Y-m-d H:i:s' );
		$access_expires_at  = gmdate( 'Y-m-d H:i:s', time() + Constants::ACCESS_TOKEN_LIFETIME );
		$refresh_expires_at = gmdate( 'Y-m-d H:i:s', time() + Constants::REFRESH_TOKEN_LIFETIME );

		$new_conn = array(
			'id'                 => $connection_id,
			'client_id'          => $client_id,
			'client_name'        => $client_name,
			'user_id'            => $user_id,
			'token_hash'         => hash( 'sha256', $access_token ),
			'refresh_token_hash' => hash( 'sha256', $refresh_token ),
			'scopes'             => $scopes,
			'expires_at'         => $access_expires_at,
			'refresh_expires_at' => $refresh_expires_at,
			'created_at'         => $now,
			'last_used_at'       => $now,
			'revoked_at'         => null,
		);

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_CONNECTIONS,
			static function ( ?string $raw ) use ( $new_conn ): string {
				$connections = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$connections = $decoded;
					}
				}
				$connections[] = $new_conn;
				return (string) json_encode( array_values( $connections ) );
			}
		);

		return array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => Constants::ACCESS_TOKEN_LIFETIME,
			'refresh_token' => $refresh_token,
			'scope'         => implode( ' ', $scopes ),
		);
	}

	/**
	 * Retrieve stored connection grants from Settings.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.8.0
	 */
	private function get_stored_connections(): array {
		$raw = $this->settings->get_option( Constants::SETTING_MCP_CONNECTIONS );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Update the last_used_at timestamp for a connection grant safely under database lock.
	 *
	 * Ensures usage tracking never overwrites newer token refreshes, revocations, or concurrent grants.
	 * Throttles database writes so requests within 60 seconds do not cause unnecessary lock contention.
	 *
	 * @param string      $connection_id    Connection ID.
	 * @param string      $timestamp        ISO / MySQL timestamp.
	 * @param string|null $previous_used_at Previous timestamp to throttle updates.
	 * @return void
	 * @since 1.8.0
	 */
	public function touch_connection_usage( string $connection_id, string $timestamp, ?string $previous_used_at = null ): void {
		if ( null !== $previous_used_at && '' !== $previous_used_at ) {
			$diff = strtotime( $timestamp ) - strtotime( $previous_used_at );
			if ( $diff >= 0 && $diff < 60 ) {
				return;
			}
		}

		$this->settings->update_option_with_lock(
			Constants::SETTING_MCP_CONNECTIONS,
			static function ( ?string $raw ) use ( $connection_id, $timestamp ): ?string {
				if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
					return $raw;
				}

				$connections = json_decode( $raw, true );
				if ( ! is_array( $connections ) ) {
					return $raw;
				}

				$changed = false;
				foreach ( $connections as $idx => $conn ) {
					if ( is_array( $conn ) && isset( $conn['id'] ) && $conn['id'] === $connection_id ) {
						// Do not touch revoked connection grants.
						if ( ! empty( $conn['revoked_at'] ) ) {
							return $raw;
						}

						if ( empty( $conn['last_used_at'] ) || $conn['last_used_at'] < $timestamp ) {
							$connections[ $idx ]['last_used_at'] = $timestamp;
							$changed                             = true;
						}
						break;
					}
				}

				return $changed ? (string) json_encode( array_values( $connections ) ) : $raw;
			}
		);
	}
}
