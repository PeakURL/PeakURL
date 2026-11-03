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
	 * Maximum bytes to read for PHP header inspection (8 KB).
	 */
	public const int MAX_HEADER_READ_BYTES = 8192;

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
		'textDomain',
	);

	/**
	 * Optional manifest property names.
	 *
	 * @var array<int, string>
	 */
	public const array OPTIONAL_FIELDS = array(
		'$schema',
		'domainPath',
		'licenseUri',
		'pluginUri',
		'description',
		'author',
		'authorUri',
		'documentation',
		'repository',
		'support',
		'issues',
	);

	/**
	 * WordPress-inspired PHP comment headers recognized in plugin.php.
	 *
	 * @var array<string, string>
	 */
	public const array KNOWN_PHP_HEADERS = array(
		'Plugin Name'      => 'name',
		'Plugin URI'       => 'pluginUri',
		'Description'      => 'description',
		'Version'          => 'version',
		'Requires PeakURL' => 'requires',
		'Tested up to'     => 'testedUpTo',
		'Requires PHP'     => 'php',
		'Author'           => 'author',
		'Author URI'       => 'authorUri',
		'License'          => 'license',
		'License URI'      => 'licenseUri',
		'Text Domain'      => 'textDomain',
		'Domain Path'      => 'domainPath',
	);

	/**
	 * Reserved translation text domains forbidden for plugins.
	 *
	 * @var array<int, string>
	 */
	public const array RESERVED_DOMAINS = array(
		'peakurl',
		'default',
		'core',
		'system',
		'messages',
		'admin',
		'api',
	);

	/**
	 * Stable Plugin release version pattern (MAJOR.MINOR.PATCH beginning with 0.1.0 or higher).
	 */
	private const string PLUGIN_VERSION_REGEX = '/^(?:0\.[1-9]\d*|[1-9]\d*\.(?:0|[1-9]\d*))\.(?:0|[1-9]\d*)$/D';

	/**
	 * Stable three-component compatibility version pattern (MAJOR.MINOR.PATCH).
	 */
	private const string COMPAT_VERSION_REGEX = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D';

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
	 * Pattern enforcing kebab-case textDomain.
	 */
	private const string TEXT_DOMAIN_REGEX = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

	/**
	 * Pattern enforcing relative domainPath starting with a slash.
	 */
	private const string DOMAIN_PATH_REGEX = '/^\/[^\s\x00-\x1f\x7f]+$/D';

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
	 * @param string|null          $plugin_slug  Optional target plugin slug.
	 * @return array<string, mixed> Validation outcome map.
	 */
	public function validate(
		array $manifest,
		?string $host_version = null,
		?string $php_version = null,
		?string $plugin_slug = null
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

		if ( isset( $manifest['description'] ) && is_string( $manifest['description'] ) ) {
			$desc_len = mb_strlen( $manifest['description'], 'UTF-8' );
			if ( $desc_len < 1 || $desc_len > 500 ) {
				$errors[] = 'Field "description" must be between 1 and 500 characters.';
			}
			if ( ! preg_match( self::NO_SURROUNDING_WHITESPACE_REGEX, $manifest['description'] ) ) {
				$errors[] = 'Field "description" cannot have leading or trailing whitespace.';
			}
			if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $manifest['description'] ) ) {
				$errors[] = 'Field "description" contains invalid control characters.';
			}
		}

		if ( isset( $manifest['author'] ) && is_string( $manifest['author'] ) ) {
			$author_len = mb_strlen( $manifest['author'], 'UTF-8' );
			if ( $author_len < 1 || $author_len > 100 ) {
				$errors[] = 'Field "author" must be between 1 and 100 characters.';
			}
			if ( ! preg_match( self::NO_SURROUNDING_WHITESPACE_REGEX, $manifest['author'] ) ) {
				$errors[] = 'Field "author" cannot have leading or trailing whitespace.';
			}
		}

		// 6. Stable version checks.
		if ( isset( $manifest['version'] ) && is_string( $manifest['version'] ) ) {
			if ( ! preg_match( self::PLUGIN_VERSION_REGEX, $manifest['version'] ) ) {
				$errors[] = 'Field "version" must be a valid plugin release version in the form MAJOR.MINOR.PATCH beginning with 0.1.0 or higher (0.0.x versions, prerelease suffixes, and build metadata are not permitted).';
			}
		}

		$compat_fields = array( 'requires', 'testedUpTo' );
		foreach ( $compat_fields as $field ) {
			if ( isset( $manifest[ $field ] ) && is_string( $manifest[ $field ] ) ) {
				if ( ! preg_match( self::COMPAT_VERSION_REGEX, $manifest[ $field ] ) ) {
					$errors[] = sprintf( 'Field "%s" must be a stable three-component version in the form MAJOR.MINOR.PATCH.', $field );
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
		$url_fields = array( 'licenseUri', 'pluginUri', 'authorUri', 'documentation', 'repository', 'support', 'issues' );
		foreach ( $url_fields as $field ) {
			if ( isset( $manifest[ $field ] ) && is_string( $manifest[ $field ] ) ) {
				if ( mb_strlen( $manifest[ $field ], 'UTF-8' ) > 2048 ) {
					$errors[] = sprintf( 'Field "%s" cannot exceed 2048 characters.', $field );
				}
				if ( ! preg_match( self::URL_REGEX, $manifest[ $field ] ) ) {
					$errors[] = sprintf( 'Field "%s" must be an absolute http:// or https:// URL without whitespace or control characters.', $field );
				}
			}
		}

		// 8b. Internationalization fields validation.
		if ( isset( $manifest['textDomain'] ) && is_string( $manifest['textDomain'] ) ) {
			$domain_len = mb_strlen( $manifest['textDomain'], 'UTF-8' );
			if ( $domain_len < 1 || $domain_len > 100 ) {
				$errors[] = 'Field "textDomain" must be between 1 and 100 characters.';
			}
			if ( ! preg_match( self::TEXT_DOMAIN_REGEX, $manifest['textDomain'] ) ) {
				$errors[] = 'Field "textDomain" must be lowercase alphanumeric with hyphens (kebab-case).';
			}
			if ( in_array( strtolower( $manifest['textDomain'] ), self::RESERVED_DOMAINS, true ) ) {
				$errors[] = 'Field "textDomain" is not available. Please choose another.';
			}
			if ( isset( $manifest['name'] ) && is_string( $manifest['name'] ) ) {
				$canonical_slug = self::slugify( $manifest['name'] );
				$domain         = strtolower( trim( $manifest['textDomain'] ) );
				$target_slug    = ( null !== $plugin_slug && '' !== trim( $plugin_slug ) )
					? strtolower( trim( $plugin_slug ) )
					: $canonical_slug;

				if ( $domain !== $target_slug ) {
					$errors[] = sprintf(
						'Field "textDomain" must match the plugin slug ("%s").',
						$target_slug
					);
				}
			}
		}

		if ( isset( $manifest['domainPath'] ) && is_string( $manifest['domainPath'] ) ) {
			$path_len = strlen( $manifest['domainPath'] );
			if ( $path_len < 1 || $path_len > 255 ) {
				$errors[] = 'Field "domainPath" cannot exceed 255 characters.';
			}
			if ( ! preg_match( self::DOMAIN_PATH_REGEX, $manifest['domainPath'] ) || str_contains( $manifest['domainPath'], '..' ) ) {
				$errors[] = 'Field "domainPath" must be a relative directory starting with "/" and must not contain "..".';
			}
		}

		// 9. testedUpTo >= requires check.
		if (
			isset( $manifest['requires'], $manifest['testedUpTo'] ) &&
			is_string( $manifest['requires'] ) &&
			is_string( $manifest['testedUpTo'] ) &&
			preg_match( self::COMPAT_VERSION_REGEX, $manifest['requires'] ) &&
			preg_match( self::COMPAT_VERSION_REGEX, $manifest['testedUpTo'] )
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
			'manifest'   => $manifest,
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
	 * Compare two stable semantic version strings.
	 *
	 * Compares major, minor, and patch segments with exact numeric string precision.
	 *
	 * @param string $v1 First semantic version string.
	 * @param string $v2 Second semantic version string.
	 * @return int -1 if $v1 < $v2, 0 if equal, 1 if $v1 > $v2.
	 */
	public function compare_versions( string $v1, string $v2 ): int {
		if ( $v1 === $v2 ) {
			return 0;
		}

		$core_1 = explode( '.', $v1 );
		$core_2 = explode( '.', $v2 );

		// Compare major, minor, patch with exact numeric precision.
		for ( $i = 0; $i < 3; $i++ ) {
			$seg_1 = $core_1[ $i ] ?? '0';
			$seg_2 = $core_2[ $i ] ?? '0';
			$cmp   = $this->compare_numeric_strings( $seg_1, $seg_2 );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
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

	/**
	 * Parse optional WordPress-inspired plugin metadata headers from plugin.php without execution.
	 *
	 * Reads at most 8 KB from the start of the file, never includes or evaluates code,
	 * parses the first PHP docblock/comment, and strictly validates known fields.
	 *
	 * @param string $entrypoint_path Absolute path to plugin.php.
	 * @return array<string, mixed> Map containing 'has_header', 'headers', and 'errors'.
	 */
	public function read_php_header( string $entrypoint_path ): array {
		if ( ! is_file( $entrypoint_path ) || ! is_readable( $entrypoint_path ) ) {
			return array(
				'has_header' => false,
				'headers'    => array(),
				'errors'     => array( 'Plugin entrypoint file is missing or unreadable.' ),
			);
		}

		$fp = @fopen( $entrypoint_path, 'rb' );
		if ( false === $fp ) {
			return array(
				'has_header' => false,
				'headers'    => array(),
				'errors'     => array( 'Failed to open plugin entrypoint file.' ),
			);
		}

		$chunk = fread( $fp, self::MAX_HEADER_READ_BYTES );
		fclose( $fp );

		if ( false === $chunk || '' === $chunk ) {
			return array(
				'has_header' => false,
				'headers'    => array(),
				'errors'     => array(),
			);
		}

		// Tokenize chunk to find the genuine leading PHP docblock/comment without code execution.
		$tokens       = token_get_all( $chunk );
		$comment_body = null;

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				$token_id   = $token[0];
				$token_text = $token[1];

				if ( T_OPEN_TAG === $token_id || T_WHITESPACE === $token_id ) {
					continue;
				}

				if ( T_DOC_COMMENT === $token_id || T_COMMENT === $token_id ) {
					// Must be a leading block comment starting with /* or /**.
					if ( str_starts_with( $token_text, '/*' ) ) {
						$comment_body = $token_text;
					}
					break;
				}

				// Any executable code token encountered before a comment means no leading header block.
				break;
			} else {
				// Single character token before comment means no leading header block.
				break;
			}
		}

		if ( null === $comment_body ) {
			return array(
				'has_header' => false,
				'headers'    => array(),
				'errors'     => array(),
			);
		}

		// Strip opening /* or /** and closing */.
		$inner_comment = preg_replace( '/^\/\*\*?|\*\/$/', '', $comment_body ) ?? '';
		$lines         = preg_split( '/\r\n|\r|\n/', $inner_comment );
		if ( false === $lines ) {
			return array(
				'has_header' => false,
				'headers'    => array(),
				'errors'     => array(),
			);
		}

		$headers      = array();
		$errors       = array();
		$seen_headers = array();
		$has_header   = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			$cleaned = ltrim( $trimmed, "* \t" );
			if ( '' === $cleaned ) {
				continue;
			}

			// Match "Header Name: Value" pattern.
			if ( preg_match( '/^([A-Za-z0-9\s_-]+?):\s*(.*)$/', $cleaned, $parts ) ) {
				$raw_header_name = trim( $parts[1] );
				$raw_value       = trim( $parts[2] );

				// Check against known headers.
				$matched_prop           = null;
				$canonical_header_label = $raw_header_name;
				foreach ( self::KNOWN_PHP_HEADERS as $header_label => $prop_name ) {
					if ( strcasecmp( $raw_header_name, $header_label ) === 0 ) {
						$matched_prop           = $prop_name;
						$canonical_header_label = $header_label;
						break;
					}
				}

				if ( null === $matched_prop ) {
					// Reject unsupported WordPress headers or ambiguous header definitions.
					if ( in_array( strtolower( $raw_header_name ), array( 'network', 'update uri', 'requires plugins' ), true ) ) {
						$errors[] = sprintf( 'Unsupported WordPress-specific header "%s" in plugin.php.', $raw_header_name );
					}
					continue;
				}

				$has_header = true;
				if ( isset( $seen_headers[ $matched_prop ] ) ) {
					$errors[] = sprintf( 'Duplicate PHP header "%s" found in plugin.php comment block.', $canonical_header_label );
					continue;
				}

				$seen_headers[ $matched_prop ] = true;
				$headers[ $matched_prop ]      = $raw_value;
			}
		}

		return array(
			'has_header' => $has_header,
			'headers'    => $headers,
			'errors'     => $errors,
		);
	}

	/**
	 * Verify that PHP comment header values match the canonical plugin.json manifest.
	 *
	 * When a property exists in both plugin.php header and plugin.json, their values
	 * must be consistent. PHP header values never override plugin.json.
	 *
	 * @param array<string, mixed>  $manifest    Parsed manifest data.
	 * @param array<string, string> $header_data Extracted PHP header data.
	 * @return array<int, string> List of mismatch error messages.
	 */
	public function validate_manifest_against_header( array $manifest, array $header_data ): array {
		$errors = array();

		foreach ( $header_data as $prop => $header_val ) {
			if ( ! array_key_exists( $prop, $manifest ) ) {
				$errors[] = sprintf(
					'Header "%s" is declared in plugin.php but missing from plugin.json. All plugin metadata must be declared in plugin.json.',
					$prop
				);
				continue;
			}

			$manifest_val = trim( (string) $manifest[ $prop ] );
			if ( $header_val !== $manifest_val ) {
				$errors[] = sprintf(
					'Mismatch between plugin.php header and plugin.json for "%s" (header: "%s", manifest: "%s").',
					$prop,
					$header_val,
					$manifest_val
				);
			}
		}

		return $errors;
	}

	/**
	 * Convert a plugin name to a canonical kebab-case slug candidate.
	 *
	 * @param string $name Raw plugin name.
	 * @return string Canonical slug candidate.
	 */
	public static function slugify( string $name ): string {
		$clean = trim( $name );
		$clean = strtolower( $clean );
		$clean = (string) preg_replace( '/[^a-z0-9\s_-]/', '', $clean );
		$clean = (string) preg_replace( '/[\s_]+/', '-', $clean );
		$clean = trim( $clean, '-' );

		if ( '' === $clean || strlen( $clean ) < 2 ) {
			return 'plugin';
		}

		return substr( $clean, 0, 64 );
	}
}
