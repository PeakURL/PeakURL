#!/usr/bin/env php
<?php
/**
 * Unified CLI runner for PeakURL i18n operations.
 *
 * Usage:
 *   php scripts/i18n/cli.php pot
 *   php scripts/i18n/cli.php update [--locale=<code>]
 *   php scripts/i18n/cli.php compile [--locale=<code>]
 *   php scripts/i18n/cli.php build [--locale=<code>]
 *
 * @package PeakURL\Scripts\I18n
 */

declare(strict_types=1);

use PeakURL\Scripts\I18n\I18nToolkit;

$root_path = dirname( __DIR__, 2 );
require $root_path . '/app/vendor/autoload.php';
require __DIR__ . '/toolkit.php';

$toolkit = new I18nToolkit( $root_path );
$action  = $argv[1] ?? 'help';

switch ( $action ) {
	case 'pot':
		$count = $toolkit->build_pot();
		fwrite( STDOUT, sprintf( "Updated %s with %d strings.\n", $toolkit->get_template_path(), $count ) );
		break;

	case 'update':
	case 'update-po':
		$passthru_args = array_merge( array( $argv[0] ), array_slice( $argv, 2 ) );
		$stats         = $toolkit->update_po( $passthru_args );
		fwrite(
			STDOUT,
			sprintf(
				"Synchronized %d catalog(s) from %s (%d updated, %d created).\n",
				$stats['total'],
				$toolkit->get_template_path(),
				$stats['updated'],
				$stats['created'],
			),
		);
		break;

	case 'compile':
		$passthru_args = array_merge( array( $argv[0] ), array_slice( $argv, 2 ) );
		$compiled      = $toolkit->compile( $passthru_args );
		fwrite( STDOUT, sprintf( "Compiled %d language pack(s) in %s.\n", $compiled, $toolkit->get_languages_dir() ) );
		break;

	case 'build':
	case 'all':
		$count = $toolkit->build_pot();
		fwrite( STDOUT, sprintf( "Updated %s with %d strings.\n", $toolkit->get_template_path(), $count ) );
		$passthru_args = array_merge( array( $argv[0] ), array_slice( $argv, 2 ) );
		$stats         = $toolkit->update_po( $passthru_args );
		fwrite(
			STDOUT,
			sprintf(
				"Synchronized %d catalog(s) from %s (%d updated, %d created).\n",
				$stats['total'],
				$toolkit->get_template_path(),
				$stats['updated'],
				$stats['created'],
			),
		);
		$compiled = $toolkit->compile( $passthru_args );
		fwrite( STDOUT, sprintf( "Compiled %d language pack(s) in %s.\n", $compiled, $toolkit->get_languages_dir() ) );
		break;

	default:
		fwrite(
			STDOUT,
			"PeakURL i18n Toolkit\n\n" .
			"Usage:\n" .
			"  php scripts/i18n/cli.php pot                    Build the POT template from code\n" .
			"  php scripts/i18n/cli.php update [--locale=code] Synchronize PO catalogs with POT template\n" .
			"  php scripts/i18n/cli.php compile [--locale=code] Compile PO catalogs to MO and JSON\n" .
			"  php scripts/i18n/cli.php build [--locale=code]   Run full extraction, update, and compile pipeline\n",
		);
		break;
}
