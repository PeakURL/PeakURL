<?php
/**
 * PeakURL browser-based site installation form (installer step 3).
 *
 * Collects site name, administrator credentials, and optional
 * settings, then delegates to {@see Install::install()} to
 * create the schema, seed initial data, and log the admin in.
 *
 * On success the user is redirected to `/dashboard/about?source=install`.
 *
 * @package PeakURL\Site
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Http\Request;
use PeakURL\Services\Install;
use PeakURL\Services\InstallerI18n;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
}

// ────────────────────────────────────────────────────────────────
// Helper functions
// ────────────────────────────────────────────────────────────────

/**
 * Derive the URL base path from the PHP SCRIPT_NAME.
 *
 * @param string $script_name Value of $_SERVER['SCRIPT_NAME'].
 * @return string Base path without trailing slash, or ''.
 * @since 1.0.0
 */
function peakurl_install_base_path( string $script_name ): string {
	$base_path = str_replace( '\\', '/', dirname( $script_name ) );

	if ( '.' === $base_path || '/' === $base_path ) {
		return '';
	}

	return rtrim( $base_path, '/' );
}

/**
 * Build a full URL path by combining the base path and a suffix.
 *
 * @param string $base_path Base path (may be empty).
 * @param string $suffix    Suffix to append.
 * @return string Combined URL path.
 * @since 1.0.0
 */
function peakurl_install_url( string $base_path, string $suffix, array $query = array() ): string {
	$normalized_suffix = '/' . ltrim( $suffix, '/' );

	if ( '' === $base_path ) {
		$url = $normalized_suffix;
	} else {
		$url = $base_path . $normalized_suffix;
	}

	$query = array_filter(
		$query,
		static function ( $value ): bool {
			return '' !== trim( (string) $value );
		},
	);

	if ( empty( $query ) ) {
		return $url;
	}

	return $url . '?' . http_build_query( $query );
}

/**
 * Retrieve a form value and HTML-escape it for safe output.
 *
 * @param array<string,string> $values Current form values.
 * @param string               $key    Field name.
 * @return string Escaped value.
 * @since 1.0.0
 */
function peakurl_install_value( array $values, string $key ): string {
	return htmlspecialchars(
		(string) ( $values[ $key ] ?? '' ),
		ENT_QUOTES,
		'UTF-8',
	);
}

$root_path     = file_exists( __DIR__ . '/app/vendor/autoload.php' ) ? __DIR__ : dirname( __DIR__ );
$app_path      = $root_path . '/app';
$autoload_path = $app_path . '/vendor/autoload.php';
$base_path     = peakurl_install_base_path(
	(string) ( $_SERVER['SCRIPT_NAME'] ?? '/install.php' ),
);

if ( ! file_exists( $autoload_path ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "PeakURL dependencies are missing. Upload the complete release package before running the installer.\n";
	exit();
}

require $autoload_path;

$requested_locale = trim(
	(string) ( $_POST['site_language'] ?? $_GET['site_language'] ?? '' ),
);
$installer_i18n   = new InstallerI18n(
	$root_path,
	$requested_locale,
	(string) ( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ),
);

peakurl_override_i18n_service( $installer_i18n->get_service() );

$runtime_state = Install::get_runtime_state( $app_path );

if ( Install::STATE_READY === $runtime_state ) {
	header( 'Location: ' . peakurl_install_url( $base_path, '/dashboard' ) );
	exit();
}

if ( Install::STATE_NEEDS_SETUP === $runtime_state ) {
	header( 'Location: ' . peakurl_install_url( $base_path, '/setup-config.php' ) );
	exit();
}

$scheme = 'http';

if (
	( ! empty( $_SERVER['HTTPS'] ) &&
		'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) ||
	'https' === strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) )
) {
	$scheme = 'https';
}

$host                    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$detected_site_url       = $scheme . '://' . $host . $base_path;
$values                  = Install::get_form_defaults( $app_path, $detected_site_url );
$values['site_language'] = $installer_i18n->get_locale();
$error_message           = '';
$page_title              = sprintf( __( 'Administrator Setup - %s', 'peakurl' ), 'PeakURL' );

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
	foreach ( array_keys( $values ) as $key ) {
		if ( ! array_key_exists( $key, $_POST ) ) {
			continue;
		}

		$posted_value   = (string) $_POST[ $key ];
		$values[ $key ] =
			'owner_password' === $key ? $posted_value : trim( $posted_value );
	}

	try {
		$request = Request::from_globals();
		Install::install( $app_path, $_POST, $request );

		foreach ( $request->get_response_cookies() as $cookie_header ) {
			header( 'Set-Cookie: ' . $cookie_header, false );
		}

		header( 'Location: ' . peakurl_install_url( $base_path, '/dashboard/about?source=install' ) );
		exit();
	} catch ( \Throwable $exception ) {
		$error_message = $exception->getMessage();
	}
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars( $installer_i18n->get_html_lang(), ENT_QUOTES, 'UTF-8' ); ?>" dir="<?php echo htmlspecialchars( $installer_i18n->get_text_direction(), ENT_QUOTES, 'UTF-8' ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo $page_title; ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<style>
		:root {
			color-scheme: light;
			--accent: #6366f1;
			--accent-light: rgba(99,102,241,0.08);
			--accent-glow: rgba(99,102,241,0.18);
			--dark: #0f172a;
			--gray-50: #f8fafc;
			--gray-100: #f1f5f9;
			--gray-200: #e2e8f0;
			--gray-300: #cbd5e1;
			--gray-400: #94a3b8;
			--gray-500: #64748b;
			--gray-700: #334155;
			--gray-900: #0f172a;
			--white: #fff;
			--red-50: #fef2f2;
			--red-100: #fee2e2;
			--red-200: #fecaca;
			--red-500: #ef4444;
			--red-700: #b91c1c;
			--green-500: #22c55e;
			--radius: 14px;
			--radius-lg: 24px;
		}

		*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }

		body {
			font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			color: var(--gray-900);
			min-height: 100vh;
			background: var(--gray-50);
			background-image:
				radial-gradient(ellipse 80% 50% at 50% -20%, var(--accent-light), transparent),
				radial-gradient(ellipse 60% 40% at 80% 100%, rgba(99,102,241,0.04), transparent);
		}

		/* ── Shell ── */
		.shell {
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			align-items: center;
			padding: 48px 24px 72px;
		}

		/* ── Top bar ── */
		.topbar {
			display: flex;
			align-items: center;
			gap: 11px;
			margin-bottom: 48px;
		}

		.topbar-icon {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 40px;
			height: 40px;
			border-radius: 12px;
			background: var(--dark);
			box-shadow: 0 2px 8px rgba(15,23,42,0.18);
		}

		.topbar-icon svg { color: var(--white); }

		.topbar-text {
			font-size: 19px;
			font-weight: 800;
			letter-spacing: -0.03em;
			color: var(--dark);
		}

		/* ── Stepper ── */
		.stepper {
			display: flex;
			align-items: center;
			margin-bottom: 40px;
			background: var(--white);
			border: 1px solid var(--gray-200);
			border-radius: 60px;
			padding: 10px 28px;
			box-shadow: 0 1px 3px rgba(15,23,42,0.04);
		}

		.stepper-step {
			display: flex;
			align-items: center;
			gap: 10px;
		}

		.stepper-dot {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 30px;
			height: 30px;
			border-radius: 50%;
			font-size: 12px;
			font-weight: 700;
			flex-shrink: 0;
			transition: all 0.2s ease;
		}

		.stepper-dot.done {
			background: var(--dark);
			color: var(--white);
		}

		.stepper-dot.active {
			background: var(--accent);
			color: var(--white);
			box-shadow: 0 0 0 4px var(--accent-glow);
		}

		.stepper-dot.upcoming {
			background: var(--gray-100);
			color: var(--gray-400);
			border: 1.5px solid var(--gray-200);
		}

		.stepper-label {
			font-size: 13px;
			font-weight: 500;
			color: var(--gray-400);
			white-space: nowrap;
		}

		.stepper-step.is-done .stepper-label { color: var(--gray-500); }
		.stepper-step.is-active .stepper-label { color: var(--gray-900); font-weight: 600; }

		.stepper-line {
			width: 40px;
			height: 2px;
			background: var(--gray-200);
			margin: 0 14px;
			flex-shrink: 0;
			border-radius: 1px;
		}

		.stepper-line.done { background: var(--dark); }

		/* ── Card ── */
		.card {
			width: 100%;
			max-width: 580px;
			background: var(--white);
			border: 1px solid var(--gray-200);
			border-radius: var(--radius-lg);
			padding: 44px;
			box-shadow:
				0 1px 2px rgba(15,23,42,0.03),
				0 4px 16px rgba(15,23,42,0.04),
				0 12px 48px rgba(15,23,42,0.06);
		}

		.card-header {
			display: flex;
			align-items: flex-start;
			gap: 16px;
			margin-bottom: 8px;
		}

		.card-icon {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 52px;
			height: 52px;
			border-radius: 16px;
			background: linear-gradient(135deg, rgba(34,197,94,0.08), rgba(34,197,94,0.16));
			color: var(--green-500);
			flex-shrink: 0;
		}

		.card-header-text { flex: 1; padding-top: 2px; }

		h1 {
			font-size: 1.5rem;
			font-weight: 800;
			letter-spacing: -0.03em;
			color: var(--gray-900);
			line-height: 1.25;
		}

		.desc {
			margin-top: 6px;
			font-size: 14px;
			line-height: 1.7;
			color: var(--gray-500);
		}

		/* ── Divider ── */
		.divider {
			height: 1px;
			background: var(--gray-200);
			margin: 24px 0;
		}

		/* ── Error ── */
		.error-box {
			margin: 20px 0 4px;
			display: flex;
			align-items: flex-start;
			gap: 12px;
			padding: 14px 18px;
			border-radius: var(--radius);
			border: 1px solid var(--red-200);
			background: var(--red-50);
		}

		.error-box .bang {
			flex-shrink: 0;
			width: 22px;
			height: 22px;
			border-radius: 50%;
			background: var(--red-100);
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 11px;
			font-weight: 800;
			color: var(--red-500);
			margin-top: 1px;
		}

		.error-box p {
			font-size: 14px;
			line-height: 1.6;
			color: var(--red-700);
		}

		/* ── Form ── */
		.form-body {
			margin-top: 28px;
			display: flex;
			flex-direction: column;
			gap: 22px;
		}

		.form-section-label {
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: var(--gray-400);
			margin-bottom: -6px;
		}

		.grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 18px;
		}

		.field { display: flex; flex-direction: column; gap: 7px; }
		.field.full { grid-column: 1 / -1; }

		label {
			font-size: 13px;
			font-weight: 600;
			color: var(--gray-900);
		}



		input[type="text"],
		input[type="password"],
		input[type="email"] {
			width: 100%;
			padding: 11px 14px;
			border-radius: 12px;
			border: 1.5px solid var(--gray-200);
			background: var(--white);
			font-family: inherit;
			font-size: 14px;
			color: var(--gray-900);
			outline: none;
			transition: border-color 0.15s, box-shadow 0.15s;
		}

		input:hover { border-color: var(--gray-300); }

		input:focus {
			border-color: var(--accent);
			box-shadow: 0 0 0 3px var(--accent-glow);
		}

		input::placeholder { color: var(--gray-400); }

		.hint {
			font-size: 12px;
			color: var(--gray-400);
			line-height: 1.5;
		}

		/* ── Actions ── */
		.actions {
			display: flex;
			gap: 12px;
			align-items: center;
			flex-wrap: wrap;
			padding-top: 4px;
		}

		.actions-note {
			font-size: 13px;
			color: var(--gray-500);
			margin-right: auto;
		}

		.btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 8px;
			padding: 12px 24px;
			border-radius: 12px;
			font-family: inherit;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
			text-decoration: none;
			border: 0;
			transition: all 0.15s ease;
		}

		.btn svg { width: 15px; height: 15px; }

		.btn-primary {
			background: var(--accent);
			color: var(--white);
			box-shadow: 0 1px 3px rgba(99,102,241,0.3), 0 4px 12px rgba(99,102,241,0.15);
		}

		.btn-primary:hover {
			background: #5558e6;
			box-shadow: 0 2px 6px rgba(99,102,241,0.35), 0 6px 20px rgba(99,102,241,0.2);
			transform: translateY(-1px);
		}

		.btn-primary:active { transform: translateY(0); }

		.btn-ghost {
			background: var(--white);
			color: var(--gray-500);
			border: 1.5px solid var(--gray-200);
		}

		.btn-ghost:hover {
			background: var(--gray-50);
			color: var(--gray-900);
			border-color: var(--gray-300);
		}

		/* ── Footer ── */
		.footer {
			margin-top: 36px;
			font-size: 12px;
			color: var(--gray-400);
		}

		.footer a {
			color: var(--gray-500);
			text-decoration: none;
			font-weight: 600;
			transition: color 0.15s;
		}

		.footer a:hover { color: var(--accent); }

		/* ── Responsive ── */
		@media (max-width: 640px) {
			.shell { padding: 32px 16px 48px; }
			.card { padding: 28px 22px; border-radius: 20px; }
			.grid { grid-template-columns: 1fr; }
			.stepper { padding: 8px 16px; }
			.stepper-label { display: none; }
			.stepper-line { width: 28px; margin: 0 8px; }
			.card-header { flex-direction: column; gap: 12px; }
		}
	</style>
</head>
<body>
	<div class="shell">
		<!-- Logo -->
		<div class="topbar">
			<div class="topbar-icon">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
			</div>
			<span class="topbar-text">PeakURL</span>
		</div>

		<!-- Step indicator -->
		<div class="stepper">
			<div class="stepper-step is-done">
				<div class="stepper-dot done">&#10003;</div>
				<span class="stepper-label"><?php echo esc_html__( 'Welcome', 'peakurl' ); ?></span>
			</div>

			<div class="stepper-line done"></div>

			<div class="stepper-step is-done">
				<div class="stepper-dot done">&#10003;</div>
				<span class="stepper-label"><?php echo esc_html__( 'Database', 'peakurl' ); ?></span>
			</div>

			<div class="stepper-line done"></div>

			<div class="stepper-step is-active">
				<div class="stepper-dot active">3</div>
				<span class="stepper-label"><?php echo esc_html__( 'Admin account', 'peakurl' ); ?></span>
			</div>
		</div>

		<!-- Card -->
		<div class="card">
			<div class="card-header">
				<div class="card-icon">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
				</div>
				<div class="card-header-text">
					<h1><?php echo esc_html__( 'Create your account', 'peakurl' ); ?></h1>
					<p class="desc">
						<?php echo esc_html__( 'Name your site and set up the first administrator. You will be signed in automatically once the install completes.', 'peakurl' ); ?>
					</p>
				</div>
			</div>

			<?php if ( '' !== $error_message ) : ?>
				<div class="error-box">
					<div class="bang">!</div>
					<p><?php echo htmlspecialchars( $error_message, ENT_QUOTES, 'UTF-8' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo htmlspecialchars( peakurl_install_url( $base_path, '/install.php' ), ENT_QUOTES, 'UTF-8' ); ?>" novalidate>
				<input type="hidden" name="site_language" value="<?php echo peakurl_install_value( $values, 'site_language' ); ?>">
				<div class="form-body">
					<div class="divider" style="margin: 0;"></div>
					<p class="form-section-label"><?php echo esc_html__( 'Site info', 'peakurl' ); ?></p>
					<div class="grid">
						<div class="field full">
							<label for="workspace_name"><?php echo esc_html__( 'Site title', 'peakurl' ); ?></label>
							<input id="workspace_name" name="workspace_name" type="text" value="<?php echo peakurl_install_value( $values, 'workspace_name' ); ?>" placeholder="<?php echo esc_attr__( 'My Link Hub', 'peakurl' ); ?>" required>
						</div>
					</div>
					<div class="divider" style="margin: 4px 0;"></div>
					<p class="form-section-label"><?php echo esc_html__( 'Administrator', 'peakurl' ); ?></p>
					<div class="grid">
						<div class="field">
							<label for="owner_username"><?php echo esc_html__( 'Username', 'peakurl' ); ?></label>
							<input id="owner_username" name="owner_username" type="text" value="<?php echo peakurl_install_value( $values, 'owner_username' ); ?>" placeholder="admin" required>
							<p class="hint"><?php echo esc_html__( 'Letters, numbers, dots, dashes, underscores, or @.', 'peakurl' ); ?></p>
						</div>
						<div class="field">
							<label for="owner_email"><?php echo esc_html__( 'Email', 'peakurl' ); ?></label>
							<input id="owner_email" name="owner_email" type="email" value="<?php echo peakurl_install_value( $values, 'owner_email' ); ?>" placeholder="<?php echo esc_attr__( 'you@company.com', 'peakurl' ); ?>" required>
						</div>
						<div class="field full">
							<label for="owner_password"><?php echo esc_html__( 'Password', 'peakurl' ); ?></label>
							<input id="owner_password" name="owner_password" type="password" value="<?php echo peakurl_install_value( $values, 'owner_password' ); ?>" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" required>
							<p class="hint"><?php echo esc_html__( 'At least 8 characters. You will be signed in right after installation.', 'peakurl' ); ?></p>
						</div>
					</div>
					<div class="actions">
						<p class="actions-note"><?php echo esc_html__( 'Database configuration is already saved.', 'peakurl' ); ?></p>
						<button class="btn btn-primary" type="submit">
							<?php echo sprintf( esc_html__( 'Install %s', 'peakurl' ), 'PeakURL' ); ?>
							<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
						</button>
					</div>
				</div>
			</form>
		</div>

		<!-- Footer -->
		<div class="footer">
			<?php echo sprintf( esc_html__( 'Powered by %s', 'peakurl' ), '<a href="https://peakurl.org?utm_source=peakurl_install&utm_medium=installer&utm_campaign=powered_by" target="_blank" rel="noopener noreferrer">PeakURL</a>' ); ?>
		</div>
	</div>
</body>
</html>
