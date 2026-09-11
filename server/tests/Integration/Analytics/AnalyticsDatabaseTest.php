<?php
/**
 * Integration tests for Analytics database method calls (QA-002 regression).
 *
 * @package PeakURL\Tests\Integration\Analytics
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Analytics;

use PHPUnit\Framework\TestCase;
use PeakURL\Services\Database\PeakURL_DB;
use ReflectionClass;

class AnalyticsDatabaseTest extends TestCase {

	public function test_peakurl_db_has_get_results_and_get_var(): void {
		$ref = new ReflectionClass( PeakURL_DB::class );

		$this->assertTrue( $ref->hasMethod( 'get_results' ) );
		$this->assertTrue( $ref->hasMethod( 'get_var' ) );
		$this->assertTrue( $ref->hasMethod( 'get_row' ) );
		$this->assertTrue( $ref->hasMethod( 'query' ) );
	}

	public function test_peakurl_db_does_not_have_obsolete_query_all(): void {
		$ref = new ReflectionClass( PeakURL_DB::class );

		$this->assertFalse( $ref->hasMethod( 'query_all' ) );
		$this->assertFalse( $ref->hasMethod( 'query_value' ) );
	}
}
