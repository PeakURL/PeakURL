<?php
/**
 * Unit tests for Schema advisory lock naming and scoping.
 *
 * @package PeakURL\Tests\Unit\Database
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\Schema;
use PDO;

class SchemaLockTest extends TestCase {

	private function create_mock_connection( string $db_name, string $prefix ): Connection {
		$pdo  = $this->createMock( PDO::class );
		$conn = $this->createMock( Connection::class );

		$conn->method( 'get_connection' )->willReturn( $pdo );
		$conn->method( 'get_config' )->willReturn(
			array(
				Constants::DB_DATABASE => $db_name,
				Constants::DB_PREFIX   => $prefix,
			)
		);
		$conn->method( 'get_table_prefix' )->willReturn( $prefix );

		return $conn;
	}

	public function test_lock_name_is_deterministic_and_scoped(): void {
		$conn1   = $this->create_mock_connection( 'peakurl_prod', 'pk_' );
		$schema1 = new Schema( $conn1 );

		$lock_name1 = $schema1->get_lock_name();

		$this->assertStringStartsWith( 'peakurl_schema_repair_', $lock_name1 );
		$this->assertLessThanOrEqual( 64, strlen( $lock_name1 ), 'MySQL advisory lock name must not exceed 64 characters.' );

		// Repeated call must return identical lock name.
		$this->assertSame( $lock_name1, $schema1->get_lock_name() );
	}

	public function test_different_databases_with_same_prefix_produce_different_locks(): void {
		$schema_prod = new Schema( $this->create_mock_connection( 'peakurl_production', 'pk_' ) );
		$schema_dev  = new Schema( $this->create_mock_connection( 'peakurl_staging', 'pk_' ) );

		$this->assertNotSame(
			$schema_prod->get_lock_name(),
			$schema_dev->get_lock_name(),
			'Independent databases on the same MySQL server must not share an advisory lock.'
		);
	}

	public function test_different_prefixes_in_same_database_produce_different_locks(): void {
		$schema1 = new Schema( $this->create_mock_connection( 'peakurl', 'site1_' ) );
		$schema2 = new Schema( $this->create_mock_connection( 'peakurl', 'site2_' ) );

		$this->assertNotSame(
			$schema1->get_lock_name(),
			$schema2->get_lock_name(),
			'Different table prefixes in the same database must not share an advisory lock.'
		);
	}
}
