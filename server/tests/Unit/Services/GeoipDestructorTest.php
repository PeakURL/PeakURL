<?php
/**
 * Unit tests for Geoip service destructor lifecycle contracts.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Services\Geoip;
use PeakURL\Services\Geoip\Context as GeoipContext;
use ReflectionClass;

class GeoipDestructorTest extends TestCase {

	public function test_destructor_is_safe_when_context_is_uninitialized(): void {
		$ref      = new ReflectionClass( Geoip::class );
		$instance = $ref->newInstanceWithoutConstructor();

		// Should not throw Error: Typed property PeakURL\Services\Geoip::$context must not be accessed before initialization
		unset( $instance );
		$this->assertTrue( true, 'Destructor must safely handle uninitialized context property.' );
	}

	public function test_destructor_invokes_close_reader_when_context_is_initialized(): void {
		$mock_context = $this->createMock( GeoipContext::class );
		$mock_context->expects( $this->once() )
			->method( 'close_reader' );

		$ref      = new ReflectionClass( Geoip::class );
		$instance = $ref->newInstanceWithoutConstructor();

		$prop = $ref->getProperty( 'context' );
		$prop->setValue( $instance, $mock_context );

		unset( $instance );
	}
}
