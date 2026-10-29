<?php
/**
 * Canonical plugin manifest validator.
 *
 * Enforces the official PeakURL v1 manifest contract (JSON Schema Draft 2020-12)
 * and determines runtime compatibility against the host environment.
 *
 * @package PeakURL\Core\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Plugins;

use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Validator — Manifest structure, type, and SemVer compatibility validator.
 *
 * @since 1.8.0
 */
class Validator {

	/**
	 * Canonical v1 schema identifier.
	 */
	public const string SCHEMA_URL = 'https://peakurl.org/schemas/v1/plugin.json';

	/**
	 * Maximum manifest file size (64 KB).
	 */
	public const int MAX_MANIFEST_BYTES = 65536;

	/**
	 * Required manifest property names.
	 *
	 * @var array<int, string>
	 */
	public const array REQUIRED_FIELDS = array(
		'name',
		'version',
		'requires',
		'testedUpTo',
		'php',
		'license',
	);

	/**
	 * Optional manifest property names.
	 *
	 * @var array<int, string>
	 */
	public const array OPTIONAL_FIELDS = array(
		'$schema',
		'licenseUri',
		'homepage',
		'documentation',
		'repository',
		'support',
		'issues',
	);

	/**
	 * Strict SemVer 2.0.0 regular expression.
	 */
	private const string SEMVER_REGEX = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

	/**
	 * PHP version notation pattern (X.Y or X.Y.Z).
	 */
	private const string PHP_VERSION_REGEX = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?$/';

	/**
	 * Strict HTTP/HTTPS URL pattern rejecting raw whitespace and control characters.
	 */
	private const string URL_REGEX = '/^https?:\/\/[^\s\x00-\x1f\x7f]+$/D';

	/**
	 * Pattern forbidding leading or trailing whitespace (including newlines and tabs).
	 */
	private const string NO_SURROUNDING_WHITESPACE_REGEX = '/^\S(?:.*\S)?$/uD';

	/**
	 * Read and parse a local plugin.json file.
	 *
	 * @param string $file_path Absolute path to plugin.json.
	 * @return array<string, mixed> Parsed result with 'valid', 'manifest', and 'errors'.
	 */
	public function read_manifest( string $file_path ): array {
		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return array(
				'valid'    => false,
				'manifest' => array(),
				'errors'   => array( 'plugin.json is missing or unreadable.' ),
			);
		}

		$size = filesize( $file_path );
		if ( false === $size || $size > self::MAX_MANIFEST_BYTES ) {
			return array(
				'valid'    => false,
				'manifest' => array(),
				'errors'   => array( sprintf( 'plugin.json exceeds the maximum size limit of %d bytes.', self::MAX_MANIFEST_BYTES ) ),
			);
		}

		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return array(
				'valid'    => false,
				'manifest' => array(),
				'errors'   => array( 'Failed to read plugin.json.' ),
			);
		}

		try {
			$data = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return array(
				'valid'    => false,
				'manifest' => array(),
				'errors'   => array( 'plugin.json contains invalid JSON: ' . $e->getMessage() ),
			);
		}

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return array(
				'valid'    => false,
				'manifest' => array(),
				'errors'   => array( 'plugin.json root must be a JSON object.' ),
			);
		}

		return array(
			'valid'    => true,
			'manifest' => $data,
			'errors'   => array(),
		);
	}

	/**
	 * Validate a manifest payload against the v1 contract and host environment.
	 *
	 * @param array<string, mixed> $manifest     Parsed manifest data.
	 * @param string|null          $host_version Installed PeakURL version.
	 * @param string|null          $php_version  Host PHP version.
	 * @return array<string, mixed> Validation outcome map.
	 */
	public function validate(
		array $manifest,
		?string $host_version = null,
		?string $php_version = null
	): array {
		$errors   = array();
		$warnings = array();

		// 1. Reject unknown or forbidden root properties.
		$allowed_fields = array_merge( self::REQUIRED_FIELDS, self::OPTIONAL_FIELDS );
		foreach ( array_keys( $manifest ) as $key ) {
			if ( ! in_array( $key, $allowed_fields, true ) ) {
				$errors[] = sprintf( 'Unexpected property "%s" in manifest.', $key );
			}
		}

		// 2. Validate required fields presence and string types.
		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $manifest ) ) {
				$errors[] = sprintf( 'Missing required field "%s".', $field );
				continue;
			}

			if ( ! is_string( $manifest[ $field ] ) ) {
				$errors[] = sprintf( 'Field "%s" must be a string.', $field );
			}
		}

		// 3. Validate optional fields string types.
		foreach ( self::OPTIONAL_FIELDS as $field ) {
			if ( array_key_exists( $field, $manifest ) && ! is_string( $manifest[ $field ] ) ) {
				$errors[] = sprintf( 'Field "%s" must be a string.', $field );
			}
		}

		// 4. Schema URI check.
		if ( isset( $manifest['$schema'] ) && is_string( $manifest['$schema'] ) ) {
			if ( self::SCHEMA_URL !== $manifest['$schema'] ) {
				$errors[] = sprintf( 'Field "$schema" must equal "%s".', self::SCHEMA_URL );
			}
		}

		// 5. String boundaries and character sets.
		if ( isset( $manifest['name'] ) && is_string( $manifest['name'] ) ) {
			$name_len = mb_strlen( $manifest['name'], 'UTF-8' );
			if ( $name_len < 2 || $name_len > 100 ) {
				$errors[] = 'Field "name" must be between 2 and 100 characters.';
			}
			if ( ! preg_match( self::NO_SURROUNDING_WHITESPACE_REGEX, $manifest['name'] ) ) {
				$errors[] = 'Field "name" cannot have leading or trailing whitespace.';
			}
		}

		if ( isset( $manifest['license'] ) && is_string( $manifest['license'] ) ) {
			$license_len = mb_strlen( $manifest['license'], 'UTF-8' );
			if ( $license_len < 1 || $license_len > 50 ) {
				$errors[] = 'Field "license" must be between 1 and 50 characters.';
			}
			if ( ! preg_match( self::NO_SURROUNDING_WHITESPACE_REGEX, $manifest['license'] ) ) {
				$errors[] = 'Field "license" cannot have leading or trailing whitespace.';
			}
		}

		// 6. SemVer 2.0.0 checks.
		$version_fields = array( 'version', 'requires', 'testedUpTo' );
		foreach ( $version_fields as $field ) {
			if ( isset( $manifest[ $field ] ) && is_string( $manifest[ $field ] ) ) {
				if ( ! preg_match( self::SEMVER_REGEX, $manifest[ $field ] ) ) {
					$errors[] = sprintf( 'Field "%s" must be valid SemVer 2.0.0.', $field );
				}
			}
		}

		// 7. PHP version notation.
		if ( isset( $manifest['php'] ) && is_string( $manifest['php'] ) ) {
			if ( ! preg_match( self::PHP_VERSION_REGEX, $manifest['php'] ) ) {
				$errors[] = 'Field "php" must follow X.Y or X.Y.Z notation.';
			}
		}

		// 8. Resource URLs validation.
		$url_fields = array( 'licenseUri', 'homepage', 'documentation', 'repository', 'support', 'issues' );
		foreach ( $url_fields as $field ) {
			if ( isset( $manifest[ $field ] ) && is_string( $manifest[ $field ] ) ) {
				if ( ! preg_match( self::URL_REGEX, $manifest[ $field ] ) ) {
					$errors[] = sprintf( 'Field "%s" must be an absolute http:// or https:// URL without whitespace or control characters.', $field );
				}
			}
		}

		// 9. testedUpTo >= requires check.
		if (
			isset( $manifest['requires'], $manifest['testedUpTo'] ) &&
			is_string( $manifest['requires'] ) &&
			is_string( $manifest['testedUpTo'] ) &&
			preg_match( self::SEMVER_REGEX, $manifest['requires'] ) &&
			preg_match( self::SEMVER_REGEX, $manifest['testedUpTo'] )
		) {
			if ( $this->compare_versions( $manifest['testedUpTo'], $manifest['requires'] ) < 0 ) {
				$errors[] = 'Field "testedUpTo" must be greater than or equal to "requires".';
			}
		}

		$is_valid = empty( $errors );

		// 10. Compatibility check.
		$compatible = false;
		if ( $is_valid ) {
			$compat = $this->check_compatibility(
				(string) $manifest['requires'],
				(string) $manifest['testedUpTo'],
				(string) $manifest['php'],
				$host_version,
				$php_version
			);

			$compatible = $compat['compatible'];
			$errors     = array_merge( $errors, $compat['errors'] );
			$warnings   = array_merge( $warnings, $compat['warnings'] );
		}

		// Extract optional links map.
		$links = array();
		foreach ( $url_fields as $field ) {
			if ( isset( $manifest[ $field ] ) && is_string( $manifest[ $field ] ) ) {
				$links[ $field ] = $manifest[ $field ];
			}
		}

		return array(
			'valid'      => $is_valid,
			'compatible' => $compatible,
			'errors'     => $errors,
			'warnings'   => $warnings,
			'links'      => $links,
		);
	}

	/**
	 * Check runtime compatibility against the host PeakURL and PHP environment.
	 *
	 * @param string      $requires     Minimum required PeakURL version.
	 * @param string      $tested_up_to Tested PeakURL version.
	 * @param string      $php          Minimum required PHP version.
	 * @param string|null $host_version Installed PeakURL version (or null to look up).
	 * @param string|null $php_version  Host PHP version (defaults to PHP_VERSION).
	 * @return array{compatible: bool, errors: array<int, string>, warnings: array<int, string>}
	 */
	public function check_compatibility(
		string $requires,
		string $tested_up_to,
		string $php,
		?string $host_version = null,
		?string $php_version = null
	): array {
		$errors   = array();
		$warnings = array();

		$current_host = $host_version ?? $this->authoritative_host_version();
		$current_php  = $php_version ?? PHP_VERSION;

		// PeakURL version compatibility check.
		if ( null === $current_host || '' === trim( $current_host ) ) {
			$errors[] = 'Installed PeakURL version could not be determined.';
		} elseif ( $this->compare_versions( $current_host, $requires ) < 0 ) {
			$errors[] = sprintf(
				'Plugin requires PeakURL %s, but the installed version is %s.',
				$requires,
				$current_host
			);
		} elseif ( $this->compare_versions( $current_host, $tested_up_to ) > 0 ) {
			$warnings[] = sprintf(
				'Plugin has only been tested up to PeakURL %s (installed version is %s).',
				$tested_up_to,
				$current_host
			);
		}

		// PHP version compatibility check.
		if ( version_compare( $current_php, $php, '<' ) ) {
			$errors[] = sprintf(
				'Plugin requires PHP %s, but current PHP version is %s.',
				$php,
				$current_php
			);
		}

		return array(
			'compatible' => empty( $errors ),
			'errors'     => $errors,
			'warnings'   => $warnings,
		);
	}

	/**
	 * Retrieve the authoritative host PeakURL version from configuration or .version.
	 *
	 * Never silently substitutes a fallback string when the version is unavailable.
	 *
	 * @return string|null
	 */
	public function authoritative_host_version(): ?string {
		if ( class_exists( Configuration::class ) && defined( Constants::class . '::VERSION' ) ) {
			$config = Configuration::get_current();
			$val    = trim( (string) ( $config[ Constants::VERSION ] ?? '' ) );
			if ( '' !== $val ) {
				return $val;
			}
		}

		return null;
	}

	/**
	 * Compare two SemVer 2.0.0 version strings per specification precedence rules.
	 *
	 * - Build metadata is stripped before comparison per SemVer 2.0.0 Section 10.
	 * - Pre-release versions have lower precedence than normal versions.
	 * - Large numeric identifiers are compared with exact precision without overflow.
	 *
	 * @param string $v1 First semantic version string.
	 * @param string $v2 Second semantic version string.
	 * @return int -1 if $v1 < $v2, 0 if equal, 1 if $v1 > $v2.
	 */
	public function compare_versions( string $v1, string $v2 ): int {
		// Strip build metadata per SemVer 2.0.0 Section 10.
		$clean_v1 = preg_replace( '/\+.*$/', '', $v1 ) ?? $v1;
		$clean_v2 = preg_replace( '/\+.*$/', '', $v2 ) ?? $v2;

		if ( $clean_v1 === $clean_v2 ) {
			return 0;
		}

		// Separate normal version from pre-release tag.
		$parts_1 = explode( '-', $clean_v1, 2 );
		$parts_2 = explode( '-', $clean_v2, 2 );

		$core_1 = explode( '.', $parts_1[0] );
		$core_2 = explode( '.', $parts_2[0] );

		// Compare major, minor, patch with exact numeric precision.
		for ( $i = 0; $i < 3; $i++ ) {
			$seg_1 = $core_1[ $i ] ?? '0';
			$seg_2 = $core_2[ $i ] ?? '0';
			$cmp   = $this->compare_numeric_strings( $seg_1, $seg_2 );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
		}

		$has_pre_1 = isset( $parts_1[1] );
		$has_pre_2 = isset( $parts_2[1] );

		// Normal version has higher precedence than pre-release version.
		if ( ! $has_pre_1 && $has_pre_2 ) {
			return 1;
		}
		if ( $has_pre_1 && ! $has_pre_2 ) {
			return -1;
		}
		if ( ! $has_pre_1 && ! $has_pre_2 ) {
			return 0;
		}

		// Compare pre-release identifiers dot-by-dot.
		$pre_1_ids = explode( '.', $parts_1[1] );
		$pre_2_ids = explode( '.', $parts_2[1] );
		$max_len   = max( count( $pre_1_ids ), count( $pre_2_ids ) );

		for ( $i = 0; $i < $max_len; $i++ ) {
			if ( ! isset( $pre_1_ids[ $i ] ) ) {
				return -1; // Fewer identifiers = lower precedence.
			}
			if ( ! isset( $pre_2_ids[ $i ] ) ) {
				return 1;
			}

			$id_1 = $pre_1_ids[ $i ];
			$id_2 = $pre_2_ids[ $i ];

			if ( $id_1 === $id_2 ) {
				continue;
			}

			$is_num_1 = preg_match( '/^\d+$/', $id_1 ) === 1;
			$is_num_2 = preg_match( '/^\d+$/', $id_2 ) === 1;

			if ( $is_num_1 && $is_num_2 ) {
				return $this->compare_numeric_strings( $id_1, $id_2 );
			}

			if ( $is_num_1 && ! $is_num_2 ) {
				return -1; // Numeric identifiers have lower precedence than non-numeric.
			}

			if ( ! $is_num_1 && $is_num_2 ) {
				return 1;
			}

			return strcmp( $id_1, $id_2 ) < 0 ? -1 : 1;
		}

		return 0;
	}

	/**
	 * Compare two non-negative decimal numeric strings without precision loss.
	 *
	 * Avoids casting to 64-bit int or float which loses precision on very large integers.
	 *
	 * @param string $a First numeric string.
	 * @param string $b Second numeric string.
	 * @return int -1 if $a < $b, 0 if $a == $b, 1 if $a > $b.
	 */
	public function compare_numeric_strings( string $a, string $b ): int {
		if ( $a === $b ) {
			return 0;
		}

		$len_a = strlen( $a );
		$len_b = strlen( $b );

		if ( $len_a !== $len_b ) {
			return $len_a < $len_b ? -1 : 1;
		}

		return strcmp( $a, $b ) < 0 ? -1 : 1;
	}
}
