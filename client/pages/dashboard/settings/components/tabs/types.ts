import type { SerializedError } from "@reduxjs/toolkit";
import type { FetchBaseQueryError } from "@reduxjs/toolkit/query";

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
	SiteTimeFormat,
	SmtpEncryption,
	UserCapabilities,
} from "@/api";

export type { GeneralFormPayload, GeneralFormState } from "../../types";

/**
 * Supported release actions exposed by the updater UI.
 */
export type ReleaseAction = "install" | "reinstall";

/**
 * Normalized RTK Query error union used by the settings content shell.
 */
export type QueryError = FetchBaseQueryError | SerializedError | undefined;

/**
 * Props for the settings content router component.
 */
export interface ContentProps {
	activeTab: SettingsTabId;
}
