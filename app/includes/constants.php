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
final class Constants {

	/**
	 * Prevent instantiation; this class only groups internal constants.
	 *
	 * @since 1.2.2
	 */
	private function __construct() {}

	/** Runtime version array key. */
	public const VERSION = 'PEAKURL_VERSION';
	/** Environment key. */
	public const ENV = 'PEAKURL_ENV';
	/** Debug flag key. */
	public const DEBUG = 'PEAKURL_DEBUG';
	/** Site URL key. */
	public const SITE_URL = 'SITE_URL';
	/** Auth key config name. */
	public const AUTH_KEY = 'PEAKURL_AUTH_KEY';
	/** Auth salt config name. */
	public const AUTH_SALT = 'PEAKURL_AUTH_SALT';
	/** Update manifest URL key. */
	public const UPDATE_MANIFEST_URL = 'PEAKURL_UPDATE_MANIFEST_URL';
	/** Content directory key. */
	public const CONTENT_DIR = 'PEAKURL_CONTENT_DIR';
	/** GeoIP database path key. */
	public const GEOIP_DB_PATH = 'PEAKURL_GEOIP_DB_PATH';
	/** Session cookie name key. */
	public const SESSION_COOKIE_NAME = 'SESSION_COOKIE_NAME';
	/** Session lifetime key. */
	public const SESSION_LIFETIME = 'SESSION_LIFETIME';
	/** Session cookie path key. */
	public const SESSION_COOKIE_PATH = 'SESSION_COOKIE_PATH';
	/** Session cookie domain key. */
	public const SESSION_COOKIE_DOMAIN = 'SESSION_COOKIE_DOMAIN';
	/** Session cookie SameSite key. */
	public const SESSION_COOKIE_SAME_SITE = 'SESSION_COOKIE_SAME_SITE';
	/** Session cookie secure-mode key. */
	public const SESSION_COOKIE_SECURE = 'SESSION_COOKIE_SECURE';
	/** Database host key. */
	public const DB_HOST = 'DB_HOST';
	/** Database port key. */
	public const DB_PORT = 'DB_PORT';
	/** Database name key. */
	public const DB_DATABASE = 'DB_DATABASE';
	/** Database username key. */
	public const DB_USERNAME = 'DB_USERNAME';
	/** Database password key. */
	public const DB_PASSWORD = 'DB_PASSWORD';
	/** Database charset key. */
	public const DB_CHARSET = 'DB_CHARSET';
	/** Database table-prefix key. */
	public const DB_PREFIX = 'DB_PREFIX';
	/** Install owner fallback flag key. */
	public const OWNER_FALLBACK = 'PEAKURL_OWNER_FALLBACK';
	/** Install owner first-name key. */
	public const OWNER_FIRST_NAME = 'PEAKURL_OWNER_FIRST_NAME';
	/** Install owner last-name key. */
	public const OWNER_LAST_NAME = 'PEAKURL_OWNER_LAST_NAME';
	/** Install owner username key. */
	public const OWNER_USERNAME = 'PEAKURL_OWNER_USERNAME';
	/** Install owner email key. */
	public const OWNER_EMAIL = 'PEAKURL_OWNER_EMAIL';
	/** Install owner password key. */
	public const OWNER_PASSWORD = 'PEAKURL_OWNER_PASSWORD';
	/** Install site language key. */
	public const SITE_LANGUAGE = 'PEAKURL_SITE_LANGUAGE';
	/** Install site name key. */
	public const WORKSPACE_NAME = 'PEAKURL_WORKSPACE_NAME';
	/** Install site slug key. */
	public const WORKSPACE_SLUG = 'PEAKURL_WORKSPACE_SLUG';

	/** Runtime config keys parsed from config.php and .env files. */
	public const RUNTIME_KEYS = array(
		self::ENV,
		self::DEBUG,
		self::SITE_URL,
		self::AUTH_KEY,
		self::AUTH_SALT,
		self::UPDATE_MANIFEST_URL,
		self::CONTENT_DIR,
		self::GEOIP_DB_PATH,
		self::DB_HOST,
		self::DB_PORT,
		self::DB_DATABASE,
		self::DB_USERNAME,
		self::DB_PASSWORD,
		self::DB_CHARSET,
		self::DB_PREFIX,
		self::SESSION_COOKIE_NAME,
		self::SESSION_LIFETIME,
		self::SESSION_COOKIE_PATH,
		self::SESSION_COOKIE_DOMAIN,
		self::SESSION_COOKIE_SAME_SITE,
		self::SESSION_COOKIE_SECURE,
	);

	/** Install-only keys removed from config.php after setup. */
	public const INSTALL_KEYS = array(
		self::OWNER_FALLBACK,
		self::OWNER_FIRST_NAME,
		self::OWNER_LAST_NAME,
		self::OWNER_USERNAME,
		self::OWNER_EMAIL,
		self::OWNER_PASSWORD,
		self::SITE_LANGUAGE,
		self::WORKSPACE_NAME,
		self::WORKSPACE_SLUG,
	);

	/** Install keys required before the site can bootstrap. */
	public const INSTALL_REQUIRED_KEYS = array(
		self::OWNER_USERNAME,
		self::OWNER_EMAIL,
		self::OWNER_PASSWORD,
		self::WORKSPACE_NAME,
		self::WORKSPACE_SLUG,
	);

	/** Database keys that identify a connection. */
	public const DB_KEYS = array(
		self::DB_HOST,
		self::DB_PORT,
		self::DB_DATABASE,
		self::DB_USERNAME,
		self::DB_PASSWORD,
		self::DB_CHARSET,
		self::DB_PREFIX,
	);

	/** Canonical version file name. */
	public const VERSION_FILE = '.version';
	/** Fallback version string. */
	public const DEFAULT_VERSION = '0.0.0';
	/** Public REST API base path. */
	public const API_BASE_PATH = '/api/v1';
	/** Default update manifest URL. */
	public const DEFAULT_UPDATE_MANIFEST_URL = 'https://api.peakurl.org/v1/update';
	/** Current managed database schema version. */
	public const DB_SCHEMA_VERSION = 7;
	/** Default content directory. */
	public const DEFAULT_CONTENT_DIR = 'content';
	/** Default site locale. */
	public const DEFAULT_LOCALE = 'en_US';
	/** Default site timezone. */
	public const DEFAULT_TIMEZONE = 'UTC';
	/** Default dashboard time format. */
	public const DEFAULT_TIME_FORMAT = '12';
	/** Default GeoIP database path. */
	public const DEFAULT_GEOIP_DB_PATH = 'content/uploads/geoip/GeoLite2-City.mmdb';
	/** Languages directory relative to the content root. */
	public const LANGUAGES_DIRECTORY = 'languages';
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
	/** PeakURL gettext text domain. */
	public const I18N_TEXT_DOMAIN = 'peakurl';
	/** Settings key storing the installed DB schema version. */
	public const SETTING_DB_SCHEMA_VERSION = 'db_schema_version';
	/** Settings key storing the last successful DB schema upgrade time. */
	public const SETTING_DB_SCHEMA_LAST_UPGRADED_AT = 'db_schema_last_upgraded_at';
	/** Settings key storing the last DB schema upgrade error. */
	public const SETTING_DB_SCHEMA_LAST_ERROR = 'db_schema_last_error';
	/** Settings key storing the site favicon metadata payload. */
	public const SETTING_SITE_FAVICON = 'site_favicon_json';
	/** Settings key storing the default social preview image metadata. */
	public const SETTING_SOCIAL_PREVIEW_IMAGE = 'social_preview_image_json';
}
