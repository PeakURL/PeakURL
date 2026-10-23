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
 * @package PeakURL\Core\Config
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Config;

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

	/**
	 * Prevent instantiation; this class only groups internal constants.
	 *
	 * @since 1.2.2
	 */
	private function __construct() {}

	/** Runtime version array key. */
	public const string VERSION = 'PEAKURL_VERSION';
	/** Environment key. */
	public const string ENV = 'PEAKURL_ENV';
	/** Debug flag key. */
	public const string DEBUG = 'PEAKURL_DEBUG';
	/** Site URL key. */
	public const string SITE_URL = 'SITE_URL';
	/** Auth key config name. */
	public const string AUTH_KEY = 'PEAKURL_AUTH_KEY';
	/** Auth salt config name. */
	public const string AUTH_SALT = 'PEAKURL_AUTH_SALT';
	/** Update manifest URL key. */
	public const string UPDATE_MANIFEST_URL = 'PEAKURL_UPDATE_MANIFEST_URL';
	/** Content directory key. */
	public const string CONTENT_DIR = 'PEAKURL_CONTENT_DIR';
	/** GeoIP database path key. */
	public const string GEOIP_DB_PATH = 'PEAKURL_GEOIP_DB_PATH';
	/** Cache enabled flag key. */
	public const string CACHE_ENABLED = 'PEAKURL_CACHE_ENABLED';
	/** Cache driver key (auto, redis, apcu, file, none). */
	public const string CACHE_DRIVER = 'PEAKURL_CACHE_DRIVER';
	/** Cache storage path override key. */
	public const string CACHE_PATH = 'PEAKURL_CACHE_PATH';
	/** Redis host key. */
	public const string REDIS_HOST = 'PEAKURL_REDIS_HOST';
	/** Redis port key. */
	public const string REDIS_PORT = 'PEAKURL_REDIS_PORT';
	/** Redis database index key. */
	public const string REDIS_DATABASE = 'PEAKURL_REDIS_DATABASE';
	/** Redis password/auth key. */
	public const string REDIS_PASSWORD = 'PEAKURL_REDIS_PASSWORD';
	/** Session cookie name key. */
	public const string SESSION_COOKIE_NAME = 'SESSION_COOKIE_NAME';
	/** Session lifetime key. */
	public const string SESSION_LIFETIME = 'SESSION_LIFETIME';
	/** Session cookie path key. */
	public const string SESSION_COOKIE_PATH = 'SESSION_COOKIE_PATH';
	/** Session cookie domain key. */
	public const string SESSION_COOKIE_DOMAIN = 'SESSION_COOKIE_DOMAIN';
	/** Session cookie SameSite key. */
	public const string SESSION_COOKIE_SAME_SITE = 'SESSION_COOKIE_SAME_SITE';
	/** Session cookie secure-mode key. */
	public const string SESSION_COOKIE_SECURE = 'SESSION_COOKIE_SECURE';
	/** Database host key. */
	public const string DB_HOST = 'DB_HOST';
	/** Database port key. */
	public const string DB_PORT = 'DB_PORT';
	/** Database name key. */
	public const string DB_DATABASE = 'DB_DATABASE';
	/** Database username key. */
	public const string DB_USERNAME = 'DB_USERNAME';
	/** Database password key. */
	public const string DB_PASSWORD = 'DB_PASSWORD';
	/** Database charset key. */
	public const string DB_CHARSET = 'DB_CHARSET';
	/** Database table-prefix key. */
	public const string DB_PREFIX = 'DB_PREFIX';
	/** Install owner fallback flag key. */
	public const string OWNER_FALLBACK = 'PEAKURL_OWNER_FALLBACK';
	/** Install owner first-name key. */
	public const string OWNER_FIRST_NAME = 'PEAKURL_OWNER_FIRST_NAME';
	/** Install owner last-name key. */
	public const string OWNER_LAST_NAME = 'PEAKURL_OWNER_LAST_NAME';
	/** Install owner username key. */
	public const string OWNER_USERNAME = 'PEAKURL_OWNER_USERNAME';
	/** Install owner email key. */
	public const string OWNER_EMAIL = 'PEAKURL_OWNER_EMAIL';
	/** Install owner password key. */
	public const string OWNER_PASSWORD = 'PEAKURL_OWNER_PASSWORD';
	/** Install site language key. */
	public const string SITE_LANGUAGE = 'PEAKURL_SITE_LANGUAGE';
	/** Install site timezone key. */
	public const string SITE_TIMEZONE = 'PEAKURL_SITE_TIMEZONE';
	/** Install site name key. */
	public const string WORKSPACE_NAME = 'PEAKURL_WORKSPACE_NAME';
	/** Install site slug key. */
	public const string WORKSPACE_SLUG = 'PEAKURL_WORKSPACE_SLUG';

	/** Runtime config keys parsed from config.php and .env files. */
	public const array RUNTIME_KEYS = array(
		self::ENV,
		self::DEBUG,
		self::SITE_URL,
		self::AUTH_KEY,
		self::AUTH_SALT,
		self::UPDATE_MANIFEST_URL,
		self::CONTENT_DIR,
		self::GEOIP_DB_PATH,
		self::CACHE_ENABLED,
		self::CACHE_DRIVER,
		self::CACHE_PATH,
		self::REDIS_HOST,
		self::REDIS_PORT,
		self::REDIS_DATABASE,
		self::REDIS_PASSWORD,
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
	public const array INSTALL_KEYS = array(
		self::OWNER_FALLBACK,
		self::OWNER_FIRST_NAME,
		self::OWNER_LAST_NAME,
		self::OWNER_USERNAME,
		self::OWNER_EMAIL,
		self::OWNER_PASSWORD,
		self::SITE_LANGUAGE,
		self::SITE_TIMEZONE,
		self::WORKSPACE_NAME,
		self::WORKSPACE_SLUG,
	);

	/** Install keys required before the site can bootstrap. */
	public const array INSTALL_REQUIRED_KEYS = array(
		self::OWNER_USERNAME,
		self::OWNER_EMAIL,
		self::OWNER_PASSWORD,
		self::WORKSPACE_NAME,
		self::WORKSPACE_SLUG,
	);

	/** Database keys that identify a connection. */
	public const array DB_KEYS = array(
		self::DB_HOST,
		self::DB_PORT,
		self::DB_DATABASE,
		self::DB_USERNAME,
		self::DB_PASSWORD,
		self::DB_CHARSET,
		self::DB_PREFIX,
	);

	/** Canonical version file name. */
	public const string VERSION_FILE = '.version';
	/** Fallback version string. */
	public const string DEFAULT_VERSION = '1.0.0';
	/** Public REST API base path. */
	public const string API_BASE_PATH = '/api/v1';
	/** Model Context Protocol (MCP) server endpoint path. */
	public const string MCP_PATH = '/mcp';
	/** Default update manifest URL. */
	public const string DEFAULT_UPDATE_MANIFEST_URL = 'https://api.peakurl.org/v1/update';
	/** Current managed database schema version. */
	public const int DB_SCHEMA_VERSION = 11;
	/** Default content directory. */
	public const string DEFAULT_CONTENT_DIR = 'content';
	/** Default site locale. */
	public const string DEFAULT_LOCALE = 'en_US';
	/** Default site timezone. */
	public const string DEFAULT_TIMEZONE = 'UTC';
	/** Default dashboard time format. */
	public const string DEFAULT_TIME_FORMAT = '12';
	/** Default GeoIP database path. */
	public const string DEFAULT_GEOIP_DB_PATH = 'content/uploads/geoip/GeoLite2-City.mmdb';
	/** Default managed GeoIP manifest URL. */
	public const string DEFAULT_GEOIP_MANIFEST_URL = 'https://releases.peakurl.org/geoip/manifest.json';
	/** Default managed GeoIP archive URL. */
	public const string DEFAULT_GEOIP_ARCHIVE_URL = 'https://releases.peakurl.org/geoip/GeoLite2-City.mmdb.gz';
	/** Languages directory relative to the content root. */
	public const string LANGUAGES_DIRECTORY = 'languages';
	/** Default session cookie name. */
	public const string DEFAULT_SESSION_COOKIE_NAME = 'peakurl_session';
	/** Default session lifetime in seconds. */
	public const int DEFAULT_SESSION_LIFETIME = 2592000;
	/** Default SameSite value. */
	public const string DEFAULT_SESSION_COOKIE_SAME_SITE = 'Strict';
	/** Default secure-cookie mode. */
	public const string DEFAULT_SESSION_COOKIE_SECURE = 'auto';
	/** Debug log filename. */
	public const string DEBUG_LOG_FILE = 'debug.log';
	/** PeakURL gettext text domain. */
	public const string I18N_TEXT_DOMAIN = 'peakurl';
	/** Settings key storing the installed DB schema version. */
	public const string SETTING_DB_SCHEMA_VERSION = 'db_schema_version';
	/** Settings key storing the last successful DB schema upgrade time. */
	public const string SETTING_DB_SCHEMA_LAST_UPGRADED_AT = 'db_schema_last_upgraded_at';
	/** Settings key storing the last DB schema upgrade error. */
	public const string SETTING_DB_SCHEMA_LAST_ERROR = 'db_schema_last_error';
	/** Settings key storing the site favicon metadata payload. */
	public const string SETTING_SITE_FAVICON = 'site_favicon_json';
	/** Settings key storing the default social preview image metadata. */
	public const string SETTING_SOCIAL_PREVIEW_IMAGE = 'social_preview_image_json';
	/** Settings key storing cache enabled status. */
	public const string SETTING_CACHE_ENABLED = 'cache_enabled';
	/** Settings key storing preferred cache driver. */
	public const string SETTING_CACHE_DRIVER = 'cache_driver';
	/** Settings key storing default link cache TTL in seconds. */
	public const string SETTING_CACHE_DEFAULT_TTL = 'cache_default_ttl';
	/** Settings key storing negative cache TTL in seconds. */
	public const string SETTING_CACHE_NEGATIVE_TTL = 'cache_negative_ttl';
	/** Cache format generation version. */
	public const int CACHE_VERSION = 1;
	/** Default cache driver selection mode. */
	public const string CACHE_DEFAULT_DRIVER = 'auto';
	/** Default cache enabled status. */
	public const bool CACHE_DEFAULT_ENABLED = true;
	/** Default public link TTL in seconds (1 hour). */
	public const int CACHE_LINK_TTL = 3600;
	/** Default negative lookup TTL in seconds (60 seconds). */
	public const int CACHE_NEGATIVE_TTL = 60;
	/** Default dashboard query TTL in seconds (60 seconds). */
	public const int CACHE_DASHBOARD_TTL = 60;
	/** Default cache directory relative to content root. */
	public const string CACHE_DIRECTORY = 'cache';
	/** Default Redis port. */
	public const int DEFAULT_REDIS_PORT = 6379;
	/** Default Redis database index. */
	public const int DEFAULT_REDIS_DATABASE = 0;
	/** Settings key storing scheduled jobs execution history retention in days. */
	public const string SETTING_CRON_HISTORY_RETENTION_DAYS = 'cron_history_retention_days';
	/** Default scheduled jobs execution history retention in days (30 days). */
	public const int DEFAULT_CRON_HISTORY_RETENTION_DAYS = 30;
	/** Settings key storing persisted custom role capabilities JSON. */
	public const string SETTING_ROLE_CAPABILITIES = 'role_capabilities';
	/** Settings key storing whether MCP server is enabled. */
	public const string SETTING_MCP_ENABLED = 'mcp_enabled';
	/** Settings key storing whether MCP write tools are enabled. */
	public const string SETTING_MCP_WRITE_ENABLED = 'mcp_write_enabled';
	/** Settings key storing allowed redirect URIs JSON for MCP OAuth. */
	public const string SETTING_MCP_ALLOWED_REDIRECT_URIS = 'mcp_allowed_redirect_uris';
	/** Default MCP server enabled state (disabled by default). */
	public const bool DEFAULT_MCP_ENABLED = false;
	/** Default MCP write tools enabled state (disabled by default). */
	public const bool DEFAULT_MCP_WRITE_ENABLED = false;
}
