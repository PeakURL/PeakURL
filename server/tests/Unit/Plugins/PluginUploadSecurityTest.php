<?php
/**
 * Security and atomicity test suite for plugin package ZIP uploads.
 *
 * Verifies strict archive structure, compression ratio bounds, POSIX file modes,
 * traversal prevention, same-filesystem staging, and failure rollback guarantees.
 *
 * @package PeakURL\Tests\Unit\Plugins
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Plugins;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Http\Request;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests ZIP security checks, genuine negative fixtures, and installation atomicity.
 */
class PluginUploadSecurityTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_zip_sec_test_' . uniqid();
		mkdir( $this->temp_dir . '/plugins', 0777, true );
		mkdir( $this->temp_dir . '/mu-plugins', 0777, true );
	}

	protected function tearDown(): void {
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function create_service(
		array $user_roles = array( 'administrator' ),
		?SettingsApi $settings_api = null
	): SystemService {
		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'    => 1,
					'email' => 'admin@peakurl.local',
					'roles' => $user_roles,
				)
			);

		$roles = $this->createMock( Roles::class );
		$roles->method( 'has_capability' )
			->willReturnCallback(
				static function ( array $user, string $cap ) use ( $user_roles ): bool {
					return in_array( 'administrator', $user_roles, true );
				}
			);

		$authorization = new Authorization( $roles );

		if ( null === $settings_api ) {
			$settings_api = $this->createMock( SettingsApi::class );
			$settings_api->method( 'get_option' )->willReturn( json_encode( array() ) );
		}

		$ref_class    = new \ReflectionClass( SystemService::class );
		$service_inst = $ref_class->newInstanceWithoutConstructor();

		$props = array(
			'auth_service'  => $auth_service,
			'authorization' => $authorization,
			'roles'         => $roles,
			'settings_api'  => $settings_api,
			'config'        => array(
				Constants::CONTENT_DIR => $this->temp_dir,
				Constants::VERSION     => '1.8.0',
			),
		);

		foreach ( $props as $name => $val ) {
			$prop = $ref_class->getProperty( $name );
			$prop->setValue( $service_inst, $val );
		}

		return $service_inst;
	}

	private function create_valid_manifest( array $overrides = array() ): array {
		return array_merge(
			array(
				'$schema'    => 'https://peakurl.org/schemas/v1/plugin.json',
				'name'       => 'Secure Plugin',
				'version'    => '1.0.0',
				'requires'   => '1.0.0',
				'testedUpTo' => '1.8.0',
				'php'        => '8.3',
				'license'    => 'MIT',
			),
			$overrides
		);
	}

	private function create_upload_request( string $zip_path, string $original_name = 'package.zip' ): Request {
		$files = array(
			'plugin_zip' => array(
				'tmp_name' => $zip_path,
				'name'     => $original_name,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $zip_path ),
			),
		);

		return new Request( 'POST', '/api/v1/system/plugins/upload', array(), array(), array(), $files );
	}

	public function test_upload_plugin_rejects_backslash_path(): void {
		$zip_file = $this->temp_dir . '/backslash.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		// Windows backslash entry.
		$zip->addFromString( 'sec-plugin\\stray.php', "<?php // Malformed backslash\n" );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains invalid path separator (backslash).' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_path_traversal(): void {
		$zip_file = $this->temp_dir . '/traversal.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->addFromString( 'sec-plugin/../../escaped.txt', 'danger' );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains unsafe path entries.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_absolute_path(): void {
		$zip_file = $this->temp_dir . '/absolute.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->addFromString( '/etc/passwd', 'root:x:0:0::/root:/bin/bash' );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains unsafe path entries.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_drive_letter_path(): void {
		$zip_file = $this->temp_dir . '/drive_letter.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->addFromString( 'C:/Windows/System32/drivers.txt', 'drive letter attempt' );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains unsafe path entries.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_empty_and_dot_path_segments(): void {
		$zip_file = $this->temp_dir . '/malformed_segments.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->addFromString( 'sec-plugin//nested.php', "<?php // empty segment\n" );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains unsafe path entries.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_control_characters_or_null_bytes(): void {
		$zip_file = $this->temp_dir . '/control_char.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		// ASCII 9 (tab control character).
		$zip->addFromString( "sec-plugin/evil\tfile.php", 'tab control character' );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains unsafe path entries.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_unix_symbolic_link(): void {
		$zip_file = $this->temp_dir . '/symlink.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->addFromString( 'sec-plugin/symlink_target', '/etc/shadow' );
		// Set Unix symlink attributes: mode 0120777 shifted 16 bits.
		$zip->setExternalAttributesName( 'sec-plugin/symlink_target', ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains symlinks, which are forbidden.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_unsupported_unix_entry_type(): void {
		$zip_file = $this->temp_dir . '/unsupported_type.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->addFromString( 'sec-plugin/fifo_pipe', '' );
		// Set FIFO / named pipe attribute: mode 0010666 shifted 16 bits.
		$zip->setExternalAttributesName( 'sec-plugin/fifo_pipe', ZipArchive::OPSYS_UNIX, ( 0010666 << 16 ) );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains unsupported entry types.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_exceeding_max_entry_count(): void {
		$zip_file = $this->temp_dir . '/oversized_count.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		for ( $i = 0; $i < 5002; $i++ ) {
			$zip->addFromString( "sec-plugin/file_{$i}.txt", 'x' );
		}
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains an invalid number of entries' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_duplicate_path_entries(): void {
		$zip_file = $this->temp_dir . '/duplicate_entries.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->addFromString( 'sec-plugin/dupA.txt', 'one' );
		$zip->addFromString( 'sec-plugin/dupB.txt', 'two' );
		$zip->close();

		// Replace dupB.txt with dupA.txt in binary archive to construct duplicate entries.
		$raw_zip = file_get_contents( $zip_file );
		$this->assertIsString( $raw_zip );
		file_put_contents( $zip_file, str_replace( 'dupB.txt', 'dupA.txt', $raw_zip ) );

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_colliding_path_entries(): void {
		$zip_file = $this->temp_dir . '/colliding_paths.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->addFromString( 'sec-plugin/folder', 'regular file' );
		$zip->addEmptyDir( 'sec-plugin/folder/' );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains colliding path entries.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_multiple_manifests(): void {
		$zip_file = $this->temp_dir . '/multi_manifest.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		// Second manifest inside subfolder.
		$zip->addFromString( 'sec-plugin/sub/plugin.json', json_encode( $this->create_valid_manifest( array( 'name' => 'Nested' ) ) ) );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains multiple plugin manifests.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_mismatched_manifest_and_entrypoint_location(): void {
		$zip_file = $this->temp_dir . '/mismatched_locations.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		// Manifest is in sec-plugin/, but plugin.php is at root.
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'plugin.php', "<?php // Root entry\n" );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		// Should fail either on stray root entry or missing exact matching entrypoint.
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_entries_outside_single_top_level_directory(): void {
		$zip_file = $this->temp_dir . '/stray_files.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		// Stray file outside sec-plugin/.
		$zip->addFromString( 'stray-file.txt', 'Outside single top-level directory' );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive contains entries outside the single top-level package directory.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_zip_bomb_compression_ratio_with_comp_size(): void {
		$zip_file = $this->temp_dir . '/zip_bomb.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		// 500 KB of repetitive zeros compresses to ~500 bytes (ratio > 1000:1).
		$zip->addFromString( 'sec-plugin/bomb.dat', str_repeat( '0', 500 * 1024 ) );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Archive entry exceeds maximum safe compression ratio.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_destination_collision_with_existing_folder(): void {
		// Pre-create destination folder in plugins.
		$installed_dest = $this->temp_dir . '/plugins/sec-plugin';
		mkdir( $installed_dest, 0777, true );
		file_put_contents( $installed_dest . '/existing.txt', 'installed' );

		$zip_file = $this->temp_dir . '/collision.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 409 );
		$this->expectExceptionMessage( 'already installed' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_destination_collision_with_symlink(): void {
		// Pre-create a symlink at the destination path.
		$dummy_target = $this->temp_dir . '/dummy_target';
		mkdir( $dummy_target, 0777, true );
		$installed_dest = $this->temp_dir . '/plugins/sec-plugin';
		symlink( $dummy_target, $installed_dest );

		$zip_file = $this->temp_dir . '/symlink_collision.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'sec-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'sec-plugin/plugin.php', "<?php // Entry\n" );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 409 );
		$this->expectExceptionMessage( 'already installed' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_stages_on_same_filesystem_and_installs_cleanly(): void {
		$zip_file = $this->temp_dir . '/clean_package.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString(
			'valid-plugin/plugin.json',
			json_encode(
				$this->create_valid_manifest(
					array(
						'name' => 'Valid Installed Plugin',
					)
				)
			)
		);
		$zip->addFromString( 'valid-plugin/plugin.php', "<?php\n// Plugin entry\n" );
		$zip->addFromString( 'valid-plugin/README.md', "# Valid Plugin\nA clean plugin package.\n" );
		$zip->close();

		$service  = $this->create_service();
		$request  = $this->create_upload_request( $zip_file );
		$response = $service->upload_plugin( $request );

		$this->assertSame( 'valid-plugin', $response['id'] );
		$this->assertSame( 'Valid Installed Plugin', $response['name'] );
		$this->assertFalse( $response['active'] );
		$this->assertTrue( $response['valid'] );
		$this->assertTrue( $response['compatible'] );

		// Verify target exists on disk.
		$installed_dest = $this->temp_dir . '/plugins/valid-plugin';
		$this->assertTrue( is_dir( $installed_dest ) );
		$this->assertTrue( is_file( $installed_dest . '/plugin.json' ) );
		$this->assertTrue( is_file( $installed_dest . '/plugin.php' ) );

		// Verify no temporary staging folders (.tmp_install_*) linger in content/plugins.
		$entries = scandir( $this->temp_dir . '/plugins' );
		$this->assertIsArray( $entries );
		foreach ( $entries as $entry ) {
			$this->assertFalse( str_starts_with( $entry, '.tmp_install_' ), 'Staging directory must be cleaned up' );
		}
	}

	public function test_upload_plugin_supports_root_based_package_layout(): void {
		$zip_file = $this->temp_dir . '/root_package.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString(
			'plugin.json',
			json_encode(
				$this->create_valid_manifest(
					array(
						'name' => 'Root Plugin',
					)
				)
			)
		);
		$zip->addFromString( 'plugin.php', "<?php // Root entry\n" );
		$zip->close();

		$service  = $this->create_service();
		$request  = $this->create_upload_request( $zip_file );
		$response = $service->upload_plugin( $request );

		$this->assertSame( 'root-plugin', $response['id'] );
		$this->assertTrue( is_dir( $this->temp_dir . '/plugins/root-plugin' ) );
		$this->assertTrue( is_file( $this->temp_dir . '/plugins/root-plugin/plugin.json' ) );
		$this->assertTrue( is_file( $this->temp_dir . '/plugins/root-plugin/plugin.php' ) );
	}

	public function test_upload_plugin_rolls_back_destination_on_post_move_verification_failure(): void {
		// Package contains a manifest that fails compatibility during post-move verification.
		$zip_file = $this->temp_dir . '/failing_verification.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString(
			'rollback-plugin/plugin.json',
			json_encode(
				$this->create_valid_manifest(
					array(
						'requires' => '99.0.0', // Incompatible with host 1.8.0.
					)
				)
			)
		);
		$zip->addFromString( 'rollback-plugin/plugin.php', "<?php // Entry\n" );
		$zip->close();

		$service = $this->create_service();
		$request = $this->create_upload_request( $zip_file );

		try {
			$service->upload_plugin( $request );
			$this->fail( 'Should have thrown ApiException' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->getCode() );
		}

		// Destination must NOT exist.
		$this->assertFalse( file_exists( $this->temp_dir . '/plugins/rollback-plugin' ) );
	}

	public function test_parse_ini_size_handles_suffixes_and_edge_cases(): void {
		$service = $this->create_service();

		$this->assertSame( 524288, $service->parse_ini_size( '512K' ) );
		$this->assertSame( 524288, $service->parse_ini_size( '512k' ) );
		$this->assertSame( 67108864, $service->parse_ini_size( '64M' ) );
		$this->assertSame( 67108864, $service->parse_ini_size( '64m' ) );
		$this->assertSame( 1073741824, $service->parse_ini_size( '1G' ) );
		$this->assertSame( 1073741824, $service->parse_ini_size( '1g' ) );
		$this->assertSame( 2048, $service->parse_ini_size( '2048' ) );
		$this->assertSame( 2048, $service->parse_ini_size( '2048B' ) );
		$this->assertSame( 0, $service->parse_ini_size( '0' ) );
		$this->assertSame( 0, $service->parse_ini_size( '-1' ) );
		$this->assertSame( 0, $service->parse_ini_size( '' ) );
	}

	public function test_get_effective_upload_limit_evaluates_post_max_size_and_upload_max_filesize(): void {
		$service = $this->create_service();

		// Case 1: post_max_size is smaller than upload_max_filesize.
		$limit1 = $service->get_effective_upload_limit( '64M', '8M' );
		$this->assertSame( 8 * 1024 * 1024, $limit1['bytes'] );
		$this->assertSame( '8 MB', $limit1['formatted'] );

		// Case 2: upload_max_filesize is smaller than post_max_size.
		$limit2 = $service->get_effective_upload_limit( '2M', '16M' );
		$this->assertSame( 2 * 1024 * 1024, $limit2['bytes'] );
		$this->assertSame( '2 MB', $limit2['formatted'] );

		// Case 3: post_max_size is 0 (unlimited).
		$limit3 = $service->get_effective_upload_limit( '10M', '0' );
		$this->assertSame( 10 * 1024 * 1024, $limit3['bytes'] );
		$this->assertSame( '10 MB', $limit3['formatted'] );

		// Case 4: Gigabyte limits.
		$limit4 = $service->get_effective_upload_limit( '1G', '2G' );
		$this->assertSame( 1024 * 1024 * 1024, $limit4['bytes'] );
		$this->assertSame( '1 GB', $limit4['formatted'] );
	}

	public function test_upload_plugin_rejects_archive_exceeding_dynamic_limit(): void {
		$zip_file = $this->temp_dir . '/large_plugin.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'large-plugin/plugin.json', json_encode( $this->create_valid_manifest() ) );
		$zip->addFromString( 'large-plugin/plugin.php', "<?php // Entry\n" );
		// Add uncompressible random payload so archive size on disk exceeds 100 KB.
		$zip->addFromString( 'large-plugin/payload.dat', random_bytes( 120 * 1024 ) );
		$zip->close();

		$service   = $this->create_service();
		$ref_class = new \ReflectionClass( $service );
		$prop      = $ref_class->getProperty( 'config' );
		$config    = $prop->getValue( $service );

		$config['PEAKURL_MAX_PLUGIN_UPLOAD_SIZE'] = '100K';

		$prop->setValue( $service, $config );

		$request = $this->create_upload_request( $zip_file );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'Plugin archive exceeds the 100 KB maximum upload limit.' );
		$service->upload_plugin( $request );
	}

	public function test_upload_plugin_rejects_ini_size_error_code(): void {
		$service = $this->create_service();
		$files   = array(
			'plugin_zip' => array(
				'tmp_name' => '',
				'name'     => 'huge.zip',
				'error'    => UPLOAD_ERR_INI_SIZE,
				'size'     => 0,
			),
		);
		$request = new Request( 'POST', '/api/v1/system/plugins/upload', array(), array(), array(), $files );

		$effective = $service->get_effective_upload_limit();

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( sprintf( 'Plugin archive exceeds the %s maximum upload limit.', $effective['formatted'] ) );
		$service->upload_plugin( $request );
	}

	private function recursive_rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) ) {
				@unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}
}
