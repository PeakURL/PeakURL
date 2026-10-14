<?php
/**
 * Unit tests for UserAgentParser and Matomo detection adapter.
 *
 * @package PeakURL\Tests\Unit\Utils
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Analytics\Visitor;
use PeakURL\Utils\UserAgentCacheAdapter;
use PeakURL\Utils\UserAgentParser;

class UserAgentParserTest extends TestCase {

	public function test_output_contains_exactly_three_string_keys(): void {
		$result = UserAgentParser::parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/123.0.0.0 Safari/537.36' );

		$this->assertSame( array( 'browser', 'os', 'device' ), array_keys( $result ) );
		$this->assertIsString( $result['browser'] );
		$this->assertIsString( $result['os'] );
		$this->assertIsString( $result['device'] );
		$this->assertLessThanOrEqual( 64, strlen( $result['browser'] ) );
		$this->assertLessThanOrEqual( 64, strlen( $result['os'] ) );
		$this->assertLessThanOrEqual( 64, strlen( $result['device'] ) );
	}

	public function test_visitor_parse_user_agent_delegates_to_parser(): void {
		$ua     = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
		$direct = UserAgentParser::parse( $ua );
		$via    = Visitor::parse_user_agent( $ua );

		$this->assertSame( $direct, $via );
		$this->assertSame( 'Safari', $via['browser'] );
		$this->assertSame( 'iOS', $via['os'] );
		$this->assertSame( 'Mobile', $via['device'] );
	}

	public function test_ios_browsers_and_devices(): void {
		// iPhone Safari
		$iphone_safari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Mobile/15E148 Safari/604.1';
		$res           = UserAgentParser::parse( $iphone_safari );
		$this->assertSame( 'Safari', $res['browser'] );
		$this->assertSame( 'iOS', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );

		// iOS Chrome (CriOS)
		$crios = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/123.0.6312.52 Mobile/15E148 Safari/604.1';
		$res   = UserAgentParser::parse( $crios );
		$this->assertSame( 'Chrome', $res['browser'] );
		$this->assertSame( 'iOS', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );

		// iOS Firefox (FxiOS)
		$fxios = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/124.0 Mobile/15E148 Safari/605.1.15';
		$res   = UserAgentParser::parse( $fxios );
		$this->assertSame( 'Firefox', $res['browser'] );
		$this->assertSame( 'iOS', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );

		// iOS Edge (EdgiOS)
		$edgios = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 EdgiOS/123.0.2420.65 Mobile/15E148 Safari/605.1.15';
		$res    = UserAgentParser::parse( $edgios );
		$this->assertSame( 'Edge', $res['browser'] );
		$this->assertSame( 'iOS', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );

		// iPod Touch
		$ipod = 'Mozilla/5.0 (iPod touch; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1';
		$res  = UserAgentParser::parse( $ipod );
		$this->assertSame( 'Safari', $res['browser'] );
		$this->assertSame( 'iOS', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );

		// Classic iPad
		$ipad_classic = 'Mozilla/5.0 (iPad; CPU OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Mobile/15E148 Safari/604.1';
		$res          = UserAgentParser::parse( $ipad_classic );
		$this->assertSame( 'Safari', $res['browser'] );
		$this->assertSame( 'iPadOS', $res['os'] );
		$this->assertSame( 'Tablet', $res['device'] );

		// Desktop-class iPad (ambiguous header presenting as Macintosh Intel Mac OS X)
		$ipad_desktop = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Safari/605.1.15';
		$res          = UserAgentParser::parse( $ipad_desktop );
		$this->assertSame( 'Safari', $res['browser'] );
		$this->assertSame( 'macOS', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );
	}

	public function test_desktop_platforms_and_browsers(): void {
		// Windows Chrome
		$win_chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
		$res        = UserAgentParser::parse( $win_chrome );
		$this->assertSame( 'Chrome', $res['browser'] );
		$this->assertSame( 'Windows', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );

		// Windows Firefox
		$win_firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0';
		$res         = UserAgentParser::parse( $win_firefox );
		$this->assertSame( 'Firefox', $res['browser'] );
		$this->assertSame( 'Windows', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );

		// Windows Edge
		$win_edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36 Edg/123.0.2420.65';
		$res      = UserAgentParser::parse( $win_edge );
		$this->assertSame( 'Edge', $res['browser'] );
		$this->assertSame( 'Windows', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );

		// Windows Opera
		$win_opera = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 OPR/108.0.0.0';
		$res       = UserAgentParser::parse( $win_opera );
		$this->assertSame( 'Opera', $res['browser'] );
		$this->assertSame( 'Windows', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );

		// macOS Safari
		$mac_safari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Safari/605.1.15';
		$res        = UserAgentParser::parse( $mac_safari );
		$this->assertSame( 'Safari', $res['browser'] );
		$this->assertSame( 'macOS', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );

		// macOS Chrome
		$mac_chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
		$res        = UserAgentParser::parse( $mac_chrome );
		$this->assertSame( 'Chrome', $res['browser'] );
		$this->assertSame( 'macOS', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );

		// Linux Firefox
		$linux_ff = 'Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0';
		$res      = UserAgentParser::parse( $linux_ff );
		$this->assertSame( 'Firefox', $res['browser'] );
		$this->assertSame( 'Linux', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );

		// Linux Chrome
		$linux_chrome = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
		$res          = UserAgentParser::parse( $linux_chrome );
		$this->assertSame( 'Chrome', $res['browser'] );
		$this->assertSame( 'Linux', $res['os'] );
		$this->assertSame( 'Desktop', $res['device'] );
	}

	public function test_android_devices_and_browsers(): void {
		// Android Phone Chrome
		$phone_chrome = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.6312.80 Mobile Safari/537.36';
		$res          = UserAgentParser::parse( $phone_chrome );
		$this->assertSame( 'Chrome', $res['browser'] );
		$this->assertSame( 'Android', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );

		// Android Tablet Chrome
		$tablet_chrome = 'Mozilla/5.0 (Linux; Android 14; SM-X910) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.6312.80 Safari/537.36';
		$res           = UserAgentParser::parse( $tablet_chrome );
		$this->assertSame( 'Chrome', $res['browser'] );
		$this->assertSame( 'Android', $res['os'] );
		$this->assertSame( 'Tablet', $res['device'] );

		// Samsung Internet
		$samsung = 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S928B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.5938.140 Mobile Safari/537.36';
		$res     = UserAgentParser::parse( $samsung );
		$this->assertSame( 'Samsung Internet', $res['browser'] );
		$this->assertSame( 'Android', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );

		// Android Opera
		$android_opera = 'Mozilla/5.0 (Linux; Android 14; SM-G998B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Mobile Safari/537.36 OPR/80.1.4244.77335';
		$res           = UserAgentParser::parse( $android_opera );
		$this->assertSame( 'Opera', $res['browser'] );
		$this->assertSame( 'Android', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );

		// Android Edge
		$android_edge = 'Mozilla/5.0 (Linux; Android 14; SM-G998B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Mobile Safari/537.36 EdgA/123.0.2420.65';
		$res          = UserAgentParser::parse( $android_edge );
		$this->assertSame( 'Edge', $res['browser'] );
		$this->assertSame( 'Android', $res['os'] );
		$this->assertSame( 'Mobile', $res['device'] );
	}

	public function test_special_devices(): void {
		// Gaming Console (PS5)
		$ps5 = 'Mozilla/5.0 (PlayStation; PlayStation 5/2.26) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0 Safari/605.1.15';
		$res = UserAgentParser::parse( $ps5 );
		$this->assertSame( 'NetFront', $res['browser'] );
		$this->assertSame( 'PlayStation', $res['os'] );
		$this->assertSame( 'Console', $res['device'] );

		// Smart TV
		$smart_tv = 'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.79 Safari/537.36 WebAppManager';
		$res      = UserAgentParser::parse( $smart_tv );
		$this->assertSame( 'Chrome', $res['browser'] );
		$this->assertSame( 'webOS', $res['os'] );
		$this->assertSame( 'TV', $res['device'] );
	}

	public function test_bots_and_crawlers_return_safe_fallbacks(): void {
		$googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$res       = UserAgentParser::parse( $googlebot );
		$this->assertSame( UserAgentParser::FALLBACK_BROWSER, $res['browser'] );
		$this->assertSame( UserAgentParser::FALLBACK_OS, $res['os'] );
		$this->assertSame( UserAgentParser::FALLBACK_DEVICE, $res['device'] );

		$bingbot = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';
		$res     = UserAgentParser::parse( $bingbot );
		$this->assertSame( UserAgentParser::FALLBACK_BROWSER, $res['browser'] );
		$this->assertSame( UserAgentParser::FALLBACK_OS, $res['os'] );
		$this->assertSame( UserAgentParser::FALLBACK_DEVICE, $res['device'] );
	}

	public function test_empty_whitespace_and_malformed_inputs(): void {
		$empty = UserAgentParser::parse( '' );
		$this->assertSame( 'Browser', $empty['browser'] );
		$this->assertSame( 'Unknown OS', $empty['os'] );
		$this->assertSame( 'Unknown Device', $empty['device'] );

		$whitespace = UserAgentParser::parse( "   \t\r\n   " );
		$this->assertSame( 'Browser', $whitespace['browser'] );
		$this->assertSame( 'Unknown OS', $whitespace['os'] );
		$this->assertSame( 'Unknown Device', $whitespace['device'] );

		$junk = UserAgentParser::parse( 'NonExistentApplication/1.0.0 (Unrecognized System)' );
		$this->assertSame( 'Browser', $junk['browser'] );
		$this->assertSame( 'Unknown OS', $junk['os'] );
		$this->assertSame( 'Unknown Device', $junk['device'] );
	}

	public function test_oversized_boundary_and_deterministic_fallback(): void {
		// Documented Policy: maximum 2,048 bytes of TRIMMED User-Agent input.
		$base_ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36 CustomExtension/';
		$pad_len  = 2048 - strlen( $base_ua );
		$exact_ua = $base_ua . str_repeat( 'a', $pad_len );

		// 1. Exact 2,048-byte trimmed input with non-trimmable padding proceeds to classification.
		$this->assertSame( 2048, strlen( $exact_ua ) );
		$this->assertSame( 2048, strlen( trim( $exact_ua ) ) );
		$res_limit = UserAgentParser::parse( $exact_ua );
		$this->assertSame( 'Chrome', $res_limit['browser'] );
		$this->assertSame( 'Windows', $res_limit['os'] );
		$this->assertSame( 'Desktop', $res_limit['device'] );

		// 2. Exact 2,048-byte trimmed input surrounded by whitespace must also pass under the trimmed-input policy.
		$whitespace_padded_ua = "   \t\r\n  " . $exact_ua . "   \n  ";
		$this->assertGreaterThan( 2048, strlen( $whitespace_padded_ua ) );
		$this->assertSame( 2048, strlen( trim( $whitespace_padded_ua ) ) );
		$res_ws = UserAgentParser::parse( $whitespace_padded_ua );
		$this->assertSame( 'Chrome', $res_ws['browser'] );
		$this->assertSame( 'Windows', $res_ws['os'] );
		$this->assertSame( 'Desktop', $res_ws['device'] );

		// 3. Exactly one byte over the limit after trimming (2,049 bytes) must immediately return deterministic fallback.
		$over_limit_ua = $exact_ua . 'b';
		$this->assertSame( 2049, strlen( $over_limit_ua ) );
		$this->assertSame( 2049, strlen( trim( $over_limit_ua ) ) );
		$res_over = UserAgentParser::parse( $over_limit_ua );
		$this->assertSame( UserAgentParser::FALLBACK_BROWSER, $res_over['browser'] );
		$this->assertSame( UserAgentParser::FALLBACK_OS, $res_over['os'] );
		$this->assertSame( UserAgentParser::FALLBACK_DEVICE, $res_over['device'] );

		// 4. Over-limit 2,049-byte trimmed input with surrounding whitespace also returns fallback.
		$over_ws_ua = '  ' . $over_limit_ua . '  ';
		$this->assertSame( 2049, strlen( trim( $over_ws_ua ) ) );
		$res_over_ws = UserAgentParser::parse( $over_ws_ua );
		$this->assertSame( UserAgentParser::FALLBACK_BROWSER, $res_over_ws['browser'] );
		$this->assertSame( UserAgentParser::FALLBACK_OS, $res_over_ws['os'] );
		$this->assertSame( UserAgentParser::FALLBACK_DEVICE, $res_over_ws['device'] );

		// 5. Valid prefix followed by large excessive content (>3,000 characters) must immediately return fallback.
		$huge_prefix_ua = $base_ua . str_repeat( 'X', 3000 );
		$this->assertGreaterThan( 2048, strlen( trim( $huge_prefix_ua ) ) );
		$res_huge = UserAgentParser::parse( $huge_prefix_ua );
		$this->assertSame( UserAgentParser::FALLBACK_BROWSER, $res_huge['browser'] );
		$this->assertSame( UserAgentParser::FALLBACK_OS, $res_huge['os'] );
		$this->assertSame( UserAgentParser::FALLBACK_DEVICE, $res_huge['device'] );
	}

	public function test_cache_adapter_methods_and_isolation(): void {
		$adapter = new UserAgentCacheAdapter();
		$test_id = 'test_key_' . bin2hex( random_bytes( 4 ) );

		$this->assertFalse( $adapter->contains( $test_id ) );
		$this->assertFalse( $adapter->fetch( $test_id ) );

		$data = array(
			'sample' => 'regex_table',
			'count'  => 42,
		);
		$this->assertTrue( $adapter->save( $test_id, $data, 60 ) );
		$this->assertTrue( $adapter->contains( $test_id ) );
		$this->assertSame( $data, $adapter->fetch( $test_id ) );

		$this->assertTrue( $adapter->delete( $test_id ) );
		$this->assertFalse( $adapter->contains( $test_id ) );
		$this->assertFalse( $adapter->fetch( $test_id ) );
	}

	public function test_cache_adapter_flush_all_invalidates_across_instances_and_preserves_unrelated_keys(): void {
		$adapter = new UserAgentCacheAdapter();
		$test_id = 'test_inval_' . bin2hex( random_bytes( 4 ) );
		$data    = array(
			'parser' => 'AbstractParser',
			'rules'  => array( 'a', 'b', 'c' ),
		);

		// 1. Set an unrelated application cache key in PeakURL cache.
		if ( function_exists( 'peakurl_cache_set' ) ) {
			peakurl_cache_set( 'unrelated_app_token', 'preserve_me_12345', 3600 );
		}

		// 2. Save Matomo entry and verify it is retrievable.
		$this->assertTrue( $adapter->save( $test_id, $data, 300 ) );
		$this->assertSame( $data, $adapter->fetch( $test_id ) );
		$this->assertTrue( $adapter->contains( $test_id ) );

		// 3. Flush all Matomo cache entries.
		$this->assertTrue( $adapter->flushAll() );

		// 4. Verify entry is no longer retrievable from current adapter.
		$this->assertFalse( $adapter->fetch( $test_id ) );
		$this->assertFalse( $adapter->contains( $test_id ) );

		// 5. Verify entry is no longer retrievable from a new adapter instance backed by the same backend.
		$new_adapter = new UserAgentCacheAdapter();
		$this->assertFalse( $new_adapter->fetch( $test_id ) );
		$this->assertFalse( $new_adapter->contains( $test_id ) );

		// 6. Assert that unrelated application cache key remains completely untouched.
		if ( function_exists( 'peakurl_cache_get' ) ) {
			$this->assertSame( 'preserve_me_12345', peakurl_cache_get( 'unrelated_app_token' ) );
		}
	}

	public function test_cache_adapter_flush_all_across_independent_php_processes(): void {
		$root_dir       = dirname( __DIR__, 4 );
		$temp_dir       = sys_get_temp_dir() . '/peakurl_test_proc_cache_' . bin2hex( random_bytes( 4 ) );
		$temp_cache_dir = $temp_dir . '/cache';
		mkdir( $temp_cache_dir, 0755, true );

		try {
			// Single minimal test worker fixture handling distinct actions across separate PHP CLI processes.
			$worker_code = sprintf(
				'<?php
define("ABSPATH", "%s/");
require_once "%s/server/vendor/autoload.php";
require_once "%s/server/core/config/constants.php";
require_once "%s/server/core/config/environment.php";
require_once "%s/server/services/cache/functions.php";
require_once "%s/server/utils/user-agent-cache-adapter.php";

$GLOBALS["bench_cache_path"] = $argv[1] ?? "";
$action                      = $argv[2] ?? "";

function get_peakurl_config() {
	return [
		\\PeakURL\\Core\\Config\\Constants::CACHE_ENABLED => true,
		\\PeakURL\\Core\\Config\\Constants::CACHE_DRIVER  => "file",
		\\PeakURL\\Core\\Config\\Constants::CACHE_PATH    => $GLOBALS["bench_cache_path"],
	];
}

$adapter = new \\PeakURL\\Utils\\UserAgentCacheAdapter();

if ("writer" === $action) {
	$saved      = $adapter->save("proc_test_key", ["data" => "matomo_rules"]);
	$unrel      = peakurl_cache_set("proc_unrelated_token", "preserved_data", 3600);
	$fetched    = $adapter->fetch("proc_test_key");
	$gen_marker = peakurl_cache_get("matomo_dd_gen", 1);
	echo json_encode([
		"status"     => "ok",
		"saved"      => $saved,
		"unrel"      => $unrel,
		"fetched"    => $fetched,
		"gen_marker" => $gen_marker,
	]);
	exit(0);
}

if ("invalidator" === $action) {
	$exists_before  = $adapter->contains("proc_test_key");
	$fetched_before = $adapter->fetch("proc_test_key");
	$gen_before     = peakurl_cache_get("matomo_dd_gen", 1);
	$flushed        = $adapter->flushAll();
	$gen_after      = peakurl_cache_get("matomo_dd_gen", 1);
	$unrelated      = peakurl_cache_get("proc_unrelated_token");
	echo json_encode([
		"status"         => "ok",
		"exists_before"  => $exists_before,
		"fetched_before" => $fetched_before,
		"flushed"        => $flushed,
		"gen_before"     => $gen_before,
		"gen_after"      => $gen_after,
		"unrelated"      => $unrelated,
	]);
	exit(0);
}

if ("reader" === $action) {
	$fetched_old  = $adapter->fetch("proc_test_key");
	$contains_old = $adapter->contains("proc_test_key");
	$unrelated    = peakurl_cache_get("proc_unrelated_token");
	$saved_new    = $adapter->save("proc_test_key", ["data" => "new_generation_rules"]);
	$fetched_new  = $adapter->fetch("proc_test_key");
	echo json_encode([
		"status"       => "ok",
		"fetched_old"  => $fetched_old,
		"contains_old" => $contains_old,
		"unrelated"    => $unrelated,
		"saved_new"    => $saved_new,
		"fetched_new"  => $fetched_new,
	]);
	exit(0);
}

http_response_code(400);
echo json_encode(["status" => "error", "message" => "Unknown action: " . $action]);
exit(1);
',
				$root_dir,
				$root_dir,
				$root_dir,
				$root_dir,
				$root_dir,
				$root_dir
			);

			$worker_file = $temp_dir . '/cache_worker.php';
			file_put_contents( $worker_file, $worker_code );

			$run_worker = function ( string $action ) use ( $worker_file, $temp_cache_dir ): array {
				$command      = escapeshellarg( PHP_BINARY ) . ' ' .
					escapeshellarg( $worker_file ) . ' ' .
					escapeshellarg( $temp_cache_dir ) . ' ' .
					escapeshellarg( $action );
				$output_lines = array();
				$exit_code    = 0;
				exec( $command, $output_lines, $exit_code );

				$this->assertSame( 0, $exit_code, "Subprocess {$action} must exit with code 0." );
				$raw_output = trim( implode( "\n", $output_lines ) );
				$decoded    = json_decode( $raw_output, true );
				$this->assertIsArray( $decoded, "Subprocess {$action} output must decode as JSON: {$raw_output}" );
				$this->assertSame( 'ok', $decoded['status'] ?? '' );

				return $decoded;
			};

			// 1. Process A — Writer: stores entry K and unrelated token U. Confirms persistence, exits without flush.
			$res_a = $run_worker( 'writer' );
			$this->assertTrue( $res_a['saved'], 'Process A must save entry K.' );
			$this->assertTrue( $res_a['unrel'], 'Process A must save unrelated token U.' );
			$this->assertSame( array( 'data' => 'matomo_rules' ), $res_a['fetched'], 'Process A must read back entry K.' );
			$gen_a = (int) $res_a['gen_marker'];

			// 2. Process B — Invalidator: boots fresh process without saved-key knowledge.
			// Proves entry K is present, invokes flushAll(), asserts generation marker changed and U survives.
			$res_b = $run_worker( 'invalidator' );
			$this->assertTrue( $res_b['exists_before'], 'Process B must observe entry K before flush.' );
			$this->assertSame( array( 'data' => 'matomo_rules' ), $res_b['fetched_before'], 'Process B must fetch entry K before flush.' );
			$this->assertTrue( $res_b['flushed'], 'Process B flushAll() must return true.' );
			$this->assertGreaterThan( $gen_a, (int) $res_b['gen_after'], 'Generation marker must advance after Process B flush.' );
			$this->assertSame( 'preserved_data', $res_b['unrelated'], 'Unrelated application token must survive Process B flush.' );

			// 3. Process C — Independent Reader: fresh process boots with same backend.
			// Proves K is inaccessible via fetch() and contains(), U survives, and new generation writes work.
			$res_c = $run_worker( 'reader' );
			$this->assertFalse( $res_c['fetched_old'], 'Old entry K must return false in Process C.' );
			$this->assertFalse( $res_c['contains_old'], 'Old entry K must report contains() === false in Process C.' );
			$this->assertSame( 'preserved_data', $res_c['unrelated'], 'Unrelated application token must remain intact in Process C.' );
			$this->assertTrue( $res_c['saved_new'], 'Writing a new entry under the new generation must succeed.' );
			$this->assertSame( array( 'data' => 'new_generation_rules' ), $res_c['fetched_new'], 'New generation entry must be readable.' );
		} finally {
			// Safely purge disposable subprocess files and cache directory.
			exec( 'rm -rf ' . escapeshellarg( $temp_dir ) );
		}
	}

	public function test_cache_adapter_flush_all_returns_false_when_generation_write_fails(): void {
		$root_dir = dirname( __DIR__, 4 );
		$temp_dir = sys_get_temp_dir() . '/peakurl_test_ro_cache_' . bin2hex( random_bytes( 4 ) );
		mkdir( $temp_dir, 0755, true );

		try {
			// Simulate persistent write failure: create cache directory and make it non-writable before flush.
			$worker_code = sprintf(
				'<?php
define("ABSPATH", "%s/");
require_once "%s/server/vendor/autoload.php";
require_once "%s/server/core/config/constants.php";
require_once "%s/server/core/config/environment.php";
require_once "%s/server/services/cache/functions.php";
require_once "%s/server/utils/user-agent-cache-adapter.php";

function get_peakurl_config() {
	return [
		\\PeakURL\\Core\\Config\\Constants::CACHE_ENABLED => true,
		\\PeakURL\\Core\\Config\\Constants::CACHE_DRIVER  => "file",
		\\PeakURL\\Core\\Config\\Constants::CACHE_PATH    => "%s/cache",
	];
}

$adapter = new \\PeakURL\\Utils\\UserAgentCacheAdapter();
$adapter->save("write_fail_key", ["data" => 123]);

// Make directory non-writable so peakurl_cache_set fails when persisting the generation bump.
chmod("%s/cache/general", 0555);
chmod("%s/cache", 0555);

$flushed = $adapter->flushAll();
echo json_encode(["flushed" => $flushed]);
',
				$root_dir,
				$root_dir,
				$root_dir,
				$root_dir,
				$root_dir,
				$root_dir,
				$temp_dir,
				$temp_dir,
				$temp_dir
			);

			$worker_file = $temp_dir . '/fail_worker.php';
			file_put_contents( $worker_file, $worker_code );
			$command      = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker_file );
			$output_lines = array();
			$exit_code    = 0;
			exec( $command, $output_lines, $exit_code );

			$this->assertSame( 0, $exit_code, 'Fail worker must exit with code 0.' );
			$res = json_decode( trim( implode( "\n", $output_lines ) ), true );

			$this->assertIsArray( $res );
			$this->assertFalse( $res['flushed'], 'flushAll() must return false when generation persistence fails.' );
		} finally {
			chmod( $temp_dir . '/cache/general', 0755 );
			chmod( $temp_dir . '/cache', 0755 );
			exec( 'rm -rf ' . escapeshellarg( $temp_dir ) );
		}
	}

	public function test_cache_adapter_handles_empty_arrays_and_falsy_values(): void {
		$adapter  = new UserAgentCacheAdapter();
		$empty_id = 'empty_key_' . bin2hex( random_bytes( 4 ) );

		$this->assertTrue( $adapter->save( $empty_id, array() ) );
		$this->assertSame( array(), $adapter->fetch( $empty_id ) );
		$this->assertTrue( $adapter->contains( $empty_id ) );

		$this->assertTrue( $adapter->delete( $empty_id ) );
		$this->assertFalse( $adapter->fetch( $empty_id ) );
	}
}
