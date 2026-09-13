<?php
/**
 * Integration tests for Webhooks controller test parameter extraction (QA-008).
 *
 * @package PeakURL\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Webhooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Webhooks\Controller as WebhooksController;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;

class WebhooksTest extends TestCase {

	public function test_webhooks_controller_accepts_id_from_body_for_test_endpoint(): void {
		$mock_service = $this->createMock( WebhooksService::class );
		$mock_service->expects( $this->once() )
			->method( 'test_webhook' )
			->with(
				$this->isInstanceOf( Request::class ),
				$this->equalTo( 'whk_12345' )
			)
			->willReturn(
				array(
					'webhookId'  => 'whk_12345',
					'statusCode' => 200,
					'success'    => true,
				)
			);

		$controller = new WebhooksController( $mock_service );
		$request    = new Request(
			'POST',
			'/api/v1/webhooks/test',
			array(),
			array( 'id' => 'whk_12345' )
		);

		$response = $controller->test( $request );
		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['success'] );
	}
}
