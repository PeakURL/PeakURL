<?php
/**
 * Automated Route and API Coverage Inventory guard test.
 *
 * Verifies that:
 * 1. Every route registered in Application is mapped to an authoritative inventory entry.
 * 2. Every route has documented HTTP method, path, capability/role requirements, and coverage tags.
 * 3. Zero unclassified or orphaned routes exist in the backend.
 *
 * @package PeakURL\Tests\Unit\Http
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Application;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Database\Connection;

class RouteInventoryTest extends TestCase {

	/**
	 * Authoritative inventory of all registered PeakURL API and redirect routes.
	 *
	 * Format: METHOD path => [ 'area' => ..., 'capability' => ..., 'coverage' => [...] ]
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const ROUTE_INVENTORY = array(
		// Core
		'GET /api/v1/health'                               => array(
			'area'       => 'Core',
			'capability' => 'public',
			'coverage'   => array( 'unit', 'e2e' ),
		),
		'GET /api/v1/system/i18n'                          => array(
			'area'       => 'Core',
			'capability' => 'public',
			'coverage'   => array( 'unit', 'e2e' ),
		),

		// Auth
		'POST /api/v1/auth/register'                       => array(
			'area'       => 'Auth',
			'capability' => 'public',
			'coverage'   => array( 'contract' ),
		),
		'POST /api/v1/auth/verify-email'                   => array(
			'area'       => 'Auth',
			'capability' => 'public',
			'coverage'   => array( 'contract' ),
		),
		'POST /api/v1/auth/resend-verification'            => array(
			'area'       => 'Auth',
			'capability' => 'public',
			'coverage'   => array( 'contract' ),
		),
		'POST /api/v1/auth/login'                          => array(
			'area'       => 'Auth',
			'capability' => 'public',
			'coverage'   => array( 'contract', 'auth', 'smoke', 'e2e' ),
		),
		'POST /api/v1/auth/login/verify'                   => array(
			'area'       => 'Auth',
			'capability' => 'public',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/auth/logout'                         => array(
			'area'       => 'Auth',
			'capability' => 'authenticated',
			'coverage'   => array( 'contract', 'auth', 'smoke', 'e2e' ),
		),
		'POST /api/v1/auth/forgot-password'                => array(
			'area'       => 'Auth',
			'capability' => 'public',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),
		'GET /api/v1/auth/reset-password/{token}'          => array(
			'area'       => 'Auth',
			'capability' => 'public',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/auth/reset-password/{token}'         => array(
			'area'       => 'Auth',
			'capability' => 'public',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/auth/api-key'                        => array(
			'area'       => 'Auth',
			'capability' => 'manage_api_keys',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'DELETE /api/v1/auth/api-key/{id}'                 => array(
			'area'       => 'Auth',
			'capability' => 'manage_api_keys',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'GET /api/v1/auth/security'                        => array(
			'area'       => 'Auth',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/auth/security/two-factor/setup'      => array(
			'area'       => 'Auth',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/auth/security/two-factor/verify'     => array(
			'area'       => 'Auth',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/auth/security/two-factor/disable'    => array(
			'area'       => 'Auth',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/auth/security/two-factor/backup-codes' => array(
			'area'       => 'Auth',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/auth/security/backup-codes/download' => array(
			'area'       => 'Auth',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'DELETE /api/v1/auth/security/sessions'            => array(
			'area'       => 'Auth',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'DELETE /api/v1/auth/security/sessions/{id}'       => array(
			'area'       => 'Auth',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth' ),
		),

		// Users
		'GET /api/v1/users'                                => array(
			'area'       => 'Users',
			'capability' => 'manage_users',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),
		'POST /api/v1/users'                               => array(
			'area'       => 'Users',
			'capability' => 'manage_users',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),
		'GET /api/v1/users/me'                             => array(
			'area'       => 'Users',
			'capability' => 'authenticated',
			'coverage'   => array( 'contract', 'smoke', 'e2e' ),
		),
		'PUT /api/v1/users/me'                             => array(
			'area'       => 'Users',
			'capability' => 'manage_profile',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),
		'PUT /api/v1/users/{username}'                     => array(
			'area'       => 'Users',
			'capability' => 'manage_users',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),
		'DELETE /api/v1/users/{username}'                  => array(
			'area'       => 'Users',
			'capability' => 'manage_users',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),

		// Links (URLs)
		'GET /api/v1/urls'                                 => array(
			'area'       => 'Links',
			'capability' => 'view_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'GET /api/v1/urls/export'                          => array(
			'area'       => 'Links',
			'capability' => 'view_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'GET /api/v1/urls/{id}'                            => array(
			'area'       => 'Links',
			'capability' => 'view_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'POST /api/v1/urls'                                => array(
			'area'       => 'Links',
			'capability' => 'create_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'POST /api/v1/urls/bulk'                           => array(
			'area'       => 'Links',
			'capability' => 'create_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'POST /api/v1/urls/restore'                        => array(
			'area'       => 'Links',
			'capability' => 'edit_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'POST /api/v1/urls/{id}/restore'                   => array(
			'area'       => 'Links',
			'capability' => 'edit_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'POST /api/v1/urls/{id}'                           => array(
			'area'       => 'Links',
			'capability' => 'edit_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'PUT /api/v1/urls/{id}'                            => array(
			'area'       => 'Links',
			'capability' => 'edit_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'DELETE /api/v1/urls'                              => array(
			'area'       => 'Links',
			'capability' => 'delete_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'DELETE /api/v1/urls/trash'                        => array(
			'area'       => 'Links',
			'capability' => 'delete_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'DELETE /api/v1/urls/bulk'                         => array(
			'area'       => 'Links',
			'capability' => 'delete_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'DELETE /api/v1/urls/{id}'                         => array(
			'area'       => 'Links',
			'capability' => 'delete_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),

		// Analytics
		'GET /api/v1/analytics'                            => array(
			'area'       => 'Analytics',
			'capability' => 'view_site_analytics',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'GET /api/v1/analytics/activity'                   => array(
			'area'       => 'Analytics',
			'capability' => 'view_site_analytics',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'GET /api/v1/analytics/recent-clicks'              => array(
			'area'       => 'Analytics',
			'capability' => 'view_site_analytics',
			'coverage'   => array( 'contract', 'integration' ),
		),
		'GET /api/v1/analytics/activity/history'           => array(
			'area'       => 'Analytics',
			'capability' => 'view_site_analytics',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'POST /api/v1/analytics/activity/{id}/restore'     => array(
			'area'       => 'Analytics',
			'capability' => 'edit_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'DELETE /api/v1/analytics/activity'                => array(
			'area'       => 'Analytics',
			'capability' => 'delete_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'DELETE /api/v1/analytics/activity/bulk'           => array(
			'area'       => 'Analytics',
			'capability' => 'delete_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'DELETE /api/v1/analytics/activity/{id}'           => array(
			'area'       => 'Analytics',
			'capability' => 'delete_all_links',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'GET /api/v1/analytics/url/{id}/location'          => array(
			'area'       => 'Analytics',
			'capability' => 'view_site_analytics',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'GET /api/v1/analytics/url/{id}/stats'             => array(
			'area'       => 'Analytics',
			'capability' => 'view_site_analytics',
			'coverage'   => array( 'contract', 'e2e' ),
		),

		// Webhooks
		'GET /api/v1/webhooks'                             => array(
			'area'       => 'Webhooks',
			'capability' => 'manage_webhooks',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/webhooks'                            => array(
			'area'       => 'Webhooks',
			'capability' => 'manage_webhooks',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/webhooks/test'                       => array(
			'area'       => 'Webhooks',
			'capability' => 'manage_webhooks',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/webhooks/{id}/test'                  => array(
			'area'       => 'Webhooks',
			'capability' => 'manage_webhooks',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'PUT /api/v1/webhooks/{id}'                        => array(
			'area'       => 'Webhooks',
			'capability' => 'manage_webhooks',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'DELETE /api/v1/webhooks/{id}'                     => array(
			'area'       => 'Webhooks',
			'capability' => 'manage_webhooks',
			'coverage'   => array( 'contract', 'auth' ),
		),

		// Settings & System Configuration
		'GET /api/v1/system/general'                       => array(
			'area'       => 'Settings',
			'capability' => 'manage_site_settings',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),
		'POST /api/v1/system/general'                      => array(
			'area'       => 'Settings',
			'capability' => 'manage_site_settings',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),
		'GET /api/v1/system/cache'                         => array(
			'area'       => 'Settings',
			'capability' => 'manage_performance',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/cache'                        => array(
			'area'       => 'Settings',
			'capability' => 'manage_performance',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/cache/clear'                  => array(
			'area'       => 'Settings',
			'capability' => 'manage_performance',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'GET /api/v1/system/captcha'                       => array(
			'area'       => 'Settings',
			'capability' => 'manage_site_settings',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/captcha'                      => array(
			'area'       => 'Settings',
			'capability' => 'manage_site_settings',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'GET /api/v1/system/geoip'                         => array(
			'area'       => 'Settings',
			'capability' => 'manage_location_data',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/geoip'                        => array(
			'area'       => 'Settings',
			'capability' => 'manage_location_data',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/geoip/download'               => array(
			'area'       => 'Settings',
			'capability' => 'manage_location_data',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'GET /api/v1/system/mail'                          => array(
			'area'       => 'Settings',
			'capability' => 'manage_mail_delivery',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/mail'                         => array(
			'area'       => 'Settings',
			'capability' => 'manage_mail_delivery',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/mail/test'                    => array(
			'area'       => 'Settings',
			'capability' => 'manage_mail_delivery',
			'coverage'   => array( 'contract', 'auth' ),
		),

		// System Notices, Diagnostics & Update
		'GET /api/v1/system/notices'                       => array(
			'area'       => 'System',
			'capability' => 'authenticated',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'GET /api/v1/system/status'                        => array(
			'area'       => 'System',
			'capability' => 'manage_site_settings',
			'coverage'   => array( 'contract', 'auth', 'e2e' ),
		),
		'GET /api/v1/system/update'                        => array(
			'area'       => 'System',
			'capability' => 'manage_updates',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/update/check'                 => array(
			'area'       => 'System',
			'capability' => 'manage_updates',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/update/apply'                 => array(
			'area'       => 'System',
			'capability' => 'manage_updates',
			'coverage'   => array( 'contract', 'auth', 'integration' ),
		),
		'POST /api/v1/system/update/reinstall'             => array(
			'area'       => 'System',
			'capability' => 'manage_updates',
			'coverage'   => array( 'contract', 'auth' ),
		),
		'POST /api/v1/system/update/database'              => array(
			'area'       => 'System',
			'capability' => 'manage_updates',
			'coverage'   => array( 'contract', 'auth' ),
		),

		// Public Catch-All Redirects
		'GET /{id}'                                        => array(
			'area'       => 'Redirect',
			'capability' => 'public',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'HEAD /{id}'                                       => array(
			'area'       => 'Redirect',
			'capability' => 'public',
			'coverage'   => array( 'contract' ),
		),
		'POST /{id}'                                       => array(
			'area'       => 'Redirect',
			'capability' => 'public',
			'coverage'   => array( 'contract' ),
		),
		'GET /{id}/'                                       => array(
			'area'       => 'Redirect',
			'capability' => 'public',
			'coverage'   => array( 'contract', 'e2e' ),
		),
		'HEAD /{id}/'                                      => array(
			'area'       => 'Redirect',
			'capability' => 'public',
			'coverage'   => array( 'contract' ),
		),
		'POST /{id}/'                                      => array(
			'area'       => 'Redirect',
			'capability' => 'public',
			'coverage'   => array( 'contract' ),
		),
	);

	private Application $application;

	protected function setUp(): void {
		parent::setUp();

		$mock_stmt = $this->createMock( \PDOStatement::class );
		$mock_stmt->method( 'execute' )->willReturn( true );
		$mock_stmt->method( 'fetch' )->willReturn( false );
		$mock_stmt->method( 'fetchAll' )->willReturn( array() );

		$mock_pdo = $this->createMock( \PDO::class );
		$mock_pdo->method( 'prepare' )->willReturn( $mock_stmt );

		$config = array(
			Constants::DB_PREFIX   => 'peak_',
			Constants::DB_DATABASE => 'peakurl',
			Constants::CONTENT_DIR => sys_get_temp_dir() . '/peakurl_test_content',
			Constants::SITE_URL    => 'https://peakurl.dev',
		);

		$connection = new class( $config, $mock_pdo ) extends Connection {
			private \PDO $mock_pdo;

			public function __construct( array $config, \PDO $mock_pdo ) {
				parent::__construct( $config );
				$this->mock_pdo = $mock_pdo;
			}

			public function get_connection(): \PDO {
				return $this->mock_pdo;
			}
		};

		$this->application = new Application( $connection, $config );
	}

	public function test_all_registered_application_routes_are_in_inventory(): void {
		$registered_routes = $this->extract_registered_routes();
		$unclassified      = array();

		foreach ( $registered_routes as $route_key ) {
			if ( ! isset( self::ROUTE_INVENTORY[ $route_key ] ) ) {
				$unclassified[] = $route_key;
			}
		}

		$this->assertEmpty(
			$unclassified,
			sprintf(
				'The following route(s) are registered in Application but missing from the Route Inventory classification: %s',
				implode( ', ', $unclassified )
			)
		);
	}

	public function test_no_orphaned_routes_in_inventory(): void {
		$registered_routes = array_flip( $this->extract_registered_routes() );
		$orphaned          = array();

		foreach ( array_keys( self::ROUTE_INVENTORY ) as $inventory_key ) {
			if ( ! isset( $registered_routes[ $inventory_key ] ) ) {
				$orphaned[] = $inventory_key;
			}
		}

		$this->assertEmpty(
			$orphaned,
			sprintf(
				'The following route(s) exist in Route Inventory but are not registered in Application: %s',
				implode( ', ', $orphaned )
			)
		);
	}

	public function test_all_inventory_routes_have_valid_metadata_and_coverage(): void {
		$valid_coverage_tags = array( 'unit', 'contract', 'integration', 'e2e', 'auth', 'smoke' );

		foreach ( self::ROUTE_INVENTORY as $route_key => $meta ) {
			$this->assertNotEmpty( $meta['area'], "Route {$route_key} must specify an area." );
			$this->assertNotEmpty( $meta['capability'], "Route {$route_key} must specify a capability or auth requirement." );
			$this->assertIsArray( $meta['coverage'], "Route {$route_key} must define coverage tags." );
			$this->assertNotEmpty( $meta['coverage'], "Route {$route_key} must have at least one coverage tag." );

			foreach ( $meta['coverage'] as $tag ) {
				$this->assertContains(
					$tag,
					$valid_coverage_tags,
					"Route {$route_key} contains invalid coverage tag '{$tag}'."
				);
			}
		}
	}

	/**
	 * Extract registered routes from Application router as "METHOD path" strings.
	 *
	 * @return array<int, string>
	 */
	private function extract_registered_routes(): array {
		$router = $this->application->get_router();
		$routes = $router->get_routes();
		$list   = array();

		foreach ( $routes as $method => $method_routes ) {
			foreach ( $method_routes as $route ) {
				$list[] = $method . ' ' . $route['path'];
			}
		}

		sort( $list, SORT_STRING );
		return $list;
	}
}
