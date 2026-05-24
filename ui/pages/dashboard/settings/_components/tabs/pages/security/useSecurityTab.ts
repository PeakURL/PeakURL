import { useGetSecuritySettingsQuery } from "@/store/slices/api";
import { isDocumentRtl } from "@/i18n/direction";

import { useActiveSessions } from "./useActiveSessions";
import { useTwoFactorSettings } from "./useTwoFactorSettings";
import type { SecuritySession, SecurityTabProps } from "../types";
import type { SecurityDirection } from "./types";

interface UseSecurityTabOptions {
	notification: SecurityTabProps["notification"];
}

/**
 * Composes the security tab query with its focused workflow hooks.
 */
export function useSecurityTab({ notification }: UseSecurityTabOptions) {
	const isRtl = isDocumentRtl();
	const direction: SecurityDirection = isRtl ? "rtl" : "ltr";
	const {
		data: securityData,
		isFetching: isSecurityLoading,
		refetch: refetchSecurity,
	} = useGetSecuritySettingsQuery(undefined);

	const security = securityData?.data || {};
	const sessions: SecuritySession[] = security.sessions || [];
	const twoFactor = useTwoFactorSettings({
		notification,
		refetchSecurity,
		security,
	});
	const activeSessions = useActiveSessions({
		isLoading: isSecurityLoading,
		notification,
		refetchSecurity,
		sessions,
	});

	return {
		activeSessions,
		direction,
		isRtl,
		twoFactor,
	};
}
