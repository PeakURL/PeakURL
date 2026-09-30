<?php
/**
 * Webhook input validation.
 *
 * Validates URLs, SSRF constraints, and event selections for webhook creation and updates.
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Errors\ApiException;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Validator — Webhook input sanitization, SSRF protection, and event verification.
 *
 * @since 1.0.0
 */
class Validator {

	/**
	 * Return the list of supported event identifiers derived directly from the event catalogue.
	 *
	 * @return array<int, string>
	 * @since 1.7.1
	 */
	public static function get_supported_event_ids(): array {
		return array_column( self::get_event_catalogue(), 'id' );
	}


	/**
	 * Return the authoritative webhook event catalogue.
	 *
	 * @return array<int, array{id: string, label: string, description: string, group: string}>
	 * @since 1.7.1
	 */
	public static function get_event_catalogue(): array {
		return array(
			array(
				'id'          => 'link.created',
				'label'       => __( 'Link Created', 'peakurl' ),
				'description' => __( 'Triggered when a new short link is created.', 'peakurl' ),
				'group'       => 'link',
			),
			array(
				'id'          => 'link.updated',
				'label'       => __( 'Link Updated', 'peakurl' ),
				'description' => __( 'Triggered when an existing link is modified.', 'peakurl' ),
				'group'       => 'link',
			),
			array(
				'id'          => 'link.clicked',
				'label'       => __( 'Link Clicked', 'peakurl' ),
				'description' => __( 'Triggered when a link redirect is accessed.', 'peakurl' ),
				'group'       => 'link',
			),
			array(
				'id'          => 'link.deleted',
				'label'       => __( 'Link Deleted', 'peakurl' ),
				'description' => __( 'Triggered when a link is trashed or permanently deleted.', 'peakurl' ),
				'group'       => 'link',
			),
			array(
				'id'          => 'link.restored',
				'label'       => __( 'Link Restored', 'peakurl' ),
				'description' => __( 'Triggered when a trashed link is restored to active status.', 'peakurl' ),
				'group'       => 'link',
			),
			array(
				'id'          => 'link.activated',
				'label'       => __( 'Link Activated', 'peakurl' ),
				'description' => __( 'Triggered when a deactivated link is re-enabled.', 'peakurl' ),
				'group'       => 'link',
			),
			array(
				'id'          => 'link.deactivated',
				'label'       => __( 'Link Deactivated', 'peakurl' ),
				'description' => __( 'Triggered when an active link is disabled.', 'peakurl' ),
				'group'       => 'link',
			),
			array(
				'id'          => 'link.expired',
				'label'       => __( 'Link Expired', 'peakurl' ),
				'description' => __( 'Triggered when a link reaches its expiration timestamp.', 'peakurl' ),
				'group'       => 'link',
			),
			array(
				'id'          => 'api_key.created',
				'label'       => __( 'API Key Created', 'peakurl' ),
				'description' => __( 'Triggered when a new personal API key is generated.', 'peakurl' ),
				'group'       => 'api_key',
			),
			array(
				'id'          => 'api_key.revoked',
				'label'       => __( 'API Key Revoked', 'peakurl' ),
				'description' => __( 'Triggered when an existing API key is revoked.', 'peakurl' ),
				'group'       => 'api_key',
			),
			array(
				'id'          => 'user.created',
				'label'       => __( 'User Created', 'peakurl' ),
				'description' => __( 'Triggered when a new user account is created or registered.', 'peakurl' ),
				'group'       => 'user',
			),
			array(
				'id'          => 'user.updated',
				'label'       => __( 'User Updated', 'peakurl' ),
				'description' => __( 'Triggered when user account details or profile settings are modified.', 'peakurl' ),
				'group'       => 'user',
			),
			array(
				'id'          => 'user.deleted',
				'label'       => __( 'User Deleted', 'peakurl' ),
				'description' => __( 'Triggered when a user account is removed.', 'peakurl' ),
				'group'       => 'user',
			),
		);
	}

	/**
	 * Validate and normalize verify SSL setting.
	 *
	 * @param array<string, mixed> $payload Input parameters.
	 * @return bool True if SSL verification should be enabled (default true).
	 * @since 1.7.1
	 */
	private function validate_verify_ssl( array $payload ): bool {
		if ( array_key_exists( 'verifySsl', $payload ) ) {
			return ! empty( $payload['verifySsl'] );
		}

		return true;
	}

	/**
	 * Maximum allowed length for a webhook label.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	public const MAX_LABEL_LENGTH = 255;

	/**
	 * Validate and sanitize a webhook label.
	 *
	 * @param mixed $raw_label Input candidate.
	 * @return string Validated and trimmed label string.
	 *
	 * @throws ApiException When label is missing, not a string, empty, or exceeds maximum length.
	 * @since 1.7.1
	 */
	public function validate_label( mixed $raw_label ): string {
		if ( ! is_string( $raw_label ) ) {
			throw new ApiException( __( 'Webhook name is required.', 'peakurl' ), 422 );
		}

		$label = trim( $raw_label );

		if ( '' === $label ) {
			throw new ApiException( __( 'Webhook name cannot be empty.', 'peakurl' ), 422 );
		}

		if ( mb_strlen( $label ) > self::MAX_LABEL_LENGTH ) {
			throw new ApiException(
				sprintf(
					/* translators: %d: maximum number of characters. */
					__( 'Webhook name cannot exceed %d characters.', 'peakurl' ),
					self::MAX_LABEL_LENGTH
				),
				422
			);
		}

		return $label;
	}

	/**
	 * Validate and sanitize payload for creating a new webhook.
	 *
	 * @param array<string, mixed> $payload Request body parameters.
	 * @return array{label: string, url: string, events: array<int, string>, verify_ssl: int} Sanitized fields.
	 *
	 * @throws ApiException When label is invalid (422), URL is invalid or unsafe (422), or no events selected (422).
	 * @since 1.0.0
	 */
	public function validate_create( array $payload ): array {
		$label      = $this->validate_label( $payload['label'] ?? null );
		$url        = $this->validate_url( (string) ( $payload['url'] ?? '' ) );
		$events     = $this->validate_events( $payload['events'] ?? null );
		$verify_ssl = $this->validate_verify_ssl( $payload );

		return array(
			'label'      => $label,
			'url'        => $url,
			'events'     => $events,
			'verify_ssl' => $verify_ssl ? 1 : 0,
		);
	}

	/**
	 * Validate and sanitize payload for updating an existing webhook.
	 *
	 * @param array<string, mixed> $payload Request body parameters.
	 * @return array<string, mixed> Sanitized update fields.
	 *
	 * @throws ApiException When URL is invalid or unsafe (422) or events array is empty (422).
	 * @since 1.0.0
	 */
	public function validate_update( array $payload ): array {
		$updates = array();

		if ( array_key_exists( 'label', $payload ) ) {
			$updates['label'] = $this->validate_label( $payload['label'] );
		}

		if ( array_key_exists( 'url', $payload ) ) {
			$updates['url'] = $this->validate_url( (string) $payload['url'] );
		}

		if ( array_key_exists( 'events', $payload ) ) {
			$updates['events'] = $this->validate_events( $payload['events'] );
		}

		if ( array_key_exists( 'verifySsl', $payload ) ) {
			$updates['verify_ssl'] = ! empty( $payload['verifySsl'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'isActive', $payload ) ) {
			$updates['is_active'] = ! empty( $payload['isActive'] ) ? 1 : 0;
		}

		return $updates;
	}

	/**
	 * Validate a webhook URL for syntax, scheme, and SSRF security.
	 *
	 * @param string $raw_url Input URL candidate.
	 * @return string Validated normalized URL string.
	 *
	 * @throws ApiException When URL is malformed, not HTTP/HTTPS, or targets private/internal targets.
	 * @since 1.7.1
	 */
	private function validate_url( string $raw_url ): string {
		$url = trim( $raw_url );

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new ApiException( __( 'A valid webhook URL is required.', 'peakurl' ), 422 );
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new ApiException( __( 'A valid webhook URL is required.', 'peakurl' ), 422 );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' !== $scheme ) {
			throw new ApiException( __( 'Webhook URL must use the HTTPS protocol.', 'peakurl' ), 422 );
		}

		if ( null === $this->validate_destination( $url ) ) {
			throw new ApiException(
				__( 'Webhook URL cannot target localhost, private, or reserved network addresses.', 'peakurl' ),
				422,
			);
		}

		return $url;
	}

	/**
	 * Validate a webhook URL destination and return its validated public IP.
	 *
	 * Returns the validated public IP address for outbound cURL destination pinning,
	 * or null if the destination is malformed, non-HTTPS, unresolvable, or internal/private.
	 *
	 * @param string $url URL to inspect.
	 * @return string|null Validated public destination IP address, or null if invalid.
	 * @since 1.7.1
	 */
	public function validate_destination( string $url ): ?string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' !== $scheme ) {
			return null;
		}

		$raw_host = trim( (string) $parts['host'] );
		// Strip IPv6 brackets if present.
		$host = trim( $raw_host, '[]' );

		if ( '' === $host ) {
			return null;
		}

		$ip = $this->get_destination_ip( $host );
		if ( null === $ip || ! $this->is_public_ip( $ip ) ) {
			return null;
		}

		return $ip;
	}

	/**
	 * Verify and return a public IP address for the target host.
	 *
	 * Returns null when the host is unresolvable or resolves to an internal/private address.
	 *
	 * @param string $host Target hostname or IP address.
	 * @return string|null Public IP address or null if unresolvable/internal.
	 * @since 1.7.1
	 */
	protected function get_destination_ip( string $host ): ?string {
		$raw = trim( trim( $host ), '[]' );
		if ( '' === $raw ) {
			return null;
		}

		if ( filter_var( $raw, FILTER_VALIDATE_IP ) ) {
			return $this->is_public_ip( $raw ) ? $raw : null;
		}

		$lower_host = strtolower( $raw );
		if (
			'localhost' === $lower_host ||
			str_ends_with( $lower_host, '.localhost' ) ||
			str_ends_with( $lower_host, '.local' ) ||
			str_ends_with( $lower_host, '.internal' ) ||
			str_ends_with( $lower_host, '.lan' )
		) {
			return null;
		}

		// Query DNS records for IPv4 (A) and IPv6 (AAAA). Fail closed on empty/false.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Network functions emit PHP warnings on unresolvable DNS.
		$records = @dns_get_record( $raw, DNS_A | DNS_AAAA );
		if ( ! is_array( $records ) || empty( $records ) ) {
			return null;
		}

		$public_ip = null;
		foreach ( $records as $record ) {
			$ip = isset( $record['ip'] ) ? (string) $record['ip'] : ( isset( $record['ipv6'] ) ? (string) $record['ipv6'] : '' );
			if ( '' === $ip || ! $this->is_public_ip( $ip ) ) {
				return null;
			}
			if ( null === $public_ip ) {
				$public_ip = $ip;
			}
		}

		return $public_ip;
	}

	/**
	 * Determine whether an IP address is a publicly routable unicast IP.
	 *
	 * Rejects:
	 * - IPv4 private (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
	 * - IPv4 loopback (127.0.0.0/8)
	 * - IPv4 link-local (169.254.0.0/16)
	 * - IPv4 carrier-grade NAT (100.64.0.0/10)
	 * - IPv4 documentation/test (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24)
	 * - IPv4 multicast/broadcast/reserved (0.0.0.0/8, 224.0.0.0/4, 240.0.0.0/4, 255.255.255.255)
	 * - IPv6 loopback (::1)
	 * - IPv6 unique local (fc00::/7)
	 * - IPv6 link-local (fe80::/10)
	 * - IPv6 IPv4-mapped (::ffff:0:0/96)
	 *
	 * @param string $ip IP address string.
	 * @return bool True when publicly routable unicast.
	 * @since 1.7.1
	 */
	private function is_public_ip( string $ip ): bool {
		$trimmed = trim( $ip );

		if ( '' === $trimmed ) {
			return false;
		}

		// Basic PHP filter check.
		$is_valid_public = false !== filter_var(
			$trimmed,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		if ( ! $is_valid_public ) {
			return false;
		}

		// Additional IPv4 CIDR protections for ranges not always flagged by PHP FILTER_VALIDATE_IP.
		if ( filter_var( $trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $trimmed );
			if ( false === $long ) {
				return false;
			}

			// 0.0.0.0/8
			if ( ( $long & 0xFF000000 ) === 0 ) {
				return false;
			}

			// 100.64.0.0/10 (Carrier Grade NAT)
			if ( ( $long & 0xFFC00000 ) === (int) ip2long( '100.64.0.0' ) ) {
				return false;
			}

			// 169.254.0.0/16 (Link Local / Cloud Metadata)
			if ( ( $long & 0xFFFF0000 ) === (int) ip2long( '169.254.0.0' ) ) {
				return false;
			}

			// 192.0.0.0/24
			if ( ( $long & 0xFFFFFF00 ) === (int) ip2long( '192.0.0.0' ) ) {
				return false;
			}

			// 198.18.0.0/15 (Benchmarking)
			if ( ( $long & 0xFFFE0000 ) === (int) ip2long( '198.18.0.0' ) ) {
				return false;
			}
		}

		// IPv6 additional checks.
		if ( filter_var( $trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$lower = strtolower( $trimmed );
			if ( '::1' === $lower || '::' === $lower ) {
				return false;
			}

			// Unique local addresses (fc00::/7).
			if ( 0 === strpos( $lower, 'fc' ) || 0 === strpos( $lower, 'fd' ) ) {
				return false;
			}

			// Link-local addresses (fe80::/10).
			if (
				0 === strpos( $lower, 'fe8' ) ||
				0 === strpos( $lower, 'fe9' ) ||
				0 === strpos( $lower, 'fea' ) ||
				0 === strpos( $lower, 'feb' )
			) {
				return false;
			}

			// IPv4-mapped IPv6 addresses (::ffff:...).
			if ( 0 === strpos( $lower, '::ffff:' ) ) {
				$ipv4_part = substr( $lower, 7 );
				if ( filter_var( $ipv4_part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					return $this->is_public_ip( $ipv4_part );
				}
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate and sanitize a list of event strings against supported events.
	 *
	 * @param mixed $events Raw event array.
	 * @return array<int, string> Validated unique event list.
	 *
	 * @throws ApiException When events is empty or contains unsupported events.
	 * @since 1.0.0
	 */
	public function validate_events( $events ): array {
		if ( ! is_array( $events ) || empty( $events ) ) {
			throw new ApiException( __( 'Select at least one webhook event.', 'peakurl' ), 422 );
		}

		$supported = self::get_supported_event_ids();
		$validated = array();

		foreach ( $events as $raw_event ) {
			$event = strtolower( trim( (string) $raw_event ) );

			if ( '' === $event ) {
				continue;
			}

			if ( ! in_array( $event, $supported, true ) ) {
				throw new ApiException(
					/* translators: %s: webhook event name */
					sprintf( __( 'Unsupported webhook event: %s.', 'peakurl' ), $event ),
					422,
				);
			}

			$validated[ $event ] = true;
		}

		if ( empty( $validated ) ) {
			throw new ApiException( __( 'Select at least one webhook event.', 'peakurl' ), 422 );
		}

		return array_keys( $validated );
	}

	/**
	 * Sanitize and deduplicate a list of event strings.
	 *
	 * @param mixed $events Raw event array.
	 * @return array<int, string> Filtered, unique event list.
	 * @since 1.0.0
	 */
	public function sanitize_events( $events ): array {
		if ( ! is_array( $events ) ) {
			return array();
		}

		$supported = self::get_supported_event_ids();
		$valid     = array();

		foreach ( $events as $event ) {
			$event = strtolower( trim( (string) $event ) );
			if ( in_array( $event, $supported, true ) ) {
				$valid[ $event ] = true;
			}
		}

		return array_keys( $valid );
	}
}
