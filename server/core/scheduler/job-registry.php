<?php
/**
 * Background job registry.
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
 * JobRegistry — central catalog of valid registered background jobs.
 *
 * Prevents arbitrary PHP callable or class execution by strictly requiring
 * jobs to be declared via JobDefinition instances.
 *
 * @since 1.7.0
 */
class JobRegistry {

	/**
	 * Map of registered job ID => JobDefinition.
	 *
	 * @var array<string, JobDefinition>
	 * @since 1.7.0
	 */
	private array $jobs = array();

	/**
	 * Register a job definition in the registry.
	 *
	 * @param JobDefinition $definition Job definition.
	 * @return void
	 * @since 1.7.0
	 */
	public function register( JobDefinition $definition ): void {
		$this->jobs[ $definition->get_id() ] = $definition;
	}

	/**
	 * Retrieve a registered job definition by ID.
	 *
	 * @param string $id Job identifier.
	 * @return JobDefinition|null Job definition or null if not found.
	 * @since 1.7.0
	 */
	public function get( string $id ): ?JobDefinition {
		return $this->jobs[ $id ] ?? null;
	}

	/**
	 * Check whether a job ID is registered.
	 *
	 * @param string $id Job identifier.
	 * @return bool True if registered.
	 * @since 1.7.0
	 */
	public function has( string $id ): bool {
		return isset( $this->jobs[ $id ] );
	}

	/**
	 * Get all registered job definitions.
	 *
	 * @return array<string, JobDefinition> Map of job ID => JobDefinition.
	 * @since 1.7.0
	 */
	public function all(): array {
		return $this->jobs;
	}

	/**
	 * Get all registered job IDs.
	 *
	 * @return array<int, string> List of registered job IDs.
	 * @since 1.7.0
	 */
	public function get_registered_ids(): array {
		return array_keys( $this->jobs );
	}
}
