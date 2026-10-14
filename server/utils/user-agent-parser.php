<?php
/**
 * User-Agent parser adapter backed by Matomo DeviceDetector.
 *
 * Classifies incoming HTTP User-Agent strings into normalized browser,
 * operating system, and device categories.
 *
 * @package PeakURL\Utils
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Utils;

use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Universal User-Agent parser with safe normalization and fail-safe fallbacks.
 *
 * @since 1.7.2
 */
class UserAgentParser {

	/**
	 * Shared cache adapter instance.
	 *
	 * @var UserAgentCacheAdapter|null
	 */
	private static ?UserAgentCacheAdapter $cache_adapter = null;

	/**
	 * Maximum allowed length for incoming raw User-Agent headers to protect against ReDoS.
	 */
	private const MAX_USER_AGENT_LENGTH = 2048;

	/**
	 * Maximum length for stored classification columns VARCHAR(64).
	 */
	private const MAX_FIELD_LENGTH = 64;

	/**
	 * Fallback classification when browser cannot be positively identified.
	 */
	public const FALLBACK_BROWSER = 'Browser';

	/**
	 * Fallback classification when operating system cannot be positively identified.
	 */
	public const FALLBACK_OS = 'Unknown OS';

	/**
	 * Fallback classification when device type cannot be positively identified.
	 */
	public const FALLBACK_DEVICE = 'Unknown Device';

	/**
	 * Extract normalized browser, operating system, and device type from a User-Agent header.
	 *
	 * @param string $user_agent Raw User-Agent header value.
	 * @return array{browser: string, os: string, device: string}
	 * @since 1.7.2
	 */
	public static function parse( string $user_agent ): array {
		$trimmed = trim( $user_agent );

		// Reject empty input or oversized headers exceeding the maximum threshold.
		if ( '' === $trimmed || strlen( $trimmed ) > self::MAX_USER_AGENT_LENGTH ) {
			return array(
				'browser' => self::FALLBACK_BROWSER,
				'os'      => self::FALLBACK_OS,
				'device'  => self::FALLBACK_DEVICE,
			);
		}

		try {
			$detector = new DeviceDetector( $trimmed );
			$detector->setCache( self::get_cache() );
			$detector->discardBotInformation( true );
			$detector->parse();

			return array(
				'browser' => self::normalize_browser( $detector ),
				'os'      => self::normalize_os( $detector ),
				'device'  => self::normalize_device( $detector ),
			);
		} catch ( \Throwable ) {
			// Fail safe without exposing unparsed headers or interrupting requests.
			return array(
				'browser' => self::FALLBACK_BROWSER,
				'os'      => self::FALLBACK_OS,
				'device'  => self::FALLBACK_DEVICE,
			);
		}
	}

	/**
	 * Normalize browser label to canonical PeakURL naming.
	 *
	 * @param DeviceDetector $detector Parsed device detector instance.
	 * @return string
	 */
	private static function normalize_browser( DeviceDetector $detector ): string {
		if ( $detector->isBot() ) {
			return self::FALLBACK_BROWSER;
		}

		$client = $detector->getClient();
		if ( ! is_array( $client ) || 'browser' !== ( $client['type'] ?? '' ) ) {
			return self::FALLBACK_BROWSER;
		}

		$raw_name = trim( (string) ( $client['name'] ?? '' ) );
		if ( '' === $raw_name ) {
			return self::FALLBACK_BROWSER;
		}

		// Exact mappings for canonical browser families recognized by Matomo.
		static $browser_map = array(
			// Samsung Internet
			'Samsung Browser'           => 'Samsung Internet',
			'Samsung Browser Lite'      => 'Samsung Internet',

			// Microsoft Edge
			'Microsoft Edge'            => 'Edge',
			'Edge WebView'              => 'Edge',

			// Opera
			'Opera'                     => 'Opera',
			'Opera Mobile'              => 'Opera',
			'Opera Mini'                => 'Opera',
			'Opera Mini iOS'            => 'Opera',
			'Opera Touch'               => 'Opera',
			'Opera GX'                  => 'Opera',
			'Opera Neon'                => 'Opera',
			'Opera Devices'             => 'Opera',
			'Opera Next'                => 'Opera',
			'Opera Crypto'              => 'Opera',
			'Opera Air'                 => 'Opera',

			// Chrome
			'Chrome'                    => 'Chrome',
			'Chrome Mobile'             => 'Chrome',
			'Chrome Mobile iOS'         => 'Chrome',
			'Chrome Webview'            => 'Chrome',
			'Headless Chrome'           => 'Chrome',
			'Chrome Frame'              => 'Chrome',
			'ChromePlus'                => 'Chrome',

			// Firefox
			'Firefox'                   => 'Firefox',
			'Firefox Mobile'            => 'Firefox',
			'Firefox Mobile iOS'        => 'Firefox',
			'Firefox Focus'             => 'Firefox',
			'Firefox Klar'              => 'Firefox',
			'Firefox Reality'           => 'Firefox',
			'Firefox Rocket'            => 'Firefox',

			// Safari
			'Safari'                    => 'Safari',
			'Mobile Safari'             => 'Safari',
			'Safari Technology Preview' => 'Safari',
		);

		if ( isset( $browser_map[ $raw_name ] ) ) {
			return $browser_map[ $raw_name ];
		}

		return substr( $raw_name, 0, self::MAX_FIELD_LENGTH );
	}

	/**
	 * Normalize operating system label to canonical PeakURL naming.
	 *
	 * @param DeviceDetector $detector Parsed device detector instance.
	 * @return string
	 */
	private static function normalize_os( DeviceDetector $detector ): string {
		if ( $detector->isBot() ) {
			return self::FALLBACK_OS;
		}

		$os_data = $detector->getOs();
		if ( ! is_array( $os_data ) ) {
			return self::FALLBACK_OS;
		}

		$raw_os    = trim( (string) ( $os_data['name'] ?? '' ) );
		$os_family = trim( (string) ( $os_data['family'] ?? '' ) );

		if ( 'iPadOS' === $raw_os ) {
			return 'iPadOS';
		}

		if ( 'iOS' === $raw_os || 'iOS' === $os_family ) {
			return 'iOS';
		}

		if ( 'Android' === $raw_os || 'Android' === $os_family ) {
			return 'Android';
		}

		if ( 'Mac' === $raw_os || 'Mac' === $os_family ) {
			return 'macOS';
		}

		if ( 'Windows' === $raw_os || 'Windows' === $os_family ) {
			return 'Windows';
		}

		if ( 'GNU/Linux' === $raw_os || 'GNU/Linux' === $os_family || 'Linux' === $raw_os ) {
			return 'Linux';
		}

		if ( 'Chrome OS' === $raw_os || 'Chrome OS' === $os_family ) {
			return 'Chrome OS';
		}

		if ( '' !== $raw_os && 'UNK' !== $raw_os ) {
			return substr( $raw_os, 0, self::MAX_FIELD_LENGTH );
		}

		return self::FALLBACK_OS;
	}

	/**
	 * Normalize device category to canonical PeakURL naming.
	 *
	 * @param DeviceDetector $detector Parsed device detector instance.
	 * @return string
	 */
	private static function normalize_device( DeviceDetector $detector ): string {
		if ( $detector->isBot() ) {
			return self::FALLBACK_DEVICE;
		}

		$device_type = $detector->getDevice();

		if ( AbstractDeviceParser::DEVICE_TYPE_TABLET === $device_type ) {
			return 'Tablet';
		}

		if ( in_array(
			$device_type,
			array(
				AbstractDeviceParser::DEVICE_TYPE_SMARTPHONE,
				AbstractDeviceParser::DEVICE_TYPE_FEATURE_PHONE,
				AbstractDeviceParser::DEVICE_TYPE_PHABLET,
				AbstractDeviceParser::DEVICE_TYPE_PORTABLE_MEDIA_PAYER,
				AbstractDeviceParser::DEVICE_TYPE_WEARABLE,
			),
			true
		) ) {
			return 'Mobile';
		}

		if ( in_array(
			$device_type,
			array(
				AbstractDeviceParser::DEVICE_TYPE_TV,
				AbstractDeviceParser::DEVICE_TYPE_SMART_DISPLAY,
			),
			true
		) ) {
			return 'TV';
		}

		if ( AbstractDeviceParser::DEVICE_TYPE_CONSOLE === $device_type ) {
			return 'Console';
		}

		if ( AbstractDeviceParser::DEVICE_TYPE_DESKTOP === $device_type || $detector->isDesktop() ) {
			return 'Desktop';
		}

		if ( $detector->isMobile() ) {
			$raw_os = (string) $detector->getOs( 'name' );
			return 'iPadOS' === $raw_os ? 'Tablet' : 'Mobile';
		}

		return self::FALLBACK_DEVICE;
	}

	/**
	 * Retrieve the shared cache adapter.
	 *
	 * @return UserAgentCacheAdapter
	 */
	private static function get_cache(): UserAgentCacheAdapter {
		if ( null === self::$cache_adapter ) {
			self::$cache_adapter = new UserAgentCacheAdapter();
		}

		return self::$cache_adapter;
	}
}
