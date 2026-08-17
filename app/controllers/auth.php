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

namespace PeakURL\Controllers;

use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Auth controller — delegates to Store for business logic.
 *
 * @since 1.0.0
 */
class AuthController extends BaseController {

	/**
	 * Reject public registration for self-hosted installs.
	 *
	 * @param Request $request Incoming HTTP request with body parameters.
	 * @return array<string, mixed> JSON error response.
	 * @since 1.0.0
	 */
	public function register( Request $request ): array {
		unset( $request );

		return $this->error_response(
			__( 'Public registration is disabled on this self-hosted release.', 'peakurl' ),
			403,
		);
	}

	/**
	 * Verify a user's email address.
	 *
	 * @param Request $request Request containing the verification token.
	 * @return array<string, mixed> Success or 404 error response.
	 * @since 1.0.0
	 */
	public function verify_email( Request $request ): array {
		$token    = trim( (string) $request->get_body_param( 'token', '' ) );
		$verified = $this->data_store->verify_email( $token );

		if ( ! $verified ) {
			return $this->not_found_response(
				__( 'Verification token is invalid or expired.', 'peakurl' ),
			);
		}

		return $this->confirm_response( 'verified', __( 'Email verified.', 'peakurl' ) );
	}

	/**
	 * Resend the email verification message.
	 *
	 * @param Request $request Request with the target email address.
	 * @return array<string, mixed> Success response.
	 * @since 1.0.0
	 */
	public function resend_verification( Request $request ): array {
		return $this->success_response(
			$this->data_store->resend_verification(
				$request,
				$request->get_body_params(),
			),
			__( 'Verification email resent.', 'peakurl' ),
		);
	}

	/**
	 * Authenticate with email/username and password.
	 *
	 * @param Request $request Request containing identifier and password.
	 * @return array<string, mixed> User data and session cookie.
	 * @since 1.0.0
	 */
	public function login( Request $request ): array {
		$this->verify_captcha_token( $request );

		return $this->success_response(
			$this->data_store->login( $request, $request->get_body_params() ),
			__( 'Signed in.', 'peakurl' ),
		);
	}

	/**
	 * Complete a two-factor login challenge.
	 *
	 * @param Request $request Request with identifier, password, and TOTP token.
	 * @return array<string, mixed> Authenticated user response.
	 * @since 1.0.0
	 */
	public function verify_two_factor_login( Request $request ): array {
		return $this->success_response(
			$this->data_store->verify_two_factor_login(
				$request,
				$request->get_body_params(),
			),
			__( 'Two-factor login verified.', 'peakurl' ),
		);
	}

	/**
	 * Sign out and revoke the session.
	 *
	 * @param Request $request Current authenticated request.
	 * @return array<string, mixed> Logout confirmation response.
	 * @since 1.0.0
	 */
	public function logout( Request $request ): array {
		$this->data_store->logout( $request );
		return $this->confirm_response( 'loggedOut', __( 'Signed out.', 'peakurl' ) );
	}

	/**
	 * Initiate a password reset.
	 *
	 * @param Request $request Request with the user's email address or username.
	 * @return array<string, mixed> Confirmation response.
	 * @since 1.0.0
	 */
	public function forgot_password( Request $request ): array {
		$this->verify_captcha_token( $request );

		return $this->success_response(
			$this->data_store->forgot_password( $request->get_body_params() ),
			__( 'If that account exists, a password reset link has been sent.', 'peakurl' ),
		);
	}

	/**
	 * Validate a password reset token before showing the reset form.
	 *
	 * @param Request $request Request containing the reset token route param.
	 * @return array<string, mixed> Token status response.
	 * @since 1.1.0
	 */
	public function validate_reset_token( Request $request ): array {
		$token = $this->route_param( $request, 'token' );

		if ( ! $this->data_store->validate_reset_token( $token ) ) {
			return $this->not_found_response(
				__( 'Password reset token is invalid or expired.', 'peakurl' ),
			);
		}

		return $this->success_response(
			array(
				'valid' => true,
			),
			__( 'Password reset token is valid.', 'peakurl' ),
		);
	}

	/**
	 * Reset a user's password via token.
	 *
	 * @param Request $request Request with the new password.
	 * @return array<string, mixed> Success or 404 error response.
	 * @since 1.0.0
	 */
	public function reset_password( Request $request ): array {
		$this->verify_captcha_token( $request );

		$token = $this->route_param( $request, 'token' );
		$reset = $this->data_store->reset_password(
			$token,
			$request->get_body_params(),
		);

		if ( ! $reset ) {
			return $this->not_found_response(
				__( 'Password reset token is invalid or expired.', 'peakurl' ),
			);
		}

		return $this->success_response(
			array(
				'token' => $token,
			),
			__( 'Password reset complete.', 'peakurl' ),
		);
	}

	/**
	 * Create a new API key.
	 *
	 * @param Request $request Authenticated request with optional label.
	 * @return array<string, mixed> Response containing the new key.
	 * @since 1.0.0
	 */
	public function generate_api_key( Request $request ): array {
		$label = (string) $request->get_body_param( 'label', __( 'Generated Key', 'peakurl' ) );
		$key   = $this->data_store->add_api_key( $request, $label );

		return $this->success_response(
			array(
				'apiKey'     => $key['key'],
				'baseApiUrl' => \get_api_base_url(),
			),
			__( 'API key created.', 'peakurl' ),
		);
	}

	/**
	 * Delete an API key.
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Deletion confirmation or 404 error.
	 * @since 1.0.0
	 */
	public function delete_api_key( Request $request ): array {
		$deleted = $this->data_store->delete_api_key(
			$request,
			$this->route_param( $request, 'id' ),
		);

		return $this->delete_response(
			$deleted,
			__( 'API key not found.', 'peakurl' ),
			__( 'API key deleted.', 'peakurl' ),
		);
	}

	/**
	 * Get security settings for the current user.
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Security settings response.
	 * @since 1.0.0
	 */
	public function get_security( Request $request ): array {
		return $this->success_response(
			$this->data_store->get_security_settings( $request ),
			__( 'Security settings loaded.', 'peakurl' ),
		);
	}

	/**
	 * Begin TOTP two-factor setup.
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Setup payload with secret and otpauth URI.
	 * @since 1.0.0
	 */
	public function start_two_factor_setup( Request $request ): array {
		return $this->success_response(
			$this->data_store->start_two_factor_setup( $request ),
			__( 'Two-factor setup started.', 'peakurl' ),
		);
	}

	/**
	 * Verify a TOTP code to enable two-factor.
	 *
	 * @param Request $request Request with the TOTP token.
	 * @return array<string, mixed> Response with backup codes.
	 * @since 1.0.0
	 */
	public function verify_two_factor( Request $request ): array {
		$token = trim( (string) $request->get_body_param( 'token', '' ) );

		if ( '' === $token ) {
			return $this->error_response(
				__( 'Verification token is required.', 'peakurl' ),
				422,
			);
		}

		return $this->success_response(
			array(
				'backupCodes' => $this->data_store->verify_two_factor(
					$request,
					$token,
				),
			),
			__( 'Two-factor authentication enabled.', 'peakurl' ),
		);
	}

	/**
	 * Disable two-factor authentication.
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Confirmation response.
	 * @since 1.0.0
	 */
	public function disable_two_factor( Request $request ): array {
		$current_password = (string) $request->get_body_param(
			'currentPassword',
			'',
		);

		$this->data_store->disable_two_factor( $request, $current_password );
		return $this->confirm_response(
			'disabled',
			__( 'Two-factor authentication disabled.', 'peakurl' ),
		);
	}

	/**
	 * Regenerate two-factor backup codes.
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Response with new backup codes.
	 * @since 1.0.0
	 */
	public function regenerate_backup_codes( Request $request ): array {
		$current_password = (string) $request->get_body_param(
			'currentPassword',
			'',
		);

		return $this->success_response(
			array(
				'backupCodes' => $this->data_store->regenerate_backup_codes(
					$request,
					$current_password,
				),
			),
			__( 'Backup codes regenerated.', 'peakurl' ),
		);
	}

	/**
	 * Download backup codes as a plain-text file.
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Text response with codes.
	 * @since 1.0.6
	 */
	public function download_backup_codes( Request $request ): array {
		$current_password = (string) $request->get_body_param(
			'currentPassword',
			'',
		);
		$codes            = $this->data_store->get_backup_codes(
			$request,
			$current_password,
		);
		$body             = implode(
			"\n",
			array_merge(
				array(
					__( 'PeakURL Backup Codes', 'peakurl' ),
					__( 'Keep these codes safe. Each code can be used once.', 'peakurl' ),
					'',
				),
				array_map(
					static fn( string $code ): string => '- ' . $code,
					$codes,
				),
				array( '', __( 'Generated:', 'peakurl' ) . ' ' . gmdate( DATE_ATOM ) ),
			),
		);

		return JsonResponse::text( $body );
	}

	/**
	 * Revoke an active session.
	 *
	 * @param Request $request Authenticated request.
	 * @return array<string, mixed> Confirmation or 404 error.
	 * @since 1.0.0
	 */
	public function revoke_session( Request $request ): array {
		$revoked = $this->data_store->revoke_session(
			$request,
			$this->route_param( $request, 'id' ),
		);

		if ( ! $revoked ) {
			return $this->not_found_response( __( 'Session not found.', 'peakurl' ) );
		}

		return $this->confirm_response( 'revoked', __( 'Session revoked.', 'peakurl' ) );
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

		return $this->success_response(
			array(
				'revoked'      => true,
				'revokedCount' => $revoked_count,
			),
			__( 'Other sessions revoked.', 'peakurl' ),
		);
	}
}
