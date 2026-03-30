<?php
/**
 * PeakURL base configuration file.
 *
 * This file contains the bootstrap settings required to run PeakURL.
 *
 * Database credentials, the site URL, and authentication keys belong here.
 * Dashboard-managed settings are stored in the database and should not be
 * added to this file.
 *
 * @package PeakURL\Site
 * @since 1.0.0
 */

/*
 * Database settings.
 *
 * You can get this information from your hosting provider.
 */
/**
 * The name of the database for PeakURL.
 */
define( 'DB_DATABASE', __DB_DATABASE__ );

/** Database username. */
define( 'DB_USERNAME', __DB_USERNAME__ );

/** Database password. */
define( 'DB_PASSWORD', __DB_PASSWORD__ );

/** Database hostname. */
define( 'DB_HOST', __DB_HOST__ );

/** Database port. */
define( 'DB_PORT', __DB_PORT__ );

/*
 * Database table prefix.
 *
 * You can have multiple PeakURL installs in one database when each install
 * uses a unique table prefix. Use only letters, numbers, and underscores.
 */
$table_prefix = __DB_PREFIX__;

/*
 * Site settings.
 */
/**
 * The full installed site URL.
 *
 * Use the final public URL for this PeakURL install.
 */
define( 'SITE_URL', __SITE_URL__ );

/*
 * Authentication keys and salts.
 *
 * These values are generated automatically during setup and are used to sign
 * session cookies and protect encrypted dashboard credentials. Changing
 * either value invalidates existing sessions and encrypted saved credentials.
 */
/** Unique key used for authentication and encryption. */
define( 'PEAKURL_AUTH_KEY', __PEAKURL_AUTH_KEY__ );

/** Unique salt paired with the authentication key. */
define( 'PEAKURL_AUTH_SALT', __PEAKURL_AUTH_SALT__ );

/*
 * Application environment settings.
 */
/**
 * PeakURL environment type.
 *
 * Typical values are 'production', 'staging', or 'development'.
 */
define( 'PEAKURL_ENV', __PEAKURL_ENV__ );

/*
 * Debug mode.
 *
 * Set this to true to write PHP errors and caught exceptions to
 * `content/debug.log`.
 */
/**
 * Whether PeakURL debug mode is enabled.
 */
define( 'PEAKURL_DEBUG', __PEAKURL_DEBUG__ );
