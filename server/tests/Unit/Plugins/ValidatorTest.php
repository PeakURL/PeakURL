<?php
/**
 * Unit tests for Validator.
 *
 * @package PeakURL\Tests\Unit\Plugins
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Plugins;

use PeakURL\Core\Plugins\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests canonical v1 manifest validation, SemVer precedence, and compatibility checking.
 */
class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new Validator();
	}

	/**
	 * Canonical valid manifest fixture.
	 *
	 * @return array<string, string>
	 */
	private function get_canonical_manifest(): array {
		return array(
			'$schema'       => 'https://peakurl.org/schemas/v1/plugin.json',
			'name'          => 'Example Plugin',
			'version'       => '1.0.0',
			'requires'      => '1.8.0',
			'testedUpTo'    => '1.8.0',
			'php'           => '8.3',
			'license'       => 'MIT',
			'textDomain'    => 'example-plugin',
			'domainPath'    => '/languages',
			'licenseUri'    => 'https://opensource.org/licenses/MIT',
			'pluginUri'     => 'https://example.com/plugin',
			'description'   => 'A test plugin.',
			'author'        => 'Acme Corp',
			'authorUri'     => 'https://example.com/author',
			'documentation' => 'https://example.com/plugin/docs',
			'repository'    => 'https://github.com/example/plugin',
			'support'       => 'https://example.com/support',
			'issues'        => 'https://github.com/example/plugin/issues',
		);
	}

	/**
	 * Minimal valid manifest fixture (required properties only).
	 *
	 * @return array<string, string>
	 */
	private function get_minimal_manifest(): array {
		return array(
			'name'       => 'Minimal Plugin',
			'version'    => '1.0.0',
			'requires'   => '1.8.0',
			'testedUpTo' => '1.8.0',
			'php'        => '8.3',
			'license'    => 'GPL-2.0-or-later',
			'textDomain' => 'minimal-plugin',
		);
	}

	public function test_canonical_manifest_validates_cleanly(): void {
		$manifest   = $this->get_canonical_manifest();
		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );

		$this->assertTrue( $validation['valid'] );
		$this->assertTrue( $validation['compatible'] );
		$this->assertEmpty( $validation['errors'] );
		$this->assertEmpty( $validation['warnings'] );
		$this->assertSame( 'https://example.com/plugin', $validation['links']['pluginUri'] );
		$this->assertSame( 'A test plugin.', $validation['manifest']['description'] );
		$this->assertSame( 'Acme Corp', $validation['manifest']['author'] );
		$this->assertSame( 'https://example.com/author', $validation['links']['authorUri'] );
	}

	public function test_minimal_manifest_validates_cleanly(): void {
		$manifest   = $this->get_minimal_manifest();
		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );

		$this->assertTrue( $validation['valid'] );
		$this->assertTrue( $validation['compatible'] );
		$this->assertEmpty( $validation['errors'] );
	}

	public function test_missing_required_fields(): void {
		foreach ( Validator::REQUIRED_FIELDS as $field ) {
			$manifest = $this->get_minimal_manifest();
			unset( $manifest[ $field ] );

			$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $validation['valid'], "Manifest missing '{$field}' must be invalid." );
			$this->assertTrue(
				$this->errors_contain( $validation['errors'], "Missing required field \"{$field}\"" ),
				"Error message must specify missing field '{$field}'."
			);
		}
	}

	public function test_unexpected_properties_rejected(): void {
		$forbidden = array(
			'links'            => array( 'homepageUrl' => 'https://example.com' ),
			'homepageUrl'      => 'https://example.com',
			'documentationUrl' => 'https://example.com',
			'license_uri'      => 'https://example.com',
			'entry'            => 'plugin.php',
			'id'               => 'plugin-id',
			'slug'             => 'plugin-slug',
			'tags'             => array( 'utility' ),
			'extraProperty'    => 'forbidden',
		);

		foreach ( $forbidden as $key => $val ) {
			$manifest         = $this->get_minimal_manifest();
			$manifest[ $key ] = $val;

			$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $validation['valid'], "Manifest containing '{$key}' must be invalid." );
			$this->assertTrue(
				$this->errors_contain( $validation['errors'], "Unexpected property \"{$key}\" in manifest." ),
				"Must report unexpected property '{$key}'."
			);
		}
	}

	public function test_legacy_homepage_property_rejected(): void {
		$manifest             = $this->get_minimal_manifest();
		$manifest['homepage'] = 'https://example.com';

		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
		$this->assertFalse( $validation['valid'] );
		$this->assertTrue(
			$this->errors_contain( $validation['errors'], 'Unexpected property "homepage" in manifest.' )
		);
	}

	public function test_schema_uri_enforcement(): void {
		$manifest            = $this->get_canonical_manifest();
		$manifest['$schema'] = 'https://example.com/other-schema.json';

		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
		$this->assertFalse( $validation['valid'] );
		$this->assertTrue( $this->errors_contain( $validation['errors'], 'must equal "https://peakurl.org/schemas/v1/plugin.json"' ) );
	}

	public function test_stable_version_policy_enforced(): void {
		// Valid stable plugin release versions (MAJOR.MINOR.PATCH beginning with 0.1.0 or higher).
		foreach ( array( '0.1.0', '0.1.5', '0.2.0', '0.10.5', '1.0.0', '1.2.3', '2.0.0', '10.20.30' ) as $valid_ver ) {
			$manifest            = $this->get_minimal_manifest();
			$manifest['version'] = $valid_ver;
			$res                 = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertTrue( $res['valid'], "Version '{$valid_ver}' must be valid." );
		}

		// Invalid plugin release versions (0.0.x, leading zeroes, prereleases, build metadata, incomplete components, v-prefixes).
		$invalid_versions = array(
			'0.0.1',
			'0.0.0',
			'0.0.9',
			'01.0.0',
			'0.01.0',
			'0.1.01',
			'1.0.0-alpha',
			'1.0.0-beta.1',
			'1.0.0-rc.1',
			'1.0.0+build.12',
			'0.1.0-alpha',
			'v1.0.0',
			'v0.1.0',
			'1.0',
			'0.1',
			'1',
			'1.0.0.0',
			'1.0.0-SNAPSHOT',
		);

		foreach ( $invalid_versions as $invalid_ver ) {
			$manifest            = $this->get_minimal_manifest();
			$manifest['version'] = $invalid_ver;
			$res                 = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $res['valid'], "Version '{$invalid_ver}' must be rejected." );
			$this->assertTrue(
				$this->errors_contain( $res['errors'], 'Field "version" must be a valid plugin release version in the form MAJOR.MINOR.PATCH beginning with 0.1.0 or higher (0.0.x versions, prerelease suffixes, and build metadata are not permitted).' )
			);
		}
	}

	public function test_compatibility_version_policy_enforced(): void {
		// Valid stable compatibility versions.
		foreach ( array( '1.8.0', '1.8.2', '2.0.0' ) as $valid_compat ) {
			$manifest               = $this->get_minimal_manifest();
			$manifest['requires']   = $valid_compat;
			$manifest['testedUpTo'] = $valid_compat;
			$res                    = $this->validator->validate( $manifest, '2.0.0', '8.3.0' );
			$this->assertEmpty( $res['errors'] );
		}

		// Invalid compatibility versions.
		foreach ( array( '1.8.0-alpha', '1.8', 'v1.8.0', '1.8.0+build.1' ) as $invalid_compat ) {
			$manifest             = $this->get_minimal_manifest();
			$manifest['requires'] = $invalid_compat;
			$res                  = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $res['valid'], "Requires '{$invalid_compat}' must be rejected." );
			$this->assertTrue(
				$this->errors_contain( $res['errors'], 'Field "requires" must be a stable three-component version in the form MAJOR.MINOR.PATCH.' )
			);
		}
	}

	public function test_version_ordering_and_numeric_comparison(): void {
		// Numeric version ordering where 1.10.0 is newer than 1.9.0.
		$this->assertSame( 1, $this->validator->compare_versions( '1.10.0', '1.9.0' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.9.0', '1.10.0' ) );
		$this->assertSame( 1, $this->validator->compare_versions( '1.10.2', '1.10.1' ) );
		$this->assertSame( 1, $this->validator->compare_versions( '2.0.0', '1.99.99' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.99.99', '2.0.0' ) );
		$this->assertSame( 0, $this->validator->compare_versions( '1.8.0', '1.8.0' ) );
	}

	public function test_semver_large_numeric_identifiers_comparison_precision(): void {
		// Verify arbitrary-precision numeric string comparison without 64-bit int overflow.
		$huge_1 = '9999999999999999999999999999999999999999';
		$huge_2 = '10000000000000000000000000000000000000000'; // 1 digit longer = larger

		$this->assertSame( -1, $this->validator->compare_numeric_strings( $huge_1, $huge_2 ) );
		$this->assertSame( 1, $this->validator->compare_numeric_strings( $huge_2, $huge_1 ) );
		$this->assertSame( 0, $this->validator->compare_numeric_strings( $huge_1, $huge_1 ) );

		$v1 = '1.' . $huge_1 . '.0';
		$v2 = '1.' . $huge_2 . '.0';

		$this->assertSame( -1, $this->validator->compare_versions( $v1, $v2 ) );
		$this->assertSame( 1, $this->validator->compare_versions( $v2, $v1 ) );
		$this->assertSame( 0, $this->validator->compare_versions( $v1, $v1 ) );
	}

	public function test_tested_up_to_less_than_requires_rejected(): void {
		$manifest               = $this->get_minimal_manifest();
		$manifest['requires']   = '1.8.0';
		$manifest['testedUpTo'] = '1.7.9';

		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
		$this->assertFalse( $validation['valid'] );
		$this->assertTrue(
			$this->errors_contain( $validation['errors'], 'Field "testedUpTo" must be greater than or equal to "requires".' )
		);
	}

	public function test_name_whitespace_and_length_boundaries(): void {
		$invalid_names = array(
			''                     => 'length',
			' '                    => 'whitespace',
			'  '                   => 'whitespace',
			' Example Plugin'      => 'leading whitespace',
			'Example Plugin '      => 'trailing whitespace',
			"Example Plugin\n"     => 'trailing newline',
			"\tExample Plugin"     => 'leading tab',
			'A'                    => 'minimum length of 2',
			str_repeat( 'X', 101 ) => 'maximum length of 100',
		);

		foreach ( $invalid_names as $name => $reason ) {
			$manifest         = $this->get_minimal_manifest();
			$manifest['name'] = $name;

			$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $validation['valid'], "Name '{$name}' ({$reason}) must be rejected." );
		}
	}

	public function test_license_whitespace_and_length_boundaries(): void {
		$invalid_licenses = array(
			''                    => 'length',
			' '                   => 'whitespace',
			' MIT'                => 'leading space',
			'MIT '                => 'trailing space',
			"MIT\n"               => 'trailing newline',
			"\tMIT"               => 'leading tab',
			str_repeat( 'L', 51 ) => 'maximum length of 50',
		);

		foreach ( $invalid_licenses as $license => $reason ) {
			$manifest            = $this->get_minimal_manifest();
			$manifest['license'] = $license;

			$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $validation['valid'], "License '{$license}' ({$reason}) must be rejected." );
		}
	}

	public function test_url_whitespace_and_control_characters_rejected(): void {
		$bad_urls = array(
			' https://example.com',
			'https://example.com ',
			"https://example.com\n",
			"https://example.com\t",
			'https://example.com/with space',
			'ftp://example.com',
			'javascript:alert(1)',
			'//protocol-relative.com',
		);

		foreach ( $bad_urls as $bad_url ) {
			$manifest              = $this->get_minimal_manifest();
			$manifest['pluginUri'] = $bad_url;

			$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $validation['valid'], "URL '{$bad_url}' must be rejected." );
		}
	}

	public function test_host_compatibility_peakurl_version_checks(): void {
		$manifest               = $this->get_minimal_manifest();
		$manifest['requires']   = '1.9.0';
		$manifest['testedUpTo'] = '1.9.0';

		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
		$this->assertTrue( $validation['valid'] );
		$this->assertFalse( $validation['compatible'] );
		$this->assertTrue(
			$this->errors_contain( $validation['errors'], 'Plugin requires PeakURL 1.9.0, but the installed version is 1.8.0.' )
		);
	}

	public function test_host_compatibility_fails_when_version_unavailable(): void {
		$manifest = $this->get_minimal_manifest();

		// Passing null when no authoritative version is in environment/config must not substitute 1.8.0.
		$validation = $this->validator->validate( $manifest, '', '8.3.0' );
		$this->assertTrue( $validation['valid'] );
		$this->assertFalse( $validation['compatible'] );
		$this->assertTrue(
			$this->errors_contain( $validation['errors'], 'Installed PeakURL version could not be determined.' )
		);
	}

	public function test_host_compatibility_php_version_checks(): void {
		$manifest        = $this->get_minimal_manifest();
		$manifest['php'] = '8.4';

		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
		$this->assertTrue( $validation['valid'] );
		$this->assertFalse( $validation['compatible'] );
		$this->assertTrue(
			$this->errors_contain( $validation['errors'], 'Plugin requires PHP 8.4, but current PHP version is 8.3.0.' )
		);
	}

	public function test_tested_up_to_older_than_host_emits_warning(): void {
		$manifest               = $this->get_minimal_manifest();
		$manifest['requires']   = '1.7.0';
		$manifest['testedUpTo'] = '1.7.9';

		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
		$this->assertTrue( $validation['valid'] );
		$this->assertTrue( $validation['compatible'] );
		$this->assertNotEmpty( $validation['warnings'] );
		$this->assertTrue(
			$this->errors_contain( $validation['warnings'], 'Plugin has only been tested up to PeakURL 1.7.9 (installed version is 1.8.0).' )
		);
	}

	public function test_read_php_header_ignores_non_leading_docblocks(): void {
		$temp_file = tempnam( sys_get_temp_dir(), 'php_header_test_' );
		$php_code  = <<<'PHP'
<?php
namespace Acme\Plugin;

/**
 * Plugin Name: False Positive In Namespace
 * Version: 9.9.9
 */
class PluginRunner {
}
PHP;
		file_put_contents( $temp_file, $php_code );

		try {
			$res = $this->validator->read_php_header( $temp_file );
			$this->assertFalse( $res['has_header'], 'Non-leading docblocks after code tokens must not be treated as package header.' );
			$this->assertEmpty( $res['headers'] );
		} finally {
			unlink( $temp_file );
		}
	}

	public function test_validate_manifest_against_header_rejects_missing_manifest_property(): void {
		$manifest = $this->get_minimal_manifest();
		$header   = array(
			'name'    => 'Minimal Plugin',
			'version' => '1.0.0',
			'author'  => 'Acme Devs',
		);

		$errors = $this->validator->validate_manifest_against_header( $manifest, $header );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Header "author" is declared in plugin.php but missing from plugin.json', $errors[0] );
	}

	public function test_read_php_header_parses_without_evaluating_code(): void {
		$temp_file = tempnam( sys_get_temp_dir(), 'php_header_test_' );
		$php_code  = <<<'PHP'
<?php
/**
 * Plugin Name: Static Extracted Plugin
 * Plugin URI: https://example.com/plugin
 * Description: Header parsing without code evaluation.
 * Version: 2.3.4
 * Requires PeakURL: 1.8.0
 * Tested up to: 1.9.0
 * Requires PHP: 8.3
 * Author: Acme Corp
 * Author URI: https://example.com/author
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

// If code were executed, this would throw or set a global:
throw new \RuntimeException( 'Code execution forbidden during metadata extraction!' );
PHP;
		file_put_contents( $temp_file, $php_code );

		try {
			$res = $this->validator->read_php_header( $temp_file );
			$this->assertTrue( $res['has_header'] );
			$this->assertEmpty( $res['errors'] );
			$header = $res['headers'];
			$this->assertSame( 'Static Extracted Plugin', $header['name'] );
			$this->assertSame( 'https://example.com/plugin', $header['pluginUri'] );
			$this->assertSame( 'Header parsing without code evaluation.', $header['description'] );
			$this->assertSame( '2.3.4', $header['version'] );
			$this->assertSame( '1.8.0', $header['requires'] );
			$this->assertSame( '1.9.0', $header['testedUpTo'] );
			$this->assertSame( '8.3', $header['php'] );
			$this->assertSame( 'Acme Corp', $header['author'] );
			$this->assertSame( 'https://example.com/author', $header['authorUri'] );
			$this->assertSame( 'MIT', $header['license'] );
		} finally {
			unlink( $temp_file );
		}
	}

	public function test_read_php_header_rejects_unsupported_wordpress_headers(): void {
		$temp_file = tempnam( sys_get_temp_dir(), 'php_header_test_' );
		$php_code  = <<<'PHP'
<?php
/**
 * Plugin Name: WP Port Plugin
 * Version: 1.0.0
 * Network: true
 */
PHP;
		file_put_contents( $temp_file, $php_code );

		try {
			$res = $this->validator->read_php_header( $temp_file );
			$this->assertNotEmpty( $res['errors'] );
			$this->assertStringContainsString( 'Unsupported WordPress-specific header "Network"', $res['errors'][0] );
		} finally {
			unlink( $temp_file );
		}
	}

	public function test_read_php_header_accepts_text_domain_and_domain_path(): void {
		$temp_file = tempnam( sys_get_temp_dir(), 'php_header_test_' );
		$php_code  = <<<'PHP'
<?php
/**
 * Plugin Name: Example Plugin
 * Version: 1.0.0
 * Text Domain: example-plugin
 * Domain Path: /languages
 */
PHP;
		file_put_contents( $temp_file, $php_code );

		try {
			$res = $this->validator->read_php_header( $temp_file );
			$this->assertTrue( $res['has_header'] );
			$this->assertEmpty( $res['errors'] );
			$this->assertSame( 'example-plugin', $res['headers']['textDomain'] );
			$this->assertSame( '/languages', $res['headers']['domainPath'] );
		} finally {
			unlink( $temp_file );
		}
	}

	public function test_validate_manifest_against_header_detects_mismatches(): void {
		$manifest = $this->get_canonical_manifest();
		$header   = array(
			'name'     => 'Example Plugin',
			'version'  => '9.9.9', // Mismatched version!
			'requires' => '1.8.0',
		);

		$errors = $this->validator->validate_manifest_against_header( $manifest, $header );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Mismatch between plugin.php header and plugin.json for "version"', $errors[0] );
	}

	public function test_text_domain_validation(): void {
		$valid_domains = array( 'my-plugin', 'click-history-reset', 'example123', 'a-b-c' );
		foreach ( $valid_domains as $domain ) {
			$manifest               = $this->get_minimal_manifest();
			$manifest['name']       = $domain;
			$manifest['textDomain'] = $domain;
			$res                    = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertTrue( $res['valid'], "Domain '$domain' should be valid." );
		}

		$invalid_domains = array( 'MyPlugin', 'my_plugin', '-invalid', 'invalid-', 'has space', '' );
		foreach ( $invalid_domains as $domain ) {
			$manifest               = $this->get_minimal_manifest();
			$manifest['name']       = $domain;
			$manifest['textDomain'] = $domain;
			$res                    = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $res['valid'], "Domain '$domain' should be rejected." );
			$this->assertTrue( $this->errors_contain( $res['errors'], 'textDomain' ) );
		}

		// Rejection when textDomain does not match plugin slug
		$mismatch_manifest               = $this->get_minimal_manifest();
		$mismatch_manifest['name']       = 'My Plugin';
		$mismatch_manifest['textDomain'] = 'different-slug';
		$mismatch_res                    = $this->validator->validate( $mismatch_manifest, '1.8.0', '8.3.0' );
		$this->assertFalse( $mismatch_res['valid'], 'Mismatched textDomain must be rejected.' );
		$this->assertTrue( $this->errors_contain( $mismatch_res['errors'], 'Field "textDomain" must match the plugin slug' ) );

		// Rejection of reserved domains
		foreach ( Validator::RESERVED_DOMAINS as $reserved ) {
			$reserved_manifest               = $this->get_minimal_manifest();
			$reserved_manifest['name']       = $reserved;
			$reserved_manifest['textDomain'] = $reserved;
			$reserved_res                    = $this->validator->validate( $reserved_manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $reserved_res['valid'], "Reserved domain '$reserved' must be rejected." );
			$this->assertTrue( $this->errors_contain( $reserved_res['errors'], 'Field "textDomain" is not available. Please choose another.' ) );
		}
	}

	public function test_domain_path_validation(): void {
		$valid_paths = array( '/languages', '/lang', '/assets/i18n' );
		foreach ( $valid_paths as $path ) {
			$manifest               = $this->get_minimal_manifest();
			$manifest['domainPath'] = $path;
			$res                    = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertTrue( $res['valid'], "Path '$path' should be valid." );
		}

		$invalid_paths = array( 'languages', '/../languages', '/languages/../../etc' );
		foreach ( $invalid_paths as $path ) {
			$manifest               = $this->get_minimal_manifest();
			$manifest['domainPath'] = $path;
			$res                    = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
			$this->assertFalse( $res['valid'], "Path '$path' should be rejected." );
			$this->assertTrue( $this->errors_contain( $res['errors'], 'domainPath' ) );
		}
	}

	/**
	 * Helper checking whether an array of error strings contains a needle.
	 *
	 * @param array<int, string> $errors  Errors list.
	 * @param string             $needle  Search substring.
	 * @return bool
	 */
	private function errors_contain( array $errors, string $needle ): bool {
		foreach ( $errors as $error ) {
			if ( str_contains( $error, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
