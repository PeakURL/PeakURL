<?php
/**
 * Unit tests for PeakURL_DB and Sql security boundaries.
 *
 * Verifies that the database abstraction boundary is enforced, escape hatches
 * are removed, identifiers are strictly validated, dynamic IN lists are
 * parameterized, and metacharacters remain data.
 *
 * @package PeakURL\Tests\Unit\Database
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Sql;
use ReflectionClass;

class PeakURLDbSecurityBoundaryTest extends TestCase {

	/**
	 * Verify PeakURL_DB does not expose a public prepare() escape hatch.
	 */
	public function test_peakurl_db_does_not_expose_prepare_escape_hatch(): void {
		$ref = new ReflectionClass( PeakURL_DB::class );

		$this->assertFalse(
			$ref->hasMethod( 'prepare' ),
			'PeakURL_DB must not expose a public prepare() method; statement execution must remain behind wrapper query methods.'
		);
	}

	/**
	 * Verify PeakURL_DB exposes only the intended public query, CRUD, and transaction API.
	 */
	public function test_peakurl_db_exposes_expected_safe_wrapper_interface(): void {
		$ref = new ReflectionClass( PeakURL_DB::class );

		$expected_methods = array(
			'get_results',
			'get_row',
			'get_col',
			'get_var',
			'query',
			'insert',
			'update',
			'delete',
			'truncate',
			'upsert',
			'get_results_by',
			'get_row_by',
			'get_col_by',
			'get_var_by',
			'get_results_where_in',
			'update_where_in',
			'delete_where_in',
			'in_placeholders',
			'esc_like',
			'begin_transaction',
			'commit',
			'roll_back',
			'in_transaction',
			'table_name',
			'table_exists',
		);

		foreach ( $expected_methods as $method ) {
			$this->assertTrue(
				$ref->hasMethod( $method ),
				sprintf( 'PeakURL_DB must provide public method [%s].', $method )
			);
			$this->assertTrue(
				$ref->getMethod( $method )->isPublic(),
				sprintf( 'PeakURL_DB method [%s] must be public.', $method )
			);
		}
	}

	/**
	 * Verify dynamic IN placeholder generation strictly parameterizes all values.
	 */
	public function test_in_placeholders_binds_all_values_as_named_parameters(): void {
		$values = array(
			'normal_val',
			"' OR '1'='1",
			'"; DROP TABLE users; --',
			"line\nbreak",
			'special_%_chars',
		);

		$result = Sql::in_placeholders( $values, 'wh_id' );

		$this->assertSame(
			':wh_id_0, :wh_id_1, :wh_id_2, :wh_id_3, :wh_id_4',
			$result['sql']
		);
		$this->assertCount( 5, $result['params'] );
		$this->assertSame( 'normal_val', $result['params']['wh_id_0'] );
		$this->assertSame( "' OR '1'='1", $result['params']['wh_id_1'] );
		$this->assertSame( '"; DROP TABLE users; --', $result['params']['wh_id_2'] );
		$this->assertSame( "line\nbreak", $result['params']['wh_id_3'] );
		$this->assertSame( 'special_%_chars', $result['params']['wh_id_4'] );
	}

	/**
	 * Verify where_in prepares safely quoted column names and bound parameters.
	 */
	public function test_where_in_quotes_identifiers_and_parameterizes_values(): void {
		$values = array( 'abc', "def' OR '1'='1" );
		$clause = Sql::where_in( 'webhook_id', $values, 'wid' );

		$this->assertSame( '`webhook_id` IN (:wid_0, :wid_1)', $clause['sql'] );
		$this->assertSame(
			array(
				'wid_0' => 'abc',
				'wid_1' => "def' OR '1'='1",
			),
			$clause['params']
		);
	}

	/**
	 * Verify valid SQL identifiers are accepted by the sanitizer.
	 */
	public function test_sanitize_identifier_accepts_valid_names(): void {
		$valid_names = array(
			'webhooks',
			'webhook_deliveries',
			'cron_jobs',
			'cron_runs',
			'link_health',
			'id',
			'created_at',
			'user_id_123',
			'status',
		);

		foreach ( $valid_names as $name ) {
			$this->assertSame( $name, Sql::sanitize_identifier( $name ) );
			$this->assertSame( '`' . $name . '`', Sql::quote_identifier( $name ) );
		}
	}

	/**
	 * Verify unsafe identifiers containing SQL metacharacters are rejected.
	 */
	public function test_sanitize_identifier_rejects_sql_metacharacters(): void {
		$unsafe_identifiers = array(
			'webhooks; DROP TABLE users; --',
			"webhooks' OR '1'='1",
			'webhooks/*',
			'webhooks@',
			'table with spaces',
			'table-with-dashes',
			'users WHERE id=1',
			'',
			'   ',
			'table.column',
			'schema.`table`',
		);

		foreach ( $unsafe_identifiers as $identifier ) {
			try {
				Sql::sanitize_identifier( $identifier );
				$this->fail( sprintf( 'Expected InvalidArgumentException for unsafe identifier [%s].', $identifier ) );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'invalid', $e->getMessage() );
			}
		}
	}

	/**
	 * Verify placeholder prefix is strictly sanitized against injection.
	 */
	public function test_in_placeholders_rejects_unsafe_prefix(): void {
		$this->expectException( InvalidArgumentException::class );
		Sql::in_placeholders( array( 'val1' ), 'prefix; DROP TABLE' );
	}

	/**
	 * Verify esc_like escapes wildcard characters and backslashes for literal matching.
	 */
	public function test_esc_like_escapes_wildcards_and_backslashes(): void {
		$raw      = 'user_input%with\\backslash_and%percent';
		$expected = 'user\\_input\\%with\\\\backslash\\_and\\%percent';

		$this->assertSame( $expected, Sql::esc_like( $raw ) );
	}

	/**
	 * Verify that production webhook methods normalize and bound batch limits in generated SQL.
	 */
	public function test_production_webhook_methods_bound_numeric_limits_in_sql(): void {
		$captured_sqls = array();

		$db = $this->createMock( PeakURL_DB::class );
		$db->method( 'query' )
			->willReturnCallback(
				function ( string $sql ) use ( &$captured_sqls ): int {
					$captured_sqls[] = $sql;
					return 0;
				}
			);
		$db->method( 'get_results' )->willReturn( array() );

		$service = new \PeakURL\Features\Webhooks\Service(
			$db,
			new \PeakURL\Features\Webhooks\Validator(),
			$this->createMock( \PeakURL\Features\Auth\Service::class ),
			new \PeakURL\Core\Auth\Roles(),
			new \PeakURL\Core\Auth\Authorization( new \PeakURL\Core\Auth\Roles() ),
			array(),
			$this->createMock( \PeakURL\Services\Crypto::class )
		);

		// Test representative inputs against claim_pending_deliveries
		$claim_cases = array(
			-50      => 'LIMIT 1',
			0        => 'LIMIT 1',
			99999999 => 'LIMIT 100',
			25       => 'LIMIT 25',
		);

		foreach ( $claim_cases as $input_limit => $expected_sql_limit ) {
			$captured_sqls = array();
			$service->process_pending_deliveries( $input_limit );

			$this->assertNotEmpty( $captured_sqls );
			$this->assertStringContainsString( $expected_sql_limit, $captured_sqls[0] );
		}

		// Test representative inputs against cleanup_delivery_history
		$cleanup_cases = array(
			-50      => 'LIMIT 1',
			0        => 'LIMIT 1',
			99999999 => 'LIMIT 1000',
			500      => 'LIMIT 500',
		);

		foreach ( $cleanup_cases as $input_limit => $expected_sql_limit ) {
			$captured_sqls = array();
			$service->cleanup_delivery_history( 30, $input_limit );

			$this->assertNotEmpty( $captured_sqls );
			$this->assertStringContainsString( $expected_sql_limit, $captured_sqls[0] );
		}
	}
}
