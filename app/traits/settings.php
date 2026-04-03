<?php
/**
 * Data store settings trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SettingsTrait — install and settings-table helpers for Store.
 *
 * @since 1.0.0
 */
trait SettingsTrait {

	/**
	 * Synchronise install-time configuration values into the settings table.
	 *
	 * @since 1.0.0
	 */
	private function sync_install_settings(): void {
		if ( ! $this->table_exists( 'settings' ) ) {
			return;
		}

		$site_name    = trim( (string) ( $this->config['PEAKURL_WORKSPACE_NAME'] ?? '' ) );
		$site_slug    = trim( (string) ( $this->config['PEAKURL_WORKSPACE_SLUG'] ?? '' ) );
		$site_url     = trim( (string) ( $this->config['SITE_URL'] ?? '' ) );
		$admin_email  = trim( (string) ( $this->config['PEAKURL_OWNER_EMAIL'] ?? '' ) );
		$version      = trim(
			(string) ( $this->config[ Constants::CONFIG_VERSION ] ?? '' ),
		);
		$manifest_url = trim(
			(string) ( $this->config[ Constants::CONFIG_UPDATE_MANIFEST_URL ] ?? '' ),
		);

		if ( '' !== $site_name ) {
			$this->upsert_setting( 'site_name', $site_name );
		}

		if ( '' !== $site_slug ) {
			$this->upsert_setting( 'site_slug', $site_slug );
		}

		if ( '' !== $site_url ) {
			$this->upsert_setting( 'site_url', $site_url );
		}

		if ( '' !== $admin_email ) {
			$this->upsert_setting( 'admin_email', $admin_email, false );
		}

		if ( '' !== $version ) {
			$this->upsert_setting( 'installed_version', $version, false );
		}

		if ( '' !== $manifest_url ) {
			$this->upsert_setting(
				'update_manifest_url',
				$manifest_url,
				false,
			);
		}

		$this->sync_runtime_managed_settings();

		if ( null === $this->get_setting_value( 'installed_at' ) ) {
			$this->upsert_setting( 'installed_at', $this->now(), false );
		}

		$this->delete_settings(
			array(
				'site_title',
				'workspace_name',
				'workspace_slug',
				'default_team_id',
			),
		);
	}

	/**
	 * Retrieve a single setting value by its key.
	 *
	 * @param string $setting_key Setting key to look up.
	 * @return string|null The stored value or null when missing.
	 * @since 1.0.0
	 */
	private function get_setting_value( string $setting_key ): ?string {
		return $this->settings_api->get_option( $setting_key );
	}

	/**
	 * Insert or update a setting row.
	 *
	 * @param string $setting_key   Setting key.
	 * @param string $setting_value Setting value to persist.
	 * @param bool   $autoload      Whether the setting should autoload.
	 * @since 1.0.0
	 */
	private function upsert_setting(
		string $setting_key,
		string $setting_value,
		bool $autoload = true
	): void {
		$this->settings_api->update_option(
			$setting_key,
			$setting_value,
			$this->now(),
			$autoload,
		);
	}

	/**
	 * Delete one or more settings.
	 *
	 * @param array<int, string> $setting_keys Setting keys to remove.
	 * @since 1.0.0
	 */
	private function delete_settings( array $setting_keys ): void {
		$this->settings_api->delete_options( $setting_keys );
	}

	/**
	 * Seed database-backed runtime settings with application defaults.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function sync_runtime_managed_settings(): void {
		$this->seed_setting_if_missing(
			'site_language',
			Constants::DEFAULT_LOCALE,
		);
		$this->seed_setting_if_missing(
			'mail_driver',
			'mail',
			false,
		);
		$this->seed_setting_if_missing(
			'mail_from_email',
			'',
			false,
		);
		$this->seed_setting_if_missing(
			'mail_from_name',
			'',
			false,
		);
		$this->seed_setting_if_missing(
			'smtp_host',
			'',
			false,
		);
		$this->seed_setting_if_missing(
			'smtp_port',
			'587',
			false,
		);
		$this->seed_setting_if_missing(
			'smtp_encryption',
			'tls',
			false,
		);
		$this->seed_setting_if_missing(
			'smtp_auth',
			'false',
			false,
		);
		$this->seed_setting_if_missing(
			'smtp_username',
			'',
			false,
		);
		$this->seed_setting_if_missing(
			'maxmind_account_id',
			'',
			false,
		);
	}

	/**
	 * Seed a setting only when no row exists yet.
	 *
	 * @param string $setting_key   Setting key.
	 * @param string $setting_value Setting value.
	 * @param bool   $autoload      Whether the setting should autoload.
	 * @return void
	 * @since 1.0.0
	 */
	private function seed_setting_if_missing(
		string $setting_key,
		string $setting_value,
		bool $autoload = true
	): void {
		if ( null !== $this->get_setting_value( $setting_key ) ) {
			return;
		}

		$this->upsert_setting( $setting_key, $setting_value, $autoload );
	}
}
