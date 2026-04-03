<?php
/**
 * Data store helpers trait.
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
use PeakURL\Services\DatabaseSchema;
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
 * HelpersTrait — low-level store helpers shared across modules.
 *
 * @since 1.0.0
 */
trait HelpersTrait {

	/**
	 * Normalise a URL status string to a known enum value.
	 *
	 * @param string $status Raw status input.
	 * @return string Normalised URL status.
	 * @since 1.0.0
	 */
	private function normalize_url_status( string $status ): string {
		$status = strtolower( trim( $status ) );

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
	 * Sanitise a short code or alias to lowercase alphanumeric with hyphens.
	 *
	 * @param string $value Raw code input.
	 * @return string Sanitised code.
	 * @since 1.0.0
	 */
	private function sanitize_code( string $value ): string {
		return $this->replace_or_empty(
			'/[^a-z0-9-]/',
			'',
			strtolower( trim( $value ) ),
		);
	}

	/**
	 * Generate a unique random 6-character short code.
	 *
	 * @return string Collision-free short code.
	 * @since 1.0.0
	 */
	private function generate_short_code(): string {
		do {
			$code = strtolower( substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) );
		} while ( $this->short_code_exists( $code ) );

		return $code;
	}

	/**
	 * Check whether a short code or alias already exists in the URLs table.
	 *
	 * @param string $code Code to check.
	 * @return bool True if the code is taken.
	 * @since 1.0.0
	 */
	private function short_code_exists( string $code ): bool {
		return $this->links_api->short_code_exists( $code );
	}

	/**
	 * Determine whether a code conflicts with reserved application routes.
	 *
	 * @param string $code Code to test.
	 * @return bool True when reserved.
	 * @since 1.0.0
	 */
	private function is_reserved_short_code( string $code ): bool {
		return in_array(
			strtolower( trim( $code ) ),
			array( 'api', 'dashboard', 'login' ),
			true,
		);
	}

	/**
	 * Calculate a simple click-to-unique conversion rate percentage.
	 *
	 * @param int $clicks        Total click count.
	 * @param int $unique_clicks Unique click count.
	 * @return float Conversion rate rounded to one decimal.
	 * @since 1.0.0
	 */
	private function calculate_conversion_rate(
		int $clicks,
		int $unique_clicks
	): float {
		if ( $unique_clicks <= 0 ) {
			return 0.0;
		}

		return round( ( $clicks / $unique_clicks ) * 100, 1 );
	}

	/**
	 * Normalise a mixed datetime value into a MySQL-compatible string.
	 *
	 * @param mixed $value Raw datetime input.
	 * @return string|null Formatted datetime or null.
	 * @since 1.0.0
	 */
	private function normalize_datetime_value( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$timestamp = strtotime( $value );

			if ( false === $timestamp ) {
				throw new ApiException( 'Invalid date value provided.', 422 );
			}

			return gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		throw new ApiException( 'Invalid date value provided.', 422 );
	}

	/**
	 * Cast a mixed value to a nullable trimmed string.
	 *
	 * @param mixed $value Input value.
	 * @return string|null Trimmed string or null.
	 * @since 1.0.0
	 */
	private function nullable_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table_name Table name to check.
	 * @return bool True when the table exists.
	 * @since 1.0.0
	 */
	private function table_exists( string $table_name ): bool {
		return $this->db->table_exists( $table_name );
	}

	/**
	 * Build the shared database schema service.
	 *
	 * @return DatabaseSchema
	 * @since 1.0.3
	 */
	private function get_database_schema_service(): DatabaseSchema {
		return new DatabaseSchema( $this->connection );
	}

	/**
	 * Check whether all install seed config values are present and non-empty.
	 *
	 * @return bool True when every required seed value is set.
	 * @since 1.0.0
	 */
	private function has_install_seed_values(): bool {
		return '' !== trim( (string) ( $this->config['PEAKURL_OWNER_USERNAME'] ?? '' ) ) &&
			'' !== trim( (string) ( $this->config['PEAKURL_OWNER_EMAIL'] ?? '' ) ) &&
			'' !== trim( (string) ( $this->config['PEAKURL_OWNER_PASSWORD'] ?? '' ) ) &&
			'' !== trim( (string) ( $this->config['PEAKURL_WORKSPACE_NAME'] ?? '' ) ) &&
			'' !== trim( (string) ( $this->config['PEAKURL_WORKSPACE_SLUG'] ?? '' ) ) &&
			'' !== trim( (string) ( $this->config['SITE_URL'] ?? '' ) );
	}

	/**
	 * Get the current UTC timestamp in MySQL datetime format.
	 *
	 * @return string `Y-m-d H:i:s` formatted UTC time.
	 * @since 1.0.0
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Calculate the earliest valid session creation timestamp.
	 *
	 * @return string MySQL datetime marking the session TTL boundary.
	 * @since 1.0.0
	 */
	private function session_active_since(): string {
		return gmdate(
			'Y-m-d H:i:s',
			time() - max(
				0,
				(int) ( $this->config[ Constants::CONFIG_SESSION_LIFETIME ] ?? Constants::DEFAULT_SESSION_LIFETIME ),
			),
		);
	}

	/**
	 * Convert a MySQL datetime string to ISO 8601 (ATOM) format.
	 *
	 * @param string $value MySQL datetime value.
	 * @return string ISO 8601 formatted string.
	 * @since 1.0.0
	 */
	private function to_iso( string $value ): string {
		$timestamp = strtotime( $value . ' UTC' );

		if ( false === $timestamp ) {
			return gmdate( DATE_ATOM );
		}

		return gmdate( DATE_ATOM, $timestamp );
	}

	/**
	 * Encode a value as a JSON string with unescaped slashes.
	 *
	 * @param mixed $value Value to encode.
	 * @return string JSON string.
	 * @since 1.0.0
	 */
	private function encode_json( $value ): string {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES );

		return false === $encoded ? '[]' : $encoded;
	}

	/**
	 * Decode a JSON string into an associative array.
	 *
	 * @param string $value JSON string.
	 * @return array<string, mixed> Decoded array.
	 * @since 1.0.0
	 */
	private function decode_json( string $value ): array {
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Decode a JSON string into a numerically-indexed array.
	 *
	 * @param string $value JSON string.
	 * @return array<int, mixed> Decoded values array.
	 * @since 1.0.0
	 */
	private function decode_json_array( string $value ): array {
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	/**
	 * Generate a prefixed unique ID.
	 *
	 * @param string $prefix Entity type prefix.
	 * @return string Prefixed random hex ID.
	 * @since 1.0.0
	 */
	private function generate_id( string $prefix ): string {
		return $prefix . '_' . $this->generate_random_id();
	}

	/**
	 * Generate a random hex string.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string Hex-encoded random string.
	 * @since 1.0.0
	 */
	private function generate_random_id( int $bytes = 10 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Retrieve the last auto-increment ID from the database connection.
	 *
	 * @return string Last inserted ID.
	 * @since 1.0.0
	 */
	private function last_insert_id(): string {
		return $this->db->insert_id();
	}

	/**
	 * Execute a write SQL statement.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Bound parameters.
	 * @since 1.0.0
	 */
	private function execute( string $sql, array $params = array() ): void {
		$this->db->query( $sql, $params );
	}

	/**
	 * Execute a write statement and return the affected row count.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Bound parameters.
	 * @return int Number of affected rows.
	 * @since 1.0.0
	 */
	private function execute_statement( string $sql, array $params = array() ): int {
		return $this->db->query( $sql, $params );
	}

	/**
	 * Fetch a single row as an associative array.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Bound parameters.
	 * @return array<string, mixed>|null Row or null when not found.
	 * @since 1.0.0
	 */
	private function query_one( string $sql, array $params = array() ): ?array {
		return $this->db->get_row( $sql, $params );
	}

	/**
	 * Fetch all matching rows as associative arrays.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Bound parameters.
	 * @return array<int, array<string, mixed>> Result rows.
	 * @since 1.0.0
	 */
	private function query_all( string $sql, array $params = array() ): array {
		return $this->db->get_results( $sql, $params );
	}

	/**
	 * Fetch a single scalar value from the first row.
	 *
	 * @param string               $sql    SQL query.
	 * @param array<string, mixed> $params Bound parameters.
	 * @return mixed Scalar value or false when no rows.
	 * @since 1.0.0
	 */
	private function query_value( string $sql, array $params = array() ) {
		return $this->db->get_var( $sql, $params );
	}

	/**
	 * Run a regex replacement that returns an empty string on failure.
	 *
	 * @param string $pattern     PCRE pattern.
	 * @param string $replacement Replacement string.
	 * @param string $subject     Input string.
	 * @return string Result or empty string on error.
	 * @since 1.0.0
	 */
	private function replace_or_empty(
		string $pattern,
		string $replacement,
		string $subject
	): string {
		$result = preg_replace( $pattern, $replacement, $subject );

		return is_string( $result ) ? $result : '';
	}
}
