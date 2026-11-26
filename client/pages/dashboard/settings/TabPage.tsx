import { Navigate, useParams } from "react-router";

import { PageHeader } from "@/components";
import { __ } from "@/i18n";
import NotFoundPage from "@/pages/NotFoundPage";
import { getSettingsLabels, isValidSettingsTab } from "@/router/tabs";
import type { SettingsTabId } from "@/router/tabs";

import { Content } from "./components";
import { DocumentationLink } from "./components/DocumentationLink";
import { useSettingsAccess } from "./components/layout";

function getSettingsDescriptions() {
	return {
		general: __(
			"Manage your profile, branding, and installation preferences."
		),
		security: __(
			"Manage your password, two-factor authentication, and active sessions."
		),
		api: __(
			"Manage API keys and find the endpoint for your PeakURL installation."
		),
		integrations: __(
			"Connect PeakURL to your automations with webhooks and protect public link forms with CAPTCHA."
		),
		mcp: __(
			"Connect AI clients directly to PeakURL using Model Context Protocol (MCP) and OAuth 2.0 PKCE."
		),
		performance: __(
			"Configure object caching backends and Time-to-Live (TTL) policies to optimize link redirection latency and throughput."
		),
		email: __(
			"PeakURL uses this mail transport for password-reset emails and other account recovery notifications."
		),
		location: __(
			"Enable country and city analytics with a local MaxMind GeoLite2 City database stored in your persistent content folder."
		),
		updates: __(
			"Check for application updates and manage your installation’s database schema."
		),
	};
}

export default function TabPage() {
	const { tab } = useParams();
	const { allowed, isLoading } = useSettingsAccess();

	if (!isValidSettingsTab(tab)) {
		return <NotFoundPage />;
	}

	if (!allowed[tab] && !isLoading) {
		return <Navigate replace to="/dashboard/settings/general" />;
	}

	if (!allowed[tab]) {
		return null;
	}

	const documentation: Partial<Record<SettingsTabId, string>> = {
		mcp: "https://peakurl.org/docs/mcp",
		performance: "https://go.peakurl.org/227c49",
		email: "https://go.peakurl.org/d80593",
	};
	const documentationHref = documentation[tab];

	return (
		<div className="settings-tab-page">
			<PageHeader
				className="settings-page-header"
				title={getSettingsLabels()[tab]}
				description={<bdi>{getSettingsDescriptions()[tab]}</bdi>}
				actions={
					documentationHref && (
						<div className="settings-page-actions">
							{tab === "mcp" && (
								<span className="mcp-badge mcp-badge-neutral">
									{__("Preview")}
								</span>
							)}
							<DocumentationLink
								href={documentationHref}
								label={
									tab === "mcp"
										? __("MCP Documentation")
										: undefined
								}
							/>
						</div>
					)
				}
			/>
			<Content activeTab={tab} />
		</div>
	);
}
