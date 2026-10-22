<?php
/**
 * PeakURL early runtime loader.
 *
 * Resolves the application runtime layout according to PEAKURL_DEV
 * before Composer autoloading is initialized.
 *
 * @package PeakURL
 * @since 1.7.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

$peakurl_raw_dev = getenv( 'PEAKURL_DEV' );

if ( false === $peakurl_raw_dev || '' === $peakurl_raw_dev ) {
	$peakurl_raw_dev = $_ENV['PEAKURL_DEV'] ?? ( $_SERVER['PEAKURL_DEV'] ?? false );
}

$is_peakurl_dev = true === filter_var(
	$peakurl_raw_dev,
	FILTER_VALIDATE_BOOLEAN,
	FILTER_NULL_ON_FAILURE,
);

$peakurl_source_root  = rtrim( ABSPATH, '/\\' );
$peakurl_runtime_root = $is_peakurl_dev ? $peakurl_source_root . '/server' : $peakurl_source_root;

// Early preflight: PeakURL requires PHP 8.3.0 or higher.
if ( PHP_VERSION_ID < 80300 ) {
	$current_php = PHP_VERSION;
	if ( 'cli' === PHP_SAPI ) {
		fwrite( STDERR, sprintf( "Error: PeakURL requires PHP 8.3.0 or higher. Current version: %s\n", $current_php ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		exit( 1 );
	}

	http_response_code( 500 );

	$uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
	$accept = (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' );
	$is_api = false !== strpos( $uri, '/api/' ) || false !== strpos( $accept, 'application/json' );

	if ( $is_api ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		echo (string) json_encode(
			array(
				'error'           => sprintf( 'PeakURL requires PHP 8.3.0 or higher. Your server is currently running PHP %s.', $current_php ),
				'requiredVersion' => '8.3.0',
				'currentVersion'  => $current_php,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		exit( 1 );
	}

	header( 'Content-Type: text/html; charset=utf-8' );

	$css_file   = is_readable( $peakurl_runtime_root . '/services/status-page.css' )
		? $peakurl_runtime_root . '/services/status-page.css'
		: $peakurl_source_root . '/server/services/status-page.css';
	$badge_file = is_readable( $peakurl_runtime_root . '/services/powered-by.css' )
		? $peakurl_runtime_root . '/services/powered-by.css'
		: $peakurl_source_root . '/server/services/powered-by.css';
	$styles     = ( is_readable( $css_file ) ? (string) file_get_contents( $css_file ) : '' ) .
		( is_readable( $badge_file ) ? (string) file_get_contents( $badge_file ) : '' );

	$logo_path = is_readable( $peakurl_source_root . '/client/assets/logo/peakurl.svg' )
		? $peakurl_source_root . '/client/assets/logo/peakurl.svg'
		: $peakurl_source_root . '/assets/peakurl.svg';
	$logo_data = is_readable( $logo_path )
		? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $logo_path ) )
		: '';

	$artwork_path = is_readable( $peakurl_runtime_root . '/services/status-illustrations/server-error.svg' )
		? $peakurl_runtime_root . '/services/status-illustrations/server-error.svg'
		: $peakurl_source_root . '/server/services/status-illustrations/server-error.svg';
	$artwork_data = is_readable( $artwork_path )
		? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $artwork_path ) )
		: '';

	$escaped_php = htmlspecialchars( $current_php, ENT_QUOTES, 'UTF-8' );
	?>
<!doctype html>
<html lang="en" dir="ltr" data-status-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Unsupported PHP Version — PeakURL</title>
<style><?php echo $styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
<script>try{const theme=localStorage.getItem('theme');if(theme==='light'||theme==='dark')document.documentElement.dataset.statusTheme=theme;}catch{}</script>
</head>
<body class="status-page">
<div class="status-container">
<header class="status-header">
	<div class="status-brand">
		<?php if ( '' !== $logo_data ) : ?>
			<img src="<?php echo htmlspecialchars( $logo_data, ENT_QUOTES, 'UTF-8' ); ?>" alt="" width="40" height="40">
		<?php endif; ?>
		<span>PeakURL</span>
	</div>
	<span class="status-tagline">Self-hosted link management platform</span>
</header>
<main class="status-main">
	<section class="status-hero">
		<div class="status-copy">
			<p class="status-eyebrow">500 · Unsupported PHP version</p>
			<h1 id="page-heading" class="status-title">PHP 8.3 or higher is required</h1>
			<p class="status-description">
				PeakURL requires <strong>PHP 8.3.0 or higher</strong> to run safely and reliably. Your server is currently running <strong>PHP <?php echo $escaped_php; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>.
			</p>
			<div class="status-actions">
				<a class="status-button" href="https://peakurl.org/docs/getting-started#installation" target="_blank" rel="noopener noreferrer">PHP upgrade guide</a>
			</div>
			<p class="status-note">
				Please update your server’s PHP version in cPanel, Plesk, Docker, or your hosting control panel to PHP 8.3, 8.4, or 8.5 before running PeakURL.
			</p>
		</div>
		<?php if ( '' !== $artwork_data ) : ?>
			<img class="status-artwork" data-status-illustration="server-error" src="<?php echo htmlspecialchars( $artwork_data, ENT_QUOTES, 'UTF-8' ); ?>" alt="" aria-hidden="true" draggable="false" oncontextmenu="return false" width="360" height="280">
		<?php endif; ?>
	</section>
	<section class="status-support">
		<h2 class="status-support-title">Runtime environment</h2>
		<p>Current runtime: PHP <?php echo $escaped_php; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> · Minimum required: PHP 8.3.0 (tested with PHP 8.3, 8.4, and 8.5).</p>
	</section>
</main>
<footer class="status-footer">
	<a class="powered-by" href="https://peakurl.org?utm_source=peakurl_public&amp;utm_medium=public_page&amp;utm_campaign=powered_by" target="_blank" rel="noopener noreferrer">
		<span>Powered by</span>
		<span class="powered-by-brand">
			<?php if ( '' !== $logo_data ) : ?>
				<img src="<?php echo htmlspecialchars( $logo_data, ENT_QUOTES, 'UTF-8' ); ?>" alt="" width="22" height="22">
			<?php endif; ?>
			PeakURL
		</span>
	</a>
</footer>
</div>
</body>
</html>
	<?php
	exit( 1 );
}

if ( $is_peakurl_dev && ! is_dir( $peakurl_runtime_root ) ) {
	throw new \RuntimeException(
		sprintf(
			'PEAKURL_DEV is set to true, but the development directory "server/" was not found at %s.',
			$peakurl_source_root,
		),
	);
}

$peakurl_environment_file = $peakurl_runtime_root . '/core/config/environment.php';

if ( ! file_exists( $peakurl_environment_file ) ) {
	throw new \RuntimeException(
		sprintf(
			'Environment definition file not found at "%s".',
			$peakurl_environment_file,
		),
	);
}

require_once $peakurl_environment_file;

\PeakURL\Core\Config\Environment::initialize( $peakurl_source_root, $is_peakurl_dev );
