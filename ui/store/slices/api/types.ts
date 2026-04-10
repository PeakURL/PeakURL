import type {
	CreatedWebhook,
	WebhookSummary,
} from '@/components/settings/types';
import type {
	CountryMetric,
	DashboardDeviceData,
	DashboardStats,
	RecentActivity,
	TrafficSeries,
} from '@/pages/dashboard/_components/types';
import type {
	BulkCreateResponse,
	PasteImportRequestItem,
} from '@/pages/dashboard/tools/import/_components/types';
import type { ImportRecord } from '@/pages/dashboard/tools/import/_components/file-upload/types';
import type {
	LinkLocationPayload,
	LinkStatsResponse,
} from '@/pages/dashboard/links/_components/StatsDrawer/types';
import type {
	LinkRecord,
	LinksSortBy,
	LinksSortOrder,
} from '@/pages/dashboard/links/_components/types';
import type { UpdateUrlPayload } from '@/pages/dashboard/links/_components/types';
import type {
	CreateUrlPayload,
	CreateUrlResponse,
} from '@/pages/dashboard/links/_components/UrlShorteningForm/types';
import type { GetUrlsResponse } from '@/pages/dashboard/links/types';
import type {
	GeoipConfigurationPayload,
	MailConfigurationPayload,
	ProfileUser,
} from '@/pages/dashboard/settings/_components/Content/types';
import type {
	BackupCodesResponse,
	EmailStatus,
	LocationDataStatus,
	RevokeOtherSessionsResponse,
	SecuritySettingsResponse,
	SiteSettings,
	TwoFactorSetupResponse,
	UpdateStatusPayload,
} from '@/pages/dashboard/settings/_components/Content/sections/types';
import type {
	SystemStatusResponse,
	UrlExportResponse,
} from '@/pages/dashboard/tools/types';
import type {
	UserDialogPayload,
	UserSummary,
} from '@/pages/dashboard/users/types';
import type { AdminNoticesResponse } from '@/pages/layout/dashboard/types';

/**
 * Cache tag names shared by the dashboard RTK Query API slices.
 */
export type ApiTagType =
	| 'AuthSession'
	| 'Urls'
	| 'Analytics'
	| 'Profile'
	| 'Users'
	| 'Webhooks'
	| 'Security'
	| 'Geoip'
	| 'Mail'
	| 'Updates'
	| 'GeneralSettings'
	| 'SystemStatus'
	| 'AdminNotices';

/**
 * Ordered list of RTK Query cache tags registered on the base API instance.
 *
 * Keeping the literal tag list in one shared export makes the domain slices
 * easier to scan and prevents small string drift between cache providers and
 * invalidators during future endpoint additions.
 */
export const API_TAG_TYPES: ApiTagType[] = [
	'AuthSession',
	'Urls',
	'Analytics',
	'Profile',
	'Users',
	'Webhooks',
	'Security',
	'Geoip',
	'Mail',
	'Updates',
	'GeneralSettings',
	'SystemStatus',
	'AdminNotices',
];

/**
 * Generic `data` wrapper used by many dashboard API endpoints.
 */
export interface ApiDataResponse<T> {
	/** Endpoint payload returned under the canonical `data` key. */
	data?: T;
}

/**
 * Analytics payload returned for the dashboard overview page.
 */
export interface DashboardAnalyticsPayload extends DashboardStats {
	/** Time-series traffic data used by the overview chart. */
	traffic?: Partial<TrafficSeries> | null;

	/** Device totals grouped by form factor. */
	devices?: DashboardDeviceData['devices'];

	/** Browser totals grouped by browser family. */
	browsers?: DashboardDeviceData['browsers'];

	/** Operating-system totals grouped by OS family. */
	operatingSystems?: DashboardDeviceData['operatingSystems'];

	/** Country-level click counts used by the map and cards. */
	countries?: CountryMetric[];
}

/**
 * Response wrapper returned by the dashboard analytics endpoint.
 */
export interface DashboardAnalyticsResponse {
	/** Aggregated analytics payload for the selected date range. */
	data?: DashboardAnalyticsPayload;
}

/**
 * Response wrapper returned by the recent activity endpoint.
 */
export interface ActivityResponse {
	/** Activity feed items ordered from newest to oldest. */
	data?: RecentActivity[];
}

/**
 * Arguments accepted by the link-specific analytics endpoints.
 */
export interface LinkAnalyticsArgs {
	/** Stable link identifier used by the analytics route. */
	id: string;

	/** Optional time range in days for the query. */
	days?: number;
}

/**
 * Query arguments accepted by the links list endpoint.
 */
export interface GetUrlsQueryArgs {
	/** Page number to request. */
	page?: number;

	/** Number of records to request per page. */
	limit?: number;

	/** Sort field applied by the API. */
	sortBy?: LinksSortBy | 'createdAt' | 'updatedAt';

	/** Sort direction applied by the API. */
	sortOrder?: LinksSortOrder;

	/** Optional search term forwarded to the API. */
	search?: string;
}

/**
 * Query arguments accepted by the links export lookup endpoint.
 */
export interface GetUrlsExportQueryArgs {
	/** Sort field applied before exporting records. */
	sortBy?: LinksSortBy | 'createdAt' | 'updatedAt';

	/** Sort direction applied before exporting records. */
	sortOrder?: LinksSortOrder;

	/** Optional search term forwarded to the export route. */
	search?: string;
}

/**
 * Compatibility response used by the links list endpoint.
 *
 * Some older code paths still surface `items` at the top level, so the query
 * layer keeps that legacy field typed while favoring the canonical `data`
 * wrapper returned by the current API contract.
 */
export interface UrlsListResponse extends GetUrlsResponse {
	/** Legacy top-level item collection preserved for compatibility. */
	items?: LinkRecord[];
}

/**
 * Response wrapper returned by the single-link lookup endpoint.
 */
export type UrlResponse = ApiDataResponse<LinkRecord>;

/**
 * Request payload accepted by the bulk-create links endpoint.
 */
export interface BulkCreateUrlsPayload {
	/** Link records parsed from file or pasted import sources. */
	urls: Array<ImportRecord | PasteImportRequestItem>;
}

/**
 * Login request payload shared by password and 2FA verification steps.
 */
export interface CredentialLoginPayload {
	/** Username or email entered by the user. */
	identifier: string;

	/** Plain-text password entered by the user. */
	password: string;

	/** Optional TOTP or backup code used for two-factor verification. */
	token?: string;
}

/**
 * Session-check response returned by `/users/me`.
 *
 * PeakURL currently exposes the authenticated user in `data`, with a
 * compatibility `user` field still handled by a few older call sites. The UI
 * treats `data` as canonical and only falls back to `user` in the remaining
 * auth-guard surfaces that still accommodate the older shape.
 */
export interface AuthCheckResponse {
	/** Canonical authenticated user payload. */
	data?: ProfileUser;

	/** Compatibility user payload used by older auth flows. */
	user?: ProfileUser;
}

/**
 * Authentication response returned by login and 2FA verification routes.
 *
 * The API currently mirrors some auth flags at both the top level and under
 * `data`, so this interface preserves that compatibility while documenting the
 * structure explicitly for the login screens.
 */
export interface LoginResponse {
	/** Compatibility flag indicating whether 2FA is still required. */
	requiresTwoFactor?: boolean;

	/** Auth payload returned by the API. */
	data?: {
		/** Authenticated user when login succeeds. */
		user?: ProfileUser;

		/**
		 * Flag indicating that a second-factor code is required before the
		 * session is considered authenticated.
		 */
		requiresTwoFactor?: boolean;
	};
}

/**
 * Logout response returned when the current session is revoked.
 *
 * A successful response also triggers cookie expiration headers; the dashboard
 * only needs the lightweight payload below to confirm the action in state.
 */
export interface LogoutResponse {
	/** Logout payload returned by the API. */
	data?: {
		/** Whether the backend successfully ended the session. */
		loggedOut?: boolean;
	};
}

/**
 * Request payload used by the forgot-password form.
 */
export interface ForgotPasswordPayload {
	/** Email address or username entered on the recovery form. */
	identifier: string;
}

/**
 * Request payload used by the reset-password form.
 */
export interface ResetPasswordPayload {
	/** Password-reset token embedded in the URL route. */
	token: string;

	/** New plain-text password chosen by the user. */
	password: string;
}

/**
 * Request payload used when updating an existing user record.
 */
export interface UpdateUserPayload extends UserDialogPayload {
	/** Existing username used to resolve the update route. */
	currentUsername?: string;
}

/**
 * Request payload used when creating a one-time API key.
 */
export interface GenerateApiKeyPayload {
	/** Human-readable label shown beside the API key summary. */
	label: string;
}

/**
 * Response returned when creating a new one-time API key.
 *
 * The plain-text key is intentionally available only during creation, so the
 * response shape keeps that one-time token and the recommended base API URL
 * together for the settings modal.
 */
export interface GenerateApiKeyResponse {
	/** One-time API key payload shown immediately after creation. */
	data?: {
		/** Plain-text API key shown once to the user. */
		apiKey?: string | null;

		/** Base API URL recommended for the generated key. */
		baseApiUrl?: string | null;
	};
}

/**
 * Password-confirmed payload used by protected security actions.
 */
export interface CurrentPasswordPayload {
	/** Current account password used to confirm the action. */
	currentPassword: string;
}

/**
 * Two-factor verification payload submitted from the authenticator step.
 */
export interface VerifyTwoFactorPayload {
	/** Six-digit verification token entered by the user. */
	token: string;
}

/**
 * Request payload used when creating an outbound webhook.
 */
export interface CreateWebhookPayload {
	/** Destination endpoint URL. */
	url: string;

	/** Event identifiers subscribed by the webhook. */
	events: string[];
}

/**
 * Save payload used by the general-settings API route.
 */
export interface SaveGeneralSettingsPayload {
	/** Locale code for the selected dashboard language. */
	siteLanguage: string;
}

/**
 * Response returned by the updater database-repair endpoint.
 *
 * The updater may complete successfully while still reporting outstanding
 * issues, so the response carries a count instead of a simple boolean.
 */
export interface UpgradeDatabaseResponse {
	/** Database repair metadata returned by the updater. */
	data?: {
		/** Number of remaining database issues detected after repair. */
		issuesCount?: number | string | null;
	};
}

export type {
	AdminNoticesResponse,
	BackupCodesResponse,
	BulkCreateResponse,
	CreateUrlPayload,
	CreateUrlResponse,
	CreatedWebhook,
	EmailStatus,
	GeoipConfigurationPayload,
	LinkLocationPayload,
	LinkStatsResponse,
	LocationDataStatus,
	MailConfigurationPayload,
	RevokeOtherSessionsResponse,
	SecuritySettingsResponse,
	SiteSettings,
	SystemStatusResponse,
	TwoFactorSetupResponse,
	UpdateStatusPayload,
	UpdateUrlPayload,
	UrlExportResponse,
	UserDialogPayload,
	UserSummary,
	WebhookSummary,
};
