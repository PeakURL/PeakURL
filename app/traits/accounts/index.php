<?php
/**
 * Data store account traits index.
 *
 * @package PeakURL\Data
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Traits\Accounts;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AccountsTrait — account methods for Store.
 *
 * Composes the auth, users, security, and shared account helpers into
 * one folder-level entrypoint for the Store facade.
 *
 * @since 1.0.14
 */
trait AccountsTrait {

	use AuthTrait;
	use UsersTrait;
	use SecurityTrait;
	use SupportTrait;
}
