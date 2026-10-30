<?php
/**
 * Plugin Name: Example Plugin
 * Description: Demonstrates standard regular plugin packaging for PeakURL.
 * Text Domain: example-plugin
 * Domain Path: /languages
 *
 * @package PeakURL\SamplePlugins
 * @since 1.8.0
 */

declare(strict_types=1);

// Prevent direct script execution.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Register a harmless filter demonstrating the PeakURL hooks API.
 * This callback is executed ONLY when the plugin is loaded at runtime (P4-C),
 * never during P4-B metadata discovery.
 */
if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'peakurl_example_plugin_filter',
		static function ( string $value = '' ): string {
			return 'filtered_by_example_plugin:' . $value;
		},
		10,
		1
	);
}
