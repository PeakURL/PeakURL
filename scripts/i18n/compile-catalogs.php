#!/usr/bin/env php
<?php
/**
 * Compile PeakURL PO files into MO and dashboard JSON catalogs.
 *
 * @package PeakURL\Scripts\I18n
 */

declare(strict_types=1);

use PeakURL\Scripts\I18n\I18nToolkit;

$root_path = dirname( __DIR__, 2 );
require $root_path . '/app/vendor/autoload.php';
require __DIR__ . '/toolkit.php';

$toolkit  = new I18nToolkit( $root_path );
$compiled = $toolkit->compile( $argv );

fwrite(
	STDOUT,
	sprintf(
		"Compiled %d language pack(s) in %s.\n",
		$compiled,
		$toolkit->get_languages_dir(),
	),
);
