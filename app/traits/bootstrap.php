<?php
/**
 * Data store bootstrap trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Http\ApiException;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * BootstrapTrait — workspace bootstrap methods for Store.
 *
 * @since 1.0.0
 */
trait BootstrapTrait {

	/**
	 * Bootstrap the workspace on first request.
	 *
	 * Ensures the database tables exist, creates the owner admin user
	 * from config seed values if absent, and synchronizes install-time
	 * settings. Runs inside a transaction and is idempotent within a
	 * single request lifecycle.
	 *
	 * @throws ApiException  When tables are missing or install is incomplete.
	 * @throws \RuntimeException When the owner row cannot be created.
	 * @since 1.0.0
	 */
	public function bootstrap_workspace(): void {
		if ( $this->bootstrapped ) {
			return;
		}

		try {
			$this->get_database_schema_service()->repair_schema();
		} catch ( \Throwable $exception ) {
			throw new ApiException(
				'PeakURL could not finish the database upgrade. Verify the database user can alter tables, then retry.',
				500,
			);
		}

		$this->i18n_service->prepare_languages_directory();

		if ( ! $this->table_exists( 'users' ) ) {
			throw new ApiException(
				'Database tables are missing. Run the installer or `php bin/setup-database.php` inside the PHP runtime directory.',
				500,
			);
		}

		$this->db->begin_transaction();

		try {
			$owner = $this->get_workspace_owner_row();

			if ( ! $owner ) {
				if ( ! $this->has_install_data() ) {
					throw new ApiException(
						'PeakURL is not installed yet. Run install.php to finish setup.',
						503,
					);
				}

				$now = $this->now();

				$this->db->insert(
					'users',
					array(
						'username'          =>
							(string) $this->config['PEAKURL_OWNER_USERNAME'],
						'email'             =>
							(string) $this->config['PEAKURL_OWNER_EMAIL'],
						'first_name'        =>
							(string) $this->config['PEAKURL_OWNER_FIRST_NAME'],
						'last_name'         =>
							(string) $this->config['PEAKURL_OWNER_LAST_NAME'],
						'password_hash'     => password_hash(
							(string) $this->config['PEAKURL_OWNER_PASSWORD'],
							PASSWORD_DEFAULT,
						),
						'role'              => 'admin',
						'is_email_verified' => 1,
						'email_verified_at' => $now,
						'company'           => 'PeakURL',
						'bio'               => 'PeakURL workspace owner.',
						'created_at'        => $now,
						'updated_at'        => $now,
					),
				);

				$owner = $this->find_user_row_by_id( $this->last_insert_id() );
			}

			if ( ! $owner ) {
				throw new \RuntimeException(
					'Failed to bootstrap the workspace owner.',
				);
			}

			$this->sync_install_settings();

			$this->db->commit();
			$this->bootstrapped = true;
		} catch ( \Throwable $exception ) {
			if ( $this->db->in_transaction() ) {
				$this->db->roll_back();
			}

			throw $exception;
		}
	}

	/**
	 * Send the install welcome email once for the seeded workspace owner.
	 *
	 * Delivery is best-effort so installation can still complete on hosts
	 * where mail is not configured yet.
	 *
	 * @return void
	 * @since 1.0.2
	 */
	public function send_install_welcome_email_once(): void {
		if ( ! $this->table_exists( 'settings' ) ) {
			return;
		}

		if ( null !== $this->get_option( 'install_welcome_email_sent_at' ) ) {
			return;
		}

		$owner = $this->get_workspace_owner_row();

		if ( ! is_array( $owner ) ) {
			return;
		}

		try {
			$this->notifications_service->send_install_welcome_email( $owner );
			$this->update_option(
				'install_welcome_email_sent_at',
				$this->now(),
				false,
			);
		} catch ( \Throwable $exception ) {
			error_log(
				sprintf(
					'PeakURL mail error for install welcome (%s): %s',
					(string) ( $owner['email'] ?? 'unknown-email' ),
					$exception->getMessage(),
				),
			);
		}
	}
}
