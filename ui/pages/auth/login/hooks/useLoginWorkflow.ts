import type { SubmitEvent } from "react";
import { useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router";

import type { CaptchaWidgetRef } from "@/components";
import { __ } from "@/i18n";
import {
	selectSessionUser,
	useAuthCheckQuery,
	useLoginMutation,
	useVerifyTwoFactorLoginMutation,
} from "@/state/slices/api";
import {
	getErrorMessage,
	getErrorStatus,
	getInstallRecovery,
	isRelativeUrl,
	sanitizeUrl,
} from "@/utils";

export function useLoginWorkflow() {
	const location = useLocation();
	const navigate = useNavigate();

	const [identifier, setIdentifier] = useState("");
	const [password, setPassword] = useState("");
	const [rememberMe, setRememberMe] = useState(false);
	const [token, setToken] = useState("");
	const [backupCode, setBackupCode] = useState("");
	const [useBackupMode, setUseBackupMode] = useState(false);
	const [twoFactorRequired, setTwoFactorRequired] = useState(false);
	const [formError, setFormError] = useState("");
	const captchaRef = useRef<CaptchaWidgetRef>(null);

	const [login, { isLoading: isLoggingIn }] = useLoginMutation();
	const [verifyLogin, { isLoading: isVerifying }] =
		useVerifyTwoFactorLoginMutation();
	const { data, error, isError, isFetching, isLoading, refetch } =
		useAuthCheckQuery(undefined);

	const currentUser = selectSessionUser(data);
	const errorStatus = getErrorStatus(error);
	const isAuthError = 401 === errorStatus || 403 === errorStatus;
	const isRetryingApiCheck =
		isFetching && !isLoading && !currentUser && !isAuthError;
	const isApiError = (isError || isRetryingApiCheck) && !isAuthError;
	const installRecovery = getInstallRecovery(error);
	const hasResolvedSession = undefined !== data || undefined !== error;
	const isPending = !hasResolvedSession && isLoading;
	const submitPending = isLoggingIn || isVerifying;

	const redirectTo = useMemo(() => {
		const searchParams = new URLSearchParams(location.search || "");
		const redirectParam = sanitizeUrl(searchParams.get("redirect") || "");

		if (redirectParam && isRelativeUrl(redirectParam)) {
			return redirectParam;
		}

		return "/dashboard";
	}, [location.search]);

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		setFormError("");

		if (twoFactorRequired) {
			const submittedCode = (useBackupMode ? backupCode : token).trim();

			if (!submittedCode) {
				setFormError(
					useBackupMode
						? __("Enter a backup code.")
						: __("Enter the verification code.")
				);
				return;
			}

			try {
				await verifyLogin({
					identifier: identifier.trim(),
					password,
					rememberMe,
					token: submittedCode,
				}).unwrap();

				navigate(redirectTo, { replace: true });
			} catch (err) {
				setFormError(getErrorMessage(err, __("Verification failed.")));
			}
			return;
		}

		if (!identifier.trim() || !password) {
			setFormError(__("Username/email and password are required."));
			return;
		}

		try {
			let captchaToken: string | undefined = undefined;
			if (captchaRef.current) {
				captchaToken = await captchaRef.current.getToken();
			}

			const result = await login({
				identifier: identifier.trim(),
				password,
				rememberMe,
				captchaToken,
			}).unwrap();

			if (result.data?.requiresTwoFactor) {
				setTwoFactorRequired(true);
				setToken("");
				setBackupCode("");
				setUseBackupMode(false);
				setFormError("");
				return;
			}

			navigate(redirectTo, { replace: true });
		} catch (err) {
			setFormError(getErrorMessage(err, __("Invalid credentials.")));
		}
	};

	const handleBackToSignIn = () => {
		setTwoFactorRequired(false);
		setToken("");
		setBackupCode("");
		setUseBackupMode(false);
		setFormError("");
	};

	return {
		identifier,
		setIdentifier,
		password,
		setPassword,
		rememberMe,
		setRememberMe,
		token,
		setToken,
		backupCode,
		setBackupCode,
		useBackupMode,
		setUseBackupMode,
		twoFactorRequired,
		setTwoFactorRequired,
		formError,
		setFormError,
		captchaRef,
		currentUser,
		error,
		isRetryingApiCheck,
		isPending,
		isApiError,
		installRecovery,
		submitPending,
		redirectTo,
		handleSubmit,
		handleBackToSignIn,
		refetch,
	};
}
