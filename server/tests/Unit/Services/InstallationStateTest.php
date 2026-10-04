<?php
/**
 * Unit tests for InstallationState contract and constants.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Install\InstallationState;

class InstallationStateTest extends TestCase {

	public function test_installation_state_constants_are_stable(): void {
		$this->assertSame( 'not_configured', InstallationState::NOT_CONFIGURED );
		$this->assertSame( 'not_installed', InstallationState::NOT_INSTALLED );
		$this->assertSame( 'database_unavailable', InstallationState::DATABASE_UNAVAILABLE );
		$this->assertSame( 'ready', InstallationState::READY );
	}

	public function test_missing_config_returns_not_configured(): void {
		$non_existent_path = sys_get_temp_dir() . '/non_existent_' . bin2hex( random_bytes( 4 ) );
		$prev_env          = Environment::get_instance();
		Environment::set_instance( new Environment( $non_existent_path, false ) );

		try {
			$this->assertFalse( InstallationState::config_exists( $non_existent_path ) );
			$this->assertSame( InstallationState::NOT_CONFIGURED, InstallationState::get_state( $non_existent_path ) );
			$this->assertFalse( InstallationState::is_installed( $non_existent_path ) );
		} finally {
			Environment::set_instance( $prev_env );
		}
	}
}
