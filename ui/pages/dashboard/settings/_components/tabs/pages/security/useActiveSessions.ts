import { useState } from "react";
import {
	useRevokeOtherSessionsMutation,
	useRevokeSessionMutation,
} from "@/store/slices/api";
import { __, sprintf } from "@/i18n";
import { getErrorMessage } from "@/utils";
import type { SecuritySession, SecurityTabProps } from "../types";

interface UseActiveSessionsOptions {
	isLoading: boolean;
	notification: SecurityTabProps["notification"];
	refetchSecurity: () => unknown;
	sessions: SecuritySession[];
}

/**
 * Owns active-session revoke state and notification handling.
 */
export function useActiveSessions({
	isLoading,
	notification,
	refetchSecurity,
	sessions,
}: UseActiveSessionsOptions) {
	const [revokingId, setRevokingId] = useState<string | null>(null);
	const [isRevokingOthers, setIsRevokingOthers] = useState(false);
	const [showRevokeOthersConfirm, setShowRevokeOthersConfirm] =
		useState(false);

	const [revokeSession] = useRevokeSessionMutation();
	const [revokeOtherSessions] = useRevokeOtherSessionsMutation();
	const otherActiveSessions = sessions.filter(
		(session: SecuritySession) => !session.isCurrent && !session.revokedAt
	);

	const openRevokeOtherSessionsDialog = () => {
		setShowRevokeOthersConfirm(true);
	};

	const closeRevokeOtherSessionsDialog = () => {
		if (isRevokingOthers) {
			return;
		}

		setShowRevokeOthersConfirm(false);
	};

	const handleRevokeSession = async (
		sessionId: string,
		isCurrent?: boolean
	) => {
		setRevokingId(sessionId);
		try {
			await revokeSession(sessionId).unwrap();
			notification?.success(
				__("Session ended"),
				isCurrent
					? __("Current browser session ended.")
					: __("The session was revoked.")
			);
			if (isCurrent) {
				// The current session is gone at this point, so reload into the auth flow.
				setTimeout(() => {
					window.location.reload();
				}, 400);
				return;
			}
			refetchSecurity();
		} catch (err) {
			notification?.error(
				__("Error"),
				getErrorMessage(err, __("Failed to end the session"))
			);
		} finally {
			setRevokingId(null);
		}
	};

	const handleRevokeOtherSessions = async () => {
		if (0 === otherActiveSessions.length) {
			return;
		}

		setIsRevokingOthers(true);

		try {
			const result = await revokeOtherSessions(undefined).unwrap();
			const revokedCount = result?.data?.revokedCount ?? 0;
			setShowRevokeOthersConfirm(false);

			notification?.success(
				__("Other sessions ended"),
				revokedCount > 0
					? sprintf(__("%1$s other session%2$s were ended."), [
							String(revokedCount),
							1 === revokedCount ? "" : "s",
						])
					: __("No other active sessions were found.")
			);
			refetchSecurity();
		} catch (err) {
			notification?.error(
				__("Error"),
				getErrorMessage(err, __("Failed to end the other sessions"))
			);
		} finally {
			setIsRevokingOthers(false);
		}
	};

	return {
		dialog: {
			isLoading: isRevokingOthers,
			onClose: closeRevokeOtherSessionsDialog,
			onConfirm: handleRevokeOtherSessions,
			open: showRevokeOthersConfirm,
			sessionCount: otherActiveSessions.length,
		},
		section: {
			isLoading,
			isRevokingOthers,
			onRequestRevokeOthers: openRevokeOtherSessionsDialog,
			onRevokeSession: handleRevokeSession,
			otherActiveSessions,
			revokingId,
			sessions,
		},
	};
}
