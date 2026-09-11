<?php
/**
 * CAPTCHA provider settings and verification.
 *
 * @package PeakURL\Services
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Api\SettingsApi;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Captcha — dashboard-managed CAPTCHA protection for public short links.
 *
 * @since 1.2.0
 */
class Captcha {

	/**
	 * Disabled provider token.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private const PROVIDER_NONE = 'none';

	/**
	 * Google reCAPTCHA provider token.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private const PROVIDER_RECAPTCHA = 'recaptcha';

	/**
	 * Cloudflare Turnstile provider token.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private const PROVIDER_TURNSTILE = 'turnstile';

	/**
	 * Google reCAPTCHA v3 action name for public redirects.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private const RECAPTCHA_ACTION = 'peakurl_redirect';

	/**
	 * Minimum Google reCAPTCHA v3 score accepted by default.
	 *
	 * @var float
	 * @since 1.2.0
	 */
	private const RECAPTCHA_SCORE_THRESHOLD = 0.5;

	/**
	 * Fixed dashboard hint for saved provider secret keys.
	 *
	 * Secret keys are never returned to the browser, even partially.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private const SAVED_SECRET_HINT = '••••••';

	/**
	 * Runtime configuration values.
	 *
	 * @var array<string, mixed>
	 * @since 1.2.0
	 */
	private array $config;

	/**
	 * Settings API helper.
	 *
	 * @var SettingsApi
	 * @since 1.2.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Crypto helper for the provider secret key.
	 *
	 * @var Crypto
	 * @since 1.2.0
	 */
	private Crypto $crypto_service;

	/**
	 * Create a new CAPTCHA service.
	 *
	 * @param array<string, mixed> $config         Runtime configuration.
	 * @param SettingsApi          $settings_api   Settings API helper.
	 * @param Crypto               $crypto_service Crypto helper.
	 * @since 1.2.0
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
	 * Return the current dashboard settings payload.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	public function get_status(): array {
		$settings          = $this->get_settings();
		$capability        = $this->get_capability();
		$configured        = $this->is_configured( $settings );
		$secret_configured = '' !== $settings['secretKey'];

		return array(
			'provider'               => $settings['provider'],
			'siteKey'                => $settings['siteKey'],
			'siteKeyConfigured'      => '' !== $settings['siteKey'],
			'siteKeyHint'            => $this->mask_key_hint( $settings['siteKey'] ),
			'secretKeyConfigured'    => $secret_configured,
			'secretKeyHint'          => $secret_configured ? self::SAVED_SECRET_HINT : null,
			'configured'             => $configured,
			'enabled'                => self::PROVIDER_NONE !== $settings['provider'] && $configured,
			'canManageFromDashboard' => $capability['allowed'],
			'manageDisabledReason'   => $capability['reason'],
		);
	}

	/**
	 * Persist dashboard-managed CAPTCHA settings.
	 *
	 * @param string               $app_path Absolute path to the app directory.
	 * @param array<string, mixed> $config   Current runtime configuration.
	 * @param array<string, mixed> $input    Submitted settings payload.
	 * @return array<string, mixed> Fresh dashboard settings payload.
	 *
	 * @throws \RuntimeException When settings are invalid or cannot be saved.
	 * @since 1.2.0
	 */
	public function save_settings(
		string $app_path,
		array $config,
		array $input
	): array {
		$capability = $this->get_capability();

		if ( ! $capability['allowed'] ) {
			throw new \RuntimeException( (string) $capability['reason'] );
		}

		$current       = $this->get_settings();
		$provider      = $this->normalize_provider(
			(string) ( $input['provider'] ?? $current['provider'] ),
		);
		$site_key      = trim( (string) ( $input['siteKey'] ?? $current['siteKey'] ) );
		$secret_key    = trim( (string) ( $input['secretKey'] ?? '' ) );
		$same_provider = $provider === $current['provider'];

		if ( self::PROVIDER_NONE === $provider ) {
			$site_key   = '';
			$secret_key = '';
		} elseif ( '' === $secret_key && $same_provider ) {
			$secret_key = $current['secretKey'];
		}

		$values = array(
			'provider'  => $provider,
			'siteKey'   => $site_key,
			'secretKey' => $secret_key,
		);

		$this->validate_settings( $values );
		$this->persist_settings( $app_path, $config, $values );

		return ( new self(
			$config,
			$this->settings_api,
			$this->crypto_service,
		) )->get_status();
	}

	/**
	 * Return the public widget configuration when CAPTCHA protection is usable.
	 *
	 * @return array<string, string>|null
	 * @since 1.2.0
	 */
	public function get_challenge(): ?array {
		$settings = $this->get_settings();

		if ( ! $this->is_configured( $settings ) ) {
			return null;
		}

		if ( self::PROVIDER_RECAPTCHA === $settings['provider'] ) {
			return array(
				'provider'      => self::PROVIDER_RECAPTCHA,
				'siteKey'       => $settings['siteKey'],
				'action'        => self::RECAPTCHA_ACTION,
				'responseField' => 'g-recaptcha-response',
				'scriptUrl'     => 'https://www.google.com/recaptcha/api.js',
			);
		}

		if ( self::PROVIDER_TURNSTILE === $settings['provider'] ) {
			return array(
				'provider'      => self::PROVIDER_TURNSTILE,
				'siteKey'       => $settings['siteKey'],
				'responseField' => 'cf-turnstile-response',
				'scriptUrl'     => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
			);
		}

		return null;
	}

	/**
	 * Verify a submitted provider token with the active provider.
	 *
	 * @param string $token      Submitted challenge token.
	 * @param string $ip_address Visitor IP address, when available.
	 * @return bool True when the provider confirms the token.
	 * @since 1.2.0
	 */
	public function verify_token( string $token, string $ip_address = '' ): bool {
		$token    = trim( $token );
		$settings = $this->get_settings();

		if ( '' === $token || ! $this->is_configured( $settings ) ) {
			return false;
		}

		$endpoint = $this->get_verify_endpoint( $settings['provider'] );

		if ( '' === $endpoint ) {
			return false;
		}

		$response = $this->post_verify_request(
			$endpoint,
			array_filter(
				array(
					'secret'   => $settings['secretKey'],
					'response' => $token,
					'remoteip' => trim( $ip_address ),
				),
				static fn( string $value ): bool => '' !== $value,
			),
		);

		if ( empty( $response['success'] ) ) {
			return false;
		}

		if ( self::PROVIDER_RECAPTCHA === $settings['provider'] ) {
			$action = (string) ( $response['action'] ?? '' );
			$score  = isset( $response['score'] ) ? (float) $response['score'] : 0.0;

			return self::RECAPTCHA_ACTION === $action &&
				$score >= self::RECAPTCHA_SCORE_THRESHOLD;
		}

		return true;
	}

	/**
	 * Return the normalized provider settings.
	 *
	 * @return array{provider: string, siteKey: string, secretKey: string}
	 * @since 1.2.0
	 */
	private function get_settings(): array {
		$options = $this->settings_api->get_options(
			array(
				'captcha_provider',
				'captcha_site_key',
				'captcha_secret_key_encrypted',
			),
		);

		return array(
			'provider'  => $this->normalize_provider(
				(string) ( $options['captcha_provider'] ?? self::PROVIDER_NONE ),
			),
			'siteKey'   => trim( (string) ( $options['captcha_site_key'] ?? '' ) ),
			'secretKey' => $this->decrypt_secret_value(
				(string) ( $options['captcha_secret_key_encrypted'] ?? '' ),
			),
		);
	}

	/**
	 * Normalize a provider token.
	 *
	 * @param string $provider Raw provider token.
	 * @return string Normalized provider token.
	 * @since 1.2.0
	 */
	private function normalize_provider( string $provider ): string {
		$provider = sanitize_key( $provider );

		if (
			in_array(
				$provider,
				array(
					self::PROVIDER_NONE,
					self::PROVIDER_RECAPTCHA,
					self::PROVIDER_TURNSTILE,
				),
				true,
			)
		) {
			return $provider;
		}

		return self::PROVIDER_NONE;
	}

	/**
	 * Validate normalized provider settings.
	 *
	 * @param array{provider: string, siteKey: string, secretKey: string} $values Normalized settings.
	 * @return void
	 *
	 * @throws \RuntimeException When settings are incomplete.
	 * @since 1.2.0
	 */
	private function validate_settings( array $values ): void {
		if ( self::PROVIDER_NONE === $values['provider'] ) {
			return;
		}

		if ( '' === $values['siteKey'] ) {
			throw new \RuntimeException( __( 'Enter the CAPTCHA site key.', 'peakurl' ) );
		}

		if ( '' === $values['secretKey'] ) {
			throw new \RuntimeException( __( 'Enter the CAPTCHA secret key.', 'peakurl' ) );
		}

		if ( strlen( $values['siteKey'] ) > 255 ) {
			throw new \RuntimeException( __( 'CAPTCHA site key must be 255 characters or fewer.', 'peakurl' ) );
		}

		if ( strlen( $values['secretKey'] ) > 512 ) {
			throw new \RuntimeException( __( 'CAPTCHA secret key must be 512 characters or fewer.', 'peakurl' ) );
		}
	}

	/**
	 * Store normalized provider settings in the settings table.
	 *
	 * @param string                                      $app_path Absolute path to the app directory.
	 * @param array<string, mixed>                        $config   Current runtime configuration.
	 * @param array{provider: string, siteKey: string, secretKey: string} $values Normalized settings.
	 * @return void
	 *
	 * @throws \RuntimeException When encrypted settings cannot be persisted.
	 * @since 1.2.0
	 */
	private function persist_settings(
		string $app_path,
		array $config,
		array $values
	): void {
		$updated_at = gmdate( 'Y-m-d H:i:s' );
		$secret_key = $values['secretKey'];

		if ( '' !== $secret_key && ! $this->crypto_service->is_configured() ) {
			$this->crypto_service = new Crypto( $config );
			$this->crypto_service->persist_auth_keys( $app_path );
		}

		$this->settings_api->update_option( 'captcha_provider', $values['provider'], $updated_at, false );
		$this->settings_api->update_option( 'captcha_site_key', $values['siteKey'], $updated_at, false );
		$this->settings_api->update_option(
			'captcha_secret_key_encrypted',
			'' === $secret_key ? '' : $this->crypto_service->encrypt( $secret_key ),
			$updated_at,
			false,
		);
	}

	/**
	 * Determine whether the dashboard can update CAPTCHA settings.
	 *
	 * @return array{allowed: bool, reason: string|null}
	 * @since 1.2.0
	 */
	private function get_capability(): array {
		if ( ! $this->settings_api->table_exists() ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The settings table is not available yet.', 'peakurl' ),
			);
		}

		return array(
			'allowed' => true,
			'reason'  => null,
		);
	}

	/**
	 * Check whether provider settings are complete.
	 *
	 * @param array{provider: string, siteKey: string, secretKey: string} $settings Provider settings.
	 * @return bool
	 * @since 1.2.0
	 */
	private function is_configured( array $settings ): bool {
		return self::PROVIDER_NONE !== $settings['provider'] &&
			'' !== $settings['siteKey'] &&
			'' !== $settings['secretKey'];
	}

	/**
	 * Return the provider Siteverify endpoint.
	 *
	 * @param string $provider Normalized provider token.
	 * @return string Siteverify endpoint URL.
	 * @since 1.2.0
	 */
	private function get_verify_endpoint( string $provider ): string {
		if ( self::PROVIDER_RECAPTCHA === $provider ) {
			return 'https://www.google.com/recaptcha/api/siteverify';
		}

		if ( self::PROVIDER_TURNSTILE === $provider ) {
			return 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		}

		return '';
	}

	/**
	 * POST a Siteverify request and decode the JSON response.
	 *
	 * @param string               $endpoint Siteverify endpoint URL.
	 * @param array<string, string> $payload  Verification payload.
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	private function post_verify_request(
		string $endpoint,
		array $payload
	): array {
		$body     = http_build_query( $payload, '', '&' );
		$response = function_exists( 'curl_init' )
			? $this->post_with_curl( $endpoint, $body )
			: $this->post_with_stream( $endpoint, $body );

		if ( '' === $response ) {
			return array();
		}

		$decoded = json_decode( $response, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * POST the provider request with cURL.
	 *
	 * @param string $endpoint Siteverify endpoint URL.
	 * @param string $body     URL-encoded request body.
	 * @return string Raw provider response.
	 * @since 1.2.0
	 */
	private function post_with_curl( string $endpoint, string $body ): string {
		$handle = curl_init( $endpoint );

		if ( false === $handle ) {
			return '';
		}

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_CONNECTTIMEOUT => 3,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/x-www-form-urlencoded',
				),
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 8,
			),
		);

		$response = curl_exec( $handle );
		curl_close( $handle );

		return is_string( $response ) ? $response : '';
	}

	/**
	 * POST the provider request with PHP streams.
	 *
	 * @param string $endpoint Siteverify endpoint URL.
	 * @param string $body     URL-encoded request body.
	 * @return string Raw provider response.
	 * @since 1.2.0
	 */
	private function post_with_stream( string $endpoint, string $body ): string {
		$context  = stream_context_create(
			array(
				'http' => array(
					'content' => $body,
					'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
					'method'  => 'POST',
					'timeout' => 8,
				),
			),
		);
		$response = @file_get_contents( $endpoint, false, $context );

		return is_string( $response ) ? $response : '';
	}

	/**
	 * Decrypt a stored secret value with a safe empty fallback.
	 *
	 * @param string $value Stored encrypted value.
	 * @return string
	 * @since 1.2.0
	 */
	private function decrypt_secret_value( string $value ): string {
		try {
			return $this->crypto_service->decrypt( $value );
		} catch ( \RuntimeException $exception ) {
			return '';
		}
	}

	/**
	 * Mask a key value for dashboard display.
	 *
	 * This is only used for public provider keys. Secret keys use a fixed hint
	 * and are never returned to the dashboard.
	 *
	 * @param string $value Raw key.
	 * @return string|null
	 * @since 1.2.0
	 */
	private function mask_key_hint( string $value ): ?string {
		if ( '' === $value ) {
			return null;
		}

		if ( strlen( $value ) <= 8 ) {
			return str_repeat( '•', min( 4, strlen( $value ) ) );
		}

		return substr( $value, 0, 4 ) . '••••••' . substr( $value, -4 );
	}
}
