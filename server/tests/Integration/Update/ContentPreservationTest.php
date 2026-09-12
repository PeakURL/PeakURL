<?php
/**
 * Integration tests for Updater persistent content preservation and rollback safety.
 *
 * Verifies that:
 * 1. User uploads, plugins, custom translations, and cache inside content/ survive updates.
 * 2. Root config.php and .maintenance survive updates.
 * 3. Package-provided translations sync without deleting user languages.
 * 4. Retired release files are cleaned up while new release files are placed.
 * 5. Rollback on failure restores previous release files and content state.
 * 6. Availability rejects dev mode, package.json, and .git trees.
 *
 * @package PeakURL\Tests\Integration\Update
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Update;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Update\Context;
use PeakURL\Services\Update\Filesystem;
use PeakURL\Services\Update\ReleaseFiles;

class ContentPreservationTest extends TestCase {

	private ?Environment $original_environment = null;
	private string $test_dir;
	private string $source_root;
	private string $package_root;
	private string $backup_root;
	private Filesystem $filesystem;
	private Context $context;
	private ReleaseFiles $release_files;

	protected function setUp(): void {
		parent::setUp();
		$this->original_environment = Environment::get_instance();
		$this->filesystem           = new Filesystem();

		$this->test_dir     = sys_get_temp_dir() . '/peakurl_update_preservation_' . bin2hex( random_bytes( 6 ) );
		$this->source_root  = $this->test_dir . '/installed';
		$this->package_root = $this->test_dir . '/package';
		$this->backup_root  = $this->test_dir . '/backup';

		$this->filesystem->mkdir_p( $this->source_root );
		$this->filesystem->mkdir_p( $this->package_root );
		$this->filesystem->mkdir_p( $this->backup_root );

		$env = new Environment( $this->source_root, false );
		Environment::set_instance( $env );

		$this->context       = new Context( array(), $this->filesystem );
		$this->release_files = new ReleaseFiles( $this->context, $this->filesystem );
	}

	protected function tearDown(): void {
		Environment::set_instance( $this->original_environment );
		$this->filesystem->delete( $this->test_dir );
		parent::tearDown();
	}

	public function test_update_preserves_user_content_and_config(): void {
		// 1. Seed installed production installation.
		$config_content = "<?php\ndefine('DB_DATABASE', 'prod_db');\n";
		file_put_contents( $this->source_root . '/config.php', $config_content );
		file_put_contents( $this->source_root . '/.maintenance', 'maintenance-active' );
		file_put_contents( $this->source_root . '/index.php', '<?php // v1 index' );
		file_put_contents( $this->source_root . '/app.html', '<!-- v1 app -->' );
		file_put_contents( $this->source_root . '/retired-release-file.txt', 'old release data' );

		$this->filesystem->mkdir_p( $this->source_root . '/core' );
		file_put_contents( $this->source_root . '/core/old-class.php', '<?php // old class' );

		// Seed user-owned persistent content.
		$this->filesystem->mkdir_p( $this->source_root . '/content/uploads' );
		file_put_contents( $this->source_root . '/content/uploads/avatar.png', 'avatar-binary-data' );

		$this->filesystem->mkdir_p( $this->source_root . '/content/plugins/custom-plugin' );
		file_put_contents( $this->source_root . '/content/plugins/custom-plugin/plugin.php', '<?php // custom plugin' );

		$this->filesystem->mkdir_p( $this->source_root . '/content/languages' );
		file_put_contents( $this->source_root . '/content/languages/fr.json', '{"greeting": "bonjour"}' );
		file_put_contents( $this->source_root . '/content/languages/en.json', '{"greeting": "hello v1"}' );

		$this->filesystem->mkdir_p( $this->source_root . '/content/cache' );
		file_put_contents( $this->source_root . '/content/cache/analytics.cache', 'cached-metrics' );

		// 2. Seed update package (new version).
		file_put_contents( $this->package_root . '/index.php', '<?php // v2 index' );
		file_put_contents( $this->package_root . '/app.html', '<!-- v2 app -->' );
		$this->filesystem->mkdir_p( $this->package_root . '/core' );
		file_put_contents( $this->package_root . '/core/new-class.php', '<?php // new class' );

		// Package includes updated and new translations.
		$this->filesystem->mkdir_p( $this->package_root . '/content/languages' );
		file_put_contents( $this->package_root . '/content/languages/en.json', '{"greeting": "hello v2"}' );
		file_put_contents( $this->package_root . '/content/languages/es.json', '{"greeting": "hola"}' );

		// 3. Execute update file sync orchestration.
		$installed_paths = $this->release_files->get_release_paths( $this->source_root );
		$package_paths   = $this->release_files->get_release_paths( $this->package_root );
		$content_paths   = $this->release_files->get_content_paths( $this->package_root );

		// PRESERVED_ROOT_PATHS must be excluded from release paths.
		$this->assertNotContains( 'config.php', $installed_paths );
		$this->assertNotContains( 'content', $installed_paths );
		$this->assertNotContains( '.maintenance', $installed_paths );

		// Backup phase.
		$this->release_files->backup_release_paths( $installed_paths, $this->backup_root );
		$this->release_files->backup_content_paths( $content_paths, $this->backup_root );

		// Replace release paths & sync package content.
		$this->release_files->replace_release_paths( $installed_paths, $package_paths, $this->package_root );
		$this->release_files->copy_content_paths( $content_paths, $this->package_root );

		// 4. Assert preservation and update results.
		// User config and maintenance survive untouched.
		$this->assertFileExists( $this->source_root . '/config.php' );
		$this->assertSame( $config_content, file_get_contents( $this->source_root . '/config.php' ) );
		$this->assertFileExists( $this->source_root . '/.maintenance' );
		$this->assertSame( 'maintenance-active', file_get_contents( $this->source_root . '/.maintenance' ) );

		// User content survives.
		$this->assertFileExists( $this->source_root . '/content/uploads/avatar.png' );
		$this->assertSame( 'avatar-binary-data', file_get_contents( $this->source_root . '/content/uploads/avatar.png' ) );

		$this->assertFileExists( $this->source_root . '/content/plugins/custom-plugin/plugin.php' );
		$this->assertSame( '<?php // custom plugin', file_get_contents( $this->source_root . '/content/plugins/custom-plugin/plugin.php' ) );

		$this->assertFileExists( $this->source_root . '/content/languages/fr.json' );
		$this->assertSame( '{"greeting": "bonjour"}', file_get_contents( $this->source_root . '/content/languages/fr.json' ) );

		$this->assertFileExists( $this->source_root . '/content/cache/analytics.cache' );
		$this->assertSame( 'cached-metrics', file_get_contents( $this->source_root . '/content/cache/analytics.cache' ) );

		// Package content synced.
		$this->assertFileExists( $this->source_root . '/content/languages/es.json' );
		$this->assertSame( '{"greeting": "hola"}', file_get_contents( $this->source_root . '/content/languages/es.json' ) );

		$this->assertFileExists( $this->source_root . '/content/languages/en.json' );
		$this->assertSame( '{"greeting": "hello v2"}', file_get_contents( $this->source_root . '/content/languages/en.json' ) );

		// Release files updated.
		$this->assertSame( '<?php // v2 index', file_get_contents( $this->source_root . '/index.php' ) );
		$this->assertSame( '<!-- v2 app -->', file_get_contents( $this->source_root . '/app.html' ) );
		$this->assertFileExists( $this->source_root . '/core/new-class.php' );

		// Retired release files deleted.
		$this->assertFileDoesNotExist( $this->source_root . '/retired-release-file.txt' );
		$this->assertFileDoesNotExist( $this->source_root . '/core/old-class.php' );
	}

	public function test_rollback_restores_original_state_on_failure(): void {
		// Seed initial state.
		file_put_contents( $this->source_root . '/index.php', '<?php // original index' );
		file_put_contents( $this->source_root . '/retired-file.txt', 'original retired file' );
		$this->filesystem->mkdir_p( $this->source_root . '/content/languages' );
		file_put_contents( $this->source_root . '/content/languages/en.json', '{"version": 1}' );

		// Seed package.
		file_put_contents( $this->package_root . '/index.php', '<?php // bad update index' );
		file_put_contents( $this->package_root . '/bad-file.txt', 'corrupt' );
		$this->filesystem->mkdir_p( $this->package_root . '/content/languages' );
		file_put_contents( $this->package_root . '/content/languages/en.json', '{"version": 2}' );
		file_put_contents( $this->package_root . '/content/languages/de.json', '{"version": 2}' );

		$installed_paths = $this->release_files->get_release_paths( $this->source_root );
		$package_paths   = $this->release_files->get_release_paths( $this->package_root );
		$rollback_paths  = $this->release_files->merge_release_paths( $installed_paths, $package_paths );
		$content_paths   = $this->release_files->get_content_paths( $this->package_root );

		// Backup.
		$this->release_files->backup_release_paths( $installed_paths, $this->backup_root );
		$this->release_files->backup_content_paths( $content_paths, $this->backup_root );

		// Partial apply (simulate corrupted or interrupted state).
		$this->release_files->replace_release_paths( $installed_paths, $package_paths, $this->package_root );
		$this->release_files->copy_content_paths( $content_paths, $this->package_root );

		// Rollback triggered.
		$this->release_files->restore_release_paths( $rollback_paths, $this->backup_root );
		$this->release_files->restore_content_paths( $content_paths, $this->backup_root );

		// Assert restored.
		$this->assertSame( '<?php // original index', file_get_contents( $this->source_root . '/index.php' ) );
		$this->assertFileExists( $this->source_root . '/retired-file.txt' );
		$this->assertSame( 'original retired file', file_get_contents( $this->source_root . '/retired-file.txt' ) );
		$this->assertFileDoesNotExist( $this->source_root . '/bad-file.txt' );

		// Content restored.
		$this->assertSame( '{"version": 1}', file_get_contents( $this->source_root . '/content/languages/en.json' ) );
		$this->assertFileDoesNotExist( $this->source_root . '/content/languages/de.json' );
	}

	public function test_availability_rejects_dev_mode_and_source_tree(): void {
		// Clean release root should be allowed.
		$availability = $this->context->get_availability();
		$this->assertTrue( $availability['allowed'] );
		$this->assertNull( $availability['reason'] );

		// Development layout rejection.
		$dev_root = $this->test_dir . '/dev_checkout';
		$this->filesystem->mkdir_p( $dev_root . '/server' );
		$dev_env = new Environment( $dev_root, true );
		Environment::set_instance( $dev_env );
		$dev_context = new Context( array(), $this->filesystem );

		$dev_availability = $dev_context->get_availability();
		$this->assertFalse( $dev_availability['allowed'] );
		$this->assertStringContainsString( 'installed release package', (string) $dev_availability['reason'] );

		// package.json in root rejection.
		Environment::set_instance( new Environment( $this->source_root, false ) );
		file_put_contents( $this->source_root . '/package.json', '{}' );
		$pkg_availability = $this->context->get_availability();
		$this->assertFalse( $pkg_availability['allowed'] );
		unlink( $this->source_root . '/package.json' );

		// .git directory in root rejection.
		$this->filesystem->mkdir_p( $this->source_root . '/.git' );
		$git_availability = $this->context->get_availability();
		$this->assertFalse( $git_availability['allowed'] );
		$this->filesystem->delete( $this->source_root . '/.git' );
	}
}
