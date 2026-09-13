<?php
/**
 * Unit tests for Webhooks Validator (QA-011 regression).
 *
 * @package PeakURL\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Webhooks\Validator;
use PeakURL\Core\Errors\ApiException;

class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new Validator();
	}

	public function test_validate_events_accepts_supported_events(): void {
		$events = array( 'link.created', 'link.updated', 'link.clicked', 'link.deleted' );
		$result = $this->validator->validate_events( $events );
		$this->assertSame( $events, $result );
	}

	public function test_validate_events_rejects_unsupported_events(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_events( array( 'invalid.event.name' ) );
	}

	public function test_validate_events_rejects_empty_array(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_events( array() );
	}

	public function test_validate_create_rejects_invalid_url(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->validator->validate_create(
			array(
				'url'    => 'not-a-valid-url',
				'events' => array( 'link.created' ),
			)
		);
	}
}
