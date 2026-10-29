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
			'licenseUri'    => 'https://opensource.org/licenses/MIT',
			'homepage'      => 'https://example.com/plugin',
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
			'version'    => '0.1.0',
			'requires'   => '1.8.0',
			'testedUpTo' => '1.8.0',
			'php'        => '8.3',
			'license'    => 'GPL-2.0-or-later',
		);
	}

	public function test_canonical_manifest_validates_cleanly(): void {
		$manifest   = $this->get_canonical_manifest();
		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );

		$this->assertTrue( $validation['valid'] );
		$this->assertTrue( $validation['compatible'] );
		$this->assertEmpty( $validation['errors'] );
		$this->assertEmpty( $validation['warnings'] );
		$this->assertSame( 'https://example.com/plugin', $validation['links']['homepage'] );
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
			'author'           => 'Acme Corp',
			'description'      => 'A test plugin.',
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

	public function test_schema_uri_enforcement(): void {
		$manifest            = $this->get_canonical_manifest();
		$manifest['$schema'] = 'https://example.com/other-schema.json';

		$validation = $this->validator->validate( $manifest, '1.8.0', '8.3.0' );
		$this->assertFalse( $validation['valid'] );
		$this->assertTrue( $this->errors_contain( $validation['errors'], 'must equal "https://peakurl.org/schemas/v1/plugin.json"' ) );
	}

	public function test_semver_build_metadata_ignored_in_precedence(): void {
		$this->assertSame( 0, $this->validator->compare_versions( '1.8.0+build.1', '1.8.0' ) );
		$this->assertSame( 0, $this->validator->compare_versions( '1.8.0', '1.8.0+build.2' ) );
		$this->assertSame( 0, $this->validator->compare_versions( '1.8.0+sha.123', '1.8.0+sha.456' ) );
		$this->assertSame( 1, $this->validator->compare_versions( '1.8.1+build.1', '1.8.0+build.99' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.7.9+build.99', '1.8.0' ) );
	}

	public function test_semver_large_numeric_identifiers_comparison_precision(): void {
		// Verify arbitrary-precision numeric string comparison without 64-bit int overflow.
		$huge_1 = '9999999999999999999999999999999999999999';
		$huge_2 = '10000000000000000000000000000000000000000'; // 1 digit longer = larger

		$this->assertSame( -1, $this->validator->compare_numeric_strings( $huge_1, $huge_2 ) );
		$this->assertSame( 1, $this->validator->compare_numeric_strings( $huge_2, $huge_1 ) );
		$this->assertSame( 0, $this->validator->compare_numeric_strings( $huge_1, $huge_1 ) );

		// In SemVer pre-releases:
		$v1 = '1.0.0-alpha.' . $huge_1;
		$v2 = '1.0.0-alpha.' . $huge_2;

		$this->assertSame( -1, $this->validator->compare_versions( $v1, $v2 ) );
		$this->assertSame( 1, $this->validator->compare_versions( $v2, $v1 ) );
		$this->assertSame( 0, $this->validator->compare_versions( $v1, $v1 ) );
	}

	public function test_semver_prerelease_precedence_rules(): void {
		$this->assertSame( 1, $this->validator->compare_versions( '1.0.0', '1.0.0-alpha' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.0.0-alpha', '1.0.0' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.0.0-alpha', '1.0.0-alpha.1' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.0.0-alpha.1', '1.0.0-alpha.beta' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.0.0-alpha.beta', '1.0.0-beta' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.0.0-beta', '1.0.0-beta.2' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.0.0-beta.2', '1.0.0-beta.11' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.0.0-beta.11', '1.0.0-rc.1' ) );
		$this->assertSame( -1, $this->validator->compare_versions( '1.0.0-rc.1', '1.0.0' ) );
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
			$manifest             = $this->get_minimal_manifest();
			$manifest['homepage'] = $bad_url;

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
