<?php
/**
 * Authentication and security domain service.
 *
 * Implements login, logout, verification, password recovery,
 * two-factor authentication, API keys, and session lifecycle.
 *
 * @package PeakURL\Features\Auth
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Auth;

use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\RuntimeConfig;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Security\Security;
use PeakURL\Features\Analytics\Visitor;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — Authentication, authorization tokens, and credentials management.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Database service.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Users data API.
	 *
	 * @var UsersApi
	 * @since 1.0.0
	 */
	private UsersApi $users_api;

	/**
	 * Credentials manager for API keys and backup codes.
	 *
	 * @var Credentials
	 * @since 1.0.0
	 */
	private Credentials $credentials;

	/**
	 * Input validator.
	 *
	 * @var Validator
	 * @since 1.0.0
	 */
	private Validator $validator;

	/**
	 * Two-factor TOTP service.
	 *
	 * @var Totp
	 * @since 1.0.0
	 */
	private Totp $totp;

	/**
	 * Transactional notifications service.
	 *
	 * @var Notifications
	 * @since 1.0.0
	 */
	private Notifications $notifications;

	/**
	 * Crypto service.
	 *
	 * @var Crypto
	 * @since 1.0.0
	 */
	private Crypto $crypto;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Authorization service.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Optional GeoIP service for session locations.
	 *
	 * @var Geoip|null
	 * @since 1.0.0
	 */
	private ?Geoip $geoip;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Create an AuthService instance from runtime configuration and database connection.
	 *
	 * @param array<string, mixed> $config     Runtime configuration.
	 * @param Connection           $connection Database connection manager.
	 * @param SettingsApi|null     $settings   Optional settings API helper.
	 * @return self
	 * @since 1.2.3
	 */
	public static function create(
		array $config,
		Connection $connection,
		?SettingsApi $settings = null
	): self {
		$db_prefix      = (string) ( $config[ Constants::DB_PREFIX ] ?? '' );
		$db             = new PeakURL_DB( $connection, $db_prefix );
		$settings_api   = $settings ?? new SettingsApi( $db );
		$crypto_service = new Crypto( $config );
		$roles          = new Roles();

		return new self(
			$db,
			new UsersApi( $db ),
			new Credentials( $db ),
			new Validator(),
			new Totp(),
			new Notifications(),
			$crypto_service,
			$roles,
			new Authorization( $roles ),
			new Geoip( $config, $settings_api, $crypto_service ),
			$config
		);
	}

	/**
	 * Create a new Auth domain service instance.
	 *
	 * @param PeakURL_DB         $db            Database service.
	 * @param UsersApi           $users_api     Users query API.
	 * @param Credentials        $credentials   Credentials manager.
	 * @param Validator          $validator     Input validator.
	 * @param Totp               $totp          Two-factor TOTP service.
	 * @param Notifications      $notifications Notification service.
	 * @param Crypto             $crypto        Crypto service.
	 * @param Roles              $roles         Roles registry.
	 * @param Authorization      $authorization Authorization service.
	 * @param Geoip|null         $geoip         Optional GeoIP service.
	 * @param array<string, mixed> $config      Runtime config map.
	 * @since 1.0.0
	 */
	public function __construct(
		PeakURL_DB $db,
		UsersApi $users_api,
		Credentials $credentials,
		Validator $validator,
		Totp $totp,
		Notifications $notifications,
		Crypto $crypto,
		Roles $roles,
		Authorization $authorization,
		?Geoip $geoip,
		array $config
	) {
		$this->db            = $db;
		$this->users_api     = $users_api;
		$this->credentials   = $credentials;
		$this->validator     = $validator;
		$this->totp          = $totp;
		$this->notifications = $notifications;
		$this->crypto        = $crypto;
		$this->roles         = $roles;
		$this->authorization = $authorization;
		$this->geoip         = $geoip;
		$this->config        = $config;
	}

	/**
	 * Return whether an email address already belongs to another user.
	 *
	 * @param string      $email           Normalized email address.
	 * @param string|null $exclude_user_id Optional user ID to ignore.
	 * @return bool
	 * @since 1.0.0
	 */
	public function email_in_use(
		string $email,
		?string $exclude_user_id = null
	): bool {
		$user = $this->users_api->get_user_by_email( $email );

		if ( ! $user ) {
			return false;
		}

		if ( null === $exclude_user_id ) {
			return true;
		}

		return (string) $user['id'] !== $exclude_user_id;
	}

	/**
	 * Return whether a username already belongs to another user.
	 *
	 * @param string      $username        Username to inspect.
	 * @param string|null $exclude_user_id Optional user ID to ignore.
	 * @return bool
	 * @since 1.0.0
	 */
	public function username_in_use(
		string $username,
		?string $exclude_user_id = null
	): bool {
		$user = $this->users_api->get_user_by_username( $username );

		if ( ! $user ) {
			return false;
		}

		if ( null === $exclude_user_id ) {
			return true;
		}

		return (string) $user['id'] !== $exclude_user_id;
	}

	/**
	 * Issue a raw + hashed lookup token pair for user account flows.
	 *
	 * Verification and password-reset links still send the raw token to the
	 * browser, but the database only stores its SHA-256 hash.
	 *
	 * @return array{raw: string, hash: string}
	 * @since 1.0.0
	 */
	public function issue_lookup_token(): array {
		$raw_token = Credentials::generate_lookup_token();

		return array(
			'raw'  => $raw_token,
			'hash' => Credentials::hash_lookup_token( $raw_token ),
		);
	}

	/**
	 * Ensure a sensitive account action is confirmed with the current password.
	 *
	 * @param array<string, mixed>|null $user_row         User database row.
	 * @param string                    $current_password Plain-text current password.
	 * @param string                    $missing_message  Validation message for missing input.
	 * @return array<string, mixed> Validated user row.
	 *
	 * @throws ApiException When the password is missing or incorrect.
	 * @since 1.0.0
	 */
	public function confirm_current_password(
		?array $user_row,
		string $current_password,
		string $missing_message
	): array {
		if ( ! $user_row ) {
			throw new ApiException(
				__( 'User account could not be loaded.', 'peakurl' ),
				404,
			);
		}

		if ( '' === $current_password ) {
			throw new ApiException( $missing_message, 422 );
		}

		if (
			! password_verify(
				$current_password,
				(string) ( $user_row['password_hash'] ?? '' ),
			)
		) {
			throw new ApiException(
				__( 'Current password is incorrect.', 'peakurl' ),
				422,
			);
		}

		return $user_row;
	}

	/**
	 * Ensure two-factor actions only run when 2FA is enabled.
	 *
	 * @param array<string, mixed> $user_row User database row.
	 * @return void
	 *
	 * @throws ApiException When 2FA is not enabled.
	 * @since 1.0.0
	 */
	public function validate_two_factor( array $user_row ): void {
		if ( empty( $user_row['two_factor_enabled'] ) ) {
			throw new ApiException(
				__(
					'Two-factor authentication is not enabled for this account.',
					'peakurl',
				),
				422,
			);
		}
	}

	/**
	 * Send a password-changed notification without blocking the write path.
	 *
	 * @param array<string, mixed> $user User database row.
	 * @return void
	 * @since 1.0.0
	 */
	public function send_password_changed( array $user ): void {
		try {
			$this->notifications->send_password_changed( $user );
		} catch ( \RuntimeException $exception ) {
			error_log(
				sprintf(
					'PeakURL mail error for password changed (%s): %s',
					(string) ( $user['email'] ?? 'unknown-email' ),
					$exception->getMessage(),
				),
			);
		}
	}

	/**
	 * Register a new user account.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Registration body.
	 * @return array<string, mixed> Formatted user profile.
	 *
	 * @throws ApiException On duplicate email or username.
	 * @since 1.0.0
	 */
	public function register( Request $request, array $payload ): array {
		$email    = $this->validator->validate_email( (string) ( $payload['email'] ?? '' ) );
		$username = $this->validator->validate_username(
			(string) ( $payload['username'] ?? '' )
		);
		$password = $this->validator->validate_password(
			(string) ( $payload['password'] ?? '' )
		);

		if ( $this->email_in_use( $email ) ) {
			throw new ApiException(
				__( 'Email address is already registered.', 'peakurl' ),
				422,
			);
		}

		if ( $this->username_in_use( $username ) ) {
			throw new ApiException( __( 'Username is already taken.', 'peakurl' ), 422 );
		}

		$now                = Date::now();
		$verification_token = $this->issue_lookup_token();

		$this->db->insert(
			'users',
			array(
				'first_name'                    => trim( (string) ( $payload['firstName'] ?? '' ) ),
				'last_name'                     => trim( (string) ( $payload['lastName'] ?? '' ) ),
				'username'                      => $username,
				'email'                         => $email,
				'password_hash'                 => password_hash( $password, PASSWORD_DEFAULT ),
				'email_verification_token'      => $verification_token['hash'],
				'email_verification_sent_at'    => $now,
				'email_verification_expires_at' => gmdate(
					'Y-m-d H:i:s',
					time() + ( 24 * 3600 ),
				),
				'created_at'                    => $now,
				'updated_at'                    => $now,
			),
		);

		$user_id = (string) $this->db->insert_id();

		$this->create_session_for_user( $request, $user_id );
		$user = $this->users_api->get_user( $user_id );

		\do_action( 'user_register', $this->format_user( $user ), $request );

		return $this->format_user( $user, $request );
	}

	/**
	 * Verify an email address with a signed token.
	 *
	 * @param string $token Plain-text verification token.
	 * @return bool True if successfully verified.
	 *
	 * @throws ApiException When token is invalid or expired.
	 * @since 1.0.0
	 */
	public function verify_email( string $token ): bool {
		$clean_token = trim( $token );

		if ( '' === $clean_token ) {
			throw new ApiException( __( 'Invalid verification token.', 'peakurl' ), 422 );
		}

		$token_hash = Credentials::hash_lookup_token( $clean_token );
		$user       = $this->db->get_row(
			'SELECT * FROM users
            WHERE email_verification_token = :token_hash
            AND email_verification_expires_at > :now
            LIMIT 1',
			array(
				'token_hash' => $token_hash,
				'now'        => Date::now(),
			),
		);

		if ( ! $user ) {
			throw new ApiException(
				__( 'Verification token is invalid or has expired.', 'peakurl' ),
				422,
			);
		}

		$this->db->query(
			'UPDATE users
            SET is_email_verified = 1,
                email_verified_at = :email_verified_at,
                email_verification_token = NULL,
                email_verification_sent_at = NULL,
                email_verification_expires_at = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'email_verified_at' => Date::now(),
				'updated_at'        => Date::now(),
				'id'                => $user['id'],
			),
		);

		return true;
	}

	/**
	 * Resend the verification email to the current user.
	 *
	 * @param Request     $request Incoming HTTP request.
	 * @param string|null $email   Optional email override.
	 * @return void
	 *
	 * @throws ApiException When unauthenticated or email not found.
	 * @since 1.0.0
	 */
	public function resend_verification(
		Request $request,
		?string $email = null
	): void {
		$current_user = $this->current_user( $request );

		if ( $current_user ) {
			$email = (string) $current_user['email'];
		} elseif ( empty( $email ) ) {
			throw new ApiException( __( 'Email address is required.', 'peakurl' ), 422 );
		}

		$user = $this->users_api->get_user_by_email( $email );

		if ( ! $user || ! empty( $user['is_email_verified'] ) ) {
			return;
		}

		$verification_token = $this->issue_lookup_token();

		$this->db->query(
			'UPDATE users
            SET email_verification_token = :token_hash,
                email_verification_sent_at = :sent_at,
                email_verification_expires_at = :expires_at,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'token_hash' => $verification_token['hash'],
				'sent_at'    => Date::now(),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 24 * 3600 ) ),
				'updated_at' => Date::now(),
				'id'         => $user['id'],
			),
		);
	}

	/**
	 * Authenticate user with password or trigger two-factor step.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Login parameters.
	 * @return array<string, mixed> Login state result.
	 *
	 * @throws ApiException When credentials are missing or incorrect.
	 * @since 1.0.0
	 */
	public function login( Request $request, array $payload ): array {
		$identifier = trim(
			(string) ( $payload['identifier'] ??
				( $payload['email'] ?? ( $payload['username'] ?? '' ) ) )
		);
		$password   = (string) ( $payload['password'] ?? '' );
		$token      = trim( (string) ( $payload['token'] ?? '' ) );
		$remember   = ! empty( $payload['rememberMe'] );

		if ( '' === $identifier || '' === $password ) {
			throw new ApiException(
				__( 'Email or username and password are required.', 'peakurl' ),
				422,
			);
		}

		$user = $this->db->get_row(
			'SELECT * FROM users
            WHERE LOWER(email) = :email_identifier OR LOWER(username) = :username_identifier
            LIMIT 1',
			array(
				'email_identifier'    => $identifier,
				'username_identifier' => $identifier,
			),
		);

		if (
			! $user ||
			! password_verify( $password, (string) $user['password_hash'] )
		) {
			\do_action( 'login_failed', $identifier, 'invalid_credentials', $request );

			throw new ApiException(
				__( 'Invalid email, username, or password.', 'peakurl' ),
				401,
			);
		}

		if ( ! empty( $user['two_factor_enabled'] ) ) {
			if ( '' === $token ) {
				return array(
					'user'              => $this->format_user( $user, $request ),
					'requiresTwoFactor' => true,
				);
			}

			if (
				! $this->totp->verify_code(
					(string) $user['two_factor_secret'],
					$token,
				)
			) {
				if ( ! $this->credentials->verify_backup_code( (string) $user['id'], $token ) ) {
					\do_action( 'login_failed', $identifier, 'invalid_two_factor_code', $request );

					throw new ApiException( __( 'Invalid two-factor code.', 'peakurl' ), 401 );
				}
			}
		}

		$this->create_session_for_user( $request, (string) $user['id'], $remember );
		$this->db->update(
			'users',
			array(
				'last_login_at' => Date::now(),
				'updated_at'    => Date::now(),
			),
			array(
				'id' => $user['id'],
			),
		);

		$user = $this->users_api->get_user( (string) $user['id'] );

		\do_action( 'login', $this->format_user( $user ), $request );

		return array(
			'user'              => $this->format_user( $user, $request ),
			'requiresTwoFactor' => false,
		);
	}

	/**
	 * Verify two-factor challenge during login.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Body payload.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When 2FA is still required.
	 * @since 1.0.0
	 */
	public function verify_two_factor_login(
		Request $request,
		array $payload
	): array {
		$result = $this->login( $request, $payload );

		if ( ! empty( $result['requiresTwoFactor'] ) ) {
			throw new ApiException(
				__( 'Two-factor authentication code is required.', 'peakurl' ),
				401,
			);
		}

		return $result;
	}

	/**
	 * Destroy the active session and revoke the current token.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return void
	 * @since 1.0.0
	 */
	public function logout( Request $request ): void {
		$session = $this->find_session_by_request( $request );
		$user    = $session
			? $this->users_api->get_user( (string) $session['user_id'] )
			: null;

		if ( $session ) {
			$this->db->delete(
				'sessions',
				array(
					'id' => $session['id'],
				),
			);
		}

		$request->expire_cookie(
			(string) $this->config[ Constants::SESSION_COOKIE_NAME ],
			Security::session_cookie_options( $this->config, $request ),
		);

		\do_action( 'logout', $this->format_user( $user ), $request );
	}

	/**
	 * Request a password-reset link for an email or username.
	 *
	 * Always succeeds silently from the client perspective to prevent user enumeration.
	 *
	 * @param Request $request    Incoming HTTP request.
	 * @param string  $identifier Email or username.
	 * @return void
	 * @since 1.0.0
	 */
	public function forgot_password(
		Request $request,
		string $identifier
	): void {
		$this->validator->validate_email_or_username( $identifier );

		$normalized_identifier = strtolower( trim( $identifier ) );
		$user                  = false !== strpos( $identifier, '@' )
			? $this->users_api->get_user_by_email( $normalized_identifier )
			: $this->users_api->get_user_by_username( $identifier );
		$reset_token           = $this->issue_lookup_token();

		if ( $user ) {
			$this->db->query(
				'UPDATE users
                SET password_reset_token = :token_hash,
                    password_reset_sent_at = :sent_at,
                    password_reset_expires_at = :expires_at,
                    updated_at = :updated_at
                WHERE id = :id',
				array(
					'token_hash' => $reset_token['hash'],
					'sent_at'    => Date::now(),
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
					'updated_at' => Date::now(),
					'id'         => $user['id'],
				),
			);

			$this->notifications->send_password_reset(
				$user,
				$reset_token['raw'],
			);
		}
	}

	/**
	 * Validate a password reset token.
	 *
	 * @param string $token Plain-text reset token.
	 * @return bool True if valid and not expired.
	 * @since 1.0.0
	 */
	public function validate_reset_token( string $token ): bool {
		$clean_token = trim( $token );

		if ( '' === $clean_token ) {
			return false;
		}

		$user = $this->find_user_by_reset_token( $clean_token );

		return null !== $user;
	}

	/**
	 * Set a new password using a valid reset token.
	 *
	 * @param Request $request  Incoming HTTP request.
	 * @param string  $token    Plain-text reset token.
	 * @param string  $password New password.
	 * @return bool True if successfully updated.
	 *
	 * @throws ApiException When token is invalid or expired.
	 * @since 1.0.0
	 */
	public function reset_password(
		Request $request,
		string $token,
		string $password
	): bool {
		$clean_token = trim( $token );
		$user        = $this->find_user_by_reset_token( $clean_token );

		if ( ! $user ) {
			throw new ApiException(
				__( 'Password reset token is invalid or has expired.', 'peakurl' ),
				422,
			);
		}

		$validated_password = $this->validator->validate_password( $password );

		$this->db->query(
			'UPDATE users
            SET password_hash = :hash,
                password_reset_token = NULL,
                password_reset_sent_at = NULL,
                password_reset_expires_at = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'hash'       => password_hash( $validated_password, PASSWORD_DEFAULT ),
				'updated_at' => Date::now(),
				'id'         => $user['id'],
			),
		);

		$this->db->query(
			'UPDATE sessions
            SET revoked_at = :revoked_at
            WHERE user_id = :user_id',
			array(
				'revoked_at' => Date::now(),
				'user_id'    => $user['id'],
			),
		);

		$this->send_password_changed( $user );

		\do_action( 'password_reset', $this->format_user( $user ) );

		return true;
	}

	/**
	 * Find user record associated with an active password reset token.
	 *
	 * @param string $token Raw token from URL.
	 * @return array<string, mixed>|null User row or null.
	 * @since 1.0.0
	 */
	private function find_user_by_reset_token( string $token ): ?array {
		$clean_token = trim( $token );

		if ( '' === $clean_token ) {
			return null;
		}

		$token_hash = Credentials::hash_lookup_token( $clean_token );

		return $this->db->get_row(
			'SELECT * FROM users
            WHERE password_reset_token = :token_hash
            AND password_reset_expires_at > :now
            LIMIT 1',
			array(
				'token_hash' => $token_hash,
				'now'        => Date::now(),
			),
		);
	}

	/**
	 * Create and return a new API key for the current user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $label   Optional key label.
	 * @return array<string, mixed> Created key details.
	 * @since 1.0.0
	 */
	public function add_api_key( Request $request, string $label ): array {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_api_keys',
			__( 'You do not have permission to manage API keys.', 'peakurl' ),
		);

		return $this->credentials->insert_api_key(
			(string) $user['id'],
			$this->validator->sanitize_key_label( $label ),
		);
	}

	/**
	 * Revoke an existing API key.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      API key identifier.
	 * @return bool True if deleted.
	 * @since 1.0.0
	 */
	public function delete_api_key( Request $request, string $id ): bool {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_api_keys',
			__( 'You do not have permission to manage API keys.', 'peakurl' ),
		);

		return $this->credentials->revoke_api_key( (string) $user['id'], $id );
	}

	/**
	 * Return the security overview for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Security settings.
	 * @since 1.0.0
	 */
	public function get_security_settings( Request $request ): array {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to manage account security.', 'peakurl' ),
		);

		$sessions     = $this->list_user_sessions(
			(string) $user['id'],
			$request,
			true,
		);
		$backup_codes = $this->credentials->list_backup_codes( (string) $user['id'] );
		$row          = $this->users_api->get_user( (string) $user['id'] );

		return array(
			'twoFactorEnabled'           => ! empty( $row['two_factor_enabled'] ),
			'hasPendingSetup'            => ! empty( $row['two_factor_pending_secret'] ),
			'backupCodesRemaining'       => count( $backup_codes ),
			'backupCodesLastGeneratedAt' => ! empty(
				$row['backup_codes_generated_at']
			)
				? Date::to_iso(
					(string) $row['backup_codes_generated_at']
				)
				: null,
			'sessions'                   => $sessions,
		);
	}

	/**
	 * Begin two-factor authentication setup.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Secret and otpauth URL.
	 * @since 1.0.0
	 */
	public function start_two_factor_setup( Request $request ): array {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to manage account security.', 'peakurl' ),
		);

		$secret = $this->totp->generate_secret();

		$this->db->update(
			'users',
			array(
				'two_factor_pending_secret' => $secret,
				'updated_at'                => Date::now(),
			),
			array(
				'id' => $user['id'],
			),
		);

		$label       = ! empty( $user['email'] ) ? $user['email'] : $user['username'];
		$otpauth_url = $this->totp->get_otpauth_url(
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
	 * Confirm two-factor setup by verifying initial TOTP token.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $token   TOTP code.
	 * @return array<int, string> Backup codes.
	 *
	 * @throws ApiException When code is invalid or setup not pending.
	 * @since 1.0.0
	 */
	public function verify_two_factor( Request $request, string $token ): array {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to manage account security.', 'peakurl' ),
		);

		$row            = $this->users_api->get_user( (string) $user['id'] );
		$pending_secret = (string) ( $row['two_factor_pending_secret'] ?? '' );

		if ( '' === $pending_secret ) {
			throw new ApiException(
				__( 'No two-factor setup is pending for this account.', 'peakurl' ),
				422,
			);
		}

		if ( ! $this->totp->verify_code( $pending_secret, $token ) ) {
			throw new ApiException( __( 'Invalid verification code.', 'peakurl' ), 422 );
		}

		$backup_codes = $this->credentials->replace_backup_codes( (string) $user['id'] );

		$this->db->query(
			'UPDATE users
            SET two_factor_enabled = 1,
                two_factor_secret = :secret,
                two_factor_pending_secret = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'secret'     => $pending_secret,
				'updated_at' => Date::now(),
				'id'         => $user['id'],
			),
		);

		return $backup_codes;
	}

	/**
	 * Disable two-factor authentication for current user.
	 *
	 * @param Request $request          Incoming HTTP request.
	 * @param string  $current_password Current password confirmation.
	 * @return void
	 * @since 1.0.0
	 */
	public function disable_two_factor(
		Request $request,
		string $current_password
	): void {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to manage account security.', 'peakurl' ),
		);

		$user_row = $this->confirm_current_password(
			$this->users_api->get_user( (string) $user['id'] ),
			$current_password,
			__( 'Current password is required to disable two-factor authentication.', 'peakurl' ),
		);
		$this->validate_two_factor( $user_row );

		$this->db->query(
			'UPDATE users
            SET two_factor_enabled = 0,
                two_factor_secret = NULL,
                two_factor_pending_secret = NULL,
                backup_codes_json = NULL,
                backup_codes_generated_at = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'updated_at' => Date::now(),
				'id'         => $user_row['id'],
			),
		);
	}

	/**
	 * Regenerate backup codes for the authenticated user.
	 *
	 * @param Request $request          Incoming HTTP request.
	 * @param string  $current_password Current password confirmation.
	 * @return array<int, string> New backup codes.
	 * @since 1.0.0
	 */
	public function regenerate_backup_codes(
		Request $request,
		string $current_password
	): array {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to manage account security.', 'peakurl' ),
		);

		$user_row = $this->confirm_current_password(
			$this->users_api->get_user( (string) $user['id'] ),
			$current_password,
			__( 'Current password is required to regenerate backup codes.', 'peakurl' ),
		);
		$this->validate_two_factor( $user_row );

		return $this->credentials->replace_backup_codes( (string) $user_row['id'] );
	}

	/**
	 * Return backup codes for the authenticated user.
	 *
	 * @param Request $request          Incoming HTTP request.
	 * @param string  $current_password Current password confirmation.
	 * @return array<int, string> Backup codes.
	 * @since 1.0.6
	 */
	public function get_backup_codes(
		Request $request,
		string $current_password
	): array {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to manage account security.', 'peakurl' ),
		);

		$user_row = $this->confirm_current_password(
			$this->users_api->get_user( (string) $user['id'] ),
			$current_password,
			__( 'Current password is required to download backup codes.', 'peakurl' ),
		);
		$this->validate_two_factor( $user_row );

		return $this->credentials->list_backup_codes( (string) $user_row['id'] );
	}

	/**
	 * Revoke a specific user session.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Session row ID.
	 * @return bool True if revoked.
	 * @since 1.0.0
	 */
	public function revoke_session( Request $request, string $id ): bool {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to manage account security.', 'peakurl' ),
		);
		$this->prune_stale_sessions();

		$deleted = $this->db->query(
			'DELETE FROM sessions
            WHERE id = :id
            AND user_id = :user_id
            AND last_active_at >= :active_since',
			array(
				'id'           => $id,
				'user_id'      => $user['id'],
				'active_since' => $this->session_active_since(),
			),
		);

		return $deleted > 0;
	}

	/**
	 * Revoke all user sessions except the active one.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Number of sessions revoked.
	 *
	 * @throws ApiException When current session is unknown.
	 * @since 1.0.0
	 */
	public function revoke_other_sessions( Request $request ): int {
		$user = $this->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to manage account security.', 'peakurl' ),
		);
		$this->prune_stale_sessions();
		$current_session = $this->find_session_by_request( $request );

		if ( ! $current_session ) {
			throw new ApiException(
				__( 'PeakURL could not identify the current session.', 'peakurl' ),
				422,
			);
		}

		return $this->db->query(
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

	/**
	 * Return the authenticated user from an API key or session cookie.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>|null Formatted user or null.
	 * @since 1.0.0
	 */
	public function current_user( Request $request ): ?array {
		$api_user = $this->get_api_user( $request );

		if ( $api_user ) {
			return $api_user;
		}

		$session = $this->find_session_by_request( $request );

		if ( $session ) {
			$this->touch_session( (string) $session['id'] );
			$user = $this->users_api->get_user( (string) $session['user_id'] );
			return $user ? $this->format_user( $user, $request ) : null;
		}

		return null;
	}

	/**
	 * Return the current user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 *
	 * @throws ApiException When no valid session or API key exists.
	 * @since 1.0.0
	 */
	public function get_current_user( Request $request ): array {
		$user = $this->current_user( $request );

		if ( ! $user ) {
			throw new ApiException( __( 'Authentication required.', 'peakurl' ), 401 );
		}

		return $user;
	}

	/**
	 * Return the current user and verify admin capabilities.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Authenticated admin user.
	 *
	 * @throws ApiException When unauthorized.
	 * @since 1.0.0
	 */
	public function get_admin_user( Request $request ): array {
		$user = $this->get_current_user( $request );

		if ( ! $this->roles->has_capability( $user, 'manage_options' ) ) {
			throw new ApiException(
				__( 'Administrator privileges required.', 'peakurl' ),
				403,
			);
		}

		return $user;
	}

	/**
	 * Authenticate a request by its API key header.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>|null Formatted user or null if no valid key.
	 * @since 1.0.0
	 */
	private function get_api_user( Request $request ): ?array {
		$authorization = trim(
			(string) $request->get_header( 'Authorization', '' ),
		);

		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
			return null;
		}

		$token = trim( (string) ( $matches[1] ?? '' ) );

		if ( '' === $token ) {
			return null;
		}

		$row = $this->db->get_row(
			'SELECT u.*
            FROM api_keys k
            INNER JOIN users u ON u.id = k.user_id
            WHERE k.key_hash = :key_hash
            LIMIT 1',
			array( 'key_hash' => $this->credentials->hash_api_key( $token ) ),
		);

		return $row ? $this->format_user( $row ) : null;
	}

	/**
	 * Look up a session row by the session cookie on the request.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>|null Session row or null.
	 * @since 1.0.0
	 */
	public function find_session_by_request( Request $request ): ?array {
		$this->prune_stale_sessions();

		$cookie_name = (string) ( $this->config[ Constants::SESSION_COOKIE_NAME ] ?? '' );
		$token       = $this->crypto->verify_session_token(
			trim( (string) $request->get_cookie( $cookie_name, '' ) )
		);

		if ( null === $token || '' === $token ) {
			return null;
		}

		return $this->db->get_row(
			'SELECT * FROM sessions
            WHERE token_hash = :token_hash
            AND revoked_at IS NULL
            AND last_active_at >= :active_since
			LIMIT 1',
			array(
				'token_hash'   => Credentials::hash_token( $token ),
				'active_since' => $this->session_active_since(),
			),
		);
	}

	/**
	 * Create a new session row and set the session cookie.
	 *
	 * @param Request $request  Incoming HTTP request.
	 * @param string  $user_id  User row ID.
	 * @param bool    $remember Whether to issue a persistent 30-day cookie (true) or transient (false).
	 * @since 1.0.0
	 */
	public function create_session_for_user(
		Request $request,
		string $user_id,
		bool $remember = true
	): void {
		$this->prune_stale_sessions();

		if ( ! $this->crypto->is_configured() ) {
			$runtime_path = dirname( __DIR__, 2 );
			$this->crypto->persist_auth_keys( $runtime_path );
			$this->config = RuntimeConfig::bootstrap( $runtime_path );
			$this->crypto = new Crypto( $this->config );
		}

		$this->delete_current_session( $request );
		$this->delete_matching_browser_sessions( $request, $user_id );

		$raw_token = bin2hex( random_bytes( 32 ) );
		$metadata  = Visitor::parse_user_agent( $request->get_user_agent() );
		$now       = Date::now();
		$row       = array(
			'id'               => Str::random_id(),
			'user_id'          => $user_id,
			'token_hash'       => Credentials::hash_token( $raw_token ),
			'user_agent'       => $request->get_user_agent(),
			'ip_address'       => $request->get_ip_address(),
			'browser'          => $metadata['browser'],
			'operating_system' => $metadata['os'],
			'device'           => $metadata['device'],
			'created_at'       => $now,
			'last_active_at'   => $now,
		);

		$this->db->insert( 'sessions', $row );

		$cookie_options = array();

		if ( $remember ) {
			$lifetime                  = (int) ( $this->config[ Constants::SESSION_LIFETIME ] ?? Constants::DEFAULT_SESSION_LIFETIME );
			$cookie_options['max-age'] = $lifetime;
			$cookie_options['expires'] = gmdate(
				'D, d M Y H:i:s T',
				time() + $lifetime,
			);
		}

		$request->queue_cookie(
			(string) $this->config[ Constants::SESSION_COOKIE_NAME ],
			$this->crypto->sign_session_token( $raw_token ),
			Security::session_cookie_options(
				$this->config,
				$request,
				$cookie_options
			),
		);
	}

	/**
	 * Remove the current browser session before issuing a replacement token.
	 *
	 * @param Request $request Incoming request that may carry a session cookie.
	 * @return void
	 * @since 1.3.0
	 */
	private function delete_current_session( Request $request ): void {
		$current_session = $this->find_session_by_request( $request );

		if ( ! $current_session ) {
			return;
		}

		$this->db->delete(
			'sessions',
			array(
				'id' => $current_session['id'],
			),
		);
	}

	/**
	 * Remove prior active records for the same browser fingerprint.
	 *
	 * @param Request $request Incoming request used to identify the browser.
	 * @param string  $user_id Authenticated user ID.
	 * @return void
	 * @since 1.3.0
	 */
	private function delete_matching_browser_sessions(
		Request $request,
		string $user_id
	): void {
		$user_agent = trim( (string) $request->get_user_agent() );
		$ip_address = trim( (string) $request->get_ip_address() );

		if ( '' === $user_agent || '' === $ip_address ) {
			return;
		}

		$this->db->query(
			'DELETE FROM sessions
			WHERE user_id = :user_id
			AND revoked_at IS NULL
			AND last_active_at >= :active_since
			AND COALESCE(user_agent, \'\') = :user_agent
			AND COALESCE(ip_address, \'\') = :ip_address',
			array(
				'user_id'      => $user_id,
				'active_since' => $this->session_active_since(),
				'user_agent'   => $user_agent,
				'ip_address'   => $ip_address,
			),
		);
	}

	/**
	 * Bump the `last_active_at` timestamp on a session.
	 *
	 * @param string $session_id Session row ID.
	 * @return void
	 * @since 1.0.0
	 */
	private function touch_session( string $session_id ): void {
		$this->db->update(
			'sessions',
			array(
				'last_active_at' => Date::now(),
			),
			array(
				'id' => $session_id,
			),
		);
	}

	/**
	 * List active sessions for a user, marking the current one.
	 *
	 * @param string  $user_id          User row ID.
	 * @param Request $request          Incoming request (to identify the current session).
	 * @param bool    $include_location Whether to include GeoIP location details.
	 * @return array<int, array<string, mixed>> Session list.
	 * @since 1.0.0
	 */
	public function list_user_sessions(
		string $user_id,
		Request $request,
		bool $include_location = false
	): array {
		$this->prune_stale_sessions();

		$current_session = $this->find_session_by_request( $request );
		$rows            = $this->db->get_results(
			'SELECT *
			FROM sessions
			WHERE user_id = :user_id
			AND revoked_at IS NULL
			AND last_active_at >= :active_since
			ORDER BY last_active_at DESC',
			array(
				'user_id'      => $user_id,
				'active_since' => $this->session_active_since(),
			),
		);

		return array_map(
			fn( array $row ): array => $this->format_session(
				$row,
				$current_session,
				$include_location,
			),
			$rows,
		);
	}

	/**
	 * Format a session row for API responses.
	 *
	 * @param array<string, mixed>      $row              Raw session row.
	 * @param array<string, mixed>|null $current_session  Current session row.
	 * @param bool                      $include_location Whether to include GeoIP details.
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	private function format_session(
		array $row,
		?array $current_session,
		bool $include_location
	): array {
		$ip_address = (string) ( $row['ip_address'] ?? '' );
		$session    = array(
			'id'           => (string) $row['id'],
			'device'       => (string) ( $row['device'] ?? '' ),
			'browser'      => (string) ( $row['browser'] ?? '' ),
			'os'           => (string) ( $row['operating_system'] ?? '' ),
			'ipAddress'    => $ip_address,
			'lastActiveAt' => Date::to_iso( (string) $row['last_active_at'] ),
			'createdAt'    => Date::to_iso( (string) $row['created_at'] ),
			'revokedAt'    => null,
			'isCurrent'    => $current_session
				? $row['id'] === $current_session['id']
				: false,
		);

		if ( $include_location ) {
			$session['location'] = $this->get_session_location( $ip_address );
		}

		return $session;
	}

	/**
	 * Resolve GeoIP details for a session IP address.
	 *
	 * @param string $ip_address Session IP address.
	 * @return array<string, string|bool|null>
	 * @since 1.1.0
	 */
	private function get_session_location( string $ip_address ): array {
		$is_public = Security::is_public_ip_address( $ip_address );
		$location  = ( $is_public && $this->geoip )
			? $this->geoip->lookup_location( $ip_address )
			: array(
				'country_code' => null,
				'country_name' => null,
				'city_name'    => null,
			);

		return array(
			'city'        => $this->normalize_location_value(
				$location['city_name'] ?? null,
			),
			'country'     => $this->normalize_location_value(
				$location['country_name'] ?? null,
			),
			'countryCode' => $this->normalize_location_value(
				$location['country_code'] ?? null,
			),
			'isPublic'    => $is_public,
		);
	}

	/**
	 * Normalize optional session location strings.
	 *
	 * @param mixed $value Raw location value.
	 * @return string|null
	 * @since 1.1.0
	 */
	private function normalize_location_value( $value ): ?string {
		$value = trim( (string) $value );

		return '' !== $value ? $value : null;
	}

	/**
	 * Delete expired sessions and leftover revoked rows once per request.
	 *
	 * @return void
	 * @since 1.0.3
	 */
	public function prune_stale_sessions(): void {
		static $pruned = false;

		if ( $pruned ) {
			return;
		}

		$this->db->query(
			'DELETE FROM sessions
			WHERE revoked_at IS NOT NULL
			OR last_active_at < :active_since',
			array(
				'active_since' => $this->session_active_since(),
			),
		);

		$pruned = true;
	}

	/**
	 * Calculate the earliest valid session creation timestamp.
	 *
	 * @return string MySQL datetime marking the session TTL boundary.
	 * @since 1.0.0
	 */
	public function session_active_since(): string {
		return gmdate(
			'Y-m-d H:i:s',
			time() - max(
				0,
				(int) ( $this->config[ Constants::SESSION_LIFETIME ] ?? Constants::DEFAULT_SESSION_LIFETIME ),
			),
		);
	}

	/**
	 * Format a raw user database row into an API-ready user array.
	 *
	 * @param array<string, mixed>|null $row     Raw user row from the database.
	 * @param Request|null              $request Optional request for session context.
	 * @return array<string, mixed> Formatted user profile.
	 * @since 1.0.0
	 */
	public function format_user( ?array $row, ?Request $request = null ): array {
		if ( ! $row ) {
			return array();
		}

		$api_keys = array();

		if (
			$request &&
			$this->roles->has_capability( $row, 'manage_api_keys' )
		) {
			$api_keys = $this->credentials->list_api_keys( (string) $row['id'] );
		}

		return array(
			'id'              => (string) $row['id'],
			'firstName'       => (string) ( $row['first_name'] ?? '' ),
			'lastName'        => (string) ( $row['last_name'] ?? '' ),
			'displayName'     => ( $row['display_name'] ?? null ) ? (string) $row['display_name'] : null,
			'username'        => (string) ( $row['username'] ?? '' ),
			'email'           => (string) ( $row['email'] ?? '' ),
			'phoneNumber'     => (string) ( $row['phone_number'] ?? '' ),
			'company'         => (string) ( $row['company'] ?? '' ),
			'jobTitle'        => (string) ( $row['job_title'] ?? '' ),
			'bio'             => (string) ( $row['bio'] ?? '' ),
			'role'            => (string) ( $row['role'] ?? 'editor' ),
			'capabilities'    => $this->roles->capabilities_for_role(
				(string) ( $row['role'] ?? 'editor' ),
			),
			'isEmailVerified' => ! empty( $row['is_email_verified'] ),
			'emailVerifiedAt' => $row['email_verified_at']
				? Date::to_iso( (string) $row['email_verified_at'] )
				: null,
			'apiKey'          => null,
			'apiKeys'         => $api_keys,
			'createdAt'       => Date::to_iso( (string) $row['created_at'] ),
			'updatedAt'       => Date::to_iso( (string) $row['updated_at'] ),
			'security'        => $request
				? array(
					'sessions' => $this->list_user_sessions(
						(string) $row['id'],
						$request,
					),
				)
				: null,
		);
	}
}
