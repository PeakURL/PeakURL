<?php
/**
 * Route configuration exception.
 *
 * @package PeakURL\Core\Errors
 * @since 1.1.2
 */

declare(strict_types=1);

namespace PeakURL\Core\Errors;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Exception thrown when route configuration is invalid.
 *
 * @since 1.1.2
 */
class RouteConfigurationException extends \RuntimeException {
}
