<?php
/**
 * Application bootstrap and route registration.
 *
 * Wires the Router, Store, and all controllers together, dispatches
 * the incoming request, and sends the final HTTP response.
 *
 * @package PeakURL\Includes
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Includes;

use PeakURL\Controllers\AnalyticsController;
use PeakURL\Controllers\AdminNoticesController;
use PeakURL\Controllers\AuthController;
use PeakURL\Controllers\CaptchaController;
use PeakURL\Controllers\GeoipController;
use PeakURL\Controllers\MailController;
use PeakURL\Controllers\SettingsController;
use PeakURL\Controllers\SystemStatusController;
use PeakURL\Controllers\UpdatesController;
use PeakURL\Controllers\UrlsController;
use PeakURL\Controllers\UsersController;
use PeakURL\Controllers\WebhooksController;
use PeakURL\Http\ApiException;
use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;
use PeakURL\Http\Router;
use PeakURL\Store;
use PeakURL\Utils\Security;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Main application kernel.
 *
 * Instantiated in `public/index.php` after the Composer autoloader
 * has been required and the configuration loaded.
 *
 * @since 1.0.0
 */
class Application {

	/** @var Router HTTP route dispatcher. */
	private Router $router;

	/** @var Store Shared data access layer. */
	private Store $data_store;

	/** @var array<string, mixed> Merged runtime configuration. */
	private array $config;

	/**
	 * Bootstrap the application, create the data store, and register routes.
	 *
	 * @param Connection           $connection Database connection manager.
	 * @param array<string, mixed> $config   Merged runtime configuration.
	 * @since 1.0.0
	 */
	public function __construct( Connection $connection, array $config ) {
		$this->router     = new Router();
		$this->config     = $config;
		$this->data_store = new Store( $connection, $config );
		$this->register_routes();
	}

	/**
	 * Dispatch the current HTTP request and send the response.
	 *
	 * Catches ApiException for structured error responses and generic
	 * \Throwable for unexpected failures.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function run(): void {
		$request = Request::from_globals();

		try {
			$this->validate_request_origin( $request );
			$this->data_store->bootstrap_site();

			if ( $this->is_admin_request( $request ) ) {
				/**
				 * Fires during dashboard API initialization.
				 *
				 * Custom PHP can use this hook for dashboard-only request setup.
				 *
				 * @since 1.2.2
				 */
				\do_action( 'admin_init' );
			}

			$response = $this->dispatch_request( $request );

			if ( ! is_array( $response ) ) {
				$response = JsonResponse::error(
					'Expected array response from router, received: ' .
					$this->get_debug_type( $response ) .
					'.',
				);
			}
		} catch ( ApiException $exception ) {
			$response = JsonResponse::error(
				$exception->getMessage(),
				$exception->get_status(),
				$exception->get_data(),
			);
		} catch ( \Throwable $exception ) {
			if ( ! empty( $this->config['PEAKURL_DEBUG'] ) ) {
				error_log( (string) $exception );
			}

			$response = JsonResponse::error(
				__(
					'An internal server error occurred. Please try again or contact support if the issue persists.',
					'peakurl',
				),
				500,
				'development' === ( $this->config['PEAKURL_ENV'] ?? 'production' )
					? array( 'exception' => $exception->getMessage() )
					: array(),
			);
		}

		$this->send_response( $response, $request );
	}

	/**
	 * Dispatch the API request through WordPress-style REST hooks.
	 *
	 * @param Request $request Incoming request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.2.2
	 */
	private function dispatch_request( Request $request ): array {
		/**
		 * Filters the response before the API request is dispatched.
		 *
		 * Returning an array short-circuits route dispatch. Return null to let
		 * PeakURL dispatch the request through the registered router.
		 *
		 * @since 1.2.2
		 *
		 * @param array<string, mixed>|null $response   Pre-dispatch response.
		 * @param Request                   $request    Incoming request.
		 * @param Router                    $router     API router.
		 * @param Store                     $data_store Shared data store.
		 */
		$pre_dispatch = \apply_filters(
			'rest_pre_dispatch',
			null,
			$request,
			$this->router,
			$this->data_store,
		);

		if ( is_array( $pre_dispatch ) ) {
			$response = $pre_dispatch;
		} else {
			/**
			 * Fires immediately before an API request is dispatched.
			 *
			 * @since 1.2.2
			 *
			 * @param Request $request    Incoming request.
			 * @param Router  $router     API router.
			 * @param Store   $data_store Shared data store.
			 */
			\do_action(
				'rest_request_before_dispatch',
				$request,
				$this->router,
				$this->data_store,
			);

			$response = $this->router->dispatch( $request );
		}

		/**
		 * Filters the response after the API request has been dispatched.
		 *
		 * @since 1.2.2
		 *
		 * @param array<string, mixed> $response   Structured response.
		 * @param Request              $request    Incoming request.
		 * @param Router               $router     API router.
		 * @param Store                $data_store Shared data store.
		 */
		$response = \apply_filters(
			'rest_post_dispatch',
			$response,
			$request,
			$this->router,
			$this->data_store,
		);

		return is_array( $response ) ? $response : JsonResponse::error(
			'Expected array response from rest_post_dispatch, received: ' .
			$this->get_debug_type( $response ) .
			'.',
		);
	}

	/**
	 * Determine whether the current request belongs to the dashboard API.
	 *
	 * @param Request $request Incoming request.
	 * @return bool
	 * @since 1.2.2
	 */
	private function is_admin_request( Request $request ): bool {
		$path = $request->get_path();

		return '/api/v1' === $path || 0 === strpos( $path, '/api/v1/' );
	}

	/**
	 * Reject cross-origin browser mutations before route dispatch.
	 *
	 * API clients without browser Origin/Referer headers are still allowed;
	 * browser-originating writes must come from the configured site origin.
	 *
	 * @param Request $request Incoming request.
	 * @return void
	 *
	 * @throws ApiException When a mutating browser request is cross-origin.
	 * @since 1.1.1
	 */
	private function validate_request_origin( Request $request ): void {
		if ( in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return;
		}

		$origin = Security::get_request_origin( $request );

		if ( null === $origin || Security::is_same_origin( $this->config, $origin ) ) {
			return;
		}

		throw new ApiException(
			__( 'Request origin is not allowed.', 'peakurl' ),
			403,
		);
	}

	/**
	 * Register all API and catch-all routes on the router.
	 *
	 * Instantiates every controller and maps HTTP verb + path pairs to
	 * the appropriate handler method.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function register_routes(): void {
		$auth      = new AuthController( $this->data_store );
		$users     = new UsersController( $this->data_store );
		$urls      = new UrlsController( $this->data_store );
		$analytics = new AnalyticsController( $this->data_store );
		$webhooks  = new WebhooksController( $this->data_store );
		$captcha   = new CaptchaController( $this->data_store );
		$geoip     = new GeoipController( $this->data_store );
		$mail      = new MailController( $this->data_store );
		$notices   = new AdminNoticesController( $this->data_store );
		$settings  = new SettingsController( $this->data_store );
		$status    = new SystemStatusController( $this->data_store );
		$updates   = new UpdatesController( $this->data_store );

		$this->register_core_routes( $settings );
		$this->register_auth_routes( $auth );
		$this->register_user_routes( $users );
		$this->register_url_routes( $urls );
		$this->register_analytics_routes( $analytics );
		$this->register_webhook_routes( $webhooks );
		$this->register_system_routes(
			$notices,
			$settings,
			$status,
			$captcha,
			$geoip,
			$mail,
		);
		$this->register_update_routes( $updates );

		/**
		 * Fires after built-in API routes have been registered.
		 *
		 * Custom PHP can register additional routes on the shared router.
		 *
		 * @since 1.2.2
		 *
		 * @param Router      $router      API router.
		 * @param Store       $data_store  Shared data store.
		 * @param Application $application Current application instance.
		 */
		\do_action( 'rest_api_init', $this->router, $this->data_store, $this );

		$this->register_redirect_routes( $urls );
	}

	/**
	 * Register public redirect routes.
	 *
	 * @param SettingsController $settings Settings controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_core_routes( SettingsController $settings ): void {
		$this->add_routes(
			array(
				array( 'get', '/api/v1/health', array( $this, 'health' ) ),
				array( 'get', '/api/v1/system/i18n', array( $settings, 'i18n' ) ),
			)
		);
	}

	/**
	 * Register authentication and account-security routes.
	 *
	 * @param AuthController $auth Authentication controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_auth_routes( AuthController $auth ): void {
		$this->add_routes(
			array(
				array( 'post', '/api/v1/auth/register', array( $auth, 'register' ) ),
				array( 'post', '/api/v1/auth/verify-email', array( $auth, 'verify_email' ) ),
				array( 'post', '/api/v1/auth/resend-verification', array( $auth, 'resend_verification' ) ),
				array( 'post', '/api/v1/auth/login', array( $auth, 'login' ) ),
				array( 'post', '/api/v1/auth/login/verify', array( $auth, 'verify_two_factor_login' ) ),
				array( 'post', '/api/v1/auth/logout', array( $auth, 'logout' ) ),
				array( 'post', '/api/v1/auth/forgot-password', array( $auth, 'forgot_password' ) ),
				array( 'get', '/api/v1/auth/reset-password/{token}', array( $auth, 'validate_reset_token' ) ),
				array( 'post', '/api/v1/auth/reset-password/{token}', array( $auth, 'reset_password' ) ),
				array( 'post', '/api/v1/auth/api-key', array( $auth, 'generate_api_key' ) ),
				array( 'delete', '/api/v1/auth/api-key/{id}', array( $auth, 'delete_api_key' ) ),
				array( 'get', '/api/v1/auth/security', array( $auth, 'get_security' ) ),
				array( 'post', '/api/v1/auth/security/two-factor/setup', array( $auth, 'start_two_factor_setup' ) ),
				array( 'post', '/api/v1/auth/security/two-factor/verify', array( $auth, 'verify_two_factor' ) ),
				array( 'post', '/api/v1/auth/security/two-factor/disable', array( $auth, 'disable_two_factor' ) ),
				array( 'post', '/api/v1/auth/security/two-factor/backup-codes', array( $auth, 'regenerate_backup_codes' ) ),
				array( 'post', '/api/v1/auth/security/backup-codes/download', array( $auth, 'download_backup_codes' ) ),
				array( 'delete', '/api/v1/auth/security/sessions', array( $auth, 'revoke_other_sessions' ) ),
				array( 'delete', '/api/v1/auth/security/sessions/{id}', array( $auth, 'revoke_session' ) ),
			)
		);
	}

	/**
	 * Register user-management routes.
	 *
	 * @param UsersController $users Users controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_user_routes( UsersController $users ): void {
		$this->add_routes(
			array(
				array( 'get', '/api/v1/users', array( $users, 'index' ) ),
				array( 'post', '/api/v1/users', array( $users, 'create' ) ),
				array( 'get', '/api/v1/users/me', array( $users, 'me' ) ),
				array( 'put', '/api/v1/users/me', array( $users, 'update_me' ) ),
				array( 'put', '/api/v1/users/{username}', array( $users, 'update' ) ),
				array( 'delete', '/api/v1/users/{username}', array( $users, 'delete' ) ),
			)
		);
	}

	/**
	 * Register short-link CRUD and import/export routes.
	 *
	 * @param UrlsController $urls URLs controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_url_routes( UrlsController $urls ): void {
		$this->add_routes(
			array(
				array( 'get', '/api/v1/urls', array( $urls, 'index' ) ),
				array( 'get', '/api/v1/urls/export', array( $urls, 'export' ) ),
				array( 'get', '/api/v1/urls/{id}', array( $urls, 'show' ) ),
				array( 'post', '/api/v1/urls', array( $urls, 'create' ) ),
				array( 'post', '/api/v1/urls/bulk', array( $urls, 'bulk_create' ) ),
				array( 'post', '/api/v1/urls/{id}', array( $urls, 'update' ) ),
				array( 'delete', '/api/v1/urls/bulk', array( $urls, 'bulk_delete' ) ),
				array( 'put', '/api/v1/urls/{id}', array( $urls, 'update' ) ),
				array( 'delete', '/api/v1/urls/{id}', array( $urls, 'delete' ) ),
			)
		);
	}

	/**
	 * Register analytics routes.
	 *
	 * @param AnalyticsController $analytics Analytics controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_analytics_routes( AnalyticsController $analytics ): void {
		$this->add_routes(
			array(
				array( 'get', '/api/v1/analytics', array( $analytics, 'index' ) ),
				array( 'get', '/api/v1/analytics/activity', array( $analytics, 'activity' ) ),
				array( 'get', '/api/v1/analytics/recent-clicks', array( $analytics, 'recent_clicks' ) ),
				array( 'get', '/api/v1/analytics/activity/history', array( $analytics, 'history' ) ),
				array( 'delete', '/api/v1/analytics/activity/bulk', array( $analytics, 'bulk_delete' ) ),
				array( 'delete', '/api/v1/analytics/activity/{id}', array( $analytics, 'delete' ) ),
				array( 'get', '/api/v1/analytics/url/{id}/location', array( $analytics, 'location' ) ),
				array( 'get', '/api/v1/analytics/url/{id}/stats', array( $analytics, 'stats' ) ),
			)
		);
	}

	/**
	 * Register webhook routes.
	 *
	 * @param WebhooksController $webhooks Webhooks controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_webhook_routes( WebhooksController $webhooks ): void {
		$this->add_routes(
			array(
				array( 'get', '/api/v1/webhooks', array( $webhooks, 'index' ) ),
				array( 'post', '/api/v1/webhooks', array( $webhooks, 'create' ) ),
				array( 'delete', '/api/v1/webhooks/{id}', array( $webhooks, 'delete' ) ),
			)
		);
	}

	/**
	 * Register system settings, status, GeoIP, and mail routes.
	 *
	 * @param AdminNoticesController $notices  Admin notices controller.
	 * @param SettingsController     $settings Settings controller.
	 * @param SystemStatusController $status   System status controller.
	 * @param CaptchaController      $captcha  CAPTCHA controller.
	 * @param GeoipController        $geoip    GeoIP controller.
	 * @param MailController         $mail     Mail controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_system_routes(
		AdminNoticesController $notices,
		SettingsController $settings,
		SystemStatusController $status,
		CaptchaController $captcha,
		GeoipController $geoip,
		MailController $mail
	): void {
		$this->add_routes(
			array(
				array( 'get', '/api/v1/system/notices', array( $notices, 'index' ) ),
				array( 'get', '/api/v1/system/general', array( $settings, 'general' ) ),
				array( 'post', '/api/v1/system/general', array( $settings, 'update_general' ) ),
				array( 'get', '/api/v1/system/status', array( $status, 'status' ) ),
				array( 'get', '/api/v1/system/captcha', array( $captcha, 'status' ) ),
				array( 'post', '/api/v1/system/captcha', array( $captcha, 'update' ) ),
				array( 'get', '/api/v1/system/geoip', array( $geoip, 'status' ) ),
				array( 'post', '/api/v1/system/geoip', array( $geoip, 'update' ) ),
				array( 'post', '/api/v1/system/geoip/download', array( $geoip, 'download' ) ),
				array( 'get', '/api/v1/system/mail', array( $mail, 'status' ) ),
				array( 'post', '/api/v1/system/mail', array( $mail, 'update' ) ),
				array( 'post', '/api/v1/system/mail/test', array( $mail, 'test' ) ),
			)
		);
	}

	/**
	 * Register update-management routes.
	 *
	 * @param UpdatesController $updates Updates controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_update_routes( UpdatesController $updates ): void {
		$this->add_routes(
			array(
				array( 'get', '/api/v1/system/update', array( $updates, 'status' ) ),
				array( 'post', '/api/v1/system/update/check', array( $updates, 'refresh' ) ),
				array( 'post', '/api/v1/system/update/apply', array( $updates, 'apply' ) ),
				array( 'post', '/api/v1/system/update/reinstall', array( $updates, 'reinstall' ) ),
				array( 'post', '/api/v1/system/update/database', array( $updates, 'upgrade_database' ) ),
			)
		);
	}

	/**
	 * Register public short-link redirect catch-all routes.
	 *
	 * @param UrlsController $urls URLs controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_redirect_routes( UrlsController $urls ): void {
		$this->add_routes(
			array(
				array( 'get', '/{id}', array( $urls, 'redirect' ) ),
				array( 'get', '/{id}/', array( $urls, 'redirect' ) ),
				array( 'head', '/{id}', array( $urls, 'redirect' ) ),
				array( 'head', '/{id}/', array( $urls, 'redirect' ) ),
				array( 'post', '/{id}', array( $urls, 'redirect' ) ),
				array( 'post', '/{id}/', array( $urls, 'redirect' ) ),
			)
		);
	}

	/**
	 * Register a compact route map on the router.
	 *
	 * @param array<int, array{0: string, 1: string, 2: callable}> $routes Route definitions.
	 * @return void
	 * @since 1.1.1
	 */
	private function add_routes( array $routes ): void {
		foreach ( $routes as $route ) {
			$method  = strtolower( (string) $route[0] );
			$path    = (string) $route[1];
			$handler = $route[2];

			$this->assert_supported_route_method( $method );

			switch ( $method ) {
				case 'get':
					$this->router->get( $path, $handler );
					break;
				case 'head':
					$this->router->head( $path, $handler );
					break;
				case 'post':
					$this->router->post( $path, $handler );
					break;
				case 'put':
					$this->router->put( $path, $handler );
					break;
				case 'patch':
					$this->router->patch( $path, $handler );
					break;
				case 'delete':
					$this->router->delete( $path, $handler );
					break;
				default:
					throw new RouteConfigurationException(
						'Unsupported route method: ' . $method,
					);
			}
		}
	}

	/**
	 * Ensure a route method is supported by this router.
	 *
	 * @param string $method Normalized lowercase HTTP method.
	 * @return void
	 *
	 * @throws RouteConfigurationException If the route method is unsupported.
	 * @since 1.1.2
	 */
	private function assert_supported_route_method( string $method ): void {
		$supported_methods = array(
			'get',
			'head',
			'post',
			'put',
			'patch',
			'delete',
		);

		if ( in_array( $method, $supported_methods, true ) ) {
			return;
		}

		throw new RouteConfigurationException(
			'Unsupported route method: ' . $method,
		);
	}

	/**
	 * Get a stable debug type label for unexpected values.
	 *
	 * @param mixed $value Value to inspect.
	 * @return string Type name.
	 * @since 1.1.2
	 */
	private function get_debug_type( $value ): string {
		if ( is_object( $value ) ) {
			return get_class( $value );
		}

		return gettype( $value );
	}

	/**
	 * Health-check endpoint handler (GET /api/v1/health).
	 *
	 * @param Request $request Incoming HTTP request (unused).
	 * @return array<string, mixed> JSON-ready success response.
	 * @since 1.0.0
	 */
	public function health( Request $request ): array {
		unset( $request );

		return JsonResponse::success(
			array(
				'status'   => 'ok',
				'database' => 'connected',
			),
			'PeakURL PHP app is running.',
		);
	}

	/**
	 * Write the HTTP response (status, headers, cookies, and body).
	 *
	 * @param array<string, mixed> $response Structured response from a handler.
	 * @param Request              $request  The originating request (for cookies).
	 * @return void
	 * @since 1.0.0
	 */
	private function send_response( array $response, Request $request ): void {
		$status  = isset( $response['status'] ) ? (int) $response['status'] : 200;
		$headers = isset( $response['headers'] ) ? $response['headers'] : array();
		$body    = $response['body'] ?? null;

		http_response_code( $status );

		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

		foreach ( $request->get_response_cookies() as $cookie_header ) {
			header( 'Set-Cookie: ' . $cookie_header, false );
		}

		if ( 'HEAD' === $request->get_method() ) {
			return;
		}

		if ( is_array( $body ) ) {
			if ( ! isset( $headers['Content-Type'] ) ) {
				header( 'Content-Type: application/json; charset=utf-8' );
			}

			try {
				echo json_encode(
					$body,
					JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
				);
			} catch ( \JsonException $exception ) {
				if ( ! empty( $this->config['PEAKURL_DEBUG'] ) ) {
					error_log( (string) $exception );
				}

				http_response_code( 500 );
				header( 'Content-Type: application/json; charset=utf-8' );
				echo '{"success":false,"message":"JSON encoding failed.","data":[],"timestamp":"' .
					gmdate( DATE_ATOM ) .
					'"}';
			}
			return;
		}

		echo (string) $body;
	}
}
