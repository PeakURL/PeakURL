#!/usr/bin/env php
<?php
/**
 * Compile PeakURL PO files into MO and dashboard JSON catalogs.
 */

declare(strict_types=1);

use Gettext\Generator\MoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Languages\Language as GettextLanguage;

$root_path = dirname( __DIR__, 2 );

require $root_path . '/app/vendor/autoload.php';

$languages_dir = $root_path . '/content/languages';
$po_files      = glob( $languages_dir . '/peakurl-*.po' ) ?: array();
$compiled      = 0;

foreach ( $po_files as $po_file ) {
	if (
		! is_string( $po_file ) ||
		'.pot' === substr( $po_file, -4 )
	) {
		continue;
	}

	if ( ! preg_match( '/peakurl-([A-Za-z0-9_]+)\.po$/', $po_file, $matches ) ) {
		continue;
	}

	$locale       = (string) $matches[1];
	$translations = ( new PoLoader() )->loadFile( $po_file );
	$language     = GettextLanguage::getById( $locale ) ?: GettextLanguage::getById(
		strstr( $locale, '_', true ) ?: $locale,
	);
	$plural_forms = $translations->getHeaders()->get( 'Plural-Forms' );

	if ( '' === (string) $translations->getHeaders()->get( 'Language' ) ) {
		$translations->getHeaders()->set( 'Language', $locale );
	}

	$translations->setDomain( 'peakurl' );

	if ( '' === (string) $plural_forms ) {
		$plural_forms = $language
			? sprintf(
				'nplurals=%d; plural=%s;',
				count( $language->categories ),
				$language->buildFormula( true ),
			)
			: 'nplurals=2; plural=(n != 1);';
		$translations->getHeaders()->set( 'Plural-Forms', $plural_forms );
	}

	$messages = array(
		'' => array(
			'domain'       => 'peakurl',
			'lang'         => $locale,
			'plural-forms' => (string) $plural_forms,
		),
	);

	foreach ( $translations as $translation ) {
		$original = (string) $translation->getOriginal();

		if ( '' === $original || $translation->isDisabled() ) {
			continue;
		}

		$key = null !== $translation->getContext()
			? (string) $translation->getContext() . "\004" . $original
			: $original;

		if ( null !== $translation->getPlural() ) {
			$values = array_values(
				array_filter(
					array_map(
						static fn( $value ): string => (string) $value,
						$translation->getPluralTranslations(),
					),
					static fn( string $value ): bool => '' !== $value,
				),
			);
		} else {
			$value  = (string) $translation->getTranslation();
			$values = '' !== $value ? array( $value ) : array();
		}

		if ( empty( $values ) ) {
			continue;
		}

		$messages[ $key ] = $values;
	}

	$mo_path   = str_replace( '.po', '.mo', $po_file );
	$json_path = str_replace( '.po', '.json', $po_file );

	( new MoGenerator() )->generateFile( $translations, $mo_path );
	file_put_contents(
		$json_path,
		json_encode(
			array(
				'translation-revision-date' => gmdate( 'Y-m-d H:iO' ),
				'generator'                 => 'PeakURL i18n compiler',
				'domain'                    => 'peakurl',
				'locale_data'               => array(
					'messages' => $messages,
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		) . PHP_EOL,
	);
	++$compiled;
}

fwrite(
	STDOUT,
	sprintf(
		"Compiled %d language pack(s) in %s.\n",
		$compiled,
		$languages_dir,
	),
);
