import type { ReactNode } from "react";
import { useLocation, matchPath } from "react-router";

import { isValidSettingsTab } from "@/router/tabs";

import { SettingsNavigation, useSettingsAccess } from "./components/layout";

export default function Layout({ children }: { children: ReactNode }) {
	const { pathname } = useLocation();
	const routeTab = matchPath("/dashboard/settings/:tab", pathname)?.params
		.tab;
	const activeTab = isValidSettingsTab(routeTab) ? routeTab : "general";
	const { allowed } = useSettingsAccess();

	return (
		<div className="settings-layout">
			<SettingsNavigation activeTab={activeTab} allowed={allowed} />
			{children}
		</div>
	);
}
