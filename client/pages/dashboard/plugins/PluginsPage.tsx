import { useState } from "react";
import {
	ArrowLeft,
	Package,
	Plus,
	RefreshCw,
	ShieldCheck,
	Upload,
} from "lucide-react";
import { useLocation, useNavigate } from "react-router";

import { Button, PageHeader } from "@/components";
import { useAdminAccess } from "@/hooks";
import { __ } from "@/i18n";
import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import { useGetInstalledPluginsQuery } from "@/state/slices/api/system";

import {
	AddNewPluginsView,
	InstalledPluginsTable,
	PluginTabs,
} from "./components";
import type { PluginTabItem, TabId } from "./types";

function PluginsPage() {
	const location = useLocation();
	const navigate = useNavigate();
	const isAddNew = location.pathname === "/dashboard/plugins/add";

	const { canManagePlugins, isLoading: isAuthLoading } = useAdminAccess();
	const [userSelectedTab, setUserSelectedTab] = useState<TabId | null>(null);
	const [showUpload, setShowUpload] = useState(false);

	const {
		data: pluginsResponse,
		isLoading: isPluginsLoading,
		isError: isPluginsError,
		error: pluginsError,
		refetch,
	} = useGetInstalledPluginsQuery();

	const pluginsPayload = pluginsResponse?.data;
	const regularPlugins = pluginsPayload?.regular ?? [];
	const mustUsePlugins = pluginsPayload?.mustUse ?? [];

	const hasRegular = regularPlugins.length > 0;
	const hasMustUse = mustUsePlugins.length > 0;
	const hasAnyPlugins = hasRegular || hasMustUse;
	const shouldShowTabs = hasMustUse;

	// Derive the active tab from available inventory and user choice without cascading effect renders
	const activeTab: TabId = (() => {
		if (userSelectedTab === "must_use" && hasMustUse) {
			return "must_use";
		}
		if (userSelectedTab === "installed" && (hasRegular || !hasMustUse)) {
			return "installed";
		}
		// Default resolution when user hasn't selected or selection became unavailable:
		if (!hasRegular && hasMustUse) {
			return "must_use";
		}
		return "installed";
	})();

	const tabs: PluginTabItem[] = [
		{
			id: "installed",
			label: __("Installed"),
			count: regularPlugins.length,
		},
		{
			id: "must_use",
			label: __("Must-Use"),
			count: mustUsePlugins.length,
		},
	];

	/* ─── Loading Authentication ─── */
	if (isAuthLoading) {
		return (
			<div className="plugins-page-loading">
				<div className="plugins-page-loading-spinner" />
			</div>
		);
	}

	/* ─── Non-admin gate ─── */
	if (!canManagePlugins) {
		return (
			<div className="plugins-page-gate">
				<div className="plugins-page-gate-icon">
					<ShieldCheck size={28} />
				</div>
				<h2 className="plugins-page-gate-title">
					{__("Admin access required")}
				</h2>
				<p className="plugins-page-gate-copy">
					{__(
						"Only administrator accounts can inspect, manage, and configure installed plugins."
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="plugins-page space-y-6">
			{/* Page Header */}
			<PageHeader
				icon={dashboardPageIcons["plugins"]}
				title={isAddNew ? __("Add New Plugin") : __("Plugins")}
				description={
					isAddNew
						? __(
								"Browse available plugins or upload a ZIP file to install one."
							)
						: __("Manage the plugins installed on this server.")
				}
				actions={
					isAddNew ? (
						<div className="flex items-center gap-2.5">
							<Button
								variant="outline"
								onClick={() => navigate("/dashboard/plugins")}
								className="inline-flex items-center gap-1.5"
							>
								<ArrowLeft size={16} />
								<span>{__("Installed Plugins")}</span>
							</Button>
							<Button
								variant={showUpload ? "secondary" : "primary"}
								onClick={() => setShowUpload((prev) => !prev)}
								className="inline-flex items-center gap-1.5"
							>
								<Upload size={16} />
								<span>
									{showUpload
										? __("Close Uploader")
										: __("Upload Plugin")}
								</span>
							</Button>
						</div>
					) : (
						<Button
							onClick={() => navigate("/dashboard/plugins/add")}
							className="inline-flex items-center gap-1.5"
						>
							<Plus size={16} />
							<span>{__("Add New")}</span>
						</Button>
					)
				}
			/>

			{/* Main Content */}
			{isAddNew ? (
				/* Add New Directory View */
				<AddNewPluginsView
					showUpload={showUpload}
					onToggleUpload={setShowUpload}
					uploadLimit={pluginsPayload?.uploadLimit}
				/>
			) : isPluginsLoading ? (
				/* Loading inventory state */
				<div className="py-16 text-center" aria-live="polite">
					<div className="flex items-center justify-center gap-3 text-text-muted">
						<RefreshCw
							size={18}
							className="animate-spin text-accent"
						/>
						<span className="text-sm font-medium">
							{__("Loading plugins…")}
						</span>
					</div>
				</div>
			) : isPluginsError ? (
				/* Error state handled by table */
				<InstalledPluginsTable
					plugins={[]}
					type="regular"
					isLoading={false}
					isError={true}
					error={pluginsError}
					onRetry={refetch}
				/>
			) : !hasAnyPlugins ? (
				/* First-Use Empty State: no regular and no must-use plugins */
				<div className="py-14 sm:py-20 text-center max-w-md mx-auto">
					<div
						className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-surface-alt border border-stroke/70 text-text-muted"
						aria-hidden="true"
					>
						<Package size={22} />
					</div>
					<h3 className="text-base font-semibold text-heading">
						{__("No plugins installed yet")}
					</h3>
					<p className="mt-2 text-xs sm:text-sm text-text-muted leading-relaxed">
						{__(
							"Browse available plugins or upload a ZIP file to get started."
						)}
					</p>
					<div className="mt-5">
						<Button
							onClick={() => navigate("/dashboard/plugins/add")}
							className="inline-flex items-center gap-1.5"
						>
							<Plus size={16} />
							<span>{__("Add Plugin")}</span>
						</Button>
					</div>
				</div>
			) : (
				/* Populated Views */
				<div className="space-y-4">
					{/* Tab Bar - Only rendered when must-use plugins exist */}
					{shouldShowTabs && (
						<div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
							<PluginTabs
								activeTab={activeTab}
								onTabChange={setUserSelectedTab}
								tabs={tabs}
							/>
						</div>
					)}

					{/* Must-Use Tab Hint */}
					{shouldShowTabs && activeTab === "must_use" && (
						<p className="text-xs text-text-muted">
							{__(
								"Must-use plugins are managed by the server administrator and load automatically on every request. They cannot be activated, deactivated, or deleted from the dashboard."
							)}
						</p>
					)}

					{/* Tab 1: Regular Installed Plugins */}
					{activeTab === "installed" && (
						<InstalledPluginsTable
							plugins={regularPlugins}
							type="regular"
							isLoading={isPluginsLoading}
							isError={isPluginsError}
							error={pluginsError}
							onRetry={refetch}
						/>
					)}

					{/* Tab 2: Must-Use Plugins */}
					{activeTab === "must_use" && (
						<InstalledPluginsTable
							plugins={mustUsePlugins}
							type="must_use"
							isLoading={isPluginsLoading}
							isError={isPluginsError}
							error={pluginsError}
							onRetry={refetch}
						/>
					)}
				</div>
			)}
		</div>
	);
}

export default PluginsPage;
