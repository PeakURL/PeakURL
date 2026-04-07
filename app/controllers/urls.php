<?php
/**
 * Short URL endpoints.
 *
 * Provides CRUD operations for shortened URLs, bulk import/delete, and
 * the public redirect handler that resolves short codes.
 *
 * @package PeakURL\Controllers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Controllers;

use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;
use PeakURL\Store;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * URL controller — delegates to Store for persistence and analytics.
 *
 * @since 1.0.0
 */
class UrlsController {

	/** @var Store Shared data access layer. */
	private Store $data_store;

	/**
	 * Create a new UrlsController.
	 *
	 * @param Store $data_store Data access layer instance.
	 * @since 1.0.0
	 */
	public function __construct( Store $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * Extract a stats-preview short code from a public route parameter.
	 *
	 * A trailing `+` opens the dashboard stats drawer instead of resolving the
	 * destination URL directly.
	 *
	 * @param string $id Raw public route identifier.
	 * @return string|null Sanitised short code or null when not a stats path.
	 * @since 1.0.0
	 */
	private function get_stats_preview_short_code( string $id ): ?string {
		$matches = array();

		if ( 1 !== preg_match( '/^([a-z0-9-]+)\+$/i', trim( $id ), $matches ) ) {
			return null;
		}

		$short_code = trim( (string) ( $matches[1] ?? '' ) );

		return '' !== $short_code ? $short_code : null;
	}

	/**
	 * Build an app-relative URL that preserves subdirectory installs.
	 *
	 * @param Request $request Current request instance.
	 * @param string  $suffix  Root-relative path to append.
	 * @return string URL path relative to the active install root.
	 * @since 1.0.0
	 */
	private function build_runtime_url( Request $request, string $suffix ): string {
		$script_name = str_replace(
			'\\',
			'/',
			(string) $request->get_server_param( 'SCRIPT_NAME', '/index.php' ),
		);
		$base_path   = dirname( $script_name );

		if ( '.' === $base_path || '/' === $base_path ) {
			$base_path = '';
		} else {
			$base_path = rtrim( $base_path, '/' );
		}

		$normalized_suffix = '/' . ltrim( $suffix, '/' );

		return '' === $base_path
			? $normalized_suffix
			: $base_path . $normalized_suffix;
	}

	/**
	 * List URLs with pagination and sorting (GET /api/v1/urls).
	 *
	 * @param Request $request Incoming HTTP request with query parameters.
	 * @return array<string, mixed> Paginated URL list response.
	 * @since 1.0.0
	 */
	public function index( Request $request ): array {
		$payload = $this->data_store->list_urls(
			$request,
			array(
				'page'      => $request->get_query_param( 'page', 1 ),
				'limit'     => $request->get_query_param( 'limit', 25 ),
				'sortBy'    => $request->get_query_param( 'sortBy', 'createdAt' ),
				'sortOrder' => $request->get_query_param( 'sortOrder', 'desc' ),
				'search'    => $request->get_query_param( 'search', '' ),
			)
		);

		return JsonResponse::success( $payload, __( 'URLs loaded.', 'peakurl' ) );
	}

	/**
	 * Export accessible URLs for the current user (GET /api/v1/urls/export).
	 *
	 * Returns all links the current user can access, without pagination, for
	 * dashboard export workflows.
	 *
	 * @param Request $request Incoming HTTP request with optional sort/search parameters.
	 * @return array<string, mixed> Export payload with all accessible links.
	 * @since 1.0.0
	 */
	public function export( Request $request ): array {
		$payload = $this->data_store->export_urls(
			$request,
			array(
				'sortBy'    => $request->get_query_param( 'sortBy', 'createdAt' ),
				'sortOrder' => $request->get_query_param( 'sortOrder', 'desc' ),
				'search'    => $request->get_query_param( 'search', '' ),
			)
		);

		return JsonResponse::success( $payload, __( 'URLs export loaded.', 'peakurl' ) );
	}

	/**
	 * Show a single URL by ID (GET /api/v1/urls/{id}).
	 *
	 * @param Request $request Request with route parameter 'id'.
	 * @return array<string, mixed> URL data or 404 error.
	 * @since 1.0.0
	 */
	public function show( Request $request ): array {
		$url = $this->data_store->find_url(
			$request,
			(string) $request->get_route_param( 'id' ),
		);

		if ( ! $url ) {
			return JsonResponse::error( __( 'URL not found.', 'peakurl' ), 404 );
		}

		return JsonResponse::success( $url, __( 'URL loaded.', 'peakurl' ) );
	}

	/**
	 * Create a new short URL (POST /api/v1/urls).
	 *
	 * @param Request $request Request with URL payload.
	 * @return array<string, mixed> Created URL response (201).
	 * @since 1.0.0
	 */
	public function create( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->create_url(
				$request,
				$request->get_body_params(),
			),
			__( 'Short URL created.', 'peakurl' ),
			201,
		);
	}

	/**
	 * Bulk import URLs (POST /api/v1/urls/bulk).
	 *
	 * @param Request $request Request with array of URL payloads.
	 * @return array<string, mixed> Bulk import result.
	 * @since 1.0.0
	 */
	public function bulk_create( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->bulk_create_urls(
				$request,
				$request->get_body_params(),
			),
			__( 'Bulk import processed.', 'peakurl' ),
		);
	}

	/**
	 * Update an existing URL (PUT /api/v1/urls/{id}).
	 *
	 * @param Request $request Request with route 'id' and body changes.
	 * @return array<string, mixed> Updated URL or 404 error.
	 * @since 1.0.0
	 */
	public function update( Request $request ): array {
		$url = $this->data_store->update_url(
			$request,
			(string) $request->get_route_param( 'id' ),
			$request->get_body_params(),
		);

		if ( ! $url ) {
			return JsonResponse::error( __( 'URL not found.', 'peakurl' ), 404 );
		}

		return JsonResponse::success( $url, __( 'URL updated.', 'peakurl' ) );
	}

	/**
	 * Delete a URL (DELETE /api/v1/urls/{id}).
	 *
	 * @param Request $request Request with route 'id'.
	 * @return array<string, mixed> Deletion confirmation or 404 error.
	 * @since 1.0.0
	 */
	public function delete( Request $request ): array {
		$deleted = $this->data_store->delete_url(
			$request,
			(string) $request->get_route_param( 'id' ),
		);

		if ( ! $deleted ) {
			return JsonResponse::error( __( 'URL not found.', 'peakurl' ), 404 );
		}

		return JsonResponse::success( array( 'deleted' => true ), __( 'URL deleted.', 'peakurl' ) );
	}

	/**
	 * Bulk delete URLs by ID array (DELETE /api/v1/urls/bulk).
	 *
	 * @param Request $request Request with 'ids' body parameter.
	 * @return array<string, mixed> Response with deleted count.
	 * @since 1.0.0
	 */
	public function bulk_delete( Request $request ): array {
		$ids   = $request->get_body_param( 'ids', array() );
		$count = $this->data_store->bulk_delete_urls(
			$request,
			is_array( $ids ) ? $ids : array(),
		);

		return JsonResponse::success(
			array(
				'deletedCount' => $count,
			),
			__( 'Bulk delete complete.', 'peakurl' ),
		);
	}

	/**
	 * Resolve a short code and handle public access (GET|POST /{id}).
	 *
	 * Handles password-protected and expired links before sending the final
	 * redirect response.
	 *
	 * @param Request $request Request with route 'id' (the short code).
	 * @return array<string, mixed> Redirect response or public HTML page.
	 * @since 1.0.0
	 */
	public function redirect( Request $request ): array {
		$route_id                 = (string) $request->get_route_param( 'id' );
		$stats_preview_short_code = $this->get_stats_preview_short_code( $route_id );

		if (
			null !== $stats_preview_short_code &&
			in_array( $request->get_method(), array( 'GET', 'HEAD' ), true )
		) {
			return JsonResponse::redirect(
				$this->build_runtime_url(
					$request,
					'/dashboard/links?stats=' .
						rawurlencode( $stats_preview_short_code ),
				),
			);
		}

		$result = $this->data_store->resolve_public_link_access(
			$route_id,
			$request,
		);

		if ( 'redirect' === ( $result['status'] ?? '' ) ) {
			return JsonResponse::redirect( (string) $result['location'] );
		}

		if (
			'password_required' === ( $result['status'] ?? '' ) ||
			'password_invalid' === ( $result['status'] ?? '' )
		) {
			return JsonResponse::text(
				$this->render_password_prompt(
					$request,
					(string) $request->get_route_param( 'id' ),
					is_array( $result['url'] ?? null ) ? $result['url'] : array(),
					(string) ( $result['message'] ?? '' ),
				),
				'password_invalid' === ( $result['status'] ?? '' ) ? 401 : 200,
				'text/html; charset=utf-8',
			);
		}

		if ( 'expired' === ( $result['status'] ?? '' ) ) {
			return JsonResponse::text(
				$this->render_public_status_page(
					__( 'This link has expired', 'peakurl' ),
					__( 'The short link you requested is no longer active because its expiration date has passed.', 'peakurl' ),
					'expired',
				),
				410,
				'text/html; charset=utf-8',
			);
		}

		return JsonResponse::text(
			$this->render_public_status_page(
				__( 'This link is unavailable', 'peakurl' ),
				__( 'The short link you requested is not available right now.', 'peakurl' ),
			),
			404,
			'text/html; charset=utf-8',
		);
	}

	/**
	 * Render the public password prompt for a protected short link.
	 *
	 * @param Request              $request Current HTTP request.
	 * @param string               $id      Short code or alias.
	 * @param array<string, mixed> $url     Raw URL row.
	 * @param string               $error   Optional error message.
	 * @return string HTML page markup.
	 * @since 1.0.0
	 */
	private function render_password_prompt(
		Request $request,
		string $id,
		array $url,
		string $error = ''
	): string {
		$form_action  = htmlspecialchars(
			$request->get_path(),
			ENT_QUOTES,
			'UTF-8',
		);
		$error_markup = '';

		if ( '' !== trim( $error ) ) {
			$error_markup =
				'<div class="alert">' .
				'<svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>' .
				'<span>' . htmlspecialchars( $error, ENT_QUOTES, 'UTF-8' ) . '</span>' .
				'</div>';
		}

		$lock_icon =
			'<svg class="hero-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' .
			'<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>' .
			'<path d="M7 11V7a5 5 0 0 1 10 0v4"/>' .
			'<circle cx="12" cy="16" r="1"/>' .
			'</svg>';

		$content_html =
			'<div class="hero-icon-wrap">' . $lock_icon . '</div>' .
			'<h1 class="title">' . __( 'Password required', 'peakurl' ) . '</h1>' .
			'<p class="subtitle">' . __( 'This link is protected. Enter the password to continue.', 'peakurl' ) . '</p>' .
			$error_markup .
			'<form method="post" action="' . $form_action . '" autocomplete="off">' .
			'<div class="field">' .
			'<label class="label" for="link_password">' . __( 'Password', 'peakurl' ) . '</label>' .
			'<div class="input-wrap">' .
			'<svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' .
			'<input class="input" id="link_password" name="link_password" type="password" autocomplete="current-password" placeholder="' . __( 'Enter password', 'peakurl' ) . '" required autofocus>' .
			'</div>' .
			'</div>' .
			'<button class="btn" type="submit">' .
			'<span>' . __( 'Continue', 'peakurl' ) . '</span>' .
			'<svg class="btn-arrow" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L11.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 11-1.04-1.08l3.158-2.96H3.75A.75.75 0 013 10z" clip-rule="evenodd"/></svg>' .
			'</button>' .
			'</form>';

		return $this->render_public_shell( __( 'Protected Link', 'peakurl' ), $content_html );
	}

	/**
	 * Render a simple public status page for expired or unavailable links.
	 *
	 * @param string $title       Page title.
	 * @param string $description Supporting description.
	 * @param string $icon_type   Status icon type: 'expired' or 'unavailable'.
	 * @return string HTML page markup.
	 * @since 1.0.0
	 */
	private function render_public_status_page(
		string $title,
		string $description,
		string $icon_type = 'unavailable'
	): string {
		if ( 'expired' === $icon_type ) {
			$icon =
				'<svg class="hero-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' .
				'<circle cx="12" cy="12" r="10"/>' .
				'<polyline points="12 6 12 12 16 14"/>' .
				'</svg>';
		} else {
			$icon =
				'<svg class="hero-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' .
				'<circle cx="12" cy="12" r="10"/>' .
				'<line x1="15" y1="9" x2="9" y2="15"/>' .
				'<line x1="9" y1="9" x2="15" y2="15"/>' .
				'</svg>';
		}

		$content_html =
			'<div class="hero-icon-wrap hero-icon-wrap--muted">' . $icon . '</div>' .
			'<h1 class="title">' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . '</h1>' .
			'<p class="subtitle">' . htmlspecialchars( $description, ENT_QUOTES, 'UTF-8' ) . '</p>';

		return $this->render_public_shell( $title, $content_html );
	}

	/**
	 * Render a shared branded shell for public short-link pages.
	 *
	 * @param string $page_title   Browser page title.
	 * @param string $content_html Safe inner HTML for the card body.
	 * @return string HTML page markup.
	 * @since 1.0.0
	 */
	private function render_public_shell(
		string $page_title,
		string $content_html
	): string {
		$page_title = htmlspecialchars( $page_title, ENT_QUOTES, 'UTF-8' );

		return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$page_title} &mdash; PeakURL</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --accent:#6366f1;--accent-hover:#4f46e5;
  --bg:#fafafa;--surface:#fff;
  --border:#e5e7eb;--border-focus:#6366f1;
  --heading:#111827;--text:#6b7280;
  --error:#ef4444;--error-bg:#fef2f2;--error-border:#fecaca;--error-text:#991b1b;
  --radius:12px;--radius-lg:20px;
}
body{
  font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  background:var(--bg);min-height:100vh;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:24px 16px;color:var(--heading);
  -webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;
}
.card{
  width:100%;max-width:420px;
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius-lg);
  padding:40px 36px;
  box-shadow:0 1px 3px rgba(0,0,0,.04),0 8px 24px rgba(0,0,0,.04);
  text-align:center;
}
.hero-icon-wrap{
  display:inline-flex;align-items:center;justify-content:center;
  width:56px;height:56px;border-radius:16px;
  background:linear-gradient(135deg,rgba(99,102,241,.1),rgba(99,102,241,.05));
  margin-bottom:20px;
}
.hero-icon-wrap--muted{
  background:linear-gradient(135deg,rgba(107,114,128,.1),rgba(107,114,128,.05));
}
.hero-icon-wrap--muted .hero-icon{color:#9ca3af}
.hero-icon{width:26px;height:26px;color:var(--accent)}
.title{
  font-size:22px;font-weight:700;letter-spacing:-.03em;
  color:var(--heading);line-height:1.2;margin-bottom:8px;
}
.subtitle{
  font-size:14px;line-height:1.6;color:var(--text);margin-bottom:0;
}
form{margin-top:28px;text-align:left}
.field{margin-bottom:20px}
.label{
  display:block;margin-bottom:6px;
  font-size:13px;font-weight:600;color:var(--heading);
}
.input-wrap{position:relative}
.input-icon{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  width:16px;height:16px;color:#9ca3af;pointer-events:none;
}
.input{
  width:100%;padding:12px 14px 12px 40px;
  border:1px solid var(--border);border-radius:var(--radius);
  background:var(--bg);color:var(--heading);
  font-size:14px;font-family:inherit;outline:none;
  transition:border-color .15s ease,box-shadow .15s ease;
}
.input::placeholder{color:#9ca3af}
.input:focus{
  border-color:var(--border-focus);
  box-shadow:0 0 0 3px rgba(99,102,241,.1);
  background:var(--surface);
}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  width:100%;padding:12px 20px;border:0;
  border-radius:var(--radius);
  background:var(--accent);color:#fff;
  font-size:14px;font-weight:600;font-family:inherit;
  cursor:pointer;
  transition:background .15s ease,transform .1s ease,box-shadow .15s ease;
}
.btn:hover{background:var(--accent-hover);box-shadow:0 4px 12px rgba(99,102,241,.25)}
.btn:active{transform:scale(.985)}
.btn-arrow{width:16px;height:16px;transition:transform .15s ease}
.btn:hover .btn-arrow{transform:translateX(2px)}
.alert{
  display:flex;align-items:flex-start;gap:8px;
  margin-top:0;margin-bottom:20px;
  padding:12px 14px;border-radius:var(--radius);
  background:var(--error-bg);border:1px solid var(--error-border);
  color:var(--error-text);font-size:13px;line-height:1.5;text-align:left;
}
.alert-icon{width:16px;height:16px;flex-shrink:0;margin-top:1px}
.footer{
  margin-top:24px;
  font-size:12px;color:#9ca3af;
}
.footer a{color:#9ca3af;text-decoration:none;transition:color .15s}
.footer a:hover{color:var(--heading)}
@media(max-width:480px){
  .card{padding:28px 24px;border-radius:16px}
  .title{font-size:20px}
}
</style>
</head>
<body>
<main class="card">{$content_html}</main>
<p class="footer">Powered by <a href="https://peakurl.org">PeakURL</a></p>
</body>
</html>
HTML;
	}

	/**
	 * Render the shared public PeakURL brand mark.
	 *
	 * @return string HTML markup.
	 * @since 1.0.0
	 */
	private function render_public_brand_mark(): string {
		return '<svg style="width:28px;height:28px" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
	}
}
