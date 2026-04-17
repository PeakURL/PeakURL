<?php
declare(strict_types=1);

$public_dir   = '/app/app/public';
$request_uri  = $_SERVER['REQUEST_URI'] ?? '/';
$request_path = rawurldecode( (string) parse_url( $request_uri, PHP_URL_PATH ) );
$favicon_map  = array(
	'/favicon.png'          => array(
		'path'         => '/app/content/uploads/favicon/favicon.png',
		'content_type' => 'image/png',
	),
	'/favicon.ico'          => array(
		'path'         => '/app/content/uploads/favicon/favicon.png',
		'content_type' => 'image/png',
	),
	'/apple-touch-icon.png' => array(
		'path'         => '/app/content/uploads/favicon/favicon.png',
		'content_type' => 'image/png',
	),
	'/site.webmanifest'     => array(
		'path'         => '/app/content/uploads/favicon/site.webmanifest',
		'content_type' => 'application/manifest+json; charset=utf-8',
	),
);

if ( ! is_dir( $public_dir ) || ! is_file( $public_dir . '/index.php' ) ) {
	http_response_code( 503 );
	echo 'PeakURL app dev server is not ready.';
	return true;
}

if ( isset( $favicon_map[ $request_path ] ) ) {
	$file      = (string) $favicon_map[ $request_path ]['path'];
	$file_type = (string) $favicon_map[ $request_path ]['content_type'];

	if ( ! is_file( $file ) ) {
		http_response_code( 404 );
		return true;
	}

	header( 'Content-Type: ' . $file_type );
	header( 'Cache-Control: public, max-age=3600' );
	header( 'Content-Length: ' . (string) filesize( $file ) );
	readfile( $file );
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
