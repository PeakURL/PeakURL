<?php
/**
 * Application logger.
 *
 * Handles writing debug logs to content/debug.log when debug mode is active.
 *
 * @package PeakURL\Core\Logging
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Logging;

use PeakURL\Core\Config\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Logger utility.
 *
 * @since 1.0.0
 */
class Logger {

	/**
	 * Runtime configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Create a new Logger.
	 *
	 * @param array<string, mixed> $config Configuration map.
	 */
	public function __construct( array $config = array() ) {
		$this->config = $config;
	}

	/**
	 * Log a message to debug.log if debug is enabled.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (debug, info, warning, error).
	 * @return void
	 */
	public function log( string $message, string $level = 'info' ): void {
		if ( empty( $this->config[ Constants::DEBUG ] ) ) {
			return;
		}

		$formatted = sprintf(
			'[%s] [%s] %s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message
		);

		error_log( $formatted );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	public function error( string $message ): void {
		$this->log( $message, 'error' );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Debug message.
	 * @return void
	 */
	public function debug( string $message ): void {
		$this->log( $message, 'debug' );
	}
}
