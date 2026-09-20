import type { ReactNode } from "react";
import type { SerializedError } from "@reduxjs/toolkit";
import type { FetchBaseQueryError } from "@reduxjs/toolkit/query";

import type {
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
	SiteSettings,
	SiteTimeFormat,
	SmtpEncryption,
	UpdateStatusPayload,
	UserCapabilities,
} from "@/api";

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
	SiteSettings,
	SiteTimeFormat,
	SmtpEncryption,
	UpdateStatusPayload,
	UserCapabilities,
};

import type { SettingsTabId } from "@/router/tabs";

export type { SettingsTabId };

export type SettingsTabIcon =
	| "settings"
	| "activity"
	| "shield"
	| "key"
	| "mail"
	| "mapPin"
	| "globe"
	| "plug"
	| "zap"
	| "download";

export interface SettingsTabItem {
	id: SettingsTabId;
	name: string;
	icon: SettingsTabIcon;
}

export interface SettingsLayoutProps {
	children: ReactNode;
}

export interface SidebarProps {
	tabs: SettingsTabItem[];
	activeTab: SettingsTabId;
}

export type QueryError = FetchBaseQueryError | SerializedError | undefined;

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

export interface ContentProps {
	activeTab: SettingsTabId;
}
