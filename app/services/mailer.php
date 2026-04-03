<?php
/**
 * PeakURL mail transport and delivery service.
 *
 * Handles dashboard-managed mail transport settings plus message
 * delivery through PHP mail() and SMTP.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PHPMailer\PHPMailer\Exception as PHPMailer_Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PeakURL\Api\SettingsApi;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Mail transport and delivery service.
 *
 * @since 1.0.0
 */
class Mailer {

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
	 * @var SettingsApi
	 * @since 1.0.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Crypto helper for stored SMTP credentials.
	 *
	 * @var Crypto
	 * @since 1.0.0
	 */
	private Crypto $crypto_service;

	/**
	 * Create a new mailer service.
	 *
	 * @param array<string, mixed> $config         Runtime configuration.
	 * @param SettingsApi         $settings_api   Settings API helper.
	 * @param Crypto       $crypto_service Crypto helper.
	 * @since 1.0.0
	 */
	public function __construct(
		array $config,
		SettingsApi $settings_api,
		Crypto $crypto_service
	) {
		$this->config         = $config;
		$this->settings_api   = $settings_api;
		$this->crypto_service = $crypto_service;
	}

	/**
	 * Return the current mail configuration status for the dashboard.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function get_status(): array {
		$settings      = $this->get_runtime_mail_settings();
		$configuration = $this->get_configuration_target();
		$capability    = $this->get_dashboard_capability();

		return array(
			'driver'                 => $settings['driver'],
			'fromEmail'              => $this->get_from_email( $settings ),
			'configuredFromEmail'    => $settings['fromEmail'],
			'fromName'               => $this->get_from_name( $settings ),
			'configuredFromName'     => $settings['fromName'],
			'smtpHost'               => $settings['smtpHost'],
			'smtpPort'               => $settings['smtpPort'],
			'smtpEncryption'         => '' === $settings['smtpEncryption']
				? 'none'
				: $settings['smtpEncryption'],
			'smtpAuth'               => $settings['smtpAuth'],
			'smtpUsername'           => $settings['smtpUsername'],
			'smtpPasswordConfigured' => '' !== $settings['smtpPassword'],
			'smtpPasswordHint'       => $this->mask_secret( $settings['smtpPassword'] ),
			'configurationLabel'     => $configuration['label'],
			'configurationPath'      => $configuration['path'],
			'canManageFromDashboard' => $capability['allowed'],
			'manageDisabledReason'   => $capability['reason'],
		);
	}

	/**
	 * Persist dashboard-managed mail settings into the settings table.
	 *
	 * @param string               $app_path Absolute path to the app directory.
	 * @param array<string, mixed> $config   Current runtime configuration.
	 * @param array<string, mixed> $input    Submitted mail settings.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the settings are invalid or cannot be saved.
	 * @since 1.0.0
	 */
	public function save_configuration(
		string $app_path,
		array $config,
		array $input
	): array {
		$capability = $this->get_dashboard_capability();

		if ( ! $capability['allowed'] ) {
			throw new \RuntimeException( (string) $capability['reason'] );
		}

		$current = $this->get_runtime_mail_settings();

		$driver = $this->normalize_driver(
			(string) ( $input['driver'] ?? $current['driver'] )
		);
		$values = array(
			'driver'         => $driver,
			'fromEmail'      => trim(
				(string) ( $input['fromEmail'] ?? $current['fromEmail'] )
			),
			'fromName'       => trim(
				(string) ( $input['fromName'] ?? $current['fromName'] )
			),
			'smtpHost'       => trim(
				(string) ( $input['smtpHost'] ?? $current['smtpHost'] )
			),
			'smtpPort'       => (string) (int) ( $input['smtpPort'] ?? $current['smtpPort'] ),
			'smtpEncryption' => $this->normalize_encryption(
				(string) ( $input['smtpEncryption'] ?? $current['smtpEncryption'] )
			),
			'smtpAuth'       => $this->normalize_auth_flag(
				$input['smtpAuth'] ?? $current['smtpAuth']
			)
				? 'true'
				: 'false',
			'smtpUsername'   => trim(
				(string) ( $input['smtpUsername'] ?? $current['smtpUsername'] )
			),
			'smtpPassword'   => trim(
				(string) ( $input['smtpPassword'] ?? '' )
			),
		);

		if ( '' === $values['smtpPassword'] ) {
			$values['smtpPassword'] = $current['smtpPassword'];
		}

		$this->validate_configuration( $values );
		$this->persist_settings( $app_path, $config, $values );

		return ( new self(
			$config,
			$this->settings_api,
			$this->crypto_service,
		) )->get_status();
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
	 * @throws \RuntimeException When delivery fails.
	 * @since 1.0.0
	 */
	public function send(
		string $to_email,
		string $to_name,
		string $subject,
		string $html_body,
		string $text_body = ''
	): void {
		$this->send_message( $to_email, $to_name, $subject, $html_body, $text_body );
	}

	/**
	 * Deliver a fully composed email with the active transport.
	 *
	 * @param string $to_email  Recipient email address.
	 * @param string $to_name   Recipient display name.
	 * @param string $subject   Subject line.
	 * @param string $html_body HTML body.
	 * @param string $text_body Plain-text body.
	 * @return void
	 *
	 * @throws \RuntimeException When delivery fails.
	 * @since 1.0.0
	 */
	private function send_message(
		string $to_email,
		string $to_name,
		string $subject,
		string $html_body,
		string $text_body
	): void {
		$mailer   = new PHPMailer( true );
		$settings = $this->get_runtime_mail_settings();

		try {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public properties use upstream casing.
			$mailer->CharSet = PHPMailer::CHARSET_UTF8;
			$mailer->Timeout = 15;
			$mailer->setFrom(
				$this->get_from_email( $settings ),
				$this->get_from_name( $settings ),
			);
			$mailer->addAddress( $to_email, $to_name );
			$mailer->Subject = $subject;
			$mailer->isHTML( true );
			$mailer->Body    = $html_body;
			$mailer->AltBody = $text_body;

			if ( 'smtp' === $settings['driver'] ) {
				$mailer->isSMTP();
				$mailer->Host        = $settings['smtpHost'];
				$mailer->Port        = $settings['smtpPort'];
				$mailer->SMTPAuth    = $settings['smtpAuth'];
				$encryption          = $this->normalize_encryption(
					$settings['smtpEncryption']
				);
				$mailer->SMTPAutoTLS = 'tls' === $encryption;

				if ( 'ssl' === $encryption ) {
					$mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
				} elseif ( 'tls' === $encryption ) {
					$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				}

				if ( $mailer->SMTPAuth ) {
					$mailer->Username = $settings['smtpUsername'];
					$mailer->Password = $settings['smtpPassword'];
				}
			} else {
				$mailer->isMail();
			}
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			$mailer->send();
		} catch ( PHPMailer_Exception $exception ) {
			throw new \RuntimeException(
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
	 * @throws \RuntimeException When the configuration is invalid.
	 * @since 1.0.0
	 */
	private function validate_configuration( array $values ): void {
		if ( strlen( $values['fromName'] ) > 190 ) {
			throw new \RuntimeException(
				'From name must be 190 characters or fewer.',
			);
		}

		if (
			'' !== $values['fromEmail'] &&
			false === filter_var( $values['fromEmail'], FILTER_VALIDATE_EMAIL )
		) {
			throw new \RuntimeException(
				'From email must be a valid email address.',
			);
		}

		if ( 'smtp' !== $values['driver'] ) {
			return;
		}

		if ( '' === $values['smtpHost'] ) {
			throw new \RuntimeException( 'SMTP host is required when SMTP is enabled.' );
		}

		$smtp_port = (int) $values['smtpPort'];

		if ( $smtp_port < 1 || $smtp_port > 65535 ) {
			throw new \RuntimeException(
				'SMTP port must be between 1 and 65535.',
			);
		}

		if ( 'true' !== $values['smtpAuth'] ) {
			return;
		}

		if ( '' === $values['smtpUsername'] ) {
			throw new \RuntimeException(
				'SMTP username is required when SMTP authentication is enabled.',
			);
		}

		if ( '' === $values['smtpPassword'] ) {
			throw new \RuntimeException(
				'SMTP password is required when SMTP authentication is enabled.',
			);
		}
	}

	/**
	 * Return the database-backed configuration target details.
	 *
	 * @return array{label: string, path: string}
	 * @since 1.0.0
	 */
	private function get_configuration_target(): array {
		return array(
			'label' => 'settings table',
			'path'  => 'settings',
		);
	}

	/**
	 * Determine whether the dashboard can update the mail configuration.
	 *
	 * @return array{allowed: bool, reason: string|null}
	 * @since 1.0.0
	 */
	private function get_dashboard_capability(): array {
		if ( ! $this->settings_api->has_table() ) {
			return array(
				'allowed' => false,
				'reason'  => 'The settings table is not available yet.',
			);
		}

		return array(
			'allowed' => true,
			'reason'  => null,
		);
	}

	/**
	 * Persist the normalized runtime values into the settings table.
	 *
	 * @param string               $app_path Absolute path to the app directory.
	 * @param array<string, mixed> $config   Current runtime configuration.
	 * @param array<string, string> $values  Normalized mail settings.
	 * @return void
	 *
	 * @throws \RuntimeException When encrypted settings cannot be persisted.
	 * @since 1.0.0
	 */
	private function persist_settings(
		string $app_path,
		array $config,
		array $values
	): void {
		$updated_at = gmdate( 'Y-m-d H:i:s' );
		$password   = $values['smtpPassword'];

		if ( '' !== $password && ! $this->crypto_service->has_auth_keys() ) {
			$this->crypto_service = new Crypto( $config );
			$this->crypto_service->ensure_persisted_auth_keys( $app_path );
		}

		$this->settings_api->update_option( 'mail_driver', $values['driver'], $updated_at, false );
		$this->settings_api->update_option( 'mail_from_email', $values['fromEmail'], $updated_at, false );
		$this->settings_api->update_option( 'mail_from_name', $values['fromName'], $updated_at, false );
		$this->settings_api->update_option( 'smtp_host', $values['smtpHost'], $updated_at, false );
		$this->settings_api->update_option( 'smtp_port', $values['smtpPort'], $updated_at, false );
		$this->settings_api->update_option( 'smtp_encryption', $values['smtpEncryption'], $updated_at, false );
		$this->settings_api->update_option( 'smtp_auth', $values['smtpAuth'], $updated_at, false );
		$this->settings_api->update_option( 'smtp_username', $values['smtpUsername'], $updated_at, false );
		$this->settings_api->update_option(
			'smtp_password_encrypted',
			'' === $password ? '' : $this->crypto_service->encrypt( $password ),
			$updated_at,
			false,
		);
	}

	/**
	 * Resolve the active mail settings, preferring the settings table.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function get_runtime_mail_settings(): array {
		$options = $this->settings_api->get_options(
			array(
				'mail_driver',
				'mail_from_email',
				'mail_from_name',
				'smtp_host',
				'smtp_port',
				'smtp_encryption',
				'smtp_auth',
				'smtp_username',
				'smtp_password_encrypted',
			),
		);

		return array(
			'driver'         => $this->normalize_driver(
				(string) ( $options['mail_driver'] ?? 'mail' )
			),
			'fromEmail'      => trim(
				(string) ( $options['mail_from_email'] ?? '' )
			),
			'fromName'       => trim(
				(string) ( $options['mail_from_name'] ?? '' )
			),
			'smtpHost'       => trim(
				(string) ( $options['smtp_host'] ?? '' )
			),
			'smtpPort'       => (int) ( $options['smtp_port'] ?? 587 ),
			'smtpEncryption' => $this->normalize_encryption(
				(string) ( $options['smtp_encryption'] ?? 'tls' )
			),
			'smtpAuth'       => $this->normalize_auth_flag(
				$options['smtp_auth'] ?? false
			),
			'smtpUsername'   => trim(
				(string) ( $options['smtp_username'] ?? '' )
			),
			'smtpPassword'   => $this->decrypt_secret_value(
				(string) ( $options['smtp_password_encrypted'] ?? '' )
			),
		);
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
	 * Decrypt a stored secret value with plain-text fallback.
	 *
	 * @param string $value Stored value.
	 * @return string
	 * @since 1.0.0
	 */
	private function decrypt_secret_value( string $value ): string {
		try {
			return $this->crypto_service->decrypt( $value );
		} catch ( \RuntimeException $exception ) {
			return '';
		}
	}

	/**
	 * Resolve the outgoing from email address.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_from_email( array $settings = array() ): string {
		if ( empty( $settings ) ) {
			$settings = $this->get_runtime_mail_settings();
		}

		if ( 'smtp' === (string) ( $settings['driver'] ?? 'mail' ) ) {
			$configured_from_email = trim(
				(string) ( $settings['fromEmail'] ?? '' ),
			);

			if ( false !== filter_var( $configured_from_email, FILTER_VALIDATE_EMAIL ) ) {
				return strtolower( $configured_from_email );
			}

			$smtp_username = trim(
				(string) ( $settings['smtpUsername'] ?? '' ),
			);

			if ( false !== filter_var( $smtp_username, FILTER_VALIDATE_EMAIL ) ) {
				return strtolower( $smtp_username );
			}
		}

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
	private function get_from_name( array $settings = array() ): string {
		if ( empty( $settings ) ) {
			$settings = $this->get_runtime_mail_settings();
		}

		if ( 'smtp' === (string) ( $settings['driver'] ?? 'mail' ) ) {
			$configured_from_name = trim(
				(string) ( $settings['fromName'] ?? '' ),
			);

			if ( '' !== $configured_from_name ) {
				return $configured_from_name;
			}
		}

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
