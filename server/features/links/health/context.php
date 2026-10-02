<?php
/**
 * Shared context for Link Health services.
 *
 * Provides runtime version information and configuration for the link health domain.
 *
 * @package PeakURL\Features\Links\Health
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Features\Links\Health;

use PeakURL\Core\Config\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Context — shared state and version provider for Link Health services.
 *
 * @since 1.7.1
 */
class Context {

	/**
	 * Merged runtime configuration.
	 *
	 * @var array<string, mixed>
	 * @since 1.7.1
	 */
	private array $config;

	/**
	 * Effective PeakURL version string.
	 *
	 * @var string
	 * @since 1.7.1
	 */
	private string $version;

	/**
	 * Create a new Link Health context.
	 *
	 * @param array<string, mixed> $config Merged runtime configuration.
	 * @since 1.7.1
	 */
	public function __construct( array $config ) {
		$this->config = $config;

		$version = trim(
			(string) ( $config[ Constants::VERSION ] ?? '' )
		);

		$this->version = '' !== $version ? $version : Constants::DEFAULT_VERSION;
	}

	/**
	 * Return the runtime configuration map.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.1
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Return the current PeakURL version.
	 *
	 * @return string Current PeakURL version string.
	 * @since 1.7.1
	 */
	public function get_version(): string {
		return $this->version;
	}
}
