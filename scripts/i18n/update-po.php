#!/usr/bin/env php
<?php
/**
 * Synchronize PeakURL PO catalogs with the current POT template.
 */

declare(strict_types=1);

use Gettext\Generator\PoGenerator;
use Gettext\Languages\Language as GettextLanguage;
use Gettext\Loader\PoLoader;
use Gettext\Merge;
use Gettext\Translations;

$root_path = dirname( __DIR__, 2 );

require $root_path . '/app/vendor/autoload.php';

$languages_dir = $root_path . '/content/languages';
$template_path = $languages_dir . '/peakurl.pot';

if ( ! file_exists( $template_path ) ) {
	fwrite(
		STDERR,
		sprintf(
			"Missing POT template: %s\nRun npm run i18n:pot first.\n",
			$template_path,
		),
	);
	exit( 1 );
}

$requested_locales = parse_requested_locales( $argv );
$targets           = resolve_targets( $requested_locales, $languages_dir );

if ( empty( $targets ) ) {
	fwrite(
		STDOUT,
		"No PO catalogs found. Pass a locale such as --locale=es_ES to create one.\n",
	);
	exit( 0 );
}

$loader    = new PoLoader();
$generator = new PoGenerator();
$template  = $loader->loadFile( $template_path );
$updated   = 0;
$created   = 0;

$template->setDomain( 'peakurl' );

foreach ( $targets as $locale => $po_path ) {
	$already_exists = file_exists( $po_path );
	$catalog        = $already_exists
		? merge_catalog_with_template(
			$template,
			$loader->loadFile( $po_path ),
			$locale,
		)
		: create_catalog_from_template( $template, $locale );

	$generator->generateFile( $catalog, $po_path );

	if ( $already_exists ) {
		++$updated;
	} else {
		++$created;
	}
}

fwrite(
	STDOUT,
	sprintf(
		"Synchronized %d catalog(s) from %s (%d updated, %d created).\n",
		count( $targets ),
		$template_path,
		$updated,
		$created,
	),
);

/**
 * Parse CLI locale arguments.
 *
 * Supports both positional locales and `--locale=<code>`.
 *
 * @param array<int, string> $argv Raw CLI arguments.
 * @return array<int, string>
 */
function parse_requested_locales( array $argv ): array {
	$locales = array();

	foreach ( array_slice( $argv, 1 ) as $argument ) {
		if ( 0 === strpos( $argument, '--locale=' ) ) {
			$argument = substr( $argument, 9 );
		}

		if ( ! is_string( $argument ) || '' === trim( $argument ) ) {
			continue;
		}

		$normalized_locale = normalize_locale_code( $argument );

		if ( '' !== $normalized_locale && ! in_array( $normalized_locale, $locales, true ) ) {
			$locales[] = $normalized_locale;
		}
	}

	return $locales;
}

/**
 * Normalize a locale value to a WordPress-style code.
 *
 * @param string $locale Raw locale.
 * @return string
 */
function normalize_locale_code( string $locale ): string {
	$locale = trim( str_replace( '-', '_', $locale ) );

	if ( '' === $locale ) {
		return '';
	}

	$parts = explode( '_', $locale );

	if ( empty( $parts ) ) {
		return '';
	}

	$parts[0] = strtolower( (string) $parts[0] );

	foreach ( $parts as $index => $part ) {
		if ( 0 === $index ) {
			continue;
		}

		$parts[ $index ] = strlen( $part ) <= 3
			? strtoupper( $part )
			: ucfirst( strtolower( $part ) );
	}

	return implode( '_', $parts );
}

/**
 * Resolve the PO files to update or create.
 *
 * @param array<int, string> $requested_locales Requested locales.
 * @param string             $languages_dir     Absolute languages directory.
 * @return array<string, string>
 */
function resolve_targets( array $requested_locales, string $languages_dir ): array {
	$targets = array();

	if ( ! empty( $requested_locales ) ) {
		foreach ( $requested_locales as $locale ) {
			$targets[ $locale ] = sprintf(
				'%s/peakurl-%s.po',
				$languages_dir,
				$locale,
			);
		}

		return $targets;
	}

	foreach ( glob( $languages_dir . '/peakurl-*.po' ) ?: array() as $po_path ) {
		if (
			! is_string( $po_path ) ||
			'.pot' === substr( $po_path, -4 ) ||
			! preg_match( '/peakurl-([A-Za-z0-9_]+)\.po$/', $po_path, $matches )
		) {
			continue;
		}

		$targets[ (string) $matches[1] ] = $po_path;
	}

	return $targets;
}

/**
 * Merge an existing PO catalog with the current template.
 *
 * Keeps existing translations and translator comments, while refreshing the
 * extracted references and dropping obsolete strings that no longer exist in
 * the template.
 *
 * @param Translations $template Template translations.
 * @param Translations $existing Existing locale catalog.
 * @param string       $locale   Locale identifier.
 * @return Translations
 */
function merge_catalog_with_template(
	Translations $template,
	Translations $existing,
	string $locale
): Translations {
	$strategy = Merge::TRANSLATIONS_OURS
		| Merge::TRANSLATIONS_OVERRIDE
		| Merge::HEADERS_THEIRS
		| Merge::COMMENTS_THEIRS
		| Merge::EXTRACTED_COMMENTS_OURS
		| Merge::REFERENCES_OURS;
	$merged   = $template->mergeWith( $existing, $strategy );

	$merged->setDomain( 'peakurl' );
	apply_locale_headers( $merged, $locale );

	return $merged;
}

/**
 * Create a new locale catalog from the template.
 *
 * @param Translations $template Template translations.
 * @param string       $locale   Locale identifier.
 * @return Translations
 */
function create_catalog_from_template(
	Translations $template,
	string $locale
): Translations {
	$catalog = clone $template;

	$catalog->setDomain( 'peakurl' );
	apply_locale_headers( $catalog, $locale );

	return $catalog;
}

/**
 * Ensure the locale headers are present on a catalog.
 *
 * @param Translations $catalog Catalog to update.
 * @param string       $locale  Locale identifier.
 * @return void
 */
function apply_locale_headers( Translations $catalog, string $locale ): void {
	$catalog->getHeaders()->set( 'Language', $locale );

	$language = resolve_language_info( $locale );

	if ( null !== $language ) {
		$catalog->getHeaders()->set(
			'Plural-Forms',
			sprintf(
				'nplurals=%d; plural=%s;',
				count( $language->categories ),
				$language->buildFormula( true ),
			),
		);
	}
}

/**
 * Resolve locale metadata from the gettext language registry.
 *
 * @param string $locale Locale identifier.
 * @return GettextLanguage|null
 */
function resolve_language_info( string $locale ): ?GettextLanguage {
	$language = GettextLanguage::getById( $locale );

	if ( null !== $language ) {
		return $language;
	}

	$base_locale = strstr( $locale, '_', true );

	if ( false !== $base_locale && '' !== $base_locale ) {
		return GettextLanguage::getById( $base_locale );
	}

	return null;
}
