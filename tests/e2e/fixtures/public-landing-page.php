<?php
/**
 * Render the source landing page for browser presentation checks.
 *
 * @package PeakURL\Tests
 */

declare(strict_types=1);

putenv( 'PEAKURL_DEV=true' );
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
require ABSPATH . 'server/vendor/autoload.php';

set_i18n_service( new \PeakURL\Services\I18n( array( \PeakURL\Core\Config\Constants::SITE_LANGUAGE => $argv[1] ?? 'en_US' ) ) );
echo get_landing_page_html( login_path: '/installed/login' );
