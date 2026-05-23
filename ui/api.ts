/**
 * Dashboard API route helpers.
 *
 * PHP owns the public API base (`/api/v1`) and injects it through
 * `window.__PEAKURL__.apiBase`. This module owns only the path after that
 * base, so RTK Query slices and direct fetches share one route map.
 */

type ApiRoutePart = string | number;

/**
 * Encode one dynamic API route parameter before placing it in a path.
 *
 * Use this for IDs, tokens, usernames, or any value that may contain
 * characters with special meaning in a URL path.
 */
function encodeApiRouteParam(value: ApiRoutePart): string {
	return encodeURIComponent(String(value));
}

/**
 * Join API path parts without leading or trailing slashes.
 *
 * Routes intentionally stay relative to `API_CLIENT_BASE_URL`, which keeps
 * release installs, subdirectory installs, and Vite/dev mode on the same API
 * contract.
 */
function apiRoutePath(...parts: ApiRoutePart[]): string {
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
		activity: apiRoutePath("analytics", "activity"),

		/**
		 * Relative API path: `analytics/activity/{id}`.
		 *
		 * Deletes one activity-log row by ID.
		 */
		activityById: (id: ApiRoutePart) =>
			apiRoutePath("analytics", "activity", encodeApiRouteParam(id)),

		/**
		 * Relative API path: `analytics/activity/bulk`.
		 *
		 * Deletes multiple activity-log rows in one request.
		 */
		activityBulk: apiRoutePath("analytics", "activity", "bulk"),

		/**
		 * Relative API path: `analytics/activity/history`.
		 *
		 * Loads paginated dashboard activity history.
		 */
		activityHistory: apiRoutePath("analytics", "activity", "history"),

		/**
		 * Relative API path: `analytics`.
		 *
		 * Loads the dashboard analytics summary.
		 */
		index: apiRoutePath("analytics"),

		/**
		 * Relative API path: `analytics/url/{id}/location`.
		 *
		 * Loads GeoIP analytics for one short link.
		 */
		linkLocation: (id: ApiRoutePart) =>
			apiRoutePath("analytics", "url", encodeApiRouteParam(id), "location"),

		/**
		 * Relative API path: `analytics/url/{id}/stats`.
		 *
		 * Loads click statistics for one short link.
		 */
		linkStats: (id: ApiRoutePart) =>
			apiRoutePath("analytics", "url", encodeApiRouteParam(id), "stats"),

		/**
		 * Relative API path: `analytics/recent-clicks`.
		 *
		 * Loads the recent clicks feed.
		 */
		recentClicks: apiRoutePath("analytics", "recent-clicks"),
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
		apiKey: apiRoutePath("auth", "api-key"),

		/**
		 * Relative API path: `auth/api-key/{id}`.
		 *
		 * Deletes one dashboard API key by ID.
		 */
		apiKeyById: (id: ApiRoutePart) =>
			apiRoutePath("auth", "api-key", encodeApiRouteParam(id)),

		/**
		 * Relative API path: `auth/forgot-password`.
		 *
		 * Starts the password-reset email flow.
		 */
		forgotPassword: apiRoutePath("auth", "forgot-password"),

		/**
		 * Relative API path: `auth/login`.
		 *
		 * Signs in with username/email and password.
		 */
		login: apiRoutePath("auth", "login"),

		/**
		 * Relative API path: `auth/login/verify`.
		 *
		 * Completes a two-factor login challenge.
		 */
		loginVerify: apiRoutePath("auth", "login", "verify"),

		/**
		 * Relative API path: `auth/logout`.
		 *
		 * Revokes the active dashboard session.
		 */
		logout: apiRoutePath("auth", "logout"),

		/**
		 * Relative API path: `auth/register`.
		 *
		 * Creates the initial user account where registration is available.
		 */
		register: apiRoutePath("auth", "register"),

		/**
		 * Relative API path: `auth/resend-verification`.
		 *
		 * Sends another email-verification message.
		 */
		resendVerification: apiRoutePath("auth", "resend-verification"),

		/**
		 * Relative API path: `auth/reset-password/{token}`.
		 *
		 * Validates or submits a password-reset token.
		 */
		resetPassword: (token: ApiRoutePart) =>
			apiRoutePath("auth", "reset-password", encodeApiRouteParam(token)),

		/**
		 * Relative API path: `auth/security`.
		 *
		 * Loads the current user's security settings.
		 */
		security: apiRoutePath("auth", "security"),

		/**
		 * Relative API path: `auth/security/backup-codes/download`.
		 *
		 * Downloads two-factor backup codes as a plain-text file.
		 */
		securityBackupCodesDownload: apiRoutePath(
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
		securitySessions: apiRoutePath("auth", "security", "sessions"),

		/**
		 * Relative API path: `auth/security/sessions/{id}`.
		 *
		 * Revokes one active session by ID.
		 */
		securitySession: (id: ApiRoutePart) =>
			apiRoutePath("auth", "security", "sessions", encodeApiRouteParam(id)),

		/**
		 * Relative API path: `auth/security/two-factor/backup-codes`.
		 *
		 * Regenerates two-factor backup codes.
		 */
		twoFactorBackupCodes: apiRoutePath(
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
		twoFactorDisable: apiRoutePath(
			"auth",
			"security",
			"two-factor",
			"disable"
		),

		/**
		 * Relative API path: `auth/security/two-factor/setup`.
		 *
		 * Begins two-factor setup and returns setup details.
		 */
		twoFactorSetup: apiRoutePath(
			"auth",
			"security",
			"two-factor",
			"setup"
		),

		/**
		 * Relative API path: `auth/security/two-factor/verify`.
		 *
		 * Verifies a TOTP code and enables two-factor authentication.
		 */
		twoFactorVerify: apiRoutePath(
			"auth",
			"security",
			"two-factor",
			"verify"
		),

		/**
		 * Relative API path: `auth/verify-email`.
		 *
		 * Verifies an email-verification token.
		 */
		verifyEmail: apiRoutePath("auth", "verify-email"),
	},

	/**
	 * Site settings, admin notices, diagnostics, mail, GeoIP, and updates.
	 */
	system: {
		/**
		 * Relative API path: `system/captcha`.
		 *
		 * Loads or saves CAPTCHA provider settings.
		 */
		captcha: apiRoutePath("system", "captcha"),

		/**
		 * Relative API path: `system/general`.
		 *
		 * Loads or saves general site settings.
		 */
		general: apiRoutePath("system", "general"),

		/**
		 * Relative API path: `system/geoip`.
		 *
		 * Loads or saves GeoIP and MaxMind settings.
		 */
		geoip: apiRoutePath("system", "geoip"),

		/**
		 * Relative API path: `system/geoip/download`.
		 *
		 * Downloads or refreshes the GeoLite2 database.
		 */
		geoipDownload: apiRoutePath("system", "geoip", "download"),

		/**
		 * Relative API path: `system/i18n`.
		 *
		 * Returns dashboard data and the translation catalog fallback.
		 */
		i18n: apiRoutePath("system", "i18n"),

		/**
		 * Relative API path: `system/mail`.
		 *
		 * Loads or saves mail transport configuration.
		 */
		mail: apiRoutePath("system", "mail"),

		/**
		 * Relative API path: `system/mail/test`.
		 *
		 * Sends a mail transport test message.
		 */
		mailTest: apiRoutePath("system", "mail", "test"),

		/**
		 * Relative API path: `system/notices`.
		 *
		 * Loads dashboard admin notices.
		 */
		notices: apiRoutePath("system", "notices"),

		/**
		 * Relative API path: `system/status`.
		 *
		 * Loads system status and diagnostics.
		 */
		status: apiRoutePath("system", "status"),

		/**
		 * Relative API path: `system/update`.
		 *
		 * Loads cached updater status.
		 */
		update: apiRoutePath("system", "update"),

		/**
		 * Relative API path: `system/update/apply`.
		 *
		 * Applies a trusted available update.
		 */
		updateApply: apiRoutePath("system", "update", "apply"),

		/**
		 * Relative API path: `system/update/check`.
		 *
		 * Refreshes update availability from the manifest.
		 */
		updateCheck: apiRoutePath("system", "update", "check"),

		/**
		 * Relative API path: `system/update/database`.
		 *
		 * Runs pending database schema upgrades.
		 */
		updateDatabase: apiRoutePath("system", "update", "database"),

		/**
		 * Relative API path: `system/update/reinstall`.
		 *
		 * Reinstalls the current release package.
		 */
		updateReinstall: apiRoutePath("system", "update", "reinstall"),
	},

	/**
	 * Short-link CRUD, bulk import/delete, and export endpoints.
	 */
	urls: {
		/**
		 * Relative API path: `urls/bulk`.
		 *
		 * Imports or deletes multiple short links in one request.
		 */
		bulk: apiRoutePath("urls", "bulk"),

		/**
		 * Relative API path: `urls/{id}`.
		 *
		 * Reads, updates, or deletes one short link by ID.
		 */
		byId: (id: ApiRoutePart) =>
			apiRoutePath("urls", encodeApiRouteParam(id)),

		/**
		 * Relative API path: `urls/export`.
		 *
		 * Exports accessible short links.
		 */
		export: apiRoutePath("urls", "export"),

		/**
		 * Relative API path: `urls`.
		 *
		 * Lists or creates short links.
		 */
		index: apiRoutePath("urls"),
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
		byUsername: (username: ApiRoutePart) =>
			apiRoutePath("users", encodeApiRouteParam(username)),

		/**
		 * Relative API path: `users`.
		 *
		 * Lists or creates managed users.
		 */
		index: apiRoutePath("users"),

		/**
		 * Relative API path: `users/me`.
		 *
		 * Loads or updates the current user's profile.
		 */
		me: apiRoutePath("users", "me"),
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
		byId: (id: ApiRoutePart) =>
			apiRoutePath("webhooks", encodeApiRouteParam(id)),

		/**
		 * Relative API path: `webhooks`.
		 *
		 * Lists or creates webhook registrations.
		 */
		index: apiRoutePath("webhooks"),
	},
} as const;
