import { useMemo, useState, type SubmitEvent } from "react";
import { AlertCircle, CheckCircle2, Trash2, Zap } from "lucide-react";

import {
	Button,
	ConfirmDialog,
	Input,
	Select,
	type SelectOption,
} from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn, formatByteSize } from "@/utils";

import type { CacheConfigurationPayload, CacheStatusPayload } from "@/api";
import type { StateCardProps, StateCardVariant, StatCardProps } from "../types";

export interface PerformanceTabProps {
	status?: CacheStatusPayload | null;
	errorMessage?: string | null;
	isLoading: boolean;
	isSaving: boolean;
	isPurging: boolean;
	onSave: (
		payload: CacheConfigurationPayload
	) => Promise<CacheStatusPayload | undefined>;
	onPurge: () => Promise<void>;
}

function StateCard({
	icon: Icon,
	title,
	description,
	variant = "info",
}: StateCardProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const styles: Record<StateCardVariant, string> = {
		info: "settings-performance-state-card-info",
		success: "settings-performance-state-card-success",
		error: "settings-performance-state-card-error",
	};

	return (
		<div className={cn("settings-performance-state-card", styles[variant])}>
			<div
				dir={direction}
				className="settings-performance-state-card-layout"
			>
				<Icon
					size={18}
					className="settings-performance-state-card-icon"
				/>
				<div className="settings-performance-state-card-content">
					<h3 className="settings-performance-state-card-title">
						{title}
					</h3>
					<p className="settings-performance-state-card-text">
						{description}
					</p>
				</div>
			</div>
		</div>
	);
}

function StatCard({ label, value, valueDirection = "auto" }: StatCardProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";

	return (
		<div dir={direction} className="settings-stat-card">
			<p className="settings-stat-label">{label}</p>
			<p className="settings-stat-value">
				{"ltr" === valueDirection ? (
					<span className="preserve-ltr-value inline-block">
						{value}
					</span>
				) : "rtl" === valueDirection ? (
					<span dir="rtl" className="inline-block">
						{value}
					</span>
				) : (
					<bdi dir="auto">{value}</bdi>
				)}
			</p>
		</div>
	);
}

const REDIRECT_TTL_PRESETS: SelectOption[] = [
	{ value: "3600", label: __("1 Hour (3,600s) — Default") },
	{ value: "21600", label: __("6 Hours (21,600s)") },
	{ value: "86400", label: __("24 Hours (86,400s)") },
	{ value: "604800", label: __("7 Days (604,800s)") },
	{ value: "custom", label: __("Custom Duration...") },
];

const NEGATIVE_TTL_PRESETS: SelectOption[] = [
	{ value: "60", label: __("1 Minute (60s) — Default") },
	{ value: "180", label: __("3 Minutes (180s)") },
	{ value: "300", label: __("5 Minutes (300s)") },
	{ value: "900", label: __("15 Minutes (900s)") },
	{ value: "custom", label: __("Custom Duration...") },
];

export function PerformanceTab({
	status,
	errorMessage,
	isLoading,
	isSaving,
	isPurging,
	onSave,
	onPurge,
}: PerformanceTabProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";

	const configuredDriver = status?.configuredDriver || "auto";
	const defaultTtl = status?.defaultTtl ?? 3600;
	const negativeTtl = status?.negativeTtl ?? 60;

	const [driver, setDriver] = useState<string>(configuredDriver);
	const [selectedTtlPreset, setSelectedTtlPreset] = useState<string>(() => {
		const str = String(defaultTtl);
		return ["3600", "21600", "86400", "604800"].includes(str)
			? str
			: "custom";
	});
	const [customTtl, setCustomTtl] = useState<string>(String(defaultTtl));

	const [selectedNegativePreset, setSelectedNegativePreset] =
		useState<string>(() => {
			const str = String(negativeTtl);
			return ["60", "180", "300", "900"].includes(str) ? str : "custom";
		});
	const [customNegativeTtl, setCustomNegativeTtl] = useState<string>(
		String(negativeTtl)
	);

	const isRedisAvailable = Boolean(status?.redis?.available);
	const isApcuAvailable = Boolean(status?.apcu?.available);
	const isFileAvailable = status?.file
		? Boolean(status.file.available ?? status.file.writable)
		: true;

	const driverOptions: SelectOption[] = useMemo(
		() => [
			{
				value: "auto",
				label: __("Automatic (Recommended)"),
			},
			{
				value: "redis",
				label: isRedisAvailable
					? __("Redis (In-Memory Cache)")
					: __("Redis (Unavailable — Server Unreachable)"),
				disabled: !isRedisAvailable,
			},
			{
				value: "apcu",
				label: isApcuAvailable
					? __("APCu (PHP Shared Memory)")
					: __("APCu (Unavailable — Extension Not Loaded)"),
				disabled: !isApcuAvailable,
			},
			{
				value: "file",
				label: isFileAvailable
					? __("Filesystem (content/cache)")
					: __("Filesystem (Unavailable — Directory Not Writable)"),
				disabled: !isFileAvailable,
			},
			{
				value: "null",
				label: __("Disabled (Direct Database Queries)"),
			},
		],
		[isRedisAvailable, isApcuAvailable, isFileAvailable]
	);

	const activeDriverLabel = (() => {
		switch (status?.activeDriver) {
			case "redis":
				return __("Redis");
			case "apcu":
				return __("APCu");
			case "file":
				return __("Filesystem");
			default:
				return __("Direct Database");
		}
	})();

	const isOperational =
		Boolean(status?.enabled) &&
		Boolean(status?.activeDriver) &&
		status?.activeDriver !== "none" &&
		status?.activeDriver !== "null";
	const isFallback = status?.status === "fallback";

	const effectiveDefaultTtl =
		"custom" === selectedTtlPreset
			? Math.max(0, parseInt(customTtl, 10) || 3600)
			: parseInt(selectedTtlPreset, 10) || 3600;

	const effectiveNegativeTtl =
		"custom" === selectedNegativePreset
			? Math.max(0, parseInt(customNegativeTtl, 10) || 60)
			: parseInt(selectedNegativePreset, 10) || 60;

	const hasUnsavedChanges =
		driver !== configuredDriver ||
		effectiveDefaultTtl !== defaultTtl ||
		effectiveNegativeTtl !== negativeTtl;

	const [isPurgeModalOpen, setIsPurgeModalOpen] = useState(false);

	const handleConfirmPurge = async () => {
		await onPurge();
		setIsPurgeModalOpen(false);
	};

	const handleReset = () => {
		setDriver(configuredDriver);
		const strDefault = String(defaultTtl);
		setSelectedTtlPreset(
			["3600", "21600", "86400", "604800"].includes(strDefault)
				? strDefault
				: "custom"
		);
		setCustomTtl(strDefault);

		const strNegative = String(negativeTtl);
		setSelectedNegativePreset(
			["60", "180", "300", "900"].includes(strNegative)
				? strNegative
				: "custom"
		);
		setCustomNegativeTtl(strNegative);
	};

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		const enabled = "null" !== driver && "none" !== driver;
		await onSave({
			enabled,
			driver,
			defaultTtl: effectiveDefaultTtl,
			negativeTtl: effectiveNegativeTtl,
		});
	};

	return (
		<div className="settings-performance">
			{/* Intro & Overview Header */}
			<div className="settings-performance-intro">
				<div
					dir={direction}
					className="settings-performance-intro-layout"
				>
					<div className="settings-performance-intro-copy">
						<div
							dir={direction}
							className="settings-performance-intro-title-row"
						>
							<div className="settings-performance-intro-icon">
								<Zap size={18} />
							</div>
							<div>
								<h2 className="block w-full text-lg font-semibold text-heading pb-4 mb-4 border-b border-stroke">
									{__("Performance & Caching")}
								</h2>
								<p className="settings-group-description mb-0!">
									{__(
										"Configure object caching backends and Time-to-Live (TTL) policies to optimize link redirection latency and throughput."
									)}
								</p>
							</div>
						</div>
					</div>

					<Button
						size="sm"
						variant="danger"
						className="settings-performance-purge-button"
						onClick={() => setIsPurgeModalOpen(true)}
						loading={isPurging}
						icon={Trash2}
						disabled={isLoading || isSaving}
					>
						{__("Purge Cache")}
					</Button>
				</div>
			</div>

			{/* Health & Metrics Stats Grid */}
			<div className="settings-stat-grid">
				<StatCard
					label={__("Active Engine")}
					value={activeDriverLabel}
				/>
				<StatCard
					label={__("Cache Footprint")}
					value={formatByteSize(status?.sizeBytes, __("0 B"))}
					valueDirection="ltr"
				/>
				<StatCard
					label={__("Default TTL")}
					value={`${String(defaultTtl)}s`}
					valueDirection="ltr"
				/>
			</div>

			{errorMessage && (
				<StateCard
					icon={AlertCircle}
					title={__("Cache status unavailable")}
					description={errorMessage}
					variant="error"
				/>
			)}

			{status && !errorMessage && (
				<StateCard
					icon={
						isOperational && !isFallback
							? CheckCircle2
							: isFallback
								? AlertCircle
								: Zap
					}
					title={
						isOperational && !isFallback
							? __("Object cache is operational")
							: isFallback
								? __("Fallback cache driver active")
								: __("Object cache is disabled")
					}
					description={
						isOperational && !isFallback
							? sprintf(
									__(
										"Object caching is active and serving link redirects via the %s backend."
									),
									activeDriverLabel
								)
							: isFallback
								? sprintf(
										__(
											"The preferred cache driver is currently unavailable. PeakURL has automatically fallen back to %s."
										),
										activeDriverLabel
									)
								: __(
										"Object caching is disabled. Link lookups and redirects are served directly from the database."
									)
					}
					variant={isOperational && !isFallback ? "success" : "info"}
				/>
			)}

			{/* Configuration Form */}
			<form onSubmit={handleSubmit} className="settings-form">
				<section className="settings-fieldset">
					<h2 className="settings-legend">
						{__("Cache Engine Settings")}
					</h2>
					<hr className="settings-separator" />
					<p className="settings-group-description mb-0!">
						{__(
							"Select your preferred caching backend. PeakURL automatically selects the highest performance operational backend if the preferred driver is unavailable."
						)}
					</p>

					<div className="settings-grid">
						<div>
							<label className="settings-section-label">
								{__("Preferred Cache Driver")}
							</label>
							<Select
								value={driver}
								onChange={(val) => setDriver(String(val))}
								options={driverOptions}
								ariaLabel={__("Preferred Cache Driver")}
								disabled={isSaving}
							/>
							<p className="settings-group-description settings-performance-help-text">
								{__(
									"PeakURL prioritizes Redis if configured, APCu if loaded, and Filesystem storage as the universal fallback."
								)}
							</p>
						</div>

						<div>
							<label className="settings-section-label">
								{__("Redirect Cache TTL")}
							</label>
							<Select
								value={selectedTtlPreset}
								onChange={(val) =>
									setSelectedTtlPreset(String(val))
								}
								options={REDIRECT_TTL_PRESETS}
								ariaLabel={__("Redirect Cache TTL")}
								disabled={isSaving}
							/>
							<p className="settings-group-description settings-performance-help-text">
								{__(
									"Time-to-Live duration for caching active short URL redirect destinations."
								)}
							</p>
						</div>

						{"custom" === selectedTtlPreset ? (
							<div>
								<Input
									type="number"
									min={1}
									max={31536000}
									label={__("Custom Redirect TTL (seconds)")}
									value={customTtl}
									onChange={(e) =>
										setCustomTtl(e.target.value)
									}
									helperText={__(
										"Enter the redirect destination cache lifetime in seconds."
									)}
									disabled={isSaving}
								/>
							</div>
						) : null}

						<div>
							<label className="settings-section-label">
								{__("Negative Lookup (404) TTL")}
							</label>
							<Select
								value={selectedNegativePreset}
								onChange={(val) =>
									setSelectedNegativePreset(String(val))
								}
								options={NEGATIVE_TTL_PRESETS}
								ariaLabel={__("Negative Lookup (404) TTL")}
								disabled={isSaving}
							/>
							<p className="settings-group-description settings-performance-help-text">
								{__(
									"Time-to-Live duration for caching non-existent link lookups to mitigate database load from automated scans."
								)}
							</p>
						</div>

						{"custom" === selectedNegativePreset ? (
							<div>
								<Input
									type="number"
									min={1}
									max={86400}
									label={__("Custom Negative TTL (seconds)")}
									value={customNegativeTtl}
									onChange={(e) =>
										setCustomNegativeTtl(e.target.value)
									}
									helperText={__(
										"Enter the 404 negative lookup cache lifetime in seconds."
									)}
									disabled={isSaving}
								/>
							</div>
						) : null}
					</div>

					<div
						dir={direction}
						className="settings-general-actions settings-general-actions-end"
					>
						{hasUnsavedChanges ? (
							<Button
								type="button"
								variant="secondary"
								onClick={handleReset}
								disabled={isSaving}
							>
								{__("Reset")}
							</Button>
						) : null}

						<Button
							type="submit"
							variant="primary"
							loading={isSaving}
							disabled={!hasUnsavedChanges || isSaving}
						>
							{__("Save Performance Settings")}
						</Button>
					</div>
				</section>
			</form>

			<ConfirmDialog
				open={isPurgeModalOpen}
				onClose={() => setIsPurgeModalOpen(false)}
				title={__("Purge Object Cache")}
				description={__(
					"Are you sure you want to purge the object cache? All cached redirect records, in-memory objects, and temporary cache files will be immediately invalidated. Subsequent requests will query the database to rebuild the cache."
				)}
				confirmText={__("Purge Cache")}
				cancelText={__("Cancel")}
				confirmVariant="danger"
				loading={isPurging}
				onConfirm={handleConfirmPurge}
			/>
		</div>
	);
}

export default PerformanceTab;
