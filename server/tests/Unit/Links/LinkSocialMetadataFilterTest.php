<?php
/**
 * Unit tests for link_social_metadata filter hook (Phase 2-B).
 *
 * Verifies that:
 * - link_social_metadata allows controlled customization of title, description, imageUrl, and siteName
 * - Empty imageUrl switches card type to summary and omits Open Graph image tags
 * - Invalid callback return types (non-arrays, non-strings, malformed arrays) fall back to original metadata
 * - Attribute and HTML body escaping prevents XSS injection from untrusted metadata
 * - Dangerous image URL schemes (javascript:, data:, ftp:) fall back to trusted image URLs
 * - Canonical routing and link identity (url and destination) are immutable and cannot be tampered with
 * - Missing, inactive, and expired links bypass social preview and never invoke filters
 * - Password-protected links provide safe public previews without exposing password hashes or credentials
 * - No registered listeners executes with zero overhead and returns standard preview
 * - Callback priority and argument ordering are strictly preserved
 * - Exceptions thrown by filter callbacks do not break the public response and fall back gracefully
 * - Public preview HTML remains identical to baseline when no filters are attached
 *
 * @package PeakURL\Tests\Unit\Links
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PeakURL\Features\Links\Controller as LinksController;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Http\Request;
use RuntimeException;
use stdClass;

class LinkSocialMetadataFilterTest extends TestCase {

	private MockObject&LinksService $links_service;
	private LinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->links_service = $this->createMock( LinksService::class );
		$this->controller    = new LinksController( $this->links_service );
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	/**
	 * Build a mock social preview request from a crawler user-agent.
	 *
	 * @param string $path    Request path.
	 * @param string $crawler Crawler user-agent string.
	 * @param string $method  HTTP method.
	 * @return Request
	 */
	private function create_crawler_request(
		string $path = '/test-link',
		string $crawler = 'Twitterbot/1.0',
		string $method = 'GET'
	): Request {
		$code = ltrim( $path, '/' );

		return new Request(
			$method,
			$path,
			array(),
			array(),
			array( 'User-Agent' => $crawler ),
			array( 'id' => $code ),
			array(
				'REMOTE_ADDR'     => '192.0.2.1',
				'HTTP_USER_AGENT' => $crawler,
			)
		);
	}

	/**
	 * Return standard preview data fixtures for test links.
	 *
	 * @param array<string, mixed> $link_overrides    Custom overrides for link array.
	 * @param array<string, string> $preview_overrides Custom overrides for preview array.
	 * @return array{link: array<string, mixed>, preview: array<string, string>}
	 */
	private function sample_preview_data( array $link_overrides = array(), array $preview_overrides = array() ): array {
		return array(
			'link'    => array_merge(
				array(
					'id'             => 'link_preview_1',
					'shortCode'      => 'test-code',
					'alias'          => 'test-link',
					'shortUrl'       => 'https://peakurl.dev/test-link',
					'destinationUrl' => 'https://example.com/target',
					'hasPassword'    => false,
					'status'         => 'active',
				),
				$link_overrides
			),
			'preview' => array_merge(
				array(
					'title'       => 'Original Title',
					'description' => 'Original Description',
					'imageUrl'    => 'https://peakurl.dev/uploads/original.png',
					'url'         => 'https://peakurl.dev/test-link',
					'destination' => 'https://example.com/target',
					'siteName'    => 'PeakURL',
				),
				$preview_overrides
			),
		);
	}

	public function test_title_and_description_filtering(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview, array $link ): array {
				$preview['title']       = 'Filtered Custom Title';
				$preview['description'] = 'Filtered Custom Description';

				return $preview;
			},
			10,
			2
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'text/html; charset=utf-8', $response['headers']['Content-Type'] );

		$body = (string) $response['body'];
		$this->assertStringContainsString( '<title>Filtered Custom Title</title>', $body );
		$this->assertStringContainsString( '<meta property="og:title" content="Filtered Custom Title">', $body );
		$this->assertStringContainsString( '<meta name="twitter:title" content="Filtered Custom Title">', $body );
		$this->assertStringContainsString( '<h1>Filtered Custom Title</h1>', $body );

		$this->assertStringContainsString( '<meta property="og:description" content="Filtered Custom Description">', $body );
		$this->assertStringContainsString( '<meta name="twitter:description" content="Filtered Custom Description">', $body );
		$this->assertStringContainsString( '<p>Filtered Custom Description</p>', $body );

		$this->assertStringNotContainsString( 'Original Title', $body );
		$this->assertStringNotContainsString( 'Original Description', $body );
	}

	public function test_image_url_and_site_name_filtering(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview ): array {
				$preview['imageUrl'] = 'https://cdn.example.com/custom-card.jpg';
				$preview['siteName'] = 'Custom Platform Name';

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$this->assertStringContainsString( '<meta property="og:image" content="https://cdn.example.com/custom-card.jpg">', $body );
		$this->assertStringContainsString( '<meta property="og:image:secure_url" content="https://cdn.example.com/custom-card.jpg">', $body );
		$this->assertStringContainsString( '<meta name="twitter:image" content="https://cdn.example.com/custom-card.jpg">', $body );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image">', $body );
		$this->assertStringContainsString( '<meta property="og:site_name" content="Custom Platform Name">', $body );
	}

	public function test_clearing_image_url_switches_card_to_summary_and_omits_image_tags(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview ): array {
				$preview['imageUrl'] = '';

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$this->assertStringContainsString( '<meta name="twitter:card" content="summary">', $body );
		$this->assertStringNotContainsString( 'og:image', $body );
		$this->assertStringNotContainsString( 'twitter:image', $body );
	}

	#[DataProvider( 'provide_invalid_callback_returns' )]
	public function test_invalid_callback_return_types_fall_back_to_original( mixed $invalid_return ): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function () use ( $invalid_return ) {
				return $invalid_return;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$this->assertSame( 200, $response['status'] );

		$body = (string) $response['body'];
		$this->assertStringContainsString( '<title>Original Title</title>', $body );
		$this->assertStringContainsString( '<meta property="og:title" content="Original Title">', $body );
		$this->assertStringContainsString( '<meta property="og:description" content="Original Description">', $body );
		$this->assertStringContainsString( '<meta property="og:site_name" content="PeakURL">', $body );
	}

	/**
	 * Data provider for invalid callback return values.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_invalid_callback_returns(): array {
		return array(
			'null return'             => array( null ),
			'false return'            => array( false ),
			'integer return'          => array( 12345 ),
			'string return'           => array( 'invalid return string' ),
			'object return'           => array( new stdClass() ),
			'nested array for title'  => array( array( 'title' => array( 'bad' ) ) ),
			'boolean for description' => array( array( 'description' => true ) ),
			'array for siteName'      => array( array( 'siteName' => array( 'PeakURL' ) ) ),
			'array for imageUrl'      => array( array( 'imageUrl' => array( 'https://example.com' ) ) ),
		);
	}

	public function test_html_and_attribute_escaping(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview ): array {
				$preview['title']       = 'Special "Quotes" & \'Apostrophes\' <Tags>';
				$preview['description'] = 'Safe & "Sound" <Description>';
				$preview['siteName']    = 'Site & "Co"';

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$this->assertStringContainsString( 'Special &quot;Quotes&quot; &amp; &#039;Apostrophes&#039; &lt;Tags&gt;', $body );
		$this->assertStringContainsString( 'Safe &amp; &quot;Sound&quot; &lt;Description&gt;', $body );
		$this->assertStringContainsString( 'Site &amp; &quot;Co&quot;', $body );

		$this->assertStringNotContainsString( '<Tags>', $body );
		$this->assertStringNotContainsString( '<Description>', $body );
	}

	public function test_malicious_markup_escaping_prevents_xss(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview ): array {
				$preview['title']       = 'Malicious <script>alert("XSS")</script>';
				$preview['description'] = '"><img src=x onerror=alert(1)>';
				$preview['siteName']    = '" onfocus="alert(2)';

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringNotContainsString( '</script>', $body );
		$this->assertStringNotContainsString( '<img', $body );

		$this->assertStringContainsString( '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $body );
		$this->assertStringContainsString( '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $body );
		$this->assertStringContainsString( '&quot; onfocus=&quot;alert(2)', $body );
	}

	#[DataProvider( 'provide_unsafe_image_urls' )]
	public function test_invalid_image_url_protocol_falls_back_to_original( string $unsafe_url ): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview ) use ( $unsafe_url ): array {
				$preview['imageUrl'] = $unsafe_url;

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$this->assertStringNotContainsString( $unsafe_url, $body );
		$this->assertStringContainsString( 'https://peakurl.dev/uploads/original.png', $body );
	}

	/**
	 * Data provider for unsafe or unsupported image URLs.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_unsafe_image_urls(): array {
		return array(
			'javascript scheme'          => array( 'javascript:alert(1)' ),
			'data scheme'                => array( 'data:image/svg+xml;base64,PHN2Zz4=' ),
			'ftp scheme'                 => array( 'ftp://example.com/image.png' ),
			'file scheme'                => array( 'file:///etc/passwd' ),
			'malformed url'              => array( '://malformed-url' ),
			'relative path'              => array( '/relative/path/image.png' ),
			'missing host http'          => array( 'http://' ),
			'missing host with path'     => array( 'https:///path/image.png' ),
			'missing host with port'     => array( 'http://:80/image.png' ),
			'embedded user and password' => array( 'https://user:pass@example.com/image.png' ),
			'embedded user only'         => array( 'http://admin@example.com/pic.jpg' ),
		);
	}

	public function test_valid_cdn_image_urls_with_query_parameters_are_preserved(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		$cdn_url = 'https://cdn.example.com/assets/cards/header.webp?w=1200&h=630&fit=crop&v=3';

		add_filter(
			'link_social_metadata',
			function ( array $preview ) use ( $cdn_url ): array {
				$preview['imageUrl'] = $cdn_url;

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$escaped_cdn_url = esc_url( $cdn_url );
		$this->assertStringContainsString( '<meta property="og:image" content="' . $escaped_cdn_url . '">', $body );
		$this->assertStringContainsString( '<meta name="twitter:image" content="' . $escaped_cdn_url . '">', $body );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image">', $body );
	}

	public function test_canonical_url_and_destination_cannot_be_tampered(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview ): array {
				$preview['url']         = 'https://phishing.site/fake-link';
				$preview['destination'] = 'https://phishing.site/fake-dest';

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$this->assertStringNotContainsString( 'https://phishing.site/fake-link', $body );
		$this->assertStringNotContainsString( 'https://phishing.site/fake-dest', $body );

		$this->assertStringContainsString( '<link rel="canonical" href="https://peakurl.dev/test-link">', $body );
		$this->assertStringContainsString( '<meta property="og:url" content="https://peakurl.dev/test-link">', $body );
		$this->assertStringContainsString( '<meta name="twitter:url" content="https://peakurl.dev/test-link">', $body );
		$this->assertStringContainsString( '<a href="https://peakurl.dev/test-link">https://peakurl.dev/test-link</a>', $body );
	}

	public function test_missing_link_does_not_fire_filter_and_returns_404(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( null );
		$this->links_service->method( 'get_link_access' )->willReturn(
			array( 'status' => 'not_found' )
		);

		$invoked = false;
		add_filter(
			'link_social_metadata',
			function ( array $preview ) use ( &$invoked ): array {
				$invoked = true;

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request( '/missing-link' ) );

		$this->assertFalse( $invoked );
		$this->assertSame( 404, $response['status'] );
	}

	public function test_inactive_link_does_not_fire_filter_and_returns_status_page(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( null );
		$this->links_service->method( 'get_link_access' )->willReturn(
			array( 'status' => 'inactive' )
		);

		$invoked = false;
		add_filter(
			'link_social_metadata',
			function ( array $preview ) use ( &$invoked ): array {
				$invoked = true;

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request( '/inactive-link' ) );

		$this->assertFalse( $invoked );
		$this->assertSame( 404, $response['status'] );
	}

	public function test_expired_link_does_not_fire_filter_and_returns_410(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( null );
		$this->links_service->method( 'get_link_access' )->willReturn(
			array( 'status' => 'expired' )
		);

		$invoked = false;
		add_filter(
			'link_social_metadata',
			function ( array $preview ) use ( &$invoked ): array {
				$invoked = true;

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request( '/expired-link' ) );

		$this->assertFalse( $invoked );
		$this->assertSame( 410, $response['status'] );
	}

	public function test_password_protected_link_passes_bounded_link_without_credentials(): void {
		$raw_link_with_secrets = array(
			'id'             => 'pw_link_1',
			'shortCode'      => 'secret',
			'alias'          => 'secret-alias',
			'shortUrl'       => 'https://peakurl.dev/secret-alias',
			'destinationUrl' => 'https://example.com/confidential',
			'hasPassword'    => true,
			'status'         => 'active',
			'password_value' => 'secret_db_hash_not_to_leak',
			'password_hash'  => 'another_hash_not_to_leak',
			'password'       => 'plain_text_pass',
			'salt'           => 'secret_salt',
			'token'          => 'secret_token',
		);

		$this->links_service->method( 'get_link_social_preview' )->willReturn(
			$this->sample_preview_data( $raw_link_with_secrets )
		);

		$captured_link = null;
		add_filter(
			'link_social_metadata',
			function ( array $preview, array $link ) use ( &$captured_link ): array {
				$captured_link = $link;

				return $preview;
			},
			10,
			2
		);

		$response = $this->controller->redirect( $this->create_crawler_request( '/secret-alias' ) );

		$this->assertSame( 200, $response['status'] );
		$this->assertNotNull( $captured_link );
		$this->assertTrue( $captured_link['hasPassword'] );
		$this->assertSame( 'pw_link_1', $captured_link['id'] );
		$this->assertSame( 'secret-alias', $captured_link['alias'] );

		$this->assertArrayNotHasKey( 'password_value', $captured_link );
		$this->assertArrayNotHasKey( 'password_hash', $captured_link );
		$this->assertArrayNotHasKey( 'password', $captured_link );
		$this->assertArrayNotHasKey( 'salt', $captured_link );
		$this->assertArrayNotHasKey( 'token', $captured_link );
		$this->assertArrayNotHasKey( 'destinationUrl', $captured_link );
		$this->assertSame(
			array( 'id', 'shortCode', 'alias', 'shortUrl', 'title', 'hasPassword', 'status' ),
			array_keys( $captured_link )
		);
	}

	public function test_protected_link_destination_and_sensitive_fields_absent_from_both_callback_arguments(): void {
		$raw_link_with_all_internals = array(
			'id'             => 'pw_link_sensitive',
			'userId'         => 'user_999',
			'shortCode'      => 'secret_code',
			'alias'          => 'secret_alias',
			'shortUrl'       => 'https://peakurl.dev/secret_alias',
			'destinationUrl' => 'https://confidential-internal.corp/admin/payroll',
			'hasPassword'    => true,
			'status'         => 'active',
			'clicks'         => 42,
			'uniqueClicks'   => 17,
			'utmSource'      => 'internal_campaign',
			'utmMedium'      => 'confidential_medium',
			'utmCampaign'    => 'secret_promo',
			'utmTerm'        => 'sensitive_term',
			'utmContent'     => 'classified',
			'createdAt'      => '2026-01-01T00:00:00Z',
			'updatedAt'      => '2026-01-02T00:00:00Z',
			'password_value' => 'hash_secret_value',
			'password_hash'  => 'hash_secret_field',
			'password'       => 'plaintext_secret_password',
			'salt'           => 'secret_salt_123',
			'token'          => 'secret_token_456',
		);

		$raw_preview = array(
			'title'       => 'Protected Link Preview',
			'description' => 'Safe description without destination',
			'imageUrl'    => 'https://peakurl.dev/uploads/preview.png',
			'url'         => 'https://peakurl.dev/secret_alias',
			'destination' => 'https://confidential-internal.corp/admin/payroll',
			'siteName'    => 'PeakURL',
		);

		$this->links_service->method( 'get_link_social_preview' )->willReturn(
			array(
				'link'    => $raw_link_with_all_internals,
				'preview' => $raw_preview,
			)
		);

		$captured_preview = null;
		$captured_link    = null;

		add_filter(
			'link_social_metadata',
			function ( array $preview, array $link ) use ( &$captured_preview, &$captured_link ): array {
				$captured_preview = $preview;
				$captured_link    = $link;

				return $preview;
			},
			10,
			2
		);

		$response = $this->controller->redirect( $this->create_crawler_request( '/secret_alias' ) );
		$this->assertSame( 200, $response['status'] );

		$this->assertNotNull( $captured_preview );
		$this->assertArrayNotHasKey( 'destination', $captured_preview );
		$this->assertArrayNotHasKey( 'destinationUrl', $captured_preview );
		$this->assertSame(
			array( 'title', 'description', 'imageUrl', 'siteName', 'url' ),
			array_keys( $captured_preview )
		);

		$this->assertNotNull( $captured_link );
		$this->assertArrayNotHasKey( 'destination', $captured_link );
		$this->assertArrayNotHasKey( 'destinationUrl', $captured_link );
		$this->assertArrayNotHasKey( 'userId', $captured_link );
		$this->assertArrayNotHasKey( 'clicks', $captured_link );
		$this->assertArrayNotHasKey( 'uniqueClicks', $captured_link );
		$this->assertArrayNotHasKey( 'utmSource', $captured_link );
		$this->assertArrayNotHasKey( 'utmMedium', $captured_link );
		$this->assertArrayNotHasKey( 'utmCampaign', $captured_link );
		$this->assertArrayNotHasKey( 'utmTerm', $captured_link );
		$this->assertArrayNotHasKey( 'utmContent', $captured_link );
		$this->assertArrayNotHasKey( 'createdAt', $captured_link );
		$this->assertArrayNotHasKey( 'updatedAt', $captured_link );
		$this->assertArrayNotHasKey( 'password_value', $captured_link );
		$this->assertArrayNotHasKey( 'password_hash', $captured_link );
		$this->assertArrayNotHasKey( 'password', $captured_link );
		$this->assertArrayNotHasKey( 'salt', $captured_link );
		$this->assertArrayNotHasKey( 'token', $captured_link );
		$this->assertSame(
			array( 'id', 'shortCode', 'alias', 'shortUrl', 'title', 'hasPassword', 'status' ),
			array_keys( $captured_link )
		);

		$body = (string) $response['body'];
		$this->assertStringNotContainsString( 'https://confidential-internal.corp', $body );
	}

	public function test_no_registered_listeners_returns_unchanged_preview(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		$this->assertFalse( has_filter( 'link_social_metadata' ) );

		$response = $this->controller->redirect( $this->create_crawler_request() );

		$this->assertSame( 200, $response['status'] );
		$body = (string) $response['body'];

		$this->assertStringContainsString( '<title>Original Title</title>', $body );
		$this->assertStringContainsString( '<meta property="og:title" content="Original Title">', $body );
		$this->assertStringContainsString( '<meta property="og:description" content="Original Description">', $body );
		$this->assertStringContainsString( '<meta property="og:site_name" content="PeakURL">', $body );
		$this->assertStringContainsString( '<meta property="og:image" content="https://peakurl.dev/uploads/original.png">', $body );
	}

	public function test_callback_priority_and_argument_ordering(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		$captured_args = null;

		add_filter(
			'link_social_metadata',
			function ( array $preview, array $link ): array {
				$preview['title'] = $preview['title'] . ' - Priority 10';

				return $preview;
			},
			10,
			2
		);

		add_filter(
			'link_social_metadata',
			function ( array $preview, array $link ) use ( &$captured_args ): array {
				$captured_args    = array(
					'preview' => $preview,
					'link'    => $link,
				);
				$preview['title'] = $preview['title'] . ' - Priority 20';

				return $preview;
			},
			20,
			2
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );

		$this->assertSame( 200, $response['status'] );
		$this->assertNotNull( $captured_args );
		$this->assertSame( 'Original Title - Priority 10', $captured_args['preview']['title'] );
		$this->assertSame( 'link_preview_1', $captured_args['link']['id'] );

		$body = (string) $response['body'];
		$this->assertStringContainsString( '<title>Original Title - Priority 10 - Priority 20</title>', $body );
	}

	public function test_filter_exception_does_not_disrupt_social_preview_response(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function () {
				throw new RuntimeException( 'Confidential token=secret123 parameter should not leak to logs' );
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );

		$this->assertSame( 200, $response['status'] );
		$body = (string) $response['body'];
		$this->assertStringContainsString( '<title>Original Title</title>', $body );
		$this->assertStringContainsString( '<meta property="og:description" content="Original Description">', $body );
	}

	public function test_non_crawler_request_does_not_fire_social_preview_or_filter(): void {
		$this->links_service->method( 'get_link_access' )->willReturn(
			array(
				'status'   => 'redirect',
				'location' => 'https://example.com/target',
				'url'      => array(
					'id'              => 'link_1',
					'destination_url' => 'https://example.com/target',
				),
			)
		);

		$filter_invoked = false;
		add_filter(
			'link_social_metadata',
			function ( array $preview ) use ( &$filter_invoked ): array {
				$filter_invoked = true;

				return $preview;
			}
		);

		$browser_request = new Request(
			'GET',
			'/test-link',
			array(),
			array(),
			array( 'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)' ),
			array( 'id' => 'test-link' ),
			array(
				'REMOTE_ADDR'     => '192.0.2.1',
				'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
			)
		);

		$response = $this->controller->redirect( $browser_request );

		$this->assertFalse( $filter_invoked );
		$this->assertSame( 302, $response['status'] );
		$this->assertSame( 'https://example.com/target', $response['headers']['Location'] );
	}

	public function test_empty_or_whitespace_title_falls_back_to_original_title(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview ): array {
				$preview['title'] = '   ';

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$this->assertStringContainsString( '<title>Original Title</title>', $body );
	}

	public function test_empty_or_whitespace_site_name_falls_back_to_original_site_name(): void {
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $this->sample_preview_data() );

		add_filter(
			'link_social_metadata',
			function ( array $preview ): array {
				$preview['siteName'] = '   ';

				return $preview;
			}
		);

		$response = $this->controller->redirect( $this->create_crawler_request() );
		$body     = (string) $response['body'];

		$this->assertStringContainsString( '<meta property="og:site_name" content="PeakURL">', $body );
	}

	public function test_existing_preview_html_remains_strictly_identical_without_filters(): void {
		$sample_data = $this->sample_preview_data();
		$this->links_service->method( 'get_link_social_preview' )->willReturn( $sample_data );

		$response_without_hooks = $this->controller->redirect( $this->create_crawler_request() );
		$this->assertSame( 200, $response_without_hooks['status'] );

		$generator_meta = get_generator_tag();
		$generator_line = '' !== $generator_meta ? "\n" . $generator_meta : '';

		$expected_html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Original Title</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, max-image-preview:large">{$generator_line}
<link rel="canonical" href="https://peakurl.dev/test-link">
<meta property="og:type" content="website">
<meta property="og:site_name" content="PeakURL">
<meta property="og:title" content="Original Title">
<meta property="og:description" content="Original Description">
<meta property="og:url" content="https://peakurl.dev/test-link">
<meta property="og:image" content="https://peakurl.dev/uploads/original.png">
<meta property="og:image:secure_url" content="https://peakurl.dev/uploads/original.png">
<meta name="twitter:image" content="https://peakurl.dev/uploads/original.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Original Title">
<meta name="twitter:description" content="Original Description">
<meta name="twitter:url" content="https://peakurl.dev/test-link">
</head>
<body>
<main>
<h1>Original Title</h1>
<p>Original Description</p>
<p><a href="https://peakurl.dev/test-link">https://peakurl.dev/test-link</a></p>
</main>
</body>
</html>
HTML;

		$this->assertSame( $expected_html, (string) $response_without_hooks['body'] );
	}
}
