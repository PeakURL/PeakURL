<?php
/**
 * Maintenance mode view data and HTML rendering.
 *
 * @package PeakURL\Services
 * @since 1.0.8
 */

declare(strict_types=1);

use PeakURL\Core\Config\Constants;
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
				file_exists( ABSPATH . 'config.php' )
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
					(string) ( $app_connection->get_option( 'site_name' ) ?? '' ),
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
		$html_lang          = htmlspecialchars(
			(string) ( $maintenance_view_data['htmlLang'] ?? 'en-US' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$text_direction     = 'rtl' === strtolower( (string) ( $maintenance_view_data['textDirection'] ?? 'ltr' ) )
			? 'rtl'
			: 'ltr';
		$title              = htmlspecialchars(
			(string) ( $maintenance_view_data['title'] ?? 'PeakURL is briefly unavailable' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$status_label       = htmlspecialchars(
			(string) ( $maintenance_view_data['statusLabel'] ?? 'Update in progress' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$heading            = htmlspecialchars(
			(string) ( $maintenance_view_data['heading'] ?? 'Briefly unavailable' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$message            = htmlspecialchars(
			(string) ( $maintenance_view_data['message'] ?? 'We are finishing an update right now. Please refresh this page in a moment.' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$supporting_message = htmlspecialchars(
			(string) ( $maintenance_view_data['supportingMessage'] ?? 'Your dashboard and short links will be back shortly.' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$loading_label      = htmlspecialchars(
			(string) ( $maintenance_view_data['loadingLabel'] ?? 'Loading' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$generator_meta     = get_generator_tag( (string) ( $maintenance_view_data['version'] ?? '' ) );

		return '<!doctype html>' .
			'<html lang="' . $html_lang . '" dir="' . $text_direction . '">' .
			'<head>' .
			'<meta charset="utf-8">' .
			'<title>' . $title . '</title>' .
			'<meta name="viewport" content="width=device-width, initial-scale=1">' .
			( '' !== $generator_meta ? "\n\t\t" . $generator_meta : '' ) .
			'<style>' .
			'body{margin:0;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:radial-gradient(circle at top,#eef2ff 0,#f8fafc 38%,#eef2ff 100%);color:#0f172a;min-height:100vh}' .
			'.shell{min-height:100vh;display:grid;place-items:center;padding:24px}' .
			'.card{width:min(100%,540px);background:rgba(255,255,255,.96);border:1px solid rgba(99,102,241,.14);border-radius:28px;box-shadow:0 28px 90px rgba(15,23,42,.12);padding:36px 32px}' .
			'.status{display:flex;align-items:center;gap:14px;margin-bottom:22px}' .
			'.status-label{font-size:12px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#6366f1}' .
			'.loader{width:18px;height:18px;border-radius:999px;border:2px solid rgba(99,102,241,.18);border-top-color:#6366f1;animation:peakurl-spin .8s linear infinite;flex:none}' .
			'h1{margin:0 0 14px;font-size:clamp(2rem,4vw,2.6rem);line-height:1.04;letter-spacing:-.04em;color:#111827}' .
			'p{margin:0;font-size:1rem;line-height:1.75;color:#475569}' .
			'.supporting{margin-top:14px;color:#64748b}' .
			'@keyframes peakurl-spin{to{transform:rotate(360deg)}}' .
			'@media (max-width:640px){.shell{padding:18px}.card{padding:30px 22px;border-radius:24px}}' .
			'</style>' .
			'</head>' .
			'<body>' .
			'<main class="shell">' .
			'<section class="card">' .
			'<div class="status">' .
			'<div class="loader" aria-hidden="true"></div>' .
			'<span class="status-label">' . $status_label . '</span>' .
			'<span class="screen-reader-text" style="position:absolute;left:-9999px">' . $loading_label . '</span>' .
			'</div>' .
			'<h1>' . $heading . '</h1>' .
			'<p>' . $message . '</p>' .
			'<p class="supporting">' . $supporting_message . '</p>' .
			'</section>' .
			'</main>' .
			'</body>' .
			'</html>';
	}
}
