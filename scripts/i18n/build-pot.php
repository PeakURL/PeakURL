#!/usr/bin/env php
<?php
/**
 * Build the PeakURL POT template from PHP and JS source files.
 */

declare(strict_types=1);

use Gettext\Generator\PoGenerator;
use Gettext\Translation;
use Gettext\Translations;

$root_path = dirname( __DIR__, 2 );

require $root_path . '/app/vendor/autoload.php';

$target_path = $root_path . '/content/languages/peakurl.pot';
$scan_roots  = array(
	$root_path . '/app',
	$root_path . '/site',
	$root_path . '/ui',
);
$skip_paths  = array(
	'/vendor/',
	'/node_modules/',
	'/build/',
	'/release/',
	'/backup/',
	'/content/',
);
$extensions  = array(
	'php',
	'js',
	'ts',
	'tsx',
);
$patterns    = array(
	array(
		'type'    => 'singular',
		'pattern' => '/(?P<fn>__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(?P<quote>[\'"])(?P<text>(?:\\\\.|(?!\k<quote>).)*)\k<quote>\s*(?:,\s*(?P<domain_quote>[\'"])(?P<domain>(?:\\\\.|(?!\k<domain_quote>).)*)\k<domain_quote>)?\s*\)/s',
	),
	array(
		'type'    => 'context',
		'pattern' => '/(?P<fn>_x|_ex)\(\s*(?P<text_quote>[\'"])(?P<text>(?:\\\\.|(?!\k<text_quote>).)*)\k<text_quote>\s*,\s*(?P<context_quote>[\'"])(?P<context>(?:\\\\.|(?!\k<context_quote>).)*)\k<context_quote>\s*(?:,\s*(?P<domain_quote>[\'"])(?P<domain>(?:\\\\.|(?!\k<domain_quote>).)*)\k<domain_quote>)?\s*\)/s',
	),
	array(
		'type'    => 'plural',
		'pattern' => '/(?P<fn>_n)\(\s*(?P<single_quote>[\'"])(?P<single>(?:\\\\.|(?!\k<single_quote>).)*)\k<single_quote>\s*,\s*(?P<plural_quote>[\'"])(?P<plural>(?:\\\\.|(?!\k<plural_quote>).)*)\k<plural_quote>\s*,/s',
	),
	array(
		'type'    => 'plural_context',
		'pattern' => '/(?P<fn>_nx)\(\s*(?P<single_quote>[\'"])(?P<single>(?:\\\\.|(?!\k<single_quote>).)*)\k<single_quote>\s*,\s*(?P<plural_quote>[\'"])(?P<plural>(?:\\\\.|(?!\k<plural_quote>).)*)\k<plural_quote>\s*,.*?,\s*(?P<context_quote>[\'"])(?P<context>(?:\\\\.|(?!\k<context_quote>).)*)\k<context_quote>\s*(?:,\s*(?P<domain_quote>[\'"])(?P<domain>(?:\\\\.|(?!\k<domain_quote>).)*)\k<domain_quote>)?\s*\)/s',
	),
);

$translations = Translations::create( 'peakurl', 'en_US' );
$translations->setDescription( 'PeakURL translation template.' );
$translations->getHeaders()->set( 'Project-Id-Version', trim( (string) @file_get_contents( $root_path . '/.version' ) ) ?: '0.0.0' );
$translations->getHeaders()->set( 'Report-Msgid-Bugs-To', 'https://peakurl.org' );
$translations->getHeaders()->set( 'Content-Type', 'text/plain; charset=UTF-8' );
$translations->getHeaders()->set( 'Content-Transfer-Encoding', '8bit' );

foreach ( $scan_roots as $scan_root ) {
	if ( ! is_dir( $scan_root ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator(
			$scan_root,
			FilesystemIterator::SKIP_DOTS,
		),
	);

	foreach ( $iterator as $file_info ) {
		if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
			continue;
		}

		$path          = $file_info->getPathname();
		$relative_path = ltrim( str_replace( $root_path, '', $path ), '/' );
		$extension     = strtolower( (string) $file_info->getExtension() );

		if ( ! in_array( $extension, $extensions, true ) ) {
			continue;
		}

		$should_skip = false;

		foreach ( $skip_paths as $skip_path ) {
			if ( false !== strpos( $path, $skip_path ) ) {
				$should_skip = true;
				break;
			}
		}

		if ( $should_skip ) {
			continue;
		}

		$contents = file_get_contents( $path );

		if ( false === $contents || '' === $contents ) {
			continue;
		}

		foreach ( $patterns as $definition ) {
			$match_count = preg_match_all(
				$definition['pattern'],
				$contents,
				$matches,
				PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
			);

			if ( false === $match_count || 0 === $match_count ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$domain = $match['domain'][0] ?? 'peakurl';

				if ( '' === $domain || 'peakurl' !== $domain ) {
					continue;
				}

				$line = 1 + substr_count(
					substr( $contents, 0, (int) $match[0][1] ),
					"\n",
				);

				switch ( $definition['type'] ) {
					case 'singular':
						$translation = Translation::create(
							null,
							stripcslashes( (string) $match['text'][0] ),
						);
						break;
					case 'context':
						$translation = Translation::create(
							stripcslashes( (string) $match['context'][0] ),
							stripcslashes( (string) $match['text'][0] ),
						);
						break;
					case 'plural':
						$translation = Translation::create(
							null,
							stripcslashes( (string) $match['single'][0] ),
						)->setPlural(
							stripcslashes( (string) $match['plural'][0] ),
						);
						break;
					case 'plural_context':
						$translation = Translation::create(
							stripcslashes( (string) $match['context'][0] ),
							stripcslashes( (string) $match['single'][0] ),
						)->setPlural(
							stripcslashes( (string) $match['plural'][0] ),
						);
						break;
					default:
						continue 2;
				}

				$translation->getReferences()->add( $relative_path, $line );
				$translations->addOrMerge( $translation );
			}
		}
	}
}

if ( ! is_dir( dirname( $target_path ) ) ) {
	mkdir( dirname( $target_path ), 0777, true );
}

( new PoGenerator() )->generateFile( $translations, $target_path );

fwrite(
	STDOUT,
	sprintf(
		"Updated %s with %d strings.\n",
		$target_path,
		count( $translations ),
	),
);
