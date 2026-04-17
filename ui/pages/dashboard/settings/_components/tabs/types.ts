import type { SerializedError } from '@reduxjs/toolkit';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import type { SettingsTabId } from '../layout/types';

/**
 * Supported release actions exposed by the updater UI.
 */
export type ReleaseAction = 'install' | 'reinstall';

/**
 * Normalized RTK Query error union used by the settings content shell.
 */
export type QueryError = FetchBaseQueryError | SerializedError | undefined;

/**
 * Summary metadata for a user-owned API key.
 */
export interface ApiKeySummary {
	id: string;
	label?: string | null;
	maskedKey?: string | null;
	createdAt?: string | null;
}

/**
 * Capability flags returned for the authenticated user.
 */
export interface ProfileUserCapabilities {
	manage_users?: boolean | null;
	manage_site_settings?: boolean | null;
	manage_mail_delivery?: boolean | null;
	manage_location_data?: boolean | null;
	manage_updates?: boolean | null;
	manage_profile?: boolean | null;
	manage_api_keys?: boolean | null;
	manage_webhooks?: boolean | null;
	view_all_links?: boolean | null;
	view_own_links?: boolean | null;
	view_site_analytics?: boolean | null;
	view_own_analytics?: boolean | null;
	create_links?: boolean | null;
}

/**
 * Canonical profile shape consumed by the settings dashboard.
 */
export interface ProfileUser {
	_id?: string | null;
	id?: string | null;
	username?: string | null;
	email?: string | null;
	firstName?: string | null;
	lastName?: string | null;
	phoneNumber?: string | null;
	company?: string | null;
	jobTitle?: string | null;
	bio?: string | null;
	role?: string | null;
	updatedAt?: string | null;
	baseApiUrl?: string | null;
	siteUrl?: string | null;
	apiKeys?: ApiKeySummary[] | null;
	capabilities?: ProfileUserCapabilities | null;
}

/**
 * Editable profile fields shown in the general settings form.
 */
export interface GeneralFormState {
	firstName: string;
	lastName: string;
	username: string;
	email: string;
	phoneNumber: string;
	company: string;
	jobTitle: string;
	bio: string;
}

/**
 * Profile form payload extended with the selected dashboard language.
 */
export interface GeneralFormPayload extends GeneralFormState {
	siteName: string;
	siteLanguage: string;
	faviconFile?: File | null;
	removeFavicon?: boolean;
}

/**
 * Credentials payload used to save GeoLite2 download settings.
 */
export interface GeoipConfigurationPayload {
	accountId: string;
	licenseKey: string;
}

/**
 * Supported mail transport drivers.
 */
export type MailDriver = 'mail' | 'smtp';

/**
 * Supported SMTP encryption modes.
 */
export type SmtpEncryption = 'tls' | 'ssl' | 'none';

/**
 * Persisted email delivery settings saved from the dashboard.
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
 * Props for the settings content router component.
 */
export interface ContentProps {
	activeTab: SettingsTabId;
}
