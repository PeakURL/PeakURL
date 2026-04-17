<?php
/**
 * Data store account security trait.
 *
 * @package PeakURL\Data
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Traits\Accounts;

use PeakURL\Http\ApiException;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SecurityTrait — API key, two-factor, and session methods.
 *
 * @since 1.0.14
 */
trait SecurityTrait {

	/**
	 * Generate and store a new API key for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $label   Human-readable label for the key.
	 * @return array<string, mixed> The created API key record.
	 * @since 1.0.0
	 */
	public function add_api_key( Request $request, string $label ): array {
		$user = $this->assert_request_capability(
			$request,
			'manage_api_keys',
			'You do not have permission to manage API keys.',
		);
		return $this->insert_api_key( (string) $user['id'], $label );
	}

	/**
	 * Delete an API key belonging to the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      API key row ID.
	 * @return bool True if a key was deleted.
	 * @since 1.0.0
	 */
	public function delete_api_key( Request $request, string $id ): bool {
		$user    = $this->assert_request_capability(
			$request,
			'manage_api_keys',
			'You do not have permission to manage API keys.',
		);
		$deleted = $this->db->delete(
			'api_keys',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		);

		return $deleted > 0;
	}

	/**
	 * Return the security dashboard for the authenticated user.
	 *
	 * Includes two-factor status, active sessions, API keys,
	 * and backup-code availability.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Security settings payload.
	 * @since 1.0.0
	 */
	public function get_security_settings( Request $request ): array {
		$user         = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$sessions     = $this->list_user_sessions( (string) $user['id'], $request );
		$backup_codes = $this->list_backup_codes( (string) $user['id'] );
		$row          = $this->find_user_row_by_id( (string) $user['id'] );

		return array(
			'twoFactorEnabled'           => ! empty( $row['two_factor_enabled'] ),
			'hasPendingSetup'            => ! empty( $row['two_factor_pending_secret'] ),
			'backupCodesRemaining'       => count( $backup_codes ),
			'backupCodesLastGeneratedAt' => ! empty(
				$row['backup_codes_generated_at']
			)
				? $this->to_iso(
					(string) $row['backup_codes_generated_at']
				)
				: null,
			'sessions'                   => $sessions,
		);
	}

	/**
	 * Begin two-factor authentication setup.
	 *
	 * Generates a TOTP secret and a provisioning URI for authenticator
	 * apps. The secret is stored but 2FA is not yet enabled.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Secret and provisioning URI.
	 * @since 1.0.0
	 */
	public function start_two_factor_setup( Request $request ): array {
		$user   = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$secret = $this->totp_service->generate_secret();

		$this->db->update(
			'users',
			array(
				'two_factor_pending_secret' => $secret,
				'updated_at'                => $this->now(),
			),
			array(
				'id' => $user['id'],
			),
		);

		$label       = ! empty( $user['email'] ) ? $user['email'] : $user['username'];
		$otpauth_url = $this->totp_service->build_otpauth_url(
			'PeakURL',
			(string) $label,
			$secret,
		);

		return array(
			'secret'     => $secret,
			'otpauthUrl' => $otpauth_url,
		);
	}

	/**
	 * Confirm two-factor setup by verifying an initial TOTP token.
	 *
	 * Enables 2FA on the user account and generates backup codes.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $token   TOTP code from the authenticator app.
	 * @return array<string, mixed> Confirmation with backup codes.
	 *
	 * @throws ApiException When the token is invalid (422).
	 * @since 1.0.0
	 */
	public function verify_two_factor( Request $request, string $token ): array {
		$user           = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$row            = $this->find_user_row_by_id( (string) $user['id'] );
		$pending_secret = (string) ( $row['two_factor_pending_secret'] ?? '' );

		if ( '' === $pending_secret ) {
			throw new ApiException(
				__( 'No two-factor setup is pending for this account.', 'peakurl' ),
				422,
			);
		}

		if ( ! $this->totp_service->verify_code( $pending_secret, $token ) ) {
			throw new ApiException( __( 'Invalid verification code.', 'peakurl' ), 422 );
		}

		$backup_codes = $this->replace_backup_codes( (string) $user['id'] );

		$this->execute(
			'UPDATE users
            SET two_factor_enabled = 1,
                two_factor_secret = :secret,
                two_factor_pending_secret = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'secret'     => $pending_secret,
				'updated_at' => $this->now(),
				'id'         => $user['id'],
			),
		);

		return $backup_codes;
	}

	/**
	 * Disable two-factor authentication for the authenticated user.
	 *
	 * Clears the TOTP secret, disables the flag, and removes backup codes.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @since 1.0.0
	 */
	public function disable_two_factor(
		Request $request,
		string $current_password
	): void {
		$user     = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$user_row = $this->assert_password_confirmation_for_user(
			$this->find_user_row_by_id( (string) $user['id'] ),
			$current_password,
			__(
				'Current password is required to disable two-factor authentication.',
				'peakurl',
			),
		);
		$this->assert_two_factor_is_enabled_for_user( $user_row );

		$this->execute(
			'UPDATE users
            SET two_factor_enabled = 0,
                two_factor_secret = NULL,
                two_factor_pending_secret = NULL,
                backup_codes_json = NULL,
                backup_codes_generated_at = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'updated_at' => $this->now(),
				'id'         => $user_row['id'],
			),
		);
	}

	/**
	 * Regenerate backup codes for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> New set of backup codes.
	 * @since 1.0.0
	 */
	public function regenerate_backup_codes(
		Request $request,
		string $current_password
	): array {
		$user     = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$user_row = $this->assert_password_confirmation_for_user(
			$this->find_user_row_by_id( (string) $user['id'] ),
			$current_password,
			__(
				'Current password is required to regenerate backup codes.',
				'peakurl',
			),
		);
		$this->assert_two_factor_is_enabled_for_user( $user_row );

		return $this->replace_backup_codes( (string) $user_row['id'] );
	}

	/**
	 * Return backup codes for download after password confirmation.
	 *
	 * @param Request $request          Incoming HTTP request.
	 * @param string  $current_password Current password from the request.
	 * @return array<int, string> Plain-text backup codes.
	 * @since 1.0.6
	 */
	public function get_backup_codes_for_download(
		Request $request,
		string $current_password
	): array {
		$user     = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$user_row = $this->assert_password_confirmation_for_user(
			$this->find_user_row_by_id( (string) $user['id'] ),
			$current_password,
			__(
				'Current password is required to download backup codes.',
				'peakurl',
			),
		);
		$this->assert_two_factor_is_enabled_for_user( $user_row );

		return $this->list_backup_codes( (string) $user_row['id'] );
	}

	/**
	 * Revoke (log out) a specific session by ID.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Session row ID.
	 * @return bool True if a session was revoked.
	 * @since 1.0.0
	 */
	public function revoke_session( Request $request, string $id ): bool {
		$user = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$this->prune_stale_sessions();

		return $this->execute_statement(
			'DELETE FROM sessions
            WHERE id = :id
            AND user_id = :user_id
            AND last_active_at >= :active_since',
			array(
				'id'           => $id,
				'user_id'      => $user['id'],
				'active_since' => $this->session_active_since(),
			),
		) > 0;
	}

	/**
	 * Revoke every active session except the current browser session.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Number of revoked sessions.
	 *
	 * @throws ApiException When the current session cannot be identified.
	 * @since 1.0.0
	 */
	public function revoke_other_sessions( Request $request ): int {
		$user = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$this->prune_stale_sessions();
		$current_session = $this->find_session_by_request( $request );

		if ( ! $current_session ) {
			throw new ApiException(
				__( 'PeakURL could not identify the current session.', 'peakurl' ),
				422,
			);
		}

		return $this->execute_statement(
			'DELETE FROM sessions
			WHERE user_id = :user_id
			AND id <> :current_session_id
			AND revoked_at IS NULL
			AND last_active_at >= :active_since',
			array(
				'user_id'            => $user['id'],
				'current_session_id' => $current_session['id'],
				'active_since'       => $this->session_active_since(),
			),
		);
	}
}
