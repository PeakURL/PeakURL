import { useAdminAccess } from "@/hooks";
import type { SettingsTabId } from "@/router/tabs";

/** Share the existing capability rules between navigation and direct route access. */
export function useSettingsAccess() {
	const access = useAdminAccess();

	return {
		isLoading: access.isLoading,
		allowed: {
			general: true,
			security: true,
			api: access.canManageApiKeys,
			integrations: access.canManageIntegrations,
			mcp: access.canManageMcp,
			performance: access.canManagePerformance,
			email: access.canManageMailDelivery,
			location: access.canManageLocationData,
			updates: access.canManageUpdates,
		} satisfies Record<SettingsTabId, boolean>,
	};
}
