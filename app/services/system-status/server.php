<?php
/**
 * Server status details builder.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Server — build the server section of the system-status payload.
 *
 * @since 1.0.14
 */
class Server {

	/**
	 * Shared system-status context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new server status helper.
	 *
	 * @param Context $context Shared system-status context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Build PHP and server status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function build(): array {
		return array(
			'phpVersion'        => PHP_VERSION,
			'phpSapi'           => PHP_SAPI,
			'operatingSystem'   => PHP_OS_FAMILY,
			'serverSoftware'    => trim(
				(string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ),
			),
			'timezone'          => (string) date_default_timezone_get(),
			'memoryLimit'       => (string) ini_get( 'memory_limit' ),
			'maxExecutionTime'  => (int) ini_get( 'max_execution_time' ),
			'uploadMaxFilesize' => (string) ini_get( 'upload_max_filesize' ),
			'postMaxSize'       => (string) ini_get( 'post_max_size' ),
			'extensions'        => array(
				'intl'     => extension_loaded( 'intl' ),
				'curl'     => extension_loaded( 'curl' ),
				'mbstring' => extension_loaded( 'mbstring' ),
				'openssl'  => extension_loaded( 'openssl' ),
				'pdoMysql' => extension_loaded( 'pdo_mysql' ),
				'zip'      => class_exists( '\ZipArchive' ),
			),
		);
	}
}
