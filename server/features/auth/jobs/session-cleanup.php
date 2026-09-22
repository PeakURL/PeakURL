<?php
/**
 * Session cleanup background job.
 *
 * @package PeakURL\Features\Auth\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Auth\Jobs;

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Services\Database\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SessionCleanupJob — removes expired and revoked user sessions.
 *
 * @since 1.7.0
 */
class SessionCleanupJob implements JobHandlerInterface {

	/**
	 * Database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.0
	 */
	private PeakURL_DB $db;

	/**
	 * Configured session lifetime in seconds.
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $session_lifetime;

	/**
	 * Auth domain service.
	 *
	 * @var AuthService|null
	 * @since 1.7.0
	 */
	private ?AuthService $auth_service;

	/**
	 * Create a new session cleanup job.
	 *
	 * @param PeakURL_DB           $db           Database wrapper.
	 * @param array<string, mixed> $config       Application configuration.
	 * @param AuthService|null     $auth_service Optional auth domain service.
	 * @since 1.7.0
	 */
	public function __construct( PeakURL_DB $db, array $config = array(), ?AuthService $auth_service = null ) {
		$this->db               = $db;
		$this->auth_service     = $auth_service;
		$this->session_lifetime = (int) ( $config[ Constants::SESSION_LIFETIME ] ?? Constants::DEFAULT_SESSION_LIFETIME );
		if ( $this->session_lifetime <= 0 ) {
			$this->session_lifetime = Constants::DEFAULT_SESSION_LIFETIME;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		if ( null !== $this->auth_service ) {
			$deleted_count = $this->auth_service->prune_stale_sessions( 1000 );
		} else {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $this->session_lifetime );

			$deleted_count = $this->db->query(
				'DELETE FROM sessions
				WHERE revoked_at IS NOT NULL
				OR last_active_at < :active_since
				LIMIT 1000',
				array(
					'active_since' => $cutoff,
				)
			);
		}

		$message = 1 === $deleted_count
			? sprintf( 'Pruned %d expired or revoked session.', $deleted_count )
			: sprintf( 'Pruned %d expired or revoked sessions.', $deleted_count );

		return ExecutionResult::success(
			$message,
			array( 'deletedSessions' => $deleted_count )
		);
	}
}
