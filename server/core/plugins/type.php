<?php
/**
 * Plugin type definition.
 *
 * Distinguishes regular plugins from must-use plugins.
 *
 * @package PeakURL\Core\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Plugins;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Type — Backed enum for plugin installation categories.
 *
 * @since 1.8.0
 */
enum Type: string {
	case REGULAR  = 'regular';
	case MUST_USE = 'must_use';

	/**
	 * Return the human-readable label for this plugin type.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::REGULAR  => 'Regular',
			self::MUST_USE => 'Must-Use',
		};
	}
}
