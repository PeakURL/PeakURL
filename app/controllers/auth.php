<?php
/**
 * Authentication and security endpoints.
 *
 * Handles login, logout, registration, password reset, API key management,
 * two-factor authentication setup/verification, and session revocation.
 *
 * @package PeakURL\Controllers
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Auth controller — delegates to Data_Store for business logic.
 *
 * @since 1.0.0
 */
class Auth_Controller {

	/** @var Data_Store Shared data access layer. */
	private Data_Store $data_store;

	/**
	 * Create a new Auth_Controller.
	 *
	 * @param Data_Store $data_store Data access layer instance.
	 * @since 1.0.0
	 */
	public function __construct( Data_Store $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * Register a new user account (POST /api/v1/auth/register).
	 *
	 * @param Request $request Incoming HTTP request with body parameters.
	 * @return array<string, mixed> JSON success response with the new user.
	 * @since 1.0.0
	 */
	public function register( Request $request ): array {
		unset( $request );

		return Json_Response::error(
			'Public registration is disabled on this self-hosted release.',
			403,
		);
	}

	/**
	 * Verify a user's email address (POST /api/v1/auth/verify-email).
	 *
	 * @param Request $request Request containing the verification token.
	 * @return array<string, mixed> Success or 404 error response.
	 * @since 1.0.0
	 */
	public function verify_email( Request $request ): array {
		$token    = trim( (string) $request->get_body_param( 'token', '' ) );
		$verified = $this->data_store->verify_email( $token );

		if ( ! $verified ) {
			return Json_Response::error(
				'Verification token is invalid or expired.',
				404,
			);
		}

		return Json_Response::success( array( 'verified' => true ), 'Email verified.' );
	}

	/**
	 * Resend the email verification message (POST /api/v1/auth/resend-verification).
	 *
	 * @param Request $request Request with the target email address.
	 * @return array<string, mixed> Success response.
	 * @since 1.0.0
	 */
	public function resend_verification( Request $request ): array {
		return Json_Response::success(
			$this->data_store->resend_verification(
				$request,
				$request->get_body_params(),
			),
			'Verification email resent.',
		);
	}

	/**
	 * Authenticate with email/username and password (POST /api/v1/auth/login).
	 *
	 * @param Request $request Request containing identifier and password.
	 * @return array<string, mixed> User data and session cookie.
	 * @since 1.0.0
	 */
	public function login( Request $request ): array {
		return Json_Response::success(
			$this->data_store->login( $request, $request->get_body_params() ),
			'Signed in.',
		);
	}

	/**
	 * Complete a two-factor login challenge (POST /api/v1/auth/login/verify).
	 *
	 * @param Request $request Request with identifier, password, and TOTP token.
	 * @return array<string, mixed> Authenticated user response.
	 * @since 1.0.0
	 */
	public function verify_two_factor_login( Request $request ): array {
		return Json_Response::success(
			$this->data_store->verify_two_factor_login(
				$request,
				$request->get_body_params(),
			),
			'Two-factor login verified.',
		);
	}

	/**
	 * Sign out and revoke the session (POST /api/v1/auth/logout).
	 *
	 * @param Request $request Current authenticated request.
	 * @return array<string, mixed> Logout confirmation response.
	 * @since 1.0.0
	 */
	public function logout( Request $request ): array {
		$this->data_store->logout( $request );
		return Json_Response::success( array( 'loggedOut' => true ), 'Signed out.' );
	}

	/**
	 * Initiate a password reset (POST /api/v1/auth/forgot-password).
	 *
	 * @param Request $request Request with the user's email address or username.
	 * @return array<string, mixed> Confirmation response.
	 * @since 1.0.0
	 */
	public function forgot_password( Request $request ): array {
		return Json_Response::success(
			$this->data_store->forgot_password( $request->get_body_params() ),
			'If that account exists, a password reset link has been sent.',
		);
	}

	/**
	 * Reset a user's password via token (POST /api/v1/auth/reset-password/{token}).
	 *
	 * @param Request $request Request with the new password.
	 * @return array<string, mixed> Success or 404 error response.
	 * @since 1.0.0
	 */
	public function reset_password( Request $request ): array {
		$token = (string) $request->get_route_param( 'token' );
		$reset = $this->data_store->reset_password(
			$token,
			$request->get_body_params(),
		);

		if ( ! $reset ) {
			return Json_Response::error(
				'Password reset token is invalid or expired.',
				404,
			);
		}

		return Json_Response::success(
			array(
				'token' => $token,
			),
			'Password reset complete.',
		);
	}

	/**
	 * Create a new API key (POST /api/v1/auth/api-key).
	 *
	 * @param Request $request Authenticated request with optional label.
	 * @return array<string, mixed> Response containing the new key.
	 * @since 1.0.0
	 */
	public function generate_api_key( Request $request ): array {
		$label = (string) $request->get_body_param( 'label', 'Generated Key' );
		$key   = $this->data_store->add_api_key( $request, $label );

		return Json_Response::success(
			array(
				'apiKey' => $key['key'],
				'key'    => $key,
			),
			'API key created.',
		);
	}

	/**
	 * Delete an API key (DELETE /api/v1/auth/api-key/{id}).
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Deletion confirmation or 404 error.
	 * @since 1.0.0
	 */
	public function delete_api_key( Request $request ): array {
		$deleted = $this->data_store->delete_api_key(
			$request,
			(string) $request->get_route_param( 'id' ),
		);

		if ( ! $deleted ) {
			return Json_Response::error( 'API key not found.', 404 );
		}

		return Json_Response::success( array( 'deleted' => true ), 'API key deleted.' );
	}

	/**
	 * Get security settings for the current user (GET /api/v1/auth/security).
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Security settings response.
	 * @since 1.0.0
	 */
	public function get_security( Request $request ): array {
		return Json_Response::success(
			$this->data_store->get_security_settings( $request ),
			'Security settings loaded.',
		);
	}

	/**
	 * Begin TOTP two-factor setup (POST /api/v1/auth/security/two-factor/setup).
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Setup payload with secret and otpauth URI.
	 * @since 1.0.0
	 */
	public function start_two_factor_setup( Request $request ): array {
		return Json_Response::success(
			$this->data_store->start_two_factor_setup( $request ),
			'Two-factor setup started.',
		);
	}

	/**
	 * Verify a TOTP code to enable two-factor (POST /api/v1/auth/security/two-factor/verify).
	 *
	 * @param Request $request Request with the TOTP token.
	 * @return array<string, mixed> Response with backup codes.
	 * @since 1.0.0
	 */
	public function verify_two_factor( Request $request ): array {
		$token = trim( (string) $request->get_body_param( 'token', '' ) );

		if ( '' === $token ) {
			return Json_Response::error( 'Verification token is required.', 422 );
		}

		return Json_Response::success(
			array(
				'backupCodes' => $this->data_store->verify_two_factor(
					$request,
					$token,
				),
			),
			'Two-factor authentication enabled.',
		);
	}

	/**
	 * Disable two-factor authentication (POST /api/v1/auth/security/two-factor/disable).
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Confirmation response.
	 * @since 1.0.0
	 */
	public function disable_two_factor( Request $request ): array {
		$this->data_store->disable_two_factor( $request );
		return Json_Response::success(
			array( 'disabled' => true ),
			'Two-factor authentication disabled.',
		);
	}

	/**
	 * Regenerate two-factor backup codes (POST /api/v1/auth/security/two-factor/backup-codes).
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Response with new backup codes.
	 * @since 1.0.0
	 */
	public function regenerate_backup_codes( Request $request ): array {
		return Json_Response::success(
			array(
				'backupCodes' => $this->data_store->regenerate_backup_codes(
					$request,
				),
			),
			'Backup codes regenerated.',
		);
	}

	/**
	 * Download backup codes as a plain-text file (GET /api/v1/auth/security/backup-codes/download).
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Text response with codes.
	 * @since 1.0.0
	 */
	public function download_backup_codes( Request $request ): array {
		$security = $this->data_store->get_security_settings( $request );
		$codes    = $security['backupCodes'] ?? array();
		$body     = implode(
			"\n",
			array_merge(
				array(
					'PeakURL Backup Codes',
					'Keep these codes safe. Each code can be used once.',
					'',
				),
				array_map(
					static fn( string $code ): string => '- ' . $code,
					$codes,
				),
				array( '', 'Generated: ' . gmdate( DATE_ATOM ) ),
			),
		);

		return Json_Response::text( $body );
	}

	/**
	 * Revoke an active session (DELETE /api/v1/auth/security/sessions/{id}).
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Confirmation or 404 error.
	 * @since 1.0.0
	 */
	public function revoke_session( Request $request ): array {
		$revoked = $this->data_store->revoke_session(
			$request,
			(string) $request->get_route_param( 'id' ),
		);

		if ( ! $revoked ) {
			return Json_Response::error( 'Session not found.', 404 );
		}

		return Json_Response::success( array( 'revoked' => true ), 'Session revoked.' );
	}

	/**
	 * Revoke every active session except the current one.
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function revoke_other_sessions( Request $request ): array {
		$revoked_count = $this->data_store->revoke_other_sessions( $request );

		return Json_Response::success(
			array(
				'revoked'      => true,
				'revokedCount' => $revoked_count,
			),
			'Other sessions revoked.',
		);
	}
}
