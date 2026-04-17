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
			$this->add_option( 'site_name', $site_name );
		}

		if ( '' !== $site_slug ) {
			$this->add_option( 'site_slug', $site_slug );
		}

		if ( '' !== $site_url ) {
			$this->update_option( 'site_url', $site_url );
		}

		if ( '' !== $admin_email ) {
			$this->add_option( 'admin_email', $admin_email, false );
		}

		if ( '' !== $version ) {
			$this->update_option( 'installed_version', $version, false );
		}

		if ( '' !== $manifest_url ) {
			$this->update_option(
				'update_manifest_url',
				$manifest_url,
				false,
			);
		}

		$this->sync_managed_settings();

		if ( null === $this->get_option( 'installed_at' ) ) {
			$this->update_option( 'installed_at', $this->now(), false );
		}

		$this->delete_options(
			array(
				'site_title',
				'workspace_name',
				'workspace_slug',
				'default_team_id',
			),
		);
	}

	/**
	 * Retrieve a single option value by its key.
	 *
	 * @param string $option_name Option key to look up.
	 * @return string|null The stored value or null when missing.
	 * @since 1.0.0
	 */
	private function get_option( string $option_name ): ?string {
		return $this->settings_api->get_option( $option_name );
	}

	/**
	 * Insert or update an option row.
	 *
	 * @param string $option_name  Option key.
	 * @param string $option_value Option value to persist.
	 * @param bool   $autoload      Whether the option should autoload.
	 * @since 1.0.0
	 */
	private function update_option(
		string $option_name,
		string $option_value,
		bool $autoload = true
	): void {
		$this->settings_api->update_option(
			$option_name,
			$option_value,
			$this->now(),
			$autoload,
		);
	}

	/**
	 * Delete one or more options.
	 *
	 * @param array<int, string> $option_names Option keys to remove.
	 * @since 1.0.0
	 */
	private function delete_options( array $option_names ): void {
		$this->settings_api->delete_options( $option_names );
	}

	/**
	 * Initialize default database-backed runtime options.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function sync_managed_settings(): void {
		$site_language = trim( (string) ( $this->config['PEAKURL_SITE_LANGUAGE'] ?? '' ) );

		if ( '' === $site_language ) {
			$site_language = Constants::DEFAULT_LOCALE;
		} else {
			$site_language = $this->i18n_service->normalize_locale( $site_language );

			if ( ! $this->i18n_service->is_locale_available( $site_language ) ) {
				$site_language = Constants::DEFAULT_LOCALE;
			}
		}

		$this->add_option(
			'site_language',
			$site_language,
		);
		$this->add_option(
			'mail_driver',
			'mail',
			false,
		);
		$this->add_option(
			'mail_from_email',
			'',
			false,
		);
		$this->add_option(
			'mail_from_name',
			'',
			false,
		);
		$this->add_option(
			'smtp_host',
			'',
			false,
		);
		$this->add_option(
			'smtp_port',
			'587',
			false,
		);
		$this->add_option(
			'smtp_encryption',
			'tls',
			false,
		);
		$this->add_option(
			'smtp_auth',
			'false',
			false,
		);
		$this->add_option(
			'smtp_username',
			'',
			false,
		);
		$this->add_option(
			'maxmind_account_id',
			'',
			false,
		);
	}

	/**
	 * Add an option only when no row exists yet.
	 *
	 * @param string $option_name  Option key.
	 * @param string $option_value Option value.
	 * @param bool   $autoload      Whether the option should autoload.
	 * @return void
	 * @since 1.0.0
	 */
	private function add_option(
		string $option_name,
		string $option_value,
		bool $autoload = true
	): void {
		if ( null !== $this->get_option( $option_name ) ) {
			return;
		}

		$this->update_option( $option_name, $option_value, $autoload );
	}
}
