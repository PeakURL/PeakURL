<?php
/**
 * Background runner and execution dispatcher.
 *
 * Coordinates immediate asynchronous execution, post-response background
 * task processing via fastcgi_finish_request, and opportunistic on-visit
 * scheduled job execution for hosting environments without system cron.
 *
 * @package PeakURL\Core\Scheduler
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Core\Scheduler;

use PeakURL\Api\SettingsApi;
use PeakURL\Features\Webhooks\Service as WebhooksService;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * BackgroundRunner — central orchestrator for non-blocking asynchronous work.
 *
 * Ensures event-driven operations (webhooks, targeted health checks) execute
 * promptly after the HTTP response has been flushed to the visitor, while also
 * processing scheduled background jobs when the site receives traffic if system
 * crontab is not configured.
 *
 * @since 1.7.1
 */
class BackgroundRunner {

	/**
	 * Minimum interval in seconds between opportunistic on-visit scheduled job checks.
	 */
	public const SCHEDULED_JOB_CHECK_INTERVAL = 60;

	/**
	 * Central background job scheduler.
	 *
	 * @var Scheduler|null
	 */
	private ?Scheduler $scheduler;

	/**
	 * Webhooks domain service.
	 *
	 * @var WebhooksService|null
	 */
	private ?WebhooksService $webhooks_service;

	/**
	 * Settings API instance.
	 *
	 * @var SettingsApi|null
	 */
	private ?SettingsApi $settings_api;

	/**
	 * Targeted job identifiers queued for immediate execution post-response.
	 *
	 * @var array<int, string>
	 */
	private array $targeted_jobs = array();

	/**
	 * Whether immediate webhook deliveries were queued in this request.
	 *
	 * @var bool
	 */
	private bool $has_pending_webhooks = false;

	/**
	 * Whether this runner has already executed in the current PHP request lifecycle.
	 *
	 * @var bool
	 */
	private bool $has_executed = false;

	/**
	 * Create a new background runner instance.
	 *
	 * @param Scheduler|null       $scheduler        Scheduler instance.
	 * @param WebhooksService|null $webhooks_service Webhooks service.
	 * @param SettingsApi|null     $settings_api     Settings API instance.
	 * @since 1.7.1
	 */
	public function __construct(
		?Scheduler $scheduler = null,
		?WebhooksService $webhooks_service = null,
		?SettingsApi $settings_api = null
	) {
		$this->scheduler        = $scheduler;
		$this->webhooks_service = $webhooks_service;
		$this->settings_api     = $settings_api;
	}

	/**
	 * Queue a targeted one-off job for immediate post-response execution.
	 *
	 * @param string $job_id Targeted job ID (e.g. peakurl_link_health_check:<link_id>).
	 * @return void
	 * @since 1.7.1
	 */
	public function enqueue_targeted_job( string $job_id ): void {
		$clean_id = trim( $job_id );
		if ( '' !== $clean_id && ! in_array( $clean_id, $this->targeted_jobs, true ) ) {
			$this->targeted_jobs[] = $clean_id;
		}
	}

	/**
	 * Flag that pending webhook deliveries need immediate post-response processing.
	 *
	 * @return void
	 * @since 1.7.1
	 */
	public function enqueue_webhooks(): void {
		$this->has_pending_webhooks = true;
	}

	/**
	 * Determine whether the current PHP runtime environment supports detached post-response execution.
	 *
	 * Returns true if running under PHP-FPM (fastcgi_finish_request) or LiteSpeed
	 * (litespeed_finish_request), allowing background tasks to execute without holding
	 * the HTTP client connection open.
	 *
	 * @return bool True if post-response execution is natively supported, false otherwise.
	 * @since 1.7.1
	 */
	public static function supports_post_response_execution(): bool {
		return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}

	/**
	 * Flush all response data to the HTTP client and terminate the client connection.
	 *
	 * Under PHP-FPM / FastCGI, calls fastcgi_finish_request() so the visitor's
	 * browser or client receives the full response immediately with zero delay.
	 * For other SAPIs, gracefully flushes output buffers with Connection: close.
	 *
	 * @return void
	 * @since 1.7.1
	 */
	public static function finish_request(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			return;
		}

		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
			return;
		}

		if ( 'cli' !== PHP_SAPI ) {
			if ( ! headers_sent() ) {
				header( 'Connection: close' );
			}
			ignore_user_abort( true );
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			flush();
		}
	}

	/**
	 * Dispatch background tasks post-response.
	 *
	 * Flushes response to the client immediately, then processes queued targeted
	 * jobs, pending webhook deliveries, and due scheduled jobs.
	 *
	 * @param bool $process_due_scheduled_jobs Whether to also check and execute due scheduled jobs.
	 * @param bool $force_scheduled_jobs       Whether to bypass scheduled job interval throttling (e.g. worker lock already acquired).
	 * @return void
	 * @since 1.7.1
	 */
	public function dispatch_post_response(
		bool $process_due_scheduled_jobs = true,
		bool $force_scheduled_jobs = false
	): void {
		if ( $this->has_executed ) {
			return;
		}
		$this->has_executed = true;

		// 1. Immediately flush response to client so user/visitor experiences zero delay.
		self::finish_request();

		// In non-CLI environments that cannot guarantee detached execution (e.g. Apache mod_php),
		// do not execute external webhooks or heavy maintenance jobs during this HTTP request
		// to avoid holding open the connection and blocking visitor latency.
		// All queued deliveries and jobs remain safely persisted in the database for crontab execution.
		if ( 'cli' !== PHP_SAPI && ! self::supports_post_response_execution() ) {
			return;
		}

		// 2. Protect background execution from premature web request timeouts.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		if ( function_exists( 'do_action' ) ) {
			\do_action( 'peakurl_background_tasks_start', $this );
		}

		// 3. Execute any targeted one-off jobs (e.g. immediate post-create link health check).
		if ( ! empty( $this->targeted_jobs ) && null !== $this->scheduler ) {
			$jobs_to_run         = $this->targeted_jobs;
			$this->targeted_jobs = array();

			foreach ( $jobs_to_run as $job_id ) {
				try {
					$this->scheduler->run_job( $job_id, true );
				} catch ( \Throwable $e ) {
					error_log( sprintf( 'PeakURL BackgroundRunner: targeted job [%s] failed: %s', $job_id, $e->getMessage() ) );
				}
			}
		}

		// 4. Deliver pending webhook deliveries (immediate event-driven dispatch or periodic maintenance).
		$should_process_webhooks    = $this->has_pending_webhooks;
		$this->has_pending_webhooks = false;

		if ( ! $should_process_webhooks && $process_due_scheduled_jobs ) {
			$should_process_webhooks = true;
		}

		if ( $should_process_webhooks && null !== $this->webhooks_service ) {
			try {
				$this->webhooks_service->process_pending_deliveries();
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'PeakURL BackgroundRunner: webhook processing failed: %s', $e->getMessage() ) );
			}
		}

		// 5. Opportunistic on-visit scheduled job check (for installs without system crontab).
		if ( $process_due_scheduled_jobs && null !== $this->scheduler ) {
			try {
				$this->process_due_scheduled_jobs( $force_scheduled_jobs );
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'PeakURL BackgroundRunner: scheduled job processing failed: %s', $e->getMessage() ) );
			}
		}

		if ( function_exists( 'do_action' ) ) {
			\do_action( 'peakurl_background_tasks_finished', $this );
		}
	}

	/**
	 * Run due scheduled background jobs if the check interval has elapsed.
	 *
	 * Uses atomic job-level locking to prevent duplicate concurrent runs.
	 *
	 * @param bool $force Bypass interval throttling.
	 * @return void
	 * @since 1.7.1
	 */
	public function process_due_scheduled_jobs( bool $force = false ): void {
		if ( null === $this->scheduler ) {
			return;
		}

		if ( ! $force && null !== $this->settings_api ) {
			if ( ! $this->settings_api->acquire_option_lock( 'cron_last_visit_check', self::SCHEDULED_JOB_CHECK_INTERVAL ) ) {
				return;
			}
		}

		$this->scheduler->run_due_jobs();
	}

	/**
	 * Get the Scheduler instance.
	 *
	 * @return Scheduler|null
	 * @since 1.7.1
	 */
	public function get_scheduler(): ?Scheduler {
		return $this->scheduler;
	}

	/**
	 * Get the WebhooksService instance.
	 *
	 * @return WebhooksService|null
	 * @since 1.7.1
	 */
	public function get_webhooks_service(): ?WebhooksService {
		return $this->webhooks_service;
	}
}
