<?php
/**
 * Shared internal constant names and default values.
 *
 * Keeps repeated config keys and runtime defaults in one place so internal
 * refactors do not require string-by-string updates across the application.
 *
 * The tracked root `.version` file remains the canonical version source for
 * build and release automation.
 *
 * @package PeakURL\Includes
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Includes;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Shared PeakURL runtime constants.
 *
 * @since 1.0.0
 */
class Constants {

	/** Runtime version array key. */
	public const CONFIG_VERSION = 'PEAKURL_VERSION';
	/** Environment key. */
	public const CONFIG_ENV = 'PEAKURL_ENV';
	/** Debug flag key. */
	public const CONFIG_DEBUG = 'PEAKURL_DEBUG';
	/** Site URL key. */
	public const CONFIG_SITE_URL = 'SITE_URL';
	/** Auth key config name. */
	public const CONFIG_AUTH_KEY = 'PEAKURL_AUTH_KEY';
	/** Auth salt config name. */
	public const CONFIG_AUTH_SALT = 'PEAKURL_AUTH_SALT';
	/** Update manifest URL key. */
	public const CONFIG_UPDATE_MANIFEST_URL = 'PEAKURL_UPDATE_MANIFEST_URL';
	/** Content directory key. */
	public const CONFIG_CONTENT_DIR = 'PEAKURL_CONTENT_DIR';
	/** GeoIP database path key. */
	public const CONFIG_GEOIP_DB_PATH = 'PEAKURL_GEOIP_DB_PATH';
	/** Session cookie name key. */
	public const CONFIG_SESSION_COOKIE_NAME = 'SESSION_COOKIE_NAME';
	/** Session lifetime key. */
	public const CONFIG_SESSION_LIFETIME = 'SESSION_LIFETIME';
	/** Session cookie path key. */
	public const CONFIG_SESSION_COOKIE_PATH = 'SESSION_COOKIE_PATH';
	/** Session cookie domain key. */
	public const CONFIG_SESSION_COOKIE_DOMAIN = 'SESSION_COOKIE_DOMAIN';
	/** Session cookie SameSite key. */
	public const CONFIG_SESSION_COOKIE_SAME_SITE = 'SESSION_COOKIE_SAME_SITE';
	/** Session cookie secure-mode key. */
	public const CONFIG_SESSION_COOKIE_SECURE = 'SESSION_COOKIE_SECURE';

	/** Canonical version file name. */
	public const VERSION_FILE = '.version';
	/** Fallback version string. */
	public const DEFAULT_VERSION = '0.0.0';
	/** Default update manifest URL. */
	public const DEFAULT_UPDATE_MANIFEST_URL = 'https://api.peakurl.org/v1/update';
	/** Default content directory. */
	public const DEFAULT_CONTENT_DIR = 'content';
	/** Default GeoIP database path. */
	public const DEFAULT_GEOIP_DB_PATH = 'content/uploads/geoip/GeoLite2-City.mmdb';
	/** Default session cookie name. */
	public const DEFAULT_SESSION_COOKIE_NAME = 'peakurl_session';
	/** Default session lifetime in seconds. */
	public const DEFAULT_SESSION_LIFETIME = 2592000;
	/** Default SameSite value. */
	public const DEFAULT_SESSION_COOKIE_SAME_SITE = 'Strict';
	/** Default secure-cookie mode. */
	public const DEFAULT_SESSION_COOKIE_SECURE = 'auto';
	/** Debug log filename. */
	public const DEBUG_LOG_FILE = 'debug.log';
}
