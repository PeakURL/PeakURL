<?php
/**
 * Configuration registry.
 *
 * Provides typed access to the application's runtime configuration.
 *
 * @package PeakURL\Core\Config
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Config;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Immutable configuration holder.
 *
 * @since 1.0.0
 */
class Config {

	/**
	 * Merged runtime configuration key-value map.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Create a new Config instance.
	 *
	 * @param array<string, mixed> $values Configuration map.
	 */
	public function __construct( array $values = array() ) {
		$this->values = $values;
	}

	/**
	 * Retrieve a configuration value by key with optional fallback.
	 *
	 * @param string $key           Configuration key name.
	 * @param mixed  $default_value Fallback value when key is absent.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		return $this->values[ $key ] ?? $default_value;
	}

	/**
	 * Return the entire configuration map.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * Return whether a configuration key exists and is non-empty.
	 *
	 * @param string $key Configuration key name.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return ! empty( $this->values[ $key ] );
	}

	/**
	 * Return whether debug mode is enabled.
	 *
	 * @return bool
	 */
	public function is_debug(): bool {
		return ! empty( $this->values[ Constants::DEBUG ] );
	}

	/**
	 * Return the configured site URL.
	 *
	 * @return string
	 */
	public function get_site_url(): string {
		return (string) ( $this->values[ Constants::SITE_URL ] ?? '' );
	}
}
