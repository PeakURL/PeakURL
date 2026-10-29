import { useState } from "react";
import {
	AlertCircle,
	AlertTriangle,
	ExternalLink,
	Layers,
	Package,
	RefreshCw,
} from "lucide-react";

import { Button, ConfirmDialog, useNotification } from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn } from "@/shared/formatting";
import { getErrorStatus } from "@/shared/errors";
import {
	useActivatePluginMutation,
	useDeactivatePluginMutation,
	useDeletePluginMutation,
} from "@/state/slices/api/system";

import type { InstalledPlugin } from "../../types";

export interface InstalledPluginsTableProps {
	/** Discovered plugin records. */
	plugins: readonly InstalledPlugin[];

	/** Current tab category. */
	type: "regular" | "must_use";

	/** Loading indicator. */
	isLoading?: boolean;

	/** Error indicator. */
	isError?: boolean;

	/** Raw API error object. */
	error?: unknown;

	/** Optional retry callback when query fails. */
	onRetry?: () => void;
}

export function InstalledPluginsTable({
	plugins = [],
	type,
	isLoading = false,
	isError = false,
	error,
	onRetry,
}: InstalledPluginsTableProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";
	const notification = useNotification();

	const [activatePlugin, { isLoading: isActivating }] =
		useActivatePluginMutation();
	const [deactivatePlugin, { isLoading: isDeactivating }] =
		useDeactivatePluginMutation();
	const [deletePlugin, { isLoading: isDeleting }] = useDeletePluginMutation();

	const [pluginToDelete, setPluginToDelete] =
		useState<InstalledPlugin | null>(null);
	const [expandedIssues, setExpandedIssues] = useState<
		Record<string, boolean>
	>({});
	const [busyPluginId, setBusyPluginId] = useState<string | null>(null);

	const toggleIssue = (id: string) => {
		setExpandedIssues((prev) => ({
			...prev,
			[id]: !prev[id],
		}));
	};

	const handleActivate = async (plugin: InstalledPlugin) => {
		setBusyPluginId(plugin.id);
		try {
			await activatePlugin({ id: plugin.id }).unwrap();
			notification.success(__("Plugin activated."));
		} catch (err: unknown) {
			const apiErr = err as { data?: { message?: string } };
			notification.error(
				apiErr?.data?.message ?? __("Failed to activate plugin.")
			);
		} finally {
			setBusyPluginId(null);
		}
	};

	const handleDeactivate = async (plugin: InstalledPlugin) => {
		setBusyPluginId(plugin.id);
		try {
			await deactivatePlugin({ id: plugin.id }).unwrap();
			notification.success(__("Plugin deactivated."));
		} catch (err: unknown) {
			const apiErr = err as { data?: { message?: string } };
			notification.error(
				apiErr?.data?.message ?? __("Failed to deactivate plugin.")
			);
		} finally {
			setBusyPluginId(null);
		}
	};

	const handleConfirmDelete = async () => {
		if (!pluginToDelete) {
			return;
		}
		const id = pluginToDelete.id;
		try {
			await deletePlugin({ id }).unwrap();
			notification.success(__("Plugin deleted."));
			setPluginToDelete(null);
		} catch (err: unknown) {
			const apiErr = err as { data?: { message?: string } };
			notification.error(
				apiErr?.data?.message ?? __("Failed to delete plugin.")
			);
		}
	};

	if (isLoading) {
		return (
			<div className="py-16 text-center" aria-live="polite">
				<div className="flex items-center justify-center gap-3 text-text-muted">
					<RefreshCw size={18} className="animate-spin text-accent" />
					<span className="text-sm font-medium">
						{__("Loading plugins…")}
					</span>
				</div>
			</div>
		);
	}

	if (isError) {
		const status = getErrorStatus(error);
		let errorTitle: React.ReactNode = __(
			"We couldn't load your plugins. Please try again."
		);
		let errorCopy: React.ReactNode = __(
			"Unable to retrieve installed plugins from the server. Check the server connection and try again."
		);
		let allowRetry = true;

		if (status === 401) {
			errorTitle = __("Authentication required");
			errorCopy = __("Please sign in to view installed plugins.");
			allowRetry = false;
		} else if (status === 403) {
			errorTitle = __("Insufficient permission");
			errorCopy = __(
				"You do not have permission to view installed plugins on this server."
			);
			allowRetry = false;
		} else if (status === 404) {
			errorTitle = __("Endpoint unavailable");
			errorCopy = __(
				"The plugin inventory service is currently unavailable."
			);
			allowRetry = true;
		}

		return (
			<div className="plugins-table-empty">
				<div
					className="plugins-table-empty-icon text-red-500"
					aria-hidden="true"
				>
					<AlertCircle size={18} />
				</div>
				<h3 className="plugins-table-empty-title">{errorTitle}</h3>
				<p className="plugins-table-empty-copy">{errorCopy}</p>
				{allowRetry && onRetry && (
					<div className="mt-4">
						<Button variant="secondary" size="sm" onClick={onRetry}>
							<RefreshCw size={14} className="mr-1.5" />
							{__("Retry")}
						</Button>
					</div>
				)}
			</div>
		);
	}

	if (plugins.length === 0) {
		return (
			<div className="plugins-table-empty">
				<div className="plugins-table-empty-icon" aria-hidden="true">
					<Layers size={18} />
				</div>
				<h3 className="plugins-table-empty-title">
					{type === "must_use"
						? __("No must-use plugins installed.")
						: __("No plugins installed.")}
				</h3>
				<p className="plugins-table-empty-copy">
					{type === "must_use"
						? __(
								"Must-use plugins are placed in content/mu-plugins/ by the server administrator and load automatically on every request."
							)
						: __(
								"Install plugins by uploading a ZIP file or placing extension directories into content/plugins/."
							)}
				</p>
			</div>
		);
	}

	return (
		<div className="plugins-table" dir={direction}>
			{/* Desktop & Tablet Table (Two-Column Layout) */}
			<div className="plugins-table-wrap hidden md:block">
				<table className="plugins-table-grid">
					<thead>
						<tr className="plugins-table-head-row">
							<th className="plugins-table-head-cell w-5/12 min-w-[280px]">
								{__("Plugin")}
							</th>
							<th className="plugins-table-head-cell w-7/12 min-w-[340px]">
								{__("Description")}
							</th>
						</tr>
					</thead>
					<tbody className="divide-y divide-stroke/60 bg-surface">
						{plugins.map((plugin) => {
							const isActive = Boolean(plugin.active);
							const isInvalid =
								!plugin.valid || !plugin.compatible;
							const isBusy =
								busyPluginId === plugin.id &&
								(isActivating || isDeactivating);
							const showIssue = Boolean(
								expandedIssues[plugin.id]
							);
							const hasDiagnostics =
								(plugin.errors && plugin.errors.length > 0) ||
								(plugin.warnings && plugin.warnings.length > 0);

							return (
								<tr
									key={plugin.id}
									className={cn(
										"plugins-table-row transition-colors",
										isActive
											? "border-l-[3px] border-l-accent bg-accent/[0.03] dark:bg-accent/[0.06]"
											: isInvalid
												? "border-l-[3px] border-l-red-500/60 bg-red-500/[0.02]"
												: "border-l-[3px] border-l-transparent hover:bg-surface-alt/40"
									)}
								>
									{/* Column 1: Plugin (Icon + Name + Inline Actions + Version) */}
									<td className="plugins-table-cell align-top py-4 pl-4 pr-3">
										<div className="flex items-start gap-3">
											<div
												className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-stroke/70 bg-surface-alt text-text-muted mt-0.5"
												aria-hidden="true"
											>
												<Package size={16} />
											</div>

											<div className="min-w-0 flex-1 space-y-1">
												<div className="font-semibold text-heading text-sm leading-tight">
													{plugin.name}
												</div>

												{/* Inline Actions directly beneath plugin name */}
												{type === "regular" && (
													<div className="flex flex-wrap items-center gap-2 text-xs">
														{isInvalid ? (
															<>
																<button
																	type="button"
																	onClick={() =>
																		toggleIssue(
																			plugin.id
																		)
																	}
																	className="font-medium text-text-muted hover:text-heading hover:underline cursor-pointer"
																>
																	{showIssue
																		? __(
																				"Hide issue"
																			)
																		: __(
																				"View issue"
																			)}
																</button>
																<span className="text-text-muted/40">
																	•
																</span>
																<button
																	type="button"
																	onClick={() =>
																		setPluginToDelete(
																			plugin
																		)
																	}
																	disabled={
																		isBusy
																	}
																	className="font-medium text-red-600 dark:text-red-400 hover:underline cursor-pointer"
																>
																	{__(
																		"Delete"
																	)}
																</button>
															</>
														) : isActive ? (
															<button
																type="button"
																onClick={() =>
																	handleDeactivate(
																		plugin
																	)
																}
																disabled={
																	isBusy
																}
																className="font-medium text-amber-600 dark:text-amber-400 hover:underline cursor-pointer"
															>
																{isBusy
																	? __(
																			"Deactivating…"
																		)
																	: __(
																			"Deactivate"
																		)}
															</button>
														) : (
															<>
																<button
																	type="button"
																	onClick={() =>
																		handleActivate(
																			plugin
																		)
																	}
																	disabled={
																		isBusy
																	}
																	className="font-medium text-accent hover:underline cursor-pointer"
																>
																	{isBusy
																		? __(
																				"Activating…"
																			)
																		: __(
																				"Activate"
																			)}
																</button>
																<span className="text-text-muted/40">
																	•
																</span>
																<button
																	type="button"
																	onClick={() =>
																		setPluginToDelete(
																			plugin
																		)
																	}
																	disabled={
																		isBusy
																	}
																	className="font-medium text-red-600 dark:text-red-400 hover:underline cursor-pointer"
																>
																	{__(
																		"Delete"
																	)}
																</button>
															</>
														)}
													</div>
												)}

												{/* Version number on separate muted line immediately below actions */}
												<div className="text-[11px] text-text-muted">
													{__("Version")}{" "}
													{plugin.version || "1.0.0"}
												</div>
											</div>
										</div>
									</td>

									{/* Column 2: Description (Description + Linked Author + Resource Links) */}
									<td className="plugins-table-cell align-top py-4 pl-3 pr-4">
										<div className="space-y-1.5">
											{/* Safe, readable plugin description */}
											<p className="text-xs text-text leading-relaxed">
												{plugin.description ||
													__(
														"No description available."
													)}
											</p>

											{/* Attribution & Resource Links */}
											<div className="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-text-muted">
												{plugin.author && (
													<span>
														{__("By")}{" "}
														{plugin.authorUrl ? (
															<a
																href={
																	plugin.authorUrl
																}
																target="_blank"
																rel="noopener noreferrer"
																className="font-medium text-heading hover:text-accent hover:underline"
															>
																{plugin.author}
															</a>
														) : (
															<span className="font-medium text-heading">
																{plugin.author}
															</span>
														)}
													</span>
												)}

												{plugin.author &&
													(plugin.homepage ||
														plugin.documentation) && (
														<span
															className="text-text-muted/40"
															aria-hidden="true"
														>
															•
														</span>
													)}

												{plugin.homepage && (
													<a
														href={plugin.homepage}
														target="_blank"
														rel="noopener noreferrer"
														className="inline-flex items-center gap-1 font-medium text-accent hover:underline"
													>
														<span>
															{__(
																"Visit plugin site"
															)}
														</span>
														<ExternalLink
															size={11}
														/>
													</a>
												)}

												{plugin.homepage &&
													plugin.documentation && (
														<span
															className="text-text-muted/40"
															aria-hidden="true"
														>
															•
														</span>
													)}

												{plugin.documentation && (
													<a
														href={
															plugin.documentation
														}
														target="_blank"
														rel="noopener noreferrer"
														className="inline-flex items-center gap-1 font-medium text-text-muted hover:text-heading hover:underline"
													>
														<span>
															{__("Docs")}
														</span>
														<ExternalLink
															size={11}
														/>
													</a>
												)}
											</div>

											{/* Diagnostics (shown if expanded or on invalid plugins) */}
											{(showIssue ||
												(!plugin.valid &&
													hasDiagnostics)) && (
												<div className="mt-2.5 rounded-lg border border-red-500/20 bg-red-500/5 p-3 text-xs text-red-600 dark:text-red-400 space-y-1">
													{plugin.errors?.map(
														(err, idx) => (
															<div
																key={idx}
																className="flex items-start gap-1.5"
															>
																<AlertCircle
																	size={14}
																	className="shrink-0 mt-0.5"
																/>
																<span>
																	{err}
																</span>
															</div>
														)
													)}
													{plugin.warnings?.map(
														(warn, idx) => (
															<div
																key={idx}
																className="flex items-start gap-1.5 text-amber-600 dark:text-amber-400"
															>
																<AlertTriangle
																	size={14}
																	className="shrink-0 mt-0.5"
																/>
																<span>
																	{warn}
																</span>
															</div>
														)
													)}
												</div>
											)}
										</div>
									</td>
								</tr>
							);
						})}
					</tbody>
				</table>
			</div>

			{/* Mobile Compact Vertical Stack */}
			<div className="divide-y divide-stroke md:hidden border-t border-b border-stroke">
				{plugins.map((plugin) => {
					const isActive = Boolean(plugin.active);
					const isInvalid = !plugin.valid || !plugin.compatible;
					const isBusy =
						busyPluginId === plugin.id &&
						(isActivating || isDeactivating);
					const showIssue = Boolean(expandedIssues[plugin.id]);
					const hasDiagnostics =
						(plugin.errors && plugin.errors.length > 0) ||
						(plugin.warnings && plugin.warnings.length > 0);

					return (
						<div
							key={plugin.id}
							className={cn(
								"p-4 space-y-2.5 transition-colors",
								isActive
									? "border-l-[3px] border-l-accent bg-accent/[0.03] dark:bg-accent/[0.06]"
									: isInvalid
										? "border-l-[3px] border-l-red-500/60 bg-red-500/[0.02]"
										: "border-l-[3px] border-l-transparent"
							)}
						>
							<div className="flex items-start gap-3">
								<div
									className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-stroke/70 bg-surface-alt text-text-muted mt-0.5"
									aria-hidden="true"
								>
									<Package size={16} />
								</div>
								<div className="min-w-0 flex-1 space-y-1">
									<div className="font-semibold text-heading text-sm leading-tight">
										{plugin.name}
									</div>

									{/* Inline Actions (regular plugins only) */}
									{type === "regular" && (
										<div className="flex flex-wrap items-center gap-2 text-xs">
											{isInvalid ? (
												<>
													<button
														type="button"
														onClick={() =>
															toggleIssue(
																plugin.id
															)
														}
														className="font-medium text-text-muted hover:text-heading cursor-pointer"
													>
														{showIssue
															? __("Hide issue")
															: __("View issue")}
													</button>
													<span className="text-text-muted/40">
														•
													</span>
													<button
														type="button"
														onClick={() =>
															setPluginToDelete(
																plugin
															)
														}
														disabled={isBusy}
														className="font-medium text-red-600 dark:text-red-400 cursor-pointer"
													>
														{__("Delete")}
													</button>
												</>
											) : isActive ? (
												<button
													type="button"
													onClick={() =>
														handleDeactivate(plugin)
													}
													disabled={isBusy}
													className="font-medium text-amber-600 dark:text-amber-400 cursor-pointer"
												>
													{isBusy
														? __("Deactivating…")
														: __("Deactivate")}
												</button>
											) : (
												<>
													<button
														type="button"
														onClick={() =>
															handleActivate(
																plugin
															)
														}
														disabled={isBusy}
														className="font-medium text-accent cursor-pointer"
													>
														{isBusy
															? __("Activating…")
															: __("Activate")}
													</button>
													<span className="text-text-muted/40">
														•
													</span>
													<button
														type="button"
														onClick={() =>
															setPluginToDelete(
																plugin
															)
														}
														disabled={isBusy}
														className="font-medium text-red-600 dark:text-red-400 cursor-pointer"
													>
														{__("Delete")}
													</button>
												</>
											)}
										</div>
									)}

									{/* Version on separate line below actions/name */}
									<div className="text-[11px] text-text-muted">
										{__("Version")}{" "}
										{plugin.version || "1.0.0"}
									</div>
								</div>
							</div>

							<p className="text-xs text-text leading-relaxed">
								{plugin.description ||
									__("No description available.")}
							</p>

							{/* Attribution & Resource Links */}
							{(plugin.author ||
								plugin.homepage ||
								plugin.documentation) && (
								<div className="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-text-muted pt-0.5">
									{plugin.author && (
										<span>
											{__("By")}{" "}
											{plugin.authorUrl ? (
												<a
													href={plugin.authorUrl}
													target="_blank"
													rel="noopener noreferrer"
													className="font-medium text-heading hover:text-accent hover:underline"
												>
													{plugin.author}
												</a>
											) : (
												<span className="font-medium text-heading">
													{plugin.author}
												</span>
											)}
										</span>
									)}

									{plugin.author &&
										(plugin.homepage ||
											plugin.documentation) && (
											<span
												className="text-text-muted/40"
												aria-hidden="true"
											>
												•
											</span>
										)}

									{plugin.homepage && (
										<a
											href={plugin.homepage}
											target="_blank"
											rel="noopener noreferrer"
											className="inline-flex items-center gap-1 font-medium text-accent hover:underline"
										>
											<span>
												{__("Visit plugin site")}
											</span>
											<ExternalLink size={11} />
										</a>
									)}

									{plugin.homepage &&
										plugin.documentation && (
											<span
												className="text-text-muted/40"
												aria-hidden="true"
											>
												•
											</span>
										)}

									{plugin.documentation && (
										<a
											href={plugin.documentation}
											target="_blank"
											rel="noopener noreferrer"
											className="inline-flex items-center gap-1 font-medium text-text-muted hover:text-heading hover:underline"
										>
											<span>{__("Docs")}</span>
											<ExternalLink size={11} />
										</a>
									)}
								</div>
							)}

							{/* Diagnostics */}
							{(showIssue ||
								(!plugin.valid && hasDiagnostics)) && (
								<div className="rounded-lg border border-red-500/20 bg-red-500/5 p-3 text-xs text-red-600 dark:text-red-400 space-y-1">
									{plugin.errors?.map((err, idx) => (
										<div
											key={idx}
											className="flex items-start gap-1.5"
										>
											<AlertCircle
												size={14}
												className="shrink-0 mt-0.5"
											/>
											<span>{err}</span>
										</div>
									))}
								</div>
							)}
						</div>
					);
				})}
			</div>

			{/* Safe Plugin Deletion Confirmation Dialog */}
			<ConfirmDialog
				open={Boolean(pluginToDelete)}
				onClose={() => setPluginToDelete(null)}
				title={__("Delete Plugin")}
				description={
					pluginToDelete
						? sprintf(
								// translators: %s: Plugin display name.
								__(
									'Are you sure you want to delete "%s" and its files from the server? This action cannot be undone.'
								),
								pluginToDelete.name
							)
						: ""
				}
				confirmText={__("Delete Plugin")}
				cancelText={__("Cancel")}
				confirmVariant="danger"
				loading={isDeleting}
				onConfirm={handleConfirmDelete}
			/>
		</div>
	);
}

function sprintf(format: string, ...args: string[]): string {
	let index = 0;
	return format.replace(/%s|%1\$s/g, () => args[index++] ?? "");
}

export default InstalledPluginsTable;
