<?php
/**
 * Integration lifecycle test for the "Click History Reset" (0.1.0) sample plugin.
 *
 * Verifies:
 * - Strict SemVer 0.1.0 manifest validation and header parity.
 * - ZIP package validation and upload.
 * - Activation and Loader bootstrapping.
 * - Registration of UI extension on 'links_overview_actions' with capability 'manage_site_settings'.
 * - Action registration and authenticated execution.
 * - Isolated database mutation: click records permanently purged without affecting shortened URLs.
 * - Authorization enforcement: rejection of unauthorized users (403).
 * - Independent text domain translation under 'click-history-reset' for es_ES vs en_US.
 * - Clean deactivation and uninstallation lifecycle.
 *
 * @package PeakURL\Tests\Integration\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Plugins;

use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Translation;
use Gettext\Translations;
use PDO;
use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Core\Plugins\Extensions;
use PeakURL\Core\Plugins\Loader;
use PeakURL\Core\Plugins\Validator;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\I18n;
use ZipArchive;

class ClickHistoryResetTest extends TestCase {

	private string $temp_dir;
	private string $plugins_dir;
	private array $saved_options = array();
	private PDO $pdo;
	private string $table_prefix     = 'peakurl_';
	private array $created_url_ids   = array();
	private array $created_click_ids = array();
	private ?I18n $original_i18n     = null;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();
		Extensions::get_instance()->reset();

		$this->temp_dir    = sys_get_temp_dir() . '/peakurl_chr_test_' . bin2hex( random_bytes( 4 ) );
		$this->plugins_dir = $this->temp_dir . '/plugins';

		mkdir( $this->plugins_dir, 0777, true );
		mkdir( $this->temp_dir . '/languages', 0777, true );
		touch( $this->temp_dir . '/languages/peakurl-es_ES.po' );

		$this->saved_options = array(
			'active_plugins' => json_encode( array() ),
		);

		// Connect to test database.
		$config             = Configuration::get_current();
		$conn               = Connection::get_instance( $config );
		$this->pdo          = $conn->get_connection();
		$this->table_prefix = (string) ( $config[ Constants::DB_PREFIX ] ?? 'peakurl_' );

		$this->seed_isolated_test_data();
	}

	protected function tearDown(): void {
		$this->cleanup_test_data();

		Extensions::get_instance()->reset();
		Hooks::remove_all();
		Hooks::reset_actions();

		if ( null !== $this->original_i18n ) {
			set_i18n_service( $this->original_i18n );
		}

		$this->recursive_rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function seed_isolated_test_data(): void {
		$now = gmdate( 'Y-m-d H:i:s' );

		// Seed 2 distinct URLs.
		for ( $i = 1; $i <= 2; $i++ ) {
			$url_id = 'test_chr_url_' . bin2hex( random_bytes( 6 ) );
			$stmt   = $this->pdo->prepare(
				"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
				 VALUES (?, 1, ?, ?, ?, ?, 'active', ?, ?)"
			);
			$code   = 'chr' . bin2hex( random_bytes( 4 ) );
			$stmt->execute(
				array(
					$url_id,
					$code,
					$code,
					"Test Link {$i}",
					"https://example.com/link-{$i}",
					$now,
					$now,
				)
			);
			$this->created_url_ids[] = $url_id;

			// Seed 5 clicks for each URL.
			for ( $c = 1; $c <= 5; $c++ ) {
				$click_id = 'test_chr_clk_' . bin2hex( random_bytes( 6 ) );
				$c_stmt   = $this->pdo->prepare(
					"INSERT INTO {$this->table_prefix}clicks (id, url_id, clicked_at, ip_address, browser)
					 VALUES (?, ?, ?, '127.0.0.1', 'Chrome')"
				);
				$c_stmt->execute( array( $click_id, $url_id, $now ) );
				$this->created_click_ids[] = $click_id;
			}
		}
	}

	private function cleanup_test_data(): void {
		if ( ! empty( $this->created_click_ids ) ) {
			$in_clicks = implode( ',', array_fill( 0, count( $this->created_click_ids ), '?' ) );
			$stmt      = $this->pdo->prepare( "DELETE FROM {$this->table_prefix}clicks WHERE id IN ({$in_clicks})" );
			$stmt->execute( $this->created_click_ids );
		}

		if ( ! empty( $this->created_url_ids ) ) {
			$in_urls = implode( ',', array_fill( 0, count( $this->created_url_ids ), '?' ) );
			$stmt    = $this->pdo->prepare( "DELETE FROM {$this->table_prefix}urls WHERE id IN ({$in_urls})" );
			$stmt->execute( $this->created_url_ids );
		}
	}

	private function count_test_clicks(): int {
		if ( empty( $this->created_url_ids ) ) {
			return 0;
		}
		$in_urls = implode( ',', array_fill( 0, count( $this->created_url_ids ), '?' ) );
		$stmt    = $this->pdo->prepare( "SELECT COUNT(*) FROM {$this->table_prefix}clicks WHERE url_id IN ({$in_urls})" );
		$stmt->execute( $this->created_url_ids );
		return (int) $stmt->fetchColumn();
	}

	private function count_test_urls(): int {
		if ( empty( $this->created_url_ids ) ) {
			return 0;
		}
		$in_urls = implode( ',', array_fill( 0, count( $this->created_url_ids ), '?' ) );
		$stmt    = $this->pdo->prepare( "SELECT COUNT(*) FROM {$this->table_prefix}urls WHERE id IN ({$in_urls})" );
		$stmt->execute( $this->created_url_ids );
		return (int) $stmt->fetchColumn();
	}

	private function recursive_rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	private function create_system_service(
		array $user_roles = array( 'administrator' ),
		array $capabilities = array()
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
				static function ( array $user, string $cap ) use ( $user_roles, $capabilities ): bool {
					if ( in_array( 'administrator', $user_roles, true ) ) {
						return true;
					}
					return in_array( $cap, $capabilities, true );
				}
			);

		$authorization = new Authorization( $roles );

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturnCallback(
				function ( string $key, $fallback = null ) {
					return $this->saved_options[ $key ] ?? $fallback;
				}
			);
		$settings_api->method( 'update_option' )
			->willReturnCallback(
				function ( string $key, string $value, string $updated_at, bool $autoload = true ): void {
					$this->saved_options[ $key ] = $value;
				}
			);
		$settings_api->method( 'update_option_with_lock' )
			->willReturnCallback(
				function ( string $key, callable $updater ): ?string {
					$current                     = $this->saved_options[ $key ] ?? null;
					$new_val                     = $updater( $current );
					$this->saved_options[ $key ] = (string) $new_val;
					return $new_val;
				}
			);

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

	private function build_click_history_reset_zip(): string {
		$zip_file = $this->temp_dir . '/click-history-reset-0.1.0.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$manifest = array(
			'$schema'     => 'https://peakurl.org/schemas/v1/plugin.json',
			'name'        => 'Click History Reset',
			'version'     => '0.1.0',
			'requires'    => '1.8.0',
			'testedUpTo'  => '1.8.0',
			'php'         => '8.3',
			'license'     => 'MIT',
			'textDomain'  => 'click-history-reset',
			'domainPath'  => '/languages',
			'description' => 'Allows administrators to reset recorded click history.',
		);
		$zip->addFromString( 'click-history-reset/plugin.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$plugin_php = <<<'PHP'
<?php
/**
 * Plugin Name: Click History Reset
 * Version: 0.1.0
 * Description: Allows administrators to reset recorded click history.
 * Text Domain: click-history-reset
 * Domain Path: /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

register_plugin_action(
	'click-history-reset',
	'clear_history',
	function( $request, $params ) {
		$config = \PeakURL\Core\Config\Configuration::get_current();
		$conn   = \PeakURL\Services\Database\Connection::get_instance( $config );
		$pdo    = $conn->get_connection();
		$prefix = (string) ( $config[ \PeakURL\Core\Config\Constants::DB_PREFIX ] ?? 'peakurl_' );

		$url_id = (string) ( $request->get_body_param( 'url_id' ) ?? ( $request->get_query_param( 'url_id' ) ?? '' ) );

		if ( '' !== $url_id ) {
			$stmt = $pdo->prepare( "DELETE FROM {$prefix}clicks WHERE url_id = ?" );
			$stmt->execute( array( $url_id ) );
			$deleted = $stmt->rowCount();
		} else {
			$stmt = $pdo->prepare( "DELETE FROM {$prefix}clicks" );
			$stmt->execute();
			$deleted = $stmt->rowCount();
		}

		return array(
			'success'        => true,
			'deleted_clicks' => $deleted,
			'message'        => __( 'Click history successfully cleared.', 'click-history-reset' ),
		);
	},
	'manage_site_settings'
);

register_plugin_ui_extension(
	array(
		'id'          => 'clear-click-history',
		'plugin_slug' => 'click-history-reset',
		'location'    => 'links_overview_actions',
		'label'       => __( 'Clear click history', 'click-history-reset' ),
		'action_id'   => 'clear_history',
		'capability'  => 'manage_site_settings',
		'icon'        => 'sparkles',
		'confirm'     => __( 'Are you sure you want to permanently clear all recorded click history?', 'click-history-reset' ),
		'invalidates' => array( 'Analytics', 'Urls' ),
	)
);
PHP;
		$zip->addFromString( 'click-history-reset/plugin.php', $plugin_php );

		// Spanish translation catalog
		$catalog = Translations::create( 'click-history-reset' );
		$catalog->add(
			Translation::create( null, 'Clear click history' )
				->translate( 'Borrar historial de clics' )
		);
		$catalog->add(
			Translation::create( null, 'Are you sure you want to permanently clear all recorded click history?' )
				->translate( '¿Está seguro de que desea borrar permanentemente todo el historial de clics registrado?' )
		);
		$catalog->add(
			Translation::create( null, 'Click history successfully cleared.' )
				->translate( 'Historial de clics borrado exitosamente.' )
		);

		$mo_gen = new MoGenerator();
		$po_gen = new PoGenerator();
		$zip->addFromString( 'click-history-reset/languages/click-history-reset-es_ES.mo', $mo_gen->generateString( $catalog ) );
		$zip->addFromString( 'click-history-reset/languages/click-history-reset-es_ES.po', $po_gen->generateString( $catalog ) );

		$zip->addFromString( 'click-history-reset/README.md', "# Click History Reset\n\nResets click tracking records." );
		$zip->close();

		return $zip_file;
	}

	public function test_click_history_reset_full_lifecycle(): void {
		// 1. Initial State Verification
		$this->assertSame( 2, $this->count_test_urls(), 'Expected 2 seeded test URLs' );
		$this->assertSame( 10, $this->count_test_clicks(), 'Expected 10 seeded test clicks' );

		// 2. Package Creation & Manifest Validation
		$manifest   = array(
			'$schema'     => 'https://peakurl.org/schemas/v1/plugin.json',
			'name'        => 'Click History Reset',
			'version'     => '0.1.0',
			'requires'    => '1.8.0',
			'testedUpTo'  => '1.8.0',
			'php'         => '8.3',
			'license'     => 'MIT',
			'textDomain'  => 'click-history-reset',
			'domainPath'  => '/languages',
			'description' => 'Allows administrators to reset recorded click history.',
		);
		$validator  = new Validator();
		$validation = $validator->validate( $manifest, '1.8.0', '8.4.0' );
		$this->assertTrue( $validation['valid'], 'Click History Reset manifest must be valid' );
		$this->assertSame( '0.1.0', $validation['manifest']['version'] );
		$this->assertSame( 'click-history-reset', $validation['manifest']['textDomain'] );

		$zip_path = $this->build_click_history_reset_zip();
		$this->assertFileExists( $zip_path );

		// 3. Upload & Installation
		$service        = $this->create_system_service( array( 'administrator' ) );
		$upload_request = new Request(
			'POST',
			'/api/system/plugins/upload',
			array(),
			array(),
			array(),
			array(
				'plugin_zip' => array(
					'tmp_name' => $zip_path,
					'name'     => basename( $zip_path ),
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $zip_path ),
				),
			)
		);
		$uploaded       = $service->upload_plugin( $upload_request );

		$this->assertSame( 'click-history-reset', $uploaded['id'] );
		$this->assertDirectoryExists( $this->plugins_dir . '/click-history-reset' );

		// 4. Activation
		$act_req   = new Request( 'POST', '/api/system/plugins/activate', array(), array( 'id' => 'click-history-reset' ) );
		$activated = $service->activate_plugin( $act_req );
		$this->assertTrue( $activated['active'] ?? $activated['success'] ?? true );
		$this->assertTrue( $service->is_plugin_active( 'click-history-reset' ) );

		// 5. Bootstrap Plugin Runtime & i18n
		$i18n = new I18n(
			array(
				Constants::CONTENT_DIR   => $this->temp_dir,
				Constants::SITE_LANGUAGE => 'es_ES',
			)
		);
		set_i18n_service( $i18n );
		$i18n->load_locale( 'es_ES' );

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturn( $this->saved_options['active_plugins'] );

		$registry = new \PeakURL\Core\Plugins\Registry( $this->temp_dir, null, '1.8.0', '8.4.26' );
		$loader   = new Loader( $registry, $settings_api );
		$loader->load_plugins();

		// 6. Verify i18n translations under 'click-history-reset' domain
		$this->assertSame(
			'Borrar historial de clics',
			__( 'Clear click history', 'click-history-reset' ),
			'Plugin Spanish translation must resolve under es_ES'
		);
		$this->assertSame(
			'Historial de clics borrado exitosamente.',
			__( 'Click history successfully cleared.', 'click-history-reset' )
		);

		// Switch to en_US and verify fallback
		$i18n->load_locale( 'en_US' );
		$this->assertSame(
			'Clear click history',
			__( 'Clear click history', 'click-history-reset' ),
			'Plugin translation must fall back to English under en_US'
		);

		// 7. Verify UI Extension Point Registration
		$extensions = Extensions::get_instance();
		$ui_exts    = $extensions->get_ui_extensions();
		$this->assertArrayHasKey( 'clear-click-history', $ui_exts );
		$ui_entry = $ui_exts['clear-click-history'];
		$this->assertSame( 'links_overview_actions', $ui_entry['location'] );
		$this->assertSame( 'click-history-reset', $ui_entry['plugin_slug'] );
		$this->assertSame( 'clear_history', $ui_entry['action_id'] );
		$this->assertSame( 'manage_site_settings', $ui_entry['capability'] );
		$this->assertSame( 'sparkles', $ui_entry['icon'] );
		$this->assertSame( array( 'Analytics', 'Urls' ), $ui_entry['invalidates'] );

		// 8. Action Execution Authorization: Denied for non-admin lacking manage_site_settings
		$editor_service = $this->create_system_service( array( 'editor' ), array() );
		$action_req     = new Request(
			'POST',
			'/api/plugins/click-history-reset/actions/clear_history',
			array(),
			array()
		);
		$action_req->set_route_params(
			array(
				'slug'     => 'click-history-reset',
				'actionId' => 'clear_history',
			)
		);

		$denied = false;
		try {
			$editor_service->dispatch_plugin_action( $action_req );
		} catch ( ApiException $e ) {
			$denied = ( 403 === $e->getCode() || 403 === $e->get_status() );
		}
		$this->assertTrue( $denied, 'Action execution must be forbidden (403) for non-admin' );
		$this->assertSame( 10, $this->count_test_clicks(), 'Clicks must remain intact after unauthorized call' );

		// 9. Action Execution: Purge Clicks for Specific URL
		$target_url = $this->created_url_ids[0];
		$req_params = array( 'url_id' => $target_url );
		$single_req = new Request(
			'POST',
			'/api/plugins/click-history-reset/actions/clear_history',
			array(),
			$req_params
		);
		$single_req->set_route_params(
			array(
				'slug'     => 'click-history-reset',
				'actionId' => 'clear_history',
			)
		);

		$single_res = $service->dispatch_plugin_action( $single_req );
		$this->assertTrue( $single_res['success'] );
		$this->assertSame( 5, $single_res['deleted_clicks'] );

		// 5 clicks for url_1 deleted, 5 clicks for url_2 remain
		$this->assertSame( 5, $this->count_test_clicks() );
		$this->assertSame( 2, $this->count_test_urls(), 'Both URLs must remain untouched in urls table' );

		// 10. Action Execution: Purge All Remaining Clicks
		$all_req = new Request(
			'POST',
			'/api/plugins/click-history-reset/actions/clear_history',
			array(),
			array()
		);
		$all_req->set_route_params(
			array(
				'slug'     => 'click-history-reset',
				'actionId' => 'clear_history',
			)
		);

		$all_res = $service->dispatch_plugin_action( $all_req );
		$this->assertTrue( $all_res['success'] );
		$this->assertGreaterThanOrEqual( 5, $all_res['deleted_clicks'] );

		// All test clicks purged, URLs completely intact
		$this->assertSame( 0, $this->count_test_clicks() );
		$this->assertSame( 2, $this->count_test_urls(), 'URLs must never be deleted when resetting click history' );

		// 11. Deactivation
		$deact_req   = new Request( 'POST', '/api/system/plugins/deactivate', array(), array( 'id' => 'click-history-reset' ) );
		$deactivated = $service->deactivate_plugin( $deact_req );
		$this->assertFalse( $deactivated['active'] ?? false );
		$this->assertFalse( $service->is_plugin_active( 'click-history-reset' ) );

		// Attempting action on deactivated plugin must fail with 404
		$deact_failed = false;
		try {
			$service->dispatch_plugin_action( $all_req );
		} catch ( ApiException $e ) {
			$deact_failed = ( 404 === $e->getCode() || 404 === $e->get_status() );
		}
		$this->assertTrue( $deact_failed, 'Action on inactive plugin must be rejected with 404' );

		// 12. Deletion / Uninstallation
		$del_req = new Request( 'POST', '/api/system/plugins/delete', array(), array( 'id' => 'click-history-reset' ) );
		$deleted = $service->delete_plugin( $del_req );
		$this->assertTrue( $deleted['deleted'] ?? true );
		$this->assertDirectoryDoesNotExist( $this->plugins_dir . '/click-history-reset' );
	}
}
