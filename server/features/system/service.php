<?php
/**
 * System domain service.
 *
 * Coordinates admin notices, system health diagnostics, and runtime updates.
 *
 * @package PeakURL\Features\System
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\System;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Plugins\Extensions;
use PeakURL\Core\Plugins\Metadata;
use PeakURL\Core\Plugins\Registry;
use PeakURL\Core\Plugins\Type;
use PeakURL\Core\Plugins\Validator;
use PeakURL\Core\Scheduler\Scheduler;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\AdminNotices;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as SchemaService;
use PeakURL\Services\Geoip;
use PeakURL\Services\I18n;
use PeakURL\Services\Mailer;
use PeakURL\Services\SystemStatus\Manager as SystemStatusManager;
use PeakURL\Services\Update\Manager as UpdateManager;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — System diagnostics, notices, and updater engine.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Database connection instance.
	 *
	 * @var Connection
	 * @since 1.0.0
	 */
	private Connection $connection;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.0.0
	 */
	private AuthService $auth_service;

	/**
	 * Settings API.
	 *
	 * @var SettingsApi|null
	 * @since 1.0.0
	 */
	private ?SettingsApi $settings_api = null;

	/**
	 * GeoIP service.
	 *
	 * @var Geoip
	 * @since 1.0.0
	 */
	private Geoip $geoip_service;

	/**
	 * Mailer service.
	 *
	 * @var Mailer
	 * @since 1.0.0
	 */
	private Mailer $mailer_service;

	/**
	 * Database schema service.
	 *
	 * @var SchemaService
	 * @since 1.0.0
	 */
	private SchemaService $schema_service;

	/**
	 * I18n helper.
	 *
	 * @var I18n
	 * @since 1.0.0
	 */
	private I18n $i18n_service;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	/**
	 * Runtime config map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Background job scheduler instance.
	 *
	 * @var Scheduler|null
	 * @since 1.7.0
	 */
	private ?Scheduler $scheduler;

	/**
	 * Update manager instance.
	 *
	 * @var UpdateManager|null
	 * @since 1.7.1
	 */
	private ?UpdateManager $update_manager;

	/**
	 * Create a new System Service instance.
	 *
	 * @param PeakURL_DB           $db             Shared database wrapper.
	 * @param Connection           $connection     Database connection instance.
	 * @param AuthService          $auth_service   Authentication domain service.
	 * @param SettingsApi          $settings_api   Settings API.
	 * @param Geoip                $geoip_service  GeoIP service.
	 * @param Mailer               $mailer_service Mailer service.
	 * @param SchemaService        $schema_service Database schema service.
	 * @param I18n                 $i18n_service   I18n helper.
	 * @param Roles                $roles          Roles registry.
	 * @param Authorization        $authorization  Authorization helper.
	 * @param array<string, mixed> $config         Runtime config map.
	 * @param Scheduler|null       $scheduler      Optional scheduler instance.
	 * @param UpdateManager|null   $update_manager Optional update manager instance.
	 * @since 1.0.0
	 * @since 1.7.1 Added $update_manager parameter.
	 */
	public function __construct(
		PeakURL_DB $db,
		Connection $connection,
		AuthService $auth_service,
		SettingsApi $settings_api,
		Geoip $geoip_service,
		Mailer $mailer_service,
		SchemaService $schema_service,
		I18n $i18n_service,
		Roles $roles,
		Authorization $authorization,
		array $config,
		?Scheduler $scheduler = null,
		?UpdateManager $update_manager = null
	) {
		$this->db             = $db;
		$this->connection     = $connection;
		$this->auth_service   = $auth_service;
		$this->settings_api   = $settings_api;
		$this->geoip_service  = $geoip_service;
		$this->mailer_service = $mailer_service;
		$this->schema_service = $schema_service;
		$this->i18n_service   = $i18n_service;
		$this->roles          = $roles;
		$this->authorization  = $authorization;
		$this->config         = $config;
		$this->scheduler      = $scheduler;
		$this->update_manager = $update_manager;
	}

	/**
	 * Return the current update-management user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_update_user( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

		return $user;
	}

	/**
	 * Return the current plugin-management user.
	 *
	 * Requires manage_updates or manage_site_settings capability.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 *
	 * @throws ApiException When permission is denied.
	 * @since 1.8.0
	 */
	private function get_plugins_user( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );

		if (
			! $this->authorization->has_capability( $user, 'manage_updates' ) &&
			! $this->authorization->has_capability( $user, 'manage_site_settings' )
		) {
			throw new ApiException(
				__( 'Admin access is required.', 'peakurl' ),
				403
			);
		}

		return $user;
	}

	/**
	 * Return the current dashboard admin notices.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<int, array<string, mixed>> Admin notices list.
	 * @since 1.0.0
	 */
	public function get_admin_notices( Request $request ): array {
		$user    = $this->auth_service->get_current_user( $request );
		$service = new AdminNotices();

		return $service->get_notices(
			$this->get_admin_notice_context( $user ),
		);
	}

	/**
	 * Return the dashboard system-status payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> System status diagnostics.
	 * @since 1.0.0
	 */
	public function get_system_status( Request $request ): array {
		$this->get_update_user( $request );

		$service = new SystemStatusManager(
			$this->config,
			$this->db,
			$this->settings_api,
			$this->geoip_service,
			$this->mailer_service,
			$this->schema_service,
			$this->i18n_service,
		);

		return $service->get_status();
	}

	/**
	 * Return safe metadata for all discovered plugins.
	 *
	 * Validates administrator permissions and queries the canonical Registry.
	 * Never executes plugin code and strips internal filesystem paths.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Safe plugin inventory.
	 * @since 1.8.0
	 */
	/**
	 * Retrieve the list of active regular plugin identifiers from settings.
	 *
	 * @param bool $fail_hard Whether to fail explicitly when settings storage is unavailable.
	 * @return array<int, string>
	 * @throws ApiException When $fail_hard is true and settings persistence fails.
	 * @since 1.8.0
	 */
	public function get_active_plugin_ids( bool $fail_hard = false ): array {
		if ( ! isset( $this->settings_api ) || null === $this->settings_api ) {
			if ( $fail_hard ) {
				throw new ApiException(
					__( 'Settings persistence is unavailable.', 'peakurl' ),
					500
				);
			}
			return array();
		}

		try {
			$raw = $this->settings_api->get_option( 'active_plugins' );
		} catch ( \Throwable $e ) {
			if ( $fail_hard ) {
				throw new ApiException(
					sprintf(
						// translators: %s: Error message.
						__( 'Failed to retrieve active plugins: %s', 'peakurl' ),
						$e->getMessage()
					),
					500
				);
			}
			return array();
		}

		if ( null === $raw || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$active = array();
		foreach ( $decoded as $id ) {
			if ( is_string( $id ) && '' !== trim( $id ) ) {
				$active[] = trim( $id );
			}
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * Return safe metadata for all discovered plugins.
	 *
	 * Validates administrator permissions and queries the canonical Registry.
	 * Never executes plugin code and strips internal filesystem paths.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Safe plugin inventory.
	 * @since 1.8.0
	 */
	public function get_installed_plugins( Request $request ): array {
		$this->get_plugins_user( $request );

		$content_dir = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );
		$registry    = new Registry( $content_dir );
		$all         = $registry->all();
		$regular     = $registry->regular();
		$must_use    = $registry->must_use();
		$active_ids  = $this->get_active_plugin_ids();

		$mapped_regular = array();
		foreach ( $regular as $id => $meta ) {
			$is_active        = in_array( $id, $active_ids, true );
			$mapped_regular[] = $meta->with_active( $is_active )->to_array();
		}

		$mapped_must_use = array();
		foreach ( $must_use as $id => $meta ) {
			$mapped_must_use[] = $meta->with_active( true )->to_array();
		}

		$mapped_items = array();
		foreach ( $all as $key => $meta ) {
			$is_mu          = Type::MUST_USE === $meta->type();
			$is_active      = $is_mu || in_array( $meta->id(), $active_ids, true );
			$mapped_items[] = $meta->with_active( $is_active )->to_array();
		}

		return array(
			'items'       => $mapped_items,
			'regular'     => $mapped_regular,
			'mustUse'     => $mapped_must_use,
			'total'       => count( $all ),
			'uploadLimit' => $this->get_effective_upload_limit(),
		);
	}

	/**
	 * Activate a regular plugin.
	 *
	 * Re-checks manifest and compatibility before persisting activation.
	 * Uses SettingsApi::update_option_with_lock() for concurrency safety.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Activation result payload.
	 * @throws ApiException When permission is denied, plugin is missing, incompatible, or persistence fails.
	 * @since 1.8.0
	 */
	public function activate_plugin( Request $request ): array {
		$this->get_plugins_user( $request );

		$id = trim( (string) ( $request->get_body_param( 'id' ) ?? ( $request->get_route_param( 'id' ) ?? ( $request->get_query_param( 'id' ) ?? '' ) ) ) );
		if ( '' === $id || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
			throw new ApiException(
				__( 'Invalid plugin identifier.', 'peakurl' ),
				400
			);
		}

		if ( ! isset( $this->settings_api ) || null === $this->settings_api ) {
			throw new ApiException(
				__( 'Settings persistence is unavailable.', 'peakurl' ),
				500
			);
		}

		$content_dir = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );
		$registry    = new Registry( $content_dir );
		$meta        = $registry->find( $id, Type::REGULAR );

		if ( null === $meta ) {
			throw new ApiException(
				__( 'Plugin not found.', 'peakurl' ),
				404
			);
		}

		if ( ! $meta->is_valid() ) {
			$errors    = $meta->errors();
			$error_msg = ! empty( $errors )
				? implode( ' ', $errors )
				: __( 'Plugin manifest is invalid.', 'peakurl' );
			throw new ApiException( $error_msg, 400 );
		}

		if ( ! $meta->is_compatible() ) {
			$errors    = $meta->errors();
			$error_msg = ! empty( $errors )
				? implode( ' ', $errors )
				: __( 'Plugin is not compatible with this environment.', 'peakurl' );
			throw new ApiException( $error_msg, 400 );
		}

		$active_ids = array();
		try {
			$this->settings_api->update_option_with_lock(
				'active_plugins',
				function ( ?string $current_raw ) use ( $id, &$active_ids ): string {
					$current = array();
					if ( null !== $current_raw && '' !== trim( $current_raw ) ) {
						$decoded = json_decode( $current_raw, true );
						if ( is_array( $decoded ) ) {
							foreach ( $decoded as $item ) {
								if ( is_string( $item ) && '' !== trim( $item ) ) {
									$current[] = trim( $item );
								}
							}
						}
					}
					$current = array_values( array_unique( $current ) );
					if ( ! in_array( $id, $current, true ) ) {
						$current[] = $id;
					}
					sort( $current, SORT_STRING );
					$active_ids = $current;

					return peakurl_json_encode( $current );
				}
			);
		} catch ( \Throwable $e ) {
			throw new ApiException(
				sprintf(
					// translators: %s: Error message.
					__( 'Failed to persist plugin activation: %s', 'peakurl' ),
					$e->getMessage()
				),
				500
			);
		}

		return array(
			'id'            => $id,
			'active'        => true,
			'activePlugins' => $active_ids,
		);
	}

	/**
	 * Deactivate a regular plugin.
	 *
	 * Removes the plugin identifier from the persistent active_plugins setting.
	 * Uses SettingsApi::update_option_with_lock() for concurrency safety.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Deactivation result payload.
	 * @throws ApiException When permission is denied, identifier is missing, or persistence fails.
	 * @since 1.8.0
	 */
	public function deactivate_plugin( Request $request ): array {
		$this->get_plugins_user( $request );

		$id = trim( (string) ( $request->get_body_param( 'id' ) ?? ( $request->get_route_param( 'id' ) ?? ( $request->get_query_param( 'id' ) ?? '' ) ) ) );
		if ( '' === $id ) {
			throw new ApiException(
				__( 'Invalid plugin identifier.', 'peakurl' ),
				400
			);
		}

		if ( ! isset( $this->settings_api ) || null === $this->settings_api ) {
			throw new ApiException(
				__( 'Settings persistence is unavailable.', 'peakurl' ),
				500
			);
		}

		$active_ids = array();
		try {
			$this->settings_api->update_option_with_lock(
				'active_plugins',
				function ( ?string $current_raw ) use ( $id, &$active_ids ): string {
					$current = array();
					if ( null !== $current_raw && '' !== trim( $current_raw ) ) {
						$decoded = json_decode( $current_raw, true );
						if ( is_array( $decoded ) ) {
							foreach ( $decoded as $item ) {
								if ( is_string( $item ) && '' !== trim( $item ) ) {
									$current[] = trim( $item );
								}
							}
						}
					}
					$current = array_values( array_unique( $current ) );
					$current = array_values(
						array_filter(
							$current,
							static fn( string $item ): bool => $item !== $id
						)
					);
					sort( $current, SORT_STRING );
					$active_ids = $current;

					return peakurl_json_encode( $current );
				}
			);
		} catch ( \Throwable $e ) {
			throw new ApiException(
				sprintf(
					// translators: %s: Error message.
					__( 'Failed to persist plugin deactivation: %s', 'peakurl' ),
					$e->getMessage()
				),
				500
			);
		}

		return array(
			'id'            => $id,
			'active'        => false,
			'activePlugins' => $active_ids,
		);
	}

	/**
	 * Safely delete an inactive regular plugin from disk.
	 *
	 * Enforces strict filesystem containment, rejects active plugins, and never
	 * traverses symlinks outside the plugin directory.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Deletion result payload.
	 * @throws ApiException When unauthorized, active, escaping containment, or deletion fails.
	 * @since 1.8.0
	 */
	public function delete_plugin( Request $request ): array {
		$this->get_plugins_user( $request );

		$id = trim( (string) ( $request->get_body_param( 'id' ) ?? ( $request->get_route_param( 'id' ) ?? ( $request->get_query_param( 'id' ) ?? '' ) ) ) );
		if ( '' === $id || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
			throw new ApiException(
				__( 'Invalid plugin identifier.', 'peakurl' ),
				400
			);
		}

		$active_ids = $this->get_active_plugin_ids( true );
		if ( in_array( $id, $active_ids, true ) ) {
			throw new ApiException(
				__( 'Cannot delete an active plugin. Please deactivate the plugin first.', 'peakurl' ),
				400
			);
		}

		$content_dir = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );

		// Forbid deleting must-use plugins through dashboard.
		$mu_dir = $content_dir . '/mu-plugins/' . $id;
		if ( is_dir( $mu_dir ) ) {
			throw new ApiException(
				__( 'Must-use plugins cannot be deleted through the dashboard.', 'peakurl' ),
				403
			);
		}

		$plugins_root = $content_dir . '/plugins';
		if ( ! is_dir( $plugins_root ) ) {
			throw new ApiException(
				__( 'Plugins directory not found.', 'peakurl' ),
				404
			);
		}

		$plugins_root_real = realpath( $plugins_root );
		if ( false === $plugins_root_real ) {
			throw new ApiException(
				__( 'Plugins directory path could not be resolved.', 'peakurl' ),
				500
			);
		}

		$target_dir = $plugins_root . '/' . $id;
		if ( ! is_dir( $target_dir ) ) {
			throw new ApiException(
				__( 'Plugin directory not found.', 'peakurl' ),
				404
			);
		}

		$target_real = realpath( $target_dir );
		if (
			false === $target_real ||
			! str_starts_with( $target_real, $plugins_root_real . DIRECTORY_SEPARATOR )
		) {
			throw new ApiException(
				__( 'Plugin path escapes plugins directory containment.', 'peakurl' ),
				400
			);
		}

		$this->safe_recursive_delete( $target_real );

		if ( file_exists( $target_dir ) ) {
			throw new ApiException(
				__( 'Could not remove plugin directory from disk.', 'peakurl' ),
				500
			);
		}

		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/**
	 * Parse PHP shorthand byte notation (e.g. 2M, 512K, 1G) into integer bytes.
	 *
	 * Handles case-insensitivity, negative/zero values, and standard byte suffixes.
	 *
	 * @param string $size Raw ini value string.
	 * @return int Byte count, or 0 if empty/invalid/unlimited.
	 * @since 1.8.0
	 */
	public function parse_ini_size( string $size ): int {
		$size = trim( $size );
		if ( '' === $size ) {
			return 0;
		}

		if ( preg_match( '/^(-?\d+)\s*([kmgb])?$/i', $size, $matches ) ) {
			$value = (int) $matches[1];
			if ( $value <= 0 ) {
				return 0;
			}

			$unit = isset( $matches[2] ) ? strtolower( $matches[2] ) : '';
			return match ( $unit ) {
				'g'     => $value * 1024 * 1024 * 1024,
				'm'     => $value * 1024 * 1024,
				'k'     => $value * 1024,
				default => $value,
			};
		}

		return max( 0, (int) $size );
	}

	/**
	 * Format a byte integer into a human-readable size string (e.g. "2 MB", "64 MB").
	 *
	 * @param int $bytes Byte count.
	 * @return string Formatted byte string.
	 * @since 1.8.0
	 */
	public function format_byte_size( int $bytes ): string {
		if ( $bytes >= 1024 * 1024 * 1024 ) {
			$gb = $bytes / ( 1024 * 1024 * 1024 );
			return ( floor( $gb ) === $gb ? (string) (int) $gb : (string) round( $gb, 1 ) ) . ' GB';
		}

		if ( $bytes >= 1024 * 1024 ) {
			$mb = $bytes / ( 1024 * 1024 );
			return ( floor( $mb ) === $mb ? (string) (int) $mb : (string) round( $mb, 1 ) ) . ' MB';
		}

		if ( $bytes >= 1024 ) {
			$kb = $bytes / 1024;
			return ( floor( $kb ) === $kb ? (string) (int) $kb : (string) round( $kb, 1 ) ) . ' KB';
		}

		return $bytes . ' B';
	}

	/**
	 * Compute the effective plugin ZIP upload limit in bytes and formatted string.
	 *
	 * Evaluates min(upload_max_filesize, post_max_size) while accounting for post_max_size = 0 (unlimited)
	 * and multipart payload overhead.
	 *
	 * @param string|null $upload_max Optional override for upload_max_filesize (for testing).
	 * @param string|null $post_max Optional override for post_max_size (for testing).
	 * @return array{bytes: int, formatted: string} Effective upload limit metadata.
	 * @since 1.8.0
	 */
	public function get_effective_upload_limit( ?string $upload_max = null, ?string $post_max = null ): array {
		$upload_str = null !== $upload_max ? $upload_max : (string) ini_get( 'upload_max_filesize' );
		$post_str   = null !== $post_max ? $post_max : (string) ini_get( 'post_max_size' );

		$upload_bytes = $this->parse_ini_size( $upload_str );
		$post_bytes   = $this->parse_ini_size( $post_str );

		$effective = $upload_bytes;
		if ( $post_bytes > 0 ) {
			$effective = ( $upload_bytes > 0 ) ? min( $upload_bytes, $post_bytes ) : $post_bytes;
		}

		if ( isset( $this->config['PEAKURL_MAX_PLUGIN_UPLOAD_SIZE'] ) ) {
			$app_bytes = $this->parse_ini_size( (string) $this->config['PEAKURL_MAX_PLUGIN_UPLOAD_SIZE'] );
			if ( $app_bytes > 0 ) {
				$effective = min( $effective, $app_bytes );
			}
		}

		// Fallback if ini values are unparseable or zero.
		if ( $effective <= 0 ) {
			$effective = 2 * 1024 * 1024;
		}

		return array(
			'bytes'     => $effective,
			'formatted' => $this->format_byte_size( $effective ),
		);
	}

	/**
	 * Upload, validate, and install a regular plugin archive.
	 *
	 * Performs comprehensive ZIP validation, manifest check, and collision prevention.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Installed plugin metadata.
	 * @throws ApiException When validation fails or upload is rejected.
	 * @since 1.8.0
	 */
	public function upload_plugin( Request $request ): array {
		$this->get_plugins_user( $request );

		$effective_limit = $this->get_effective_upload_limit();

		$file = $request->get_file( 'plugin_zip' )
			?? ( $request->get_file( 'file' ) ?? $request->get_file( 'archive' ) );

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			$upload_error = is_array( $file ) ? (int) ( $file['error'] ?? UPLOAD_ERR_OK ) : UPLOAD_ERR_OK;
			if ( UPLOAD_ERR_INI_SIZE === $upload_error || UPLOAD_ERR_FORM_SIZE === $upload_error ) {
				throw new ApiException(
					sprintf(
						// translators: %s: Maximum allowed archive size formatted.
						__( 'Plugin archive exceeds the %s maximum upload limit.', 'peakurl' ),
						$effective_limit['formatted']
					),
					400
				);
			}

			throw new ApiException(
				__( 'No plugin archive was uploaded.', 'peakurl' ),
				400
			);
		}

		if ( (int) ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
			$err_code = (int) $file['error'];
			if ( UPLOAD_ERR_INI_SIZE === $err_code || UPLOAD_ERR_FORM_SIZE === $err_code ) {
				throw new ApiException(
					sprintf(
						// translators: %s: Maximum allowed archive size formatted.
						__( 'Plugin archive exceeds the %s maximum upload limit.', 'peakurl' ),
						$effective_limit['formatted']
					),
					400
				);
			}

			throw new ApiException(
				// translators: %d: Upload error code.
				sprintf( __( 'Upload failed with error code: %d.', 'peakurl' ), $err_code ),
				400
			);
		}

		$tmp_file = (string) $file['tmp_name'];
		if ( ! is_file( $tmp_file ) || ! is_readable( $tmp_file ) ) {
			throw new ApiException(
				__( 'Uploaded file is not readable on the server.', 'peakurl' ),
				400
			);
		}

		// Enforce dynamic server upload limit.
		if ( (int) ( $file['size'] ?? 0 ) > $effective_limit['bytes'] || filesize( $tmp_file ) > $effective_limit['bytes'] ) {
			throw new ApiException(
				sprintf(
					// translators: %s: Maximum allowed archive size formatted.
					__( 'Plugin archive exceeds the %s maximum upload limit.', 'peakurl' ),
					$effective_limit['formatted']
				),
				400
			);
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new ApiException(
				__( 'ZipArchive PHP extension is required on the server to install plugins.', 'peakurl' ),
				500
			);
		}

		$zip = new \ZipArchive();
		$res = $zip->open( $tmp_file, \ZipArchive::CHECKCONS );
		if ( true !== $res ) {
			throw new ApiException(
				__( 'Uploaded file is not a valid ZIP archive.', 'peakurl' ),
				400
			);
		}

		$max_entries    = 5000;
		$max_total_size = 100 * 1024 * 1024; // 100 MB.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Built-in PHP ZipArchive property.
		$num_files          = $zip->numFiles;
		$total_uncompressed = 0;

		if ( $num_files <= 0 || $num_files > $max_entries ) {
			$zip->close();
			throw new ApiException(
				sprintf(
					// translators: 1: Number of entries in archive, 2: Maximum allowed entries.
					__( 'Archive contains an invalid number of entries (%1$d). Maximum permitted is %2$d.', 'peakurl' ),
					$num_files,
					$max_entries
				),
				400
			);
		}

		$manifest_count  = 0;
		$manifest_index  = null;
		$manifest_path   = null;
		$entry_points    = array();
		$entry_names     = array();
		$canonical_paths = array();

		for ( $i = 0; $i < $num_files; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}

			$raw_name = (string) $stat['name'];

			// 1. Prohibit Windows backslashes immediately instead of rewriting.
			if ( str_contains( $raw_name, '\\' ) ) {
				$zip->close();
				throw new ApiException(
					__( 'Archive contains invalid path separator (backslash).', 'peakurl' ),
					400
				);
			}

			// 2. Prohibit null bytes and ASCII control characters.
			if ( preg_match( '/[\x00-\x1f\x7f]/', $raw_name ) ) {
				$zip->close();
				throw new ApiException(
					__( 'Archive contains unsafe path entries.', 'peakurl' ),
					400
				);
			}

			// 3. Prohibit absolute paths, Windows drive letters, empty path segments (//), and traversal/dot segments.
			if (
				str_starts_with( $raw_name, '/' ) ||
				preg_match( '/^[a-zA-Z]:/', $raw_name ) ||
				str_contains( $raw_name, '//' ) ||
				preg_match( '#(^|/)\.{1,2}(/|$)#', $raw_name )
			) {
				$zip->close();
				throw new ApiException(
					__( 'Archive contains unsafe path entries.', 'peakurl' ),
					400
				);
			}

			// 4. Duplicate and colliding entry detection.
			if ( isset( $entry_names[ $raw_name ] ) ) {
				$zip->close();
				throw new ApiException(
					__( 'Archive contains duplicate path entries.', 'peakurl' ),
					400
				);
			}
			$entry_names[ $raw_name ] = true;

			$normalized_name = rtrim( $raw_name, '/' );
			if ( isset( $canonical_paths[ $normalized_name ] ) && $canonical_paths[ $normalized_name ] !== $raw_name ) {
				$zip->close();
				throw new ApiException(
					__( 'Archive contains colliding path entries.', 'peakurl' ),
					400
				);
			}
			$canonical_paths[ $normalized_name ] = $raw_name;

			// 5. Detect symlinks and unsupported entry types using ZipArchive::getExternalAttributesIndex().
			$opsys = 0;
			$attr  = 0;
			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) ) {
				if ( \ZipArchive::OPSYS_UNIX === $opsys ) {
					$file_mode = ( $attr >> 16 ) & 0170000;
					if ( 0120000 === $file_mode ) {
						$zip->close();
						throw new ApiException(
							__( 'Archive contains symlinks, which are forbidden.', 'peakurl' ),
							400
						);
					}
					if ( 0 !== $file_mode && 0100000 !== $file_mode && 0040000 !== $file_mode ) {
						$zip->close();
						throw new ApiException(
							__( 'Archive contains unsupported entry types.', 'peakurl' ),
							400
						);
					}
				}
			}

			// 6. Uncompressed and compressed size evaluation using comp_size.
			$uncompressed_size   = (int) ( $stat['size'] ?? 0 );
			$compressed_size     = (int) ( $stat['comp_size'] ?? 0 );
			$total_uncompressed += $uncompressed_size;

			if ( $total_uncompressed > $max_total_size ) {
				$zip->close();
				throw new ApiException(
					__( 'Total decompressed archive size exceeds 100 MB limit.', 'peakurl' ),
					400
				);
			}

			if ( $uncompressed_size > 1024 ) {
				if ( $compressed_size <= 0 || ( $uncompressed_size / $compressed_size ) > 100 ) {
					$zip->close();
					throw new ApiException(
						__( 'Archive entry exceeds maximum safe compression ratio.', 'peakurl' ),
						400
					);
				}
			}

			// 7. Track manifests and entry point files.
			$basename = basename( $raw_name );
			if ( 'plugin.json' === $basename ) {
				++$manifest_count;
				if ( $manifest_count > 1 ) {
					$zip->close();
					throw new ApiException(
						__( 'Archive contains multiple plugin manifests.', 'peakurl' ),
						400
					);
				}
				$manifest_index = $i;
				$manifest_path  = $raw_name;
			} elseif ( 'plugin.php' === $basename ) {
				$entry_points[ $raw_name ] = $i;
			}
		}

		if ( null === $manifest_index || null === $manifest_path ) {
			$zip->close();
			throw new ApiException(
				__( 'Archive must contain plugin.json.', 'peakurl' ),
				400
			);
		}

		$manifest_dir = str_contains( $manifest_path, '/' ) ? dirname( $manifest_path ) : '';
		$root_folder  = null;

		if ( '' !== $manifest_dir && '.' !== $manifest_dir ) {
			$dir_segments = explode( '/', $manifest_dir );
			if ( count( $dir_segments ) > 1 ) {
				$zip->close();
				throw new ApiException(
					__( 'Archive contains plugin manifest nested beyond supported top-level package directory.', 'peakurl' ),
					400
				);
			}
			$root_folder = $dir_segments[0];

			// Enforce that all entries in the package reside within this single top-level directory.
			foreach ( array_keys( $entry_names ) as $name ) {
				if ( $name !== $root_folder && $name !== $root_folder . '/' && ! str_starts_with( $name, $root_folder . '/' ) ) {
					$zip->close();
					throw new ApiException(
						__( 'Archive contains entries outside the single top-level package directory.', 'peakurl' ),
						400
					);
				}
			}

			$expected_entry_point = $root_folder . '/plugin.php';
		} else {
			// Root package layout: plugin.json and plugin.php must both be at root.
			$expected_entry_point = 'plugin.php';
		}

		if ( ! isset( $entry_points[ $expected_entry_point ] ) ) {
			$zip->close();
			throw new ApiException(
				__( 'Archive must contain plugin.php in the exact same directory as plugin.json.', 'peakurl' ),
				400
			);
		}

		// Read and validate manifest directly from archive memory.
		$manifest_raw = $zip->getFromIndex( $manifest_index );
		if ( false === $manifest_raw || strlen( $manifest_raw ) > Validator::MAX_MANIFEST_BYTES ) {
			$zip->close();
			throw new ApiException(
				__( 'plugin.json is missing or exceeds 64 KB limit.', 'peakurl' ),
				400
			);
		}

		$validator = new Validator();
		$decoded   = json_decode( $manifest_raw, true );
		if ( ! is_array( $decoded ) ) {
			$zip->close();
			throw new ApiException(
				__( 'plugin.json contains invalid JSON.', 'peakurl' ),
				400
			);
		}

		$validation = $validator->validate( $decoded );
		if ( ! $validation['valid'] ) {
			$zip->close();
			throw new ApiException(
				sprintf(
					// translators: %s: Comma-separated list of validation errors.
					__( 'Manifest validation failed: %s', 'peakurl' ),
					implode( '; ', $validation['errors'] )
				),
				400
			);
		}

		$requires      = (string) ( $validation['manifest']['requires'] ?? '' );
		$tested_up_to  = (string) ( $validation['manifest']['testedUpTo'] ?? '' );
		$php_req       = (string) ( $validation['manifest']['php'] ?? '' );
		$host_version  = (string) ( $this->config[ Constants::VERSION ] ?? '' );
		$compatibility = $validator->check_compatibility(
			$requires,
			$tested_up_to,
			$php_req,
			'' !== $host_version ? $host_version : null
		);
		if ( ! $compatibility['compatible'] ) {
			$zip->close();
			throw new ApiException(
				sprintf(
					// translators: %s: Comma-separated list of compatibility errors.
					__( 'Plugin is incompatible with this system: %s', 'peakurl' ),
					implode( '; ', $compatibility['errors'] )
				),
				400
			);
		}

		// Derive and validate identifier.
		$candidate_id = null !== $root_folder ? (string) $root_folder : '';
		if ( '' === $candidate_id && isset( $decoded['id'] ) && is_string( $decoded['id'] ) ) {
			$candidate_id = trim( $decoded['id'] );
		}
		if ( '' === $candidate_id ) {
			$candidate_id = preg_replace( '/[^a-z0-9_-]/', '-', strtolower( (string) ( $decoded['name'] ?? '' ) ) ) ?? '';
			$candidate_id = trim( $candidate_id, '-_' );
		}

		if ( '' === $candidate_id || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $candidate_id ) ) {
			$zip->close();
			throw new ApiException(
				__( 'Could not determine a valid plugin folder identifier from archive.', 'peakurl' ),
				400
			);
		}

		$content_dir    = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );
		$plugins_parent = $content_dir . '/plugins';
		$mu_parent      = $content_dir . '/mu-plugins';
		$target_dest    = $plugins_parent . '/' . $candidate_id;
		$mu_target      = $mu_parent . '/' . $candidate_id;

		// Must-use plugins are server-operator managed and cannot be updated through the dashboard.
		if ( is_link( $mu_target ) || file_exists( $mu_target ) || is_dir( $mu_target ) ) {
			$zip->close();
			throw new ApiException(
				__( 'Must-use plugins cannot be updated through the dashboard.', 'peakurl' ),
				403
			);
		}

		// Symbolic links at the destination path are rejected as security violations.
		if ( is_link( $target_dest ) ) {
			$zip->close();
			throw new ApiException(
				sprintf(
					// translators: %s: Plugin identifier.
					__( 'A plugin with identifier "%s" is already installed.', 'peakurl' ),
					$candidate_id
				),
				409
			);
		}

		// Ensure content/plugins directory exists.
		if ( ! is_dir( $plugins_parent ) ) {
			mkdir( $plugins_parent, 0755, true );
		}

		$lock_file   = $plugins_parent . '/.lock_' . $candidate_id;
		$lock_handle = @fopen( $lock_file, 'c+' );
		if ( false === $lock_handle || ! flock( $lock_handle, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock_handle ) ) {
				fclose( $lock_handle );
			}
			$zip->close();
			throw new ApiException(
				sprintf(
					/* translators: %s: Plugin identifier */
					__( 'An installation or update is already in progress for plugin \'%s\'.', 'peakurl' ),
					$candidate_id
				),
				409
			);
		}

		$temp_stage       = null;
		$backup_dest      = null;
		$backup_preserved = false;
		$moved            = false;

		try {
			$is_update        = is_dir( $target_dest ) || file_exists( $target_dest );
			$existing_version = null;
			$was_active       = false;

			if ( $is_update ) {
				$existing_registry = new Registry( $content_dir );
				$existing_meta     = $existing_registry->find( $candidate_id, Type::REGULAR );

				if ( null === $existing_meta || ! $existing_meta->is_valid() ) {
					$zip->close();
					throw new ApiException(
						sprintf(
							// translators: %s: Plugin identifier.
							__( 'A plugin with identifier "%s" is already installed.', 'peakurl' ),
							$candidate_id
						),
						409
					);
				}

				$existing_version = $existing_meta->version();
				$new_version      = (string) ( $decoded['version'] ?? '' );

				$cmp = $validator->compare_versions( $new_version, $existing_version );

				if ( $cmp < 0 ) {
					$zip->close();
					throw new ApiException(
						sprintf(
							// translators: 1: Existing version, 2: Uploaded version.
							__( 'Cannot downgrade plugin from version %1$s to %2$s.', 'peakurl' ),
							$existing_version,
							$new_version
						),
						400
					);
				}

				if ( 0 === $cmp ) {
					$zip->close();
					throw new ApiException(
						sprintf(
							// translators: %s: Installed plugin version.
							__( 'Plugin version %s is already installed.', 'peakurl' ),
							$existing_version
						),
						409
					);
				}

				$active_plugins = $this->get_active_plugin_ids();
				$was_active     = in_array( $candidate_id, $active_plugins, true );
			}

			// Stage extracted files on the EXACT SAME filesystem as the destination.
			$stage_token = bin2hex( random_bytes( 8 ) );
			$temp_stage  = $plugins_parent . '/.tmp_install_' . $candidate_id . '_' . $stage_token;
			if ( ! mkdir( $temp_stage, 0755, true ) ) {
				$zip->close();
				throw new ApiException(
					__( 'Could not allocate staging directory on plugins filesystem.', 'peakurl' ),
					500
				);
			}

			if ( ! $zip->extractTo( $temp_stage ) ) {
				throw new ApiException(
					__( 'Failed to extract plugin archive.', 'peakurl' ),
					500
				);
			}
			$zip->close();

			$source_dir = null !== $root_folder ? $temp_stage . '/' . $root_folder : $temp_stage;
			if ( ! is_dir( $source_dir ) ) {
				throw new ApiException(
					__( 'Extracted plugin directory could not be located.', 'peakurl' ),
					500
				);
			}

			// Verify on-disk files before final rename.
			if ( $this->contains_symlink( $source_dir ) ) {
				throw new ApiException(
					__( 'Extracted package contains forbidden symbolic links.', 'peakurl' ),
					400
				);
			}

			$disk_manifest_file = $source_dir . '/plugin.json';
			$disk_entry_file    = $source_dir . '/plugin.php';
			if (
				! is_file( $disk_manifest_file ) ||
				is_link( $disk_manifest_file ) ||
				! is_file( $disk_entry_file ) ||
				is_link( $disk_entry_file )
			) {
				throw new ApiException(
					__( 'Package files could not be verified on disk before installation.', 'peakurl' ),
					400
				);
			}

			// For updates, safely stage existing plugin directory as a backup on the same filesystem.
			if ( $is_update && is_dir( $target_dest ) ) {
				$backup_dest = $plugins_parent . '/.backup_' . $candidate_id . '_' . $stage_token;
				if ( ! @rename( $target_dest, $backup_dest ) ) {
					throw new ApiException(
						__( 'Failed to stage existing plugin backup before update.', 'peakurl' ),
						500
					);
				}
			} elseif ( ! $is_update && ( is_link( $target_dest ) || file_exists( $target_dest ) || is_dir( $target_dest ) ) ) {
				// Re-check target destination collisions immediately before rename (concurrency defense for new installs).
				throw new ApiException(
					sprintf(
						// translators: %s: Plugin identifier.
						__( 'A plugin with identifier "%s" is already installed.', 'peakurl' ),
						$candidate_id
					),
					409
				);
			}

			// Atomic move on the same filesystem.
			if ( ! @rename( $source_dir, $target_dest ) ) {
				throw new ApiException(
					__( 'Failed to move extracted plugin to content/plugins directory.', 'peakurl' ),
					500
				);
			}
			$moved = true;

			// Normalize permissions on installed directory.
			$this->chmod_recursive( $target_dest, 0755, 0644 );

			// Post-move verification with Registry without executing PHP.
			$registry = new Registry( $content_dir );
			$meta     = $registry->find( $candidate_id, Type::REGULAR );
			if ( null === $meta || ! $meta->is_valid() || ! $meta->is_compatible() ) {
				$err = $meta ? implode( '; ', $meta->errors() ) : 'Discovery verification failed';
				throw new \RuntimeException( $err );
			}

			// Clean up backup directory after verified successful upgrade.
			if ( null !== $backup_dest && is_dir( $backup_dest ) ) {
				$this->safe_recursive_delete( $backup_dest );
				$backup_dest = null;
			}
		} catch ( \Throwable $e ) {
			if ( $moved && ( is_dir( $target_dest ) || is_link( $target_dest ) ) ) {
				$this->safe_recursive_delete( $target_dest );
			}
			$restoration_failed = false;

			// Restore backup if update failed.
			if ( null !== $backup_dest && is_dir( $backup_dest ) ) {
				if ( ! file_exists( $target_dest ) && ! is_dir( $target_dest ) && ! is_link( $target_dest ) ) {
					$restored = @rename( $backup_dest, $target_dest );
					if ( ! $restored ) {
						$backup_preserved   = true;
						$restoration_failed = true;
					} else {
						$backup_dest = null;
					}
				} else {
					$backup_preserved   = true;
					$restoration_failed = true;
				}
			}

			if ( $restoration_failed ) {
				error_log(
					sprintf(
						'PeakURL Plugin Upgrade Rollback Failed for "%s": %s. Preserved backup at: %s',
						$candidate_id,
						$e->getMessage(),
						(string) $backup_dest
					)
				);

				throw new ApiException(
					__( 'Plugin update failed and automatic restoration could not be completed. The previous working plugin package has been preserved on the server. Please contact your system administrator.', 'peakurl' ),
					500
				);
			}

			if ( $e instanceof ApiException ) {
				throw $e;
			}
			throw new ApiException(
				sprintf(
					// translators: %s: Failure reason.
					__( 'Plugin installation failed verification: %s', 'peakurl' ),
					$e->getMessage()
				),
				500
			);
		} finally {
			if ( null !== $temp_stage && is_dir( $temp_stage ) ) {
				$this->safe_recursive_delete( $temp_stage );
			}
			if ( is_resource( $lock_handle ) ) {
				flock( $lock_handle, LOCK_UN );
				fclose( $lock_handle );
			}
		}

		$payload                    = $meta->with_active( $was_active )->to_array();
		$payload['updated']         = $is_update;
		$payload['previousVersion'] = $existing_version;

		return $payload;
	}

	/**
	 * Determine whether a plugin is currently active or a must-use plugin.
	 *
	 * @param string $slug Plugin identifier.
	 * @return bool True if active or must-use, false otherwise.
	 * @since 1.8.0
	 */
	public function is_plugin_active( string $slug ): bool {
		$content_dir = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );
		$mu_target   = $content_dir . '/mu-plugins/' . $slug;
		if ( is_dir( $mu_target ) ) {
			return true;
		}

		$active_ids = $this->get_active_plugin_ids();

		return in_array( $slug, $active_ids, true );
	}

	/**
	 * Retrieve raw stored settings for a plugin.
	 *
	 * @param string $slug           Plugin identifier.
	 * @param bool   $throw_on_error Whether to throw when settings storage fails.
	 * @return array<string, mixed> Stored setting values.
	 * @throws ApiException When storage fails and $throw_on_error is true.
	 * @since 1.8.0
	 */
	public function get_plugin_settings_data( string $slug, bool $throw_on_error = false ): array {
		if ( ! isset( $this->settings_api ) || null === $this->settings_api ) {
			if ( $throw_on_error ) {
				throw new ApiException( __( 'Settings storage is unavailable.', 'peakurl' ), 500 );
			}
			return array();
		}

		try {
			$raw = $this->settings_api->get_option( 'plugin_' . $slug . '_settings' );
		} catch ( \Throwable $e ) {
			if ( $throw_on_error ) {
				throw new ApiException(
					sprintf(
						/* translators: %s: Failure message */
						__( 'Failed to retrieve plugin settings from storage: %s', 'peakurl' ),
						$e->getMessage()
					),
					500
				);
			}
			return array();
		}

		if ( null === $raw || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			if ( $throw_on_error ) {
				throw new ApiException( __( 'Stored plugin settings could not be decoded.', 'peakurl' ), 500 );
			}
			return array();
		}

		return $decoded;
	}

	/**
	 * Retrieve registered pages and UI extensions visible to the current authenticated user.
	 *
	 * Filters descriptors so inactive plugins and unauthorized capabilities are never exposed.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> Filtered pages and UI extensions.
	 * @since 1.8.0
	 */
	public function get_plugin_extensions( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );

		$content_dir = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );
		$registry    = new Registry( $content_dir );
		$active_ids  = $this->get_active_plugin_ids();
		$mu_plugins  = array_keys( $registry->must_use() );

		$is_active = static function ( string $slug ) use ( $active_ids, $mu_plugins ): bool {
			return in_array( $slug, $active_ids, true ) || in_array( $slug, $mu_plugins, true );
		};

		$extensions = Extensions::get_instance();
		$pages      = array();

		foreach ( $extensions->get_pages() as $page ) {
			if ( ! $is_active( $page['plugin_slug'] ) ) {
				continue;
			}

			if ( ! $this->authorization->has_capability( $user, $page['capability'] ) ) {
				continue;
			}

			$assets = $extensions->get_assets( $page['plugin_slug'], 'page', $page['page_id'] );

			$pages[] = array(
				'pluginSlug' => $page['plugin_slug'],
				'pageId'     => $page['page_id'],
				'title'      => $page['title'],
				'menuTitle'  => $page['menu_title'],
				'capability' => $page['capability'],
				'icon'       => $page['icon'],
				'order'      => $page['order'],
				'sections'   => $page['sections'],
				'actions'    => $page['actions'],
				'assets'     => $assets,
			);
		}

		// Sort pages by order asc, title asc.
		usort(
			$pages,
			static function ( array $a, array $b ): int {
				if ( $a['order'] === $b['order'] ) {
					return strcmp( $a['title'], $b['title'] );
				}
				return $a['order'] <=> $b['order'];
			}
		);

		$ui_extensions = array();
		foreach ( $extensions->get_ui_extensions() as $ext ) {
			if ( ! $is_active( $ext['plugin_slug'] ) ) {
				continue;
			}

			if ( ! $this->authorization->has_capability( $user, $ext['capability'] ) ) {
				continue;
			}

			$assets = $extensions->get_assets( $ext['plugin_slug'], 'links_overview' );

			$ui_extensions[] = array(
				'id'         => $ext['id'],
				'pluginSlug' => $ext['plugin_slug'],
				'location'   => $ext['location'],
				'label'      => $ext['label'],
				'actionId'   => $ext['action_id'],
				'capability' => $ext['capability'],
				'icon'       => $ext['icon'],
				'confirm'    => $ext['confirm'],
				'assets'     => $assets,
			);
		}

		return array(
			'pages'        => $pages,
			'uiExtensions' => $ui_extensions,
		);
	}

	/**
	 * Retrieve a specific plugin admin page descriptor populated with current setting values.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> Page descriptor and current values.
	 * @throws ApiException When plugin is inactive, page not found, or user lacks capability.
	 * @since 1.8.0
	 */
	public function get_plugin_page( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );

		$slug    = (string) $request->get_route_param( 'slug' );
		$page_id = (string) $request->get_route_param( 'pageId' );

		if ( ! $this->is_plugin_active( $slug ) ) {
			throw new ApiException( __( 'Plugin is not active.', 'peakurl' ), 404 );
		}

		$page = Extensions::get_instance()->get_page( $slug, $page_id );
		if ( null === $page ) {
			throw new ApiException( __( 'Plugin page not found.', 'peakurl' ), 404 );
		}

		if ( ! $this->authorization->has_capability( $user, $page['capability'] ) ) {
			throw new ApiException( __( 'You do not have permission to access this page.', 'peakurl' ), 403 );
		}

		$stored_values = $this->get_plugin_settings_data( $slug );
		$defaults      = array();

		// Merge defaults from schema fields.
		$schema = Extensions::get_instance()->get_settings_schema( $slug );
		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			foreach ( $schema['fields'] as $f_key => $f_def ) {
				if ( is_array( $f_def ) && array_key_exists( 'default', $f_def ) ) {
					$defaults[ (string) $f_key ] = $f_def['default'];
				}
			}
		}

		// Merge defaults from page section fields.
		if ( isset( $page['sections'] ) && is_array( $page['sections'] ) ) {
			foreach ( $page['sections'] as $section ) {
				if ( is_array( $section ) && isset( $section['fields'] ) && is_array( $section['fields'] ) ) {
					foreach ( $section['fields'] as $field ) {
						if ( is_array( $field ) && isset( $field['key'] ) && array_key_exists( 'default', $field ) ) {
							if ( ! array_key_exists( (string) $field['key'], $defaults ) ) {
								$defaults[ (string) $field['key'] ] = $field['default'];
							}
						}
					}
				}
			}
		}

		$values = array_merge( $defaults, $stored_values );
		$assets = Extensions::get_instance()->get_assets( $slug, 'page', $page_id );

		return array(
			'pluginSlug' => $slug,
			'pageId'     => $page_id,
			'title'      => $page['title'],
			'menuTitle'  => $page['menu_title'],
			'capability' => $page['capability'],
			'icon'       => $page['icon'],
			'order'      => $page['order'],
			'sections'   => $page['sections'],
			'actions'    => $page['actions'],
			'values'     => $values,
			'assets'     => $assets,
		);
	}

	/**
	 * Retrieve stored settings and schema for a plugin.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> Settings values and schema.
	 * @throws ApiException When plugin is inactive or user lacks capability.
	 * @since 1.8.0
	 */
	public function get_plugin_settings( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );

		$slug   = (string) $request->get_route_param( 'slug' );
		$schema = Extensions::get_instance()->get_settings_schema( $slug );
		$cap    = (string) ( $schema['capability'] ?? 'manage_site_settings' );

		if ( ! $this->authorization->has_capability( $user, $cap ) ) {
			throw new ApiException( __( 'You do not have permission to view these settings.', 'peakurl' ), 403 );
		}

		if ( ! $this->is_plugin_active( $slug ) ) {
			throw new ApiException( __( 'Plugin is not active.', 'peakurl' ), 404 );
		}

		$settings = $this->get_plugin_settings_data( $slug );

		return array(
			'pluginSlug' => $slug,
			'settings'   => $settings,
			'schema'     => $schema,
		);
	}

	/**
	 * Update stored settings for a plugin.
	 *
	 * Enforces the registered schema fields, types, and allowed options.
	 * Updates settings atomically using row-level locking to prevent race conditions.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> Updated settings payload.
	 * @throws ApiException When plugin is inactive, storage unavailable, validation fails, or user lacks capability.
	 * @since 1.8.0
	 */
	public function update_plugin_settings( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );

		$slug   = (string) $request->get_route_param( 'slug' );
		$schema = Extensions::get_instance()->get_settings_schema( $slug );
		$cap    = (string) ( $schema['capability'] ?? 'manage_site_settings' );

		if ( ! $this->authorization->has_capability( $user, $cap ) ) {
			throw new ApiException( __( 'You do not have permission to update these settings.', 'peakurl' ), 403 );
		}

		if ( ! $this->is_plugin_active( $slug ) ) {
			throw new ApiException( __( 'Plugin is not active.', 'peakurl' ), 404 );
		}

		if ( null === $schema ) {
			throw new ApiException( __( 'Plugin has not registered a settings schema.', 'peakurl' ), 400 );
		}

		if ( ! isset( $this->settings_api ) || null === $this->settings_api ) {
			throw new ApiException( __( 'Settings storage is unavailable.', 'peakurl' ), 500 );
		}

		$input = $request->get_body_param( 'settings' ) ?? $request->get_body_params();
		if ( ! is_array( $input ) ) {
			throw new ApiException( __( 'Invalid settings payload.', 'peakurl' ), 400 );
		}

		// Map registered schema fields.
		$fields_def = array();
		foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$fields_def[ (string) $field['key'] ] = $field;
			}
		}

		// Validate submitted input strictly against the schema.
		$validated_input = array();
		foreach ( $input as $key => $value ) {
			$field_key = (string) $key;
			if ( ! isset( $fields_def[ $field_key ] ) ) {
				throw new ApiException(
					sprintf(
						/* translators: %s: Unrecognized setting key */
						__( 'Setting key \'%s\' is not permitted by the registered schema.', 'peakurl' ),
						$field_key
					),
					400
				);
			}

			$field_def  = $fields_def[ $field_key ];
			$field_type = (string) ( $field_def['type'] ?? 'string' );

			switch ( $field_type ) {
				case 'boolean':
					if ( ! is_bool( $value ) ) {
						throw new ApiException(
							sprintf(
								/* translators: %s: Setting key */
								__( 'Setting \'%s\' must be a boolean.', 'peakurl' ),
								$field_key
							),
							400
						);
					}
					$validated_input[ $field_key ] = $value;
					break;

				case 'integer':
					if ( ! is_int( $value ) && ! ( is_numeric( $value ) && ( (int) $value ) === ( $value + 0 ) && ! is_float( $value + 0 ) ) ) {
						throw new ApiException(
							sprintf(
								/* translators: %s: Setting key */
								__( 'Setting \'%s\' must be an integer.', 'peakurl' ),
								$field_key
							),
							400
						);
					}
					$validated_input[ $field_key ] = (int) $value;
					break;

				case 'number':
					if ( ! is_numeric( $value ) ) {
						throw new ApiException(
							sprintf(
								/* translators: %s: Setting key */
								__( 'Setting \'%s\' must be numeric.', 'peakurl' ),
								$field_key
							),
							400
						);
					}
					$validated_input[ $field_key ] = is_float( $value + 0 ) ? (float) $value : (int) $value;
					break;

				case 'select':
					if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
						throw new ApiException(
							sprintf(
								/* translators: %s: Setting key */
								__( 'Setting \'%s\' must be a string.', 'peakurl' ),
								$field_key
							),
							400
						);
					}
					$selected_value = (string) $value;
					$allowed_opts   = array();
					foreach ( (array) ( $field_def['options'] ?? array() ) as $opt ) {
						if ( is_array( $opt ) && isset( $opt['value'] ) ) {
							$allowed_opts[] = (string) $opt['value'];
						} elseif ( is_scalar( $opt ) ) {
							$allowed_opts[] = (string) $opt;
						}
					}
					if ( ! empty( $allowed_opts ) && ! in_array( $selected_value, $allowed_opts, true ) ) {
						throw new ApiException(
							sprintf(
								/* translators: %s: Setting key */
								__( 'Selected option for setting \'%s\' is not allowed.', 'peakurl' ),
								$field_key
							),
							400
						);
					}
					$validated_input[ $field_key ] = $selected_value;
					break;

				case 'string':
				default:
					if ( ! is_string( $value ) ) {
						throw new ApiException(
							sprintf(
								/* translators: %s: Setting key */
								__( 'Setting \'%s\' must be a string.', 'peakurl' ),
								$field_key
							),
							400
						);
					}
					// Reject control characters.
					if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
						throw new ApiException(
							sprintf(
								/* translators: %s: Setting key */
								__( 'Setting \'%s\' contains forbidden control characters.', 'peakurl' ),
								$field_key
							),
							400
						);
					}
					$validated_input[ $field_key ] = $value;
					break;
			}
		}

		$key               = 'plugin_' . $slug . '_settings';
		$merged_settings   = array();
		$existing_settings = array();

		try {
			$this->settings_api->update_option_with_lock(
				$key,
				function ( ?string $current_raw ) use ( $validated_input, &$merged_settings, &$existing_settings ): string {
					$existing = array();
					if ( null !== $current_raw && '' !== trim( $current_raw ) ) {
						$decoded = json_decode( $current_raw, true );
						if ( ! is_array( $decoded ) ) {
							throw new \RuntimeException( 'Stored plugin settings could not be decoded.' );
						}
						$existing = $decoded;
					}
					$existing_settings = $existing;
					$merged            = array_merge( $existing, $validated_input );
					$merged_settings   = $merged;

					$encoded = json_encode( $merged );
					if ( false === $encoded ) {
						throw new \RuntimeException( 'Settings could not be encoded.' );
					}

					return $encoded;
				}
			);
		} catch ( \Throwable $e ) {
			throw new ApiException(
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to update plugin settings: %s', 'peakurl' ),
					$e->getMessage()
				),
				500
			);
		}

		if ( function_exists( 'do_action' ) ) {
			\do_action( 'plugin_settings_updated', $slug, $merged_settings, $existing_settings );
		}

		return array(
			'pluginSlug' => $slug,
			'settings'   => $merged_settings,
		);
	}

	/**
	 * Dispatch an authorized plugin backend action callback.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> Action execution response.
	 * @throws ApiException When plugin is inactive, action not found, or user lacks capability.
	 * @since 1.8.0
	 */
	public function dispatch_plugin_action( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );

		$slug      = (string) $request->get_route_param( 'slug' );
		$action_id = (string) $request->get_route_param( 'actionId' );

		if ( ! $this->is_plugin_active( $slug ) ) {
			throw new ApiException( __( 'Plugin is not active.', 'peakurl' ), 404 );
		}

		$action = Extensions::get_instance()->get_action( $slug, $action_id );
		if ( null === $action ) {
			throw new ApiException( __( 'Plugin action not found.', 'peakurl' ), 404 );
		}

		if ( ! $this->authorization->has_capability( $user, $action['capability'] ) ) {
			throw new ApiException( __( 'You do not have permission to execute this action.', 'peakurl' ), 403 );
		}

		$result = call_user_func(
			$action['handler'],
			$request,
			array(
				'user'        => $user,
				'plugin_slug' => $slug,
				'action_id'   => $action_id,
			)
		);

		return is_array( $result ) ? $result : array( 'result' => $result );
	}

	/**
	 * Serve an approved, scoped browser asset registered by an active plugin.
	 *
	 * Browser assets are authenticated administrative resources. Rejects traversal,
	 * unregistered assets, source maps, PHP, and sensitive file types.
	 *
	 * @param Request $request Incoming request.
	 * @return array<string, mixed> Structured response with MIME type, ETag, and body.
	 * @throws ApiException When asset is invalid, forbidden, unauthenticated, or not found.
	 * @since 1.8.0
	 */
	public function serve_plugin_asset( Request $request ): array {
		// Plugin browser assets are authenticated administrative resources.
		$user = $this->auth_service->get_current_user( $request );
		if ( null === $user ) {
			throw new ApiException( __( 'Authentication is required to access plugin assets.', 'peakurl' ), 401 );
		}

		$slug       = (string) $request->get_route_param( 'slug' );
		$asset_path = (string) $request->get_route_param( 'assetPath' );

		if ( '' === $slug || ! preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
			throw new ApiException( __( 'Invalid plugin identifier.', 'peakurl' ), 400 );
		}

		if ( ! $this->authorization->has_capability( $user, 'access_dashboard' ) ) {
			throw new ApiException( __( 'You do not have permission to access plugin assets.', 'peakurl' ), 403 );
		}

		// Inactive plugins cannot serve assets.
		if ( ! $this->is_plugin_active( $slug ) ) {
			throw new ApiException( __( 'Plugin is not active.', 'peakurl' ), 404 );
		}

		// Strictly reject directory traversal, backslashes, absolute paths, or hidden files without normalization.
		if (
			str_contains( $asset_path, '\\' ) ||
			str_starts_with( $asset_path, '/' ) ||
			str_contains( $asset_path, '..' ) ||
			str_starts_with( $asset_path, '.' ) ||
			str_contains( $asset_path, '/.' )
		) {
			throw new ApiException( __( 'Invalid asset path.', 'peakurl' ), 400 );
		}

		if ( str_starts_with( $asset_path, 'assets/' ) ) {
			$clean_path = substr( $asset_path, 7 );
		} else {
			$clean_path = $asset_path;
		}

		if ( '' === $clean_path ) {
			throw new ApiException( __( 'Asset path cannot be empty.', 'peakurl' ), 400 );
		}

		// Verify asset is explicitly registered by the plugin.
		$registered_assets = Extensions::get_instance()->get_assets( $slug );
		$is_registered     = false;
		foreach ( $registered_assets as $asset ) {
			if ( $asset['file'] === $clean_path ) {
				$is_registered = true;
				break;
			}
		}

		if ( ! $is_registered ) {
			throw new ApiException( __( 'Asset is not registered for this plugin.', 'peakurl' ), 404 );
		}

		// Strictly disallow PHP, configuration, source maps, and other sensitive files.
		$ext = strtolower( pathinfo( $clean_path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'php', 'phtml', 'phar', 'json', 'env', 'config', 'lock', 'sql', 'sh', 'map', 'ts', 'tsx', 'md' ), true ) ) {
			throw new ApiException( __( 'Access to executable, configuration, and source map files is forbidden.', 'peakurl' ), 403 );
		}

		$content_dir = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );
		$plugin_dir  = $content_dir . '/plugins/' . $slug;
		if ( ! is_dir( $plugin_dir ) ) {
			$plugin_dir = $content_dir . '/mu-plugins/' . $slug;
		}

		if ( ! is_dir( $plugin_dir ) ) {
			throw new ApiException( __( 'Plugin directory not found.', 'peakurl' ), 404 );
		}

		$assets_dir = realpath( $plugin_dir . '/assets' );
		if ( false === $assets_dir || ! is_dir( $assets_dir ) ) {
			throw new ApiException( __( 'Plugin has no approved assets directory.', 'peakurl' ), 404 );
		}

		$target_file = realpath( $assets_dir . '/' . $clean_path );
		if (
			false === $target_file ||
			! is_file( $target_file ) ||
			is_link( $target_file ) ||
			! is_readable( $target_file ) ||
			! str_starts_with( $target_file, $assets_dir . DIRECTORY_SEPARATOR )
		) {
			throw new ApiException( __( 'Asset file not found.', 'peakurl' ), 404 );
		}

		$mime_types = array(
			'js'    => 'application/javascript; charset=utf-8',
			'mjs'   => 'application/javascript; charset=utf-8',
			'css'   => 'text/css; charset=utf-8',
			'svg'   => 'image/svg+xml',
			'png'   => 'image/png',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'webp'  => 'image/webp',
			'gif'   => 'image/gif',
			'ico'   => 'image/x-icon',
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
		);

		$content_type = $mime_types[ $ext ] ?? null;
		if ( null === $content_type ) {
			throw new ApiException( __( 'Unsupported asset MIME type.', 'peakurl' ), 403 );
		}

		$contents = file_get_contents( $target_file );
		if ( false === $contents ) {
			throw new ApiException( __( 'Failed to read asset file.', 'peakurl' ), 500 );
		}

		$etag          = '"' . md5( $contents ) . '"';
		$if_none_match = $request->get_header( 'If-None-Match' );
		if ( null !== $if_none_match && trim( $if_none_match ) === $etag ) {
			return array(
				'status'  => 304,
				'headers' => array(
					'ETag'          => $etag,
					'Cache-Control' => 'private, no-transform, max-age=3600',
				),
				'body'    => '',
			);
		}

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'   => $content_type,
				'ETag'           => $etag,
				'Cache-Control'  => 'private, no-transform, max-age=3600',
				'Content-Length' => (string) strlen( $contents ),
			),
			'body'    => $contents,
		);
	}

	/**
	 * Recursively detect whether a directory contains any symbolic links.
	 *
	 * @param string $dir Path to inspect.
	 * @return bool True if any symlink is detected, false otherwise.
	 */
	private function contains_symlink( string $dir ): bool {
		if ( is_link( $dir ) ) {
			return true;
		}
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $path ) ) {
				return true;
			}
			if ( is_dir( $path ) && $this->contains_symlink( $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively set directory and file permissions.
	 *
	 * @param string $path Target root directory.
	 * @param int    $dir_mode Directory permission octal.
	 * @param int    $file_mode File permission octal.
	 * @return void
	 */
	private function chmod_recursive( string $path, int $dir_mode, int $file_mode ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort permissions fixup during install.
		@chmod( $path, $dir_mode );

		$entries = scandir( $path );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full_path = $path . '/' . $entry;
			if ( is_dir( $full_path ) ) {
				$this->chmod_recursive( $full_path, $dir_mode, $file_mode );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort permissions fixup during install.
				@chmod( $full_path, $file_mode );
			}
		}
	}

	/**
	 * Safely delete a directory and its contents without traversing symlinks.
	 *
	 * @param string $dir Target directory path.
	 * @return void
	 */
	private function safe_recursive_delete( string $dir ): void {
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

			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $path ) ) {
				@unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->safe_recursive_delete( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}



	/**
	 * Get the shared dashboard notice context for the current user.
	 *
	 * @param array<string, mixed> $user Current authenticated user row.
	 * @return array<string, mixed> Notice context.
	 * @since 1.0.0
	 */
	public function get_admin_notice_context( array $user ): array {
		$capabilities = array(
			'manageUpdates' => $this->roles->has_capability( $user, 'manage_updates' ),
		);
		$context      = array(
			'user'         => $user,
			'capabilities' => $capabilities,
		);

		if ( ! empty( $capabilities['manageUpdates'] ) ) {
			$context['updateStatus'] = $this->load_update_status( false );
		}

		return $context;
	}

	/**
	 * Return the cached update status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Current/latest version and availability flag.
	 * @since 1.0.0
	 */
	public function get_update_status( Request $request ): array {
		$this->get_update_user( $request );

		return $this->load_update_status( false );
	}

	/**
	 * Refresh the remote update manifest status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Updated status after the remote refresh.
	 * @since 1.0.0
	 */
	public function refresh_update_status( Request $request ): array {
		$this->get_update_user( $request );

		return $this->load_update_status( true );
	}

	/**
	 * Download and apply the latest release archive.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Result of the update operation.
	 *
	 * @throws ApiException When run from a source checkout or no update is available.
	 * @since 1.0.0
	 */
	public function apply_update( Request $request ): array {
		$this->get_update_user( $request );

		$status = $this->load_update_status( true );

		if ( empty( $status['updateAvailable'] ) ) {
			throw new ApiException( __( 'PeakURL is already up to date.', 'peakurl' ), 422 );
		}

		return $this->install_release( $status );
	}

	/**
	 * Reinstall the currently installed release package.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Result of the reinstall operation.
	 *
	 * @throws ApiException When the current release cannot be reinstalled.
	 * @since 1.0.0
	 */
	public function reinstall_update( Request $request ): array {
		$this->get_update_user( $request );

		$status = $this->load_update_status( true );

		if ( ! empty( $status['updateAvailable'] ) ) {
			throw new ApiException(
				__( 'A newer PeakURL release is available. Install the update instead.', 'peakurl' ),
				422,
			);
		}

		if ( empty( $status['reinstallAvailable'] ) ) {
			throw new ApiException(
				__( 'PeakURL cannot reinstall the latest release right now.', 'peakurl' ),
				422,
			);
		}

		return $this->install_release( $status, true );
	}

	/**
	 * Run the managed database upgrade / repair flow on demand.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Database upgrade status.
	 *
	 * @throws ApiException When the upgrade fails.
	 * @since 1.0.0
	 */
	public function upgrade_database_schema( Request $request ): array {
		$this->get_update_user( $request );

		try {
			$service = $this->schema_service;
			$status  = $service->inspect();

			if ( empty( $status['upgradeRequired'] ) ) {
				return $status;
			}

			$result = $service->upgrade();

			$version = (string) ( $this->config[ Constants::VERSION ] ?? '' );
			if ( '' !== $version && $service->is_current() ) {
				$this->settings_api->update_option(
					'installed_version',
					$version,
					Date::now(),
					false,
				);
			}

			return $result;
		} catch ( \Throwable $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PeakURL database schema upgrade failed: ' . $exception->getMessage() );

			throw new ApiException(
				__( 'PeakURL could not complete the database schema upgrade. Verify that the database user has permission to create and alter tables, check the server logs, and retry.', 'peakurl' ),
				500,
			);
		}
	}

	/**
	 * Load (and optionally refresh) the cached update status.
	 *
	 * @param bool $force_check Whether to bypass the cache TTL and fetch fresh.
	 * @return array<string, mixed> Compiled update status payload.
	 * @since 1.0.0
	 */
	private function load_update_status( bool $force_check ): array {
		$settings_api    = $this->settings_api;
		$update_service  = $this->get_update_manager();
		$manifest_url    = $update_service->get_manifest_url();
		$last_checked    = $settings_api->get_option( 'update_last_checked_at' );
		$last_error      = $settings_api->get_option( 'update_last_error' );
		$cached_manifest = $this->decode_update_manifest(
			$settings_api->get_option( 'update_last_result_json' ),
		);

		$settings_api->update_option( 'update_manifest_url', $manifest_url, Date::now(), false );

		if (
			$force_check ||
			empty( $cached_manifest ) ||
			! $update_service->is_cache_fresh( $last_checked )
		) {
			try {
				$cached_manifest = $update_service->fetch_manifest();
				$last_checked    = Date::now();
				$last_error      = null;
				$settings_api->update_option(
					'update_last_result_json',
					peakurl_json_encode( $cached_manifest ),
					Date::now(),
					false,
				);
				$settings_api->update_option(
					'update_last_checked_at',
					$last_checked,
					Date::now(),
					false,
				);
				$settings_api->delete_options( array( 'update_last_error' ) );
			} catch ( \Throwable $exception ) {
				$last_checked = Date::now();
				$last_error   = $exception->getMessage();
				$settings_api->update_option(
					'update_last_checked_at',
					$last_checked,
					Date::now(),
					false,
				);
				$settings_api->update_option(
					'update_last_error',
					$last_error,
					Date::now(),
					false,
				);
			}
		}

		$status = $update_service->get_status(
			$cached_manifest,
			$last_checked,
			$last_error,
		);

		try {
			$status['database'] = $this->schema_service->inspect();
		} catch ( \Throwable $exception ) {
			$status['database'] = array(
				'currentVersion'  => 0,
				'targetVersion'   => Constants::DB_SCHEMA_VERSION,
				'compatible'      => false,
				'upgradeRequired' => true,
				'upToDate'        => false,
				'errorCount'      => 1,
				'warningCount'    => 0,
				'issues'          => array(
					array(
						'id'       => 'schema-inspection-failed',
						'severity' => 'error',
						'label'    => __( 'PeakURL could not inspect the database schema.', 'peakurl' ),
					),
				),
				'issuesCount'     => 1,
				'missingTables'   => array(),
				'lastUpgradedAt'  => null,
				'lastError'       => $exception->getMessage(),
				'upgraded'        => false,
				'changes'         => array(),
			);
		}

		return $status;
	}

	/**
	 * Apply a release package from the resolved update status payload.
	 *
	 * @param array<string, mixed> $status Resolved update status.
	 * @param bool                 $reinstall Whether the action is a reinstall.
	 * @return array<string, mixed> Result of the install operation.
	 *
	 * @throws ApiException On install error.
	 * @since 1.0.0
	 */
	private function install_release( array $status, bool $reinstall = false ): array {
		if ( empty( $status['canApply'] ) ) {
			throw new ApiException(
				(string) ( $status['applyDisabledReason'] ?? __( 'PeakURL cannot apply this release.', 'peakurl' ) ),
				422,
			);
		}

		$manifest = is_array( $status['manifest'] ?? null )
			? $status['manifest']
			: array();

		if ( empty( $manifest ) ) {
			throw new ApiException(
				__( 'PeakURL could not load the update manifest.', 'peakurl' ),
				502,
			);
		}

		$update_service         = $this->get_update_manager();
		$settings_api           = $this->settings_api;
		$previous_version_value = $settings_api->get_option( 'installed_version' );
		$had_installed_version  = null !== $previous_version_value;

		try {
			$result = $update_service->apply_update(
				$manifest,
				function ( string $version ) use ( $settings_api ): void {
					$this->schema_service->repair_schema();

					if ( ! $this->schema_service->is_current() ) {
						throw new \RuntimeException(
							__( 'PeakURL could not complete the database schema upgrade.', 'peakurl' ),
						);
					}

					$target_version = '' !== $version
						? $version
						: (string) ( $this->config[ Constants::VERSION ] ?? Constants::DEFAULT_VERSION );

					$settings_api->update_option(
						'installed_version',
						$target_version,
						Date::now(),
						false,
					);
				}
			);
		} catch ( \Throwable $exception ) {
			if ( $had_installed_version ) {
				if ( $settings_api->get_option( 'installed_version' ) !== $previous_version_value ) {
					$settings_api->update_option(
						'installed_version',
						(string) $previous_version_value,
						Date::now(),
						false,
					);
				}
			} else {
				$settings_api->delete_option( 'installed_version' );
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PeakURL update failed: ' . $exception->getMessage() );

			$settings_api->update_option( 'update_last_checked_at', Date::now(), Date::now(), false );
			$settings_api->update_option(
				'update_last_error',
				$exception->getMessage(),
				Date::now(),
				false,
			);

			throw new ApiException(
				__( 'PeakURL could not complete the release update. Check the server logs and retry.', 'peakurl' ),
				500,
			);
		}

		$installed_version = (string) (
			$result['version']
			?? $this->config[ Constants::VERSION ]
			?? Constants::DEFAULT_VERSION
		);
		$settings_api->update_option( 'update_last_applied_at', Date::now(), Date::now(), false );
		$settings_api->update_option( 'update_last_checked_at', Date::now(), Date::now(), false );
		$settings_api->update_option(
			'update_last_result_json',
			peakurl_json_encode( $manifest ),
			Date::now(),
			false,
		);
		$settings_api->delete_options( array( 'update_last_error' ) );

		return array(
			'applied'        => true,
			'reinstalled'    => $reinstall,
			'currentVersion' => $installed_version,
			'latestVersion'  => (string) ( $manifest['version'] ?? '' ),
			'packageUrl'     => (string) ( $result['packageUrl'] ?? '' ),
			'appliedAt'      => (string) ( $result['appliedAt'] ?? gmdate( DATE_ATOM ) ),
			'reloadRequired' => true,
		);
	}

	/**
	 * Get the update manager instance.
	 *
	 * @return UpdateManager
	 * @since 1.7.1
	 */
	protected function get_update_manager(): UpdateManager {
		return $this->update_manager ?? new UpdateManager( $this->config, $this->settings_api, $this->db );
	}

	/**
	 * Decode a cached update manifest JSON string.
	 *
	 * @param string|null $value Raw JSON from the settings table.
	 * @return array<string, mixed>|null Decoded manifest or null.
	 * @since 1.0.0
	 */
	private function decode_update_manifest( ?string $value ): ?array {
		if ( empty( $value ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Return the current background jobs scheduler status and registered jobs.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Cron status payload.
	 *
	 * @throws ApiException When scheduler is not configured.
	 * @since 1.7.0
	 */
	public function get_cron_status( Request $request ): array {
		$this->get_update_user( $request );

		if ( null === $this->scheduler ) {
			throw new ApiException(
				__( 'Scheduler service is not configured.', 'peakurl' ),
				500
			);
		}

		return $this->scheduler->get_status();
	}

	/**
	 * Trigger manual run-now execution of a registered background job.
	 *
	 * @param Request     $request Incoming HTTP request (admin-only).
	 * @param string|null $job_id  Optional job identifier from route parameter.
	 * @return array<string, mixed> Run result outcome.
	 *
	 * @throws ApiException When the job ID is missing, unknown, or execution fails.
	 * @since 1.7.0
	 */
	public function run_cron_job( Request $request, ?string $job_id = null ): array {
		$this->get_update_user( $request );

		if ( null === $this->scheduler ) {
			throw new ApiException(
				__( 'Scheduler service is not configured.', 'peakurl' ),
				500
			);
		}

		$target_id = $job_id;
		if ( null === $target_id || '' === trim( $target_id ) ) {
			$target_id = $request->get_route_param( 'id' );
		}

		if ( null === $target_id || '' === trim( (string) $target_id ) ) {
			$payload   = $request->json_data();
			$target_id = (string) ( $payload['job_id'] ?? $payload['id'] ?? '' );
		}

		$clean_id = trim( (string) $target_id );

		// If no specific job ID was requested, run all due background jobs.
		if ( '' === $clean_id ) {
			try {
				$results = $this->scheduler->run_due_jobs();

				return array(
					'run_all' => true,
					'results' => $results,
					'success' => true,
				);
			} catch ( \Throwable $exception ) {
				throw new ApiException( $exception->getMessage(), 500 );
			}
		}

		if ( ! $this->scheduler->get_registry()->has( $clean_id ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %s is the requested job ID. */
					__( 'Unknown background job identifier: %s', 'peakurl' ),
					$clean_id
				),
				404
			);
		}

		try {
			$result = $this->scheduler->run_job( $clean_id, true );

			return array(
				'job_id'  => $clean_id,
				'status'  => $result->get_status(),
				'summary' => $result->get_summary(),
				'error'   => $result->get_error(),
				'success' => $result->is_success(),
			);
		} catch ( \Throwable $exception ) {
			throw new ApiException( $exception->getMessage(), 500 );
		}
	}

	/**
	 * Update background job scheduler settings (e.g. retention policy).
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Updated settings payload.
	 *
	 * @throws ApiException When validation fails or user is unauthorized.
	 * @since 1.7.0
	 */
	public function update_cron_settings( Request $request ): array {
		$this->get_update_user( $request );

		if ( null === $this->scheduler ) {
			throw new ApiException(
				__( 'Scheduler service is not configured.', 'peakurl' ),
				500
			);
		}

		$payload = $request->json_data();
		if ( ! is_array( $payload ) || ! array_key_exists( 'retention_days', $payload ) || null === $payload['retention_days'] ) {
			throw new ApiException(
				__( 'Missing required field: retention_days.', 'peakurl' ),
				422
			);
		}

		$raw_retention = $payload['retention_days'];
		if ( ! is_numeric( $raw_retention ) || (int) $raw_retention < 0 || (float) (int) $raw_retention !== (float) $raw_retention ) {
			throw new ApiException(
				__( 'Retention days must be a non-negative integer.', 'peakurl' ),
				422
			);
		}

		$retention_days = (int) $raw_retention;
		$this->scheduler->set_retention_days( $retention_days );

		return array(
			'retention_days' => $retention_days,
			'success'        => true,
		);
	}

	/**
	 * Clear background job execution history.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Outcome with deleted_count.
	 *
	 * @throws ApiException When user is unauthorized or scheduler is not configured.
	 * @since 1.7.0
	 */
	public function clear_cron_history( Request $request ): array {
		$this->get_update_user( $request );

		if ( null === $this->scheduler ) {
			throw new ApiException(
				__( 'Scheduler service is not configured.', 'peakurl' ),
				500
			);
		}

		$job_id = $request->get_query_param( 'job_id' ) ?? $request->get_query_param( 'job_key' );
		if ( null === $job_id || '' === trim( (string) $job_id ) ) {
			$payload = $request->json_data();
			if ( is_array( $payload ) ) {
				if ( isset( $payload['job_id'] ) ) {
					$job_id = (string) $payload['job_id'];
				} elseif ( isset( $payload['job_key'] ) ) {
					$job_id = (string) $payload['job_key'];
				}
			}
		}

		$clean_job_id = ( null !== $job_id && '' !== trim( (string) $job_id ) ) ? trim( (string) $job_id ) : null;

		$deleted_count = $this->scheduler->clear_history( $clean_job_id );

		return array(
			'deleted_count' => $deleted_count,
			'job_id'        => $clean_job_id,
			'job_key'       => $clean_job_id,
			'success'       => true,
		);
	}

	/**
	 * Update schedule settings for a registered background job.
	 *
	 * @param Request     $request Incoming HTTP request (admin-only).
	 * @param string|null $job_id  Optional job identifier from route.
	 * @return array<string, mixed> Updated job payload.
	 *
	 * @throws ApiException When user is unauthorized or validation fails.
	 * @since 1.7.0
	 */
	public function update_cron_job( Request $request, ?string $job_id = null ): array {
		$this->get_update_user( $request );

		if ( null === $this->scheduler ) {
			throw new ApiException(
				__( 'Scheduler service is not configured.', 'peakurl' ),
				500
			);
		}

		$target_id = $job_id ?? $request->get_route_param( 'id' );
		if ( null === $target_id || '' === trim( (string) $target_id ) ) {
			$payload   = $request->json_data();
			$target_id = is_array( $payload ) ? (string) ( $payload['job_id'] ?? $payload['id'] ?? '' ) : '';
		}

		$clean_id = trim( (string) $target_id );
		if ( '' === $clean_id ) {
			throw new ApiException(
				__( 'Missing background job identifier.', 'peakurl' ),
				400
			);
		}

		if ( ! $this->scheduler->get_registry()->has( $clean_id ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %s is the requested job ID. */
					__( 'Unknown background job identifier: %s', 'peakurl' ),
					$clean_id
				),
				404
			);
		}

		$payload = $request->json_data();
		if ( ! is_array( $payload ) ) {
			throw new ApiException(
				__( 'Invalid request payload.', 'peakurl' ),
				400
			);
		}

		$params = array();

		$has_interval = array_key_exists( 'interval_seconds', $payload ) || array_key_exists( 'schedule_interval', $payload );
		if ( $has_interval ) {
			$raw_interval = $payload['interval_seconds'] ?? $payload['schedule_interval'];
			if ( ! is_numeric( $raw_interval ) || (int) $raw_interval < 300 || (float) (int) $raw_interval !== (float) $raw_interval ) {
				throw new ApiException(
					__( 'Recurrence interval must be an integer of at least 300 seconds (5 minutes).', 'peakurl' ),
					422
				);
			}
			$params['interval_seconds'] = (int) $raw_interval;
		}

		if ( array_key_exists( 'preferred_run_time', $payload ) ) {
			$raw_time = $payload['preferred_run_time'];
			if ( null !== $raw_time && '' !== trim( (string) $raw_time ) ) {
				$clean_time = trim( (string) $raw_time );
				if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $clean_time ) ) {
					throw new ApiException(
						__( 'Preferred time must be in 24-hour format (HH:MM).', 'peakurl' ),
						422
					);
				}
				$params['preferred_run_time'] = $clean_time;
			} else {
				$params['preferred_run_time'] = null;
			}
		}

		if ( array_key_exists( 'is_enabled', $payload ) ) {
			$params['is_enabled'] = (bool) $payload['is_enabled'];
		}

		if ( array_key_exists( 'retention_days', $payload ) ) {
			$raw_retention = $payload['retention_days'];
			if ( null !== $raw_retention ) {
				if ( ! is_numeric( $raw_retention ) || (int) $raw_retention < 0 || (float) (int) $raw_retention !== (float) $raw_retention ) {
					throw new ApiException(
						__( 'Retention days must be null or a non-negative integer.', 'peakurl' ),
						422
					);
				}
				$params['retention_days'] = (int) $raw_retention;
			} else {
				$params['retention_days'] = null;
			}
		}

		try {
			$job_status = $this->scheduler->update_job( $clean_id, $params );

			return array(
				'job'     => $job_status,
				'success' => true,
			);
		} catch ( \InvalidArgumentException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		} catch ( \Throwable $exception ) {
			throw new ApiException( $exception->getMessage(), 500 );
		}
	}

	/**
	 * Reset a background job schedule to its built-in recommended defaults.
	 *
	 * @param Request     $request Incoming HTTP request (admin-only).
	 * @param string|null $job_id  Optional job identifier from route.
	 * @return array<string, mixed> Reset job payload.
	 *
	 * @throws ApiException When user is unauthorized or job is not found.
	 * @since 1.7.0
	 */
	public function reset_cron_job( Request $request, ?string $job_id = null ): array {
		$this->get_update_user( $request );

		if ( null === $this->scheduler ) {
			throw new ApiException(
				__( 'Scheduler service is not configured.', 'peakurl' ),
				500
			);
		}

		$target_id = $job_id ?? $request->get_route_param( 'id' );
		if ( null === $target_id || '' === trim( (string) $target_id ) ) {
			$payload   = $request->json_data();
			$target_id = is_array( $payload ) ? (string) ( $payload['job_id'] ?? $payload['id'] ?? '' ) : '';
		}

		$clean_id = trim( (string) $target_id );
		if ( '' === $clean_id ) {
			throw new ApiException(
				__( 'Missing background job identifier.', 'peakurl' ),
				400
			);
		}

		if ( ! $this->scheduler->get_registry()->has( $clean_id ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %s is the requested job ID. */
					__( 'Unknown background job identifier: %s', 'peakurl' ),
					$clean_id
				),
				404
			);
		}

		try {
			$job_status = $this->scheduler->reset_job( $clean_id );

			return array(
				'job'     => $job_status,
				'success' => true,
			);
		} catch ( \InvalidArgumentException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		} catch ( \Throwable $exception ) {
			throw new ApiException( $exception->getMessage(), 500 );
		}
	}
}
