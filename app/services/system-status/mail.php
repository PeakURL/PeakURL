<?php
/**
 * Mail status details builder.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Mail — build the mail section of the system-status payload.
 *
 * @since 1.0.14
 */
class Mail {

	/**
	 * Shared system-status context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new mail status helper.
	 *
	 * @param Context $context Shared system-status context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Build mail status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function build(): array {
		$status = $this->context->get_mailer_service()->get_status();

		return array(
			'driver'                 => (string) ( $status['driver'] ?? 'mail' ),
			'fromEmail'              => (string) ( $status['fromEmail'] ?? '' ),
			'fromName'               => (string) ( $status['fromName'] ?? '' ),
			'smtpHost'               => (string) ( $status['smtpHost'] ?? '' ),
			'smtpPort'               => (string) ( $status['smtpPort'] ?? '' ),
			'smtpEncryption'         => (string) ( $status['smtpEncryption'] ?? 'none' ),
			'smtpAuth'               => ! empty( $status['smtpAuth'] ),
			'smtpUsername'           => (string) ( $status['smtpUsername'] ?? '' ),
			'smtpPasswordConfigured' => ! empty(
				$status['smtpPasswordConfigured']
			),
			'configurationLabel'     => (string) ( $status['configurationLabel'] ?? '' ),
			'configurationPath'      => (string) ( $status['configurationPath'] ?? '' ),
			'canManageFromDashboard' => ! empty(
				$status['canManageFromDashboard']
			),
			'manageDisabledReason'   => (string) ( $status['manageDisabledReason'] ?? '' ),
			'transportReady'         => $this->is_transport_ready( $status ),
		);
	}

	/**
	 * Determine whether the active mail transport is ready to use.
	 *
	 * @param array<string, mixed> $status Mail status payload.
	 * @return bool
	 * @since 1.0.14
	 */
	private function is_transport_ready( array $status ): bool {
		$driver = (string) ( $status['driver'] ?? 'mail' );

		if ( 'smtp' !== $driver ) {
			return true;
		}

		if ( '' === trim( (string) ( $status['smtpHost'] ?? '' ) ) ) {
			return false;
		}

		if ( empty( $status['smtpAuth'] ) ) {
			return true;
		}

		return '' !== trim( (string) ( $status['smtpUsername'] ?? '' ) ) &&
			! empty( $status['smtpPasswordConfigured'] );
	}
}
