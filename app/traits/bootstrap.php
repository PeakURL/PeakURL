<?php
/**
 * Data store bootstrap trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Store_Bootstrap_Trait — workspace bootstrap methods for Data_Store.
 *
 * @since 1.0.0
 */
trait Store_Bootstrap_Trait {

	/**
	 * Bootstrap the workspace on first request.
	 *
	 * Ensures the database tables exist, creates the owner admin user
	 * from config seed values if absent, generates a primary API key,
	 * and synchronizes install-time settings. Runs inside a transaction
	 * and is idempotent within a single request lifecycle.
	 *
	 * @throws Api_Exception  When tables are missing or install is incomplete.
	 * @throws RuntimeException When the owner row cannot be created.
	 * @since 1.0.0
	 */
	public function bootstrap_workspace(): void {
		if ( $this->bootstrapped ) {
			return;
		}

		if ( ! $this->table_exists( 'users' ) ) {
			throw new Api_Exception(
				'Database tables are missing. Run the installer or `php bin/setup-database.php` inside the PHP runtime directory.',
				500,
			);
		}

		$this->db->begin_transaction();

		try {
			$owner = $this->get_workspace_owner_row();

			if ( ! $owner ) {
				if ( ! $this->has_install_seed_values() ) {
					throw new Api_Exception(
						'PeakURL is not installed yet. Run install.php to finish setup.',
						503,
					);
				}

				$now = $this->now();

				$this->execute(
					'INSERT INTO users (
                        username,
                        email,
                        first_name,
                        last_name,
                        password_hash,
                        role,
                        is_email_verified,
                        email_verified_at,
                        company,
                        bio,
                        created_at,
                        updated_at
                    ) VALUES (
                        :username,
                        :email,
                        :first_name,
                        :last_name,
                        :password_hash,
                        :role,
                        1,
                        :email_verified_at,
                        :company,
                        :bio,
                        :created_at,
                        :updated_at
                    )',
					array(
						'username'          => (string) $this->config['PEAKURL_OWNER_USERNAME'],
						'email'             => (string) $this->config['PEAKURL_OWNER_EMAIL'],
						'first_name'        =>
							(string) $this->config['PEAKURL_OWNER_FIRST_NAME'],
						'last_name'         =>
							(string) $this->config['PEAKURL_OWNER_LAST_NAME'],
						'password_hash'     => password_hash(
							(string) $this->config['PEAKURL_OWNER_PASSWORD'],
							PASSWORD_DEFAULT,
						),
						'role'              => 'admin',
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
				throw new RuntimeException(
					'Failed to bootstrap the workspace owner.',
				);
			}

			if (
				0 ===
				(int) $this->query_value(
					'SELECT COUNT(*) FROM api_keys WHERE user_id = :user_id',
					array( 'user_id' => $owner['id'] ),
				)
			) {
				$this->insert_api_key(
					(string) $owner['id'],
					'Primary Dashboard Key',
				);
			}

			$this->sync_install_settings();

			$this->db->commit();
			$this->bootstrapped = true;
		} catch ( Throwable $exception ) {
			if ( $this->db->in_transaction() ) {
				$this->db->roll_back();
			}

			throw $exception;
		}
	}
}
