import type { SubmitEvent } from "react";
import { useState } from "react";
import {
	AlertCircle,
	CheckCircle2,
	Pencil,
	CloudDownload,
	MapPin,
	KeyRound,
	Trash2,
} from "lucide-react";

import { Button, ConfirmDialog, Input } from "@/components";
import {
	DocumentationLink,
	TabHeader,
} from "@/pages/dashboard/settings/components/TabHeader";
import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn, formatByteSize } from "@/shared/formatting";
import { formatRelativeTime } from "@/shared/dates";

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
		info: "settings-notice-info",
		success: "settings-notice-success",
		error: "settings-notice-error",
		warning: "settings-notice-warning",
	};

	return (
		<div className={cn("settings-notice", styles[variant])}>
			<div dir={direction} className="settings-notice-layout">
				<Icon size={18} className="settings-notice-icon" />
				<div className="settings-notice-content">
					<h3 className="settings-notice-title">{title}</h3>
					<div className="settings-notice-text">{description}</div>
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
	const [isConfirmingClear, setIsConfirmingClear] = useState(false);
	const [isClearing, setIsClearing] = useState(false);
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

	const handleClearCredentials = async () => {
		setIsClearing(true);
		try {
			const nextStatus = await onSave({
				accountId: "",
				licenseKey: "",
			});
			if (nextStatus) {
				setSavedStatusOverride(nextStatus);
			} else {
				setSavedStatusOverride({
					credentialsConfigured: false,
					accountId: "",
					licenseKeyHint: null,
				});
			}
			setIsConfirmingClear(false);
			setIsEditingCredentials(false);
			setAccountIdInput(null);
			setLicenseKey("");
		} catch {
			// Retain UI state on failure
		} finally {
			setIsClearing(false);
		}
	};

	const isReady = Boolean(effectiveStatus?.locationAnalyticsReady);
	const hasCredentials = Boolean(effectiveStatus?.credentialsConfigured);
	const isStale = Boolean(effectiveStatus?.isStale);
	const canManageFromDashboard =
		effectiveStatus?.canManageFromDashboard ?? true;
	const canDownloadDatabase = Boolean(canManageFromDashboard);

	const renderContextualNotice = () => {
		if (isReady) {
			if (isStale) {
				return (
					<StateCard
						icon={AlertCircle}
						title={__("Database update recommended")}
						description={__(
							"Your local GeoLite2 City database is over 30 days old. Update the database to maintain accurate location reporting."
						)}
						variant="warning"
					/>
				);
			}

			if (!hasCredentials) {
				return (
					<StateCard
						icon={AlertCircle}
						title={__("MaxMind credentials recommended")}
						description={
							<span>
								{__(
									"PeakURL works out of the box using your local database. To keep location stats accurate as IP allocations shift, add your MaxMind credentials below so the weekly background job can automatically fetch updates."
								)}{" "}
								<a
									href="https://go.peakurl.org/028e82"
									target="_blank"
									rel="noopener noreferrer"
									className="underline font-medium hover:opacity-100"
								>
									{__("Setup guide")}
								</a>
							</span>
						}
						variant="warning"
					/>
				);
			}

			return null;
		}

		if (hasCredentials) {
			return (
				<StateCard
					icon={CloudDownload}
					title={__("Database download required")}
					description={__(
						"Your MaxMind credentials have been saved. Download the GeoLite2 City database using the button above to enable visitor country and city analytics."
					)}
					variant="info"
				/>
			);
		}

		return (
			<StateCard
				icon={CloudDownload}
				title={__("Database download required")}
				description={__(
					"Download the GeoLite2 City database using the button above to enable visitor country and city analytics."
				)}
				variant="info"
			/>
		);
	};

	return (
		<div className="settings-location">
			<div className="settings-section">
				<TabHeader
					icon={MapPin}
					title={__("Location Database")}
					description={__(
						"Enable country and city analytics with a local MaxMind GeoLite2 City database stored in your persistent content folder."
					)}
					actions={
						<Button
							size="sm"
							className="settings-location-download-button"
							onClick={onDownload}
							loading={isDownloading}
							icon={CloudDownload}
							disabled={
								isLoading || isSaving || !canDownloadDatabase
							}
						>
							{isReady
								? __("Update Database")
								: __("Download Database")}
						</Button>
					}
				/>
			</div>

			<div className="settings-stat-grid">
				<StatCard
					label={__("Status")}
					value={isReady ? __("Ready") : __("Setup Required")}
				/>
				<StatCard
					label={__("Database Updated")}
					value={
						effectiveStatus?.lastDownloadedAt ||
						effectiveStatus?.databaseUpdatedAt
							? formatRelativeTime(
									effectiveStatus.lastDownloadedAt ||
										effectiveStatus.databaseUpdatedAt
								)
							: __("Never")
					}
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

			{effectiveStatus && !errorMessage && renderContextualNotice()}

			{effectiveStatus?.manageDisabledReason && (
				<StateCard
					icon={AlertCircle}
					title={__("Dashboard management unavailable")}
					description={effectiveStatus.manageDisabledReason}
					variant="info"
				/>
			)}

			<section className="settings-section">
				<div className="settings-section-header settings-section-heading">
					<div className="settings-section-copy">
						<SectionTitle icon={KeyRound}>
							{__("MaxMind Credentials")}
						</SectionTitle>

						<p className="settings-group-description mb-0!">
							{__(
								"PeakURL stores these values encrypted in the database so it can refresh the GeoLite2 City database later without asking again."
							)}
						</p>
					</div>
					<DocumentationLink
						href="https://go.peakurl.org/028e82"
						label={__("How to get credentials")}
					/>
				</div>

				{hasSavedCredentials && !isEditingCredentials ? (
					<div className="settings-location-credentials-view">
						<div className="settings-location-credentials-panel">
							<div className="settings-location-credentials-status">
								<CheckCircle2 size={16} aria-hidden="true" />
								<span>{__("Credentials saved")}</span>
							</div>
							<dl className="settings-grid">
								<div className="form-field">
									<dt className="form-field-label">
										{__("Account ID")}
									</dt>
									<dd
										dir="ltr"
										className="settings-location-credentials-value"
									>
										{effectiveStatus?.accountId || ""}
									</dd>
								</div>
								<div className="form-field">
									<dt className="form-field-label">
										{__("License Key")}
									</dt>
									<dd
										dir="ltr"
										className="settings-location-credentials-value"
									>
										{effectiveStatus?.licenseKeyHint || ""}
									</dd>
								</div>
							</dl>
						</div>
						<div
							dir={direction}
							className="settings-location-actions"
						>
							<Button
								type="button"
								size="sm"
								variant="secondary"
								icon={Pencil}
								className="w-full sm:w-auto"
								onClick={() => setIsEditingCredentials(true)}
								disabled={Boolean(
									(effectiveStatus &&
										!effectiveStatus.canManageFromDashboard) ||
									isSaving ||
									isClearing
								)}
							>
								{__("Update Credentials")}
							</Button>
							<Button
								type="button"
								size="sm"
								variant="outline"
								icon={Trash2}
								className="w-full sm:w-auto text-destructive hover:bg-destructive/10"
								onClick={() => setIsConfirmingClear(true)}
								disabled={Boolean(
									(effectiveStatus &&
										!effectiveStatus.canManageFromDashboard) ||
									isSaving ||
									isClearing
								)}
							>
								{__("Clear Credentials")}
							</Button>
						</div>
					</div>
				) : (
					<form
						className="settings-location-form"
						onSubmit={handleSubmit}
					>
						<div className="settings-grid">
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
								className="w-full sm:w-auto"
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
									className="w-full sm:w-auto"
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
			</section>

			<ConfirmDialog
				open={isConfirmingClear}
				onClose={() => !isClearing && setIsConfirmingClear(false)}
				title={__("Clear MaxMind credentials?")}
				description={__(
					"The saved account ID and license key will be removed. Your downloaded location database and existing analytics will remain available. Future updates will automatically use PeakURL's verified database archive."
				)}
				confirmText={__("Clear Credentials")}
				confirmVariant="danger"
				loading={isClearing}
				onConfirm={handleClearCredentials}
			/>
		</div>
	);
}

function StatCard({ label, value, valueDirection = "auto" }: StatCardProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";

	return (
		<div dir={direction} className="settings-statistic">
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
