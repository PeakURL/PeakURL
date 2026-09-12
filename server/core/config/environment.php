<?php
/**
 * Canonical runtime environment and layout abstraction.
 *
 * Centralizes filesystem and runtime layout resolution between
 * development source trees (PEAKURL_DEV=true) and flattened
 * production release packages (PEAKURL_DEV=false or default).
 *
 * @package PeakURL\Core\Config
 * @since 1.6.3
 */

declare(strict_types=1);

namespace PeakURL\Core\Config;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Environment — single source of truth for runtime layout paths and mode.
 *
 * @since 1.6.3
 */
class Environment {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Absolute path to the release or repository root.
	 *
	 * @var string
	 */
	private string $source_root;

	/**
	 * Whether development source-tree mode is enabled.
	 *
	 * @var bool
	 */
	private bool $is_development;

	/**
	 * Create a new environment helper.
	 *
	 * @param string $source_root Absolute path to the release or repository root.
	 * @param bool   $is_dev      Whether development layout is active.
	 *
	 * @throws \RuntimeException When PEAKURL_DEV=true but server/ is missing.
	 * @since 1.6.3
	 */
	public function __construct( string $source_root, bool $is_dev = false ) {
		$this->source_root    = rtrim( $source_root, '/\\' );
		$this->is_development = $is_dev;

		if ( $this->is_development ) {
			$dev_runtime_dir = $this->source_root . '/server';

			if ( ! is_dir( $dev_runtime_dir ) ) {
				throw new \RuntimeException(
					sprintf(
						'PEAKURL_DEV is set to true, but the development directory "server/" was not found at %s.',
						$this->source_root,
					),
				);
			}
		}
	}

	/**
	 * Initialize and set the global singleton instance.
	 *
	 * @param string $source_root Absolute path to root.
	 * @param bool   $is_dev      Whether development mode is enabled.
	 * @return self
	 * @since 1.6.3
	 */
	public static function initialize( string $source_root, bool $is_dev = false ): self {
		self::$instance = new self( $source_root, $is_dev );
		return self::$instance;
	}

	/**
	 * Initialize or return the global instance using environment variables.
	 *
	 * @param string|null $source_root Optional root directory override.
	 * @return self
	 * @since 1.6.3
	 */
	public static function initialize_from_env( ?string $source_root = null ): self {
		if ( null !== self::$instance && null === $source_root ) {
			return self::$instance;
		}

		$root = null !== $source_root && '' !== trim( $source_root )
			? $source_root
			: ( defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/\\' ) : dirname( __DIR__, 3 ) );

		$raw_dev = getenv( 'PEAKURL_DEV' );

		if ( false === $raw_dev || '' === $raw_dev ) {
			$raw_dev = $_ENV['PEAKURL_DEV'] ?? ( $_SERVER['PEAKURL_DEV'] ?? null );
		}

		$is_dev = self::parse_dev_flag( $raw_dev );

		return self::initialize( $root, $is_dev );
	}

	/**
	 * Return the active global singleton instance.
	 *
	 * @return self
	 * @since 1.6.3
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = self::initialize_from_env();
		}

		return self::$instance;
	}

	/**
	 * Set or clear the active instance (used in tests).
	 *
	 * @param self|null $instance Mock or explicit instance.
	 * @return void
	 * @since 1.6.3
	 */
	public static function set_instance( ?self $instance ): void {
		self::$instance = $instance;
	}

	/**
	 * Parse an environment value into an explicit boolean flag.
	 *
	 * Defaults to false for production safety.
	 *
	 * @param mixed $value Raw environment value.
	 * @return bool
	 * @since 1.6.3
	 */
	public static function parse_dev_flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return false;
		}

		$filtered = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return true === $filtered;
	}

	/**
	 * Determine whether development source-tree mode is active.
	 *
	 * @return bool
	 * @since 1.6.3
	 */
	public function is_development(): bool {
		return $this->is_development;
	}

	/**
	 * Determine whether production mode is active.
	 *
	 * @return bool
	 * @since 1.6.3
	 */
	public function is_production(): bool {
		return ! $this->is_development;
	}

	/**
	 * Return the absolute path to the repository or release root.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_source_root(): string {
		return $this->source_root;
	}

	/**
	 * Return the absolute path to the PHP runtime directory.
	 *
	 * In development mode, returns `<root>/server`.
	 * In production mode, returns `<root>`.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_runtime_root(): string {
		return $this->is_development
			? $this->source_root . '/server'
			: $this->source_root;
	}

	/**
	 * Return the absolute path to the vendor directory.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_vendor_path(): string {
		return $this->get_runtime_root() . '/vendor';
	}

	/**
	 * Return the absolute path to the Composer autoloader.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_vendor_autoload_path(): string {
		return $this->get_vendor_path() . '/autoload.php';
	}

	/**
	 * Load the Composer autoloader.
	 *
	 * @return void
	 * @since 1.6.3
	 */
	public function load_autoloader(): void {
		$autoload_path = $this->get_vendor_autoload_path();

		if ( ! file_exists( $autoload_path ) ) {
			http_response_code( 500 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo json_encode(
				array(
					'success' => false,
					'message' => 'Composer autoloader not found at ' . $autoload_path . '. Run `composer install` inside the runtime directory.',
				),
				JSON_PRETTY_PRINT,
			);
			exit( 1 );
		}

		require_once $autoload_path;
	}

	/**
	 * Bootstrap the Composer autoloader.
	 *
	 * Alias for {@see self::load_autoloader()}.
	 *
	 * @return void
	 * @since 1.6.3
	 */
	public function bootstrap_autoloader(): void {
		$this->load_autoloader();
	}

	/**
	 * Return the absolute path to the authoritative API entrypoint.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_api_entrypoint(): string {
		return $this->is_development
			? $this->source_root . '/server/public/index.php'
			: $this->source_root . '/api/index.php';
	}

	/**
	 * Return the absolute path to the React SPA HTML document.
	 *
	 * In development mode, returns `<root>/index.html`.
	 * In production mode, returns `<root>/app.html`.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_app_html_path(): string {
		return $this->is_development
			? $this->source_root . '/index.html'
			: $this->source_root . '/app.html';
	}

	/**
	 * Return the absolute path to the generated or bundled assets directory.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_assets_path(): string {
		return $this->source_root . '/assets';
	}

	/**
	 * Return the absolute path to the persistent content directory.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_content_path(): string {
		return $this->source_root . '/content';
	}

	/**
	 * Return the absolute path to the schema.sql database definition.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_database_schema_path(): string {
		return $this->is_development
			? $this->source_root . '/server/database/schema.sql'
			: $this->source_root . '/database/schema.sql';
	}

	/**
	 * Return the absolute path to the CLI scripts directory.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_bin_path(): string {
		return $this->is_development
			? $this->source_root . '/server/bin'
			: $this->source_root . '/bin';
	}

	/**
	 * Return the absolute path to the bundled fallback favicon asset.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_default_favicon_path(): string {
		return $this->is_development
			? $this->source_root . '/server/public/default-favicon.png'
			: $this->source_root . '/assets/default-favicon.png';
	}

	/**
	 * Return the absolute path to the bundled fallback site webmanifest.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_default_manifest_path(): string {
		return $this->is_development
			? $this->source_root . '/server/public/default-site.webmanifest'
			: $this->source_root . '/assets/default-site.webmanifest';
	}

	/**
	 * Return the canonical CLI command string for refreshing GeoLite2 database.
	 *
	 * @return string
	 * @since 1.6.3
	 */
	public function get_geoip_command(): string {
		return $this->is_development
			? 'php server/bin/update-geoip.php'
			: 'php bin/update-geoip.php';
	}
}
