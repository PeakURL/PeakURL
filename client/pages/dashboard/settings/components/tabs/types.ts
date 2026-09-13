import type { SerializedError } from "@reduxjs/toolkit";
import type { FetchBaseQueryError } from "@reduxjs/toolkit/query";

import type { SiteTimeFormat } from "@/api";

import type { SettingsTabId } from "../layout/types";

export type {
	ApiKeySummary,
	CacheConfigurationPayload,
	CacheStatusPayload,
	CacheStatusResponse,
	CaptchaConfigurationPayload,
	CaptchaProvider,
	CaptchaStatus,
	GeoipConfigurationPayload,
	MailConfigurationPayload,
	MailDriver,
	MailTestResult,
	ProfileUser,
	ProfileUserCapabilities,
	SiteTimeFormat,
	SmtpEncryption,
} from "@/api";

/**
 * Supported release actions exposed by the updater UI.
 */
export type ReleaseAction = "install" | "reinstall";

/**
 * Normalized RTK Query error union used by the settings content shell.
 */
export type QueryError = FetchBaseQueryError | SerializedError | undefined;

/**
 * Editable profile fields shown in the general settings form.
 */
export interface GeneralFormState {
	firstName: string;
	lastName: string;
	displayName: string;
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
	siteTagline: string;
	siteLanguage: string;
	siteTimezone: string;
	siteTimeFormat: SiteTimeFormat;
	socialPreviewFile?: File | null;
	removeSocialPreviewImage?: boolean;
	faviconFile?: File | null;
	removeFavicon?: boolean;
	landingPageMode?: "login" | "url" | "html";
	landingPageUrl?: string;
	trashRetentionDays?: number;
}

/**
 * Props for the settings content router component.
 */
export interface ContentProps {
	activeTab: SettingsTabId;
}
