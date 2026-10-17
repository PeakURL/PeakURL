<?php
/**
 * Installer form view; values are escaped at their output boundaries.
 *
 * @package PeakURL
 */
use PeakURL\Services\Install\Screen as InstallScreen;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}
$database_step_url = htmlspecialchars(
	InstallScreen::format_url(
		$base_path,
		'/setup-config.php',
		array(
			'step'          => 1,
			'site_language' => $values['site_language'],
		)
	),
	ENT_QUOTES,
	'UTF-8',
);
?>
<?php if ( 0 === $step ) : ?>
	<div class="language-picker">
		<form method="get" action="<?php echo htmlspecialchars( InstallScreen::format_url( $base_path, '/setup-config.php' ), ENT_QUOTES, 'UTF-8' ); ?>" class="language-picker-form">
			<label class="language-picker-label" for="site_language"><?php echo esc_html__( 'Site language', 'peakurl' ); ?></label>
			<select id="site_language" name="site_language" class="language-picker-select" onchange="this.form.submit()">
				<?php foreach ( $installer_locale->list_languages() as $language ) : ?>
					<?php
					$locale = (string) ( $language['locale'] ?? '' );
					$label  = (string) ( $language['label'] ?? $locale );
					?>
					<option value="<?php echo htmlspecialchars( $locale, ENT_QUOTES, 'UTF-8' ); ?>" <?php echo $values['site_language'] === $locale ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<noscript><button class="btn btn-ghost" type="submit"><?php echo esc_html__( 'Apply language', 'peakurl' ); ?></button></noscript>
		</form>
	</div>
<?php endif; ?>

<?php if ( 0 === $step ) : ?>

	<header class="installer-form-heading">
		<?php /* translators: %s: Application name. */ ?>
		<h1 id="page-heading"><?php echo sprintf( esc_html__( 'Welcome to %s', 'peakurl' ), 'PeakURL' ); ?></h1>
		<p class="desc">
			<?php echo esc_html__( 'Have the following database details ready. PeakURL will check the connection before saving your configuration.', 'peakurl' ); ?>
		</p>
	</header>

	<div class="divider"></div>

	<ul class="checklist">
		<li>
			<span class="check-icon">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
			</span>
			<?php echo esc_html__( 'Database name', 'peakurl' ); ?>
		</li>
		<li>
			<span class="check-icon">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
			</span>
			<?php echo esc_html__( 'Username and password', 'peakurl' ); ?>
		</li>
		<li>
			<span class="check-icon">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="8" x="2" y="3" rx="2"/><rect width="20" height="8" x="2" y="13" rx="2"/><path d="M6 7h.01M6 17h.01"/></svg>
			</span>
			<?php echo esc_html__( 'Host and port', 'peakurl' ); ?>
		</li>
		<li>
			<span class="check-icon">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
			</span>
			<?php echo esc_html__( 'Table prefix', 'peakurl' ); ?> <span class="muted">(<?php echo esc_html__( 'default: peakurl_', 'peakurl' ); ?>)</span>
		</li>
	</ul>

	<div class="info-note">
		<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
		<?php echo esc_html__( 'Your hosting provider or database administrator can provide these details.', 'peakurl' ); ?>
	</div>

	<div class="actions">
		<a class="btn btn-primary" href="<?php echo $database_step_url; ?>">
			<?php echo esc_html__( 'Begin setup', 'peakurl' ); ?>
			<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
		</a>
	</div>

<?php else : ?>

	<header class="installer-form-heading">
		<h1 id="page-heading"><?php echo esc_html__( 'Database connection', 'peakurl' ); ?></h1>
		<p class="desc">
			<?php echo esc_html__( 'Enter your MySQL or MariaDB connection details. PeakURL will verify the connection and save the settings in config.php.', 'peakurl' ); ?>
		</p>
	</header>

	<?php if ( '' !== $error_message ) : ?>
		<div class="error-box" role="alert">
			<div class="bang">!</div>
			<p><?php echo htmlspecialchars( $error_message, ENT_QUOTES, 'UTF-8' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo $database_step_url; ?>">
		<input type="hidden" name="site_url" value="<?php echo InstallScreen::get_escaped_value( $values, 'site_url' ); ?>">
		<input type="hidden" name="site_language" value="<?php echo InstallScreen::get_escaped_value( $values, 'site_language' ); ?>">
		<input type="hidden" name="browser_timezone" id="browser_timezone" value="<?php echo InstallScreen::get_escaped_value( $values, 'browser_timezone' ); ?>">
		<?php if ( ! empty( $values['site_timezone'] ) ) : ?>
			<input type="hidden" name="site_timezone" id="site_timezone" value="<?php echo InstallScreen::get_escaped_value( $values, 'site_timezone' ); ?>">
		<?php endif; ?>
		<div class="form-body">
			<div class="divider"></div>
			<p class="form-section-label"><?php echo esc_html__( 'Connection details', 'peakurl' ); ?></p>
			<div class="grid">
				<div class="field">
					<label for="db_name"><?php echo esc_html__( 'Database name', 'peakurl' ); ?></label>
					<input id="db_name" name="db_name" type="text" value="<?php echo InstallScreen::get_escaped_value( $values, 'db_name' ); ?>" placeholder="peakurl" required>
				</div>
				<div class="field">
					<label for="db_user"><?php echo esc_html__( 'Username', 'peakurl' ); ?></label>
					<input id="db_user" name="db_user" type="text" value="<?php echo InstallScreen::get_escaped_value( $values, 'db_user' ); ?>" placeholder="db_user" required>
				</div>
				<div class="field">
					<label for="db_password"><?php echo esc_html__( 'Password', 'peakurl' ); ?></label>
					<input id="db_password" name="db_password" type="password" autocomplete="off" value="<?php echo InstallScreen::get_escaped_value( $values, 'db_password' ); ?>" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;">
				</div>
				<div class="field">
					<label for="db_host"><?php echo esc_html__( 'Host', 'peakurl' ); ?></label>
					<input id="db_host" name="db_host" type="text" value="<?php echo InstallScreen::get_escaped_value( $values, 'db_host' ); ?>" placeholder="localhost" required>
				</div>
			</div>
			<div class="divider"></div>
			<p class="form-section-label"><?php echo esc_html__( 'Advanced', 'peakurl' ); ?></p>
			<div class="grid">
				<div class="field">
					<label for="db_port"><?php echo esc_html__( 'Port', 'peakurl' ); ?></label>
					<input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?php echo InstallScreen::get_escaped_value( $values, 'db_port' ); ?>" required>
				</div>
				<div class="field">
					<label for="db_prefix"><?php echo esc_html__( 'Table prefix', 'peakurl' ); ?></label>
					<input id="db_prefix" name="db_prefix" type="text" value="<?php echo InstallScreen::get_escaped_value( $values, 'db_prefix' ); ?>" required>
					<p class="hint"><?php echo esc_html__( 'Use letters, numbers, and underscores. The default is peakurl_.', 'peakurl' ); ?></p>
				</div>
			</div>
			<div class="actions">
				<a class="btn btn-ghost" href="<?php echo htmlspecialchars( InstallScreen::format_url( $base_path, '/setup-config.php', array( 'site_language' => $values['site_language'] ) ), ENT_QUOTES, 'UTF-8' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
					<?php echo esc_html__( 'Back', 'peakurl' ); ?>
				</a>
				<button class="btn btn-primary" type="submit">
					<?php echo esc_html__( 'Save and continue', 'peakurl' ); ?>
					<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
				</button>
			</div>
		</div>
	</form>

<?php endif; ?>
