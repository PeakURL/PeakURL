/**
 * Summary metadata for one user-owned API key.
 */
export interface ApiKeySummary {
	id: string;
	label?: string | null;
	maskedKey?: string | null;
	createdAt?: string | null;
}

/**
 * Raw capability flags returned by the backend HTTP JSON API (snake_case).
 */
export interface ApiUserCapabilities {
	manage_users?: boolean | null;
	manage_site_settings?: boolean | null;
	manage_mail_delivery?: boolean | null;
	manage_location_data?: boolean | null;
	manage_performance?: boolean | null;
	manage_updates?: boolean | null;
	manage_profile?: boolean | null;
	manage_api_keys?: boolean | null;
	manage_webhooks?: boolean | null;
	view_links?: boolean | null;
	edit_links?: boolean | null;
	trash_links?: boolean | null;
	delete_links?: boolean | null;
	empty_trash?: boolean | null;
	view_analytics?: boolean | null;
	create_links?: boolean | null;
}

/**
 * Normalized user capability flags for internal application usage (camelCase).
 */
export interface UserCapabilities {
	manageUsers: boolean;
	manageSiteSettings: boolean;
	manageMailDelivery: boolean;
	manageLocationData: boolean;
	managePerformance: boolean;
	manageUpdates: boolean;
	manageProfile: boolean;
	manageApiKeys: boolean;
	manageWebhooks: boolean;
	viewLinks: boolean;
	editLinks: boolean;
	trashLinks: boolean;
	deleteLinks: boolean;
	emptyTrash: boolean;
	viewAnalytics: boolean;
	createLinks: boolean;
}

/**
 * Alias for UserCapabilities to preserve compatibility with existing imports.
 */
export type ProfileUserCapabilities = UserCapabilities;

/**
 * Raw user profile payload returned by the user and auth API endpoints.
 */
export interface ApiProfileUser {
	_id?: string | null;
	id?: string | null;
	username?: string | null;
	email?: string | null;
	firstName?: string | null;
	lastName?: string | null;
	displayName?: string | null;
	phoneNumber?: string | null;
	company?: string | null;
	jobTitle?: string | null;
	bio?: string | null;
	role?: string | null;
	updatedAt?: string | null;
	baseApiUrl?: string | null;
	siteUrl?: string | null;
	apiKeys?: ApiKeySummary[] | null;
	capabilities?: ApiUserCapabilities | null;
}

/**
 * Current dashboard user profile returned by user/auth endpoints (normalized).
 */
export interface ProfileUser extends Omit<ApiProfileUser, "capabilities"> {
	capabilities?: UserCapabilities | null;
}

/**
 * Optional GeoIP details for an active dashboard session.
 */
export interface SecuritySessionLocation {
	city?: string | null;
	country?: string | null;
	countryCode?: string | null;
	isPublic?: boolean | null;
}

/**
 * Active or revoked dashboard session shown in security settings.
 */
export interface SecuritySession {
	id: string;
	device?: string | null;
	browser?: string | null;
	os?: string | null;
	ipAddress?: string | null;
	location?: SecuritySessionLocation | null;
	lastActiveAt?: string | null;
	isCurrent?: boolean;
	revokedAt?: string | null;
}

/**
 * Security settings data for the authenticated user.
 */
export interface SecuritySettingsPayload {
	sessions?: SecuritySession[];
	twoFactorEnabled?: boolean;
	hasPendingSetup?: boolean;
	backupCodesRemaining?: number;
	backupCodesLastGeneratedAt?: string | null;
}

/**
 * Endpoint response returned by the security settings route.
 */
export interface SecuritySettingsResponse {
	data?: SecuritySettingsPayload;
}

/**
 * Endpoint response returned when starting two-factor setup.
 */
export interface TwoFactorSetupResponse {
	data?: {
		secret?: string | null;
		otpauthUrl?: string | null;
		qrDataUrl?: string | null;
	};
}

/**
 * Endpoint response returned when backup codes are generated.
 */
export interface BackupCodesResponse {
	data?: {
		backupCodes?: string[];
	};
}

/**
 * Endpoint response returned after revoking other sessions.
 */
export interface RevokeOtherSessionsResponse {
	data?: {
		revokedCount?: number;
	};
}

/**
 * Canonical roles supported by the self-hosted dashboard.
 */
export type UserRole = "admin" | "editor";

/**
 * User row returned by the user-management endpoint.
 */
export interface UserSummary {
	id: string;
	firstName?: string | null;
	lastName?: string | null;
	displayName?: string | null;
	username?: string | null;
	email?: string | null;
	role?: UserRole | null;
	createdAt?: string | null;
}

/**
 * Request body used to create or update a managed user.
 */
export interface UserDialogPayload {
	firstName: string;
	lastName: string;
	displayName?: string;
	username: string;
	email: string;
	role: UserRole;
	password?: string;
}
