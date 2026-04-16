<?php
/**
 * Data counts builder for system status.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

use PeakURL\Database\SchemaSpecs;
use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Data — build the site data counts section of the system-status payload.
 *
 * @since 1.0.14
 */
class Data {

	/**
	 * Shared system-status context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new data status helper.
	 *
	 * @param Context $context Shared system-status context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Build site data counts.
	 *
	 * @return array<string, int>
	 * @since 1.0.14
	 */
	public function build(): array {
		return array(
			'managedTables' => count( SchemaSpecs::managed_tables() ),
			'users'         => $this->safe_table_count( 'users' ),
			'links'         => $this->safe_table_count( 'urls' ),
			'clicks'        => $this->safe_table_count( 'clicks' ),
			'sessions'      => $this->count_active_sessions(),
			'apiKeys'       => $this->safe_table_count( 'api_keys' ),
			'webhooks'      => $this->safe_table_count( 'webhooks' ),
			'auditEvents'   => $this->safe_table_count( 'audit_logs' ),
		);
	}

	/**
	 * Count rows in a managed table without throwing on edge failures.
	 *
	 * @param string $table_name Managed table name without prefix.
	 * @return int
	 * @since 1.0.14
	 */
	private function safe_table_count( string $table_name ): int {
		try {
			return $this->context->get_db()->count( $table_name );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return 0;
		}
	}

	/**
	 * Count currently-active session rows.
	 *
	 * @return int
	 * @since 1.0.14
	 */
	private function count_active_sessions(): int {
		try {
			return (int) $this->context->get_db()->get_var(
				'SELECT COUNT(*)
				FROM sessions
				WHERE revoked_at IS NULL
				AND last_active_at >= :active_since',
				array(
					'active_since' => $this->get_active_session_cutoff(),
				),
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return 0;
		}
	}

	/**
	 * Calculate the earliest session timestamp that is still active.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function get_active_session_cutoff(): string {
		return gmdate(
			'Y-m-d H:i:s',
			time() - max(
				0,
				(int) ( $this->context->get_config()[ Constants::CONFIG_SESSION_LIFETIME ] ?? Constants::DEFAULT_SESSION_LIFETIME ),
			),
		);
	}
}
