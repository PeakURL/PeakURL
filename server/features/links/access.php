<?php
/**
 * Public link access domain service.
 *
 * Implements public link resolution, expiration enforcement,
 * CAPTCHA verification, password protection, access cookies,
 * and click recording.
 *
 * @package PeakURL\Features\Links
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Links;

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Security\Security;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Captcha;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Access — Handles public link access, verification, and redirect resolution.
 *
 * @since 1.7.2
 */
class Access {

	/**
	 * Repository handler.
	 *
	 * @var Repository
	 * @since 1.7.2
	 */
	private Repository $repository;

	/**
	 * Validator helper.
	 *
	 * @var Validator
	 * @since 1.7.2
	 */
	private Validator $validator;

	/**
	 * Analytics domain service.
	 *
	 * @var AnalyticsService
	 * @since 1.7.2
	 */
	private AnalyticsService $analytics_service;

	/**
	 * Webhooks domain service.
	 *
	 * @var WebhooksService
	 * @since 1.7.2
	 */
	private WebhooksService $webhooks_service;

	/**
	 * CAPTCHA service.
	 *
	 * @var Captcha
	 * @since 1.7.2
	 */
	private Captcha $captcha;

	/**
	 * Runtime config map.
	 *
	 * @var array<string, mixed>
	 * @since 1.7.2
	 */
	private array $config;

	/**
	 * Optional link formatter for webhook payloads.
	 *
	 * @var callable|null
	 * @since 1.7.2
	 */
	private $link_formatter;

	/**
	 * Create a new Access instance.
	 *
	 * @param Repository           $repository        Repository handler.
	 * @param Validator            $validator         Validator handler.
	 * @param AnalyticsService     $analytics_service Analytics domain service.
	 * @param WebhooksService      $webhooks_service  Webhooks domain service.
	 * @param Captcha              $captcha           CAPTCHA service.
	 * @param array<string, mixed> $config            Runtime config map.
	 * @param callable|null        $link_formatter    Optional link formatter callback.
	 * @since 1.7.2
	 */
	public function __construct(
		Repository $repository,
		Validator $validator,
		AnalyticsService $analytics_service,
		WebhooksService $webhooks_service,
		Captcha $captcha,
		array $config,
		?callable $link_formatter = null
	) {
		$this->repository        = $repository;
		$this->validator         = $validator;
		$this->analytics_service = $analytics_service;
		$this->webhooks_service  = $webhooks_service;
		$this->captcha           = $captcha;
		$this->config            = $config;
		$this->link_formatter    = $link_formatter;
	}

	/**
	 * Return the destination redirect URL for a short code.
	 *
	 * @param string  $id      Short code or alias.
	 * @param Request $request Incoming HTTP request.
	 * @return string|null Destination URL or null.
	 * @since 1.7.2
	 */
	public function get_redirect_url(
		string $id,
		Request $request
	): ?string {
		$result = $this->get_link_access( $id, $request );

		return 'redirect' === $result['status']
			? (string) $result['location']
			: null;
	}

	/**
	 * Return the public access state for a short link.
	 *
	 * @param string  $id      Short code or alias.
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Public access result.
	 * @since 1.7.2
	 */
	public function get_link_access(
		string $id,
		Request $request
	): array {
		$code = $this->validator->sanitize_code( $id );

		if ( '' === $code ) {
			return array(
				'status' => 'not_found',
				'url'    => null,
			);
		}

		$url = $this->repository->find_link_access_row( $code );

		if ( ! $url ) {
			return array(
				'status' => 'not_found',
				'url'    => null,
			);
		}

		if ( $this->validator->is_public_link_expired( $url ) || 'expired' === (string) ( $url['status'] ?? '' ) ) {
			if ( 'expired' !== (string) ( $url['status'] ?? '' ) && ! empty( $url['id'] ) ) {
				$this->repository->expire_link( (string) $url['id'] );
				$this->repository->get_links_api()->invalidate_link_cache( $url );
				\do_action( 'link_expired', $url );
				$expired_payload = array_merge( $url, array( 'status' => 'expired' ) );
				$webhook_payload = null !== $this->link_formatter
					? ( $this->link_formatter )( $expired_payload )
					: $expired_payload;
				$this->webhooks_service->dispatch_link_event(
					'link.expired',
					$webhook_payload
				);
			}

			return array(
				'status' => 'expired',
				'url'    => $url,
			);
		}

		if ( 'active' !== (string) ( $url['status'] ?? 'active' ) ) {
			return array(
				'status' => 'unavailable',
				'url'    => $url,
			);
		}

		$allow_non_get_hit = false;
		$captcha_access    = $this->get_link_captcha_access( $url, $request );
		$captcha_protected = ! empty( $captcha_access['protected'] );

		if ( 'passed' === $captcha_access['status'] ) {
			$allow_non_get_hit = true;
		} elseif ( 'open' !== $captcha_access['status'] ) {
			return $captcha_access;
		}

		if ( ! empty( $url['password_value'] ) ) {
			$cookie_name     = $this->link_cookie_name( $url );
			$expected_cookie = $this->link_cookie_value( $url );
			$cookie_value    = (string) $request->get_cookie( $cookie_name, '' );

			if (
				'' !== $cookie_value &&
				hash_equals( $expected_cookie, $cookie_value )
			) {
				$this->analytics_service->record_click( $url, $request, $allow_non_get_hit );

				return array(
					'status'           => 'redirect',
					'url'              => $url,
					'location'         => (string) $url['destination_url'],
					'captchaProtected' => $captcha_protected,
				);
			}

			$password_attempt = trim(
				(string) $request->get_body_param( 'link_password', '' ),
			);

			if ( 'POST' === $request->get_method() ) {
				if ( '' === $password_attempt ) {
					return array(
						'status'  => 'password_required',
						'url'     => $url,
						'message' => __( 'Enter the password to open this link.', 'peakurl' ),
					);
				}

				if ( $this->validator->link_password_matches( $url, $password_attempt ) ) {
					$request->queue_cookie(
						$cookie_name,
						$expected_cookie,
						$this->link_cookie_options(
							$request,
							$url,
						),
					);
					$this->analytics_service->record_click( $url, $request, true );

					return array(
						'status'           => 'redirect',
						'url'              => $url,
						'location'         => (string) $url['destination_url'],
						'captchaProtected' => $captcha_protected,
					);
				}

				return array(
					'status'  => 'password_invalid',
					'url'     => $url,
					'message' => __( 'The password for this link is incorrect.', 'peakurl' ),
				);
			}

			return array(
				'status' => 'password_required',
				'url'    => $url,
			);
		}

		$this->analytics_service->record_click( $url, $request, $allow_non_get_hit );

		return array(
			'status'           => 'redirect',
			'url'              => $url,
			'location'         => (string) $url['destination_url'],
			'captchaProtected' => $captcha_protected,
		);
	}

	/**
	 * Verify CAPTCHA status for public redirect requests.
	 *
	 * @param array<string, mixed> $url     Raw URL database row.
	 * @param Request              $request Incoming HTTP request.
	 * @return array<string, mixed> Access state for redirect handler.
	 * @since 1.7.2
	 */
	private function get_link_captcha_access(
		array $url,
		Request $request
	): array {
		$challenge = $this->captcha->get_challenge();

		if ( null === $challenge ) {
			return array(
				'status'    => 'open',
				'protected' => false,
			);
		}

		$cookie_name     = $this->link_captcha_cookie_name( $url );
		$expected_cookie = $this->link_captcha_cookie_value( $url, $challenge );
		$cookie_value    = (string) $request->get_cookie( $cookie_name, '' );

		if (
			'' !== $cookie_value &&
			hash_equals( $expected_cookie, $cookie_value )
		) {
			return array(
				'status'    => 'open',
				'protected' => true,
			);
		}

		if ( 'POST' !== $request->get_method() ) {
			return array(
				'status'    => 'captcha_required',
				'url'       => $url,
				'challenge' => $challenge,
				'protected' => true,
			);
		}

		$token = trim(
			(string) $request->get_body_param(
				(string) $challenge['responseField'],
				'',
			),
		);

		if ( '' === $token ) {
			return array(
				'status'    => 'captcha_required',
				'url'       => $url,
				'challenge' => $challenge,
				'protected' => true,
				'message'   => __( 'Complete the verification to open this link.', 'peakurl' ),
			);
		}

		if (
			! $this->captcha->verify_token(
				$token,
				$request->get_ip_address(),
			)
		) {
			return array(
				'status'    => 'captcha_invalid',
				'url'       => $url,
				'challenge' => $challenge,
				'protected' => true,
				'message'   => __( 'Verification failed. Please try again.', 'peakurl' ),
			);
		}

		$request->queue_cookie(
			$cookie_name,
			$expected_cookie,
			$this->link_captcha_cookie_options( $request, $url ),
		);

		return array(
			'status'    => 'passed',
			'protected' => true,
		);
	}


	/**
	 * Get the cookie name used for password-protected link access.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return string
	 * @since 1.7.2
	 */
	private function link_cookie_name( array $url ): string {
		return 'peakurl_link_access_' . (string) ( $url['id'] ?? '' );
	}

	/**
	 * Get the cookie value hash for password-authorized links.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return string
	 * @since 1.7.2
	 */
	private function link_cookie_value( array $url ): string {
		return hash(
			'sha256',
			(string) ( $url['id'] ?? '' ) . '|' . (string) ( $url['password_value'] ?? '' ),
		);
	}

	/**
	 * Get cookie options for password-authorized public links.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $url     Raw URL row.
	 * @return array<string, mixed>
	 * @since 1.7.2
	 */
	private function link_cookie_options(
		Request $request,
		array $url
	): array {
		return $this->link_access_cookie_options(
			$request,
			$url,
			30 * 24 * 60 * 60,
		);
	}

	/**
	 * Get the cookie name used after successful CAPTCHA verification.
	 *
	 * @param array<string, mixed> $url Raw URL row.
	 * @return string
	 * @since 1.7.2
	 */
	private function link_captcha_cookie_name( array $url ): string {
		return 'peakurl_link_captcha_' . (string) ( $url['id'] ?? '' );
	}

	/**
	 * Get the signed cookie value for CAPTCHA verification.
	 *
	 * @param array<string, mixed>  $url       Raw URL row.
	 * @param array<string, string> $challenge Challenge details.
	 * @return string
	 * @since 1.7.2
	 */
	private function link_captcha_cookie_value(
		array $url,
		array $challenge
	): string {
		$payload = implode(
			'|',
			array(
				(string) ( $url['id'] ?? '' ),
				(string) ( $url['updated_at'] ?? '' ),
				(string) ( $challenge['provider'] ?? '' ),
				(string) ( $challenge['siteKey'] ?? '' ),
			),
		);
		$secret  = trim(
			(string) ( $this->config[ Constants::AUTH_SALT ] ?? '' ),
		);

		if ( '' === $secret ) {
			$secret = trim(
				(string) ( $this->config[ Constants::AUTH_KEY ] ?? '' ),
			);
		}

		return '' === $secret
			? hash( 'sha256', $payload )
			: hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Get cookie options for CAPTCHA-verified links.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $url     Raw URL row.
	 * @return array<string, mixed>
	 * @since 1.7.2
	 */
	private function link_captcha_cookie_options(
		Request $request,
		array $url
	): array {
		return $this->link_access_cookie_options(
			$request,
			$url,
			12 * 60 * 60,
		);
	}

	/**
	 * Build shared cookie options for link access challenges.
	 *
	 * @param Request              $request         Incoming HTTP request.
	 * @param array<string, mixed> $url             Raw URL row.
	 * @param int                  $default_max_age Default max age in seconds.
	 * @return array<string, mixed>
	 * @since 1.7.2
	 */
	private function link_access_cookie_options(
		Request $request,
		array $url,
		int $default_max_age
	): array {
		$options = Security::session_cookie_options(
			$this->config,
			$request,
			array(
				'samesite' => 'Lax',
			),
		);
		$max_age = $default_max_age;

		$expires_at = (string) ( $url['expires_at'] ?? '' );

		if ( '' !== $expires_at ) {
			$expires_timestamp = strtotime( $expires_at . ' UTC' );

			if ( false !== $expires_timestamp ) {
				$max_age = max( 60, $expires_timestamp - time() );
			}
		}

		$options['max-age'] = $max_age;
		$options['expires'] = gmdate( 'D, d M Y H:i:s T', time() + $max_age );

		return $options;
	}
}
