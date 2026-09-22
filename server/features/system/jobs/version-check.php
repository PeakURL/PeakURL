<?php
/**
 * Version check background job.
 *
 * @package PeakURL\Features\System\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\System\Jobs;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Services\Update\Manager as UpdateManager;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * VersionCheckJob — checks for newer PeakURL releases and caches the status.
 *
 * Queries the remote update manifest and stores update status in the database.
 * Does NOT apply or automatically update files.
 *
 * @since 1.7.0
 */
class VersionCheckJob implements JobHandlerInterface {

	/**
	 * Settings API.
	 *
	 * @var SettingsApi
	 * @since 1.7.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Updater manager.
	 *
	 * @var UpdateManager
	 * @since 1.7.0
	 */
	private UpdateManager $update_manager;

	/**
	 * Create a new version check job.
	 *
	 * @param SettingsApi   $settings_api   Settings API instance.
	 * @param UpdateManager $update_manager Update manager instance.
	 * @since 1.7.0
	 */
	public function __construct( SettingsApi $settings_api, UpdateManager $update_manager ) {
		$this->settings_api   = $settings_api;
		$this->update_manager = $update_manager;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		$now = Date::now();

		try {
			$manifest = $this->update_manager->fetch_manifest();
			$version  = (string) ( $manifest['version'] ?? 'unknown' );

			$this->settings_api->update_option(
				'update_last_result_json',
				peakurl_json_encode( $manifest ),
				$now,
				false
			);
			$this->settings_api->update_option( 'update_last_checked_at', $now, $now, false );
			$this->settings_api->delete_options( array( 'update_last_error' ) );

			return ExecutionResult::success(
				sprintf(
					'Version check complete: remote release %s found.',
					$version
				),
				array( 'latestVersion' => $version )
			);
		} catch ( \Throwable $exception ) {
			$error = $exception->getMessage();
			$this->settings_api->update_option( 'update_last_checked_at', $now, $now, false );
			$this->settings_api->update_option( 'update_last_error', $error, $now, false );

			return ExecutionResult::failure(
				sprintf(
					'Version check failed: %s',
					$error
				)
			);
		}
	}
}
