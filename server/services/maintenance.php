<?php
/**
 * Maintenance mode view data and HTML rendering.
 *
 * @package PeakURL\Services
 * @since 1.0.8
 */

declare(strict_types=1);

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\I18n;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'get_maintenance_view_data' ) ) {
	/**
	 * Build the translated maintenance page copy and document metadata.
	 *
	 * @param array<string, mixed>|null $config     Optional runtime config.
	 * @param Connection|null           $connection Optional reused connection.
	 * @return array<string, string>
	 * @since 1.0.8
	 */
	function get_maintenance_view_data(
		?array $config = null,
		?Connection $connection = null
	): array {
		$app_config     = $config ?? get_peakurl_config();
		$app_connection = $connection;
		$site_name      = 'PeakURL';
		$locale         = Constants::DEFAULT_LOCALE;
		$html_lang      = 'en-US';
		$text_direction = 'ltr';
		$i18n_service   = null;

		try {
			if (
				null === $app_connection &&
				file_exists( Environment::get_instance()->get_source_root() . '/config.php' )
			) {
				$app_connection = get_peakurl_connection( $app_config );
			}

			$i18n_service   = new I18n(
				$app_config,
				null !== $app_connection
					? get_settings_api( $app_config, $app_connection )
					: null,
			);
			$locale         = $i18n_service->load_locale();
			$html_lang      = $i18n_service->get_html_lang( $locale );
			$text_direction = $i18n_service->get_text_direction( $locale );

			if ( null !== $app_connection ) {
				$configured_site_name = trim(
					(string) ( get_settings_api( $app_config, $app_connection )->get_option( 'site_name' ) ?? '' ),
				);

				if ( '' !== $configured_site_name ) {
					$site_name = $configured_site_name;
				}
			}
		} catch ( \Throwable $exception ) {
			$i18n_service   = null;
			$locale         = Constants::DEFAULT_LOCALE;
			$html_lang      = 'en-US';
			$text_direction = 'ltr';
		}

		if ( $i18n_service instanceof I18n ) {
			set_i18n_service( $i18n_service );
		}

		$maintenance_title = sprintf(
			/* translators: %s: configured site name. */
			__( '%s is briefly unavailable', 'peakurl' ),
			$site_name,
		);

		$maintenance_api_message = sprintf(
			/* translators: %s: configured site name. */
			__( '%s is briefly unavailable right now. Please try again in a moment.', 'peakurl' ),
			$site_name,
		);

		$version = trim( (string) ( $app_config[ Constants::VERSION ] ?? '' ) );

		return array(
			'siteName'          => $site_name,
			'locale'            => $locale,
			'htmlLang'          => $html_lang,
			'textDirection'     => $text_direction,
			'version'           => $version,
			'title'             => $maintenance_title,
			'statusLabel'       => __( 'Temporarily unavailable', 'peakurl' ),
			'heading'           => __( 'Briefly unavailable', 'peakurl' ),
			'message'           => __( 'We are making a few improvements right now. Please refresh this page in a moment.', 'peakurl' ),
			'supportingMessage' => __( 'Thanks for your patience.', 'peakurl' ),
			'loadingLabel'      => __( 'Loading', 'peakurl' ),
			'apiMessage'        => $maintenance_api_message,
		);
	}
}

if ( ! function_exists( 'get_maintenance_api_payload' ) ) {
	/**
	 * Build the maintenance-mode JSON response payload.
	 *
	 * @param array<string, string> $maintenance_view_data Localized maintenance data.
	 * @return array<string, mixed>
	 * @since 1.0.8
	 */
	function get_maintenance_api_payload( array $maintenance_view_data ): array {
		return array(
			'success' => false,
			'message' => (string) ( $maintenance_view_data['apiMessage'] ?? 'PeakURL is updating. Please try again in a moment.' ),
			'data'    => array(
				'maintenance' => true,
			),
		);
	}
}

if ( ! function_exists( 'render_maintenance_page' ) ) {
	/**
	 * Get the HTML maintenance page.
	 *
	 * @param array<string, string> $maintenance_view_data Localized maintenance data.
	 * @return string
	 * @since 1.0.8
	 */
	function render_maintenance_page( array $maintenance_view_data ): string {
		$heading = (string) ( $maintenance_view_data['heading'] ?? 'Briefly unavailable' );
		$content = '<p class="status-eyebrow">' . htmlspecialchars( (string) ( $maintenance_view_data['statusLabel'] ?? 'Update in progress' ), ENT_QUOTES, 'UTF-8' ) . '</p>' .
			'<h1 class="status-title">' . htmlspecialchars( $heading, ENT_QUOTES, 'UTF-8' ) . '</h1>' .
			'<p class="status-description">' . htmlspecialchars( (string) ( $maintenance_view_data['message'] ?? 'We are finishing an update right now. Please refresh this page in a moment.' ), ENT_QUOTES, 'UTF-8' ) . '</p>' .
			'<p class="status-note">' . htmlspecialchars( (string) ( $maintenance_view_data['supportingMessage'] ?? 'Your dashboard and short links will be back shortly.' ), ENT_QUOTES, 'UTF-8' ) . '</p>';

		return \PeakURL\Services\StatusPage::render_document(
			(string) ( $maintenance_view_data['title'] ?? $heading ),
			$content,
			$maintenance_view_data,
			illustration: 'maintenance',
		);
	}
}
