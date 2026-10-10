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
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Links\LinkCollection;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Http\Request;

class LinkCollectionTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private MockObject&AuthService $auth_service;
	private MockObject&AnalyticsService $analytics_service;
	private LinkCollection $collection;

	protected function setUp(): void {
		parent::setUp();

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->auth_service      = $this->createMock( AuthService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );

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
}
