<?php
/**
 * System domain service.
 *
 * Coordinates admin notices, system health diagnostics, and runtime updates.
 *
 * @package PeakURL\Features\System
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\System;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Scheduler\Scheduler;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\AdminNotices;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as SchemaService;
use PeakURL\Services\Geoip;
use PeakURL\Services\I18n;
use PeakURL\Services\Mailer;
use PeakURL\Services\SystemStatus\Manager as SystemStatusManager;
use PeakURL\Services\Update\Manager as UpdateManager;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — System diagnostics, notices, and updater engine.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Database connection instance.
	 *
	 * @var Connection
	 * @since 1.0.0
	 */
	private Connection $connection;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.0.0
	 */
	private AuthService $auth_service;

	/**
	 * Settings API.
	 *
	 * @var SettingsApi
	 * @since 1.0.0
	 */
	private SettingsApi $settings_api;

	/**
	 * GeoIP service.
	 *
	 * @var Geoip
	 * @since 1.0.0
	 */
	private Geoip $geoip_service;

	/**
	 * Mailer service.
	 *
	 * @var Mailer
	 * @since 1.0.0
	 */
	private Mailer $mailer_service;

	/**
	 * Database schema service.
	 *
	 * @var SchemaService
	 * @since 1.0.0
	 */
	private SchemaService $schema_service;

	/**
	 * I18n helper.
	 *
	 * @var I18n
	 * @since 1.0.0
	 */
	private I18n $i18n_service;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	/**
	 * Runtime config map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Background job scheduler instance.
	 *
	 * @var Scheduler|null
	 * @since 1.7.0
	 */
	private ?Scheduler $scheduler;

	/**
	 * Create a new System Service instance.
	 *
	 * @param PeakURL_DB           $db             Shared database wrapper.
	 * @param Connection           $connection     Database connection instance.
	 * @param AuthService          $auth_service   Authentication domain service.
	 * @param SettingsApi          $settings_api   Settings API.
	 * @param Geoip                $geoip_service  GeoIP service.
	 * @param Mailer               $mailer_service Mailer service.
	 * @param SchemaService        $schema_service Database schema service.
	 * @param I18n                 $i18n_service   I18n helper.
	 * @param Roles                $roles          Roles registry.
	 * @param Authorization        $authorization  Authorization helper.
	 * @param array<string, mixed> $config         Runtime config map.
	 * @param Scheduler|null       $scheduler      Optional scheduler instance.
	 * @since 1.0.0
	 */
	public function __construct(
		PeakURL_DB $db,
		Connection $connection,
		AuthService $auth_service,
		SettingsApi $settings_api,
		Geoip $geoip_service,
		Mailer $mailer_service,
		SchemaService $schema_service,
		I18n $i18n_service,
		Roles $roles,
		Authorization $authorization,
		array $config,
		?Scheduler $scheduler = null
	) {
		$this->db             = $db;
		$this->connection     = $connection;
		$this->auth_service   = $auth_service;
		$this->settings_api   = $settings_api;
		$this->geoip_service  = $geoip_service;
		$this->mailer_service = $mailer_service;
		$this->schema_service = $schema_service;
		$this->i18n_service   = $i18n_service;
		$this->roles          = $roles;
		$this->authorization  = $authorization;
		$this->config         = $config;
		$this->scheduler      = $scheduler;
	}

	/**
	 * Return the current update-management user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_update_user( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

		return $user;
	}

	/**
	 * Return the current dashboard admin notices.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<int, array<string, mixed>> Admin notices list.
	 * @since 1.0.0
	 */
	public function get_admin_notices( Request $request ): array {
		$user    = $this->auth_service->get_current_user( $request );
		$service = new AdminNotices();

		return $service->get_notices(
			$this->get_admin_notice_context( $user ),
		);
	}

	/**
	 * Return the dashboard system-status payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> System status diagnostics.
	 * @since 1.0.0
	 */
	public function get_system_status( Request $request ): array {
		$this->get_update_user( $request );

		$service = new SystemStatusManager(
			$this->config,
			$this->db,
			$this->settings_api,
			$this->geoip_service,
			$this->mailer_service,
			$this->schema_service,
			$this->i18n_service,
		);

		return $service->get_status();
	}

	/**
	 * Get the shared dashboard notice context for the current user.
	 *
	 * @param array<string, mixed> $user Current authenticated user row.
	 * @return array<string, mixed> Notice context.
	 * @since 1.0.0
	 */
	public function get_admin_notice_context( array $user ): array {
		$capabilities = array(
			'manageUpdates'      => $this->roles->has_capability( $user, 'manage_updates' ),
			'manageLocationData' => $this->roles->has_capability( $user, 'manage_location_data' ),
		);
		$context      = array(
			'user'         => $user,
			'capabilities' => $capabilities,
		);

		if ( ! empty( $capabilities['manageUpdates'] ) ) {
			$context['updateStatus'] = $this->load_update_status( false );
		}

		if ( ! empty( $capabilities['manageLocationData'] ) ) {
			$context['geoipStatus'] = $this->geoip_service->get_status();
		}

		return $context;
	}

	/**
	 * Return the cached update status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Current/latest version and availability flag.
	 * @since 1.0.0
	 */
	public function get_update_status( Request $request ): array {
		$this->get_update_user( $request );

		return $this->load_update_status( false );
	}

	/**
	 * Refresh the remote update manifest status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Updated status after the remote refresh.
	 * @since 1.0.0
	 */
	public function refresh_update_status( Request $request ): array {
		$this->get_update_user( $request );

		return $this->load_update_status( true );
	}

	/**
	 * Download and apply the latest release archive.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Result of the update operation.
	 *
	 * @throws ApiException When run from a source checkout or no update is available.
	 * @since 1.0.0
	 */
	public function apply_update( Request $request ): array {
		$this->get_update_user( $request );

		$status = $this->load_update_status( true );

		if ( empty( $status['updateAvailable'] ) ) {
			throw new ApiException( __( 'PeakURL is already up to date.', 'peakurl' ), 422 );
		}

		return $this->install_release( $status );
	}

	/**
	 * Reinstall the currently installed release package.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Result of the reinstall operation.
	 *
	 * @throws ApiException When the current release cannot be reinstalled.
	 * @since 1.0.0
	 */
	public function reinstall_update( Request $request ): array {
		$this->get_update_user( $request );

		$status = $this->load_update_status( true );

		if ( ! empty( $status['updateAvailable'] ) ) {
			throw new ApiException(
				__( 'A newer PeakURL release is available. Install the update instead.', 'peakurl' ),
				422,
			);
		}

		if ( empty( $status['reinstallAvailable'] ) ) {
			throw new ApiException(
				__( 'PeakURL cannot reinstall the latest release right now.', 'peakurl' ),
				422,
			);
		}

		return $this->install_release( $status, true );
	}

	/**
	 * Run the managed database upgrade / repair flow on demand.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Database upgrade status.
	 *
	 * @throws ApiException When the upgrade fails.
	 * @since 1.0.0
	 */
	public function upgrade_database_schema( Request $request ): array {
		$this->get_update_user( $request );

		try {
			$service = $this->schema_service;
			$status  = $service->inspect();

			if ( empty( $status['upgradeRequired'] ) ) {
				return $status;
			}

			return $service->upgrade();
		} catch ( \Throwable $exception ) {
			throw new ApiException( $exception->getMessage(), 500 );
		}
	}

	/**
	 * Load (and optionally refresh) the cached update status.
	 *
	 * @param bool $force_check Whether to bypass the cache TTL and fetch fresh.
	 * @return array<string, mixed> Compiled update status payload.
	 * @since 1.0.0
	 */
	private function load_update_status( bool $force_check ): array {
		$settings_api    = $this->settings_api;
		$update_service  = new UpdateManager( $this->config );
		$manifest_url    = $update_service->get_manifest_url();
		$last_checked    = $settings_api->get_option( 'update_last_checked_at' );
		$last_error      = $settings_api->get_option( 'update_last_error' );
		$cached_manifest = $this->decode_update_manifest(
			$settings_api->get_option( 'update_last_result_json' ),
		);

		$settings_api->update_option( 'update_manifest_url', $manifest_url, Date::now(), false );

		if (
			$force_check ||
			empty( $cached_manifest ) ||
			! $update_service->is_cache_fresh( $last_checked )
		) {
			try {
				$cached_manifest = $update_service->fetch_manifest();
				$last_checked    = Date::now();
				$last_error      = null;
				$settings_api->update_option(
					'update_last_result_json',
					peakurl_json_encode( $cached_manifest ),
					Date::now(),
					false,
				);
				$settings_api->update_option(
					'update_last_checked_at',
					$last_checked,
					Date::now(),
					false,
				);
				$settings_api->delete_options( array( 'update_last_error' ) );
			} catch ( \Throwable $exception ) {
				$last_checked = Date::now();
				$last_error   = $exception->getMessage();
				$settings_api->update_option(
					'update_last_checked_at',
					$last_checked,
					Date::now(),
					false,
				);
				$settings_api->update_option(
					'update_last_error',
					$last_error,
					Date::now(),
					false,
				);
			}
		}

		$status = $update_service->get_status(
			$cached_manifest,
			$last_checked,
			$last_error,
		);

		try {
			$status['database'] = $this->schema_service->inspect();
		} catch ( \Throwable $exception ) {
			$status['database'] = array(
				'currentVersion'  => 0,
				'targetVersion'   => Constants::DB_SCHEMA_VERSION,
				'compatible'      => false,
				'upgradeRequired' => true,
				'upToDate'        => false,
				'errorCount'      => 1,
				'warningCount'    => 0,
				'issues'          => array(
					array(
						'id'       => 'schema-inspection-failed',
						'severity' => 'error',
						'label'    => __( 'PeakURL could not inspect the database schema.', 'peakurl' ),
					),
				),
				'issuesCount'     => 1,
				'missingTables'   => array(),
				'lastUpgradedAt'  => null,
				'lastError'       => $exception->getMessage(),
				'upgraded'        => false,
				'changes'         => array(),
			);
		}

		return $status;
	}

	/**
	 * Apply a release package from the resolved update status payload.
	 *
	 * @param array<string, mixed> $status Resolved update status.
	 * @param bool                 $reinstall Whether the action is a reinstall.
	 * @return array<string, mixed> Result of the install operation.
	 *
	 * @throws ApiException On install error.
	 * @since 1.0.0
	 */
	private function install_release( array $status, bool $reinstall = false ): array {
		if ( empty( $status['canApply'] ) ) {
			throw new ApiException(
				(string) ( $status['applyDisabledReason'] ?? __( 'PeakURL cannot apply this release.', 'peakurl' ) ),
				422,
			);
		}

		$manifest = is_array( $status['manifest'] ?? null )
			? $status['manifest']
			: array();

		if ( empty( $manifest ) ) {
			throw new ApiException(
				__( 'PeakURL could not load the update manifest.', 'peakurl' ),
				502,
			);
		}

		$update_service = new UpdateManager( $this->config );
		$settings_api   = $this->settings_api;

		try {
			$result = $update_service->apply_update( $manifest );
		} catch ( \Throwable $exception ) {
			$settings_api->update_option( 'update_last_checked_at', Date::now(), Date::now(), false );
			$settings_api->update_option(
				'update_last_error',
				$exception->getMessage(),
				Date::now(),
				false,
			);

			throw new ApiException( $exception->getMessage(), 500 );
		}

		$installed_version = (string) (
			$result['version']
			?? $this->config[ Constants::VERSION ]
			?? Constants::DEFAULT_VERSION
		);

		$settings_api->update_option(
			'installed_version',
			$installed_version,
			Date::now(),
			false,
		);
		$settings_api->update_option( 'update_last_applied_at', Date::now(), Date::now(), false );
		$settings_api->update_option( 'update_last_checked_at', Date::now(), Date::now(), false );
		$settings_api->update_option(
			'update_last_result_json',
			peakurl_json_encode( $manifest ),
			Date::now(),
			false,
		);
		$settings_api->delete_options( array( 'update_last_error' ) );

		return array(
			'applied'        => true,
			'reinstalled'    => $reinstall,
			'currentVersion' => $installed_version,
			'latestVersion'  => (string) ( $manifest['version'] ?? '' ),
			'packageUrl'     => (string) ( $result['packageUrl'] ?? '' ),
			'appliedAt'      => (string) ( $result['appliedAt'] ?? gmdate( DATE_ATOM ) ),
			'reloadRequired' => true,
		);
	}

	/**
	 * Decode a cached update manifest JSON string.
	 *
	 * @param string|null $value Raw JSON from the settings table.
	 * @return array<string, mixed>|null Decoded manifest or null.
	 * @since 1.0.0
	 */
	private function decode_update_manifest( ?string $value ): ?array {
		if ( empty( $value ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Return the current background jobs scheduler status and registered jobs.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Cron status payload.
	 *
	 * @throws ApiException When scheduler is not configured.
	 * @since 1.7.0
	 */
	public function get_cron_status( Request $request ): array {
		$this->get_update_user( $request );

		if ( null === $this->scheduler ) {
			throw new ApiException(
				__( 'Scheduler service is not configured.', 'peakurl' ),
				500
			);
		}

		return $this->scheduler->get_status();
	}

	/**
	 * Trigger manual run-now execution of a registered background job.
	 *
	 * @param Request     $request Incoming HTTP request (admin-only).
	 * @param string|null $job_id  Optional job identifier from route parameter.
	 * @return array<string, mixed> Run result outcome.
	 *
	 * @throws ApiException When the job ID is missing, unknown, or execution fails.
	 * @since 1.7.0
	 */
	public function run_cron_job( Request $request, ?string $job_id = null ): array {
		$this->get_update_user( $request );

		if ( null === $this->scheduler ) {
			throw new ApiException(
				__( 'Scheduler service is not configured.', 'peakurl' ),
				500
			);
		}

		$target_id = $job_id;
		if ( null === $target_id || '' === trim( $target_id ) ) {
			$target_id = $request->get_route_param( 'id' );
		}

		if ( null === $target_id || '' === trim( (string) $target_id ) ) {
			$payload   = $request->json_data();
			$target_id = (string) ( $payload['job_id'] ?? $payload['id'] ?? '' );
		}

		$clean_id = trim( (string) $target_id );

		// If no specific job ID was requested, run all due background jobs.
		if ( '' === $clean_id ) {
			try {
				$results = $this->scheduler->run_due_jobs();

				return array(
					'run_all' => true,
					'results' => $results,
					'success' => true,
				);
			} catch ( \Throwable $exception ) {
				throw new ApiException( $exception->getMessage(), 500 );
			}
		}

		if ( ! $this->scheduler->get_registry()->has( $clean_id ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %s is the requested job ID. */
					__( 'Unknown background job identifier: %s', 'peakurl' ),
					$clean_id
				),
				404
			);
		}

		try {
			$result = $this->scheduler->run_job( $clean_id, true );

			return array(
				'job_id'  => $clean_id,
				'status'  => $result->get_status(),
				'summary' => $result->get_summary(),
				'error'   => $result->get_error(),
				'success' => $result->is_success(),
			);
		} catch ( \Throwable $exception ) {
			throw new ApiException( $exception->getMessage(), 500 );
		}
	}
}
