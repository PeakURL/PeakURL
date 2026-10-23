<?php
/**
 * Unit tests for link field validation filters (Stage P2-A).
 *
 * Verifies that:
 * - link_alias filters custom and generated aliases during creation and update
 * - link_alias enforces sanitization, reserved codes, uniqueness, and 64-char length
 * - link_title filters titles during creation and update, enforcing length and UTF-8 encoding
 * - link_title decodes HTML entities exactly once before persistence
 * - link_title preserves default title when title is omitted
 * - link_title does not alter social preview titles
 * - link_destination_url filters destinations before validation
 * - link_destination_url enforces HTTP and HTTPS protocol restrictions
 * - link_destination_url preserves destination change detection and health check scheduling
 * - non-string callback returns fall back safely
 * - sensitive credentials and unnecessary metadata are excluded from hook arguments
 * - authorization runs before filters
 * - internal link creation without user context bypasses filters
 *
 * @package PeakURL\Tests\Unit\Links
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Creator as LinksCreator;
use PeakURL\Features\Links\Health\Checker as HealthChecker;
use PeakURL\Features\Links\Health\Context as HealthContext;
use PeakURL\Features\Links\Health\Probe as HealthProbe;
use PeakURL\Features\Links\Health\Resolver as HealthResolver;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Captcha;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Http\Request;

class LinkFieldFiltersTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private MockObject&AuthService $auth_service;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&WebhooksService $webhooks_service;
	private HealthChecker $health_checker;
	private MockObject&SocialPreview $social_preview;
	private MockObject&Captcha $captcha;
	private MockObject&SettingsApi $settings_api;
	private Roles $roles;
	private Authorization $authorization;
	private LinksService $links_service;
	private ?array $last_inserted_link    = null;
	private ?array $current_existing_link = null;
	private ?array $last_updated_link     = null;
	private ?array $last_updated_params   = null;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->auth_service      = $this->createMock( AuthService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->webhooks_service  = $this->createMock( WebhooksService::class );
		$this->social_preview    = $this->createMock( SocialPreview::class );
		$this->captcha           = $this->createMock( Captcha::class );
		$this->settings_api      = $this->createMock( SettingsApi::class );

		$links_api = $this->createMock( \PeakURL\Api\LinksApi::class );
		$this->repository->method( 'get_links_api' )->willReturn( $links_api );

		$this->roles          = new Roles();
		$this->authorization  = new Authorization( $this->roles );
		$this->health_checker = new HealthChecker( new HealthResolver(), new HealthProbe( new HealthContext( array() ) ) );

		$this->last_inserted_link    = null;
		$this->current_existing_link = null;
		$this->last_updated_link     = null;
		$this->last_updated_params   = null;

		$this->repository->method( 'insert_url' )
			->willReturnCallback(
				function ( array $data ): void {
					$this->last_inserted_link = $data;
				}
			);

		$this->repository->method( 'update_url_fields' )
			->willReturnCallback(
				function ( string $id, array $fields, array $params ): void {
					$this->last_updated_params = $params;
					$base                      = $this->current_existing_link ?? array( 'id' => $id );
					$this->last_updated_link   = array_merge( $base, $params );
				}
			);

		$this->repository->method( 'find_url_row' )
			->willReturnCallback(
				function ( string $id ): ?array {
					if ( null !== $this->last_updated_link && (string) ( $this->last_updated_link['id'] ?? '' ) === $id ) {
							return $this->last_updated_link;
					}
					if ( null !== $this->last_inserted_link ) {
						return $this->last_inserted_link;
					}
					return $this->current_existing_link;
				}
			);

		$this->repository->method( 'get_link_by_id' )
			->willReturnCallback(
				function ( string $id ): ?array {
					return $this->current_existing_link;
				}
			);

		$this->links_service = new LinksService(
			$this->repository,
			new LinksValidator(),
			$this->settings_api,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->captcha,
			$this->roles,
			$this->authorization,
			array( 'site_url' => 'https://peakurl.dev' ),
			$this->health_checker
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	private function mock_admin_user(): array {
		return array(
			'id'                => 'user_admin_1',
			'username'          => 'admin',
			'email'             => 'admin@peakurl.dev',
			'role'              => 'admin',
			'password_hash'     => '$2y$10$secret_hash_value',
			'two_factor_secret' => 'SECRET2FAKEY',
			'display_name'      => 'Admin User',
		);
	}

	// =========================================================================
	// 1. link_alias Filter Tests
	// =========================================================================

	public function test_link_alias_filter_modifies_custom_alias_on_creation(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$captured_alias = null;
		$captured_link  = null;
		$captured_user  = null;

		add_filter(
			'link_alias',
			function ( string $alias, array $link, array $user ) use ( &$captured_alias, &$captured_link, &$captured_user ): string {
				$captured_alias = $alias;
				$captured_link  = $link;
				$captured_user  = $user;
				return $alias . '-modified';
			},
			10,
			3
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl'    => 'https://example.com/target',
				'alias'             => 'my-custom-slug',
				'title'             => 'Custom Title',
				'password'          => 'should_not_leak',
				'unrelatedExtraKey' => 'unexpected_payload_key',
			)
		);

		$this->assertSame( 'my-custom-slug', $captured_alias );
		$this->assertSame( 'my-custom-slug-modified', $result['alias'] );
		$this->assertSame( 'my-custom-slug-modified', $result['shortCode'] );
		$this->assertSame( 'my-custom-slug-modified', $this->last_inserted_link['alias'] );
		$this->assertSame( 'my-custom-slug-modified', $this->last_inserted_link['short_code'] );

		// Verify bounded link context: documented fields only, no passwords or arbitrary JSON keys.
		$this->assertArrayHasKey( 'destinationUrl', $captured_link );
		$this->assertArrayHasKey( 'title', $captured_link );
		$this->assertArrayHasKey( 'alias', $captured_link );
		$this->assertArrayHasKey( 'status', $captured_link );
		$this->assertArrayHasKey( 'expiresAt', $captured_link );
		$this->assertArrayNotHasKey( 'password', $captured_link );
		$this->assertArrayNotHasKey( 'unrelatedExtraKey', $captured_link );

		// Verify minimal actor context: id, username, role only; no email, hashes, or display names.
		$this->assertSame( 'user_admin_1', $captured_user['id'] );
		$this->assertSame( 'admin', $captured_user['username'] );
		$this->assertSame( 'admin', $captured_user['role'] );
		$this->assertArrayNotHasKey( 'email', $captured_user );
		$this->assertArrayNotHasKey( 'password_hash', $captured_user );
		$this->assertArrayNotHasKey( 'two_factor_secret', $captured_user );
		$this->assertArrayNotHasKey( 'display_name', $captured_user );
		$this->assertArrayNotHasKey( 'displayName', $captured_user );
	}

	public function test_link_alias_filter_modifies_generated_alias_on_creation(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_alias',
			function ( string $alias ): string {
				return 'gen-' . $alias;
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/generated-test',
			)
		);

		$this->assertStringStartsWith( 'gen-', $result['alias'] );
		$this->assertSame( $result['alias'], $result['shortCode'] );
		$this->assertSame( $result['alias'], $this->last_inserted_link['alias'] );
	}

	public function test_link_alias_filter_runs_on_update(): void {
		$admin                       = $this->mock_admin_user();
		$this->current_existing_link = array(
			'id'              => 'link_up_alias',
			'user_id'         => 'user_admin_1',
			'alias'           => 'old-alias',
			'short_code'      => 'old-alias',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'title'           => 'Existing Title',
			'click_count'     => 42,
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$captured_alias = null;
		$captured_link  = null;
		$captured_user  = null;
		add_filter(
			'link_alias',
			function ( string $alias, array $link, array $user ) use ( &$captured_alias, &$captured_link, &$captured_user ): string {
				$captured_alias = $alias;
				$captured_link  = $link;
				$captured_user  = $user;
				return 'filtered-' . $alias;
			},
			10,
			3
		);

		$request = new Request( 'PUT', '/link_up_alias', array(), array() );
		$result  = $this->links_service->update_url(
			$request,
			'link_up_alias',
			array(
				'alias' => 'new-requested-alias',
			)
		);

		$this->assertSame( 'new-requested-alias', $captured_alias );
		$this->assertSame( 'filtered-new-requested-alias', $this->last_updated_params['alias'] );
		$this->assertSame( 'filtered-new-requested-alias', $this->last_updated_params['short_code'] );

		// Verify bounded update context: documented fields present, operational metrics omitted.
		$this->assertSame( 'link_up_alias', $captured_link['id'] );
		$this->assertSame( 'https://example.com/target', $captured_link['destinationUrl'] );
		$this->assertSame( 'Existing Title', $captured_link['title'] );
		$this->assertSame( 'old-alias', $captured_link['alias'] );
		$this->assertSame( 'active', $captured_link['status'] );
		$this->assertArrayNotHasKey( 'click_count', $captured_link );
		$this->assertArrayNotHasKey( 'clicks', $captured_link );
		$this->assertArrayNotHasKey( 'password', $captured_link );

		$this->assertSame( 'user_admin_1', $captured_user['id'] );
		$this->assertSame( 'admin', $captured_user['username'] );
		$this->assertSame( 'admin', $captured_user['role'] );
		$this->assertArrayNotHasKey( 'email', $captured_user );
	}

	public function test_link_alias_filter_does_not_fire_when_update_omits_alias(): void {
		$admin                       = $this->mock_admin_user();
		$this->current_existing_link = array(
			'id'              => 'link_no_alias',
			'user_id'         => 'user_admin_1',
			'alias'           => 'unchanged-alias',
			'short_code'      => 'unchanged-alias',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'title'           => 'Old Title',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );

		$filter_invoked = false;
		add_filter(
			'link_alias',
			function ( string $alias ) use ( &$filter_invoked ): string {
				$filter_invoked = true;
				return $alias;
			}
		);

		$request = new Request( 'PUT', '/link_no_alias', array(), array() );
		$this->links_service->update_url(
			$request,
			'link_no_alias',
			array(
				'title' => 'New Title',
			)
		);

		$this->assertFalse( $filter_invoked, 'link_alias must not run when update payload omits alias' );
	}

	public function test_link_alias_filter_rejects_reserved_code(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );

		add_filter(
			'link_alias',
			function (): string {
				return 'dashboard'; // Reserved application route
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/reserved',
				'alias'          => 'candidate-alias',
			)
		);
	}

	public function test_link_alias_filter_rejects_duplicate_existing_short_code(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->with( 'already-taken' )->willReturn( true );

		add_filter(
			'link_alias',
			function (): string {
				return 'already-taken';
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/dup',
				'alias'          => 'original-alias',
			)
		);
	}

	public function test_link_alias_filter_falls_back_when_callback_returns_non_string(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_alias',
			function () {
				return 12345; // Invalid non-string return type
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/fallback-test',
				'alias'          => 'valid-original-alias',
			)
		);

		$this->assertSame( 'valid-original-alias', $result['alias'] );
	}

	public function test_link_alias_filter_accepts_exactly_64_characters_on_creation(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$exact_64 = str_repeat( 'a', 64 );

		add_filter(
			'link_alias',
			function () use ( $exact_64 ): string {
				return $exact_64;
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/alias-64',
			)
		);

		$this->assertSame( $exact_64, $result['alias'] );
		$this->assertSame( 64, strlen( $result['alias'] ) );
		$this->assertSame( $exact_64, $this->last_inserted_link['alias'] );
	}

	public function test_link_alias_filter_rejects_more_than_64_characters_on_creation(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_alias',
			function (): string {
				return str_repeat( 'a', 65 );
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases cannot exceed 64 characters.' );

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/alias-65',
			)
		);
	}

	public function test_link_alias_filter_accepts_exactly_64_characters_on_update(): void {
		$admin                       = $this->mock_admin_user();
		$this->current_existing_link = array(
			'id'              => 'link_up_alias_64',
			'user_id'         => 'user_admin_1',
			'alias'           => 'old-alias',
			'short_code'      => 'old-alias',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'title'           => 'Title',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$exact_64 = str_repeat( 'b', 64 );

		add_filter(
			'link_alias',
			function () use ( $exact_64 ): string {
				return $exact_64;
			}
		);

		$request = new Request( 'PUT', '/link_up_alias_64', array(), array() );
		$this->links_service->update_url(
			$request,
			'link_up_alias_64',
			array(
				'alias' => 'new-alias',
			)
		);

		$this->assertSame( $exact_64, $this->last_updated_params['alias'] );
		$this->assertSame( 64, strlen( $this->last_updated_params['alias'] ) );
	}

	public function test_link_alias_filter_rejects_more_than_64_characters_on_update(): void {
		$admin                       = $this->mock_admin_user();
		$this->current_existing_link = array(
			'id'              => 'link_up_alias_65',
			'user_id'         => 'user_admin_1',
			'alias'           => 'old-alias',
			'short_code'      => 'old-alias',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'title'           => 'Title',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_alias',
			function (): string {
				return str_repeat( 'b', 65 );
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Aliases cannot exceed 64 characters.' );

		$request = new Request( 'PUT', '/link_up_alias_65', array(), array() );
		$this->links_service->update_url(
			$request,
			'link_up_alias_65',
			array(
				'alias' => 'new-alias',
			)
		);
	}

	// =========================================================================
	// 2. link_title Filter Tests
	// =========================================================================

	public function test_link_title_filter_modifies_title_on_creation(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_title',
			function ( string $title, array $link, array $user ): string {
				return '[PROMO] ' . $title;
			},
			10,
			3
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/promo',
				'title'          => 'Spring Sale',
			)
		);

		$this->assertSame( '[PROMO] Spring Sale', $result['title'] );
		$this->assertSame( '[PROMO] Spring Sale', $this->last_inserted_link['title'] );
	}

	public function test_link_title_filter_preserves_default_alias_title_when_title_omitted(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$received_title = null;
		add_filter(
			'link_title',
			function ( string $title ) use ( &$received_title ): string {
				$received_title = $title;
				return $title . ' Official';
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/product',
				'alias'          => 'my-gadget',
			)
		);

		$this->assertSame( 'My-gadget', $received_title );
		$this->assertSame( 'My-gadget Official', $result['title'] );
	}

	public function test_link_title_filter_runs_on_update(): void {
		$admin                       = $this->mock_admin_user();
		$this->current_existing_link = array(
			'id'              => 'link_up_title',
			'user_id'         => 'user_admin_1',
			'alias'           => 'slug1',
			'short_code'      => 'slug1',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'title'           => 'Initial Title',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );

		add_filter(
			'link_title',
			function ( string $title ): string {
				return $title . ' (Verified)';
			}
		);

		$request = new Request( 'PUT', '/link_up_title', array(), array() );
		$result  = $this->links_service->update_url(
			$request,
			'link_up_title',
			array(
				'title' => 'Updated Article',
			)
		);

		$this->assertSame( 'Updated Article (Verified)', $this->last_updated_params['title'] );
	}

	public function test_link_title_filter_enforces_191_char_limit_and_encoding(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_title',
			function (): string {
				return str_repeat( 'a', 250 ) . ' &amp; ' . str_repeat( 'b', 50 );
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/long-title',
				'title'          => 'Short',
			)
		);

		$this->assertLessThanOrEqual( 191, mb_strlen( $result['title'], 'UTF-8' ) );
		$this->assertSame( 191, mb_strlen( $this->last_inserted_link['title'], 'UTF-8' ) );
	}

	public function test_link_title_filter_does_not_alter_social_preview_title(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$this->social_preview->method( 'normalize_title' )
			->with( 'Social Card Headline' )
			->willReturn( 'Social Card Headline' );
		$this->social_preview->method( 'normalize_description' )->willReturn( null );

		add_filter(
			'link_title',
			function ( string $title ): string {
				return 'Filtered Page Title';
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/social-test',
				'title'          => 'Original Page Title',
				'socialTitle'    => 'Social Card Headline',
			)
		);

		$this->assertSame( 'Filtered Page Title', $this->last_inserted_link['title'] );
		$this->assertSame( 'Social Card Headline', $this->last_inserted_link['social_title'] );
	}

	public function test_link_title_filter_falls_back_when_callback_returns_non_string(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_title',
			function () {
				return null; // Invalid non-string return
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/title-fallback',
				'title'          => 'Fallback Title',
			)
		);

		$this->assertSame( 'Fallback Title', $result['title'] );
	}

	public function test_link_title_decodes_double_encoded_entities_exactly_once_on_creation(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/double-encoded-create',
				'title'          => '&amp;lt;script&amp;gt;',
			)
		);

		$this->assertSame( '&lt;script&gt;', $result['title'] );
		$this->assertSame( '&lt;script&gt;', $this->last_inserted_link['title'] );
	}

	public function test_link_title_decodes_double_encoded_entities_exactly_once_on_update(): void {
		$admin                       = $this->mock_admin_user();
		$this->current_existing_link = array(
			'id'              => 'link_up_double_decode',
			'user_id'         => 'user_admin_1',
			'alias'           => 'slug-dd',
			'short_code'      => 'slug-dd',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'title'           => 'Old Title',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );

		$request = new Request( 'PUT', '/link_up_double_decode', array(), array() );
		$this->links_service->update_url(
			$request,
			'link_up_double_decode',
			array(
				'title' => '&amp;lt;script&amp;gt;',
			)
		);

		$this->assertSame( '&lt;script&gt;', $this->last_updated_params['title'] );
	}

	// =========================================================================
	// 3. link_destination_url Filter Tests
	// =========================================================================

	public function test_link_destination_url_filter_modifies_destination_on_creation(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_destination_url',
			function ( string $url, array $link, array $user ): string {
				return $url . '?utm_source=peakurl_hook';
			},
			10,
			3
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/landing',
			)
		);

		$this->assertSame( 'https://example.com/landing?utm_source=peakurl_hook', $result['destinationUrl'] );
		$this->assertSame( 'https://example.com/landing?utm_source=peakurl_hook', $this->last_inserted_link['destination_url'] );
	}

	public function test_link_destination_url_filter_runs_on_update_and_detects_change(): void {
		$admin                       = $this->mock_admin_user();
		$this->current_existing_link = array(
			'id'              => 'link_up_dest',
			'user_id'         => 'user_admin_1',
			'alias'           => 'dest-slug',
			'short_code'      => 'dest-slug',
			'destination_url' => 'https://example.com/old-url',
			'status'          => 'active',
			'title'           => 'Destination Test',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );

		// Expect old health record deleted when destination changes
		$this->repository->expects( $this->once() )
			->method( 'delete_link_health' )
			->with( 'link_up_dest' );

		add_filter(
			'link_destination_url',
			function ( string $url ): string {
				return $url . '/canonical';
			}
		);

		$request = new Request( 'PUT', '/link_up_dest', array(), array() );
		$result  = $this->links_service->update_url(
			$request,
			'link_up_dest',
			array(
				'destinationUrl' => 'https://example.com/new-url',
			)
		);

		$this->assertSame( 'https://example.com/new-url/canonical', $this->last_updated_params['destination_url'] );
		$this->assertNull( $result['health'], 'Health snapshot must be cleared on destination change' );
	}

	public function test_link_destination_url_filter_rejects_invalid_url_returned_by_callback(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );

		add_filter(
			'link_destination_url',
			function (): string {
				return 'javascript:alert(1)'; // Invalid protocol/URL
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/good-url',
			)
		);
	}

	public function test_link_destination_url_filter_rejects_ftp_scheme_on_creation(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );

		add_filter(
			'link_destination_url',
			function (): string {
				return 'ftp://ftp.example.com/file.zip'; // Disallowed protocol
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'A valid destination URL is required.' );

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/good-url',
			)
		);
	}

	public function test_link_destination_url_filter_rejects_unsupported_scheme_on_update(): void {
		$admin                       = $this->mock_admin_user();
		$this->current_existing_link = array(
			'id'              => 'link_up_scheme',
			'user_id'         => 'user_admin_1',
			'alias'           => 'slug-scheme',
			'short_code'      => 'slug-scheme',
			'destination_url' => 'https://example.com/old',
			'status'          => 'active',
			'title'           => 'Title',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );

		add_filter(
			'link_destination_url',
			function (): string {
				return 'file:///etc/passwd'; // Disallowed scheme
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'A valid destination URL is required.' );

		$request = new Request( 'PUT', '/link_up_scheme', array(), array() );
		$this->links_service->update_url(
			$request,
			'link_up_scheme',
			array(
				'destinationUrl' => 'https://example.com/new',
			)
		);
	}

	public function test_link_destination_url_filter_falls_back_when_callback_returns_non_string(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_destination_url',
			function () {
				return array( 'invalid' => 'array' );
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/original-dest',
			)
		);

		$this->assertSame( 'https://example.com/original-dest', $result['destinationUrl'] );
	}

	// =========================================================================
	// 4. Composition, Priority & Ordering Tests
	// =========================================================================

	public function test_filters_execute_in_strict_priority_order(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		// Priority 20: append ' - Step 3'
		add_filter( 'link_title', fn( string $t ) => $t . ' - Step 3', 20 );
		// Priority 5: prepend 'Step 1 - '
		add_filter( 'link_title', fn( string $t ) => 'Step 1 - ' . $t, 5 );
		// Priority 10: append ' - Step 2'
		add_filter( 'link_title', fn( string $t ) => $t . ' - Step 2', 10 );

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/steps',
				'title'          => 'Core',
			)
		);

		$this->assertSame( 'Step 1 - Core - Step 2 - Step 3', $result['title'] );
	}

	public function test_pre_create_link_and_field_filters_compose_cleanly(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		// pre_create_link runs first on whole payload
		add_filter(
			'pre_create_link',
			function ( array $payload ): array {
				$payload['title'] = 'FromPreCreate';
				return $payload;
			}
		);

		// link_title runs after, receiving the modified title
		add_filter(
			'link_title',
			function ( string $title ): string {
				return $title . 'AndFiltered';
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/compose',
				'title'          => 'Initial',
			)
		);

		$this->assertSame( 'FromPreCreateAndFiltered', $result['title'] );
	}

	public function test_link_alias_filter_modifies_custom_alias_and_fallback_title_uses_modified_alias_when_title_omitted(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_alias',
			function ( string $alias ): string {
				return 'custom-filtered';
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/alias-fallback-title',
				'alias'          => 'original-alias',
			)
		);

		$this->assertSame( 'custom-filtered', $result['alias'] );
		$this->assertSame( 'Custom-filtered', $result['title'] );
		$this->assertSame( 'Custom-filtered', $this->last_inserted_link['title'] );
	}

	public function test_link_alias_filter_modifies_generated_alias_and_preserves_custom_title_when_supplied(): void {
		$admin = $this->mock_admin_user();
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		add_filter(
			'link_alias',
			function (): string {
				return 'gen-modified-slug';
			}
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), array() );
		$result  = $this->links_service->create_url(
			$request,
			array(
				'destinationUrl' => 'https://example.com/alias-gen-explicit-title',
				'title'          => 'My Explicit Title',
			)
		);

		$this->assertSame( 'gen-modified-slug', $result['alias'] );
		$this->assertSame( 'My Explicit Title', $result['title'] );
		$this->assertSame( 'My Explicit Title', $this->last_inserted_link['title'] );
	}

	public function test_authorization_failure_blocks_link_creation_before_filters_run(): void {
		$unauthorized_user = array(
			'id'   => 'user_viewer',
			'role' => 'viewer', // No create_links capability
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $unauthorized_user );

		$filter_invoked = false;
		add_filter(
			'link_alias',
			function ( string $a ) use ( &$filter_invoked ): string {
				$filter_invoked = true;
				return $a;
			}
		);
		add_filter(
			'link_destination_url',
			function ( string $u ) use ( &$filter_invoked ): string {
				$filter_invoked = true;
				return $u;
			}
		);

		$caught_exception = null;
		try {
			$request = new Request( 'POST', '/api/v1/urls', array(), array() );
			$this->links_service->create_url(
				$request,
				array(
					'destinationUrl' => 'https://example.com/forbidden',
				)
			);
		} catch ( ApiException $exception ) {
			$caught_exception = $exception;
		}

		$this->assertInstanceOf( ApiException::class, $caught_exception );
		$this->assertSame( 403, $caught_exception->getCode() );
		$this->assertFalse( $filter_invoked, 'Field filters must not execute when authorization fails' );
	}

	public function test_editor_cannot_update_another_user_link(): void {
		$editor_user                 = array(
			'id'   => 'user_editor_1',
			'role' => 'editor',
		);
		$this->current_existing_link = array(
			'id'              => 'link_owned_by_other',
			'user_id'         => 'user_different',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'title'           => 'Other User Link',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $editor_user );

		$filter_invoked = false;
		add_filter(
			'link_title',
			function ( string $t ) use ( &$filter_invoked ): string {
				$filter_invoked = true;
				return $t;
			}
		);

		$caught_exception = null;
		try {
			$request = new Request( 'PUT', '/link_owned_by_other', array(), array() );
			$this->links_service->update_url(
				$request,
				'link_owned_by_other',
				array(
					'title' => 'Hijacked Title',
				)
			);
		} catch ( ApiException $exception ) {
			$caught_exception = $exception;
		}

		$this->assertInstanceOf( ApiException::class, $caught_exception );
		$this->assertSame( 403, $caught_exception->getCode() );
		$this->assertFalse( $filter_invoked, 'Field filters must not execute when user lacks permission to edit this link' );
	}

	// =========================================================================
	// 5. Internal Creation / Authorization Boundary Tests
	// =========================================================================

	public function test_internal_link_creation_without_authenticated_user_context_bypasses_filters(): void {
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$alias_filter_invoked       = false;
		$title_filter_invoked       = false;
		$destination_filter_invoked = false;

		add_filter(
			'link_alias',
			function ( string $alias ) use ( &$alias_filter_invoked ): string {
				$alias_filter_invoked = true;
				return 'filtered-' . $alias;
			}
		);

		add_filter(
			'link_title',
			function ( string $title ) use ( &$title_filter_invoked ): string {
				$title_filter_invoked = true;
				return 'Filtered ' . $title;
			}
		);

		add_filter(
			'link_destination_url',
			function ( string $url ) use ( &$destination_filter_invoked ): string {
				$destination_filter_invoked = true;
				return $url . '/filtered';
			}
		);

		$creator = new LinksCreator(
			$this->repository,
			new LinksValidator(),
			$this->social_preview
		);

		$result = $creator->create(
			array(
				'destinationUrl' => 'https://example.com/starter',
				'alias'          => 'starter-slug',
				'title'          => 'Starter Title',
			),
			'system_owner_1'
		);

		$this->assertFalse( $alias_filter_invoked, 'link_alias must not run for internal creation without user context' );
		$this->assertFalse( $title_filter_invoked, 'link_title must not run for internal creation without user context' );
		$this->assertFalse( $destination_filter_invoked, 'link_destination_url must not run for internal creation without user context' );

		$this->assertSame( 'starter-slug', $result['alias'] );
		$this->assertSame( 'starter-slug', $result['short_code'] );
		$this->assertSame( 'Starter Title', $result['title'] );
		$this->assertSame( 'https://example.com/starter', $result['destination_url'] );
		$this->assertSame( 'system_owner_1', $result['user_id'] );
	}
}
