<?php
/**
 * In-memory SettingsApi test double for isolated unit and integration tests.
 *
 * @package PeakURL\Tests\Support
 */

declare(strict_types=1);

namespace PeakURL\Tests\Support;

use PeakURL\Api\SettingsApi;

class InMemorySettingsApi extends SettingsApi {

	/**
	 * Backed option key-value store.
	 *
	 * @var array<string, string>
	 */
	private array $store;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $store Reference to an external array store.
	 */
	public function __construct( array &$store ) {
		$this->store = &$store;
	}

	/**
	 * Read a stored option value.
	 *
	 * @param string $name Option key.
	 * @return string|null
	 */
	public function get_option( string $name ): ?string {
		return $this->store[ $name ] ?? null;
	}

	/**
	 * Read multiple stored option values.
	 *
	 * @param array<int, string> $setting_keys Setting keys.
	 * @return array<string, string> Stored key-value pairs.
	 */
	public function get_options( array $setting_keys ): array {
		$result = array();
		foreach ( $setting_keys as $key ) {
			if ( isset( $this->store[ $key ] ) ) {
				$result[ $key ] = $this->store[ $key ];
			}
		}
		return $result;
	}

	/**
	 * Write or update an option value.
	 *
	 * @param string $name       Option key.
	 * @param string $value      Option value.
	 * @param string $updated_at Optional timestamp.
	 * @param bool   $autoload   Autoload flag.
	 * @return void
	 */
	public function update_option( string $name, string $value, string $updated_at = '', bool $autoload = true ): void {
		$this->store[ $name ] = (string) $value;
	}

	/**
	 * Atomically read and update an option via callback.
	 *
	 * @param string   $name     Option key.
	 * @param callable $modifier Callback receiving current value and returning next value or null.
	 * @return string|null
	 */
	public function update_option_with_lock( string $name, callable $modifier ): ?string {
		$val     = $this->store[ $name ] ?? null;
		$new_val = $modifier( $val );
		if ( null === $new_val ) {
			unset( $this->store[ $name ] );
		} else {
			$this->store[ $name ] = (string) $new_val;
		}
		return $new_val;
	}
}
