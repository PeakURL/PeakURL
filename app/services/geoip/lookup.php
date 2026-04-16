<?php
/**
 * MaxMind location lookup helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use MaxMind\Db\Reader;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Lookup — resolve country and city data for public IP addresses.
 *
 * @since 1.0.14
 */
class Lookup {

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new lookup helper.
	 *
	 * @param Context $context Shared GeoIP context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Resolve country and city values for a visitor IP address.
	 *
	 * @param string $ip_address Visitor IP address.
	 * @return array<string, string|null>
	 * @since 1.0.14
	 */
	public function lookup_location( string $ip_address = '' ): array {
		if ( ! $this->is_public_ip_address( $ip_address ) ) {
			return $this->get_empty_location();
		}

		$reader = $this->get_reader();

		if ( null === $reader ) {
			return $this->get_empty_location();
		}

		try {
			$record = $reader->get( $ip_address );
		} catch ( \Throwable $exception ) {
			return $this->get_empty_location();
		}

		if ( ! is_array( $record ) ) {
			return $this->get_empty_location();
		}

		$country = is_array( $record['country'] ?? null )
			? $record['country']
			: ( is_array( $record['registered_country'] ?? null )
				? $record['registered_country']
				: array() );
		$city    = is_array( $record['city'] ?? null )
			? $record['city']
			: array();

		return array(
			'country_code' => $this->normalize_country_code(
				(string) ( $country['iso_code'] ?? '' ),
			),
			'country_name' => $this->get_display_name( $country ),
			'city_name'    => $this->get_display_name( $city ),
		);
	}

	/**
	 * Lazily initialize the MaxMind reader.
	 *
	 * @return Reader|null
	 * @since 1.0.14
	 */
	private function get_reader(): ?Reader {
		if ( $this->context->is_reader_initialized() ) {
			return $this->context->get_reader();
		}

		$this->context->set_reader_initialized( true );
		$database_path = $this->context->get_database_path();

		if ( '' === $database_path || ! file_exists( $database_path ) || ! is_readable( $database_path ) ) {
			return null;
		}

		try {
			$this->context->set_reader( new Reader( $database_path ) );
		} catch ( \Throwable $exception ) {
			$this->context->set_reader( null );
		}

		return $this->context->get_reader();
	}

	/**
	 * Extract an English display name from a MaxMind record section.
	 *
	 * @param array<string, mixed> $record MaxMind record data.
	 * @return string|null
	 * @since 1.0.14
	 */
	private function get_display_name( array $record ): ?string {
		$names = $record['names'] ?? null;

		if ( ! is_array( $names ) ) {
			return null;
		}

		$name = trim( (string) ( $names['en'] ?? reset( $names ) ?? '' ) );

		return '' !== $name ? $name : null;
	}

	/**
	 * Normalize an ISO country code.
	 *
	 * @param string $value Raw country code.
	 * @return string|null
	 * @since 1.0.14
	 */
	private function normalize_country_code( string $value ): ?string {
		$country_code = strtoupper( trim( $value ) );

		if (
			'' === $country_code ||
			! preg_match( '/^[A-Z]{2}$/', $country_code ) ||
			in_array( $country_code, array( 'A1', 'A2', 'O1', 'T1', 'XX' ), true )
		) {
			return null;
		}

		return $country_code;
	}

	/**
	 * Check whether an IP address is publicly routable.
	 *
	 * @param string $ip_address Candidate IP address.
	 * @return bool
	 * @since 1.0.14
	 */
	private function is_public_ip_address( string $ip_address ): bool {
		if ( '' === trim( $ip_address ) ) {
			return false;
		}

		return false !== filter_var(
			$ip_address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
		);
	}

	/**
	 * Return the empty location payload.
	 *
	 * @return array<string, string|null>
	 * @since 1.0.14
	 */
	private function get_empty_location(): array {
		return array(
			'country_code' => null,
			'country_name' => null,
			'city_name'    => null,
		);
	}
}
