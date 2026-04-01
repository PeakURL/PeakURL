<?php
/**
 * Global runtime helper functions.
 *
 * @package PeakURL\Includes
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Connection;
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Services\Crypto;
use PeakURL\Services\Mailer;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'PeakURL_Mail' ) ) {
	/**
	 * Send an email through the active PeakURL transport.
	 *
	 * Mirrors the role of WordPress `wp_mail()` while keeping PeakURL's
	 * transport settings behind one public helper.
	 *
	 * @param string               $to_email Recipient email address.
	 * @param string               $subject  Email subject line.
	 * @param string               $message  Primary message body.
	 * @param array<string, mixed> $args     Optional send arguments.
	 * @return bool
	 *
	 * @throws \RuntimeException When PeakURL cannot deliver the email.
	 * @since 1.0.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function PeakURL_Mail(
		string $to_email,
		string $subject,
		string $message,
		array $args = array()
	): bool {
		$config     = RuntimeConfig::bootstrap( ABSPATH . 'app' );
		$connection = new Connection( $config );
		$settings   = new SettingsApi( new PeakURL_DB( $connection ) );
		$crypto     = new Crypto( $config );
		$mailer     = new Mailer( $config, $settings, $crypto );
		$to_name    = trim( (string) ( $args['to_name'] ?? '' ) );
		$text       = array_key_exists( 'text_body', $args )
			? (string) $args['text_body']
			: trim( html_entity_decode( strip_tags( $message ), ENT_QUOTES, 'UTF-8' ) );
		$html       = ! empty( $args['html'] )
			? $message
			: nl2br( htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ) );

		$mailer->send( $to_email, $to_name, $subject, $html, $text );

		return true;
	}
}
