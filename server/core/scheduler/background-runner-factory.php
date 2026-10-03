<?php
/**
 * Background runner factory.
 *
 * Encapsulates dependency resolution and instantiation for the background
 * execution runner using the canonical application service graph.
 *
 * @package PeakURL\Core\Scheduler
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Core\Scheduler;

use PeakURL\Core\Application;
use PeakURL\Services\Database\Connection;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * BackgroundRunnerFactory — modular factory for BackgroundRunner instances.
 *
 * Resolves the canonical Application domain service graph for CLI and
 * opportunistic on-visit execution contexts without duplicating service construction.
 *
 * @since 1.7.1
 */
class BackgroundRunnerFactory {

	/**
	 * Create a fully configured BackgroundRunner instance for the given runtime context.
	 *
	 * Uses the canonical Application dependency graph to avoid duplicate service instantiation.
	 *
	 * @param Connection           $connection Database connection manager.
	 * @param array<string, mixed> $config     Merged runtime configuration.
	 * @param callable|null        $logger     Optional logging callback for CLI progress.
	 * @return BackgroundRunner Fully wired background runner instance.
	 * @since 1.7.1
	 */
	public static function create(
		Connection $connection,
		array $config,
		?callable $logger = null
	): BackgroundRunner {
		$application = new Application( $connection, $config, null, $logger );
		return $application->get_background_runner();
	}
}
