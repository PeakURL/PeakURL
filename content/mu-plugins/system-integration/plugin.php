<?php
/**
 * Plugin Name: System Integration
 * Description: Demonstrates must-use (MU) plugin packaging for PeakURL.
 * Text Domain: system-integration
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
 * This callback is executed ONLY when the must-use plugin is bootstrapped at runtime (P4-C),
 * never during P4-B metadata discovery.
 */
if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'peakurl_system_integration_filter',
		static function ( string $value = '' ): string {
			return 'filtered_by_system_integration:' . $value;
		},
		10,
		1
	);
}
