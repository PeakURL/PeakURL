#!/usr/bin/env php
<?php
/**
 * Build the PeakURL POT template from PHP and JS source files.
 *
 * @package PeakURL\Scripts\I18n
 */

declare(strict_types=1);

use PeakURL\Scripts\I18n\I18nToolkit;

$root_path = dirname( __DIR__, 2 );
require $root_path . '/app/vendor/autoload.php';
require __DIR__ . '/toolkit.php';

$toolkit = new I18nToolkit( $root_path );
$count   = $toolkit->build_pot();

fwrite(
	STDOUT,
	sprintf(
		"Updated %s with %d strings.\n",
		$toolkit->get_template_path(),
		$count,
	),
);
