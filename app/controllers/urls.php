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

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * URL controller — delegates to Store for persistence and analytics.
 *
 * @since 1.0.0
 */
class UrlsController extends BaseController {

	/**
	 * Redirect status used for public short links with stable destinations.
	 *
	 * @var int
	 * @since 1.1.2
	 */
	private const REDIRECT_PERMANENT = 301;

	/**
	 * Redirect status used after request-bound public access checks.
	 *
	 * @var int
	 * @since 1.2.0
	 */
	private const REDIRECT_TEMPORARY = 302;

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

		if ( 1 !== preg_match( '/^([a-z0-9-]+)\+$/i', trim( $id ), $matches ) ) {
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
		$payload = $this->data_store->list_urls(
			$request,
			$this->query_params(
				$request,
				array(
					'page'      => 1,
					'limit'     => 25,
					'sortBy'    => 'createdAt',
					'sortOrder' => 'desc',
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
		$payload = $this->data_store->export_urls(
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
		$url = $this->data_store->find_url(
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
			$this->data_store->create_url(
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
			$this->data_store->bulk_create_urls(
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
		$url = $this->data_store->update_url(
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
	 * Delete a URL.
	 *
	 * @param Request $request Request with route 'id'.
	 * @return array<string, mixed> Deletion confirmation or 404 error.
	 * @since 1.0.0
	 */
	public function delete( Request $request ): array {
		$deleted = $this->data_store->delete_url(
			$request,
			$this->route_param( $request, 'id' ),
		);

		return $this->delete_response(
			$deleted,
			__( 'URL not found.', 'peakurl' ),
			__( 'URL deleted.', 'peakurl' ),
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
		$count = $this->data_store->bulk_delete_urls(
			$request,
			$this->body_array_param( $request, 'ids' ),
		);

		return $this->success_response(
			array(
				'deletedCount' => $count,
			),
			__( 'Bulk delete complete.', 'peakurl' ),
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
			$preview = $this->data_store->get_link_social_preview( $route_id );

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

		$result = $this->data_store->get_link_access(
			$route_id,
			$request,
		);

		if ( 'redirect' === ( $result['status'] ?? '' ) ) {
			return JsonResponse::redirect(
				(string) $result['location'],
				! empty( $result['captchaProtected'] )
					? self::REDIRECT_TEMPORARY
					: self::REDIRECT_PERMANENT,
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
					is_array( $result['url'] ?? null ) ? $result['url'] : array(),
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
				__( 'This link is unavailable', 'peakurl' ),
				__( 'The short link you requested is not available right now.', 'peakurl' ),
			),
			404,
			'text/html; charset=utf-8',
		);
	}

	/**
	 * Get the public password page for a protected short link.
	 *
	 * @param Request              $request Current HTTP request.
	 * @param string               $id      Short code or alias.
	 * @param array<string, mixed> $url     Raw URL row.
	 * @param string               $error   Optional error message.
	 * @return string HTML page markup.
	 * @since 1.0.0
	 */
	private function format_password_page(
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
		$form_action    = htmlspecialchars(
			$request->get_path(),
			ENT_QUOTES,
			'UTF-8',
		);
		$site_key       = htmlspecialchars(
			(string) ( $challenge['siteKey'] ?? '' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$response_field = htmlspecialchars(
			(string) ( $challenge['responseField'] ?? 'g-recaptcha-response' ),
			ENT_QUOTES,
			'UTF-8',
		);
		$script_url_raw = (string) ( $challenge['scriptUrl'] ?? '' );
		$provider       = (string) ( $challenge['provider'] ?? '' );

		if ( 'recaptcha' === $provider ) {
			$script_query    = http_build_query(
				array(
					'render' => (string) ( $challenge['siteKey'] ?? '' ),
					'onload' => 'PeakURLRenderCaptcha',
				),
				'',
				'&',
			);
			$script_url_raw .= false === strpos( $script_url_raw, '?' )
				? '?' . $script_query
				: '&' . $script_query;
		}

		$script_url     = htmlspecialchars(
			$script_url_raw,
			ENT_QUOTES,
			'UTF-8',
		);
		$json_flags     = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
		$site_key_json  = json_encode(
			(string) ( $challenge['siteKey'] ?? '' ),
			$json_flags,
		);
		$action_json    = json_encode(
			(string) ( $challenge['action'] ?? 'peakurl_redirect' ),
			$json_flags,
		);
		$success_status = json_encode(
			__( 'Verification complete. Redirecting…', 'peakurl' ),
			$json_flags,
		);
		$expired_status = json_encode(
			__( 'Verification expired. Please try again.', 'peakurl' ),
			$json_flags,
		);
		$error_status   = json_encode(
			__( 'Verification could not be completed. Please try again.', 'peakurl' ),
			$json_flags,
		);
		$error_markup   = '';

		if ( '' !== trim( $error ) ) {
			$error_markup =
				'<div class="alert">' .
				'<svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>' .
				'<span>' . htmlspecialchars( $error, ENT_QUOTES, 'UTF-8' ) . '</span>' .
				'</div>';
		}

		$shield_icon =
			'<svg class="hero-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' .
			'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>' .
			'<path d="m9 12 2 2 4-4"/>' .
			'</svg>';

		$verification_script =
			'<script>' .
			'(function(){' .
			'var submitted=false;' .
			'function setStatus(message,state){' .
			'var status=document.getElementById("captcha-status-text");' .
			'var panel=document.getElementById("captcha-status-panel");' .
			'if(status){status.textContent=message;}' .
			'if(panel){panel.className="captcha-status-panel captcha-status-panel-"+state;}' .
			'}' .
			'function setToken(token){' .
			'var input=document.getElementById("captcha-token");' .
			'if(input){input.value=token||"";}' .
			'}' .
			'function submitVerification(token){' .
			'if(submitted){return;}' .
			'submitted=true;' .
			'setToken(token);' .
			'setStatus(' . $success_status . ',"success");' .
			'window.setTimeout(function(){' .
			'var form=document.getElementById("captcha-form");' .
			'if(form){form.submit();}' .
			'},160);' .
			'}' .
			'window.PeakURLCaptchaVerified=submitVerification;' .
			'window.PeakURLCaptchaExpired=function(){submitted=false;setStatus(' . $expired_status . ',"warning");};' .
			'window.PeakURLCaptchaError=function(){submitted=false;setStatus(' . $error_status . ',"warning");};' .
			'window.PeakURLCaptchaTheme=function(){return window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";};' .
			'window.PeakURLRenderCaptcha=function(){' .
			'if(!window.grecaptcha){window.PeakURLCaptchaError();return;}' .
			'window.grecaptcha.ready(function(){' .
			'window.grecaptcha.execute(' . $site_key_json . ',{action:' . $action_json . '}).then(' .
			'window.PeakURLCaptchaVerified,' .
			'window.PeakURLCaptchaError' .
			');' .
			'});' .
			'};' .
			'}());' .
			'</script>';

		$widget_html = 'turnstile' === $provider
			? '<div class="captcha-widget"><div class="cf-turnstile" data-sitekey="' . $site_key . '" data-theme="auto" data-language="auto" data-callback="PeakURLCaptchaVerified" data-expired-callback="PeakURLCaptchaExpired" data-error-callback="PeakURLCaptchaError"></div></div>'
			: '<input id="captcha-token" type="hidden" name="' . $response_field . '" value="">';
		$status_html = 'recaptcha' === $provider
			? '<div id="captcha-status-panel" class="captcha-status-panel captcha-status-panel-pending">' .
				'<span class="captcha-spinner" aria-hidden="true"></span>' .
				'<span id="captcha-status-text">' . __( 'Waiting for verification…', 'peakurl' ) . '</span>' .
			'</div>'
			: '';

		$content_html =
			$verification_script .
			'<script src="' . $script_url . '" async defer></script>' .
			'<div class="hero-icon-wrap">' . $shield_icon . '</div>' .
			'<h1 class="title">' . __( 'Performing security verification', 'peakurl' ) . '</h1>' .
			'<p class="subtitle">' . __( 'This website uses a security service to protect against malicious bots. This page is displayed while PeakURL verifies you are not a bot.', 'peakurl' ) . '</p>' .
			$error_markup .
			'<form id="captcha-form" class="captcha-form" method="post" action="' . $form_action . '" autocomplete="off">' .
			$status_html .
			$widget_html .
			'<noscript><p class="captcha-noscript">' . __( 'JavaScript is required to complete this verification.', 'peakurl' ) . '</p></noscript>' .
			'</form>';

		return $this->format_public_page( __( 'Security Verification', 'peakurl' ), $content_html );
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
		$title       = $this->escape_meta_text(
			(string) ( $preview['title'] ?? 'PeakURL' ),
		);
		$description = $this->escape_meta_text(
			(string) ( $preview['description'] ?? '' ),
		);
		$site_name   = $this->escape_meta_text(
			(string) ( $preview['siteName'] ?? 'PeakURL' ),
		);
		$url         = esc_url( (string) ( $preview['url'] ?? '' ) );
		$image_url   = esc_url( (string) ( $preview['imageUrl'] ?? '' ) );
		$image_tags  = '';
		$card_type   = '' !== $image_url ? 'summary_large_image' : 'summary';

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
<meta name="robots" content="noindex, max-image-preview:large">
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
	 * Get a simple public status page for expired or unavailable links.
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

		return $this->format_public_page( $title, $content_html );
	}

	/**
	 * Get shared branded markup for public short-link pages.
	 *
	 * @param string $page_title   Browser page title.
	 * @param string $content_html Safe inner HTML for the card body.
	 * @return string HTML page markup.
	 * @since 1.0.0
	 */
	private function format_public_page(
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
  color-scheme:light;
  --accent:#6366f1;--accent-hover:#4f46e5;
  --bg:#fafafa;--surface:#fff;
  --border:#e5e7eb;--border-focus:#6366f1;
  --heading:#111827;--text:#6b7280;
  --error:#ef4444;--error-bg:#fef2f2;--error-border:#fecaca;--error-text:#991b1b;
  --radius:12px;--radius-lg:20px;
}
@media(prefers-color-scheme:dark){
  :root{
    color-scheme:dark;
    --accent:#818cf8;--accent-hover:#6366f1;
    --bg:#111113;--surface:#18181b;
    --border:#2d2d32;--border-focus:#818cf8;
    --heading:#f8fafc;--text:#a1a1aa;
    --error:#f87171;--error-bg:#2a1518;--error-border:#7f1d1d;--error-text:#fecaca;
  }
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
@media(prefers-color-scheme:dark){
  .card{box-shadow:0 1px 3px rgba(0,0,0,.25),0 18px 40px rgba(0,0,0,.28)}
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
  font-size:22px;font-weight:700;letter-spacing:0;
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
.captcha-form{margin-top:24px}
.captcha-status-panel{
  display:flex;align-items:center;gap:10px;
  margin-bottom:16px;padding:12px 14px;
  border:1px solid var(--border);border-radius:var(--radius);
  background:var(--bg);color:var(--text);
  font-size:13px;font-weight:500;line-height:1.5;text-align:left;
}
.captcha-status-panel-success{
  border-color:#bbf7d0;background:#f0fdf4;color:#166534;
}
.captcha-status-panel-warning{
  border-color:#fed7aa;background:#fff7ed;color:#9a3412;
}
@media(prefers-color-scheme:dark){
  .captcha-status-panel-success{
    border-color:#14532d;background:#102719;color:#bbf7d0;
  }
  .captcha-status-panel-warning{
    border-color:#7c2d12;background:#2d1b10;color:#fed7aa;
  }
}
.captcha-spinner{
  width:16px;height:16px;border-radius:999px;
  border:2px solid rgba(99,102,241,.22);
  border-top-color:var(--accent);
  animation:captcha-spin .8s linear infinite;flex-shrink:0;
}
.captcha-status-panel-success .captcha-spinner{
  border-color:#22c55e;background:#22c55e;animation:none;
}
.captcha-status-panel-warning .captcha-spinner{
  border-color:#f97316;border-top-color:#f97316;animation:none;
}
.captcha-widget{
  display:flex;justify-content:center;align-items:center;
  min-height:78px;overflow:hidden;
}
.captcha-widget iframe{max-width:100%}
.captcha-noscript{
  margin-top:14px;color:var(--error-text);font-size:13px;line-height:1.5;text-align:center;
}
@keyframes captcha-spin{to{transform:rotate(360deg)}}
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
<p class="footer">Powered by <a href="https://peakurl.org?utm_source=peakurl_urls_controller&utm_medium=public_page&utm_campaign=powered_by" target="_blank" rel="noopener noreferrer">PeakURL</a></p>
</body>
</html>
HTML;
	}
}
