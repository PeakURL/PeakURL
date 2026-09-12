<?php
/**
 * Localized landing page view data and HTML rendering.
 *
 * @package PeakURL\Services
 * @since 1.5.3
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

if ( ! function_exists( 'get_landing_page_data' ) ) {
	/**
	 * Build the translated landing page copy and document metadata.
	 *
	 * @param array<string, mixed>|null $config     Optional runtime config.
	 * @param Connection|null           $connection Optional reused connection.
	 * @return array<string, string>
	 * @since 1.5.3
	 */
	function get_landing_page_data(
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

			$i18n_service   = get_i18n_service(
				$app_config,
				$app_connection,
			);
			$locale         = $i18n_service->get_current_locale();
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

		$page_title = sprintf(
			/* translators: %s: site name */
			__( '%s - Open-Source Self-Hosted URL Shortener', 'peakurl' ),
			$site_name,
		);

		$meta_description = sprintf(
			/* translators: %s: site name */
			__( '%s is an open-source, self-hosted URL shortener built for speed, privacy, and control.', 'peakurl' ),
			$site_name,
		);

		$welcome_message = sprintf(
			/* translators: %s: site name */
			__( 'Welcome to your self-hosted %s installation. The platform is running securely and is ready to manage your links, analytics, and integrations.', 'peakurl' ),
			$site_name,
		);

		$customize_desc = sprintf(
			/* translators: %s: site name */
			__( 'This is the default %s landing page template. To completely re-style and customize this page with your own branding, edit the local file:', 'peakurl' ),
			$site_name,
		);

		$settings_path = sprintf(
			'<strong class="text-white/80 font-medium">%s</strong>',
			__( 'Settings → General', 'peakurl' ),
		);

		$customize_note = sprintf(
			/* translators: %s: Settings link or label */
			__( 'Changes are applied instantly. You can also switch the root domain behavior back to a direct Login screen or a Custom Redirect URL from your dashboard under %s.', 'peakurl' ),
			$settings_path,
		);

		$visit_updates_link = sprintf(
			/* translators: %s: site name */
			__( 'Visit %s', 'peakurl' ),
			'peakurl.org',
		);

		return array(
			'siteName'         => $site_name,
			'locale'           => $locale,
			'htmlLang'         => $html_lang,
			'textDirection'    => $text_direction,
			'title'            => $page_title,
			'metaDescription'  => $meta_description,
			'website'          => __( 'Website', 'peakurl' ),
			'documentation'    => __( 'Documentation', 'peakurl' ),
			'github'           => __( 'GitHub', 'peakurl' ),
			'selfHosted'       => __( 'Self-Hosted', 'peakurl' ),
			'heading'          => __( 'Shorten, track, and <span class="gradient-text">own every link.</span>', 'peakurl' ),
			'welcomeMessage'   => $welcome_message,
			'dashboardLogin'   => __( 'Dashboard Login', 'peakurl' ),
			'readDocs'         => __( 'Read the docs', 'peakurl' ),
			'customizeTitle'   => __( 'Customize This Page', 'peakurl' ),
			'customizeDesc'    => $customize_desc,
			'customizeNote'    => $customize_note,
			'visitUpdatesLink' => $visit_updates_link,
			'forUpdates'       => __( 'for updates.', 'peakurl' ),
		);
	}
}

if ( ! function_exists( 'get_landing_page_html' ) ) {
	/**
	 * Get the localized landing page HTML content.
	 *
	 * @param string|null              $landing_page_file Optional landing page HTML file path.
	 * @param array<string, mixed>|null $config            Optional runtime config.
	 * @param Connection|null          $connection        Optional reused connection.
	 * @return string
	 * @since 1.5.3
	 */
	function get_landing_page_html(
		?string $landing_page_file = null,
		?array $config = null,
		?Connection $connection = null
	): string {
		$view_data = get_landing_page_data( $config, $connection );

		$file_path = $landing_page_file ?? ( Environment::get_instance()->get_content_path() . '/landing-page.html' );
		$html      = '';

		if ( file_exists( $file_path ) ) {
			$html = (string) file_get_contents( $file_path );
		}

		if ( '' === trim( $html ) ) {
			return '';
		}

		$html_lang      = htmlspecialchars( (string) ( $view_data['htmlLang'] ?? 'en-US' ), ENT_QUOTES, 'UTF-8' );
		$text_direction = 'rtl' === strtolower( (string) ( $view_data['textDirection'] ?? 'ltr' ) ) ? 'rtl' : 'ltr';

		// Replace html tag lang and dir attributes.
		$html = preg_replace(
			'/<html[^>]*>/i',
			'<html lang="' . $html_lang . '" dir="' . $text_direction . '">',
			$html,
			1
		);

		// Replace document title and meta description.
		if ( ! empty( $view_data['title'] ) ) {
			$html = preg_replace(
				'/<title>.*?<\/title>/is',
				'<title>' . htmlspecialchars( (string) $view_data['title'], ENT_QUOTES, 'UTF-8' ) . '</title>',
				$html,
				1
			);
		}

		if ( ! empty( $view_data['metaDescription'] ) ) {
			$html = preg_replace(
				'/<meta\s+name="description"\s+content="[^"]*"/i',
				'<meta name="description" content="' . htmlspecialchars( (string) $view_data['metaDescription'], ENT_QUOTES, 'UTF-8' ) . '"',
				$html,
				1
			);
		}

		// Localize standard navigation links.
		$html = str_replace( '>Website<', '>' . htmlspecialchars( (string) $view_data['website'], ENT_QUOTES, 'UTF-8' ) . '<', $html );
		$html = str_replace( '>Documentation<', '>' . htmlspecialchars( (string) $view_data['documentation'], ENT_QUOTES, 'UTF-8' ) . '<', $html );
		$html = str_replace( '>GitHub<', '>' . htmlspecialchars( (string) $view_data['github'], ENT_QUOTES, 'UTF-8' ) . '<', $html );

		// Localize badge.
		$html = preg_replace(
			'/(<span[^>]*class="[^"]*uppercase[^"]*"[^>]*>)\s*Self-Hosted\s*(<\/span>)/i',
			'${1}' . htmlspecialchars( (string) $view_data['selfHosted'], ENT_QUOTES, 'UTF-8' ) . '${2}',
			$html
		);

		// Localize hero heading.
		$html = preg_replace(
			'/(<h1[^>]*>)\s*Shorten,\s*track,\s*and\s*<span class="gradient-text">own every link\.<\/span>\s*(<\/h1>)/is',
			'${1}' . (string) $view_data['heading'] . '${2}',
			$html
		);

		// Localize hero description paragraph.
		$html = preg_replace(
			'/(<p[^>]*class="[^"]*max-w-2xl[^"]*"[^>]*>)\s*Welcome to your self-hosted PeakURL installation\..*?\s*(<\/p>)/is',
			'${1}' . htmlspecialchars( (string) $view_data['welcomeMessage'], ENT_QUOTES, 'UTF-8' ) . '${2}',
			$html
		);

		// Localize action buttons.
		$html = str_replace( 'Dashboard Login', htmlspecialchars( (string) $view_data['dashboardLogin'], ENT_QUOTES, 'UTF-8' ), $html );
		$html = str_replace( 'Read the docs', htmlspecialchars( (string) $view_data['readDocs'], ENT_QUOTES, 'UTF-8' ), $html );

		// Localize card heading and copy.
		$html = str_replace( 'Customize This Page', htmlspecialchars( (string) $view_data['customizeTitle'], ENT_QUOTES, 'UTF-8' ), $html );
		$html = preg_replace(
			'/This is the default PeakURL landing page template\.\s*To completely re-style and customize this page with your own branding,\s*edit the local file:/is',
			htmlspecialchars( (string) $view_data['customizeDesc'], ENT_QUOTES, 'UTF-8' ),
			$html
		);
		$html = preg_replace(
			'/Changes are applied instantly\.\s*You can also switch the root domain behavior back to a direct Login screen or a Custom Redirect URL from your dashboard under\s*<strong[^>]*>Settings\s*(?:&rarr;|→)\s*General<\/strong>\./is',
			(string) $view_data['customizeNote'],
			$html
		);

		// Localize footer text.
		$html = str_replace( 'Visit peakurl.org', htmlspecialchars( (string) $view_data['visitUpdatesLink'], ENT_QUOTES, 'UTF-8' ), $html );
		$html = str_replace( 'for updates.', htmlspecialchars( (string) $view_data['forUpdates'], ENT_QUOTES, 'UTF-8' ), $html );

		return $html;
	}
}
