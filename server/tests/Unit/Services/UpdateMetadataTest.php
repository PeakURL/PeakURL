<?php
/**
 * Unit tests for Update Metadata, Client query encoding, and Installation Metadata.
 *
 * @package PeakURL\Tests\Unit\Services
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Database\Context as SchemaContext;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Upgrade as SchemaUpgrade;
use PeakURL\Services\Update\Client;
use PeakURL\Services\Update\Context as UpdateContext;
use PeakURL\Services\Update\Filesystem;
use PeakURL\Services\Update\Metadata;
use PeakURL\Utils\Str;
use ReflectionMethod;

class UpdateMetadataTest extends TestCase {

	public function test_uuid_v4_canonical_format_and_version_and_variant(): void {
		$uuid = Str::uuid();

		$this->assertSame( 36, strlen( $uuid ) );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$uuid
		);
		$this->assertSame( '4', $uuid[14] );
		$this->assertContains( $uuid[19], array( '8', '9', 'a', 'b' ) );
	}

	public function test_uuid_v4_uniqueness_sample(): void {
		$uuids = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$uuids[] = Str::uuid();
		}

		$this->assertCount( 100, array_unique( $uuids ) );
	}

	public function test_user_agent_format_contains_only_version(): void {
		$context = new UpdateContext(
			array(
				Constants::VERSION  => '1.7.0',
				Constants::SITE_URL => 'https://example.com/subpath',
			),
			new Filesystem()
		);
		$client  = new Client( $context );

		$ref_method = new ReflectionMethod( Client::class, 'format_user_agent' );
		$user_agent = $ref_method->invoke( $client );

		$this->assertSame( 'PeakURL/1.7.0', $user_agent );
		$this->assertStringNotContainsString( 'example.com', $user_agent );
		$this->assertStringNotContainsString( ';', $user_agent );
	}

	public function test_query_string_rfc3986_encoding_and_appending(): void {
		$context = new UpdateContext( array(), new Filesystem() );
		$client  = new Client( $context );

		$ref_method = new ReflectionMethod( Client::class, 'build_url' );

		// 1. No params
		$this->assertSame(
			'https://example.com/v1/update',
			$ref_method->invoke( $client, 'https://example.com/v1/update', array() )
		);

		// 2. URL with no existing query
		$params = array(
			'version'  => '1.7.0',
			'site_url' => 'https://example.com/test url~',
		);
		$this->assertSame(
			'https://example.com/v1/update?version=1.7.0&site_url=https%3A%2F%2Fexample.com%2Ftest%20url~',
			$ref_method->invoke( $client, 'https://example.com/v1/update', $params )
		);

		// 3. URL with existing query
		$this->assertSame(
			'https://example.com/v1/update?channel=latest&version=1.7.0',
			$ref_method->invoke( $client, 'https://example.com/v1/update?channel=latest', array( 'version' => '1.7.0' ) )
		);

		// 4. URL with fragment
		$this->assertSame(
			'https://example.com/v1/update?version=1.7.0#notes',
			$ref_method->invoke( $client, 'https://example.com/v1/update#notes', array( 'version' => '1.7.0' ) )
		);

		// 5. URL with existing query and fragment
		$this->assertSame(
			'https://example.com/v1/update?channel=latest&version=1.7.0#notes',
			$ref_method->invoke( $client, 'https://example.com/v1/update?channel=latest#notes', array( 'version' => '1.7.0' ) )
		);
	}

	public function test_web_server_normalization(): void {
		$this->assertSame( 'nginx', Metadata::normalize_web_server( 'nginx/1.24.0 (Ubuntu)' ) );
		$this->assertSame( 'apache', Metadata::normalize_web_server( 'Apache/2.4.52 (Unix) OpenSSL/1.1.1' ) );
		$this->assertSame( 'caddy', Metadata::normalize_web_server( 'Caddy v2.7.6' ) );
		$this->assertSame( 'iis', Metadata::normalize_web_server( 'Microsoft-IIS/10.0' ) );
		$this->assertSame( 'lighttpd', Metadata::normalize_web_server( 'lighttpd/1.4.69' ) );
		$this->assertSame( 'other', Metadata::normalize_web_server( 'LiteSpeed' ) );
		$this->assertSame( 'unknown', Metadata::normalize_web_server( '' ) );
		$this->assertSame( 'unknown', Metadata::normalize_web_server( null ) );
	}

	public function test_os_normalization(): void {
		$this->assertSame( 'linux', Metadata::normalize_os( 'Linux' ) );
		$this->assertSame( 'windows', Metadata::normalize_os( 'Windows' ) );
		$this->assertSame( 'darwin', Metadata::normalize_os( 'Darwin' ) );
		$this->assertSame( 'freebsd', Metadata::normalize_os( 'BSD' ) );
		$this->assertSame( 'freebsd', Metadata::normalize_os( 'FreeBSD' ) );
		$this->assertSame( 'other', Metadata::normalize_os( 'Solaris' ) );
		$this->assertSame( 'other', Metadata::normalize_os( 'AIX' ) );
		$this->assertSame( 'unknown', Metadata::normalize_os( '' ) );
		$this->assertSame( 'unknown', Metadata::normalize_os( 'unknown' ) );
		$this->assertContains( Metadata::normalize_os( null ), array( 'linux', 'windows', 'darwin', 'freebsd', 'other', 'unknown' ) );
	}

	public function test_site_url_normalization(): void {
		$this->assertSame(
			'https://example.com',
			Metadata::normalize_site_url( 'https://Example.COM/some/path?query=1#section' )
		);
		$this->assertSame(
			'http://example.com',
			Metadata::normalize_site_url( 'http://example.com:80/subpath/' )
		);
		$this->assertSame(
			'https://example.com',
			Metadata::normalize_site_url( 'https://example.com:443/subpath/' )
		);
		$this->assertSame(
			'https://example.com:8443',
			Metadata::normalize_site_url( 'https://example.com:8443/subpath/' )
		);
		$this->assertSame(
			'',
			Metadata::normalize_site_url( 'ftp://example.com' )
		);
		$this->assertSame(
			'',
			Metadata::normalize_site_url( 'javascript:alert(1)' )
		);
		$this->assertSame(
			'',
			Metadata::normalize_site_url( 'invalid-url' )
		);
		$this->assertSame(
			'',
			Metadata::normalize_site_url( '' )
		);
	}

	public function test_days_active_calculation(): void {
		$context  = new UpdateContext( array(), new Filesystem() );
		$db       = $this->createMock( PeakURL_DB::class );
		$settings = $this->createMock( SettingsApi::class );

		// 1. Same-day installation => 0
		$settings->method( 'get_option' )
			->with( 'installed_at' )
			->willReturn( gmdate( 'Y-m-d H:i:s', time() - 3600 ) );

		$metadata = new Metadata( $context, $settings, $db );
		$this->assertSame( 0, $metadata->get_days_active() );

		// 2. 10 days ago => 10
		$settings_10 = $this->createMock( SettingsApi::class );
		$settings_10->method( 'get_option' )
			->with( 'installed_at' )
			->willReturn( gmdate( 'Y-m-d H:i:s', time() - ( 10 * 86400 + 100 ) ) );

		$metadata_10 = new Metadata( $context, $settings_10, $db );
		$this->assertSame( 10, $metadata_10->get_days_active() );

		// 3. Future date => 0
		$settings_future = $this->createMock( SettingsApi::class );
		$settings_future->method( 'get_option' )
			->with( 'installed_at' )
			->willReturn( gmdate( 'Y-m-d H:i:s', time() + 86400 ) );

		$metadata_future = new Metadata( $context, $settings_future, $db );
		$this->assertSame( 0, $metadata_future->get_days_active() );

		// 4. Missing/invalid date => 0
		$settings_invalid = $this->createMock( SettingsApi::class );
		$settings_invalid->method( 'get_option' )
			->with( 'installed_at' )
			->willReturn( 'invalid-date' );

		$metadata_invalid = new Metadata( $context, $settings_invalid, $db );
		$this->assertSame( 0, $metadata_invalid->get_days_active() );
	}

	public function test_runtime_write_invariant_metadata_never_writes(): void {
		$context  = new UpdateContext(
			array(
				Constants::VERSION  => '1.7.0',
				Constants::SITE_URL => 'https://example.com',
			),
			new Filesystem()
		);
		$db       = $this->createMock( PeakURL_DB::class );
		$settings = $this->createMock( SettingsApi::class );

		// Assert that update_option is NEVER called on SettingsApi during telemetry collection.
		$settings->expects( $this->never() )->method( 'update_option' );

		$settings->method( 'get_option' )->willReturnCallback(
			function ( string $key ) {
				if ( 'installation_id' === $key ) {
					return 'c3a502c3-9d93-4e44-b0a0-04359cf9bc41';
				}
				if ( 'installed_at' === $key ) {
					return '2026-01-01 00:00:00';
				}
				if ( Constants::SETTING_DB_SCHEMA_VERSION === $key ) {
					return '9';
				}
				if ( 'site_language' === $key ) {
					return 'en_GB';
				}
				return null;
			}
		);

		$db->method( 'get_row' )->willReturn(
			array(
				'version'         => '10.11.6-MariaDB',
				'version_comment' => 'mariadb.org binary distribution',
			)
		);

		$metadata = new Metadata( $context, $settings, $db );
		$params   = $metadata->to_array();

		$this->assertSame( '1.7.0', $params['version'] );
		$this->assertSame( PHP_VERSION, $params['php_version'] );
		$this->assertSame( 'mariadb', $params['database'] );
		$this->assertSame( '10.11.6-MariaDB', $params['database_version'] );
		$this->assertSame( '9', $params['schema_version'] );
		$this->assertSame( 'en_GB', $params['language'] );
		$this->assertSame( 'https://example.com', $params['site_url'] );
		$this->assertSame( 'c3a502c3-9d93-4e44-b0a0-04359cf9bc41', $params['installation_id'] );
		$this->assertArrayHasKey( 'web_server', $params );
		$this->assertArrayHasKey( 'os', $params );
		$this->assertArrayHasKey( 'install_type', $params );
		$this->assertArrayHasKey( 'days_active', $params );

		$this->assertCount( 12, $params );
	}

	public function test_schema_upgrade_backfill_cases(): void {
		$ref_method = new ReflectionMethod( SchemaUpgrade::class, 'backfill_installation_metadata' );

		// Case A: Both exist -> preserve both, no writes.
		$context_case_a = $this->createMock( SchemaContext::class );
		$context_case_a->method( 'get_option' )->willReturnMap(
			array(
				array( 'installed_at', '2025-06-01 12:00:00' ),
				array( 'installation_id', 'existing-uuid-v4' ),
			)
		);
		$context_case_a->expects( $this->never() )->method( 'update_option' );

		$upgrade_case_a = new SchemaUpgrade( $context_case_a, '/dummy/path.sql' );
		$changes_case_a = array();
		$ref_method->invokeArgs( $upgrade_case_a, array( &$changes_case_a ) );
		$this->assertEmpty( $changes_case_a );

		// Case B: installed_at exists, installation_id missing -> backfill installation_id only.
		$context_case_b = $this->createMock( SchemaContext::class );
		$context_case_b->method( 'get_option' )->willReturnMap(
			array(
				array( 'installed_at', '2025-06-01 12:00:00' ),
				array( 'installation_id', null ),
			)
		);
		$context_case_b->expects( $this->once() )
			->method( 'update_option' )
			->with(
				'installation_id',
				$this->callback( fn( $val ) => 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $val ) ),
				false
			);

		$upgrade_case_b = new SchemaUpgrade( $context_case_b, '/dummy/path.sql' );
		$changes_case_b = array();
		$ref_method->invokeArgs( $upgrade_case_b, array( &$changes_case_b ) );
		$this->assertCount( 1, $changes_case_b );

		// Case C: installation_id exists, installed_at missing -> backfill installed_at only.
		$context_case_c = $this->createMock( SchemaContext::class );
		$context_case_c->method( 'get_option' )->willReturnMap(
			array(
				array( 'installed_at', null ),
				array( 'installation_id', 'existing-uuid-v4' ),
			)
		);
		$context_case_c->expects( $this->once() )
			->method( 'update_option' )
			->with(
				'installed_at',
				$this->callback( fn( $val ) => 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $val ) ),
				false
			);

		$upgrade_case_c = new SchemaUpgrade( $context_case_c, '/dummy/path.sql' );
		$changes_case_c = array();
		$ref_method->invokeArgs( $upgrade_case_c, array( &$changes_case_c ) );
		$this->assertCount( 1, $changes_case_c );

		// Case D: Both missing -> backfill both.
		$context_case_d = $this->createMock( SchemaContext::class );
		$context_case_d->method( 'get_option' )->willReturnMap(
			array(
				array( 'installed_at', null ),
				array( 'installation_id', null ),
			)
		);
		$context_case_d->expects( $this->exactly( 2 ) )
			->method( 'update_option' );

		$upgrade_case_d = new SchemaUpgrade( $context_case_d, '/dummy/path.sql' );
		$changes_case_d = array();
		$ref_method->invokeArgs( $upgrade_case_d, array( &$changes_case_d ) );
		$this->assertCount( 2, $changes_case_d );

		// Idempotency: Re-running on already populated values must never write or overwrite.
		$context_idempotent = $this->createMock( SchemaContext::class );
		$context_idempotent->method( 'get_option' )->willReturnMap(
			array(
				array( 'installed_at', '2024-01-15 08:30:00' ),
				array( 'installation_id', 'a1b2c3d4-e5f6-4a1b-8c2d-3e4f5a6b7c8d' ),
			)
		);
		$context_idempotent->expects( $this->never() )->method( 'update_option' );

		$upgrade_idempotent = new SchemaUpgrade( $context_idempotent, '/dummy/path.sql' );
		$changes_idempotent = array();
		$ref_method->invokeArgs( $upgrade_idempotent, array( &$changes_idempotent ) );
		$this->assertEmpty( $changes_idempotent );
	}
}
