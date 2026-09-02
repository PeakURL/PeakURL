/**
 * Dashboard API route helpers.
 *
 * PHP owns the public API base (`/api/v1`) and injects it through
 * `window.__PEAKURL__.apiBase`. This module owns only the path after that
 * base, so RTK Query slices and direct fetches share one route map.
 */

import { API_CLIENT_BASE_URL } from "@/constants";

/**
 * Primitive value accepted as one API path part.
 */
type ApiPathPart = string | number;

/**
 * Primitive value accepted as one API query parameter.
 */
type ApiQueryValue = string | number | boolean | null | undefined;

/**
 * Encode one dynamic API route parameter before placing it in a path.
 *
 * Use this for IDs, tokens, usernames, or any value that may contain
 * characters with special meaning in a URL path.
 */
function encodeApiParam(value: ApiPathPart): string {
	return encodeURIComponent(String(value));
}

/**
 * Join API path parts without leading or trailing slashes.
 *
 * Routes intentionally stay relative to `API_CLIENT_BASE_URL`, which keeps
 * release installs, subdirectory installs, and Vite/dev mode on the same API
 * contract.
 */
function apiPath(...parts: ApiPathPart[]): string {
	return parts
		.map((part) => String(part).replace(/^\/+|\/+$/g, ""))
		.filter(Boolean)
		.join("/");
}

/**
 * Build a relative API route with optional query parameters.
 *
 * Use this for endpoints that accept query strings. It keeps route builders
 * readable and avoids returning routes with a
 * trailing `?` when no query parameters are present.
 */
export function buildApiRouteWithQuery(
	path: string,
	params?: URLSearchParams
): string {
	const query = params?.toString();

	return query ? `${path}?${query}` : path;
}

/**
 * Build query parameters from defined dashboard API values.
 *
 * Blank strings are preserved because a route may intentionally send one.
 * Pass `null` or `undefined` for optional filters that should be omitted.
 *
 * @param params - Query parameter names and values.
 * @return The populated query parameter object.
 */
export function createApiQueryParams(
	params: Record<string, ApiQueryValue>
): URLSearchParams {
	const query = new URLSearchParams();

	Object.entries(params).forEach(([key, value]) => {
		if (null === value || undefined === value) {
			return;
		}

		query.set(key, String(value));
	});

	return query;
}

/**
 * Build a browser request URL from the injected API base and a relative route.
 *
 * Use this for direct `fetch()` calls. RTK Query already receives the same
 * base URL through its configured `baseQuery`.
 */
export function getApiRequestUrl(route: string): string {
	const apiBase = API_CLIENT_BASE_URL.replace(/\/+$/g, "");
	const apiRoute = route.replace(/^\/+/g, "");

	return apiRoute ? `${apiBase}/${apiRoute}` : apiBase;
}

/**
 * Canonical dashboard API routes.
 *
 * Every value is relative to `API_CLIENT_BASE_URL`. Static values are plain
 * strings; dynamic values are functions so callers cannot forget to encode
 * route parameters.
 */
export const API_ROUTES = {
	/**
	 * Analytics, activity logs, and per-link reporting endpoints.
	 */
	analytics: {
		/**
		 * Relative API path: `analytics/activity`.
		 *
		 * Loads the recent dashboard activity feed.
		 */
		activity: apiPath("analytics", "activity"),

		/**
		 * Relative API path: `analytics/activity/{id}`.
		 *
		 * Deletes one activity-log row by ID.
		 */
		activityById: (id: ApiPathPart) =>
			apiPath("analytics", "activity", encodeApiParam(id)),

		/**
		 * Relative API path: `analytics/activity/bulk`.
		 *
		 * Deletes multiple activity-log rows in one request.
		 */
		activityBulk: apiPath("analytics", "activity", "bulk"),

		/**
		 * Relative API path: `analytics/activity/history`.
		 *
		 * Loads paginated dashboard activity history.
		 */
		activityHistory: apiPath("analytics", "activity", "history"),

		/**
		 * Relative API path: `analytics`.
		 *
		 * Loads the dashboard analytics summary.
		 */
		index: apiPath("analytics"),

		/**
		 * Relative API path: `analytics/url/{id}/location`.
		 *
		 * Loads GeoIP analytics for one short link.
		 */
		linkLocation: (id: ApiPathPart) =>
			apiPath("analytics", "url", encodeApiParam(id), "location"),

		/**
		 * Relative API path: `analytics/url/{id}/stats`.
		 *
		 * Loads click statistics for one short link.
		 */
		linkStats: (id: ApiPathPart) =>
			apiPath("analytics", "url", encodeApiParam(id), "stats"),

		/**
		 * Relative API path: `analytics/activity/{id}/restore`.
		 *
		 * Restores a link from an activity log entry.
		 */
		restoreActivityLink: (id: ApiPathPart) =>
			apiPath("analytics", "activity", encodeApiParam(id), "restore"),

		/**
		 * Relative API path: `analytics/recent-clicks`.
		 *
		 * Loads the recent clicks feed.
		 */
		recentClicks: apiPath("analytics", "recent-clicks"),
	},

	/**
	 * Authentication, password recovery, API keys, and account security.
	 */
	auth: {
		/**
		 * Relative API path: `auth/api-key`.
		 *
		 * Creates a dashboard API key for the current user.
		 */
		apiKey: apiPath("auth", "api-key"),

		/**
		 * Relative API path: `auth/api-key/{id}`.
		 *
		 * Deletes one dashboard API key by ID.
		 */
		apiKeyById: (id: ApiPathPart) =>
			apiPath("auth", "api-key", encodeApiParam(id)),

		/**
		 * Relative API path: `auth/forgot-password`.
		 *
		 * Starts the password-reset email flow.
		 */
		forgotPassword: apiPath("auth", "forgot-password"),

		/**
		 * Relative API path: `auth/login`.
		 *
		 * Signs in with username/email and password.
		 */
		login: apiPath("auth", "login"),

		/**
		 * Relative API path: `auth/login/verify`.
		 *
		 * Completes a two-factor login challenge.
		 */
		loginVerify: apiPath("auth", "login", "verify"),

		/**
		 * Relative API path: `auth/logout`.
		 *
		 * Revokes the active dashboard session.
		 */
		logout: apiPath("auth", "logout"),

		/**
		 * Relative API path: `auth/register`.
		 *
		 * Creates the initial user account where registration is available.
		 */
		register: apiPath("auth", "register"),

		/**
		 * Relative API path: `auth/resend-verification`.
		 *
		 * Sends another email-verification message.
		 */
		resendVerification: apiPath("auth", "resend-verification"),

		/**
		 * Relative API path: `auth/reset-password/{token}`.
		 *
		 * Validates or submits a password-reset token.
		 */
		resetPassword: (token: ApiPathPart) =>
			apiPath("auth", "reset-password", encodeApiParam(token)),

		/**
		 * Relative API path: `auth/security`.
		 *
		 * Loads the current user's security settings.
		 */
		security: apiPath("auth", "security"),

		/**
		 * Relative API path: `auth/security/backup-codes/download`.
		 *
		 * Downloads two-factor backup codes as a plain-text file.
		 */
		securityBackupCodesDownload: apiPath(
			"auth",
			"security",
			"backup-codes",
			"download"
		),

		/**
		 * Relative API path: `auth/security/sessions`.
		 *
		 * Revokes all other active sessions for the current user.
		 */
		securitySessions: apiPath("auth", "security", "sessions"),

		/**
		 * Relative API path: `auth/security/sessions/{id}`.
		 *
		 * Revokes one active session by ID.
		 */
		securitySession: (id: ApiPathPart) =>
			apiPath("auth", "security", "sessions", encodeApiParam(id)),

		/**
		 * Relative API path: `auth/security/two-factor/backup-codes`.
		 *
		 * Regenerates two-factor backup codes.
		 */
		twoFactorBackupCodes: apiPath(
			"auth",
			"security",
			"two-factor",
			"backup-codes"
		),

		/**
		 * Relative API path: `auth/security/two-factor/disable`.
		 *
		 * Disables two-factor authentication for the current user.
		 */
		twoFactorDisable: apiPath("auth", "security", "two-factor", "disable"),

		/**
		 * Relative API path: `auth/security/two-factor/setup`.
		 *
		 * Begins two-factor setup and returns setup details.
		 */
		twoFactorSetup: apiPath("auth", "security", "two-factor", "setup"),

		/**
		 * Relative API path: `auth/security/two-factor/verify`.
		 *
		 * Verifies a TOTP code and enables two-factor authentication.
		 */
		twoFactorVerify: apiPath("auth", "security", "two-factor", "verify"),

		/**
		 * Relative API path: `auth/verify-email`.
		 *
		 * Verifies an email-verification token.
		 */
		verifyEmail: apiPath("auth", "verify-email"),
	},

	/**
	 * Site settings, admin notices, diagnostics, mail, GeoIP, and updates.
	 */
	system: {
		/**
		 * Relative API path: `system/cache`.
		 *
		 * Loads or saves cache and performance configuration.
		 */
		cache: apiPath("system", "cache"),

		/**
		 * Relative API path: `system/cache/clear`.
		 *
		 * Clears all cached objects and empty cache files.
		 */
		cacheClear: apiPath("system", "cache", "clear"),

		/**
		 * Relative API path: `system/captcha`.
		 *
		 * Loads or saves CAPTCHA provider settings.
		 */
		captcha: apiPath("system", "captcha"),

		/**
		 * Relative API path: `system/general`.
		 *
		 * Loads or saves general site settings.
		 */
		general: apiPath("system", "general"),

		/**
		 * Relative API path: `system/geoip`.
		 *
		 * Loads or saves GeoIP and MaxMind settings.
		 */
		geoip: apiPath("system", "geoip"),

		/**
		 * Relative API path: `system/geoip/download`.
		 *
		 * Downloads or refreshes the GeoLite2 database.
		 */
		geoipDownload: apiPath("system", "geoip", "download"),

		/**
		 * Relative API path: `system/i18n`.
		 *
		 * Returns dashboard data and the translation catalog fallback.
		 */
		i18n: apiPath("system", "i18n"),

		/**
		 * Relative API path: `system/mail`.
		 *
		 * Loads or saves mail transport configuration.
		 */
		mail: apiPath("system", "mail"),

		/**
		 * Relative API path: `system/mail/test`.
		 *
		 * Sends a mail transport test message.
		 */
		mailTest: apiPath("system", "mail", "test"),

		/**
		 * Relative API path: `system/notices`.
		 *
		 * Loads dashboard admin notices.
		 */
		notices: apiPath("system", "notices"),

		/**
		 * Relative API path: `system/status`.
		 *
		 * Loads system status and diagnostics.
		 */
		status: apiPath("system", "status"),

		/**
		 * Relative API path: `system/update`.
		 *
		 * Loads cached updater status.
		 */
		update: apiPath("system", "update"),

		/**
		 * Relative API path: `system/update/apply`.
		 *
		 * Applies a trusted available update.
		 */
		updateApply: apiPath("system", "update", "apply"),

		/**
		 * Relative API path: `system/update/check`.
		 *
		 * Refreshes update availability from the manifest.
		 */
		updateCheck: apiPath("system", "update", "check"),

		/**
		 * Relative API path: `system/update/database`.
		 *
		 * Runs pending database schema upgrades.
		 */
		updateDatabase: apiPath("system", "update", "database"),

		/**
		 * Relative API path: `system/update/reinstall`.
		 *
		 * Reinstalls the current release package.
		 */
		updateReinstall: apiPath("system", "update", "reinstall"),
	},

	/**
	 * Short-link CRUD, bulk import/delete, and export endpoints.
	 */
	urls: {
		/**
		 * Relative API path: `urls/bulk`.
		 *
		 * Bulk creates or deletes short links.
		 */
		bulk: apiPath("urls", "bulk"),

		/**
		 * Relative API path: `urls/restore`.
		 *
		 * Bulk restores trashed short links.
		 */
		bulkRestore: apiPath("urls", "restore"),

		/**
		 * Relative API path: `urls/{id}`.
		 *
		 * Reads, updates, or deletes one short link by ID.
		 */
		byId: (id: ApiPathPart) => apiPath("urls", encodeApiParam(id)),

		/**
		 * Relative API path: `urls/export`.
		 *
		 * Exports accessible short links.
		 */
		export: apiPath("urls", "export"),

		/**
		 * Relative API path: `urls`.
		 *
		 * Lists or creates short links.
		 */
		index: apiPath("urls"),

		/**
		 * Relative API path: `urls/{id}/restore`.
		 *
		 * Restores one trashed short link by ID.
		 */
		restore: (id: ApiPathPart) =>
			apiPath("urls", encodeApiParam(id), "restore"),

		/**
		 * Relative API path: `urls/trash`.
		 *
		 * Permanently empties all trashed short links.
		 */
		trash: apiPath("urls", "trash"),
	},

	/**
	 * User-management and current-session profile endpoints.
	 */
	users: {
		/**
		 * Relative API path: `users/{username}`.
		 *
		 * Updates or deletes one managed user by username.
		 */
		byUsername: (username: ApiPathPart) =>
			apiPath("users", encodeApiParam(username)),

		/**
		 * Relative API path: `users`.
		 *
		 * Lists or creates managed users.
		 */
		index: apiPath("users"),

		/**
		 * Relative API path: `users/me`.
		 *
		 * Loads or updates the current user's profile.
		 */
		me: apiPath("users", "me"),
	},

	/**
	 * Webhook integration endpoints.
	 */
	webhooks: {
		/**
		 * Relative API path: `webhooks/{id}`.
		 *
		 * Deletes one webhook by ID.
		 */
		byId: (id: ApiPathPart) => apiPath("webhooks", encodeApiParam(id)),

		/**
		 * Relative API path: `webhooks`.
		 *
		 * Lists or creates webhook registrations.
		 */
		index: apiPath("webhooks"),
	},
} as const;
