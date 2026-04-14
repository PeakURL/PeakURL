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
			$this->data_store->bootstrap_workspace();
			$response = $this->router->dispatch( $request );

			if ( ! is_array( $response ) ) {
				$response = JsonResponse::error(
					'Unhandled application response.',
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
				'Unexpected application error.',
				500,
				'development' === ( $this->config['PEAKURL_ENV'] ?? 'production' )
					? array( 'exception' => $exception->getMessage() )
					: array(),
			);
		}

		$this->send_response( $response, $request );
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
		$geoip     = new GeoipController( $this->data_store );
		$mail      = new MailController( $this->data_store );
		$notices   = new AdminNoticesController( $this->data_store );
		$settings  = new SettingsController( $this->data_store );
		$status    = new SystemStatusController( $this->data_store );
		$updates   = new UpdatesController( $this->data_store );

		$this->router->get( '/api/v1/health', array( $this, 'health' ) );
		$this->router->get(
			'/api/v1/system/i18n',
			array(
				$settings,
				'i18n',
			)
		);

		$this->router->post( '/api/v1/auth/register', array( $auth, 'register' ) );
		$this->router->post(
			'/api/v1/auth/verify-email',
			array(
				$auth,
				'verify_email',
			)
		);
		$this->router->post(
			'/api/v1/auth/resend-verification',
			array(
				$auth,
				'resend_verification',
			)
		);
		$this->router->post( '/api/v1/auth/login', array( $auth, 'login' ) );
		$this->router->post(
			'/api/v1/auth/login/verify',
			array(
				$auth,
				'verify_two_factor_login',
			)
		);
		$this->router->post( '/api/v1/auth/logout', array( $auth, 'logout' ) );
		$this->router->post(
			'/api/v1/auth/forgot-password',
			array(
				$auth,
				'forgot_password',
			)
		);
		$this->router->post(
			'/api/v1/auth/reset-password/{token}',
			array(
				$auth,
				'reset_password',
			)
		);
		$this->router->post(
			'/api/v1/auth/api-key',
			array(
				$auth,
				'generate_api_key',
			)
		);
		$this->router->delete(
			'/api/v1/auth/api-key/{id}',
			array(
				$auth,
				'delete_api_key',
			)
		);
		$this->router->get( '/api/v1/auth/security', array( $auth, 'get_security' ) );
		$this->router->post(
			'/api/v1/auth/security/two-factor/setup',
			array(
				$auth,
				'start_two_factor_setup',
			)
		);
		$this->router->post(
			'/api/v1/auth/security/two-factor/verify',
			array(
				$auth,
				'verify_two_factor',
			)
		);
		$this->router->post(
			'/api/v1/auth/security/two-factor/disable',
			array(
				$auth,
				'disable_two_factor',
			)
		);
		$this->router->post(
			'/api/v1/auth/security/two-factor/backup-codes',
			array(
				$auth,
				'regenerate_backup_codes',
			)
		);
		$this->router->post(
			'/api/v1/auth/security/backup-codes/download',
			array(
				$auth,
				'download_backup_codes',
			)
		);
		$this->router->delete(
			'/api/v1/auth/security/sessions',
			array(
				$auth,
				'revoke_other_sessions',
			)
		);
		$this->router->delete(
			'/api/v1/auth/security/sessions/{id}',
			array(
				$auth,
				'revoke_session',
			)
		);

		$this->router->get( '/api/v1/users', array( $users, 'index' ) );
		$this->router->post( '/api/v1/users', array( $users, 'create' ) );
		$this->router->get( '/api/v1/users/me', array( $users, 'me' ) );
		$this->router->put( '/api/v1/users/me', array( $users, 'update_me' ) );
		$this->router->put( '/api/v1/users/{username}', array( $users, 'update' ) );
		$this->router->delete( '/api/v1/users/{username}', array( $users, 'delete' ) );

		$this->router->get( '/api/v1/urls', array( $urls, 'index' ) );
		$this->router->get( '/api/v1/urls/export', array( $urls, 'export' ) );
		$this->router->get( '/api/v1/urls/{id}', array( $urls, 'show' ) );
		$this->router->post( '/api/v1/urls', array( $urls, 'create' ) );
		$this->router->post( '/api/v1/urls/bulk', array( $urls, 'bulk_create' ) );
		$this->router->delete( '/api/v1/urls/bulk', array( $urls, 'bulk_delete' ) );
		$this->router->put( '/api/v1/urls/{id}', array( $urls, 'update' ) );
		$this->router->delete( '/api/v1/urls/{id}', array( $urls, 'delete' ) );

		$this->router->get( '/api/v1/analytics', array( $analytics, 'index' ) );
		$this->router->get(
			'/api/v1/analytics/activity',
			array(
				$analytics,
				'activity',
			)
		);
		$this->router->get(
			'/api/v1/analytics/activity/history',
			array(
				$analytics,
				'history',
			)
		);
		$this->router->delete(
			'/api/v1/analytics/activity/bulk',
			array(
				$analytics,
				'bulk_delete',
			)
		);
		$this->router->delete(
			'/api/v1/analytics/activity/{id}',
			array(
				$analytics,
				'delete',
			)
		);
		$this->router->get(
			'/api/v1/analytics/url/{id}/location',
			array(
				$analytics,
				'location',
			)
		);
		$this->router->get(
			'/api/v1/analytics/url/{id}/stats',
			array(
				$analytics,
				'stats',
			)
		);

		$this->router->get( '/api/v1/webhooks', array( $webhooks, 'index' ) );
		$this->router->post( '/api/v1/webhooks', array( $webhooks, 'create' ) );
		$this->router->delete( '/api/v1/webhooks/{id}', array( $webhooks, 'delete' ) );

		$this->router->get(
			'/api/v1/system/notices',
			array(
				$notices,
				'index',
			)
		);
		$this->router->get(
			'/api/v1/system/general',
			array(
				$settings,
				'general',
			)
		);
		$this->router->post(
			'/api/v1/system/general',
			array(
				$settings,
				'update_general',
			)
		);
		$this->router->get(
			'/api/v1/system/status',
			array(
				$status,
				'status',
			)
		);
		$this->router->get(
			'/api/v1/system/geoip',
			array(
				$geoip,
				'status',
			)
		);
		$this->router->post(
			'/api/v1/system/geoip',
			array(
				$geoip,
				'update',
			)
		);
		$this->router->post(
			'/api/v1/system/geoip/download',
			array(
				$geoip,
				'download',
			)
		);
		$this->router->get(
			'/api/v1/system/mail',
			array(
				$mail,
				'status',
			)
		);
		$this->router->post(
			'/api/v1/system/mail',
			array(
				$mail,
				'update',
			)
		);

		$this->router->get(
			'/api/v1/system/update',
			array(
				$updates,
				'status',
			)
		);
		$this->router->post(
			'/api/v1/system/update/check',
			array(
				$updates,
				'check',
			)
		);
		$this->router->post(
			'/api/v1/system/update/apply',
			array(
				$updates,
				'apply',
			)
		);
		$this->router->post(
			'/api/v1/system/update/reinstall',
			array(
				$updates,
				'reinstall',
			)
		);
		$this->router->post(
			'/api/v1/system/update/database',
			array(
				$updates,
				'upgrade_database',
			)
		);

		$this->router->get( '/{id}', array( $urls, 'redirect' ) );
		$this->router->get( '/{id}/', array( $urls, 'redirect' ) );
		$this->router->post( '/{id}', array( $urls, 'redirect' ) );
		$this->router->post( '/{id}/', array( $urls, 'redirect' ) );
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

		if ( is_array( $body ) ) {
			if ( ! isset( $headers['Content-Type'] ) ) {
				header( 'Content-Type: application/json; charset=utf-8' );
			}

			echo json_encode( $body, JSON_PRETTY_PRINT );
			return;
		}

		echo (string) $body;
	}
}
