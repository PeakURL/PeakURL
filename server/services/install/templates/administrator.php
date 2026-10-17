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
?>
<header class="installer-form-heading">
	<h1 id="page-heading"><?php echo esc_html__( 'Create your account', 'peakurl' ); ?></h1>
	<p class="desc">
		<?php echo esc_html__( 'Enter a site title and administrator credentials. PeakURL will sign you in when installation is complete.', 'peakurl' ); ?>
	</p>
</header>

<?php if ( '' !== $error_message ) : ?>
	<div class="error-box" role="alert">
		<div class="bang">!</div>
		<p><?php echo htmlspecialchars( $error_message, ENT_QUOTES, 'UTF-8' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo htmlspecialchars( InstallScreen::format_url( $base_path, '/install.php' ), ENT_QUOTES, 'UTF-8' ); ?>" novalidate>
	<input type="hidden" name="site_language" value="<?php echo InstallScreen::get_escaped_value( $values, 'site_language' ); ?>">
	<input type="hidden" name="browser_timezone" id="browser_timezone" value="<?php echo InstallScreen::get_escaped_value( $values, 'browser_timezone' ); ?>">
	<?php if ( ! empty( $values['site_timezone'] ) ) : ?>
		<input type="hidden" name="site_timezone" id="site_timezone" value="<?php echo InstallScreen::get_escaped_value( $values, 'site_timezone' ); ?>">
	<?php endif; ?>
	<div class="form-body">
		<div class="divider"></div>
		<p class="form-section-label"><?php echo esc_html__( 'Site details', 'peakurl' ); ?></p>
		<div class="grid">
			<div class="field full">
				<label for="workspace_name"><?php echo esc_html__( 'Site title', 'peakurl' ); ?></label>
				<input id="workspace_name" name="workspace_name" type="text" value="<?php echo InstallScreen::get_escaped_value( $values, 'workspace_name' ); ?>" placeholder="<?php echo esc_attr__( 'My Link Hub', 'peakurl' ); ?>" required>
			</div>
		</div>
		<div class="divider"></div>
		<p class="form-section-label"><?php echo esc_html__( 'Administrator', 'peakurl' ); ?></p>
		<div class="grid">
			<div class="field">
				<label for="owner_username"><?php echo esc_html__( 'Username', 'peakurl' ); ?></label>
				<input id="owner_username" name="owner_username" type="text" value="<?php echo InstallScreen::get_escaped_value( $values, 'owner_username' ); ?>" placeholder="admin" required>
				<p class="hint"><?php echo esc_html__( 'Use letters, numbers, periods, hyphens, underscores, or @.', 'peakurl' ); ?></p>
			</div>
			<div class="field">
				<label for="owner_email"><?php echo esc_html__( 'Email', 'peakurl' ); ?></label>
				<input id="owner_email" name="owner_email" type="email" value="<?php echo InstallScreen::get_escaped_value( $values, 'owner_email' ); ?>" placeholder="<?php echo esc_attr__( 'you@company.com', 'peakurl' ); ?>" required>
			</div>
			<div class="field full">
				<label for="owner_password"><?php echo esc_html__( 'Password', 'peakurl' ); ?></label>
				<input id="owner_password" name="owner_password" type="password" autocomplete="new-password" value="<?php echo InstallScreen::get_escaped_value( $values, 'owner_password' ); ?>" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" required>
				<p class="hint"><?php echo esc_html__( 'Use at least 8 characters.', 'peakurl' ); ?></p>
			</div>
		</div>
		<div class="actions">
			<p class="actions-note"><?php echo esc_html__( 'Your database connection is configured.', 'peakurl' ); ?></p>
			<button class="btn btn-primary" type="submit">
				<?php /* translators: %s: Application name. */ ?>
				<?php echo sprintf( esc_html__( 'Install %s', 'peakurl' ), 'PeakURL' ); ?>
				<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
			</button>
		</div>
	</div>
</form>
