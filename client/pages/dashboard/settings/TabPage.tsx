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
		canManageWebhooks,
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
		integrations: canManageWebhooks,
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
			<div className="settings-tab-page-panel">
				<Content activeTab={tab || "general"} />
			</div>
		</div>
	);
}

export default TabPage;
