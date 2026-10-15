<?php
/**
 * Test fixture helper for GeoIP synthetic databases and archives.
 *
 * Provides deterministic binary MMDB and upstream archive generation for offline
 * unit and integration tests without external network or MaxMind dependencies.
 *
 * @package PeakURL\Tests\Support
 */

declare(strict_types=1);

namespace PeakURL\Tests\Support;

use Phar;
use PharData;
use RuntimeException;

class GeoipTestFixture {

	/**
	 * Default build epoch (2026-10-15T00:00:00 UTC).
	 */
	public const DEFAULT_BUILD_EPOCH = 1790952592;

	/**
	 * Generate a deterministic, valid synthetic GeoLite2-City binary MMDB.
	 *
	 * Used for offline verification and local CI without real MaxMind downloads.
	 *
	 * @param string $target_path   Target MMDB output file path.
	 * @param int    $build_epoch   Build epoch timestamp.
	 * @param string $database_type Database edition string (default 'GeoLite2-City').
	 * @return void
	 *
	 * @throws RuntimeException When writing file fails.
	 */
	public static function generate_city_mmdb(
		string $target_path,
		int $build_epoch = self::DEFAULT_BUILD_EPOCH,
		string $database_type = 'GeoLite2-City'
	): void {
		$metadata_raw = base64_decode(
			'q83vTWF4TWluZC5jb23pW2JpbmFyeV9mb3JtYXRfbWFqb3JfdmVyc2lvbqECW2JpbmFyeV9mb3JtYXRfbWlub3JfdmVyc2lvbqBLYnVpbGRfZXBvY2gEAmq/xJBNZGF0YWJhc2VfdHlwZU1HZW9MaXRlMi1DaXR5S2Rlc2NyaXB0aW9u4UJlblZHZW9MaXRlMiBDaXR5IGRhdGFiYXNlSmlwX3ZlcnNpb26hBklsYW5ndWFnZXMIBEJkZUJlbkJlc0JmckJqYUVwdC1CUkJydUV6aC1DTkpub2RlX2NvdW50w1xEM0tyZWNvcmRfc2l6ZaEc'
		);
		$metadata_raw = str_replace( "node_count\xc3\x5c\x44\x33", "node_count\xc1\x01", $metadata_raw );
		$metadata_raw = str_replace( "record_size\xa1\x1c", "record_size\xa1\x18", $metadata_raw );

		// Pack custom build epoch into metadata if different from default 1790952592 (0x6abfc490).
		$default_epoch_bytes = "\x04\x02\x6a\xbf\xc4\x90";
		$custom_epoch_bytes  = "\x04\x02" . pack( 'N', $build_epoch );
		$metadata_raw        = str_replace( $default_epoch_bytes, $custom_epoch_bytes, $metadata_raw );

		if ( 'GeoLite2-City' !== $database_type ) {
			$metadata_raw = str_replace( 'GeoLite2-City', substr( str_pad( $database_type, 13 ), 0, 13 ), $metadata_raw );
		}

		$city_val     = "\xe1\x45names\xe1\x42en\x4dMountain View";
		$country_val  = "\xe2\x48iso_code\x42US\x45names\xe1\x42en\x4dUnited States";
		$data_section = "\xe2\x47country" . $country_val . "\x44city" . $city_val;
		$tree         = "\x00\x00\x11\x00\x00\x11";

		$content = $tree . str_repeat( "\x00", 16 ) . $data_section . $metadata_raw;

		$dir = dirname( $target_path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		if ( false === file_put_contents( $target_path, $content ) ) {
			throw new RuntimeException( "Failed to write synthetic MMDB file: {$target_path}" );
		}
	}

	/**
	 * Create a .tar.gz archive with specified entry map.
	 *
	 * @param string                $archive_path Target tar.gz file path.
	 * @param array<string, string> $entries      Map of relative path => file contents.
	 * @return void
	 */
	public static function create_tar_gz( string $archive_path, array $entries ): void {
		$tar_path = preg_replace( '/\.gz$/', '', $archive_path );
		if ( file_exists( $tar_path ) ) {
			Phar::unlinkArchive( $tar_path );
			@unlink( $tar_path );
		}

		$phar = new PharData( $tar_path );
		foreach ( $entries as $path => $content ) {
			$phar->addFromString( $path, $content );
		}

		$gz = $phar->compress( Phar::GZ );
		unset( $phar, $gz );
		Phar::unlinkArchive( $tar_path );
		@unlink( $tar_path );
	}

	/**
	 * Create a valid upstream GeoLite2 City tar.gz archive with MMDB, LICENSE.txt, and COPYRIGHT.txt.
	 *
	 * @param string $archive_path Target archive path.
	 * @param int    $build_epoch  MMDB build epoch.
	 * @return void
	 */
	public static function create_valid_upstream_archive(
		string $archive_path,
		int $build_epoch = self::DEFAULT_BUILD_EPOCH
	): void {
		$temp_mmdb = sys_get_temp_dir() . '/temp_city_' . bin2hex( random_bytes( 4 ) ) . '.mmdb';
		self::generate_city_mmdb( $temp_mmdb, $build_epoch );
		$mmdb_data = (string) file_get_contents( $temp_mmdb );
		@unlink( $temp_mmdb );

		$entries = array(
			'GeoLite2-City_20261015/GeoLite2-City.mmdb' => $mmdb_data,
			'GeoLite2-City_20261015/LICENSE.txt'        => "MaxMind GeoLite2 End User License Agreement\nAll rights reserved.\n",
			'GeoLite2-City_20261015/COPYRIGHT.txt'      => "Copyright 2026 MaxMind, Inc. All rights reserved.\n",
		);

		self::create_tar_gz( $archive_path, $entries );
	}
}
