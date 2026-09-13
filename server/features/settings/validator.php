<?php
/**
 * Settings input validator.
 *
 * Validates and normalizes settings input payloads for general configuration,
 * localization, time formats, and caching.
 *
 * @package PeakURL\Features\Settings
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Settings;

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Services\I18n;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Validator — Settings validation and normalization engine.
 *
 * @since 1.0.0
 */
class Validator {

	/**
	 * Normalize the site tagline used by default link previews.
	 *
	 * @param mixed $value Submitted tagline value.
	 * @return string Normalized tagline.
	 * @since 1.0.0
	 */
	public function normalize_tagline( $value ): string {
		$tagline = trim( (string) $value );

		if ( '' === $tagline ) {
			return __( 'Shorten, track, and own every link - PeakURL', 'peakurl' );
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $tagline, 'UTF-8' ) > 300 ) {
			return mb_substr( $tagline, 0, 300, 'UTF-8' );
		}

		return strlen( $tagline ) > 300 ? substr( $tagline, 0, 300 ) : $tagline;
	}

	/**
	 * Normalize a dashboard timezone setting.
	 *
	 * @param string $timezone            Submitted timezone identifier.
	 * @param bool   $fallback_on_invalid Whether invalid stored values should fall back.
	 * @return string Validated timezone.
	 *
	 * @throws ApiException When the timezone is invalid and fallback is false.
	 * @since 1.0.0
	 */
	public function normalize_timezone(
		string $timezone,
		bool $fallback_on_invalid = false
	): string {
		$timezone = trim( $timezone );

		if ( '' === $timezone ) {
			return Constants::DEFAULT_TIMEZONE;
		}

		$valid_timezones = \DateTimeZone::listIdentifiers();

		if (
			Constants::DEFAULT_TIMEZONE === $timezone ||
			in_array( $timezone, $valid_timezones, true )
		) {
			return $timezone;
		}

		if ( $fallback_on_invalid ) {
			return Constants::DEFAULT_TIMEZONE;
		}

		throw new ApiException(
			__( 'PeakURL could not find that timezone.', 'peakurl' ),
			422,
		);
	}

	/**
	 * Normalize the dashboard time-format preference.
	 *
	 * @param string $time_format Submitted time format.
	 * @return string '12' or '24'.
	 * @since 1.0.0
	 */
	public function normalize_time_format( string $time_format ): string {
		$time_format = sanitize_key( $time_format );

		if ( in_array( $time_format, array( '12', '24' ), true ) ) {
			return $time_format;
		}

		return Constants::DEFAULT_TIME_FORMAT;
	}

	/**
	 * Normalize the public landing page mode.
	 *
	 * @param mixed $mode Submitted landing page mode.
	 * @return string 'html', 'login', or 'url'.
	 * @since 1.0.0
	 */
	public function normalize_landing_page_mode( $mode ): string {
		$mode = trim( (string) ( $mode ?? 'html' ) );

		if ( in_array( $mode, array( 'login', 'url', 'html' ), true ) ) {
			return $mode;
		}

		return 'html';
	}

	/**
	 * Validate and normalize general settings payload.
	 *
	 * @param array<string, mixed> $payload             Submitted payload.
	 * @param I18n                 $i18n_service        I18n helper.
	 * @param string               $current_timezone    Current stored timezone.
	 * @param string               $current_time_format Current stored time format.
	 * @return array<string, mixed> Normalized settings.
	 *
	 * @throws ApiException When language pack or timezone is invalid.
	 * @since 1.0.0
	 */
	public function validate_general_settings(
		array $payload,
		I18n $i18n_service,
		string $current_timezone,
		string $current_time_format
	): array {
		$site_language = $i18n_service->normalize_locale(
			(string) ( $payload['siteLanguage'] ?? '' ),
		);

		if ( ! $i18n_service->is_locale_available( $site_language ) ) {
			throw new ApiException(
				__( 'PeakURL could not find that language pack.', 'peakurl' ),
				422,
			);
		}

		$site_timezone    = $this->normalize_timezone(
			(string) ( $payload['siteTimezone'] ?? $current_timezone ),
		);
		$site_time_format = $this->normalize_time_format(
			(string) ( $payload['siteTimeFormat'] ?? $current_time_format ),
		);

		return array(
			'siteLanguage'       => $site_language,
			'siteTimezone'       => $site_timezone,
			'siteTimeFormat'     => $site_time_format,
			'siteName'           => trim( (string) ( $payload['siteName'] ?? '' ) ),
			'siteTagline'        => $this->normalize_tagline( $payload['siteTagline'] ?? '' ),
			'landingPageMode'    => $this->normalize_landing_page_mode( $payload['landingPageMode'] ?? null ),
			'landingPageUrl'     => trim( (string) ( $payload['landingPageUrl'] ?? '' ) ),
			'trashRetentionDays' => isset( $payload['trashRetentionDays'] )
				? max( 0, (int) $payload['trashRetentionDays'] )
				: null,
		);
	}
}
