import type {
	ProtectedAction,
	SecurityFormState,
	SecuritySession,
} from "../types";

export type SecurityDirection = "ltr" | "rtl";

/**
 * Props for the password update section of the security tab.
 */
export interface PasswordSettingsProps {
	securityForm: SecurityFormState;
	setSecurityForm: (value: SecurityFormState) => void;
	onSubmit: () => void | Promise<void>;
	isUpdating: boolean;
	isRtl: boolean;
}

/**
 * Props for the two-factor authentication section.
 */
export interface TwoFactorSettingsProps {
	direction: SecurityDirection;
	twoFactorEnabled?: boolean;
	actionLabel: string;
	statusMessage: string;
	backupCodesLastGeneratedAt?: string | null;
	hasSetupDetails: boolean;
	qrDataUrl: string | null;
	secret: string | null;
	otpauthUrl: string | null;
	verificationCode: string;
	recentCodes: string[];
	isStarting: boolean;
	isRegenerating: boolean;
	isDisabling: boolean;
	isDownloading: boolean;
	isDownloadingFromApi: boolean;
	isVerifying: boolean;
	onStartSetup: () => void;
	onOpenProtectedAction: (action: ProtectedAction) => void;
	onDownloadRequest: () => void;
	onVerify: () => void | Promise<void>;
	onCancelSetup: () => void;
	onVerificationCodeChange: (value: string) => void;
}

/**
 * Props for the 2FA status summary and backup-code download action.
 */
export interface TwoFactorStatusProps {
	direction: SecurityDirection;
	twoFactorEnabled?: boolean;
	statusMessage: string;
	backupCodesLastGeneratedAt?: string | null;
	isDownloading: boolean;
	isDownloadingFromApi: boolean;
	onDownloadRequest: () => void;
}

/**
 * Props for the active authenticator-app setup form.
 */
export interface TwoFactorSetupProps {
	direction: SecurityDirection;
	qrDataUrl: string | null;
	secret: string | null;
	otpauthUrl: string | null;
	verificationCode: string;
	isVerifying: boolean;
	onVerify: () => void | Promise<void>;
	onCancelSetup: () => void;
	onVerificationCodeChange: (value: string) => void;
}

/**
 * Props for the one-time visible backup-code list.
 */
export interface BackupCodesListProps {
	direction: SecurityDirection;
	recentCodes: string[];
	isDownloading: boolean;
	isDownloadingFromApi: boolean;
	onDownloadRequest: () => void;
}

/**
 * Props for the active session list and bulk revoke action.
 */
export interface ActiveSessionsProps {
	direction: SecurityDirection;
	sessions: SecuritySession[];
	otherActiveSessions: SecuritySession[];
	isLoading: boolean;
	isRevokingOthers: boolean;
	revokingId: string | null;
	onRequestRevokeOthers: () => void;
	onRevokeSession: (
		sessionId: string,
		isCurrent?: boolean
	) => void | Promise<void>;
}

/**
 * Props for a single browser/device session row.
 */
export interface SessionItemProps {
	direction: SecurityDirection;
	session: SecuritySession;
	revokingId: string | null;
	onRevokeSession: (
		sessionId: string,
		isCurrent?: boolean
	) => void | Promise<void>;
}
