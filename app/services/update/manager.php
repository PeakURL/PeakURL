<?php
/**
 * Dashboard updater service.
 *
 * @package PeakURL\Services\Update
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Manager — self-hosted dashboard updater facade.
 *
 * Keeps the public updater API stable while the heavy lifting lives in
 * focused helpers under `app/services/update/`.
 *
 * @since 1.0.14
 */
class Manager {

	/**
	 * Shared updater context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Shared updater manifest helper.
	 *
	 * @var Manifest
	 * @since 1.0.14
	 */
	private Manifest $manifest;

	/**
	 * Shared updater status helper.
	 *
	 * @var Status
	 * @since 1.0.14
	 */
	private Status $status;

	/**
	 * Shared updater workspace helper.
	 *
	 * @var Workspace
	 * @since 1.0.14
	 */
	private Workspace $workspace;

	/**
	 * Shared updater install helper.
	 *
	 * @var Installer
	 * @since 1.0.14
	 */
	private Installer $installer;

	/**
	 * Create a new dashboard updater service.
	 *
	 * @param array<string, mixed> $config Shared runtime configuration.
	 * @since 1.0.14
	 */
	public function __construct( array $config ) {
		$filesystem      = new Filesystem();
		$this->context   = new Context( $config, $filesystem );
		$client          = new Client( $this->context );
		$this->manifest  = new Manifest( $this->context, $client );
		$this->workspace = new Workspace( $this->context, $filesystem );
		$this->status    = new Status(
			$this->context,
			$this->manifest,
			$this->workspace,
		);
		$this->installer = new Installer(
			$this->context,
			$filesystem,
			$client,
			$this->workspace,
			new ReleaseFiles( $this->context, $filesystem ),
		);
	}

	/**
	 * Get the resolved update manifest URL from config.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_manifest_url(): string {
		return $this->manifest->get_manifest_url();
	}

	/**
	 * Get the currently installed PeakURL version string.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_current_version(): string {
		return $this->context->get_current_version();
	}

	/**
	 * Check whether the cached manifest is still fresh.
	 *
	 * @param string|null $last_checked_at ISO/MySQL datetime of last check.
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_cache_fresh( ?string $last_checked_at ): bool {
		return $this->manifest->is_cache_fresh( $last_checked_at );
	}

	/**
	 * Fetch and normalize the remote update manifest.
	 *
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	public function fetch_manifest(): array {
		return $this->manifest->fetch_manifest();
	}

	/**
	 * Build the full update status payload for the dashboard API.
	 *
	 * @param array<string, string>|null $manifest        Cached or fetched manifest.
	 * @param string|null                $last_checked_at When the manifest was last fetched.
	 * @param string|null                $last_error      Last update error message.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function build_status(
		?array $manifest,
		?string $last_checked_at,
		?string $last_error
	): array {
		return $this->status->build_status(
			$manifest,
			$last_checked_at,
			$last_error,
		);
	}

	/**
	 * Apply an update from the given manifest.
	 *
	 * @param array<string, string> $manifest Normalized update manifest.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	public function apply_update( array $manifest ): array {
		return $this->installer->apply( $manifest );
	}
}
