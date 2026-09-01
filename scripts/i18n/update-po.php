#!/usr/bin/env php
<?php
/**
 * Synchronize PeakURL PO catalogs with the current POT template.
 *
 * @package PeakURL\Scripts\I18n
 */

declare(strict_types=1);

use PeakURL\Scripts\I18n\I18nToolkit;

$root_path = dirname( __DIR__, 2 );
require $root_path . '/app/vendor/autoload.php';
require __DIR__ . '/toolkit.php';

$toolkit = new I18nToolkit( $root_path );
$stats   = $toolkit->update_po( $argv );

if ( 0 === $stats['total'] ) {
	fwrite(
		STDOUT,
		"No PO catalogs found. Pass a locale such as --locale=es_ES to create one.\n",
	);
	exit( 0 );
}

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
