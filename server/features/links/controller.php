<?php
/**
 * Short URL controller endpoints.
 *
 * Provides CRUD operations for shortened URLs, bulk import/delete, and
 * the public redirect handler that resolves short codes.
 *
 * @package PeakURL\Features\Links
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Links;

use PeakURL\Core\Controller as BaseController;
use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Controller — Thin HTTP adapter delegating to Link Service.
 *
 * @since 1.0.0
 */
class Controller extends BaseController {

	/**
	 * Redirect status used after request-bound public access checks.
	 *
	 * @var int
	 * @since 1.2.0
	 */
	private const REDIRECT_TEMPORARY = 302;

	/**
	 * Link domain service.
	 *
	 * @var Service
	 * @since 1.0.0
	 */
	private Service $links_service;

	/**
	 * Create a new Link controller instance.
	 *
	 * @param Service $links_service Domain service instance.
	 * @since 1.0.0
	 */
	public function __construct( Service $links_service ) {
		$this->links_service = $links_service;
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
	private function get_stats_code( string $id ): ?string {
		$matches = array();

		if ( 1 !== preg_match( '/^([a-z0-9._-]+)\+$/i', trim( $id ), $matches ) ) {
			return null;
		}

		$short_code = trim( (string) ( $matches[1] ?? '' ) );

		return '' !== $short_code ? $short_code : null;
	}

	/**
	 * Get an app-relative path that preserves subdirectory installs.
	 *
	 * @param Request $request Current request instance.
	 * @param string  $suffix  Root-relative path to append.
	 * @return string URL path relative to the active install root.
	 * @since 1.0.0
	 */
	private function format_app_path( Request $request, string $suffix ): string {
		$base_path         = $request->get_base_path();
		$normalized_suffix = '/' . ltrim( $suffix, '/' );

		return '' === $base_path
			? $normalized_suffix
			: $base_path . $normalized_suffix;
	}

	/**
	 * List URLs with pagination and sorting.
	 *
	 * @param Request $request Incoming HTTP request with query parameters.
	 * @return array<string, mixed> Paginated URL list response.
	 * @since 1.0.0
	 */
	public function index( Request $request ): array {
		$payload = $this->links_service->list_urls(
			$request,
			$this->query_params(
				$request,
				array(
					'page'      => 1,
					'limit'     => 25,
					'sortBy'    => 'createdAt',
					'sortOrder' => 'desc',
					'status'    => 'all',
					'search'    => '',
					'range'     => '',
					'from'      => '',
					'to'        => '',
				),
			),
		);

		return $this->success_response( $payload, __( 'URLs loaded.', 'peakurl' ) );
	}

	/**
	 * Export accessible URLs for the current user.
	 *
	 * Returns all links the current user can access, without pagination, for
	 * dashboard export workflows.
	 *
	 * @param Request $request Incoming HTTP request with optional sort/search parameters.
	 * @return array<string, mixed> Export payload with all accessible links.
	 * @since 1.0.0
	 */
	public function export( Request $request ): array {
		$payload = $this->links_service->export_urls(
			$request,
			$this->query_params(
				$request,
				array(
					'sortBy'    => 'createdAt',
					'sortOrder' => 'desc',
					'search'    => '',
				),
			),
		);

		return $this->success_response( $payload, __( 'URLs export loaded.', 'peakurl' ) );
	}

	/**
	 * Show a single URL by ID.
	 *
	 * @param Request $request Request with route parameter 'id'.
	 * @return array<string, mixed> URL data or 404 error.
	 * @since 1.0.0
	 */
	public function show( Request $request ): array {
		$url = $this->links_service->find_url(
			$request,
			$this->route_param( $request, 'id' ),
		);

		return $this->found_response(
			$url,
			__( 'URL not found.', 'peakurl' ),
			__( 'URL loaded.', 'peakurl' ),
		);
	}

	/**
	 * Create a new short URL.
	 *
	 * @param Request $request Request with URL payload.
	 * @return array<string, mixed> Created URL response (201).
	 * @since 1.0.0
	 */
	public function create( Request $request ): array {
		return $this->success_response(
			$this->links_service->create_url(
				$request,
				$request->get_body_params(),
			),
			__( 'Short URL created.', 'peakurl' ),
			201,
		);
	}

	/**
	 * Bulk import URLs.
	 *
	 * @param Request $request Request with array of URL payloads.
	 * @return array<string, mixed> Bulk import result.
	 * @since 1.0.0
	 */
	public function bulk_create( Request $request ): array {
		return $this->success_response(
			$this->links_service->bulk_create_urls(
				$request,
				$request->get_body_params(),
			),
			__( 'Bulk import processed.', 'peakurl' ),
		);
	}

	/**
	 * Update an existing URL.
	 *
	 * @param Request $request Request with route 'id' and body changes.
	 * @return array<string, mixed> Updated URL or 404 error.
	 * @since 1.0.0
	 */
	public function update( Request $request ): array {
		$url = $this->links_service->update_url(
			$request,
			$this->route_param( $request, 'id' ),
			$request->get_body_params(),
		);

		return $this->found_response(
			$url,
			__( 'URL not found.', 'peakurl' ),
			__( 'URL updated.', 'peakurl' ),
		);
	}

	/**
	 * Delete a short URL by ID.
	 *
	 * Moves the URL to trash by default. If force is passed or the link is already
	 * in trash, permanently deletes the row.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Deletion confirmation or 404 error.
	 * @since 1.0.0
	 */
	public function delete( Request $request ): array {
		$force   = 'true' === (string) $request->get_query_param( 'force', '' ) || true === $request->get_body_param( 'force', false );
		$deleted = $this->links_service->delete_url(
			$request,
			$this->route_param( $request, 'id' ),
			$force,
		);

		return $this->delete_response(
			$deleted,
			__( 'URL not found.', 'peakurl' ),
			__( 'URL deleted.', 'peakurl' ),
		);
	}

	/**
	 * Restore a trashed short URL.
	 *
	 * @param Request $request Request.
	 * @return array<string, mixed> Restored URL response.
	 * @since 1.6.0
	 */
	public function restore( Request $request ): array {
		$item = $this->links_service->restore_url(
			$request,
			$this->route_param( $request, 'id' ),
		);

		return $this->success_response(
			$item,
			__( 'Link restored successfully.', 'peakurl' ),
		);
	}

	/**
	 * Manually check and record destination health for a short link.
	 *
	 * @param Request $request Request with route 'id'.
	 * @return array<string, mixed> Health check response.
	 * @since 1.7.1
	 */
	public function health_check( Request $request ): array {
		$id     = $this->route_param( $request, 'id' );
		$health = $this->links_service->check_link_health( $request, $id );

		return $this->success_response(
			$health,
			__( 'Health check completed.', 'peakurl' ),
		);
	}

	/**
	 * Bulk delete URLs by ID array.
	 *
	 * @param Request $request Request with 'ids' body parameter.
	 * @return array<string, mixed> Response with deleted count.
	 * @since 1.0.0
	 */
	public function bulk_delete( Request $request ): array {
		$force = 'true' === (string) $request->get_query_param( 'force', '' ) || true === $request->get_body_param( 'force', false );
		$count = $this->links_service->bulk_delete_urls(
			$request,
			$this->body_array_param( $request, 'ids' ),
			$force,
		);

		return $this->success_response(
			array(
				'deletedCount' => $count,
			),
			__( 'Bulk delete complete.', 'peakurl' ),
		);
	}

	/**
	 * Bulk restore trashed short URLs.
	 *
	 * @param Request $request Request with 'ids' body parameter.
	 * @return array<string, mixed> Restored count response.
	 * @since 1.6.0
	 */
	public function bulk_restore( Request $request ): array {
		$count = $this->links_service->bulk_restore_urls(
			$request,
			$this->body_array_param( $request, 'ids' ),
		);

		return $this->success_response(
			array(
				'restoredCount' => $count,
			),
			__( 'Bulk restore complete.', 'peakurl' ),
		);
	}

	/**
	 * Permanently empty all trashed URLs.
	 *
	 * @param Request $request Request.
	 * @return array<string, mixed> Deleted count response.
	 * @since 1.6.0
	 */
	public function empty_trash( Request $request ): array {
		$count = $this->links_service->empty_trash( $request );

		return $this->success_response(
			array(
				'deletedCount' => $count,
			),
			__( 'Trash emptied successfully.', 'peakurl' ),
		);
	}

	/**
	 * Delete or trash all accessible URLs based on the requested mode.
	 *
	 * Expects a body parameter 'mode' of either 'trash' or 'permanent'.
	 *
	 * @param Request $request Request with 'mode' body parameter.
	 * @return array<string, mixed> Response containing the affected count.
	 * @since 1.5.3
	 */
	public function clear( Request $request ): array {
		$mode = (string) $request->get_body_param( 'mode', '' );

		$count = $this->links_service->clear_urls( $request, $mode );

		return $this->success_response(
			array(
				'affectedCount' => $count,
			),
			'trash' === $mode
				? __( 'All links moved to trash.', 'peakurl' )
				: __( 'All links deleted.', 'peakurl' ),
		);
	}

	/**
	 * Resolve a short code and handle public access.
	 *
	 * Handles password-protected and expired links before sending the final
	 * redirect response.
	 *
	 * @param Request $request Request with route 'id' (the short code).
	 * @return array<string, mixed> Redirect response or public HTML page.
	 * @since 1.0.0
	 */
	public function redirect( Request $request ): array {
		$route_id   = $this->route_param( $request, 'id' );
		$stats_code = $this->get_stats_code( $route_id );

		if (
			null !== $stats_code &&
			in_array( $request->get_method(), array( 'GET', 'HEAD' ), true )
		) {
			return JsonResponse::redirect(
				$this->format_app_path(
					$request,
					'/dashboard/links?stats=' .
						rawurlencode( $stats_code ),
				),
				302,
			);
		}

		if (
			in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) &&
			$this->is_social_preview_request( $request )
		) {
			$preview = $this->links_service->get_link_social_preview( $route_id );

			if ( is_array( $preview ) ) {
				return JsonResponse::text(
					$this->format_social_preview_page(
						is_array( $preview['preview'] ?? null )
							? $preview['preview']
							: array(),
					),
					200,
					'text/html; charset=utf-8',
				);
			}
		}

		$result = $this->links_service->get_link_access(
			$route_id,
			$request,
		);

		if ( 'redirect' === ( $result['status'] ?? '' ) ) {
			return JsonResponse::redirect(
				(string) $result['location'],
				self::REDIRECT_TEMPORARY,
			);
		}

		if (
			'password_required' === ( $result['status'] ?? '' ) ||
			'password_invalid' === ( $result['status'] ?? '' )
		) {
			return JsonResponse::text(
				$this->format_password_page(
					$request,
					$this->route_param( $request, 'id' ),
					(string) ( $result['message'] ?? '' ),
				),
				'password_invalid' === ( $result['status'] ?? '' ) ? 401 : 200,
				'text/html; charset=utf-8',
			);
		}

		if (
			'captcha_required' === ( $result['status'] ?? '' ) ||
			'captcha_invalid' === ( $result['status'] ?? '' )
		) {
			return JsonResponse::text(
				$this->format_captcha_page(
					$request,
					is_array( $result['challenge'] ?? null )
						? $result['challenge']
						: array(),
					(string) ( $result['message'] ?? '' ),
				),
				'captcha_invalid' === ( $result['status'] ?? '' ) ? 403 : 200,
				'text/html; charset=utf-8',
			);
		}

		if ( 'expired' === ( $result['status'] ?? '' ) ) {
			return JsonResponse::text(
				$this->format_status_page(
					__( 'This link has expired', 'peakurl' ),
					__( 'The short link you requested is no longer active because its expiration date has passed.', 'peakurl' ),
					'expired',
				),
				410,
				'text/html; charset=utf-8',
			);
		}

		return JsonResponse::text(
			$this->format_status_page(
				__( 'Link not found', 'peakurl' ),
				__( 'Check that the address is complete and correct. This short link may have been removed or is no longer available.', 'peakurl' ),
			),
			404,
			'text/html; charset=utf-8',
		);
	}

	/**
	 * Get the public password page for a protected short link.
	 *
	 * @param Request $request Current HTTP request.
	 * @param string  $id      Short code or alias.
	 * @param string  $error   Optional error message.
	 * @return string HTML page markup.
	 * @since 1.0.0
	 */
	private function format_password_page(
		Request $request,
		string $id,
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

		return $this->format_public_page( __( 'Protected Link', 'peakurl' ), $content_html );
	}

	/**
	 * Get the public CAPTCHA page for a protected short link.
	 *
	 * @param Request              $request   Current HTTP request.
	 * @param array<string, mixed> $challenge Public CAPTCHA challenge settings.
	 * @param string               $error     Optional error message.
	 * @return string HTML page markup.
	 * @since 1.2.0
	 */
	private function format_captcha_page(
		Request $request,
		array $challenge,
		string $error = ''
	): string {
		$form_action = htmlspecialchars(
			$request->get_path(),
			ENT_QUOTES,
			'UTF-8',
		);
		$provider    = (string) ( $challenge['provider'] ?? '' );
		$has_error   = '' !== trim( $error );
		$script_url  = $this->format_captcha_script_url( $challenge, $provider );
		$status_html = $this->format_captcha_status_panel(
			$provider,
			$has_error,
			$error,
		);
		$widget_html = $this->format_captcha_widget( $challenge, $provider );

		$script_tag = '';
		if ( $this->is_allowed_captcha_script_url( $script_url ) ) {
			$script_tag = '<script src="' . $script_url . '" async defer></script>';
		}

		$shield_icon =
			'<svg class="hero-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' .
			'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>' .
			'<path d="m9 12 2 2 4-4"/>' .
			'</svg>';

		$content_html =
			$this->format_captcha_script( $challenge, $provider, $has_error, $error ) .
			$script_tag .
			'<div class="hero-icon-wrap">' . $shield_icon . '</div>' .
			'<h1 class="title">' . __( 'Performing security verification', 'peakurl' ) . '</h1>' .
			'<p class="subtitle">' . __( 'This website uses a security service to protect against malicious bots. This page is displayed while PeakURL verifies you are not a bot.', 'peakurl' ) . '</p>' .
			'<form id="captcha-form" class="captcha-form" method="post" action="' . $form_action . '" autocomplete="off">' .
			$status_html .
			$widget_html .
			'<noscript><p class="captcha-noscript">' . __( 'JavaScript is required to complete this verification.', 'peakurl' ) . '</p></noscript>' .
			'</form>';

		return $this->format_public_page( __( 'Security Verification', 'peakurl' ), $content_html );
	}

	/**
	 * Validate that CAPTCHA script URL is HTTPS and hosted by an allowed domain.
	 *
	 * @param string $url Script URL to validate.
	 * @return bool True when URL is trusted for script inclusion.
	 * @since 1.0.0
	 */
	private function is_allowed_captcha_script_url( string $url ): bool {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}

		$allowed_hosts = array(
			'www.google.com',
			'www.gstatic.com',
			'challenges.cloudflare.com',
		);

		return in_array( $host, $allowed_hosts, true );
	}

	/**
	 * Format the provider script URL for the CAPTCHA page.
	 *
	 * @param array<string, mixed> $challenge Public CAPTCHA challenge settings.
	 * @param string               $provider  CAPTCHA provider key.
	 * @return string Escaped script URL.
	 * @since 1.2.2
	 */
	private function format_captcha_script_url(
		array $challenge,
		string $provider
	): string {
		$script_url = (string) ( $challenge['scriptUrl'] ?? '' );
		$query      = array();

		if ( 'recaptcha' === $provider ) {
			$query = array(
				'render' => (string) ( $challenge['siteKey'] ?? '' ),
				'onload' => 'PeakURLRenderCaptcha',
			);
		} elseif ( 'turnstile' === $provider ) {
			$query = array(
				'render' => 'explicit',
				'onload' => 'PeakURLRenderCaptcha',
			);
		}

		if ( array() !== $query ) {
			$script_query = http_build_query( $query, '', '&' );
			$script_url  .= false === strpos( $script_url, '?' )
				? '?' . $script_query
				: '&' . $script_query;
		}

		return htmlspecialchars( $script_url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Format the CAPTCHA page script.
	 *
	 * @param array<string, mixed> $challenge Public CAPTCHA challenge settings.
	 * @param string               $provider  CAPTCHA provider key.
	 * @param bool                 $has_error Whether the previous verification failed.
	 * @param string               $error     Verification error message.
	 * @return string Inline script markup.
	 * @since 1.2.2
	 */
	private function format_captcha_script(
		array $challenge,
		string $provider,
		bool $has_error,
		string $error
	): string {
		$site_key_json   = $this->encode_captcha_json(
			(string) ( $challenge['siteKey'] ?? '' ),
		);
		$action_json     = $this->encode_captcha_json(
			(string) ( $challenge['action'] ?? 'peakurl_redirect' ),
		);
		$provider_json   = $this->encode_captcha_json( $provider );
		$auto_json       = $this->encode_captcha_json( ! $has_error );
		$initial_state   = $has_error ? 'error' : 'pending';
		$initial_status  = $this->encode_captcha_json(
			$has_error
				? $error
				: __( 'Waiting for verification…', 'peakurl' ),
		);
		$checking_status = $this->encode_captcha_json(
			__( 'Checking verification…', 'peakurl' ),
		);
		$running_status  = $this->encode_captcha_json(
			__( 'Starting verification…', 'peakurl' ),
		);
		$expired_status  = $this->encode_captcha_json(
			__( 'Verification expired. Please try again.', 'peakurl' ),
		);
		$error_status    = $this->encode_captcha_json(
			__( 'Verification could not be completed. Please try again.', 'peakurl' ),
		);

		return <<<HTML
<script>
(function(){
var submitted=false;
var active=false;
var provider={$provider_json};
var autoVerify={$auto_json};
var turnstileSelector="#captcha-turnstile";
var turnstileWidgetId=null;
function whenReady(callback){
	if("loading"===document.readyState){
		document.addEventListener("DOMContentLoaded",callback);
		return;
	}
	callback();
}
function setRetryVisible(visible){
	var button=document.getElementById("captcha-retry-button");
	if(button){button.hidden=!visible;}
}
function setWidgetVisible(visible){
	var widget=document.getElementById("captcha-widget");
	if(widget){widget.hidden=!visible;}
}
function setStatus(message,state,visible){
	var status=document.getElementById("captcha-status-text");
	var panel=document.getElementById("captcha-status-panel");
	var needsRetry="error"===state||"warning"===state;
	var showPanel=false!==visible||needsRetry;
	if(status){status.textContent=message;}
	if(panel){
		panel.className="captcha-status-panel captcha-status-panel-"+state;
		panel.hidden=!showPanel;
	}
	setRetryVisible(needsRetry);
	if(needsRetry){setWidgetVisible(false);}
}
function setToken(token){
	var input=document.getElementById("captcha-token");
	if(input){input.value=token||"";}
}
function renderTurnstile(){
	if("turnstile"!==provider||!window.turnstile||turnstileWidgetId!==null){return;}
	/* Explicit execution prevents automatic retry loops after a failed token. */
	try{
		turnstileWidgetId=window.turnstile.render(turnstileSelector,{
			sitekey:{$site_key_json},
			theme:window.PeakURLCaptchaTheme(),
			language:"auto",
			execution:"execute",
			callback:window.PeakURLCaptchaVerified,
			"expired-callback":window.PeakURLCaptchaExpired,
			"error-callback":window.PeakURLCaptchaError
		});
	}catch(error){
		window.PeakURLCaptchaError();
	}
}
function startVerification(){
	submitted=false;
	active=true;
	setToken("");
	setRetryVisible(false);
	setWidgetVisible("turnstile"===provider);
	/* Turnstile shows its own challenge state, so keep our panel for fallback and retry states. */
	setStatus({$running_status},"pending","turnstile"!==provider);
	if("recaptcha"===provider){
		if(!window.grecaptcha){window.PeakURLCaptchaError();return;}
		window.grecaptcha.ready(function(){
			window.grecaptcha.execute({$site_key_json},{action:{$action_json}}).then(
				window.PeakURLCaptchaVerified,
				window.PeakURLCaptchaError
			);
		});
		return;
	}
	if("turnstile"===provider){
		if(!window.turnstile){window.PeakURLCaptchaError();return;}
		if(turnstileWidgetId===null){renderTurnstile();}
		if(turnstileWidgetId===null){window.PeakURLCaptchaError();return;}
		try{
			window.turnstile.reset(turnstileWidgetId);
			window.turnstile.execute(turnstileSelector);
		}catch(error){
			window.PeakURLCaptchaError();
		}
	}
}
function submitVerification(token){
	if(submitted||!active){return;}
	if(!token){window.PeakURLCaptchaError();return;}
	submitted=true;
	active=false;
	setToken(token);
	/* Keep Turnstile visible so Cloudflare can show the verified state before submit. */
	if("turnstile"!==provider){setWidgetVisible(false);}
	setStatus({$checking_status},"pending","turnstile"!==provider);
	window.setTimeout(function(){
		whenReady(function(){
			var form=document.getElementById("captcha-form");
			if(form){form.submit();}
		});
	},"turnstile"===provider?650:160);
}
window.PeakURLCaptchaVerified=submitVerification;
window.PeakURLCaptchaExpired=function(){submitted=false;active=false;setStatus({$expired_status},"warning");};
window.PeakURLCaptchaError=function(){submitted=false;active=false;setStatus({$error_status},"warning");};
window.PeakURLStartCaptcha=startVerification;
window.PeakURLCaptchaTheme=function(){
	return window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";
};
window.PeakURLRenderCaptcha=function(){
	whenReady(function(){
		if(autoVerify){
			startVerification();
			return;
		}
		setStatus({$initial_status},"{$initial_state}");
	});
};
document.addEventListener("click",function(event){
	if(event.target&&"captcha-retry-button"===event.target.id){startVerification();}
});
}());
</script>
HTML;
	}

	/**
	 * Format the verification status panel.
	 *
	 * @param string $provider  CAPTCHA provider key.
	 * @param bool   $has_error Whether the previous verification failed.
	 * @param string $error     Verification error message.
	 * @return string Status panel HTML.
	 * @since 1.2.2
	 */
	private function format_captcha_status_panel(
		string $provider,
		bool $has_error,
		string $error
	): string {
		$state       = $has_error ? 'error' : 'pending';
		$status_text = htmlspecialchars(
			$has_error
				? $error
				: __( 'Waiting for verification…', 'peakurl' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$retry_label = htmlspecialchars(
			__( 'Try again', 'peakurl' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$has_retry   = in_array(
			$provider,
			array( 'recaptcha', 'turnstile' ),
			true,
		);
		$retry_html  = $has_retry
			? '<button id="captcha-retry-button" class="captcha-retry-button" type="button"' . ( $has_error ? '' : ' hidden' ) . '>' . $retry_label . '</button>'
			: '';

		return '<div id="captcha-status-panel" class="captcha-status-panel captcha-status-panel-' . $state . '">' .
				'<span class="captcha-spinner" aria-hidden="true"></span>' .
				'<span class="captcha-status-content">' .
					'<span id="captcha-status-text" class="captcha-status-text">' . $status_text . '</span>' .
					$retry_html .
				'</span>' .
			'</div>';
	}

	/**
	 * Format provider-specific CAPTCHA widget markup.
	 *
	 * @param array<string, mixed> $challenge Public CAPTCHA challenge settings.
	 * @param string               $provider  CAPTCHA provider key.
	 * @return string Widget HTML.
	 * @since 1.2.2
	 */
	private function format_captcha_widget(
		array $challenge,
		string $provider
	): string {
		if ( 'turnstile' === $provider ) {
			return '<div id="captcha-widget" class="captcha-widget" hidden><div id="captcha-turnstile" class="captcha-turnstile"></div></div>';
		}

		$response_field = htmlspecialchars(
			(string) ( $challenge['responseField'] ?? 'g-recaptcha-response' ),
			ENT_QUOTES,
			'UTF-8',
		);

		return '<input id="captcha-token" type="hidden" name="' . $response_field . '" value="">';
	}

	/**
	 * Encode a value for the inline CAPTCHA script.
	 *
	 * @param mixed $value Value to encode.
	 * @return string JSON literal safe for script context.
	 * @since 1.2.2
	 */
	private function encode_captcha_json( $value ): string {
		$encoded = json_encode(
			$value,
			JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT,
		);

		return false === $encoded ? 'null' : $encoded;
	}

	/**
	 * Determine whether the request is from a social preview crawler.
	 *
	 * @param Request $request Current HTTP request.
	 * @return bool True when the user-agent is known to fetch link previews.
	 * @since 1.2.0
	 */
	private function is_social_preview_request( Request $request ): bool {
		$user_agent = strtolower( $request->get_user_agent() );

		if ( '' === $user_agent ) {
			return false;
		}

		foreach (
			array(
				'facebookexternalhit',
				'facebot',
				'twitterbot',
				'linkedinbot',
				'slackbot',
				'discordbot',
				'whatsapp',
				'telegrambot',
				'pinterest',
				'skypeuripreview',
				'applebot',
			) as $crawler
		) {
			if ( false !== strpos( $user_agent, $crawler ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a public HTML document containing social preview metadata.
	 *
	 * @param array<string, string> $preview Social preview metadata.
	 * @return string HTML page markup.
	 * @since 1.2.0
	 */
	private function format_social_preview_page( array $preview ): string {
		$title          = $this->escape_meta_text(
			(string) ( $preview['title'] ?? 'PeakURL' ),
		);
		$description    = $this->escape_meta_text(
			(string) ( $preview['description'] ?? '' ),
		);
		$site_name      = $this->escape_meta_text(
			(string) ( $preview['siteName'] ?? 'PeakURL' ),
		);
		$url            = esc_url( (string) ( $preview['url'] ?? '' ) );
		$image_url      = esc_url( (string) ( $preview['imageUrl'] ?? '' ) );
		$image_tags     = '';
		$card_type      = '' !== $image_url ? 'summary_large_image' : 'summary';
		$generator_meta = get_generator_tag();
		$generator_line = '' !== $generator_meta ? "\n" . $generator_meta : '';

		if ( '' !== $image_url ) {
			$image_tags =
				'<meta property="og:image" content="' . $image_url . '">' . "\n" .
				'<meta property="og:image:secure_url" content="' . $image_url . '">' . "\n" .
				'<meta name="twitter:image" content="' . $image_url . '">' . "\n";
		}

		return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, max-image-preview:large">{$generator_line}
<link rel="canonical" href="{$url}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{$site_name}">
<meta property="og:title" content="{$title}">
<meta property="og:description" content="{$description}">
<meta property="og:url" content="{$url}">
{$image_tags}<meta name="twitter:card" content="{$card_type}">
<meta name="twitter:title" content="{$title}">
<meta name="twitter:description" content="{$description}">
<meta name="twitter:url" content="{$url}">
</head>
<body>
<main>
<h1>{$title}</h1>
<p>{$description}</p>
<p><a href="{$url}">{$url}</a></p>
</main>
</body>
</html>
HTML;
	}

	/**
	 * Escape plain text for meta attributes and fallback body text.
	 *
	 * @param string $value Raw text.
	 * @return string Escaped text.
	 * @since 1.2.0
	 */
	private function escape_meta_text( string $value ): string {
		return htmlspecialchars( trim( $value ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Get a branded public status page for expired or unavailable links.
	 *
	 * @param string $title       Page title.
	 * @param string $description Supporting description.
	 * @param string $icon_type   Status icon type: 'expired' or 'unavailable'.
	 * @return string HTML page markup.
	 * @since 1.0.0
	 */
	private function format_status_page(
		string $title,
		string $description,
		string $icon_type = 'unavailable'
	): string {
		$is_expired = 'expired' === $icon_type;

		return \PeakURL\Services\StatusPage::render(
			$title,
			$description,
			$is_expired ? __( '410 · Link expired', 'peakurl' ) : __( '404 · Link not found', 'peakurl' ),
			__( 'Contact the person who shared this link to request a new address. You do not need an account to open a public short link.', 'peakurl' ),
		);
	}

	/**
	 * Get shared branded markup for public short-link pages.
	 *
	 * @param string $page_title   Browser page title.
	 * @param string $content_html Safe inner HTML for the public page.
	 * @return string HTML page markup.
	 * @since 1.0.0
	 */
	private function format_public_page(
		string $page_title,
		string $content_html
	): string {
		return \PeakURL\Services\StatusPage::render_document( $page_title, $content_html );
	}
}
