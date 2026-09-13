import type { RefObject } from "react";
import type { LucideIcon } from "lucide-react";
import type { CaptchaWidgetRef } from "@/components";

export interface LoginHighlightItem {
	icon: LucideIcon;
	label: string;
	desc: string;
}

export interface LoginHighlightsProps {
	highlights: LoginHighlightItem[];
}

export interface LoginFormProps {
	identifier: string;
	setIdentifier: (value: string) => void;
	password: string;
	setPassword: (value: string) => void;
	rememberMe: boolean;
	setRememberMe: (value: boolean) => void;
	formError: string;
	submitPending: boolean;
	onSubmit: (event: React.SubmitEvent<HTMLFormElement>) => void;
	captchaRef: RefObject<CaptchaWidgetRef | null>;
}

export interface TwoFactorChallengeProps {
	token: string;
	setToken: (value: string) => void;
	backupCode: string;
	setBackupCode: (value: string) => void;
	useBackupMode: boolean;
	setUseBackupMode: (value: boolean) => void;
	submitPending: boolean;
	onSubmit: (event: React.SubmitEvent<HTMLFormElement>) => void;
	onBackToSignIn: () => void;
	formError: string;
}
