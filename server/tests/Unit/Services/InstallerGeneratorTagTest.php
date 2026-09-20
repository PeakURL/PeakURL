<?php
/**
 * Unit tests for installer, setup, and database error page generator tag logic.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;

class InstallerGeneratorTagTest extends TestCase {

	public function test_early_bootstrap_resolves_generator_tag_without_database(): void {
		$runtime_path   = Environment::get_instance()->get_runtime_root();
		$app_config     = Configuration::bootstrap( $runtime_path );
		$version        = trim( (string) ( $app_config[ Constants::VERSION ] ?? '' ) );
		$generator_meta = \get_generator_tag( $version );

		$this->assertNotEmpty( $generator_meta );
		$this->assertMatchesRegularExpression( '/^<meta name="generator" content="PeakURL [^"]+">$/', $generator_meta );
	}

	public function test_get_generator_tag_without_parameters_resolves_in_early_bootstrap(): void {
		$generator_meta = \get_generator_tag();

		$this->assertNotEmpty( $generator_meta );
		$this->assertMatchesRegularExpression( '/^<meta name="generator" content="PeakURL [^"]+">$/', $generator_meta );
	}
}
