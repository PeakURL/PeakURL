<?php
/**
 * Data store bootstrap trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;
use PeakURL\Services\Mailer;
use PeakURL\Services\SetupConfig;
use PeakURL\Services\Update;
use PeakURL\Utils\Query;
use PeakURL\Utils\Security;
use PeakURL\Utils\Visitor;

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

		if ( ! $this->table_exists( 'users' ) ) {
			throw new ApiException(
				'Database tables are missing. Run the installer or `php bin/setup-database.php` inside the PHP runtime directory.',
				500,
			);
		}

		$this->db->reconcile_schema();

		$this->db->begin_transaction();

		try {
			$owner = $this->get_workspace_owner_row();

			if ( ! $owner ) {
				if ( ! $this->has_install_seed_values() ) {
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
						'bio'               => 'Self-hosted workspace owner.',
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
}
