<?php
/**
 * Unit tests for link_export_prepared action hook.
 *
 * @package PeakURL\Tests\Unit\Links
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Links\Controller as LinksController;
use PeakURL\Features\Links\LinkCollection;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Http\Request;
use RuntimeException;

class LinkExportHookTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private MockObject&AuthService $auth_service;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&Roles $roles;
	private LinkCollection $collection;

	/**
	 * Default authenticated administrator user fixture.
	 *
	 * @var array<string, string>
	 */
	private array $default_user;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->default_user = array(
			'id'       => 'user_admin_1',
			'username' => 'alex_admin',
			'role'     => 'admin',
		);

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->auth_service      = $this->createMock( AuthService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->roles             = $this->createMock( Roles::class );
		$this->roles->method( 'has_capability' )->willReturn( true );

		$this->repository->method( 'get_link_collection_query' )->willReturn(
			array(
				'where'       => 'WHERE 1=1',
				'params'      => array(),
				'sortBy'      => 'u.created_at',
				'sortOrder'   => 'DESC',
				'statsParams' => array(),
			)
		);

		$this->collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(
				'id'             => (string) ( $row['id'] ?? '' ),
				'shortCode'      => (string) ( $row['short_code'] ?? '' ),
				'title'          => (string) ( $row['title'] ?? '' ),
				'destinationUrl' => (string) ( $row['destination_url'] ?? '' ),
				'clicks'         => (int) ( $row['clicks'] ?? 0 ),
				'uniqueClicks'   => (int) ( $row['unique_clicks'] ?? 0 ),
			),
			$this->roles,
			fn( array $url, array $user, ?string $owner_id ): array => ( new Roles() )->can_view_link_analytics( $user, $owner_id )
				? $url
				: array_merge(
					$url,
					array(
						'clicks'       => null,
						'uniqueClicks' => null,
					)
				)
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	/**
	 * Configure repository and auth mocks for an export request.
	 *
	 * @param array<int, array<string, mixed>> $rows Database rows returned by repository.
	 * @param array<string, mixed>|null        $user Current user payload, defaults to admin.
	 * @return Request HTTP request configured for the mock call.
	 */
	private function mock_export( array $rows = array(), ?array $user = null ): Request {
		$user    = $user ?? $this->default_user;
		$request = new Request( 'GET', '/api/v1/urls/export', array(), array() );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_rows' )->willReturn( $rows );

		return $request;
	}

	public function test_link_export_prepared_fires_with_export_payload_and_actor_context(): void {
		$user = array(
			'id'            => 'user_admin_1',
			'username'      => 'alex_admin',
			'role'          => 'admin',
			'email'         => 'admin@example.org',
			'password_hash' => 'hash_should_not_leak',
		);

		$rows = array(
			array(
				'id'              => 'link_1',
				'user_id'         => 'user_admin_1',
				'short_code'      => 'code1',
				'title'           => 'Title 1',
				'destination_url' => 'https://example.com/1',
				'clicks'          => 12,
				'unique_clicks'   => 10,
			),
			array(
				'id'              => 'link_2',
				'user_id'         => 'user_admin_1',
				'short_code'      => 'code2',
				'title'           => 'Title 2',
				'destination_url' => 'https://example.com/2',
				'clicks'          => 4,
				'unique_clicks'   => 3,
			),
		);

		$request         = $this->mock_export( $rows, $user );
		$captured_export = null;
		$captured_actor  = null;
		$fire_count      = 0;

		\add_action(
			'link_export_prepared',
			function ( array $export, array $actor ) use ( &$captured_export, &$captured_actor, &$fire_count ): void {
				++$fire_count;
				$captured_export = $export;
				$captured_actor  = $actor;
			},
			10,
			2
		);

		$result = $this->collection->export_urls( $request );

		$this->assertSame( 1, $fire_count );
		$this->assertSame( $result, $captured_export );
		$this->assertCount( 2, $captured_export['items'] );
		$this->assertSame( 2, $captured_export['meta']['totalItems'] );
		$this->assertSame( 'link_1', $captured_export['items'][0]['id'] );
		$this->assertSame( 'link_2', $captured_export['items'][1]['id'] );
		$this->assertSame(
			array(
				'id'       => 'user_admin_1',
				'username' => 'alex_admin',
				'role'     => 'admin',
			),
			$captured_actor
		);
		$this->assertArrayNotHasKey( 'email', $captured_actor );
		$this->assertArrayNotHasKey( 'password_hash', $captured_actor );
	}

	public function test_link_export_prepared_returns_identical_payload_without_listener(): void {
		$rows = array(
			array(
				'id'              => 'link_1',
				'user_id'         => 'user_admin_1',
				'short_code'      => 'code1',
				'title'           => 'Title 1',
				'destination_url' => 'https://example.com/1',
			),
		);

		$request = $this->mock_export( $rows );

		$this->assertFalse( \has_action( 'link_export_prepared' ) );

		$result = $this->collection->export_urls( $request );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 1, $result['meta']['totalItems'] );
		$this->assertSame( 'link_1', $result['items'][0]['id'] );
	}

	public function test_link_export_prepared_fires_on_empty_export(): void {
		$request         = $this->mock_export( array() );
		$captured_export = null;
		$fire_count      = 0;

		\add_action(
			'link_export_prepared',
			function ( array $export ) use ( &$captured_export, &$fire_count ): void {
				++$fire_count;
				$captured_export = $export;
			}
		);

		$result = $this->collection->export_urls( $request );

		$this->assertSame( 1, $fire_count );
		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['meta']['totalItems'] );
		$this->assertSame( $result, $captured_export );
	}

	public function test_link_export_prepared_does_not_fire_when_sorting_by_clicks_is_unauthorized(): void {
		$user = array(
			'id'       => 'user_editor_1',
			'username' => 'charlie_editor',
			'role'     => 'editor',
		);

		$request = $this->mock_export( array(), $user );
		$this->roles->method( 'is_admin' )->with( $user )->willReturn( false );

		$hook_called = false;
		\add_action(
			'link_export_prepared',
			function () use ( &$hook_called ): void {
				$hook_called = true;
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		try {
			$this->collection->export_urls( $request, array( 'sortBy' => 'clicks' ) );
		} finally {
			$this->assertFalse( $hook_called );
		}
	}

	public function test_link_export_prepared_redacts_analytics_for_non_admin_before_dispatch(): void {
		$user = array(
			'id'       => 'user_editor_1',
			'username' => 'charlie_editor',
			'role'     => 'editor',
		);

		$rows = array(
			array(
				'id'            => 'owned_link',
				'user_id'       => 'user_editor_1',
				'clicks'        => 20,
				'unique_clicks' => 15,
			),
			array(
				'id'            => 'foreign_link',
				'user_id'       => 'user_other_99',
				'clicks'        => 500,
				'unique_clicks' => 450,
			),
		);

		$request         = $this->mock_export( $rows, $user );
		$captured_export = null;

		\add_action(
			'link_export_prepared',
			function ( array $export ) use ( &$captured_export ): void {
				$captured_export = $export;
			}
		);

		$result = $this->collection->export_urls( $request );

		$this->assertSame( 20, $captured_export['items'][0]['clicks'] );
		$this->assertSame( 15, $captured_export['items'][0]['uniqueClicks'] );
		$this->assertNull( $captured_export['items'][1]['clicks'] );
		$this->assertNull( $captured_export['items'][1]['uniqueClicks'] );
		$this->assertSame( $result, $captured_export );
	}

	public function test_link_export_prepared_isolates_observer_exceptions(): void {
		$rows = array(
			array(
				'id'              => 'link_safe_1',
				'user_id'         => 'user_admin_1',
				'short_code'      => 'safe1',
				'title'           => 'Safe 1',
				'destination_url' => 'https://example.com/safe',
			),
		);

		$request = $this->mock_export( $rows );

		\add_action(
			'link_export_prepared',
			function (): void {
				throw new RuntimeException( 'Simulated observer crash' );
			}
		);

		$result = $this->collection->export_urls( $request );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'link_safe_1', $result['items'][0]['id'] );
		$this->assertSame( 1, $result['meta']['totalItems'] );
	}

	public function test_link_export_prepared_supports_multiple_listeners_in_priority_order(): void {
		$request         = $this->mock_export( array() );
		$execution_order = array();

		\add_action(
			'link_export_prepared',
			function () use ( &$execution_order ): void {
				$execution_order[] = 'second_priority_20';
			},
			20
		);

		\add_action(
			'link_export_prepared',
			function () use ( &$execution_order ): void {
				$execution_order[] = 'first_priority_5';
			},
			5
		);

		$this->collection->export_urls( $request );

		$this->assertSame( array( 'first_priority_5', 'second_priority_20' ), $execution_order );
	}

	public function test_controller_export_returns_success_envelope(): void {
		$links_service = $this->createMock( LinksService::class );
		$controller    = new LinksController( $links_service );

		$export_data = array(
			'items' => array(
				array(
					'id'        => 'exp_ctrl_1',
					'shortCode' => 'ctrl1',
				),
			),
			'meta'  => array(
				'totalItems' => 1,
			),
		);

		$request = new Request( 'GET', '/api/v1/urls/export', array(), array() );

		$links_service->expects( $this->once() )
			->method( 'export_urls' )
			->with(
				$request,
				array(
					'sortBy'    => 'createdAt',
					'sortOrder' => 'desc',
					'search'    => '',
				)
			)
			->willReturn( $export_data );

		$response = $controller->export( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'URLs export loaded.', $response['body']['message'] );
		$this->assertSame( $export_data, $response['body']['data'] );
	}
}
