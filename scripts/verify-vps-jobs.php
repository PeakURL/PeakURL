<?php
/**
 * Developer Verification Harness for Scheduled Background Jobs.
 *
 * Verifies the 7 scheduled maintenance jobs against live database, filesystem,
 * and caching backends. Cleans up all test state in try/finally blocks to leave
 * the environment unmodified.
 *
 * Usage:
 *   php scripts/verify-vps-jobs.php
 *
 * @package PeakURL\Scripts
 */

declare(strict_types=1);

$possible_roots = array(
	dirname( __DIR__ ),
	__DIR__,
	getcwd(),
);

$resolved_root = null;
foreach ( $possible_roots as $candidate ) {
	if ( is_string( $candidate ) && ( file_exists( $candidate . '/load.php' ) || file_exists( $candidate . '/server/core/load.php' ) ) ) {
		$resolved_root = rtrim( $candidate, '/\\' ) . DIRECTORY_SEPARATOR;
		break;
	}
}

if ( null === $resolved_root ) {
	fwrite( STDERR, "Error: Could not locate PeakURL runtime root.\n" );
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $resolved_root );
}

require_once ABSPATH . 'load.php';

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Environment;
use PeakURL\Core\Scheduler\BackgroundRunnerFactory;
use PeakURL\Services\Database\Connection;

$env = Environment::get_instance();
$env->load_autoloader();
$runtime_root = $env->get_runtime_root();
$config       = Configuration::bootstrap( $runtime_root );
$connection   = new Connection( $config );
$pdo          = $connection->get_connection();
$prefix       = (string) ( $config[ Constants::DB_PREFIX ] ?? 'peakurl_' );
$content_dir  = $env->get_content_path();

$runner    = BackgroundRunnerFactory::create( $connection, $config );
$scheduler = $runner->get_scheduler();
if ( null === $scheduler ) {
	fwrite( STDERR, "Error: Scheduler could not be initialized.\n" );
	exit( 1 );
}

// Resolve an existing valid test user ID from the database.
$admin_col    = $pdo->query( "SELECT id FROM `{$prefix}users` WHERE role = 'admin' LIMIT 1" )->fetchColumn();
$test_user_id = false !== $admin_col ? (int) $admin_col : 0;
if ( $test_user_id <= 0 ) {
	$first_col    = $pdo->query( "SELECT id FROM `{$prefix}users` ORDER BY id ASC LIMIT 1" )->fetchColumn();
	$test_user_id = false !== $first_col ? (int) $first_col : 1;
}

$results = array();

function record_result( string $job_id, bool $passed, string $details ): void {
	global $results;
	$results[ $job_id ] = array(
		'passed'  => $passed,
		'details' => $details,
	);
	$status_str         = $passed ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
	echo sprintf( "%s %-32s : %s\n", $status_str, $job_id, $details );
}

function get_setting_snapshot( PDO $pdo, string $prefix, string $key ): ?string {
	$stmt = $pdo->prepare( "SELECT setting_value FROM `{$prefix}settings` WHERE setting_key = ?" );
	$stmt->execute( array( $key ) );
	$val = $stmt->fetchColumn();
	return false !== $val ? (string) $val : null;
}

function restore_setting_snapshot( PDO $pdo, string $prefix, string $key, ?string $snapshot ): void {
	if ( null === $snapshot ) {
		$stmt = $pdo->prepare( "DELETE FROM `{$prefix}settings` WHERE setting_key = ?" );
		$stmt->execute( array( $key ) );
	} else {
		$stmt = $pdo->prepare( "REPLACE INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) VALUES (?, ?, 1, NOW())" );
		$stmt->execute( array( $key, $snapshot ) );
	}
}

echo "\n=======================================================================\n";
echo "PeakURL 1.7.1 - Scheduled Background Jobs Verification Suite\n";
echo 'Runtime: PHP ' . PHP_VERSION . ' | Content: ' . $content_dir . "\n";
echo "=======================================================================\n\n";

// -----------------------------------------------------------------------------
// Job 1: peakurl_analytics_retention
// -----------------------------------------------------------------------------
$orig_trash     = get_setting_snapshot( $pdo, $prefix, 'trash_retention_days' );
$orig_analytics = get_setting_snapshot( $pdo, $prefix, 'analytics_retention_days' );
$trash_link_id  = bin2hex( random_bytes( 10 ) );
$active_link_id = bin2hex( random_bytes( 10 ) );
$old_click_id   = bin2hex( random_bytes( 10 ) );

try {
	// Configure 30 days retention for testing.
	$pdo->prepare( "REPLACE INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) VALUES ('trash_retention_days', '30', 1, NOW()), ('analytics_retention_days', '30', 1, NOW())" )->execute();

	// 1. Insert dummy trashed link older than 35 days.
	$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 40 * 86400 ) );
	$pdo->prepare( "INSERT INTO `{$prefix}urls` (id, user_id, short_code, alias, destination_url, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'trashed', ?, ?)" )
		->execute( array( $trash_link_id, $test_user_id, "tc_{$trash_link_id}", "trash_{$trash_link_id}", "https://example.com/test-trash-{$trash_link_id}", $old_date, $old_date ) );

	// 2. Insert dummy active link with click event older than 40 days.
	$pdo->prepare( "INSERT INTO `{$prefix}urls` (id, user_id, short_code, alias, destination_url, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())" )
		->execute( array( $active_link_id, $test_user_id, "ac_{$active_link_id}", "act_{$active_link_id}", "https://example.com/test-active-{$active_link_id}" ) );

	$pdo->prepare( "INSERT INTO `{$prefix}clicks` (id, url_id, clicked_at, visitor_hash, ip_address) VALUES (?, ?, ?, 'testhash', '127.0.0.1')" )
		->execute( array( $old_click_id, $active_link_id, $old_date ) );

	// Run job.
	$res = $scheduler->run_job( 'peakurl_analytics_retention', true );

	$trash_rem = (int) $pdo->query( "SELECT COUNT(*) FROM `{$prefix}urls` WHERE id = '{$trash_link_id}'" )->fetchColumn();
	$click_rem = (int) $pdo->query( "SELECT COUNT(*) FROM `{$prefix}clicks` WHERE id = '{$old_click_id}'" )->fetchColumn();

	$passed = $res->is_success() && 0 === $trash_rem && 0 === $click_rem;
	record_result(
		'peakurl_analytics_retention',
		$passed,
		sprintf(
			'Purged trashed links and clicks older than retention window. Summary: "%s"',
			$res->get_summary() ?? ''
		)
	);
} catch ( \Throwable $e ) {
	record_result( 'peakurl_analytics_retention', false, 'Exception: ' . $e->getMessage() );
} finally {
	// Clean up test data and restore exact settings state.
	$pdo->prepare( "DELETE FROM `{$prefix}urls` WHERE id = ?" )->execute( array( $trash_link_id ) );
	$pdo->prepare( "DELETE FROM `{$prefix}clicks` WHERE url_id = ?" )->execute( array( $active_link_id ) );
	$pdo->prepare( "DELETE FROM `{$prefix}urls` WHERE id = ?" )->execute( array( $active_link_id ) );
	restore_setting_snapshot( $pdo, $prefix, 'trash_retention_days', $orig_trash );
	restore_setting_snapshot( $pdo, $prefix, 'analytics_retention_days', $orig_analytics );
}

// -----------------------------------------------------------------------------
// Job 2: peakurl_import_export_cleanup
// -----------------------------------------------------------------------------
$tmp_dir              = rtrim( $content_dir, '/\\' ) . '/uploads/tmp';
$exp_dir              = rtrim( $content_dir, '/\\' ) . '/exports';
$created_tmp_dir      = false;
$created_exp_dir      = false;
$created_tmp_htaccess = false;
$htaccess_tmp         = $tmp_dir . '/.htaccess';
$stale_tmp            = $tmp_dir . '/test_stale_upload_' . bin2hex( random_bytes( 4 ) ) . '.csv';
$stale_exp            = $exp_dir . '/test_stale_export_' . bin2hex( random_bytes( 4 ) ) . '.csv';
$fresh_tmp            = $tmp_dir . '/test_fresh_upload_' . bin2hex( random_bytes( 4 ) ) . '.csv';

try {
	if ( ! is_dir( $tmp_dir ) ) {
		mkdir( $tmp_dir, 0755, true );
		$created_tmp_dir = true;
	}
	if ( ! is_dir( $exp_dir ) ) {
		mkdir( $exp_dir, 0755, true );
		$created_exp_dir = true;
	}

	if ( ! file_exists( $htaccess_tmp ) ) {
		file_put_contents( $htaccess_tmp, "Deny from all\n" );
		$created_tmp_htaccess = true;
	}

	// Create dummy stale files (25 hours old).
	file_put_contents( $stale_tmp, "url,alias\nhttps://example.com,test\n" );
	touch( $stale_tmp, time() - 90000 );

	file_put_contents( $stale_exp, "url,alias\nhttps://example.com,test\n" );
	touch( $stale_exp, time() - 90000 );

	// Create fresh file (10 minutes old).
	file_put_contents( $fresh_tmp, "url,alias\nhttps://example.com,test\n" );
	touch( $fresh_tmp, time() - 600 );

	// Run job.
	$res = $scheduler->run_job( 'peakurl_import_export_cleanup', true );

	$stale_tmp_deleted = ! file_exists( $stale_tmp );
	$stale_exp_deleted = ! file_exists( $stale_exp );
	$fresh_tmp_kept    = file_exists( $fresh_tmp );
	$htaccess_kept     = file_exists( $htaccess_tmp );

	$passed = $res->is_success() && $stale_tmp_deleted && $stale_exp_deleted && $fresh_tmp_kept && $htaccess_kept;
	record_result(
		'peakurl_import_export_cleanup',
		$passed,
		sprintf(
			'Stale scratch purged (tmp: %s, exp: %s), fresh preserved (%s), .htaccess preserved (%s). Summary: "%s"',
			$stale_tmp_deleted ? 'deleted' : 'present',
			$stale_exp_deleted ? 'deleted' : 'present',
			$fresh_tmp_kept ? 'kept' : 'lost',
			$htaccess_kept ? 'kept' : 'lost',
			$res->get_summary() ?? ''
		)
	);
} catch ( \Throwable $e ) {
	record_result( 'peakurl_import_export_cleanup', false, 'Exception: ' . $e->getMessage() );
} finally {
	// Clean up created files and directories if created by this harness.
	if ( file_exists( $stale_tmp ) ) {
		@unlink( $stale_tmp );
	}
	if ( file_exists( $stale_exp ) ) {
		@unlink( $stale_exp );
	}
	if ( file_exists( $fresh_tmp ) ) {
		@unlink( $fresh_tmp );
	}
	if ( $created_tmp_htaccess && file_exists( $htaccess_tmp ) ) {
		@unlink( $htaccess_tmp );
	}
	if ( $created_tmp_dir && is_dir( $tmp_dir ) ) {
		@rmdir( $tmp_dir );
	}
	if ( $created_exp_dir && is_dir( $exp_dir ) ) {
		@rmdir( $exp_dir );
	}
}

// -----------------------------------------------------------------------------
// Job 3: peakurl_session_cleanup
// -----------------------------------------------------------------------------
$expired_session_id = bin2hex( random_bytes( 16 ) );
$revoked_session_id = bin2hex( random_bytes( 16 ) );
$active_session_id  = bin2hex( random_bytes( 16 ) );

try {
	$past_time = gmdate( 'Y-m-d H:i:s', time() - ( 35 * 86400 ) );

	// Insert expired session.
	$pdo->prepare( "INSERT INTO `{$prefix}sessions` (id, user_id, token_hash, user_agent, ip_address, created_at, last_active_at) VALUES (?, ?, ?, 'ExpiredTest', '127.0.0.1', ?, ?)" )
		->execute( array( $expired_session_id, $test_user_id, hash( 'sha256', 'expired_token' ), $past_time, $past_time ) );

	// Insert revoked session.
	$pdo->prepare( "INSERT INTO `{$prefix}sessions` (id, user_id, token_hash, user_agent, ip_address, created_at, last_active_at, revoked_at) VALUES (?, ?, ?, 'RevokedTest', '127.0.0.1', NOW(), NOW(), NOW())" )
		->execute( array( $revoked_session_id, $test_user_id, hash( 'sha256', 'revoked_token' ) ) );

	// Insert active session.
	$pdo->prepare( "INSERT INTO `{$prefix}sessions` (id, user_id, token_hash, user_agent, ip_address, created_at, last_active_at) VALUES (?, ?, ?, 'ActiveTest', '127.0.0.1', NOW(), NOW())" )
		->execute( array( $active_session_id, $test_user_id, hash( 'sha256', 'active_token' ) ) );

	// Run job.
	$res = $scheduler->run_job( 'peakurl_session_cleanup', true );

	$expired_rem = (int) $pdo->query( "SELECT COUNT(*) FROM `{$prefix}sessions` WHERE id = '{$expired_session_id}'" )->fetchColumn();
	$revoked_rem = (int) $pdo->query( "SELECT COUNT(*) FROM `{$prefix}sessions` WHERE id = '{$revoked_session_id}'" )->fetchColumn();
	$active_rem  = (int) $pdo->query( "SELECT COUNT(*) FROM `{$prefix}sessions` WHERE id = '{$active_session_id}'" )->fetchColumn();

	$passed = $res->is_success() && 0 === $expired_rem && 0 === $revoked_rem && 1 === $active_rem;
	record_result(
		'peakurl_session_cleanup',
		$passed,
		sprintf(
			'Expired and revoked pruned (exp: %d, rev: %d), active preserved (%d rem). Summary: "%s"',
			$expired_rem,
			$revoked_rem,
			$active_rem,
			$res->get_summary() ?? ''
		)
	);
} catch ( \Throwable $e ) {
	record_result( 'peakurl_session_cleanup', false, 'Exception: ' . $e->getMessage() );
} finally {
	$pdo->prepare( "DELETE FROM `{$prefix}sessions` WHERE id IN (?, ?, ?)" )
		->execute( array( $expired_session_id, $revoked_session_id, $active_session_id ) );
}

// -----------------------------------------------------------------------------
// Job 4: peakurl_geoip_update
// -----------------------------------------------------------------------------
try {
	$res    = $scheduler->run_job( 'peakurl_geoip_update', true );
	$msg    = $res->get_summary() ?? $res->get_error() ?? '';
	$passed = $res->is_success() || $res->is_skipped();
	record_result(
		'peakurl_geoip_update',
		$passed,
		sprintf( 'Status: %s. Summary: "%s"', $res->get_status(), $msg )
	);
} catch ( \Throwable $e ) {
	record_result( 'peakurl_geoip_update', false, 'Exception: ' . $e->getMessage() );
}

// -----------------------------------------------------------------------------
// Job 5: peakurl_expired_links
// -----------------------------------------------------------------------------
$exp_link_id = bin2hex( random_bytes( 10 ) );

try {
	$past_exp = gmdate( 'Y-m-d H:i:s', time() - 3600 );

	// Insert active link whose expiration timestamp has elapsed.
	$pdo->prepare( "INSERT INTO `{$prefix}urls` (id, user_id, short_code, alias, destination_url, status, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())" )
		->execute( array( $exp_link_id, $test_user_id, "ex_{$exp_link_id}", "exp_{$exp_link_id}", "https://example.com/test-exp-{$exp_link_id}", $past_exp ) );

	// Run job.
	$res = $scheduler->run_job( 'peakurl_expired_links', true );

	$new_status = (string) $pdo->query( "SELECT status FROM `{$prefix}urls` WHERE id = '{$exp_link_id}'" )->fetchColumn();

	$passed = $res->is_success() && 'expired' === $new_status;
	record_result(
		'peakurl_expired_links',
		$passed,
		sprintf( 'Transitioned due active link to "expired" (DB: "%s"). Summary: "%s"', $new_status, $res->get_summary() ?? '' )
	);
} catch ( \Throwable $e ) {
	record_result( 'peakurl_expired_links', false, 'Exception: ' . $e->getMessage() );
} finally {
	$pdo->prepare( "DELETE FROM `{$prefix}urls` WHERE id = ?" )->execute( array( $exp_link_id ) );
}

// -----------------------------------------------------------------------------
// Job 6: peakurl_cache_cleanup
// -----------------------------------------------------------------------------
try {
	$res    = $scheduler->run_job( 'peakurl_cache_cleanup', true );
	$msg    = $res->get_summary() ?? $res->get_error() ?? '';
	$passed = $res->is_success();
	record_result(
		'peakurl_cache_cleanup',
		$passed,
		sprintf( 'Maintenance completed. Summary: "%s"', $msg )
	);
} catch ( \Throwable $e ) {
	record_result( 'peakurl_cache_cleanup', false, 'Exception: ' . $e->getMessage() );
}

// -----------------------------------------------------------------------------
// Job 7: peakurl_version_check
// -----------------------------------------------------------------------------
$orig_checked_at = get_setting_snapshot( $pdo, $prefix, 'update_last_checked_at' );

try {
	// Set a known past timestamp (1 hour ago) to prove update_last_checked_at is refreshed.
	$past_timestamp = gmdate( 'Y-m-d H:i:s', time() - 3600 );
	$pdo->prepare( "REPLACE INTO `{$prefix}settings` (setting_key, setting_value, autoload, updated_at) VALUES ('update_last_checked_at', ?, 1, NOW())" )
		->execute( array( $past_timestamp ) );

	$res = $scheduler->run_job( 'peakurl_version_check', true );

	$new_checked_at = get_setting_snapshot( $pdo, $prefix, 'update_last_checked_at' );
	$was_updated    = null !== $new_checked_at && $new_checked_at > $past_timestamp;

	$passed = $res->is_success() && $was_updated;
	record_result(
		'peakurl_version_check',
		$passed,
		sprintf(
			'Executed version check and updated checked timestamp (updated: %s). Summary: "%s"',
			$was_updated ? 'yes' : 'no',
			$res->get_summary() ?? ''
		)
	);
} catch ( \Throwable $e ) {
	record_result( 'peakurl_version_check', false, 'Exception: ' . $e->getMessage() );
} finally {
	restore_setting_snapshot( $pdo, $prefix, 'update_last_checked_at', $orig_checked_at );
}

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n=======================================================================\n";
$all_passed = true;
foreach ( $results as $job_id => $data ) {
	if ( ! $data['passed'] ) {
		$all_passed = false;
	}
}

if ( $all_passed ) {
	echo "\033[32mALL 7 SCHEDULED JOBS VERIFIED SUCCESSFULLY WITHOUT RESIDUAL STATE.\033[0m\n";
} else {
	echo "\033[31mVERIFICATION SUITE ENCOUNTERED FAILURES.\033[0m\n";
	exit( 1 );
}
echo "=======================================================================\n\n";
