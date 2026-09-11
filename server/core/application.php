<?php
/**
 * Application bootstrap and route registration.
 *
 * Wires the Router, technical services, domain services, and all controllers together,
 * dispatches the incoming request, and sends the final HTTP response.
 *
 * @package PeakURL\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Core;

use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Security\Security;
use PeakURL\Features\Analytics\Controller as AnalyticsController;
use PeakURL\Features\Analytics\Repository as AnalyticsRepository;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Controller as AuthController;
use PeakURL\Features\Auth\Credentials as AuthCredentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Links\Controller as LinksController;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Settings\Controller as SettingsController;
use PeakURL\Features\Settings\Service as SettingsService;
use PeakURL\Features\Settings\Validator as SettingsValidator;
use PeakURL\Features\System\Controller as SystemController;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Features\Users\Controller as UsersController;
use PeakURL\Features\Users\Service as UsersService;
use PeakURL\Features\Users\Validator as UsersValidator;
use PeakURL\Features\Webhooks\Controller as WebhooksController;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;
use PeakURL\Http\Router;
use PeakURL\Services\Cache\CacheManager;
use PeakURL\Services\Captcha;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as DatabaseSchema;
use PeakURL\Services\Favicon;
use PeakURL\Services\Geoip;
use PeakURL\Services\I18n;
use PeakURL\Services\Install\Bootstrap;
use PeakURL\Services\Mailer;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Totp;

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

	/** @var Connection Database connection manager. */
	private Connection $connection;

	/** @var I18n I18n service. */
	private I18n $i18n_service;

	/** @var array<string, mixed> Merged runtime configuration. */
	private array $config;

	/**
	 * Bootstrap the application, create services, and register routes.
	 *
	 * @param Connection           $connection Database connection manager.
	 * @param array<string, mixed> $config     Merged runtime configuration.
	 * @since 1.0.0
	 */
	public function __construct( Connection $connection, array $config ) {
		$this->router     = new Router();
		$this->connection = $connection;
		$this->config     = $config;

		$db_prefix   = (string) ( $config[ Constants::DB_PREFIX ] ?? '' );
		$db          = new PeakURL_DB( $connection, $db_prefix );
		$schema_path = dirname( __DIR__ ) . '/database/schema.sql';
		$schema      = new DatabaseSchema( $connection, $schema_path );
		$content_dir = (string) ( $config[ Constants::CONTENT_DIR ] ?? ( ABSPATH . Constants::DEFAULT_CONTENT_DIR ) );

		$settings_api  = new SettingsApi( $db );
		$users_api     = new UsersApi( $db );
		$links_api     = new LinksApi( $db, null, $settings_api );
		$cache_service = CacheManager::resolve( $config, $content_dir );
		$links_api->set_cache( $cache_service );
		$crypto_service         = new Crypto( $config );
		$this->i18n_service     = new I18n( $config, $settings_api );
		$geoip_service          = new Geoip( $config, $settings_api, $crypto_service );
		$mailer_service         = new Mailer( $config, $settings_api, $crypto_service );
		$notifications_service  = new Notifications();
		$captcha_service        = new Captcha( $config, $settings_api, $crypto_service );
		$social_preview_service = new SocialPreview( $config, $settings_api );
		$favicon_service        = new Favicon( $config, $settings_api );
		$roles                  = new Roles();
		$authorization          = new Authorization( $roles );

		$auth_credentials     = new AuthCredentials( $db );
		$auth_validator       = new AuthValidator();
		$totp                 = new Totp();
		$auth_service         = new AuthService(
			$db,
			$users_api,
			$auth_credentials,
			$auth_validator,
			$totp,
			$notifications_service,
			$crypto_service,
			$roles,
			$authorization,
			$geoip_service,
			$config
		);
		$webhooks_service     = new WebhooksService(
			$db,
			new WebhooksValidator(),
			$auth_service,
			$roles,
			$authorization,
			$config
		);
		$analytics_repository = new AnalyticsRepository(
			$db,
			$settings_api,
			$geoip_service,
			$roles,
			$authorization,
			$webhooks_service,
			null,
			$config
		);
		$analytics_service    = new AnalyticsService(
			$analytics_repository,
			$db,
			$auth_service,
			$roles,
			$authorization,
			$config,
			$links_api
		);
		$users_service        = new UsersService(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			new UsersValidator(),
			$roles,
			$authorization,
			$social_preview_service
		);
		$links_repository     = new LinksRepository(
			$db,
			$links_api,
			$authorization
		);
		$links_validator      = new LinksValidator();
		$links_service        = new LinksService(
			$links_repository,
			$links_validator,
			$settings_api,
			$auth_service,
			$analytics_service,
			$webhooks_service,
			$social_preview_service,
			$captcha_service,
			$roles,
			$authorization,
			$config
		);
		$settings_service     = new SettingsService(
			$db,
			$connection,
			$schema,
			$cache_service,
			new SettingsValidator(),
			$settings_api,
			$auth_service,
			$this->i18n_service,
			$favicon_service,
			$social_preview_service,
			$captcha_service,
			$geoip_service,
			$mailer_service,
			$notifications_service,
			$roles,
			$authorization,
			$config,
			$links_api
		);
		$system_service       = new SystemService(
			$db,
			$connection,
			$auth_service,
			$settings_api,
			$geoip_service,
			$mailer_service,
			$schema,
			$this->i18n_service,
			$roles,
			$authorization,
			$config
		);

		$this->register_routes(
			$auth_service,
			$users_service,
			$links_service,
			$analytics_service,
			$webhooks_service,
			$settings_service,
			$system_service,
			$captcha_service
		);
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
			Bootstrap::bootstrap_site( $this->connection, $this->config, $this->i18n_service );

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
			if ( ! empty( $this->config[ Constants::DEBUG ] ) ) {
				error_log( (string) $exception );
			}

			$is_dev = 'development' === ( $this->config[ Constants::ENV ] ?? 'production' )
				|| ! empty( $this->config[ Constants::DEBUG ] );

			$message = $is_dev
				? $exception->getMessage()
				: __(
					'An internal server error occurred. Please try again or contact support if the issue persists.',
					'peakurl',
				);

			$data = $is_dev
				? array(
					'exception' => $exception->getMessage(),
					'file'      => $exception->getFile(),
					'line'      => $exception->getLine(),
					'trace'     => $exception->getTraceAsString(),
				)
				: array();

			$response = JsonResponse::error(
				$message,
				500,
				$data,
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
		 */
		$pre_dispatch = \apply_filters(
			'rest_pre_dispatch',
			null,
			$request,
			$this->router,
		);

		if ( is_array( $pre_dispatch ) ) {
			$response = $pre_dispatch;
		} else {
			/**
			 * Fires immediately before an API request is dispatched.
			 *
			 * @since 1.2.2
			 *
			 * @param Request $request Incoming request.
			 * @param Router  $router  API router.
			 */
			\do_action(
				'rest_request_before_dispatch',
				$request,
				$this->router,
			);

			$response = $this->router->dispatch( $request );
		}

		/**
		 * Filters the response after the API request has been dispatched.
		 *
		 * @since 1.2.2
		 *
		 * @param array<string, mixed> $response Structured response.
		 * @param Request              $request  Incoming request.
		 * @param Router               $router   API router.
		 */
		$response = \apply_filters(
			'rest_post_dispatch',
			$response,
			$request,
			$this->router,
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
		$path     = $request->get_path();
		$api_path = Constants::API_BASE_PATH;

		return $api_path === $path || 0 === strpos( $path, $api_path . '/' );
	}

	/**
	 * Reject cross-origin browser mutations before route dispatch.
	 *
	 * API clients with Bearer token authentication, browser extensions, and
	 * clients without browser Origin/Referer headers are allowed; browser session
	 * writes must come from the configured site origin.
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

		$authorization = trim( (string) $request->get_header( 'Authorization', '' ) );

		if ( '' !== $authorization && preg_match( '/^Bearer\s+/i', $authorization ) ) {
			return;
		}

		$origin = Security::get_request_origin( $request );

		if (
			null === $origin ||
			Security::is_same_origin( $this->config, $origin ) ||
			Security::is_extension_origin( $origin )
		) {
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
	 * Maps HTTP verb + path pairs to the appropriate controller handler method.
	 *
	 * @param AuthService      $auth_service      Auth service.
	 * @param UsersService     $users_service     Users service.
	 * @param LinksService     $links_service     Links service.
	 * @param AnalyticsService $analytics_service Analytics service.
	 * @param WebhooksService  $webhooks_service  Webhooks service.
	 * @param SettingsService  $settings_service  Settings service.
	 * @param SystemService    $system_service    System service.
	 * @param Captcha          $captcha_service   Captcha service.
	 * @return void
	 * @since 1.0.0
	 */
	private function register_routes(
		AuthService $auth_service,
		UsersService $users_service,
		LinksService $links_service,
		AnalyticsService $analytics_service,
		WebhooksService $webhooks_service,
		SettingsService $settings_service,
		SystemService $system_service,
		Captcha $captcha_service
	): void {
		$auth      = new AuthController( $auth_service, $captcha_service );
		$users     = new UsersController( $users_service );
		$urls      = new LinksController( $links_service );
		$analytics = new AnalyticsController( $analytics_service );
		$webhooks  = new WebhooksController( $webhooks_service );
		$settings  = new SettingsController( $settings_service );
		$system    = new SystemController( $system_service );

		$this->register_core_routes( $settings );
		$this->register_auth_routes( $auth );
		$this->register_user_routes( $users );
		$this->register_url_routes( $urls );
		$this->register_analytics_routes( $analytics );
		$this->register_webhook_routes( $webhooks );
		$this->register_settings_routes( $settings );
		$this->register_system_routes( $system );

		/**
		 * Fires after built-in API routes have been registered.
		 *
		 * Custom PHP can register additional routes on the shared router.
		 *
		 * @since 1.2.2
		 *
		 * @param Router      $router      API router.
		 * @param Application $application Current application instance.
		 */
		\do_action( 'rest_api_init', $this->router, $this );

		$this->register_redirect_routes( $urls );
	}

	/**
	 * Register health and dashboard data routes.
	 *
	 * @param SettingsController $settings Settings controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_core_routes( SettingsController $settings ): void {
		$this->add_routes(
			array(
				array( 'get', '/health', array( $this, 'health' ) ),
				array( 'get', '/system/i18n', array( $settings, 'i18n' ) ),
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
				array( 'post', '/auth/register', array( $auth, 'register' ) ),
				array( 'post', '/auth/verify-email', array( $auth, 'verify_email' ) ),
				array( 'post', '/auth/resend-verification', array( $auth, 'resend_verification' ) ),
				array( 'post', '/auth/login', array( $auth, 'login' ) ),
				array( 'post', '/auth/login/verify', array( $auth, 'verify_two_factor_login' ) ),
				array( 'post', '/auth/logout', array( $auth, 'logout' ) ),
				array( 'post', '/auth/forgot-password', array( $auth, 'forgot_password' ) ),
				array( 'get', '/auth/reset-password/{token}', array( $auth, 'validate_reset_token' ) ),
				array( 'post', '/auth/reset-password/{token}', array( $auth, 'reset_password' ) ),
				array( 'post', '/auth/api-key', array( $auth, 'generate_api_key' ) ),
				array( 'delete', '/auth/api-key/{id}', array( $auth, 'delete_api_key' ) ),
				array( 'get', '/auth/security', array( $auth, 'get_security' ) ),
				array( 'post', '/auth/security/two-factor/setup', array( $auth, 'start_two_factor_setup' ) ),
				array( 'post', '/auth/security/two-factor/verify', array( $auth, 'verify_two_factor' ) ),
				array( 'post', '/auth/security/two-factor/disable', array( $auth, 'disable_two_factor' ) ),
				array( 'post', '/auth/security/two-factor/backup-codes', array( $auth, 'regenerate_backup_codes' ) ),
				array( 'post', '/auth/security/backup-codes/download', array( $auth, 'download_backup_codes' ) ),
				array( 'delete', '/auth/security/sessions', array( $auth, 'revoke_other_sessions' ) ),
				array( 'delete', '/auth/security/sessions/{id}', array( $auth, 'revoke_session' ) ),
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
				array( 'get', '/users', array( $users, 'index' ) ),
				array( 'post', '/users', array( $users, 'create' ) ),
				array( 'get', '/users/me', array( $users, 'me' ) ),
				array( 'put', '/users/me', array( $users, 'update_me' ) ),
				array( 'put', '/users/{username}', array( $users, 'update' ) ),
				array( 'delete', '/users/{username}', array( $users, 'delete' ) ),
			)
		);
	}

	/**
	 * Register short-link CRUD and import/export routes.
	 *
	 * @param LinksController $urls URLs controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_url_routes( LinksController $urls ): void {
		$this->add_routes(
			array(
				array( 'get', '/urls', array( $urls, 'index' ) ),
				array( 'get', '/urls/export', array( $urls, 'export' ) ),
				array( 'get', '/urls/{id}', array( $urls, 'show' ) ),
				array( 'post', '/urls', array( $urls, 'create' ) ),
				array( 'post', '/urls/bulk', array( $urls, 'bulk_create' ) ),
				array( 'post', '/urls/restore', array( $urls, 'bulk_restore' ) ),
				array( 'post', '/urls/{id}/restore', array( $urls, 'restore' ) ),
				array( array( 'post', 'put' ), '/urls/{id}', array( $urls, 'update' ) ),
				array( 'delete', '/urls', array( $urls, 'clear' ) ),
				array( 'delete', '/urls/trash', array( $urls, 'empty_trash' ) ),
				array( 'delete', '/urls/bulk', array( $urls, 'bulk_delete' ) ),
				array( 'delete', '/urls/{id}', array( $urls, 'delete' ) ),
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
				array( 'get', '/analytics', array( $analytics, 'index' ) ),
				array( 'get', '/analytics/activity', array( $analytics, 'activity' ) ),
				array( 'get', '/analytics/recent-clicks', array( $analytics, 'recent_clicks' ) ),
				array( 'get', '/analytics/activity/history', array( $analytics, 'history' ) ),
				array( 'post', '/analytics/activity/{id}/restore', array( $analytics, 'restore_link' ) ),
				array( 'delete', '/analytics/activity', array( $analytics, 'clear' ) ),
				array( 'delete', '/analytics/activity/bulk', array( $analytics, 'bulk_delete' ) ),
				array( 'delete', '/analytics/activity/{id}', array( $analytics, 'delete' ) ),
				array( 'get', '/analytics/url/{id}/location', array( $analytics, 'location' ) ),
				array( 'get', '/analytics/url/{id}/stats', array( $analytics, 'stats' ) ),
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
				array( 'get', '/webhooks', array( $webhooks, 'index' ) ),
				array( 'post', '/webhooks', array( $webhooks, 'create' ) ),
				array( 'post', '/webhooks/test', array( $webhooks, 'test' ) ),
				array( 'post', '/webhooks/{id}/test', array( $webhooks, 'test' ) ),
				array( 'put', '/webhooks/{id}', array( $webhooks, 'update' ) ),
				array( 'delete', '/webhooks/{id}', array( $webhooks, 'delete' ) ),
			)
		);
	}

	/**
	 * Register settings and configuration routes.
	 *
	 * @param SettingsController $settings Settings controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_settings_routes( SettingsController $settings ): void {
		$this->add_routes(
			array(
				array( 'get', '/system/general', array( $settings, 'general' ) ),
				array( 'post', '/system/general', array( $settings, 'update_general' ) ),
				array( 'get', '/system/cache', array( $settings, 'cache_status' ) ),
				array( 'post', '/system/cache', array( $settings, 'cache_update' ) ),
				array( 'post', '/system/cache/clear', array( $settings, 'cache_clear' ) ),
				array( 'get', '/system/captcha', array( $settings, 'captcha_status' ) ),
				array( 'post', '/system/captcha', array( $settings, 'captcha_update' ) ),
				array( 'get', '/system/geoip', array( $settings, 'geoip_status' ) ),
				array( 'post', '/system/geoip', array( $settings, 'geoip_update' ) ),
				array( 'post', '/system/geoip/download', array( $settings, 'geoip_download' ) ),
				array( 'get', '/system/mail', array( $settings, 'mail_status' ) ),
				array( 'post', '/system/mail', array( $settings, 'mail_update' ) ),
				array( 'post', '/system/mail/test', array( $settings, 'mail_test' ) ),
			)
		);
	}

	/**
	 * Register system notices, diagnostics, and updater routes.
	 *
	 * @param SystemController $system System controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_system_routes( SystemController $system ): void {
		$this->add_routes(
			array(
				array( 'get', '/system/notices', array( $system, 'notices' ) ),
				array( 'get', '/system/status', array( $system, 'status' ) ),
				array( 'get', '/system/update', array( $system, 'update_status' ) ),
				array( 'post', '/system/update/check', array( $system, 'update_check' ) ),
				array( 'post', '/system/update/apply', array( $system, 'update_apply' ) ),
				array( 'post', '/system/update/reinstall', array( $system, 'update_reinstall' ) ),
				array( 'post', '/system/update/database', array( $system, 'upgrade_database' ) ),
			)
		);
	}

	/**
	 * Register public short-link redirect catch-all routes.
	 *
	 * @param LinksController $urls URLs controller.
	 * @return void
	 * @since 1.1.1
	 */
	private function register_redirect_routes( LinksController $urls ): void {
		$this->add_routes(
			array(
				array( array( 'get', 'head', 'post' ), '/{id}', array( $urls, 'redirect' ) ),
				array( array( 'get', 'head', 'post' ), '/{id}/', array( $urls, 'redirect' ) ),
			),
			''
		);
	}

	/**
	 * Register a compact route map on the router.
	 *
	 * Dashboard API routes use the public API base by default. Public routes
	 * can pass an empty prefix to register directly at the site root.
	 *
	 * @param array<int, array{0: string|array<int, string>, 1: string, 2: callable}> $routes      Route definitions.
	 * @param string                                                                  $path_prefix Optional route path prefix.
	 * @return void
	 * @since 1.1.1
	 */
	private function add_routes(
		array $routes,
		string $path_prefix = Constants::API_BASE_PATH
	): void {
		foreach ( $routes as $route ) {
			list( $methods, $path, $handler ) = $route;

			$methods = is_array( $methods ) ? $methods : array( $methods );
			$path    = $this->prefix_route_path(
				(string) $path,
				$path_prefix,
			);

			foreach ( $methods as $method_name ) {
				$this->router->add_route( (string) $method_name, $path, $handler );
			}
		}
	}

	/**
	 * Prefix a route path while preserving root-relative route syntax.
	 *
	 * @param string $path        Route path.
	 * @param string $path_prefix Optional route path prefix.
	 * @return string Prefixed route path.
	 * @since 1.2.2
	 */
	private function prefix_route_path( string $path, string $path_prefix = '' ): string {
		$path = '/' . ltrim( $path, '/' );

		if ( '' === $path_prefix ) {
			return $path;
		}

		return rtrim( '/' . trim( $path_prefix, '/' ), '/' ) . $path;
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
				if ( ! empty( $this->config[ Constants::DEBUG ] ) ) {
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
