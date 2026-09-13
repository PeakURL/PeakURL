<?php
/**
 * PHPCBF runner for Composer and lint-staged.
 *
 * PHP_CodeSniffer's phpcbf tool returns exit code 1 when all fixable errors are fixed.
 * In Unix conventions and tools like lint-staged, exit code 1 is treated as a failure,
 * causing git hooks to abort and roll back fixes.
 * This script intercepts exit code 1 and converts it to exit code 0 (success)
 * while preserving exit codes >= 2 (unfixable errors or execution failures).
 *
 * @package PeakURL
 */

$root_dir      = dirname( __DIR__ );
$standard_file = $root_dir . '/phpcs.xml';
$phpcbf_bin    = $root_dir . '/server/vendor/bin/phpcbf';

if ( ! file_exists( $phpcbf_bin ) || ! is_executable( $phpcbf_bin ) ) {
	$phpcbf_bin = 'phpcbf';
}

$args         = array_slice( $argv, 1 );
$has_standard = false;

foreach ( $args as $arg ) {
	if ( str_starts_with( $arg, '--standard=' ) || '--standard' === $arg ) {
		$has_standard = true;
		break;
	}
}

$cmd = array( escapeshellcmd( $phpcbf_bin ) );
if ( ! $has_standard && file_exists( $standard_file ) ) {
	$cmd[] = '--standard=' . escapeshellarg( $standard_file );
}

foreach ( $args as $arg ) {
	$cmd[] = escapeshellarg( $arg );
}

passthru( implode( ' ', $cmd ), $exit_code );

// Code 0: No fixable errors found.
// Code 1: All fixable errors were fixed.
if ( 0 === $exit_code || 1 === $exit_code ) {
	exit( 0 );
}

exit( $exit_code );
