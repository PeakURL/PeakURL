<?php
/**
 * Database connection recovery page.
 *
 * Shown when config.php exists but PeakURL cannot establish a connection with
 * its configured database settings.
 *
 * @package PeakURL\Site
 * @since 1.3.0
 */

declare(strict_types=1);

use PeakURL\Services\Install\Screen as InstallScreen;
use PeakURL\Services\Install\State as InstallState;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
}

$root_path     = file_exists( __DIR__ . '/app/vendor/autoload.php' ) ? __DIR__ : dirname( __DIR__ );
$app_path      = $root_path . '/app';
$autoload_path = $app_path . '/vendor/autoload.php';

if ( ! file_exists( $autoload_path ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "PeakURL dependencies are missing. Upload the complete release package before continuing.\n";
	exit();
}

require $autoload_path;

$base_path     = InstallScreen::get_base_path(
	(string) ( $_SERVER['SCRIPT_NAME'] ?? '/database-connection-error.php' ),
);
$install_state = InstallState::get_state( $app_path );

if ( InstallState::READY === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/dashboard' ) );
	exit();
}

if ( InstallState::NEEDS_SETUP === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/setup-config.php' ) );
	exit();
}

if ( InstallState::NEEDS_INSTALL === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/install.php' ) );
	exit();
}

$retry_url = InstallScreen::format_url( $base_path, '/' );

http_response_code( 503 );
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Database connection failed | PeakURL</title>
	<style>
		:root { color-scheme: light; --ink: #172033; --muted: #5d6b82; --border: #dce3ed; --page: #f5f7fb; --card: #fff; --danger: #b42318; --danger-soft: #fff0ee; --primary: #4f46e5; }
		* { box-sizing: border-box; }
		body { align-items: center; background: var(--page); color: var(--ink); display: flex; font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; justify-content: center; line-height: 1.5; margin: 0; min-height: 100vh; padding: 24px; }
		main { max-width: 620px; width: 100%; }
		.card { background: var(--card); border: 1px solid var(--border); border-radius: 18px; box-shadow: 0 12px 32px rgb(15 23 42 / 8%); padding: 40px; }
		.status { align-items: center; background: var(--danger-soft); border-radius: 10px; color: var(--danger); display: inline-flex; font-size: 14px; font-weight: 700; gap: 8px; padding: 7px 10px; }
		.status::before { background: currentColor; border-radius: 50%; content: ""; height: 8px; width: 8px; }
		h1 { font-size: clamp(28px, 6vw, 38px); letter-spacing: -0.035em; line-height: 1.1; margin: 20px 0 14px; }
		p { color: var(--muted); margin: 0; }
		.steps { color: var(--muted); margin: 24px 0 0; padding-left: 22px; }
		.steps li + li { margin-top: 8px; }
		code { background: #eef1f6; border-radius: 5px; color: var(--ink); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 0.92em; padding: 2px 5px; }
		.actions { display: flex; gap: 12px; margin-top: 30px; }
		.button { background: var(--primary); border-radius: 8px; color: #fff; display: inline-block; font-weight: 700; padding: 11px 16px; text-decoration: none; }
		.note { border-top: 1px solid var(--border); font-size: 14px; margin-top: 28px; padding-top: 20px; }
		@media (max-width: 560px) { .card { padding: 28px 24px; } }
	</style>
</head>
<body>
	<main>
		<section class="card" aria-labelledby="database-connection-error-title">
			<span class="status">Database connection failed</span>
			<h1 id="database-connection-error-title">PeakURL could not connect to the configured database.</h1>
			<p>A <code>config.php</code> file was found, but the database settings in it could not be used. PeakURL has not changed your configuration.</p>
			<ul class="steps">
				<li>Check <code>DB_HOST</code>, <code>DB_PORT</code>, and <code>DB_DATABASE</code>.</li>
				<li>Confirm the <code>DB_USERNAME</code> and <code>DB_PASSWORD</code> have access to that database.</li>
				<li>Save the corrected <code>config.php</code>, then try again.</li>
			</ul>
			<div class="actions">
				<a class="button" href="<?php echo htmlspecialchars( $retry_url, ENT_QUOTES, 'UTF-8' ); ?>">Try again</a>
			</div>
			<p class="note">For your security, PeakURL does not display database connection details on this page.</p>
		</section>
	</main>
</body>
</html>
