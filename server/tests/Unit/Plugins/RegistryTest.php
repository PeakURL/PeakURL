<?php
/**
 * Unit tests for Registry.
 *
 * @package PeakURL\Tests\Unit\Plugins
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Plugins;

use PeakURL\Core\Plugins\Registry;
use PeakURL\Core\Plugins\Type;
use PHPUnit\Framework\TestCase;

/**
 * Tests filesystem scanning, containment, sample plugin discovery, and no-execution guarantees.
 */
class RegistryTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_registry_test_' . uniqid();
		mkdir( $this->temp_dir, 0777, true );
	}

	protected function tearDown(): void {
		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	public function test_discovers_real_repository_sample_plugins(): void {
		$repo_root   = dirname( __DIR__, 4 );
		$content_dir = $repo_root . '/content';

		$registry = new Registry( $content_dir, null, '1.8.0', '8.3.0' );
		$all      = $registry->all();
		$regular  = $registry->regular();
		$must_use = $registry->must_use();

		// 1. Regular sample plugin: example-plugin
		$this->assertArrayHasKey( 'example-plugin', $regular );
		$example = $regular['example-plugin'];
		$this->assertSame( 'example-plugin', $example->id() );
		$this->assertSame( Type::REGULAR, $example->type() );
		$this->assertSame( 'Example Plugin', $example->name() );
		$this->assertSame( '1.0.0', $example->version() );
		$this->assertSame( '1.7.0', $example->requires() );
		$this->assertSame( '1.8.0', $example->tested_up_to() );
		$this->assertSame( '8.3', $example->php() );
		$this->assertSame( 'MIT', $example->license() );
		$this->assertNotNull( $example->description() );
		$this->assertStringContainsString( 'Demonstrates standard regular plugin packaging', (string) $example->description() );
		$this->assertStringNotContainsString( 'P4-B', (string) $example->description() );
		$this->assertTrue( $example->is_valid() );
		$this->assertTrue( $example->is_compatible() );
		$this->assertEmpty( $example->errors() );

		// 2. Must-use sample plugin: system-integration
		$this->assertArrayHasKey( 'system-integration', $must_use );
		$mu = $must_use['system-integration'];
		$this->assertSame( 'system-integration', $mu->id() );
		$this->assertSame( Type::MUST_USE, $mu->type() );
		$this->assertSame( 'System Integration', $mu->name() );
		$this->assertSame( '1.0.0', $mu->version() );
		$this->assertSame( '1.7.0', $mu->requires() );
		$this->assertNotNull( $mu->description() );
		$this->assertStringContainsString( 'Demonstrates must-use (MU) plugin packaging', (string) $mu->description() );
		$this->assertStringNotContainsString( 'P4-B', (string) $mu->description() );
		$this->assertTrue( $mu->is_valid() );
		$this->assertTrue( $mu->is_compatible() );
		$this->assertEmpty( $mu->errors() );

		// Combined inventory
		$this->assertArrayHasKey( 'example-plugin', $all );
		$this->assertArrayHasKey( 'system-integration', $all );

		// find() and has()
		$this->assertTrue( $registry->has( 'example-plugin' ) );
		$this->assertTrue( $registry->has( 'example-plugin', Type::REGULAR ) );
		$this->assertFalse( $registry->has( 'example-plugin', Type::MUST_USE ) );
		$this->assertNotNull( $registry->find( 'example-plugin' ) );
		$this->assertNull( $registry->find( 'nonexistent-plugin' ) );
	}

	public function test_plugin_php_is_not_executed_during_discovery(): void {
		$repo_root   = dirname( __DIR__, 4 );
		$content_dir = $repo_root . '/content';

		$registry = new Registry( $content_dir, null, '1.8.0', '8.3.0' );
		$registry->all();

		if ( function_exists( 'has_filter' ) ) {
			$this->assertFalse(
				has_filter( 'peakurl_example_plugin_filter' ),
				'Regular plugin entrypoint must NOT be executed during discovery.'
			);
			$this->assertFalse(
				has_filter( 'peakurl_system_integration_filter' ),
				'Must-use plugin entrypoint must NOT be executed during discovery.'
			);
		}
	}

	public function test_missing_directories_handled_gracefully(): void {
		$non_existent = $this->temp_dir . '/non_existent_content';
		$registry     = new Registry( $non_existent, null, '1.8.0', '8.3.0' );

		$this->assertEmpty( $registry->all() );
		$this->assertEmpty( $registry->regular() );
		$this->assertEmpty( $registry->must_use() );
		$this->assertFalse( $registry->has( 'anything' ) );
	}

	public function test_missing_plugin_php_is_reported_as_invalid(): void {
		$plugin_dir = $this->temp_dir . '/plugins/no-php-plugin';
		mkdir( $plugin_dir, 0777, true );

		$manifest = array(
			'name'       => 'No PHP Plugin',
			'version'    => '1.0.0',
			'requires'   => '1.8.0',
			'testedUpTo' => '1.8.0',
			'php'        => '8.3',
			'license'    => 'MIT',
		);
		file_put_contents( $plugin_dir . '/plugin.json', json_encode( $manifest ) );

		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );
		$plugin   = $registry->find( 'no-php-plugin' );

		$this->assertNotNull( $plugin );
		$this->assertFalse( $plugin->is_valid() );
		$this->assertFalse( $plugin->is_compatible() );
		$this->assertTrue( $this->has_error_containing( $plugin->errors(), 'plugin.php is missing' ) );
	}

	public function test_symlink_plugin_php_escaping_directory_rejected(): void {
		$target_dir = $this->temp_dir . '/outside';
		mkdir( $target_dir, 0777, true );
		file_put_contents( $target_dir . '/evil.php', '<?php // evil' );

		$plugin_dir = $this->temp_dir . '/plugins/symlink-plugin';
		mkdir( $plugin_dir, 0777, true );

		$manifest = array(
			'name'       => 'Symlink Plugin',
			'version'    => '1.0.0',
			'requires'   => '1.8.0',
			'testedUpTo' => '1.8.0',
			'php'        => '8.3',
			'license'    => 'MIT',
		);
		file_put_contents( $plugin_dir . '/plugin.json', json_encode( $manifest ) );
		symlink( $target_dir . '/evil.php', $plugin_dir . '/plugin.php' );

		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );
		$plugin   = $registry->find( 'symlink-plugin' );

		$this->assertNotNull( $plugin );
		$this->assertFalse( $plugin->is_valid() );
		$this->assertTrue( $this->has_error_containing( $plugin->errors(), 'escapes the plugin directory via symlink' ) );
	}

	public function test_symlink_plugin_json_escaping_directory_rejected(): void {
		$target_dir = $this->temp_dir . '/outside';
		mkdir( $target_dir, 0777, true );
		file_put_contents( $target_dir . '/outside.json', '{"name":"Escaping","version":"1.0.0","requires":"1.8.0","testedUpTo":"1.8.0","php":"8.3","license":"MIT"}' );

		$plugin_dir = $this->temp_dir . '/plugins/escaped-json-plugin';
		mkdir( $plugin_dir, 0777, true );
		file_put_contents( $plugin_dir . '/plugin.php', '<?php' );
		symlink( $target_dir . '/outside.json', $plugin_dir . '/plugin.json' );

		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );
		$plugin   = $registry->find( 'escaped-json-plugin' );

		$this->assertNotNull( $plugin );
		$this->assertFalse( $plugin->is_valid() );
		$this->assertTrue( $this->has_error_containing( $plugin->errors(), 'plugin.json escapes the plugin directory via symlink' ) );
	}

	public function test_symlink_directory_escaping_content_root_rejected(): void {
		$target_dir = $this->temp_dir . '/external_plugins/escape-plugin';
		mkdir( $target_dir, 0777, true );

		$manifest = array(
			'name'       => 'Escape Plugin',
			'version'    => '1.0.0',
			'requires'   => '1.8.0',
			'testedUpTo' => '1.8.0',
			'php'        => '8.3',
			'license'    => 'MIT',
		);
		file_put_contents( $target_dir . '/plugin.json', json_encode( $manifest ) );
		file_put_contents( $target_dir . '/plugin.php', '<?php' );

		$plugins_root = $this->temp_dir . '/content/plugins';
		mkdir( $plugins_root, 0777, true );
		symlink( $target_dir, $plugins_root . '/symlink-folder' );

		$registry = new Registry( $this->temp_dir . '/content', null, '1.8.0', '8.3.0' );
		$plugin   = $registry->find( 'symlink-folder' );

		$this->assertNotNull( $plugin );
		$this->assertFalse( $plugin->is_valid() );
		$this->assertTrue( $this->has_error_containing( $plugin->errors(), 'escapes the content root via symlink or traversal' ) );
	}

	public function test_find_with_traversal_id_is_safely_rejected(): void {
		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );

		$this->assertNull( $registry->find( '../something' ) );
		$this->assertNull( $registry->find( 'foo/bar' ) );
		$this->assertNull( $registry->find( 'foo\\bar' ) );
		$this->assertNull( $registry->find( "foo\0bar" ) );
	}

	public function test_missing_or_malformed_plugin_json_detected(): void {
		$plugin_dir = $this->temp_dir . '/plugins/broken-json-plugin';
		mkdir( $plugin_dir, 0777, true );
		file_put_contents( $plugin_dir . '/plugin.php', '<?php' );
		file_put_contents( $plugin_dir . '/plugin.json', '{invalid json syntax' );

		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );
		$plugin   = $registry->find( 'broken-json-plugin' );

		$this->assertNotNull( $plugin );
		$this->assertFalse( $plugin->is_valid() );
		$this->assertTrue( $this->has_error_containing( $plugin->errors(), 'contains invalid JSON' ) );
	}

	public function test_oversized_plugin_json_rejected(): void {
		$plugin_dir = $this->temp_dir . '/plugins/huge-manifest-plugin';
		mkdir( $plugin_dir, 0777, true );
		file_put_contents( $plugin_dir . '/plugin.php', '<?php' );

		$huge_data = array(
			'name'       => 'Huge Plugin',
			'version'    => '1.0.0',
			'requires'   => '1.8.0',
			'testedUpTo' => '1.8.0',
			'php'        => '8.3',
			'license'    => 'MIT',
			'filler'     => str_repeat( 'A', 70000 ),
		);
		file_put_contents( $plugin_dir . '/plugin.json', json_encode( $huge_data ) );

		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );
		$plugin   = $registry->find( 'huge-manifest-plugin' );

		$this->assertNotNull( $plugin );
		$this->assertFalse( $plugin->is_valid() );
		$this->assertTrue( $this->has_error_containing( $plugin->errors(), 'exceeds the maximum size limit' ) );
	}

	public function test_deterministic_alphabetical_sorting(): void {
		$names = array( 'zeta-plugin', 'alpha-plugin', 'beta-plugin', 'gamma-plugin' );
		foreach ( $names as $name ) {
			$dir = $this->temp_dir . '/plugins/' . $name;
			mkdir( $dir, 0777, true );
			file_put_contents( $dir . '/plugin.php', '<?php' );
			file_put_contents(
				$dir . '/plugin.json',
				json_encode(
					array(
						'name'       => ucfirst( $name ),
						'version'    => '1.0.0',
						'requires'   => '1.8.0',
						'testedUpTo' => '1.8.0',
						'php'        => '8.3',
						'license'    => 'MIT',
					)
				)
			);
		}

		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );
		$keys     = array_keys( $registry->all() );

		$this->assertSame( array( 'alpha-plugin', 'beta-plugin', 'gamma-plugin', 'zeta-plugin' ), $keys );
		$this->assertNull( $registry->find( 'alpha-plugin' )->description() );
	}

	public function test_cross_root_duplicate_identifiers_reported_as_collision(): void {
		$reg_dir = $this->temp_dir . '/plugins/duplicate-plugin';
		$mu_dir  = $this->temp_dir . '/mu-plugins/duplicate-plugin';
		mkdir( $reg_dir, 0777, true );
		mkdir( $mu_dir, 0777, true );

		$manifest = array(
			'name'       => 'Collision',
			'version'    => '1.0.0',
			'requires'   => '1.8.0',
			'testedUpTo' => '1.8.0',
			'php'        => '8.3',
			'license'    => 'MIT',
		);
		file_put_contents( $reg_dir . '/plugin.json', json_encode( $manifest ) );
		file_put_contents( $reg_dir . '/plugin.php', '<?php' );
		file_put_contents( $mu_dir . '/plugin.json', json_encode( $manifest ) );
		file_put_contents( $mu_dir . '/plugin.php', '<?php' );

		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );

		// 1. Verify find without type resolves collision to an invalid Metadata instance.
		$plugin = $registry->find( 'duplicate-plugin' );
		$this->assertNotNull( $plugin );
		$this->assertFalse( $plugin->is_valid() );
		$this->assertFalse( $plugin->is_compatible() );
		$this->assertTrue( $this->has_error_containing( $plugin->errors(), 'exists in both regular and must-use directories' ) );

		// 2. Verify both must-use and regular records exist and are both marked invalid and incompatible.
		$mu_plugin = $registry->find( 'duplicate-plugin', Type::MUST_USE );
		$this->assertNotNull( $mu_plugin );
		$this->assertSame( Type::MUST_USE, $mu_plugin->type() );
		$this->assertFalse( $mu_plugin->is_valid() );
		$this->assertFalse( $mu_plugin->is_compatible() );
		$this->assertTrue( $this->has_error_containing( $mu_plugin->errors(), 'exists in both regular and must-use directories' ) );

		$reg_plugin = $registry->find( 'duplicate-plugin', Type::REGULAR );
		$this->assertNotNull( $reg_plugin );
		$this->assertSame( Type::REGULAR, $reg_plugin->type() );
		$this->assertFalse( $reg_plugin->is_valid() );
		$this->assertFalse( $reg_plugin->is_compatible() );
		$this->assertTrue( $this->has_error_containing( $reg_plugin->errors(), 'exists in both regular and must-use directories' ) );

		// 3. Verify must_use() and regular() sets both retain the colliding record.
		$this->assertArrayHasKey( 'duplicate-plugin', $registry->must_use() );
		$this->assertArrayHasKey( 'duplicate-plugin', $registry->regular() );

		// 4. Verify all() retains both records (count of 2) without overwriting.
		$all = $registry->all();
		$this->assertCount( 2, $all );
	}

	public function test_to_array_masks_internal_filesystem_paths(): void {
		$plugin_dir = $this->temp_dir . '/plugins/privacy-plugin';
		mkdir( $plugin_dir, 0777, true );
		file_put_contents( $plugin_dir . '/plugin.php', '<?php' );
		file_put_contents(
			$plugin_dir . '/plugin.json',
			json_encode(
				array(
					'name'       => 'Privacy Plugin',
					'version'    => '1.0.0',
					'requires'   => '1.8.0',
					'testedUpTo' => '1.8.0',
					'php'        => '8.3',
					'license'    => 'MIT',
				)
			)
		);

		$registry = new Registry( $this->temp_dir, null, '1.8.0', '8.3.0' );
		$plugin   = $registry->find( 'privacy-plugin' );

		$this->assertNotNull( $plugin );
		$array = $plugin->to_array();

		$this->assertArrayNotHasKey( 'plugin_path', $array );
		$this->assertArrayNotHasKey( 'manifest_path', $array );
		$this->assertArrayNotHasKey( 'path', $array );

		$serialized = json_encode( $array );
		$this->assertStringNotContainsString( $this->temp_dir, (string) $serialized );
	}

	private function has_error_containing( array $errors, string $needle ): bool {
		foreach ( $errors as $error ) {
			if ( str_contains( $error, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private function recursive_rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = scandir( $dir );
		if ( false === $files ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
