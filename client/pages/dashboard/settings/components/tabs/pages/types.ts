import type { LucideIcon } from "lucide-react";

import type {
	ApiKeySummary,
	EmailStatus,
	LocationDataStatus,
	SiteSettings,
} from "@/api";
import type { ButtonVariant, NotificationContextValue } from "@/components";

import type {
	GeneralFormPayload,
	GeneralFormState,
	MailDriver,
	SmtpEncryption,
} from "../types";

export type {
	BackupCodesResponse,
	EmailStatus,
	LocationDataStatus,
	RevokeOtherSessionsResponse,
	SecuritySettingsPayload,
	SecuritySettingsResponse,
	SecuritySession,
	SecuritySessionLocation,
	SiteFavicon,
	SiteSettings,
	SiteSocialPreview,
	TwoFactorSetupResponse,
} from "@/api";

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
