import { useMemo, useState } from "react";
import { ExternalLink, Pencil, Save, Trash2, X } from "lucide-react";

import { Button, Input, Select, type SelectOption } from "@/components";
import {
	useGetCaptchaStatusQuery,
	useSaveCaptchaConfigurationMutation,
} from "@/store/slices/api";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn, getErrorMessage } from "@/utils";

import type { CaptchaProvider, CaptchaStatus } from "../../types";
import type { IntegrationsTabProps } from "./types";

type CaptchaActiveProvider = Exclude<CaptchaProvider, "none">;

const DEFAULT_CAPTCHA_PROVIDER: CaptchaActiveProvider = "recaptcha";
const MASKED_CAPTCHA_KEY = "••••••";

interface CaptchaFormState {
	enabled: boolean;
	provider: CaptchaActiveProvider;
	siteKey: string;
	secretKey: string;
}

interface CaptchaSettingsContentProps extends IntegrationsTabProps {
	error: unknown;
	isLoading: boolean;
	status: CaptchaStatus | null;
}

function isCaptchaActiveProvider(
	provider: CaptchaProvider
): provider is CaptchaActiveProvider {
	return provider !== "none";
}

function getInitialForm(status?: CaptchaStatus | null): CaptchaFormState {
	const provider = status?.provider || "none";
	const enabled = isCaptchaActiveProvider(provider);

	return {
		enabled,
		provider: enabled ? provider : DEFAULT_CAPTCHA_PROVIDER,
		siteKey: status?.siteKey || "",
		secretKey: "",
	};
}

function getProviderOptions(): SelectOption<CaptchaActiveProvider>[] {
	return [
		{ value: "recaptcha", label: __("Google reCAPTCHA v3") },
		{ value: "turnstile", label: __("Cloudflare Turnstile") },
	];
}

function getProviderLabel(provider: CaptchaActiveProvider): string {
	return provider === "turnstile"
		? __("Cloudflare Turnstile")
		: __("Google reCAPTCHA v3");
}

function maskCaptchaKey(value?: string | null): string {
	const key = (value || "").trim();

	if (!key) {
		return MASKED_CAPTCHA_KEY;
	}

	if (key.length <= 8) {
		return "•".repeat(Math.max(4, key.length));
	}

	return `${key.slice(0, 4)}${"•".repeat(6)}${key.slice(-4)}`;
}

function getProviderDocs(provider: CaptchaActiveProvider) {
	if (provider === "recaptcha") {
		return {
			href: "https://developers.google.com/recaptcha/docs/v3",
			label: __("reCAPTCHA v3 docs"),
		};
	}

	if (provider === "turnstile") {
		return {
			href: "https://developers.cloudflare.com/turnstile/",
			label: __("Turnstile docs"),
		};
	}

	return null;
}

function CaptchaSettings({ notification }: IntegrationsTabProps) {
	const { data, isLoading, error } = useGetCaptchaStatusQuery();
	const status = data?.data || null;

	return (
		<CaptchaSettingsContent
			key={[
				status?.provider || "none",
				status?.siteKeyHint || "",
				status?.secretKeyHint || "",
				status?.configured ? "configured" : "incomplete",
			].join(":")}
			error={error}
			isLoading={isLoading}
			notification={notification}
			status={status}
		/>
	);
}

function CaptchaSettingsContent({
	error,
	isLoading,
	notification,
	status,
}: CaptchaSettingsContentProps) {
	const [saveCaptchaConfiguration, { isLoading: isSaving }] =
		useSaveCaptchaConfigurationMutation();
	const [form, setForm] = useState<CaptchaFormState>(() =>
		getInitialForm(status)
	);
	const [editingKeys, setEditingKeys] = useState(() => {
		const initialForm = getInitialForm(status);
		return initialForm.enabled && !status?.configured;
	});
	const providerOptions = getProviderOptions();
	const direction = isDocumentRtl() ? "rtl" : "ltr";

	const canManage = status?.canManageFromDashboard !== false;
	const providerEnabled = form.enabled;
	const savedProvider = status?.provider || "none";
	const savedActiveProvider = isCaptchaActiveProvider(savedProvider)
		? savedProvider
		: null;
	const savedEnabled = savedProvider !== "none";
	const enabledChanged = form.enabled !== savedEnabled;
	const providerChanged = form.enabled && form.provider !== savedProvider;
	const savedConfigured = Boolean(savedActiveProvider && status?.configured);
	const showingSavedKeys =
		form.enabled && savedConfigured && !editingKeys && !providerChanged;
	const showingKeyFields = form.enabled && !showingSavedKeys;
	const providerDocs = getProviderDocs(form.provider);
	const providerNote =
		form.provider === "recaptcha"
			? __(
					"Use Google reCAPTCHA v3 site and secret keys for invisible score-based verification."
				)
			: __(
					"Use Cloudflare Turnstile site and secret keys for the Managed Challenge widget."
				);
	const savedSiteKeyHint =
		status?.siteKeyHint || maskCaptchaKey(status?.siteKey);
	const savedSecretKeyHint = status?.secretKeyHint || MASKED_CAPTCHA_KEY;
	const savedProviderLabel = savedActiveProvider
		? getProviderLabel(savedActiveProvider)
		: __("None");
	const showSaveButton = form.enabled || enabledChanged;
	const canKeepSavedSecret =
		Boolean(status?.secretKeyConfigured) && !providerChanged;
	const secretHelp = useMemo(() => {
		if (canKeepSavedSecret) {
			return __("Leave blank to keep the saved secret key.");
		}

		return __("Paste the secret key from your CAPTCHA provider.");
	}, [canKeepSavedSecret]);

	const statusLabel = useMemo(() => {
		if (!form.enabled) {
			return __("Disabled");
		}

		if (editingKeys && savedConfigured) {
			return __("Editing");
		}

		return savedConfigured && !providerChanged
			? __("Ready")
			: __("Not configured");
	}, [editingKeys, form.enabled, providerChanged, savedConfigured]);

	const setProtectionEnabled = (enabled: boolean) => {
		setForm((current) => ({
			...current,
			enabled,
		}));
		setEditingKeys(enabled ? !savedConfigured : false);
	};

	const handleProviderChange = (provider: CaptchaActiveProvider) => {
		setForm((current) => ({
			...current,
			provider,
			siteKey: provider === savedProvider ? status?.siteKey || "" : "",
			secretKey: "",
		}));
		setEditingKeys(true);
	};

	const handleEditKeys = () => {
		setForm((current) => ({
			...current,
			siteKey:
				current.provider === savedProvider ? status?.siteKey || "" : "",
			secretKey: "",
		}));
		setEditingKeys(true);
	};

	const handleCancelEdit = () => {
		const nextForm = getInitialForm(status);

		setForm(nextForm);
		setEditingKeys(nextForm.enabled && !status?.configured);
	};

	const handleSave = async () => {
		try {
			const response = await saveCaptchaConfiguration({
				provider: form.enabled ? form.provider : "none",
				siteKey: form.enabled ? form.siteKey.trim() : "",
				secretKey: form.enabled ? form.secretKey.trim() : "",
			}).unwrap();
			notification?.success?.(
				__("Success"),
				__("CAPTCHA settings saved.")
			);
			setForm(getInitialForm(response.data));
			setEditingKeys(false);
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to save CAPTCHA settings."))
			);
		}
	};

	const handleRemoveKeys = async () => {
		try {
			const response = await saveCaptchaConfiguration({
				provider: "none",
				siteKey: "",
				secretKey: "",
			}).unwrap();
			notification?.success?.(__("Success"), __("CAPTCHA keys removed."));
			setForm(getInitialForm(response.data));
			setEditingKeys(false);
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to remove CAPTCHA keys."))
			);
		}
	};

	return (
		<div dir={direction} className="integrations-tab-panel">
			<div className="integrations-tab-panel-header">
				<div className="integrations-tab-panel-copy">
					<h3 className="integrations-tab-panel-title">
						{__("CAPTCHA Protection")}
					</h3>
					<p className="integrations-tab-panel-description">
						{__(
							"Require a verification challenge before public short links redirect."
						)}
					</p>
				</div>
				<div className="flex items-center gap-3">
					<span className="integrations-tab-status-pill">
						{statusLabel}
					</span>
					<div className="integrations-tab-switch">
						<span
							id="captcha-protection-toggle-label"
							className="sr-only"
						>
							{__("Enable CAPTCHA protection")}
						</span>
						<button
							type="button"
							role="switch"
							aria-checked={form.enabled}
							aria-labelledby="captcha-protection-toggle-label"
							disabled={!canManage || isLoading}
							onClick={() => setProtectionEnabled(!form.enabled)}
							className={cn(
								"integrations-tab-switch-track",
								form.enabled
									? "integrations-tab-switch-track-active"
									: "integrations-tab-switch-track-inactive"
							)}
						>
							<span
								className={cn(
									"integrations-tab-switch-thumb",
									form.enabled
										? "integrations-tab-switch-thumb-active"
										: "integrations-tab-switch-thumb-inactive"
								)}
							/>
						</button>
					</div>
				</div>
			</div>

			{isLoading ? (
				<p className="integrations-tab-status-copy">
					{__("Loading CAPTCHA settings…")}
				</p>
			) : error ? (
				<p className="integrations-tab-status-copy integrations-tab-status-copy-error">
					{getErrorMessage(
						error,
						__("Failed to load CAPTCHA settings.")
					)}
				</p>
			) : providerEnabled ? (
				<div className="integrations-tab-form integrations-tab-captcha-form">
					<h4 className="integrations-tab-form-title">
						{__("Provider Settings")}
					</h4>
					<p className="integrations-tab-form-description">
						{showingSavedKeys
							? __(
									"Saved credentials are masked. Change or remove them when needed."
								)
							: __(
									"Choose a CAPTCHA provider and enter your API keys."
								)}
					</p>

					{showingSavedKeys ? (
						<div className="integrations-tab-captcha-summary">
							<div className="integrations-tab-captcha-summary-grid">
								<div className="integrations-tab-captcha-summary-item">
									<span className="integrations-tab-captcha-summary-label">
										{__("Provider")}
									</span>
									<span className="integrations-tab-captcha-summary-value">
										{savedProviderLabel}
									</span>
								</div>
								<div className="integrations-tab-captcha-summary-item">
									<span className="integrations-tab-captcha-summary-label">
										{__("Site Key")}
									</span>
									<code className="integrations-tab-captcha-summary-value integrations-tab-captcha-summary-code">
										{savedSiteKeyHint}
									</code>
								</div>
								<div className="integrations-tab-captcha-summary-item">
									<span className="integrations-tab-captcha-summary-label">
										{__("Secret Key")}
									</span>
									<code className="integrations-tab-captcha-summary-value integrations-tab-captcha-summary-code">
										{savedSecretKeyHint}
									</code>
								</div>
							</div>
							<p className="integrations-tab-captcha-summary-note">
								{providerNote}
							</p>
						</div>
					) : (
						<div className="integrations-tab-form-grid integrations-tab-captcha-fields">
							<div className="integrations-tab-captcha-provider form-field">
								<label
									htmlFor="captcha-provider"
									className="form-field-label"
								>
									{__("Provider")}
								</label>
								<div className="form-field-control">
									<Select
										id="captcha-provider"
										value={form.provider}
										onChange={handleProviderChange}
										disabled={!canManage}
										options={providerOptions}
										ariaLabel={__("CAPTCHA provider")}
									/>
								</div>
								<p className="form-field-helper">
									{providerNote}
								</p>
							</div>

							<Input
								label={__("Site Key")}
								value={form.siteKey}
								valueDirection="ltr"
								disabled={!canManage}
								autoCapitalize="off"
								spellCheck={false}
								placeholder={__("Paste site key")}
								onChange={(event) =>
									setForm((current) => ({
										...current,
										siteKey: event.target.value,
									}))
								}
								helperText={
									savedConfigured && !providerChanged
										? __(
												"Leave unchanged to keep the saved site key."
											)
										: undefined
								}
							/>

							<div className="integrations-tab-captcha-secret">
								<Input
									label={__("Secret Key")}
									type="password"
									value={form.secretKey}
									valueDirection="ltr"
									disabled={!canManage}
									autoCapitalize="off"
									spellCheck={false}
									placeholder={
										canKeepSavedSecret
											? __("Keep saved secret")
											: __("Paste secret key")
									}
									onChange={(event) =>
										setForm((current) => ({
											...current,
											secretKey: event.target.value,
										}))
									}
								/>
								<p className="integrations-tab-field-help">
									{secretHelp}
								</p>
							</div>
						</div>
					)}

					<div className="integrations-tab-captcha-footer">
						{providerDocs ? (
							<a
								href={providerDocs.href}
								target="_blank"
								rel="noreferrer"
								className="integrations-tab-docs-link"
							>
								{providerDocs.label}
								<ExternalLink size={14} />
							</a>
						) : (
							<div />
						)}

						{showingSavedKeys ? (
							<div className="integrations-tab-captcha-footer-actions">
								<Button
									size="sm"
									variant="secondary"
									icon={Pencil}
									onClick={handleEditKeys}
									disabled={!canManage || isSaving}
								>
									{__("Change keys")}
								</Button>
								<Button
									size="sm"
									variant="outline"
									className="integrations-tab-captcha-remove-button"
									icon={Trash2}
									loading={isSaving}
									onClick={handleRemoveKeys}
									disabled={!canManage || isSaving}
								>
									{__("Remove keys")}
								</Button>
							</div>
						) : showingKeyFields ? (
							<div className="integrations-tab-captcha-footer-actions">
								{savedConfigured ? (
									<Button
										size="sm"
										variant="secondary"
										icon={X}
										onClick={handleCancelEdit}
										disabled={!canManage || isSaving}
									>
										{__("Cancel")}
									</Button>
								) : null}
								<Button
									size="sm"
									icon={Save}
									loading={isSaving}
									onClick={handleSave}
									disabled={!canManage || isSaving}
								>
									{isSaving
										? __("Saving...")
										: __("Save Settings")}
								</Button>
							</div>
						) : (
							<div />
						)}
					</div>
				</div>
			) : (
				showSaveButton && (
					<div className="mt-4 flex justify-end">
						<Button
							size="sm"
							icon={Save}
							loading={isSaving}
							onClick={handleSave}
							disabled={!canManage || isSaving}
						>
							{isSaving ? __("Saving...") : __("Save Settings")}
						</Button>
					</div>
				)
			)}

			{!canManage && status?.manageDisabledReason && (
				<p className="mt-4 integrations-tab-status-copy integrations-tab-status-copy-error">
					{status.manageDisabledReason}
				</p>
			)}
		</div>
	);
}

export default CaptchaSettings;
