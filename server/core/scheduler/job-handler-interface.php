<?php
/**
 * Background job handler interface.
 *
 * @package PeakURL\Core\Scheduler
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Scheduler;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * JobHandlerInterface — contract for scheduler background jobs.
 *
 * Each business job implements this interface. The scheduler owns
 * discovery, locking, retries, and history; the job owns only the
 * execution of the specific task.
 *
 * @since 1.7.0
 */
interface JobHandlerInterface {

	/**
	 * Execute the background job.
	 *
	 * @param ExecutionContext $context Runtime execution context.
	 * @return ExecutionResult Execution outcome.
	 * @since 1.7.0
	 */
	public function execute( ExecutionContext $context ): ExecutionResult;
}
