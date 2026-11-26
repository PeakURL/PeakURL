import { useMemo, useState } from "react";
import { RefreshCw } from "lucide-react";

import type { PluginAssetDescriptor, PluginUIExtensionDescriptor } from "@/api";
import { Button, ConfirmDialog, useNotification } from "@/components";
import { usePluginAssets } from "@/hooks";
import { __ } from "@/i18n";
import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import { getErrorMessage } from "@/shared/errors";
import {
	useDispatchPluginActionMutation,
	useGetPluginExtensionsQuery,
} from "@/state/slices/api/system";

type PluginActionsProps = {
	className?: string;
} & (
	| { location: "links_overview_actions"; linkId?: never }
	| { location: "link_stats_actions"; linkId: string }
);

const actionIcons: Partial<
	Record<string, (typeof dashboardPageIcons)["links"]>
> = dashboardPageIcons;

/** Render the registered actions for a links dashboard surface. */
export function PluginActions({
	location,
	linkId,
	className,
}: PluginActionsProps) {
	const notification = useNotification();
	const [busyActionId, setBusyActionId] = useState<string | null>(null);
	const [pendingAction, setPendingAction] =
		useState<PluginUIExtensionDescriptor | null>(null);

	const { data: extensionsResponse } = useGetPluginExtensionsQuery();
	const [dispatchAction] = useDispatchPluginActionMutation();

	const actions = useMemo(
		() =>
			(extensionsResponse?.data?.uiExtensions ?? []).filter(
				(extension) => extension.location === location
			),
		[extensionsResponse, location]
	);

	const assets = useMemo(() => {
		const assetsByUrl = new Map<string, PluginAssetDescriptor>();
		for (const action of actions) {
			for (const asset of action.assets ?? []) {
				assetsByUrl.set(asset.url, asset);
			}
		}
		return [...assetsByUrl.values()];
	}, [actions]);
	usePluginAssets(assets);

	const executeAction = async (action: PluginUIExtensionDescriptor) => {
		setBusyActionId(action.id);
		try {
			const { data } = await dispatchAction({
				slug: action.pluginSlug,
				actionId: action.actionId,
				invalidates: action.invalidates,
				payload: linkId ? { url_id: linkId } : undefined,
			}).unwrap();

			notification.success(
				typeof data?.message === "string"
					? data.message
					: __("Action executed successfully.")
			);
		} catch (error: unknown) {
			notification.error(
				getErrorMessage(error, __("Failed to execute plugin action."))
			);
		} finally {
			setBusyActionId(null);
			setPendingAction(null);
		}
	};

	const handleActionClick = (action: PluginUIExtensionDescriptor) => {
		if (action.confirm) {
			setPendingAction(action);
			return;
		}
		void executeAction(action);
	};

	if (actions.length === 0) return null;

	return (
		<>
			<div className={className} data-plugin-action-location={location}>
				{actions.map((action) => {
					const ActionIcon = action.icon
						? actionIcons[action.icon]
						: undefined;

					return (
						<Button
							key={action.id}
							data-plugin-slug={action.pluginSlug}
							variant="secondary"
							disabled={busyActionId !== null}
							onClick={() => handleActionClick(action)}
						>
							{busyActionId === action.id ? (
								<RefreshCw className="me-1.5 size-3.5 animate-spin" />
							) : ActionIcon ? (
								<ActionIcon className="me-1.5 size-3.5" />
							) : null}
							{action.label}
						</Button>
					);
				})}
			</div>
			{pendingAction && (
				<ConfirmDialog
					open
					onClose={() => {
						if (busyActionId === null) setPendingAction(null);
					}}
					title={pendingAction.label}
					description={pendingAction.confirm ?? undefined}
					confirmText={__("Run Action")}
					loading={busyActionId === pendingAction.id}
					onConfirm={() => executeAction(pendingAction)}
				/>
			)}
		</>
	);
}
