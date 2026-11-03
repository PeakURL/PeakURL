import { useMemo, useState } from "react";
import { RefreshCw } from "lucide-react";
import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import { __ } from "@/i18n";
import {
	Button,
	ConfirmDialog,
	PageHeader,
	RefreshButton,
	useNotification,
} from "@/components";
import { usePluginAssets } from "@/hooks";
import {
	useDispatchPluginActionMutation,
	useGetPluginExtensionsQuery,
} from "@/state/slices/api/system";
import type { PluginUIExtensionDescriptor } from "@/api/types/plugins";

import type { LinksHeaderProps } from "../types";
import DateRangeFilter from "./DateRangeFilter";

const Header = ({
	onRefresh,
	isRefreshing = false,
	clickRange,
	customClickRange,
	onClickRangeChange,
	onCustomClickRangeChange,
	canViewLinks = true,
	canViewAnalytics = true,
}: LinksHeaderProps) => {
	const notification = useNotification();
	const [busyActionId, setBusyActionId] = useState<string | null>(null);
	const [pendingConfirmExt, setPendingConfirmExt] =
		useState<PluginUIExtensionDescriptor | null>(null);

	const { data: extensionsResponse } = useGetPluginExtensionsQuery();
	const [dispatchAction] = useDispatchPluginActionMutation();

	const linksActions = useMemo(
		() =>
			(extensionsResponse?.data?.uiExtensions ?? []).filter(
				(ext) => ext.location === "links_overview_actions"
			),
		[extensionsResponse]
	);

	const linksAssets = useMemo(
		() => linksActions.flatMap((ext) => ext.assets ?? []),
		[linksActions]
	);
	usePluginAssets(linksAssets);

	const executeAction = async (ext: PluginUIExtensionDescriptor) => {
		setBusyActionId(ext.id);
		try {
			const res = await dispatchAction({
				slug: ext.pluginSlug,
				actionId: ext.actionId,
				invalidates: ext.invalidates,
			}).unwrap();

			const msg =
				typeof res?.data === "object" &&
				res?.data !== null &&
				"message" in res.data
					? String((res.data as Record<string, unknown>).message)
					: __("Action executed successfully.");
			notification.success(msg);
		} catch (err: unknown) {
			const apiErr = err as { data?: { message?: string } };
			notification.error(
				apiErr?.data?.message ?? __("Failed to execute plugin action.")
			);
		} finally {
			setBusyActionId(null);
			setPendingConfirmExt(null);
		}
	};

	const handleActionClick = (ext: PluginUIExtensionDescriptor) => {
		if (ext.confirm) {
			setPendingConfirmExt(ext);
			return;
		}
		void executeAction(ext);
	};

	return (
		<>
			<PageHeader
				icon={dashboardPageIcons["links"]}
				title={__("All Links")}
				description={__("Manage and track your shortened URLs")}
				actions={
					<div className="links-header-actions">
						{linksActions.map((ext) => {
							const iconsMap = dashboardPageIcons as Record<
								string,
								(typeof dashboardPageIcons)["links"]
							>;
							const ActionIcon =
								ext.icon && iconsMap[ext.icon]
									? iconsMap[ext.icon]
									: null;

							return (
								<Button
									key={ext.id}
									variant="secondary"
									disabled={busyActionId === ext.id}
									onClick={() => handleActionClick(ext)}
								>
									{busyActionId === ext.id ? (
										<RefreshCw className="w-3.5 h-3.5 mr-1.5 animate-spin" />
									) : ActionIcon ? (
										<ActionIcon className="w-3.5 h-3.5 mr-1.5" />
									) : null}
									{ext.label}
								</Button>
							);
						})}
						{canViewLinks && (
							<RefreshButton
								onRefresh={onRefresh}
								isRefreshing={isRefreshing}
								label={__("Refresh links")}
							/>
						)}
						{canViewLinks && canViewAnalytics && (
							<DateRangeFilter
								clickRange={clickRange}
								customClickRange={customClickRange}
								onClickRangeChange={onClickRangeChange}
								onCustomClickRangeChange={
									onCustomClickRangeChange
								}
							/>
						)}
					</div>
				}
			/>

			{pendingConfirmExt && (
				<ConfirmDialog
					open={Boolean(pendingConfirmExt)}
					onClose={() => setPendingConfirmExt(null)}
					title={pendingConfirmExt.label}
					description={pendingConfirmExt.confirm ?? ""}
					confirmText={__("Run Action")}
					cancelText={__("Cancel")}
					confirmVariant="primary"
					loading={busyActionId === pendingConfirmExt.id}
					onConfirm={() => executeAction(pendingConfirmExt)}
				/>
			)}
		</>
	);
};

export default Header;
