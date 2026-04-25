import type { SubmitEvent } from "react";
import { useState } from "react";
import { Button, Input, ReadOnlyValueBlock } from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn, formatByteSize, formatDateTimeValue } from "@/utils";
import {
	AlertCircle,
	CheckCircle2,
	CloudDownload,
	MapPin,
	RefreshCcw,
} from "lucide-react";
import type {
	LocationDataStatus,
	LocationDataTabProps,
	StateCardProps,
	StateCardVariant,
	StatCardProps,
} from "../types";

function StateCard({
	icon: Icon,
	title,
	description,
	variant = "info",
}: StateCardProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const styles: Record<StateCardVariant, string> = {
		info: "settings-location-state-card-info",
		success: "settings-location-state-card-success",
		error: "settings-location-state-card-error",
	};

	return (
		<div className={cn("settings-location-state-card", styles[variant])}>
			<div
				dir={direction}
				className="settings-location-state-card-layout"
			>
				<Icon size={18} className="settings-location-state-card-icon" />
				<div className="settings-location-state-card-content">
					<h3 className="settings-location-state-card-title">
						{title}
					</h3>
					<p className="settings-location-state-card-text">
						{description}
					</p>
				</div>
			</div>
		</div>
	);
}

function LocationDataTab({
	status,
	errorMessage,
	isLoading,
	isSaving,
	isDownloading,
	onSave,
	onDownload,
}: LocationDataTabProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const [accountIdInput, setAccountIdInput] = useState<string | null>(null);
	const [licenseKey, setLicenseKey] = useState("");
	const [isEditingCredentials, setIsEditingCredentials] = useState(false);
	const [savedStatusOverride, setSavedStatusOverride] =
		useState<Partial<LocationDataStatus> | null>(null);
	const effectiveStatus = status?.credentialsConfigured
		? status
		: savedStatusOverride
			? { ...(status || {}), ...savedStatusOverride }
			: status;
	const accountId =
		null === accountIdInput
			? effectiveStatus?.accountId || ""
			: accountIdInput;
	const hasSavedCredentials = Boolean(effectiveStatus?.credentialsConfigured);

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		try {
			const nextStatus = await onSave({
				accountId: accountId.trim(),
				licenseKey: licenseKey.trim(),
			});
			if (nextStatus) {
				setSavedStatusOverride(nextStatus);
			}
			setIsEditingCredentials(false);
			setAccountIdInput(null);
			setLicenseKey("");
		} catch {}
	};

	const isReady = Boolean(effectiveStatus?.locationAnalyticsReady);

	return (
		<div className="settings-location">
			<div className="settings-location-intro">
				<div dir={direction} className="settings-location-intro-layout">
					<div className="settings-location-intro-copy">
						<div
							dir={direction}
							className="settings-location-intro-title-row"
						>
							<div className="settings-location-intro-icon">
								<MapPin size={18} />
							</div>
							<div>
								<h2 className="settings-location-intro-title">
									{__("Location Data")}
								</h2>
								<p className="settings-location-intro-description">
									{__(
										"Enable country and city analytics with a local MaxMind GeoLite2 City database stored in your persistent content folder."
									)}
								</p>
							</div>
						</div>
					</div>

					<Button
						size="sm"
						className="settings-location-download-button"
						onClick={onDownload}
						loading={isDownloading}
						icon={CloudDownload}
						disabled={
							isLoading ||
							isSaving ||
							!effectiveStatus?.credentialsConfigured ||
							Boolean(
								effectiveStatus &&
								!effectiveStatus.canManageFromDashboard
							)
						}
					>
						{isReady
							? __("Update Database")
							: __("Download Database")}
					</Button>
				</div>
			</div>

			<div className="settings-stat-grid">
				<StatCard
					label={__("Status")}
					value={isReady ? __("Ready") : __("Setup Required")}
				/>
				<StatCard
					label={__("Database Updated")}
					value={formatDateTimeValue(
						effectiveStatus?.lastDownloadedAt ||
							effectiveStatus?.databaseUpdatedAt,
						__("Never")
					)}
					valueDirection="ltr"
				/>
				<StatCard
					label={__("Database Size")}
					value={formatByteSize(
						effectiveStatus?.databaseSizeBytes,
						__("Not available")
					)}
					valueDirection="ltr"
				/>
			</div>

			{errorMessage && (
				<StateCard
					icon={AlertCircle}
					title={__("Location data unavailable")}
					description={errorMessage}
					variant="error"
				/>
			)}

			{effectiveStatus && !errorMessage && (
				<StateCard
					icon={isReady ? CheckCircle2 : RefreshCcw}
					title={
						isReady
							? __("Location analytics is enabled")
							: __("Location analytics is disabled")
					}
					description={
						isReady
							? __(
									"PeakURL is using the local GeoLite2 City database for click locations."
								)
							: __(
									"Save your MaxMind credentials, then download the GeoLite2 City database to enable visitor country and city analytics."
								)
					}
					variant={isReady ? "success" : "info"}
				/>
			)}

			{effectiveStatus?.manageDisabledReason && (
				<StateCard
					icon={AlertCircle}
					title={__("Dashboard management unavailable")}
					description={effectiveStatus.manageDisabledReason}
					variant="info"
				/>
			)}

			<div className="settings-location-credentials-card">
				<div className="settings-location-credentials-header">
					<h3 className="settings-location-credentials-title">
						{__("MaxMind Credentials")}
					</h3>
					<p className="settings-location-credentials-description">
						{__(
							"PeakURL stores these values encrypted in the database so it can refresh the GeoLite2 City database later without asking again."
						)}
					</p>
				</div>

				{hasSavedCredentials && !isEditingCredentials ? (
					<div className="settings-location-credentials-view">
						<div className="settings-location-credentials-panel">
							<div className="settings-location-credentials-grid">
								<div>
									<p className="settings-location-credentials-label">
										{__("Account ID")}
									</p>
									<ReadOnlyValueBlock
										value={effectiveStatus?.accountId}
										className="settings-location-credentials-value"
										monospace={false}
										valueClassName="settings-location-credentials-value-text"
									/>
								</div>
								<div>
									<p className="settings-location-credentials-label">
										{__("License Key")}
									</p>
									<ReadOnlyValueBlock
										value={effectiveStatus?.licenseKeyHint}
										className="settings-location-credentials-value"
										monospace={false}
										valueClassName="settings-location-credentials-value-text"
									/>
								</div>
							</div>
						</div>
						<div
							dir={direction}
							className="settings-location-actions"
						>
							<Button
								type="button"
								size="sm"
								onClick={() => setIsEditingCredentials(true)}
								disabled={Boolean(
									effectiveStatus &&
									!effectiveStatus.canManageFromDashboard
								)}
							>
								{__("Update Credentials")}
							</Button>
							<Button
								type="button"
								variant="outline"
								size="sm"
								onClick={onDownload}
								loading={isDownloading}
								disabled={
									!effectiveStatus?.credentialsConfigured ||
									Boolean(
										effectiveStatus &&
										!effectiveStatus.canManageFromDashboard
									)
								}
								icon={CloudDownload}
							>
								{isReady
									? __("Refresh Database")
									: __("Download Database")}
							</Button>
						</div>
					</div>
				) : (
					<form
						className="settings-location-form"
						onSubmit={handleSubmit}
					>
						<div className="settings-location-form-grid">
							<Input
								label={__("MaxMind Account ID")}
								type="text"
								inputMode="numeric"
								autoComplete="off"
								valueDirection="ltr"
								value={accountId}
								onChange={(event) =>
									setAccountIdInput(event.target.value)
								}
								placeholder="123456"
								className="settings-location-input"
							/>

							<Input
								label={__("MaxMind License Key")}
								type="password"
								autoComplete="new-password"
								valueDirection="ltr"
								value={licenseKey}
								onChange={(event) =>
									setLicenseKey(event.target.value)
								}
								placeholder={
									hasSavedCredentials
										? __("Enter a new MaxMind license key")
										: __("Enter your MaxMind license key")
								}
								className="settings-location-input"
							/>
						</div>

						{hasSavedCredentials && (
							<p className="settings-location-saved-key">
								{__("Saved license key:")}{" "}
								<span className="preserve-ltr-value">
									{effectiveStatus?.licenseKeyHint}
								</span>
							</p>
						)}

						<div
							dir={direction}
							className="settings-location-actions"
						>
							<Button
								type="submit"
								size="sm"
								loading={isSaving}
								disabled={Boolean(
									effectiveStatus &&
									!effectiveStatus.canManageFromDashboard
								)}
							>
								{hasSavedCredentials
									? __("Save New Credentials")
									: __("Save Credentials")}
							</Button>
							{hasSavedCredentials && (
								<Button
									type="button"
									variant="outline"
									size="sm"
									onClick={() => {
										setIsEditingCredentials(false);
										setAccountIdInput(null);
										setLicenseKey("");
									}}
								>
									{__("Cancel")}
								</Button>
							)}
						</div>
					</form>
				)}
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

export default LocationDataTab;
