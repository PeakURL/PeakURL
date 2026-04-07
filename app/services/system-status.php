<?php
/**
 * PeakURL system-status service.
 *
 * @package PeakURL\Services
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Services;

use FilesystemIterator;
use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Constants;
use PeakURL\Includes\PeakURL_DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Build a dashboard-friendly system status payload.
 *
 * Surfaces site, runtime, storage, database, mail, and location-data
 * details in one place without spreading those lookups across the
 * controller layer.
 *
 * @since 1.0.3
 */
class SystemStatus {

	/**
	 * PeakURL-managed database tables.
	 *
	 * @var array<int, string>
	 * @since 1.0.3
	 */
	private const MANAGED_TABLES = array(
		'settings',
		'users',
		'api_keys',
		'sessions',
		'urls',
		'clicks',
		'audit_logs',
		'webhooks',
	);

	/**
	 * Runtime configuration.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.3
	 */
	private array $config;

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.3
	 */
	private PeakURL_DB $db;

	/**
	 * Settings API helper.
	 *
	 * @var SettingsApi
	 * @since 1.0.3
	 */
	private SettingsApi $settings_api;

	/**
	 * GeoIP service dependency.
	 *
	 * @var Geoip
	 * @since 1.0.3
	 */
	private Geoip $geoip_service;

	/**
	 * Mail transport dependency.
	 *
	 * @var Mailer
	 * @since 1.0.3
	 */
	private Mailer $mailer_service;

	/**
	 * Database schema service dependency.
	 *
	 * @var DatabaseSchema
	 * @since 1.0.3
	 */
	private DatabaseSchema $database_schema;

	/**
	 * I18n service dependency.
	 *
	 * @var I18n
	 * @since 1.0.3
	 */
	private I18n $i18n_service;

	/**
	 * Create a new system-status service instance.
	 *
	 * @param array<string, mixed> $config         Runtime configuration.
	 * @param PeakURL_DB           $db             Shared database wrapper.
	 * @param SettingsApi          $settings_api   Settings API dependency.
	 * @param Geoip                $geoip_service  GeoIP service dependency.
	 * @param Mailer               $mailer_service Mail transport dependency.
	 * @param DatabaseSchema       $database_schema Database schema dependency.
	 * @param I18n                 $i18n_service   I18n service dependency.
	 * @since 1.0.3
	 */
	public function __construct(
		array $config,
		PeakURL_DB $db,
		SettingsApi $settings_api,
		Geoip $geoip_service,
		Mailer $mailer_service,
		DatabaseSchema $database_schema,
		I18n $i18n_service
	) {
		$this->config          = $config;
		$this->db              = $db;
		$this->settings_api    = $settings_api;
		$this->geoip_service   = $geoip_service;
		$this->mailer_service  = $mailer_service;
		$this->database_schema = $database_schema;
		$this->i18n_service    = $i18n_service;
	}

	/**
	 * Build the complete dashboard system-status payload.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function get_status(): array {
		$site     = $this->build_site_status();
		$server   = $this->build_server_status();
		$database = $this->build_database_status();
		$storage  = $this->build_storage_status();
		$mail     = $this->build_mail_status();
		$location = $this->build_location_status();
		$data     = $this->build_data_status();
		$checks   = $this->build_checks(
			$server,
			$database,
			$storage,
			$mail,
			$location,
		);

		return array(
			'generatedAt' => gmdate( DATE_ATOM ),
			'summary'     => $this->build_summary( $checks ),
			'checks'      => $checks,
			'site'        => $site,
			'server'      => $server,
			'database'    => $database,
			'storage'     => $storage,
			'mail'        => $mail,
			'location'    => $location,
			'data'        => $data,
		);
	}

	/**
	 * Build site-level status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_site_status(): array {
		$site_name           = trim( (string) $this->settings_api->get_option( 'site_name' ) );
		$site_url            = trim( (string) $this->settings_api->get_option( 'site_url' ) );
		$locale              = $this->i18n_service->get_site_locale();
		$available_languages = $this->i18n_service->list_languages();
		$active_language     = $this->find_language( $available_languages, $locale );

		if ( '' === $site_name ) {
			$site_name = 'PeakURL';
		}

		if ( '' === $site_url ) {
			$site_url = trim(
				(string) ( $this->config[ Constants::CONFIG_SITE_URL ] ?? '' ),
			);
		}

		return array(
			'name'                    => $site_name,
			'url'                     => $site_url,
			'version'                 => $this->get_peakurl_version(),
			'environment'             => trim(
				(string) ( $this->config[ Constants::CONFIG_ENV ] ?? 'production' ),
			),
			'installType'             => $this->is_source_checkout()
				? 'source'
				: 'release',
			'debugEnabled'            => ! empty(
				$this->config[ Constants::CONFIG_DEBUG ]
			),
			'locale'                  => $locale,
			'htmlLang'                => $this->i18n_service->get_html_lang( $locale ),
			'languageLabel'           => (string) ( $active_language['label'] ?? $locale ),
			'languageNativeName'      => (string) ( $active_language['nativeName'] ?? $locale ),
			'languageEnglishLabel'    => (string) ( $active_language['englishLabel'] ?? $locale ),
			'installedLanguagesCount' => count( $available_languages ),
			'defaultLocale'           => $this->i18n_service->get_default_locale(),
		);
	}

	/**
	 * Build PHP/server status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_server_status(): array {
		return array(
			'phpVersion'        => PHP_VERSION,
			'phpSapi'           => PHP_SAPI,
			'operatingSystem'   => PHP_OS_FAMILY,
			'serverSoftware'    => trim(
				(string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ),
			),
			'timezone'          => (string) date_default_timezone_get(),
			'memoryLimit'       => (string) ini_get( 'memory_limit' ),
			'maxExecutionTime'  => (int) ini_get( 'max_execution_time' ),
			'uploadMaxFilesize' => (string) ini_get( 'upload_max_filesize' ),
			'postMaxSize'       => (string) ini_get( 'post_max_size' ),
			'extensions'        => array(
				'intl'     => extension_loaded( 'intl' ),
				'curl'     => extension_loaded( 'curl' ),
				'mbstring' => extension_loaded( 'mbstring' ),
				'openssl'  => extension_loaded( 'openssl' ),
				'pdoMysql' => extension_loaded( 'pdo_mysql' ),
				'zip'      => class_exists( '\ZipArchive' ),
			),
		);
	}

	/**
	 * Build database status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_database_status(): array {
		$metadata        = $this->query_database_metadata();
		$schema_status   = $this->database_schema->inspect();
		$version         = trim( (string) ( $metadata['version'] ?? '' ) );
		$version_comment = trim( (string) ( $metadata['versionComment'] ?? '' ) );

		return array(
			'connected'             => true,
			'host'                  => (string) ( $this->config['DB_HOST'] ?? '' ),
			'port'                  => (int) ( $this->config['DB_PORT'] ?? 0 ),
			'name'                  => (string) ( $this->config['DB_DATABASE'] ?? '' ),
			'charset'               => (string) ( $this->config['DB_CHARSET'] ?? 'utf8mb4' ),
			'prefix'                => (string) ( $this->config['DB_PREFIX'] ?? '' ),
			'version'               => $version,
			'versionComment'        => $version_comment,
			'serverType'            => $this->detect_database_server_type(
				$version,
				$version_comment,
			),
			'schemaVersion'         => (int) ( $schema_status['currentVersion'] ?? 0 ),
			'requiredSchemaVersion' => (int) ( $schema_status['targetVersion'] ?? 0 ),
			'schemaCompatible'      => ! empty( $schema_status['compatible'] ),
			'schemaUpgradeRequired' => ! empty(
				$schema_status['upgradeRequired']
			),
			'schemaIssuesCount'     => (int) ( $schema_status['issuesCount'] ?? 0 ),
			'schemaLastUpgradedAt'  => (string) ( $schema_status['lastUpgradedAt'] ?? '' ),
			'schemaLastError'       => (string) ( $schema_status['lastError'] ?? '' ),
		);
	}

	/**
	 * Build filesystem and storage status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_storage_status(): array {
		$this->i18n_service->ensure_languages_directory();

		$content_directory   = $this->i18n_service->get_content_directory();
		$languages_directory = $this->i18n_service->get_languages_directory();
		$debug_log_path      = $content_directory .
			DIRECTORY_SEPARATOR .
			Constants::DEBUG_LOG_FILE;
		$config_path         = ABSPATH . 'config.php';
		$app_directory       = ABSPATH . 'app';

		return array(
			'releaseRoot'                 => rtrim( ABSPATH, '/\\' ),
			'releaseRootSizeBytes'        => $this->get_path_size_bytes( ABSPATH ),
			'appDirectory'                => $app_directory,
			'appWritable'                 => is_dir( $app_directory ) && is_writable( $app_directory ),
			'appDirectorySizeBytes'       => $this->get_path_size_bytes( $app_directory ),
			'configPath'                  => $config_path,
			'configExists'                => file_exists( $config_path ),
			'configSizeBytes'             => $this->get_path_size_bytes( $config_path ),
			'contentDirectory'            => $content_directory,
			'contentExists'               => is_dir( $content_directory ),
			'contentWritable'             => is_dir( $content_directory ) && is_writable( $content_directory ),
			'contentDirectorySizeBytes'   => $this->get_path_size_bytes( $content_directory ),
			'languagesDirectory'          => $languages_directory,
			'languagesDirectoryExists'    => is_dir( $languages_directory ),
			'languagesDirectoryReadable'  => is_dir( $languages_directory ) &&
				is_readable( $languages_directory ),
			'languagesDirectoryWritable'  => is_dir( $languages_directory ) &&
				is_writable( $languages_directory ),
			'languagesDirectorySizeBytes' => $this->get_path_size_bytes( $languages_directory ),
			'debugLogPath'                => $debug_log_path,
			'debugLogExists'              => file_exists( $debug_log_path ),
			'debugLogReadable'            => file_exists( $debug_log_path ) &&
				is_readable( $debug_log_path ),
			'debugLogSizeBytes'           => $this->get_path_size_bytes( $debug_log_path ),
		);
	}

	/**
	 * Return the size of a file or directory in bytes.
	 *
	 * @param string $path Absolute file or directory path.
	 * @return int|null Byte size when readable, otherwise null.
	 * @since 1.0.5
	 */
	private function get_path_size_bytes( string $path ): ?int {
		if ( '' === trim( $path ) || ! file_exists( $path ) ) {
			return null;
		}

		if ( is_file( $path ) ) {
			$size = @filesize( $path );

			return false === $size ? null : (int) $size;
		}

		if ( ! is_dir( $path ) ) {
			return null;
		}

		$total_size = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$path,
					FilesystemIterator::SKIP_DOTS,
				),
			);
		} catch ( \UnexpectedValueException $exception ) {
			unset( $exception );
			return null;
		}

		foreach ( $iterator as $file_info ) {
			if (
				! $file_info instanceof SplFileInfo ||
				$file_info->isDir() ||
				$file_info->isLink()
			) {
				continue;
			}

			$file_size = $file_info->getSize();

			if ( $file_size > 0 ) {
				$total_size += $file_size;
			}
		}

		return $total_size;
	}

	/**
	 * Build mail status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_mail_status(): array {
		$status = $this->mailer_service->get_status();

		return array(
			'driver'                 => (string) ( $status['driver'] ?? 'mail' ),
			'fromEmail'              => (string) ( $status['fromEmail'] ?? '' ),
			'fromName'               => (string) ( $status['fromName'] ?? '' ),
			'smtpHost'               => (string) ( $status['smtpHost'] ?? '' ),
			'smtpPort'               => (string) ( $status['smtpPort'] ?? '' ),
			'smtpEncryption'         => (string) ( $status['smtpEncryption'] ?? 'none' ),
			'smtpAuth'               => ! empty( $status['smtpAuth'] ),
			'smtpUsername'           => (string) ( $status['smtpUsername'] ?? '' ),
			'smtpPasswordConfigured' => ! empty(
				$status['smtpPasswordConfigured']
			),
			'configurationLabel'     => (string) ( $status['configurationLabel'] ?? '' ),
			'configurationPath'      => (string) ( $status['configurationPath'] ?? '' ),
			'canManageFromDashboard' => ! empty(
				$status['canManageFromDashboard']
			),
			'manageDisabledReason'   => (string) ( $status['manageDisabledReason'] ?? '' ),
			'transportReady'         => $this->is_mail_transport_ready( $status ),
		);
	}

	/**
	 * Build location-data status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_location_status(): array {
		$status                = $this->geoip_service->get_status();
		$last_downloaded_at    = $this->settings_api->get_option(
			'geoip_last_downloaded_at'
		);
		$last_downloaded_stamp = is_string( $last_downloaded_at )
			? strtotime( $last_downloaded_at . ' UTC' )
			: false;

		return array(
			'databasePath'           => (string) ( $status['databasePath'] ?? '' ),
			'databaseExists'         => ! empty( $status['databaseExists'] ),
			'databaseReadable'       => ! empty( $status['databaseReadable'] ),
			'locationAnalyticsReady' => ! empty(
				$status['locationAnalyticsReady']
			),
			'accountIdConfigured'    => ! empty(
				$status['accountIdConfigured']
			),
			'licenseKeyConfigured'   => ! empty(
				$status['licenseKeyConfigured']
			),
			'credentialsConfigured'  => ! empty(
				$status['credentialsConfigured']
			),
			'accountId'              => (string) ( $status['accountId'] ?? '' ),
			'licenseKeyHint'         => (string) ( $status['licenseKeyHint'] ?? '' ),
			'databaseUpdatedAt'      => (string) ( $status['databaseUpdatedAt'] ?? '' ),
			'databaseSizeBytes'      => (int) ( $status['databaseSizeBytes'] ?? 0 ),
			'configurationLabel'     => (string) ( $status['configurationLabel'] ?? '' ),
			'configurationPath'      => (string) ( $status['configurationPath'] ?? '' ),
			'downloadCommand'        => (string) ( $status['downloadCommand'] ?? '' ),
			'lastDownloadedAt'       => false !== $last_downloaded_stamp
				? gmdate( DATE_ATOM, (int) $last_downloaded_stamp )
				: null,
		);
	}

	/**
	 * Build site data counts.
	 *
	 * @return array<string, int>
	 * @since 1.0.3
	 */
	private function build_data_status(): array {
		return array(
			'managedTables' => count( self::MANAGED_TABLES ),
			'users'         => $this->safe_table_count( 'users' ),
			'links'         => $this->safe_table_count( 'urls' ),
			'clicks'        => $this->safe_table_count( 'clicks' ),
			'sessions'      => $this->count_active_sessions(),
			'apiKeys'       => $this->safe_table_count( 'api_keys' ),
			'webhooks'      => $this->safe_table_count( 'webhooks' ),
			'auditEvents'   => $this->safe_table_count( 'audit_logs' ),
		);
	}

	/**
	 * Build the top-level health check list.
	 *
	 * @param array<string, mixed> $server   Server status section.
	 * @param array<string, mixed> $database Database status section.
	 * @param array<string, mixed> $storage  Storage status section.
	 * @param array<string, mixed> $mail     Mail status section.
	 * @param array<string, mixed> $location Location-data status section.
	 * @return array<int, array<string, string>>
	 * @since 1.0.3
	 */
	private function build_checks(
		array $server,
		array $database,
		array $storage,
		array $mail,
		array $location
	): array {
		$checks   = array();
		$checks[] = array(
			'id'          => 'database',
			'label'       => __( 'Database connection', 'peakurl' ),
			'status'      => ! empty( $database['connected'] ) ? 'ok' : 'error',
			'description' => ! empty( $database['connected'] )
				? __( 'PeakURL can reach the configured MySQL or MariaDB database.', 'peakurl' )
				: __( 'PeakURL could not reach the configured database.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'database-schema',
			'label'       => __( 'Database schema', 'peakurl' ),
			'status'      => ! empty( $database['schemaUpgradeRequired'] )
				? 'warning'
				: 'ok',
			'description' => ! empty( $database['schemaUpgradeRequired'] )
				? __( 'PeakURL found schema changes or leftovers that still need the database upgrader.', 'peakurl' )
				: __( 'The PeakURL database schema matches the current release.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'content',
			'label'       => __( 'Content storage', 'peakurl' ),
			'status'      => ! empty( $storage['contentExists'] ) && ! empty( $storage['contentWritable'] )
				? 'ok'
				: 'error',
			'description' => ! empty( $storage['contentExists'] ) && ! empty( $storage['contentWritable'] )
				? __( 'The persistent content directory is present and writable.', 'peakurl' )
				: __( 'The persistent content directory is missing or not writable.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'languages',
			'label'       => __( 'Language packs', 'peakurl' ),
			'status'      => ! empty( $storage['languagesDirectoryExists'] ) && ! empty( $storage['languagesDirectoryReadable'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $storage['languagesDirectoryExists'] ) && ! empty( $storage['languagesDirectoryReadable'] )
				? __( 'Installed dashboard and PHP translation files can be read from content/languages.', 'peakurl' )
				: __( 'PeakURL cannot read the content/languages directory right now.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'intl',
			'label'       => __( 'PHP intl extension', 'peakurl' ),
			'status'      => ! empty( $server['extensions']['intl'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $server['extensions']['intl'] )
				? __( 'Server-side locale helpers can use native intl metadata.', 'peakurl' )
				: __( 'PeakURL can still run without intl, but server-side locale helpers use fallbacks.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'zip',
			'label'       => __( 'PHP ZipArchive', 'peakurl' ),
			'status'      => ! empty( $server['extensions']['zip'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $server['extensions']['zip'] )
				? __( 'Dashboard updates can use ZipArchive when the install type allows it.', 'peakurl' )
				: __( 'Dashboard-managed updates require the PHP ZipArchive extension.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'mail',
			'label'       => __( 'Email transport', 'peakurl' ),
			'status'      => ! empty( $mail['transportReady'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $mail['transportReady'] )
				? __( 'PeakURL has an active mail transport configuration.', 'peakurl' )
				: __( 'PeakURL is missing part of the active mail transport configuration.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'geoip',
			'label'       => __( 'Location data', 'peakurl' ),
			'status'      => ! empty( $location['locationAnalyticsReady'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $location['locationAnalyticsReady'] )
				? __( 'The GeoLite2 City database is available for visitor location analytics.', 'peakurl' )
				: __( 'Visitor location analytics is not fully ready yet.', 'peakurl' ),
		);

		return $checks;
	}

	/**
	 * Build the overall status summary from the check list.
	 *
	 * @param array<int, array<string, string>> $checks Health checks list.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_summary( array $checks ): array {
		$ok_count      = 0;
		$warning_count = 0;
		$error_count   = 0;

		foreach ( $checks as $check ) {
			$state = (string) ( $check['status'] ?? '' );

			if ( 'error' === $state ) {
				++$error_count;
				continue;
			}

			if ( 'warning' === $state ) {
				++$warning_count;
				continue;
			}

			if ( 'ok' === $state ) {
				++$ok_count;
			}
		}

		return array(
			'overall'      => $error_count > 0
				? 'error'
				: ( $warning_count > 0 ? 'warning' : 'ok' ),
			'okCount'      => $ok_count,
			'warningCount' => $warning_count,
			'errorCount'   => $error_count,
			'totalChecks'  => count( $checks ),
		);
	}

	/**
	 * Find the language entry for the current locale.
	 *
	 * @param array<int, array<string, mixed>> $languages Installed languages.
	 * @param string                           $locale    Active locale.
	 * @return array<string, mixed>|null
	 * @since 1.0.3
	 */
	private function find_language( array $languages, string $locale ): ?array {
		foreach ( $languages as $language ) {
			if ( (string) ( $language['locale'] ?? '' ) === $locale ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Query basic database server metadata.
	 *
	 * @return array<string, string>
	 * @since 1.0.3
	 */
	private function query_database_metadata(): array {
		try {
			$statement = $this->db->get_connection()->query(
				'SELECT VERSION() AS version, @@version_comment AS version_comment'
			);
			$row       = $statement instanceof \PDOStatement
				? $statement->fetch( \PDO::FETCH_ASSOC )
				: false;

			if ( is_array( $row ) ) {
				return array(
					'version'        => trim( (string) ( $row['version'] ?? '' ) ),
					'versionComment' => trim(
						(string) ( $row['version_comment'] ?? '' )
					),
				);
			}
		} catch ( \Throwable $exception ) {
			return array();
		}

		return array();
	}

	/**
	 * Detect the database server family.
	 *
	 * @param string $version         Version string.
	 * @param string $version_comment Version comment.
	 * @return string
	 * @since 1.0.3
	 */
	private function detect_database_server_type(
		string $version,
		string $version_comment
	): string {
		$haystack = strtolower( $version . ' ' . $version_comment );

		if ( false !== strpos( $haystack, 'mariadb' ) ) {
			return 'MariaDB';
		}

		if ( '' !== trim( $version ) || '' !== trim( $version_comment ) ) {
			return 'MySQL';
		}

		return 'Unknown';
	}

	/**
	 * Determine whether the active mail transport is complete enough to use.
	 *
	 * @param array<string, mixed> $status Mail status payload.
	 * @return bool
	 * @since 1.0.3
	 */
	private function is_mail_transport_ready( array $status ): bool {
		$driver = (string) ( $status['driver'] ?? 'mail' );

		if ( 'smtp' !== $driver ) {
			return true;
		}

		if ( '' === trim( (string) ( $status['smtpHost'] ?? '' ) ) ) {
			return false;
		}

		if ( empty( $status['smtpAuth'] ) ) {
			return true;
		}

		return '' !== trim( (string) ( $status['smtpUsername'] ?? '' ) ) &&
			! empty( $status['smtpPasswordConfigured'] );
	}

	/**
	 * Count rows in a managed table without throwing on edge failures.
	 *
	 * @param string $table_name Managed table name without prefix.
	 * @return int
	 * @since 1.0.3
	 */
	private function safe_table_count( string $table_name ): int {
		try {
			return $this->db->count( $table_name );
		} catch ( \Throwable $exception ) {
			return 0;
		}
	}

	/**
	 * Count currently-active session rows.
	 *
	 * @return int
	 * @since 1.0.3
	 */
	private function count_active_sessions(): int {
		try {
			return (int) $this->db->get_var(
				'SELECT COUNT(*)
				FROM sessions
				WHERE revoked_at IS NULL
				AND last_active_at >= :active_since',
				array(
					'active_since' => $this->session_active_since(),
				),
			);
		} catch ( \Throwable $exception ) {
			return 0;
		}
	}

	/**
	 * Get the active PeakURL version string.
	 *
	 * @return string
	 * @since 1.0.3
	 */
	private function get_peakurl_version(): string {
		$version = trim(
			(string) ( $this->config[ Constants::CONFIG_VERSION ] ?? '' ),
		);

		return '' !== $version ? $version : Constants::DEFAULT_VERSION;
	}

	/**
	 * Calculate the earliest session timestamp that is still active.
	 *
	 * @return string
	 * @since 1.0.3
	 */
	private function session_active_since(): string {
		return gmdate(
			'Y-m-d H:i:s',
			time() - max(
				0,
				(int) ( $this->config[ Constants::CONFIG_SESSION_LIFETIME ] ?? Constants::DEFAULT_SESSION_LIFETIME ),
			),
		);
	}

	/**
	 * Determine whether the app is running from the source checkout.
	 *
	 * @return bool
	 * @since 1.0.3
	 */
	private function is_source_checkout(): bool {
		return file_exists( ABSPATH . 'package.json' ) || is_dir( ABSPATH . '.git' );
	}
}
