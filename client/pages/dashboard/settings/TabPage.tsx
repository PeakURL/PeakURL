import { Navigate, useParams } from "react-router";

import { useAdminAccess } from "@/hooks";
import NotFoundPage from "@/pages/NotFoundPage";
import { isValidSettingsTab } from "@/router/tabs";

import { Content } from "./components";
import type { SettingsTabId } from "./components/layout/types";

function TabPage() {
	const params = useParams();
	const tab = params.tab;
	const {
		canManageApiKeys,
		canManageIntegrations,
		canManageMcp,
		canManagePerformance,
		canManageMailDelivery,
		canManageLocationData,
		canManageUpdates,
		isLoading,
	} = useAdminAccess();

	if (!isValidSettingsTab(tab)) {
		return <NotFoundPage />;
	}

	const restrictedTabs: Partial<Record<SettingsTabId, boolean>> = {
		api: canManageApiKeys,
		integrations: canManageIntegrations,
		mcp: canManageMcp,
		performance: canManagePerformance,
		email: canManageMailDelivery,
		location: canManageLocationData,
		updates: canManageUpdates,
	};

	if (tab && tab in restrictedTabs && !isLoading && !restrictedTabs[tab]) {
		return <Navigate replace to="/dashboard/settings/general" />;
	}

	return (
		<div className="settings-tab-page">
			<Content activeTab={tab || "general"} />
		</div>
	);
}

export default TabPage;
