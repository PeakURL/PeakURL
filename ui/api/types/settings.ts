import type { InstalledLanguage, TextDirection } from "@/i18n/types";

/**
 * Request body used to save MaxMind GeoLite2 credentials.
 */
export interface GeoipConfigurationPayload {
	accountId: string;
	licenseKey: string;
}

/**
 * Supported CAPTCHA providers for protected public links.
 */
export type CaptchaProvider = "none" | "recaptcha" | "turnstile";

/**
 * Request body used to save CAPTCHA provider settings.
 */
export interface CaptchaConfigurationPayload {
	provider: CaptchaProvider;
	siteKey: string;
	secretKey: string;
}

/**
 * CAPTCHA configuration status returned by the settings endpoint.
 */
export interface CaptchaStatus {
	provider: CaptchaProvider;
	siteKey?: string | null;
	siteKeyConfigured?: boolean | null;
	siteKeyHint?: string | null;
	secretKeyConfigured?: boolean | null;
	secretKeyHint?: string | null;
	configured?: boolean | null;
	enabled?: boolean | null;
	canManageFromDashboard?: boolean | null;
	manageDisabledReason?: string | null;
	saved?: boolean | null;
}

/**
 * Supported mail delivery drivers.
 */
export type MailDriver = "mail" | "smtp";

/**
 * Supported SMTP encryption modes.
 */
export type SmtpEncryption = "tls" | "ssl" | "none";

/**
 * Supported dashboard clock display modes.
 */
export type SiteTimeFormat = "12" | "24";

/**
 * Request body used to save mail delivery settings.
 */
export interface MailConfigurationPayload {
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
 * Result returned after sending a test email.
 */
export interface MailTestResult {
	sent?: boolean;
	recipient?: string | null;
	driver?: MailDriver | null;
}

/**
 * Favicon metadata returned by the general settings endpoint.
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
 * Default social preview image metadata for the install.
 */
export interface SiteSocialPreview {
	configured?: boolean;
	url?: string | null;
	mimeType?: string | null;
	width?: number | string | null;
	height?: number | string | null;
	sizes?: string | null;
	updatedAt?: string | null;
	recommendedSize?: string | null;
}

/**
 * General site settings consumed by the dashboard settings tab.
 */
export interface SiteSettings {
	siteName?: string | null;
	siteTagline?: string | null;
	siteUrl?: string | null;
	siteLanguage?: string | null;
	siteTimezone?: string | null;
	siteTimeFormat?: SiteTimeFormat | null;
	textDirection?: TextDirection;
	isRtl?: boolean;
	canManageSiteSettings?: boolean;
	availableLanguages?: InstalledLanguage[];
	favicon?: SiteFavicon | null;
	socialPreview?: SiteSocialPreview | null;
	/** Mode for the root domain landing page behavior. */
	landingPageMode?: "login" | "url" | "html";

	/** Target URL when landingPageMode is 'url'. */
	landingPageUrl?: string;

	/** Absolute path to the content directory. */
	contentDirectory?: string;
}

/**
 * Mail delivery status returned by the admin-only mail endpoint.
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
 * GeoLite2 location-data status returned by the settings endpoint.
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
