<?php
/**
 * PeakURL mail transport and delivery service.
 *
 * Handles password-reset email delivery plus dashboard-managed mail
 * transport settings for PHP mail() and SMTP.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailer_Exception;
use PHPMailer\PHPMailer\PHPMailer;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Mail transport and delivery service.
 *
 * @since 1.0.0
 */
class Mailer_Service {

	/**
	 * Runtime configuration values.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Settings API for site-level metadata lookups.
	 *
	 * @var Settings_API
	 * @since 1.0.0
	 */
	private Settings_API $settings_api;

	/**
	 * Create a new mailer service.
	 *
	 * @param array<string, mixed> $config       Runtime configuration.
	 * @param Settings_API         $settings_api Settings API helper.
	 * @since 1.0.0
	 */
	public function __construct( array $config, Settings_API $settings_api ) {
		$this->config       = $config;
		$this->settings_api = $settings_api;
	}

	/**
	 * Return the current mail configuration status for the dashboard.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function get_status(): array {
		$configuration = $this->get_configuration_target();
		$capability    = $this->get_dashboard_capability( $configuration );

		return array(
			'driver'                 => $this->get_mail_driver(),
			'fromEmail'              => $this->get_from_email(),
			'fromName'               => $this->get_from_name(),
			'smtpHost'               => trim(
				(string) ( $this->config['PEAKURL_SMTP_HOST'] ?? '' )
			),
			'smtpPort'               => (int) ( $this->config['PEAKURL_SMTP_PORT'] ?? 587 ),
			'smtpEncryption'         => '' === $this->get_smtp_encryption()
				? 'none'
				: $this->get_smtp_encryption(),
			'smtpAuth'               => ! empty( $this->config['PEAKURL_SMTP_AUTH'] ),
			'smtpUsername'           => trim(
				(string) ( $this->config['PEAKURL_SMTP_USERNAME'] ?? '' )
			),
			'smtpPasswordConfigured' => '' !== trim(
				(string) ( $this->config['PEAKURL_SMTP_PASSWORD'] ?? '' )
			),
			'smtpPasswordHint'       => $this->mask_secret(
				trim( (string) ( $this->config['PEAKURL_SMTP_PASSWORD'] ?? '' ) )
			),
			'configurationLabel'     => $configuration['label'],
			'configurationPath'      => $configuration['path'],
			'canManageFromDashboard' => $capability['allowed'],
			'manageDisabledReason'   => $capability['reason'],
		);
	}

	/**
	 * Persist dashboard-managed mail settings into the active runtime target.
	 *
	 * @param string               $app_path Absolute path to the app directory.
	 * @param array<string, mixed> $config   Current runtime configuration.
	 * @param array<string, mixed> $input    Submitted mail settings.
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the settings are invalid or cannot be saved.
	 * @since 1.0.0
	 */
	public function save_configuration(
		string $app_path,
		array $config,
		array $input
	): array {
		$configuration = $this->get_configuration_target();
		$capability    = $this->get_dashboard_capability( $configuration );

		if ( ! $capability['allowed'] ) {
			throw new RuntimeException( (string) $capability['reason'] );
		}

		$current_driver     = trim(
			(string) ( $config['PEAKURL_MAIL_DRIVER'] ?? 'mail' )
		);
		$current_smtp_host  = trim(
			(string) ( $config['PEAKURL_SMTP_HOST'] ?? '' )
		);
		$current_smtp_port  = (string) ( $config['PEAKURL_SMTP_PORT'] ?? 587 );
		$current_encryption = trim(
			(string) ( $config['PEAKURL_SMTP_ENCRYPTION'] ?? 'tls' )
		);
		$current_auth       = ! empty( $config['PEAKURL_SMTP_AUTH'] );
		$current_username   = trim(
			(string) ( $config['PEAKURL_SMTP_USERNAME'] ?? '' )
		);
		$current_password   = trim(
			(string) ( $config['PEAKURL_SMTP_PASSWORD'] ?? '' )
		);

		$driver = $this->normalize_driver(
			(string) ( $input['driver'] ?? $current_driver )
		);
		$values = array(
			'PEAKURL_MAIL_DRIVER'     => $driver,
			'PEAKURL_SMTP_HOST'       => trim(
				(string) ( $input['smtpHost'] ?? $current_smtp_host )
			),
			'PEAKURL_SMTP_PORT'       => (string) (int) ( $input['smtpPort'] ?? $current_smtp_port ),
			'PEAKURL_SMTP_ENCRYPTION' => $this->normalize_encryption(
				(string) ( $input['smtpEncryption'] ?? $current_encryption )
			),
			'PEAKURL_SMTP_AUTH'       => $this->normalize_auth_flag(
				$input['smtpAuth'] ?? $current_auth
			)
				? 'true'
				: 'false',
			'PEAKURL_SMTP_USERNAME'   => trim(
				(string) ( $input['smtpUsername'] ?? $current_username )
			),
			'PEAKURL_SMTP_PASSWORD'   => trim(
				(string) ( $input['smtpPassword'] ?? '' )
			),
		);

		if ( '' === $values['PEAKURL_SMTP_PASSWORD'] ) {
			$values['PEAKURL_SMTP_PASSWORD'] = $current_password;
		}

		$this->validate_configuration( $values );

		$runtime_values = Setup_Config_Service::config_values_from_runtime_config( $config );

		foreach ( $values as $key => $value ) {
			$runtime_values[ $key ] = $value;
		}

		$this->write_runtime_configuration( $app_path, $runtime_values );

		return ( new self(
			$this->merge_config_values( $config, $runtime_values ),
			$this->settings_api
		) )->get_status();
	}

	/**
	 * Send a password-reset email.
	 *
	 * @param array<string, mixed> $user  User database row.
	 * @param string               $token Password-reset token.
	 * @return void
	 *
	 * @throws RuntimeException When email delivery fails.
	 * @since 1.0.0
	 */
	public function send_password_reset_email( array $user, string $token ): void {
		$email = strtolower( trim( (string) ( $user['email'] ?? '' ) ) );

		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new RuntimeException(
				'PeakURL could not send the password reset email because the account email address is invalid.',
			);
		}

		$site_name = $this->get_site_name();
		$reset_url = rtrim( (string) ( $this->config['SITE_URL'] ?? '' ), '/' ) .
			'/reset-password/' .
			rawurlencode( trim( $token ) );
		$full_name = trim(
			(string) ( $user['first_name'] ?? '' ) . ' ' . (string) ( $user['last_name'] ?? '' )
		);
		$recipient = '' !== $full_name
			? $full_name
			: (string) ( $user['username'] ?? 'there' );
		$subject   = sprintf( 'Reset your %s password', $site_name );
		$html_body =
			'<p>Hello ' . htmlspecialchars( $recipient, ENT_QUOTES, 'UTF-8' ) . ',</p>' .
			'<p>We received a request to reset your password for ' . htmlspecialchars( $site_name, ENT_QUOTES, 'UTF-8' ) . '.</p>' .
			'<p><a href="' . htmlspecialchars( $reset_url, ENT_QUOTES, 'UTF-8' ) . '">Reset your password</a></p>' .
			'<p>This link expires in 1 hour. If you did not request this change, you can ignore this email.</p>';
		$text_body =
			"Hello {$recipient},\n\n" .
			"We received a request to reset your password for {$site_name}.\n\n" .
			"Reset your password: {$reset_url}\n\n" .
			"This link expires in 1 hour. If you did not request this change, you can ignore this email.\n";

		$this->send_message( $email, $recipient, $subject, $html_body, $text_body );
	}

	/**
	 * Send a fully composed email using the active transport.
	 *
	 * @param string $to_email  Recipient email address.
	 * @param string $to_name   Recipient display name.
	 * @param string $subject   Subject line.
	 * @param string $html_body HTML body.
	 * @param string $text_body Plain-text body.
	 * @return void
	 *
	 * @throws RuntimeException When delivery fails.
	 * @since 1.0.0
	 */
	private function send_message(
		string $to_email,
		string $to_name,
		string $subject,
		string $html_body,
		string $text_body
	): void {
		$mailer = new PHPMailer( true );

		try {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public properties use upstream casing.
			$mailer->CharSet = PHPMailer::CHARSET_UTF8;
			$mailer->Timeout = 15;
			$mailer->setFrom( $this->get_from_email(), $this->get_from_name() );
			$mailer->addAddress( $to_email, $to_name );
			$mailer->Subject = $subject;
			$mailer->isHTML( true );
			$mailer->Body    = $html_body;
			$mailer->AltBody = $text_body;

			if ( 'smtp' === $this->get_mail_driver() ) {
				$mailer->isSMTP();
				$mailer->Host        = trim(
					(string) ( $this->config['PEAKURL_SMTP_HOST'] ?? '' )
				);
				$mailer->Port        = (int) ( $this->config['PEAKURL_SMTP_PORT'] ?? 587 );
				$mailer->SMTPAuth    = ! empty( $this->config['PEAKURL_SMTP_AUTH'] );
				$encryption          = $this->normalize_encryption(
					(string) ( $this->config['PEAKURL_SMTP_ENCRYPTION'] ?? 'tls' )
				);
				$mailer->SMTPAutoTLS = 'tls' === $encryption;

				if ( 'ssl' === $encryption ) {
					$mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
				} elseif ( 'tls' === $encryption ) {
					$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				}

				if ( $mailer->SMTPAuth ) {
					$mailer->Username = trim(
						(string) ( $this->config['PEAKURL_SMTP_USERNAME'] ?? '' )
					);
					$mailer->Password = trim(
						(string) ( $this->config['PEAKURL_SMTP_PASSWORD'] ?? '' )
					);
				}
			} else {
				$mailer->isMail();
			}
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			$mailer->send();
		} catch ( PHPMailer_Exception $exception ) {
			throw new RuntimeException(
				'PeakURL could not send the email. ' . $exception->getMessage(),
				0,
				$exception,
			);
		}
	}

	/**
	 * Validate dashboard-submitted mail settings.
	 *
	 * @param array<string, string> $values Normalized mail settings.
	 * @return void
	 *
	 * @throws RuntimeException When the configuration is invalid.
	 * @since 1.0.0
	 */
	private function validate_configuration( array $values ): void {
		if ( 'smtp' !== $values['PEAKURL_MAIL_DRIVER'] ) {
			return;
		}

		if ( '' === $values['PEAKURL_SMTP_HOST'] ) {
			throw new RuntimeException( 'SMTP host is required when SMTP is enabled.' );
		}

		$smtp_port = (int) $values['PEAKURL_SMTP_PORT'];

		if ( $smtp_port < 1 || $smtp_port > 65535 ) {
			throw new RuntimeException(
				'SMTP port must be between 1 and 65535.',
			);
		}

		if ( 'true' !== $values['PEAKURL_SMTP_AUTH'] ) {
			return;
		}

		if ( '' === $values['PEAKURL_SMTP_USERNAME'] ) {
			throw new RuntimeException(
				'SMTP username is required when SMTP authentication is enabled.',
			);
		}

		if ( '' === $values['PEAKURL_SMTP_PASSWORD'] ) {
			throw new RuntimeException(
				'SMTP password is required when SMTP authentication is enabled.',
			);
		}
	}

	/**
	 * Determine whether PeakURL is running from the source checkout.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_source_checkout(): bool {
		return file_exists( ABSPATH . 'package.json' ) || is_dir( ABSPATH . '.git' );
	}

	/**
	 * Return the active runtime configuration target details.
	 *
	 * @return array{label: string, path: string}
	 * @since 1.0.0
	 */
	private function get_configuration_target(): array {
		if ( $this->is_source_checkout() ) {
			return array(
				'label' => 'app/.env',
				'path'  => ABSPATH . 'app/.env',
			);
		}

		return array(
			'label' => 'config.php',
			'path'  => Setup_Config_Service::get_config_path( ABSPATH . 'app' ),
		);
	}

	/**
	 * Determine whether the dashboard can update the mail configuration.
	 *
	 * @param array{label: string, path: string} $configuration Current writable target.
	 * @return array{allowed: bool, reason: string|null}
	 * @since 1.0.0
	 */
	private function get_dashboard_capability( array $configuration ): array {
		if (
			file_exists( $configuration['path'] ) &&
			! is_writable( $configuration['path'] )
		) {
			return array(
				'allowed' => false,
				'reason'  => $configuration['label'] . ' is not writable.',
			);
		}

		if (
			! file_exists( $configuration['path'] ) &&
			! is_writable( dirname( $configuration['path'] ) )
		) {
			return array(
				'allowed' => false,
				'reason'  => 'The runtime configuration directory is not writable.',
			);
		}

		return array(
			'allowed' => true,
			'reason'  => null,
		);
	}

	/**
	 * Persist the normalized runtime values into .env or config.php.
	 *
	 * @param string                $app_path Absolute path to the app directory.
	 * @param array<string, string> $values   Flat runtime values.
	 * @return void
	 * @since 1.0.0
	 */
	private function write_runtime_configuration(
		string $app_path,
		array $values
	): void {
		if ( $this->is_source_checkout() ) {
			Setup_Config_Service::write_env_overrides(
				$app_path . '/.env',
				array(
					'PEAKURL_MAIL_DRIVER'     => $values['PEAKURL_MAIL_DRIVER'],
					'PEAKURL_SMTP_HOST'       => $values['PEAKURL_SMTP_HOST'],
					'PEAKURL_SMTP_PORT'       => $values['PEAKURL_SMTP_PORT'],
					'PEAKURL_SMTP_ENCRYPTION' => $values['PEAKURL_SMTP_ENCRYPTION'],
					'PEAKURL_SMTP_AUTH'       => $values['PEAKURL_SMTP_AUTH'],
					'PEAKURL_SMTP_USERNAME'   => $values['PEAKURL_SMTP_USERNAME'],
					'PEAKURL_SMTP_PASSWORD'   => $values['PEAKURL_SMTP_PASSWORD'],
				),
				'PeakURL could not update app/.env with the mail settings.',
				'# PeakURL local development overrides'
			);
			return;
		}

		Setup_Config_Service::write_config_file( $app_path, $values );
	}

	/**
	 * Merge normalized runtime values back into the config array shape.
	 *
	 * @param array<string, mixed>  $config Original runtime config.
	 * @param array<string, string> $values Updated config values.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function merge_config_values( array $config, array $values ): array {
		$config['PEAKURL_MAIL_DRIVER']     = $values['PEAKURL_MAIL_DRIVER'];
		$config['PEAKURL_SMTP_HOST']       = $values['PEAKURL_SMTP_HOST'];
		$config['PEAKURL_SMTP_PORT']       = (int) $values['PEAKURL_SMTP_PORT'];
		$config['PEAKURL_SMTP_ENCRYPTION'] = $values['PEAKURL_SMTP_ENCRYPTION'];
		$config['PEAKURL_SMTP_AUTH']       = 'true' === $values['PEAKURL_SMTP_AUTH'];
		$config['PEAKURL_SMTP_USERNAME']   = $values['PEAKURL_SMTP_USERNAME'];
		$config['PEAKURL_SMTP_PASSWORD']   = $values['PEAKURL_SMTP_PASSWORD'];

		return $config;
	}

	/**
	 * Normalize the configured mail driver.
	 *
	 * @param string $value Raw driver value.
	 * @return string
	 * @since 1.0.0
	 */
	private function normalize_driver( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( in_array( $value, array( 'mail', 'smtp' ), true ) ) {
			return $value;
		}

		return 'mail';
	}

	/**
	 * Normalize the SMTP encryption mode.
	 *
	 * @param string $value Raw encryption value.
	 * @return string
	 * @since 1.0.0
	 */
	private function normalize_encryption( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( in_array( $value, array( '', 'none', 'ssl', 'tls' ), true ) ) {
			return 'none' === $value ? '' : $value;
		}

		return 'tls';
	}

	/**
	 * Normalize a truthy/falsy value into a boolean flag.
	 *
	 * @param mixed $value Raw input value.
	 * @return bool
	 * @since 1.0.0
	 */
	private function normalize_auth_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array(
			strtolower( trim( (string) $value ) ),
			array( '1', 'true', 'yes', 'on' ),
			true,
		);
	}

	/**
	 * Get the currently configured mail driver.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_mail_driver(): string {
		return $this->normalize_driver(
			(string) ( $this->config['PEAKURL_MAIL_DRIVER'] ?? 'mail' )
		);
	}

	/**
	 * Get the currently configured SMTP encryption mode.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_smtp_encryption(): string {
		return $this->normalize_encryption(
			(string) ( $this->config['PEAKURL_SMTP_ENCRYPTION'] ?? 'tls' )
		);
	}

	/**
	 * Resolve the outgoing from email address.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_from_email(): string {
		$host = (string) parse_url(
			(string) ( $this->config['SITE_URL'] ?? '' ),
			PHP_URL_HOST
		);
		$host = preg_replace( '/^www\./i', '', strtolower( $host ) ?? '' );

		if ( ! is_string( $host ) || '' === trim( $host ) ) {
			$host = 'localhost.localdomain';
		}

		if ( 'localhost' === $host ) {
			$host = 'localhost.localdomain';
		}

		return 'peakurl@' . $host;
	}

	/**
	 * Resolve the outgoing from display name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_from_name(): string {
		return 'PeakURL';
	}

	/**
	 * Get the human-readable site name for outgoing mail.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_site_name(): string {
		$site_name = trim( (string) $this->settings_api->get_option( 'site_name' ) );

		return '' !== $site_name ? $site_name : 'PeakURL';
	}

	/**
	 * Mask a stored secret so the dashboard never returns the raw value.
	 *
	 * @param string $value Raw secret.
	 * @return string|null
	 * @since 1.0.0
	 */
	private function mask_secret( string $value ): ?string {
		if ( '' === $value ) {
			return null;
		}

		if ( strlen( $value ) <= 4 ) {
			return str_repeat( '•', strlen( $value ) );
		}

		return substr( $value, 0, 2 ) .
			str_repeat( '•', max( 4, strlen( $value ) - 4 ) ) .
			substr( $value, -2 );
	}
}
