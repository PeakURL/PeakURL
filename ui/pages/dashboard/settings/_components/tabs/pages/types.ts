import type { LucideIcon } from "lucide-react";
import type { ButtonVariant, NotificationContextValue } from "@/components";
import type { InstalledLanguage, TextDirection } from "@/i18n/types";
import type {
	ApiKeySummary,
	GeneralFormPayload,
	GeneralFormState,
	MailDriver,
	SmtpEncryption,
	SiteTimeFormat,
} from "../types";

/**
 * Configured site favicon metadata shown in the General settings tab.
 */
export interface SiteFavicon {
	configured?: boolean;
	isCustom?: boolean;
	url?: string | null;
	iconUrl?: string | null;
	appleTouchUrl?: string | null;
	manifestUrl?: string | null;
	mimeType?: string | null;
	width?: number | string | null;
	height?: number | string | null;
	sizes?: string | null;
	updatedAt?: string | null;
	recommendedSize?: string | null;
}

/**
 * Site-level settings required by the general settings tab.
 */
export interface SiteSettings {
	siteName?: string | null;
	siteUrl?: string | null;
	siteLanguage?: string | null;
	siteTimezone?: string | null;
	siteTimeFormat?: SiteTimeFormat | null;
	textDirection?: TextDirection;
	isRtl?: boolean;
	canManageSiteSettings?: boolean;
	availableLanguages?: InstalledLanguage[];
	favicon?: SiteFavicon | null;
}

/**
 * Props for the general settings tab.
 */
export interface GeneralTabProps {
	initialForm: GeneralFormState;
	username?: string | null;
	onSubmit: (payload: GeneralFormPayload) => void;
	isUpdating: boolean;
	siteSettings?: SiteSettings | null;
	isLoadingSiteSettings: boolean;
}

/**
 * Subset of the user payload required by the API tab.
 */
export interface ApiUser {
	apiKeys?: ApiKeySummary[] | null;
}

/**
 * Props for the API keys and API docs tab.
 */
export interface ApiTabProps {
	user?: ApiUser | null;
	baseApiUrl?: string | null;
	copyToClipboard: (
		value: string,
		successMessage?: string
	) => void | Promise<void>;
	isGeneratingKey: boolean;
	isDeletingKey: boolean;
	onDeleteKey: (key: ApiKeySummary) => void | Promise<void>;
	setShowCreateModal: (open: boolean) => void;
}

/**
 * Props for the API key creation and reveal modals.
 */
export interface ApiKeyModalsProps {
	showCreateModal: boolean;
	setShowCreateModal: (open: boolean) => void;
	showKeyModal: boolean;
	setShowKeyModal: (open: boolean) => void;
	keyLabel: string;
	setKeyLabel: (value: string) => void;
	newApiKey: string;
	baseApiUrl?: string | null;
	onCreateKey: () => void | Promise<void>;
	copyToClipboard: (
		value: string,
		successMessage?: string
	) => void | Promise<void>;
	isGeneratingKey: boolean;
}

/**
 * Current mail delivery status returned by the email configuration API.
 */
export interface EmailStatus {
	driver?: MailDriver | null;
	configuredFromEmail?: string | null;
	configuredFromName?: string | null;
	fromName?: string | null;
	smtpHost?: string | null;
	smtpPort?: number | string | null;
	smtpEncryption?: SmtpEncryption | null;
	smtpAuth?: boolean | null;
	smtpUsername?: string | null;
	smtpPasswordConfigured?: boolean;
	smtpPasswordHint?: string | null;
	canManageFromDashboard?: boolean;
	manageDisabledReason?: string | null;
	canSendTestEmail?: boolean;
	testDisabledReason?: string | null;
}

/**
 * Editable email delivery form state.
 */
export interface EmailFormState {
	driver: MailDriver;
	fromEmail: string;
	fromName: string;
	smtpHost: string;
	smtpPort: string;
	smtpEncryption: SmtpEncryption;
	smtpAuth: boolean;
	smtpUsername: string;
	smtpPassword: string;
}

/**
 * Props for the email delivery settings tab.
 */
export interface EmailDeliveryTabProps {
	status?: EmailStatus | null;
	errorMessage?: string | null;
	isLoading: boolean;
	isSaving: boolean;
	isTesting: boolean;
	onSave: (payload: EmailFormState) => Promise<unknown> | unknown;
	onSendTest: () => Promise<unknown> | unknown;
}

/**
 * Props for a selectable mail delivery method card.
 */
export interface MethodButtonProps {
	isActive: boolean;
	title: string;
	description: string;
	onClick: () => void;
}

/**
 * GeoLite2 status and credential metadata shown in the location data tab.
 */
export interface LocationDataStatus {
	locationAnalyticsReady?: boolean;
	lastDownloadedAt?: string | null;
	databaseUpdatedAt?: string | null;
	databaseSizeBytes?: number | string | null;
	credentialsConfigured?: boolean;
	accountId?: string | null;
	configurationLabel?: string | null;
	canManageFromDashboard?: boolean;
	manageDisabledReason?: string | null;
	licenseKeyHint?: string | null;
}

/**
 * Credentials payload submitted by the location data form.
 */
export interface LocationDataSavePayload {
	accountId: string;
	licenseKey: string;
}

/**
 * Props for the location data management tab.
 */
export interface LocationDataTabProps {
	status?: LocationDataStatus | null;
	errorMessage?: string | null;
	isLoading: boolean;
	isSaving: boolean;
	isDownloading: boolean;
	onSave: (
		payload: LocationDataSavePayload
	) => Promise<Partial<LocationDataStatus> | void> | void;
	onDownload: () => void | Promise<void>;
}

/**
 * Visual variants used by location-data state cards.
 */
export type StateCardVariant = "info" | "success" | "error";

/**
 * Props for a location-data state card.
 */
export interface StateCardProps {
	icon: LucideIcon;
	title: string;
	description?: string | null;
	variant?: StateCardVariant;
}

/**
 * Props for a small metric card in the location-data tab.
 */
export interface StatCardProps {
	label: string;
	value: string;
	valueDirection?: "auto" | "ltr" | "rtl";
}

/**
 * Sensitive 2FA actions that require password confirmation.
 */
export type ProtectedAction = "download" | "disable" | "regenerate";

/**
 * Editable password form state for the security tab.
 */
export interface SecurityFormState {
	currentPassword: string;
	newPassword: string;
	confirmPassword: string;
}

/**
 * Single active or revoked session shown in the security tab.
 */
export interface SecuritySessionLocation {
	city?: string | null;
	country?: string | null;
	countryCode?: string | null;
	isPublic?: boolean | null;
}

export interface SecuritySession {
	id: string;
	browser?: string | null;
	os?: string | null;
	ipAddress?: string | null;
	location?: SecuritySessionLocation | null;
	lastActiveAt?: string | null;
	isCurrent?: boolean;
	revokedAt?: string | null;
}

/**
 * Security settings payload returned by the profile API.
 */
export interface SecuritySettingsPayload {
	sessions?: SecuritySession[];
	twoFactorEnabled?: boolean;
	hasPendingSetup?: boolean;
	backupCodesRemaining?: number;
	backupCodesLastGeneratedAt?: string | null;
}

/**
 * RTK Query wrapper for the security settings response.
 */
export interface SecuritySettingsResponse {
	data?: SecuritySettingsPayload;
}

/**
 * Setup response returned when starting 2FA enrollment.
 */
export interface TwoFactorSetupResponse {
	data?: {
		secret?: string | null;
		otpauthUrl?: string | null;
		qrDataUrl?: string | null;
	};
}

/**
 * Response payload that contains generated backup codes.
 */
export interface BackupCodesResponse {
	data?: {
		backupCodes?: string[];
	};
}

/**
 * Response payload returned when ending all other sessions.
 */
export interface RevokeOtherSessionsResponse {
	data?: {
		revokedCount?: number;
	};
}

/**
 * Copy and button styling for a protected 2FA action prompt.
 */
export interface ProtectedActionConfig {
	title: string;
	description: string;
	confirmText: string;
	confirmVariant?: ButtonVariant;
}

/**
 * Props for the security settings tab.
 */
export interface SecurityTabProps {
	securityForm: SecurityFormState;
	setSecurityForm: (value: SecurityFormState) => void;
	onSubmit: () => void | Promise<void>;
	isUpdating: boolean;
	notification?: Pick<
		NotificationContextValue,
		"error" | "success" | "info"
	> | null;
}
