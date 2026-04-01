<?php
/**
 * Settings data API.
 *
 * Provides a small options-style API for the settings table so callers do
 * not need to duplicate CRUD logic for installation state, updater cache,
 * GeoIP metadata, and other site-level values.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Api;

use PeakURL\Includes\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SettingsApi — options-style helper for PeakURL settings rows.
 *
 * @since 1.0.0
 */
class SettingsApi {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Create a new settings API.
	 *
	 * @param PeakURL_DB $db Shared database wrapper.
	 * @since 1.0.0
	 */
	public function __construct( PeakURL_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Fetch a setting value by key.
	 *
	 * @param string $setting_key Setting key.
	 * @return string|null Stored value or null.
	 * @since 1.0.0
	 */
	public function get_option( string $setting_key ): ?string {
		if ( ! $this->has_table() ) {
			return null;
		}

		$value = $this->db->get_var_by(
			'settings',
			'setting_value',
			array( 'setting_key' => $setting_key ),
		);

		if ( false === $value || null === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Fetch multiple setting values by key.
	 *
	 * @param array<int, string> $setting_keys Setting keys.
	 * @return array<string, string> Stored key-value pairs.
	 * @since 1.0.0
	 */
	public function get_options( array $setting_keys ): array {
		$setting_keys = array_values(
			array_filter(
				array_map(
					static fn( $value ): string => is_string( $value ) ? trim( $value ) : '',
					$setting_keys,
				),
				static fn( string $value ): bool => '' !== $value,
			),
		);

		if ( empty( $setting_keys ) || ! $this->has_table() ) {
			return array();
		}

		$rows   = $this->db->get_results_where_in(
			'settings',
			'setting_key',
			$setting_keys,
			array( 'setting_key', 'setting_value' ),
		);
		$result = array();

		foreach ( $rows as $row ) {
			if (
				! is_array( $row ) ||
				! isset( $row['setting_key'] ) ||
				! array_key_exists( 'setting_value', $row )
			) {
				continue;
			}

			$result[ (string) $row['setting_key'] ] = (string) $row['setting_value'];
		}

		return $result;
	}

	/**
	 * Determine whether a setting has a non-empty value.
	 *
	 * @param string $setting_key Setting key.
	 * @return bool True when the setting exists and is not blank.
	 * @since 1.0.0
	 */
	public function has_option( string $setting_key ): bool {
		$value = $this->get_option( $setting_key );

		return is_string( $value ) ? '' !== trim( $value ) : ! empty( $value );
	}

	/**
	 * Insert or update a setting row.
	 *
	 * @param string $setting_key   Setting key.
	 * @param string $setting_value Setting value.
	 * @param string $updated_at    UTC timestamp.
	 * @param bool   $autoload      Whether the row should autoload.
	 * @return void
	 * @since 1.0.0
	 */
	public function update_option(
		string $setting_key,
		string $setting_value,
		string $updated_at,
		bool $autoload = true
	): void {
		if ( ! $this->has_table() ) {
			return;
		}

		$this->db->upsert(
			'settings',
			array(
				'setting_key'   => $setting_key,
				'setting_value' => $setting_value,
				'autoload'      => $autoload ? 1 : 0,
				'updated_at'    => $updated_at,
			),
			array(
				'setting_value',
				'autoload',
				'updated_at',
			),
		);
	}

	/**
	 * Delete multiple settings by key.
	 *
	 * @param array<int, string> $setting_keys Keys to delete.
	 * @return void
	 * @since 1.0.0
	 */
	public function delete_options( array $setting_keys ): void {
		$setting_keys = array_values(
			array_filter(
				array_map(
					static fn( $value ): string => is_string( $value ) ? trim( $value ) : '',
					$setting_keys,
				),
				static fn( string $value ): bool => '' !== $value,
			),
		);

		if ( empty( $setting_keys ) || ! $this->has_table() ) {
			return;
		}

		$this->db->delete_where_in(
			'settings',
			'setting_key',
			$setting_keys,
		);
	}

	/**
	 * Determine whether the settings table is available.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function has_table(): bool {
		return $this->db->table_exists( 'settings' );
	}
}
