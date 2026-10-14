<?php
/**
 * Unit tests for link collection domain service.
 *
 * @package PeakURL\Tests\Unit\Links
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Links\LinkCollection;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Http\Request;

class LinkCollectionTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private MockObject&AuthService $auth_service;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&Roles $roles;
	private LinkCollection $collection;

	protected function setUp(): void {
		parent::setUp();

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->auth_service      = $this->createMock( AuthService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->roles             = $this->createMock( Roles::class );
		$this->roles->method( 'has_capability' )->willReturn( true );

		$this->collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(
				'id'             => (string) ( $row['id'] ?? '' ),
				'shortCode'      => (string) ( $row['short_code'] ?? '' ),
				'title'          => (string) ( $row['title'] ?? '' ),
				'destinationUrl' => (string) ( $row['destination_url'] ?? '' ),
				'status'         => (string) ( $row['status'] ?? 'active' ),
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
				),
			fn( ?array $row ): ?array => null !== $row ? array( 'status' => (string) ( $row['status'] ?? 'healthy' ) ) : null
		);
	}

	public function test_list_urls_with_pagination_and_default_parameters(): void {
		$user    = array(
			'id'   => 'user_1',
			'role' => 'admin',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array(
			'page'  => 1,
			'limit' => 10,
		);

		$prepared = array(
			'where'       => 'WHERE 1=1',
			'params'      => array(),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$rows = array(
			array(
				'id'              => 'l1',
				'short_code'      => 'code1',
				'title'           => 'Title 1',
				'destination_url' => 'https://example.com/1',
				'status'          => 'active',
			),
		);

		$aggregates = array(
			'totalClicks'  => 50,
			'uniqueClicks' => 40,
			'activeLinks'  => 1,
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'count_link_rows' )->willReturn( 1 );
		$this->repository->method( 'get_link_collection_stats' )->willReturn( $aggregates );
		$this->repository->method( 'get_link_rows' )->willReturn( $rows );
		$this->repository->method( 'count_trashed_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'count_expired_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'get_link_health_by_ids' )->with( array( 'l1' ) )->willReturn(
			array(
				'l1' => array( 'status' => 'healthy' ),
			)
		);

		$result = $this->collection->list_urls( $request, $query );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'meta', $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'l1', $result['items'][0]['id'] );
		$this->assertSame( array( 'status' => 'healthy' ), $result['items'][0]['health'] );
		$this->assertSame( 1, $result['meta']['page'] );
		$this->assertSame( 10, $result['meta']['limit'] );
		$this->assertSame( 1, $result['meta']['totalItems'] );
		$this->assertSame( 1, $result['meta']['totalPages'] );
		$this->assertSame( 50, $result['meta']['totalClicks'] );
		$this->assertSame( 40, $result['meta']['uniqueClicks'] );
		$this->assertSame( 1, $result['meta']['activeLinks'] );
	}

	public function test_list_urls_with_comparison_period_range(): void {
		$user    = array(
			'id'   => 'user_1',
			'role' => 'admin',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array(
			'page'  => 1,
			'limit' => 10,
			'range' => '7d',
		);

		$prepared = array(
			'where'       => 'WHERE 1=1',
			'params'      => array(),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$aggregates = array(
			'totalClicks'  => 100,
			'uniqueClicks' => 80,
			'activeLinks'  => 5,
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'count_link_rows' )->willReturn( 5 );
		$this->repository->method( 'get_link_collection_stats' )->willReturn( $aggregates );
		$this->repository->method( 'get_link_rows' )->willReturn( array() );
		$this->repository->method( 'count_trashed_links' )->willReturn( 0 );
		$this->repository->method( 'count_expired_links' )->willReturn( 0 );

		$this->analytics_service->method( 'get_analytics_period' )->with( 7 )->willReturn(
			array(
				'start_at' => '2026-10-01 00:00:00',
				'end_at'   => '2026-10-08 00:00:00',
			)
		);
		$this->analytics_service->method( 'get_last_month_period' )->willReturn(
			array(
				'start_at' => '2026-09-24 00:00:00',
				'end_at'   => '2026-10-01 00:00:00',
			)
		);

		$this->repository->method( 'get_link_click_totals' )->willReturn(
			array(
				'totalClicks'  => 60,
				'uniqueClicks' => 45,
			)
		);

		$result = $this->collection->list_urls( $request, $query );

		$this->assertSame( 60, $result['meta']['lastPeriodTotalClicks'] );
		$this->assertSame( 45, $result['meta']['lastPeriodUniqueClicks'] );
	}

	public function test_list_urls_with_empty_result_set(): void {
		$user    = array(
			'id'   => 'user_1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array(
			'search' => 'nonexistent',
		);

		$prepared = array(
			'where'       => 'WHERE u.title LIKE :search',
			'params'      => array( 'search' => '%nonexistent%' ),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$aggregates = array(
			'totalClicks'  => 0,
			'uniqueClicks' => 0,
			'activeLinks'  => 0,
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'count_link_rows' )->willReturn( 0 );
		$this->repository->method( 'get_link_collection_stats' )->willReturn( $aggregates );
		$this->repository->method( 'get_link_rows' )->willReturn( array() );
		$this->repository->method( 'count_trashed_links' )->willReturn( 0 );
		$this->repository->method( 'count_expired_links' )->willReturn( 0 );

		$result = $this->collection->list_urls( $request, $query );

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['meta']['totalItems'] );
		$this->assertSame( 1, $result['meta']['totalPages'] );
	}

	public function test_export_urls_queries_all_accessible_rows_without_pagination_limit(): void {
		$user    = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$request = new Request( 'GET', '/api/v1/urls/export', array(), array() );
		$query   = array(
			'status' => 'active',
		);

		$prepared = array(
			'where'       => 'WHERE u.status = :status',
			'params'      => array( 'status' => 'active' ),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$rows = array(
			array(
				'id'              => 'exp1',
				'short_code'      => 'e1',
				'title'           => 'Export 1',
				'destination_url' => 'https://example.com/e1',
				'status'          => 'active',
			),
			array(
				'id'              => 'exp2',
				'short_code'      => 'e2',
				'title'           => 'Export 2',
				'destination_url' => 'https://example.com/e2',
				'status'          => 'active',
			),
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->expects( $this->once() )
			->method( 'get_link_rows' )
			->with( $prepared['where'], $prepared['params'], $prepared['sortBy'], $prepared['sortOrder'], null, null, $prepared['statsParams'] )
			->willReturn( $rows );

		$result = $this->collection->export_urls( $request, $query );

		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 2, $result['meta']['totalItems'] );
		$this->assertSame( 'exp1', $result['items'][0]['id'] );
		$this->assertSame( 'exp2', $result['items'][1]['id'] );
	}

	public function test_count_trashed_and_expired_links(): void {
		$user    = array(
			'id'   => 'user_test',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'count_trashed_links' )->with( $user )->willReturn( 4 );
		$this->repository->method( 'count_expired_links' )->with( $user )->willReturn( 2 );

		$this->assertSame( 4, $this->collection->count_trashed_links( $request ) );
		$this->assertSame( 2, $this->collection->count_expired_links( $request ) );
	}

	public function test_list_urls_nulls_out_click_metrics_when_view_analytics_is_denied(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' !== $cap
		);

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(
				'id'           => (string) ( $row['id'] ?? '' ),
				'clicks'       => 50,
				'uniqueClicks' => 40,
			),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $roles->has_capability( $user, 'view_analytics' )
				? $url
				: array_merge(
					$url,
					array(
						'clicks'       => null,
						'uniqueClicks' => null,
					)
				)
		);

		$user    = array(
			'id'   => 'user_no_analytics',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array(
			'page'  => 1,
			'limit' => 10,
		);

		$prepared = array(
			'where'       => 'WHERE 1=1',
			'params'      => array(),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$rows = array(
			array(
				'id'         => 'l1',
				'short_code' => 'code1',
			),
		);

		$aggregates = array(
			'totalClicks'            => 50,
			'uniqueClicks'           => 40,
			'lastPeriodTotalClicks'  => 30,
			'lastPeriodUniqueClicks' => 25,
			'activeLinks'            => 1,
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'count_link_rows' )->willReturn( 1 );
		$this->repository->method( 'get_link_collection_stats' )->willReturn( $aggregates );
		$this->repository->method( 'get_link_rows' )->willReturn( $rows );
		$this->repository->method( 'count_trashed_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'count_expired_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'get_link_health_by_ids' )->willReturn( array() );

		$result = $collection->list_urls( $request, $query );

		$this->assertNull( $result['meta']['totalClicks'], 'totalClicks must be null when view_analytics is denied' );
		$this->assertNull( $result['meta']['uniqueClicks'], 'uniqueClicks must be null when view_analytics is denied' );
		$this->assertNull( $result['meta']['lastPeriodTotalClicks'], 'lastPeriodTotalClicks must be null when view_analytics is denied' );
		$this->assertNull( $result['meta']['lastPeriodUniqueClicks'], 'lastPeriodUniqueClicks must be null when view_analytics is denied' );
		$this->assertSame( 1, $result['meta']['activeLinks'] );
		$this->assertNull( $result['items'][0]['clicks'], 'Item clicks must be null when view_analytics is denied' );
		$this->assertNull( $result['items'][0]['uniqueClicks'], 'Item uniqueClicks must be null when view_analytics is denied' );
	}

	public function test_export_urls_nulls_out_click_metrics_when_view_analytics_is_denied(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' !== $cap
		);

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(
				'id'           => (string) ( $row['id'] ?? '' ),
				'clicks'       => 10,
				'uniqueClicks' => 8,
			),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $roles->has_capability( $user, 'view_analytics' )
				? $url
				: array_merge(
					$url,
					array(
						'clicks'       => null,
						'uniqueClicks' => null,
					)
				)
		);

		$user    = array(
			'id'   => 'user_no_analytics',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls/export', array(), array() );

		$prepared = array(
			'where'       => 'WHERE 1=1',
			'params'      => array(),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$rows = array(
			array( 'id' => 'exp1' ),
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'get_link_rows' )->willReturn( $rows );

		$result = $collection->export_urls( $request );

		$this->assertNull( $result['items'][0]['clicks'] );
		$this->assertNull( $result['items'][0]['uniqueClicks'] );
	}

	public function test_list_urls_throws_403_when_sorting_by_clicks_without_view_analytics(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' !== $cap
		);

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array( 'id' => (string) ( $row['id'] ?? '' ) ),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $url
		);

		$user    = array(
			'id'   => 'u1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array( 'sortBy' => 'clicks' );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to sort by analytics metrics.' );

		$collection->list_urls( $request, $query );
	}

	public function test_list_urls_throws_403_when_sorting_by_unique_clicks_without_view_analytics(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' !== $cap
		);

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array( 'id' => (string) ( $row['id'] ?? '' ) ),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $url
		);

		$user    = array(
			'id'   => 'u1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array( 'sortBy' => 'uniqueClicks' );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to sort by analytics metrics.' );

		$collection->list_urls( $request, $query );
	}

	public function test_export_urls_throws_403_when_sorting_by_clicks_without_view_analytics(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' !== $cap
		);

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array( 'id' => (string) ( $row['id'] ?? '' ) ),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $url
		);

		$user    = array(
			'id'   => 'u1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls/export', array(), array() );
		$query   = array( 'sortBy' => 'clicks' );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to sort by analytics metrics.' );

		$collection->export_urls( $request, $query );
	}

	public function test_list_urls_throws_403_when_non_admin_sorts_by_clicks_even_with_view_analytics(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' === $cap
		);
		$roles->method( 'is_admin' )->willReturn( false );

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array( 'id' => (string) ( $row['id'] ?? '' ) ),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $url
		);

		$user    = array(
			'id'   => 'u1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array( 'sortBy' => 'clicks' );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to sort by analytics metrics.' );

		$collection->list_urls( $request, $query );
	}

	public function test_list_urls_throws_403_when_non_admin_sorts_by_unique_clicks_even_with_view_analytics(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' === $cap
		);
		$roles->method( 'is_admin' )->willReturn( false );

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array( 'id' => (string) ( $row['id'] ?? '' ) ),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $url
		);

		$user    = array(
			'id'   => 'u1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array( 'sortBy' => 'uniqueClicks' );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to sort by analytics metrics.' );

		$collection->list_urls( $request, $query );
	}

	public function test_export_urls_throws_403_when_non_admin_sorts_by_clicks_even_with_view_analytics(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' === $cap
		);
		$roles->method( 'is_admin' )->willReturn( false );

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array( 'id' => (string) ( $row['id'] ?? '' ) ),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $url
		);

		$user    = array(
			'id'   => 'u1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls/export', array(), array() );
		$query   = array( 'sortBy' => 'clicks' );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to sort by analytics metrics.' );

		$collection->export_urls( $request, $query );
	}

	public function test_export_urls_throws_403_when_non_admin_sorts_by_unique_clicks_even_with_view_analytics(): void {
		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )->willReturnCallback(
			fn( array $user, string $cap ): bool => 'view_analytics' === $cap
		);
		$roles->method( 'is_admin' )->willReturn( false );

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array( 'id' => (string) ( $row['id'] ?? '' ) ),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $url
		);

		$user    = array(
			'id'   => 'u1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls/export', array(), array() );
		$query   = array( 'sortBy' => 'uniqueClicks' );

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to sort by analytics metrics.' );

		$collection->export_urls( $request, $query );
	}

	public function test_list_urls_redacts_clicks_on_non_owned_links_for_non_admin_with_view_analytics(): void {
		$roles = new Roles();

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(
				'id'           => (string) ( $row['id'] ?? '' ),
				'userId'       => (string) ( $row['user_id'] ?? '' ),
				'clicks'       => (int) ( $row['clicks'] ?? 0 ),
				'uniqueClicks' => (int) ( $row['unique_clicks'] ?? 0 ),
			),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $roles->can_view_link_analytics( $user, $owner_id )
				? $url
				: array_merge(
					$url,
					array(
						'clicks'       => null,
						'uniqueClicks' => null,
					)
				)
		);

		$user    = array(
			'id'   => 'user_editor_1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );

		$prepared = array(
			'where'       => 'WHERE 1=1',
			'params'      => array(),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$rows = array(
			array(
				'id'            => 'l1',
				'user_id'       => 'user_editor_1',
				'clicks'        => 10,
				'unique_clicks' => 8,
			),
			array(
				'id'            => 'l2',
				'user_id'       => 'user_other_2',
				'clicks'        => 25,
				'unique_clicks' => 18,
			),
		);

		$aggregates = array(
			'totalClicks'  => 10,
			'uniqueClicks' => 8,
			'activeLinks'  => 2,
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'count_link_rows' )->willReturn( 2 );
		$this->repository->method( 'get_link_collection_stats' )->willReturn( $aggregates );
		$this->repository->method( 'get_link_rows' )->willReturn( $rows );
		$this->repository->method( 'count_trashed_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'count_expired_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'get_link_health_by_ids' )->willReturn( array() );

		$result = $collection->list_urls( $request, array() );

		$this->assertCount( 2, $result['items'] );
		// Owned link keeps numeric clicks
		$this->assertSame( 10, $result['items'][0]['clicks'] );
		$this->assertSame( 8, $result['items'][0]['uniqueClicks'] );

		// Non-owned link has redacted clicks
		$this->assertNull( $result['items'][1]['clicks'] );
		$this->assertNull( $result['items'][1]['uniqueClicks'] );
	}

	public function test_export_urls_redacts_clicks_on_non_owned_links_for_non_admin_with_view_analytics(): void {
		$roles = new Roles();

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(
				'id'           => (string) ( $row['id'] ?? '' ),
				'userId'       => (string) ( $row['user_id'] ?? '' ),
				'clicks'       => (int) ( $row['clicks'] ?? 0 ),
				'uniqueClicks' => (int) ( $row['unique_clicks'] ?? 0 ),
			),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $roles->can_view_link_analytics( $user, $owner_id )
				? $url
				: array_merge(
					$url,
					array(
						'clicks'       => null,
						'uniqueClicks' => null,
					)
				)
		);

		$user    = array(
			'id'   => 'user_editor_1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls/export', array(), array() );

		$prepared = array(
			'where'       => 'WHERE 1=1',
			'params'      => array(),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$rows = array(
			array(
				'id'            => 'exp1',
				'user_id'       => 'user_editor_1',
				'clicks'        => 5,
				'unique_clicks' => 4,
			),
			array(
				'id'            => 'exp2',
				'user_id'       => 'user_admin_99',
				'clicks'        => 50,
				'unique_clicks' => 35,
			),
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'get_link_rows' )->willReturn( $rows );

		$result = $collection->export_urls( $request );

		$this->assertCount( 2, $result['items'] );
		// Owned link keeps numeric clicks
		$this->assertSame( 5, $result['items'][0]['clicks'] );
		$this->assertSame( 4, $result['items'][0]['uniqueClicks'] );

		// Non-owned link has redacted clicks
		$this->assertNull( $result['items'][1]['clicks'] );
		$this->assertNull( $result['items'][1]['uniqueClicks'] );
	}

	public function test_list_urls_scopes_click_aggregates_to_owner_for_non_admin(): void {
		$roles = new Roles();

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array( 'id' => (string) ( $row['id'] ?? '' ) ),
			$roles,
			fn( array $url, array $user, ?string $owner_id ): array => $roles->can_view_link_analytics( $user, $owner_id )
				? $url
				: array_merge(
					$url,
					array(
						'clicks'       => null,
						'uniqueClicks' => null,
					)
				)
		);

		$user    = array(
			'id'   => 'user_editor_owner_1',
			'role' => 'editor',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );

		$prepared = array(
			'where'       => 'WHERE u.status = :status',
			'params'      => array( 'status' => 'active' ),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'count_link_rows' )->willReturn( 10 );

		// Verify get_link_collection_stats receives click_where with user_id scoping
		$this->repository->expects( $this->once() )
			->method( 'get_link_collection_stats' )
			->with(
				$prepared['where'],
				$prepared['params'],
				$prepared['statsParams'],
				$this->callback(
					function ( string $click_where ): bool {
						return str_contains( $click_where, 'u.user_id = :stats_owner_user_id' );
					}
				),
				$this->callback(
					function ( array $click_params ): bool {
						return isset( $click_params['stats_owner_user_id'] ) && 'user_editor_owner_1' === $click_params['stats_owner_user_id'];
					}
				),
				true
			)
			->willReturn(
				array(
					'totalClicks'  => 7,
					'uniqueClicks' => 5,
					'activeLinks'  => 10,
				)
			);

		$this->repository->method( 'get_link_rows' )->willReturn( array() );
		$this->repository->method( 'count_trashed_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'count_expired_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'get_link_health_by_ids' )->willReturn( array() );

		$result = $collection->list_urls( $request, array() );

		$this->assertSame( 7, $result['meta']['totalClicks'] );
		$this->assertSame( 5, $result['meta']['uniqueClicks'] );
		$this->assertSame( 10, $result['meta']['activeLinks'] );
	}

	public function test_link_collection_fails_closed_when_analytics_filter_is_omitted(): void {
		$this->expectException( \TypeError::class );
		/** @phpstan-ignore-next-line */
		new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(),
			$this->roles
		);
	}

	public function test_link_collection_fails_closed_when_analytics_filter_is_null(): void {
		$this->expectException( \TypeError::class );
		/** @phpstan-ignore-next-line */
		new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(),
			$this->roles,
			null
		);
	}

	public function test_list_urls_applies_injected_analytics_filter(): void {
		$filter_invoked = false;
		$custom_filter  = function ( array $url, array $user, ?string $owner_id ) use ( &$filter_invoked ): array {
			$filter_invoked      = true;
			$url['clicks']       = null;
			$url['uniqueClicks'] = null;
			return $url;
		};

		$collection = new LinkCollection(
			$this->repository,
			$this->auth_service,
			$this->analytics_service,
			fn( ?array $row ): array => array(
				'id'           => 'l_filtered',
				'clicks'       => 100,
				'uniqueClicks' => 80,
			),
			$this->roles,
			$custom_filter
		);

		$user    = array(
			'id'   => 'user_test',
			'role' => 'admin',
		);
		$request = new Request( 'GET', '/api/v1/urls', array(), array() );
		$query   = array(
			'page'  => 1,
			'limit' => 10,
		);

		$prepared = array(
			'where'       => 'WHERE 1=1',
			'params'      => array(),
			'sortBy'      => 'u.created_at',
			'sortOrder'   => 'DESC',
			'statsParams' => array(),
		);

		$rows = array(
			array(
				'id'      => 'l_filtered',
				'user_id' => 'user_test',
			),
		);

		$aggregates = array(
			'totalClicks'  => 100,
			'uniqueClicks' => 80,
			'activeLinks'  => 1,
		);

		$this->auth_service->method( 'get_current_user' )->with( $request )->willReturn( $user );
		$this->repository->method( 'get_link_collection_query' )->willReturn( $prepared );
		$this->repository->method( 'count_link_rows' )->willReturn( 1 );
		$this->repository->method( 'get_link_collection_stats' )->willReturn( $aggregates );
		$this->repository->method( 'get_link_rows' )->willReturn( $rows );
		$this->repository->method( 'count_trashed_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'count_expired_links' )->with( $user )->willReturn( 0 );
		$this->repository->method( 'get_link_health_by_ids' )->willReturn( array() );

		$result = $collection->list_urls( $request, $query );

		$this->assertTrue( $filter_invoked, 'Injected analytics filter must be invoked during list_urls' );
		$this->assertCount( 1, $result['items'] );
		$this->assertNull( $result['items'][0]['clicks'] );
		$this->assertNull( $result['items'][0]['uniqueClicks'] );
	}
}
