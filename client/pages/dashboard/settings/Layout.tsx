import { useParams } from "react-router";

import { useAdminAccess } from "@/hooks";
import { __ } from "@/i18n";

import { Header, Sidebar } from "./components";
import type {
	SettingsLayoutProps,
	SettingsTabId,
	SettingsTabItem,
} from "./components/layout/types";

function Layout({ children }: SettingsLayoutProps) {
	const params = useParams();
	const activeTab = (params.tab as SettingsTabId | undefined) || "general";
	const {
		canManageApiKeys,
		canManageIntegrations,
		canManageMailDelivery,
		canManageLocationData,
		canManagePerformance,
		canManageUpdates,
	} = useAdminAccess();

	const tabs: SettingsTabItem[] = [
		{ id: "general", name: __("General"), icon: "settings" },
		{ id: "security", name: __("Security"), icon: "shield" },
	];

	if (canManageApiKeys) {
		tabs.push({ id: "api", name: __("API Keys"), icon: "key" });
	}

	if (canManageIntegrations) {
		tabs.push({
			id: "integrations",
			name: __("Integrations"),
			icon: "plug",
		});
	}

	if (canManagePerformance) {
		tabs.push({
			id: "performance",
			name: __("Performance"),
			icon: "zap",
		});
	}

	if (canManageMailDelivery) {
		tabs.push({ id: "email", name: __("Email SMTP"), icon: "mail" });
	}

	if (canManageLocationData) {
		tabs.push({
			id: "location",
			name: __("Location Data"),
			icon: "mapPin",
		});
	}

	if (canManageUpdates) {
		tabs.push({ id: "updates", name: __("Updates"), icon: "download" });
	}

	return (
		<div className="settings-layout">
			<Header />

			<div className="settings-layout-grid">
				<Sidebar tabs={tabs} activeTab={activeTab} />

				{children}
			</div>
		</div>
	);
}

export default Layout;
