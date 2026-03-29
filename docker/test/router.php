<?php
declare(strict_types=1);

$release_dir = '/app/release/peakurl';
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$request_path = rawurldecode((string) parse_url($request_uri, PHP_URL_PATH));

if (!is_dir($release_dir) || !is_file($release_dir . '/index.php')) {
	http_response_code(503);
	echo 'PeakURL release test is not ready. Run npm run release:build first.';
	return true;
}

$candidate_path = realpath($release_dir . $request_path);

if (
	false !== $candidate_path &&
	0 === strpos($candidate_path, $release_dir . DIRECTORY_SEPARATOR) &&
	is_file($candidate_path)
) {
	return false;
}

require $release_dir . '/index.php';
return true;
