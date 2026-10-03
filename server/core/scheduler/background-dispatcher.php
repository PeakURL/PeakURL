<?php
/**
 * Background task dispatcher.
 *
 * Coordinates non-blocking post-response background task execution and
 * opportunistic on-visit scheduler runs for hosting environments without system cron.
 *
 * @package PeakURL\Core\Scheduler
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Core\Scheduler;

use PeakURL\Api\SettingsApi;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * BackgroundDispatcher — modular orchestrator for on-visit background execution.
 *
 * Evaluates whether scheduled maintenance or queued deliveries require execution
 * using lightweight queries before instantiating the full application service graph.
 * Flushes client HTTP responses immediately to preserve sub-10ms user latency.
 *
 * @since 1.7.1
 */
class BackgroundDispatcher {

	/**
	 * Process-level flag preventing redundant executions in the same request.
	 *
	 * @var bool
	 */
	private static bool $has_dispatched = false;

	/**
	 * Process-level flag tracking hook registration status.
	 *
	 * @var bool
	 */
	private static bool $hook_registered = false;

	/**
	 * Attempt to atomically acquire an on-visit worker lock.
	 *
	 * Uses an atomic UPDATE with a timestamp threshold to admit exactly one worker
	 * per lock interval across concurrent requests.
	 *
	 * @param PeakURL_DB        $db           Database query wrapper.
	 * @param int               $interval     Lock interval in seconds.
	 * @param SettingsApi|null  $settings_api Optional Settings API instance.
	 * @return bool True if the lock was acquired, false if held by another worker.
	 * @since 1.7.1
	 */
	public static function acquire_visit_worker_lock(
		PeakURL_DB $db,
		int $interval = BackgroundRunner::SCHEDULED_JOB_CHECK_INTERVAL,
		?SettingsApi $settings_api = null
	): bool {
		$settings_api = $settings_api ?? new SettingsApi( $db );
		return $settings_api->acquire_option_lock( 'cron_last_visit_check', $interval );
	}

	/**
	 * Check whether due background tasks exist and worker lock can be acquired.
	 *
	 * Performs a lightweight indexed check against cron_jobs and webhook_deliveries.
	 * If work is due, atomically acquires the visit worker lock to ensure exactly
	 * one background worker is started for the execution window.
	 *
	 * @param PeakURL_DB   $db           Database query wrapper.
	 * @param SettingsApi  $settings_api Settings API instance.
	 * @param int          $interval     Throttling interval in seconds.
	 * @return bool True if work is due and worker lock was acquired, false otherwise.
	 * @since 1.7.1
	 */
	public static function can_dispatch_due_tasks(
		PeakURL_DB $db,
		SettingsApi $settings_api,
		int $interval = BackgroundRunner::SCHEDULED_JOB_CHECK_INTERVAL
	): bool {
		$now          = time();
		$last_checked = (int) ( $settings_api->get_option( 'cron_last_visit_check' ) ?? '0' );

		// Quick check: if the lock interval is currently active, avoid querying job tables.
		if ( $now - $last_checked < $interval ) {
			return false;
		}

		$now_formatted = gmdate( 'Y-m-d H:i:s', $now );

		$has_due_jobs = false !== $db->get_var(
			"SELECT 1 FROM cron_jobs
			 WHERE is_enabled = 1
			 AND status != 'waiting'
			 AND (
				 (status != 'running' AND next_run_at <= NOW())
				 OR (status = 'running' AND lock_expires_at IS NOT NULL AND lock_expires_at < NOW())
			 )
			 LIMIT 1"
		);

		if ( $has_due_jobs ) {
			return self::acquire_visit_worker_lock( $db, $interval, $settings_api );
		}

		$has_due_webhooks = false !== $db->get_var(
			"SELECT 1 FROM webhook_deliveries
			 WHERE (status = 'pending' AND next_attempt_at <= NOW())
			 OR (status = 'processing' AND updated_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
			 LIMIT 1"
		);

		if ( $has_due_webhooks ) {
			return self::acquire_visit_worker_lock( $db, $interval, $settings_api );
		}

		$settings_api->update_option(
			'cron_last_visit_check',
			(string) $now,
			$now_formatted,
			false
		);

		return false;
	}

	/**
	 * Dispatch background tasks post-response on site visits.
	 *
	 * Flushes response to the client immediately via BackgroundRunner::finish_request(),
	 * verifies due tasks, builds the background runner through BackgroundRunnerFactory,
	 * and executes due work in the background.
	 *
	 * @param array<string, mixed> $config     Merged runtime configuration.
	 * @param Connection           $connection Database connection manager.
	 * @return void
	 * @since 1.7.1
	 */
	public static function dispatch_on_visit( array $config, Connection $connection ): void {
		if ( self::$has_dispatched || 'cli' === PHP_SAPI ) {
			return;
		}
		self::$has_dispatched = true;

		// On environments without detached execution support, do not run opportunistic
		// background maintenance on visitor requests to prevent delaying the visitor.
		if ( ! BackgroundRunner::supports_post_response_execution() ) {
			return;
		}

		try {
			// 1. Immediately flush response buffers and terminate the client connection.
			BackgroundRunner::finish_request();

			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
				set_time_limit( 120 );
			}

			$db           = new PeakURL_DB( $connection );
			$settings_api = new SettingsApi( $db );

			// 2. Lightweight check before loading heavy service graph.
			if ( ! self::can_dispatch_due_tasks( $db, $settings_api ) ) {
				return;
			}

			// 3. Modular instantiation via dedicated factory.
			$runner = BackgroundRunnerFactory::create( $connection, $config );
			$runner->enqueue_webhooks();
			$runner->dispatch_post_response( true, true );
		} catch ( \Throwable $exception ) {
			error_log( sprintf( 'PeakURL BackgroundDispatcher error: %s', $exception->getMessage() ) );
		}
	}

	/**
	 * Register background dispatch hooks into the application lifecycle.
	 *
	 * Registers a single PHP shutdown handler so opportunistic on-visit execution
	 * occurs reliably after the page response has finished without delaying the visitor.
	 *
	 * @param array<string, mixed> $config     Merged runtime configuration.
	 * @param Connection           $connection Database connection manager.
	 * @return void
	 * @since 1.7.1
	 */
	public static function register_hooks( array $config, Connection $connection ): void {
		if ( self::$hook_registered || 'cli' === PHP_SAPI ) {
			return;
		}
		self::$hook_registered = true;

		register_shutdown_function(
			static function () use ( $config, $connection ): void {
				self::dispatch_on_visit( $config, $connection );
			}
		);
	}
}
