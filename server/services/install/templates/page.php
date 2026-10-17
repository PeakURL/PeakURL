<?php
/**
 * Shared installer document. Variables are prepared by Install\Page.
 *
 * @package PeakURL
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars( $locale->get_html_lang(), ENT_QUOTES, 'UTF-8' ); ?>" dir="<?php echo htmlspecialchars( $locale->get_text_direction(), ENT_QUOTES, 'UTF-8' ); ?>" data-status-theme="auto">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<?php echo get_generator_tag(); ?>
	<title><?php echo htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ); ?></title>
	<style><?php echo $styles; ?></style>
	<script><?php echo $script; ?></script>
</head>
<body class="status-page installer-page">
	<div class="installer-container">
		<header class="installer-header">
			<div class="status-brand"><img width="40" height="40" src="<?php echo htmlspecialchars( $logo, ENT_QUOTES, 'UTF-8' ); ?>" alt=""><span>PeakURL</span></div>
			<button id="installer-theme-toggle" class="installer-theme-toggle" type="button" aria-label="<?php echo esc_attr__( 'Toggle dark mode', 'peakurl' ); ?>" aria-pressed="false">
				<?php // Lucide Sun/Moon geometry matches the React theme toggle; notices ship in assets/LICENSE-Lucide.txt. ?>
				<svg class="installer-theme-icon-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
				<svg class="installer-theme-icon-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/></svg>
			</button>
		</header>
		<main class="installer-main">
			<nav class="installer-progress" aria-label="<?php echo esc_attr__( 'Installation progress', 'peakurl' ); ?>">
				<ol>
					<?php foreach ( $steps as $index => $label ) : ?>
						<li class="installer-step <?php echo $index === $step ? 'is-active' : ( $index < $step ? 'is-done' : '' ); ?>" <?php echo $index === $step ? 'aria-current="step"' : ''; ?>>
							<span class="installer-step-number" aria-hidden="true"><?php echo $index < $step ? '&#10003;' : (string) ( $index + 1 ); ?></span>
							<span><?php echo htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			</nav>
			<div class="installer-panel">
				<aside class="installer-introduction">
					<p class="installer-eyebrow"><?php echo esc_html__( 'Installation', 'peakurl' ); ?></p>
					<h2><?php echo htmlspecialchars( $headings[ $step ], ENT_QUOTES, 'UTF-8' ); ?></h2>
					<p class="installer-introduction-description"><?php echo htmlspecialchars( $descriptions[ $step ], ENT_QUOTES, 'UTF-8' ); ?></p>
					<img class="installer-artwork" data-status-illustration="<?php echo htmlspecialchars( $illustration, ENT_QUOTES, 'UTF-8' ); ?>" src="<?php echo htmlspecialchars( $artwork, ENT_QUOTES, 'UTF-8' ); ?>" width="360" height="280" alt="" aria-hidden="true" draggable="false" oncontextmenu="return false">
					<p class="installer-tagline"><?php echo htmlspecialchars( \PeakURL\Services\Brand::get_tagline(), ENT_QUOTES, 'UTF-8' ); ?></p>
				</aside>
				<section class="installer-content" aria-labelledby="page-heading"><?php echo $content_html; ?></section>
			</div>
		</main>
		<footer class="installer-footer"><?php echo \PeakURL\Services\Brand::get_powered_by_badge(); ?></footer>
	</div>
</body>
</html>
