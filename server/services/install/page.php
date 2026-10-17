<?php
/**
 * Shared installer presentation, independent of database/configuration writes.
 *
 * @package PeakURL\Services\Install
 */
declare(strict_types=1);
namespace PeakURL\Services\Install;

use PeakURL\Services\Brand;
use PeakURL\Services\Illustration;
use PeakURL\Services\Logo;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/** Owns installer layout, theme, progress, and bundled presentation assets. */
class Page {
	/**
	 * Render one installer step around trusted, already-escaped form markup.
	 *
	 * @param string $title Browser page title.
	 * @param string $content_html Escaped form view.
	 * @param int $step Zero-based welcome, database, or administrator step.
	 * @param Locale $locale Installer language, available before the database.
	 * @return string Complete document with local assets.
	 */
	public static function render( string $title, string $content_html, int $step, Locale $locale ): string {
		$step          = max( 0, min( 2, $step ) );
		$steps         = array( __( 'Welcome', 'peakurl' ), __( 'Database', 'peakurl' ), __( 'Admin account', 'peakurl' ) );
		$illustrations = array( 'installation', 'database-setup', 'administrator' );
		$headings      = array(
			__( 'Install PeakURL', 'peakurl' ),
			__( 'Connect your database', 'peakurl' ),
			__( 'Set up your site', 'peakurl' ),
		);
		$descriptions  = array(
			__( 'Connect your database, then create the administrator account for this installation.', 'peakurl' ),
			__( 'PeakURL uses this database to store your links, accounts, and analytics.', 'peakurl' ),
			__( 'Choose a site title and create the account you’ll use to manage PeakURL.', 'peakurl' ),
		);
		$illustration  = $illustrations[ $step ];
		$artwork       = Illustration::get_data_url( $illustration );
		$logo          = Logo::get_data_url();
		$styles        = file_get_contents( dirname( __DIR__ ) . '/status-page.css' ) . file_get_contents( __DIR__ . '/installer.css' ) . Brand::get_badge_styles();
		$script        = file_get_contents( __DIR__ . '/installer.js' );
		if ( 0 === $step ) {
			$script .= "\n" . file_get_contents( __DIR__ . '/languagePicker.js' );
		}
		ob_start();
		require __DIR__ . '/templates/page.php';
		return (string) ob_get_clean();
	}
}
