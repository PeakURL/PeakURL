<?php
declare(strict_types=1);

$public_dir   = '/app/app/public';
$request_uri  = $_SERVER['REQUEST_URI'] ?? '/';
$request_path = rawurldecode( (string) parse_url( $request_uri, PHP_URL_PATH ) );

if ( ! is_dir( $public_dir ) || ! is_file( $public_dir . '/index.php' ) ) {
	http_response_code( 503 );
	echo 'PeakURL app dev server is not ready.';
	return true;
}

$candidate_path = realpath( $public_dir . $request_path );

if (
	false !== $candidate_path &&
	0 === strpos( $candidate_path, $public_dir . DIRECTORY_SEPARATOR ) &&
	is_file( $candidate_path )
) {
	return false;
}

require $public_dir . '/index.php';
return true;
