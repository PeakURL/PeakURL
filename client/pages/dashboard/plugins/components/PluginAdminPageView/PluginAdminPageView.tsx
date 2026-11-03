import { useMemo, useState } from "react";
import { Link, useParams } from "react-router";
import {
	ArrowLeft,
	Save,
	RefreshCw,
	Sliders,
	AlertTriangle,
} from "lucide-react";

import {
	Button,
	ConfirmDialog,
	Input,
	PageHeader,
	TextArea,
	useNotification,
} from "@/components";
import { useAdminAccess, usePluginAssets } from "@/hooks";
import { __ } from "@/i18n";
import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import {
	useDispatchPluginActionMutation,
	useGetPluginPageQuery,
	useUpdatePluginSettingsMutation,
} from "@/state/slices/api/system";
import type { PluginPageActionDescriptor } from "@/api/types/plugins";

export default function PluginAdminPageView() {
	const { slug, pageId } = useParams<{ slug: string; pageId: string }>();
	const notification = useNotification();
	const { hasCapability } = useAdminAccess();

	const {
		data: response,
		isLoading,
		isError,
		error,
		refetch,
	} = useGetPluginPageQuery(
		{ slug: slug ?? "", pageId: pageId ?? "" },
		{ skip: !slug || !pageId }
	);

	const [updateSettings, { isLoading: isSaving }] =
		useUpdatePluginSettingsMutation();
	const [dispatchAction, { isLoading: isDispatching }] =
		useDispatchPluginActionMutation();

	const page = response?.data;
	usePluginAssets(page?.assets, {
		pluginSlug: slug ?? "",
		pageId: pageId ?? "",
	});

	const [userOverrides, setUserOverrides] = useState<Record<string, unknown>>(
		{}
	);
	const [activeActionId, setActiveActionId] = useState<string | null>(null);
	const [pendingConfirmAction, setPendingConfirmAction] =
		useState<PluginPageActionDescriptor | null>(null);

	// Synchronize form state derived from page descriptor and user inputs
	const formValues = useMemo(() => {
		const initial: Record<string, unknown> = {};
		if (page?.values) {
			Object.assign(initial, page.values);
		} else if (page?.sections) {
			page.sections.forEach((sec) => {
				sec.fields.forEach((field) => {
					initial[field.key] = field.default ?? "";
				});
			});
		}
		return { ...initial, ...userOverrides };
	}, [page, userOverrides]);

	const handleFieldChange = (key: string, value: unknown) => {
		setUserOverrides((prev) => ({
			...prev,
			[key]: value,
		}));
	};

	const handleSave = async (e?: React.FormEvent) => {
		if (e) {
			e.preventDefault();
		}
		if (!slug) {
			return;
		}

		try {
			await updateSettings({ slug, settings: formValues }).unwrap();
			notification.success(__("Settings saved successfully."));
		} catch (err: unknown) {
			const apiErr = err as { data?: { message?: string } };
			notification.error(
				apiErr?.data?.message ?? __("Failed to save settings.")
			);
		}
	};

	const executeAction = async (actionId: string) => {
		if (!slug) {
			return;
		}

		setActiveActionId(actionId);
		try {
			const res = await dispatchAction({ slug, actionId }).unwrap();
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
				apiErr?.data?.message ?? __("Failed to execute action.")
			);
		} finally {
			setActiveActionId(null);
			setPendingConfirmAction(null);
		}
	};

	const handleActionClick = (act: PluginPageActionDescriptor) => {
		if (act.variant === "danger") {
			setPendingConfirmAction(act);
			return;
		}
		void executeAction(act.id);
	};

	if (isLoading) {
		return (
			<div className="p-6 flex items-center justify-center min-h-75">
				<div className="flex flex-col items-center gap-3 text-secondary">
					<RefreshCw className="w-6 h-6 animate-spin text-accent" />
					<p className="text-sm">{__("Loading plugin page...")}</p>
				</div>
			</div>
		);
	}

	if (isError || !page) {
		const apiErr = error as { data?: { message?: string } };
		return (
			<div className="p-6 max-w-4xl mx-auto space-y-6">
				<Link
					to="/dashboard/plugins"
					className="inline-flex items-center gap-1.5 text-xs text-secondary hover:text-primary transition-colors"
				>
					<ArrowLeft className="w-3.5 h-3.5" />
					<span>{__("Back to Plugins")}</span>
				</Link>

				<div className="p-6 rounded-xl border border-destructive/20 bg-destructive/5 space-y-4 text-center">
					<div className="w-12 h-12 rounded-full bg-destructive/10 text-destructive flex items-center justify-center mx-auto">
						<AlertTriangle className="w-6 h-6" />
					</div>
					<div className="space-y-1">
						<h2 className="text-base font-semibold text-primary">
							{__("Could not load plugin page")}
						</h2>
						<p className="text-sm text-secondary">
							{apiErr?.data?.message ??
								__(
									"The requested plugin page was not found or is not currently active."
								)}
						</p>
					</div>
					<div className="pt-2 flex justify-center gap-3">
						<Button variant="secondary" onClick={() => refetch()}>
							<RefreshCw className="w-4 h-4 mr-1.5" />
							{__("Retry")}
						</Button>
						<Link to="/dashboard/plugins">
							<Button variant="outline">
								{__("Return to Plugins")}
							</Button>
						</Link>
					</div>
				</div>
			</div>
		);
	}

	if (page.capability && !hasCapability(page.capability)) {
		return (
			<div className="p-6 max-w-4xl mx-auto space-y-6">
				<Link
					to="/dashboard"
					className="inline-flex items-center gap-1.5 text-xs text-secondary hover:text-primary transition-colors"
				>
					<ArrowLeft className="w-3.5 h-3.5" />
					<span>{__("Back to Dashboard")}</span>
				</Link>

				<div className="p-6 rounded-xl border border-destructive/20 bg-destructive/5 space-y-4 text-center">
					<div className="w-12 h-12 rounded-full bg-destructive/10 text-destructive flex items-center justify-center mx-auto">
						<AlertTriangle className="w-6 h-6" />
					</div>
					<div className="space-y-1">
						<h2 className="text-base font-semibold text-primary">
							{__("Access Denied")}
						</h2>
						<p className="text-sm text-secondary">
							{__(
								"You do not possess the required capability to view or manage this plugin page."
							)}
						</p>
					</div>
					<div className="pt-2 flex justify-center">
						<Link to="/dashboard">
							<Button variant="outline">
								{__("Return to Dashboard")}
							</Button>
						</Link>
					</div>
				</div>
			</div>
		);
	}

	const sections = page.sections || [];
	const hasFields = sections.some((s) => s.fields && s.fields.length > 0);
	const actions = page.actions || [];

	return (
		<div className="p-6 max-w-5xl mx-auto space-y-6">
			{/* Breadcrumb back navigation */}
			<div>
				<Link
					to="/dashboard/plugins"
					className="inline-flex items-center gap-1.5 text-xs font-medium text-secondary hover:text-primary transition-colors"
				>
					<ArrowLeft className="w-3.5 h-3.5" />
					<span>{__("Back to Plugins")}</span>
				</Link>
			</div>

			{/* Page Header */}
			<PageHeader
				icon={dashboardPageIcons.plugins}
				title={page.title}
				description={__(
					"Manage settings and extension actions for this plugin."
				)}
				actions={
					<div className="flex items-center gap-2">
						{actions.map((act) => (
							<Button
								key={act.id}
								variant={
									act.variant === "danger"
										? "danger"
										: "secondary"
								}
								disabled={
									isDispatching && activeActionId === act.id
								}
								onClick={() => handleActionClick(act)}
							>
								{isDispatching && activeActionId === act.id && (
									<RefreshCw className="w-3.5 h-3.5 mr-1.5 animate-spin" />
								)}
								{act.label}
							</Button>
						))}

						{hasFields && (
							<Button
								variant="primary"
								type="button"
								disabled={isSaving}
								onClick={() => handleSave()}
							>
								{isSaving ? (
									<RefreshCw className="w-3.5 h-3.5 mr-1.5 animate-spin" />
								) : (
									<Save className="w-3.5 h-3.5 mr-1.5" />
								)}
								{__("Save Settings")}
							</Button>
						)}
					</div>
				}
			/>

			{/* Declarative sections form */}
			{hasFields ? (
				<form onSubmit={handleSave} className="space-y-6">
					{sections.map((section) => (
						<div
							key={section.id}
							className="p-6 rounded-xl border border-default bg-surface shadow-xs space-y-5"
						>
							<div className="space-y-1 border-b border-default pb-3">
								<h3 className="text-sm font-semibold text-primary">
									{section.title}
								</h3>
								{section.description && (
									<p className="text-xs text-secondary">
										{section.description}
									</p>
								)}
							</div>

							<div className="space-y-4">
								{section.fields.map((field) => {
									const val =
										formValues[field.key] ??
										field.default ??
										"";

									if (field.type === "switch") {
										const checked = Boolean(val);
										return (
											<div
												key={field.key}
												className="flex items-center justify-between p-3.5 rounded-lg border border-default bg-surface-subtle"
											>
												<div className="space-y-0.5">
													<label
														htmlFor={`field-${field.key}`}
														className="text-xs font-medium text-primary cursor-pointer select-none"
													>
														{field.label}
													</label>
													{field.description && (
														<p className="text-[11px] text-secondary">
															{field.description}
														</p>
													)}
												</div>
												<input
													id={`field-${field.key}`}
													type="checkbox"
													checked={checked}
													onChange={(e) =>
														handleFieldChange(
															field.key,
															e.target.checked
														)
													}
													className="cursor-pointer h-4 w-4 rounded border-default text-accent focus:ring-accent"
												/>
											</div>
										);
									}

									if (field.type === "textarea") {
										return (
											<div
												key={field.key}
												className="space-y-1.5"
											>
												<label
													htmlFor={`field-${field.key}`}
													className="block text-xs font-medium text-primary"
												>
													{field.label}
												</label>
												<TextArea
													id={`field-${field.key}`}
													value={String(val)}
													placeholder={
														field.placeholder
													}
													onChange={(e) =>
														handleFieldChange(
															field.key,
															e.target.value
														)
													}
												/>
												{field.description && (
													<p className="text-[11px] text-secondary">
														{field.description}
													</p>
												)}
											</div>
										);
									}

									if (
										field.type === "select" &&
										field.options
									) {
										return (
											<div
												key={field.key}
												className="space-y-1.5"
											>
												<label
													htmlFor={`field-${field.key}`}
													className="block text-xs font-medium text-primary"
												>
													{field.label}
												</label>
												<select
													id={`field-${field.key}`}
													value={String(val)}
													onChange={(e) =>
														handleFieldChange(
															field.key,
															e.target.value
														)
													}
													className="w-full text-xs rounded-lg border border-default bg-surface px-3 py-2 text-primary focus:outline-hidden focus:ring-2 focus:ring-accent"
												>
													{field.options.map(
														(opt) => (
															<option
																key={opt.value}
																value={
																	opt.value
																}
															>
																{opt.label}
															</option>
														)
													)}
												</select>
												{field.description && (
													<p className="text-[11px] text-secondary">
														{field.description}
													</p>
												)}
											</div>
										);
									}

									return (
										<div
											key={field.key}
											className="space-y-1.5"
										>
											<label
												htmlFor={`field-${field.key}`}
												className="block text-xs font-medium text-primary"
											>
												{field.label}
											</label>
											<Input
												id={`field-${field.key}`}
												type={
													field.type === "number"
														? "number"
														: "text"
												}
												value={String(val)}
												placeholder={field.placeholder}
												onChange={(e) =>
													handleFieldChange(
														field.key,
														e.target.value
													)
												}
											/>
											{field.description && (
												<p className="text-[11px] text-secondary">
													{field.description}
												</p>
											)}
										</div>
									);
								})}
							</div>
						</div>
					))}

					<div className="flex justify-end pt-2">
						<Button
							variant="primary"
							type="submit"
							disabled={isSaving}
							className="px-5"
						>
							{isSaving ? (
								<RefreshCw className="w-3.5 h-3.5 mr-1.5 animate-spin" />
							) : (
								<Save className="w-3.5 h-3.5 mr-1.5" />
							)}
							{__("Save Settings")}
						</Button>
					</div>
				</form>
			) : (
				<div className="p-8 rounded-xl border border-default bg-surface text-center space-y-3">
					<div className="w-10 h-10 rounded-full bg-accent/10 text-accent flex items-center justify-center mx-auto">
						<Sliders className="w-5 h-5" />
					</div>
					<h3 className="text-sm font-semibold text-primary">
						{__("Plugin Actions and Overview")}
					</h3>
					<p className="text-xs text-secondary max-w-md mx-auto">
						{__(
							"This plugin does not declare configurable settings fields. Use the available actions above to interact with this extension."
						)}
					</p>
				</div>
			)}

			{pendingConfirmAction && (
				<ConfirmDialog
					open={Boolean(pendingConfirmAction)}
					onClose={() => setPendingConfirmAction(null)}
					title={pendingConfirmAction.label}
					description={__(
						"Are you sure you want to execute this action? This operation will affect plugin runtime state."
					)}
					confirmText={__("Proceed")}
					cancelText={__("Cancel")}
					confirmVariant="danger"
					loading={activeActionId === pendingConfirmAction.id}
					onConfirm={() => executeAction(pendingConfirmAction.id)}
				/>
			)}
		</div>
	);
}
