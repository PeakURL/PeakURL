import type { ChangeEvent, SubmitEvent } from "react";
import { useEffect, useMemo, useRef, useState } from "react";
import { ImageOff, Trash2, X } from "lucide-react";

import { PEAKURL_SITE_NAME } from "@constants";
import {
	Button,
	Input,
	PreviewImage,
	Select,
	TextArea,
	type SelectOption,
} from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { getInstalledLanguageLabel } from "@/i18n/languages";
import {
	getFaviconPreviewUrl,
	cn,
	getTimeZoneOptions,
	normalizeSiteTimeFormat,
	sanitizeImageUrl,
	type SiteTimeFormat,
} from "@/utils";

import type { GeneralFormState } from "../../types";
import type { GeneralTabProps } from "../types";

const isPngFaviconFile = (file: File | null): file is File =>
	Boolean(file && "image/png" === file.type && /\.png$/i.test(file.name));

const isSocialPreviewImageFile = (file: File | null): file is File =>
	Boolean(
		file &&
		["image/png", "image/jpeg", "image/webp"].includes(file.type) &&
		/\.(png|jpe?g|webp)$/i.test(file.name)
	);

function GeneralTab({
	initialForm,
	username,
	onSubmit,
	isUpdating,
	siteSettings,
	isLoadingSiteSettings,
}: GeneralTabProps) {
	const isRtl = isDocumentRtl();
	const availableLanguages = siteSettings?.availableLanguages || [];
	const defaultSiteTagline = __(
		"Shorten, track, and own every link - PeakURL"
	);
	const [generalForm, setGeneralForm] =
		useState<GeneralFormState>(initialForm);
	const [siteName, setSiteName] = useState(
		siteSettings?.siteName || PEAKURL_SITE_NAME || "PeakURL"
	);
	const [siteTagline, setSiteTagline] = useState(
		siteSettings?.siteTagline || defaultSiteTagline
	);
	const [siteLanguage, setSiteLanguage] = useState(
		siteSettings?.siteLanguage || "en_US"
	);
	const [siteTimezone, setSiteTimezone] = useState(
		siteSettings?.siteTimezone || "UTC"
	);
	const [siteTimeFormat, setSiteTimeFormat] = useState<SiteTimeFormat>(
		normalizeSiteTimeFormat(siteSettings?.siteTimeFormat)
	);
	const [faviconFile, setFaviconFile] = useState<File | null>(null);
	const [removeFavicon, setRemoveFavicon] = useState(false);
	const [socialPreviewFile, setSocialPreviewFile] = useState<File | null>(
		null
	);
	const [removeSocialPreviewImage, setRemoveSocialPreviewImage] =
		useState(false);
	const [landingPageMode, setLandingPageMode] = useState<
		"login" | "url" | "html"
	>(siteSettings?.landingPageMode || "html");
	const [landingPageUrl, setLandingPageUrl] = useState(
		siteSettings?.landingPageUrl || ""
	);
	const [trashRetentionDays, setTrashRetentionDays] = useState<number>(
		siteSettings?.trashRetentionDays ?? 30
	);
	const fileInputRef = useRef<HTMLInputElement | null>(null);
	const socialPreviewInputRef = useRef<HTMLInputElement | null>(null);

	const [prevInitialForm, setPrevInitialForm] = useState(initialForm);
	if (prevInitialForm !== initialForm) {
		setPrevInitialForm(initialForm);
		setGeneralForm(initialForm);
	}

	const [prevSiteSettings, setPrevSiteSettings] = useState(siteSettings);
	if (prevSiteSettings !== siteSettings) {
		setPrevSiteSettings(siteSettings);
		setSiteLanguage(siteSettings?.siteLanguage || "en_US");
		setSiteTimezone(siteSettings?.siteTimezone || "UTC");
		setSiteTimeFormat(
			normalizeSiteTimeFormat(siteSettings?.siteTimeFormat)
		);
		setSiteName(siteSettings?.siteName || PEAKURL_SITE_NAME || "PeakURL");
		setSiteTagline(siteSettings?.siteTagline || defaultSiteTagline);
		setLandingPageMode(siteSettings?.landingPageMode || "html");
		setLandingPageUrl(siteSettings?.landingPageUrl || "");
		setTrashRetentionDays(siteSettings?.trashRetentionDays ?? 30);
		setFaviconFile(null);
		setRemoveFavicon(false);
		setSocialPreviewFile(null);
		setRemoveSocialPreviewImage(false);
	}

	const handleChange = (
		event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>
	) => {
		const { name, value } = event.target;
		setGeneralForm((previous) => ({
			...previous,
			[name]: value,
		}));
	};

	const handleSubmit = (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		onSubmit({
			...generalForm,
			siteName,
			siteTagline,
			siteLanguage,
			siteTimezone,
			siteTimeFormat,
			landingPageMode,
			landingPageUrl,
			trashRetentionDays,
			socialPreviewFile,
			removeSocialPreviewImage,
			faviconFile,
			removeFavicon,
		});
	};
	const availableLanguageOptions = availableLanguages.reduce<
		SelectOption<string>[]
	>((options, language) => {
		const locale = language.locale?.trim();

		if (!locale) {
			return options;
		}

		options.push({
			value: locale,
			label: getInstalledLanguageLabel(language, availableLanguages),
		});

		return options;
	}, []);
	const languageOptions: SelectOption<string>[] =
		isLoadingSiteSettings &&
		(!siteSettings?.availableLanguages ||
			0 === siteSettings.availableLanguages.length)
			? [{ value: siteLanguage, label: __("Loading languages...") }]
			: availableLanguageOptions.length > 0
				? availableLanguageOptions
				: [{ value: siteLanguage, label: siteLanguage }];
	const timezoneOptions = useMemo(() => getTimeZoneOptions(), []);
	const timeFormatOptions: SelectOption<SiteTimeFormat>[] = [
		{ value: "12", label: __("12-hour (AM/PM)") },
		{ value: "24", label: __("24-hour") },
	];
	const trashRetentionOptions: SelectOption<string>[] = [
		{ value: "7", label: __("7 days") },
		{ value: "14", label: __("14 days") },
		{ value: "30", label: __("30 days (Default)") },
		{ value: "60", label: __("60 days") },
		{ value: "90", label: __("90 days") },
		{ value: "0", label: __("Never (Keep indefinitely)") },
	];
	const landingPageModeOptions: SelectOption<"login" | "url" | "html">[] = [
		{ value: "html", label: __("Default (Landing Page)") },
		{ value: "url", label: __("Redirect URL") },
		{ value: "login", label: __("Login Page") },
	];
	const hasCustomFavicon = Boolean(siteSettings?.favicon?.isCustom);
	const storedPreviewUrl = useMemo(
		() =>
			hasCustomFavicon
				? getFaviconPreviewUrl(siteSettings?.favicon?.updatedAt)
				: "",
		[hasCustomFavicon, siteSettings?.favicon?.updatedAt]
	);

	const uploadedPreviewUrl = useMemo(
		() =>
			isPngFaviconFile(faviconFile)
				? URL.createObjectURL(faviconFile)
				: "",
		[faviconFile]
	);

	useEffect(() => {
		if (!uploadedPreviewUrl) return;
		return () => {
			URL.revokeObjectURL(uploadedPreviewUrl);
		};
	}, [uploadedPreviewUrl]);

	const faviconPreviewUrl = useMemo(() => {
		if (removeFavicon) {
			return "";
		}

		return uploadedPreviewUrl || storedPreviewUrl;
	}, [removeFavicon, storedPreviewUrl, uploadedPreviewUrl]);
	const faviconPreviewSource = useMemo(
		() => sanitizeImageUrl(faviconPreviewUrl),
		[faviconPreviewUrl]
	);

	const uploadedSocialPreviewUrl = useMemo(
		() =>
			isSocialPreviewImageFile(socialPreviewFile)
				? URL.createObjectURL(socialPreviewFile)
				: "",
		[socialPreviewFile]
	);

	useEffect(() => {
		if (!uploadedSocialPreviewUrl) return;
		return () => {
			URL.revokeObjectURL(uploadedSocialPreviewUrl);
		};
	}, [uploadedSocialPreviewUrl]);

	const handleFaviconChange = (event: ChangeEvent<HTMLInputElement>) => {
		const nextFile = event.target.files?.[0] || null;
		setFaviconFile(isPngFaviconFile(nextFile) ? nextFile : null);
		setRemoveFavicon(false);
	};

	const handleRemoveFavicon = () => {
		const hasPendingUpload = Boolean(faviconFile);

		setFaviconFile(null);
		setRemoveFavicon(!hasPendingUpload && hasCustomFavicon);

		if (fileInputRef.current) {
			fileInputRef.current.value = "";
		}
	};

	const handleSocialPreviewChange = (
		event: ChangeEvent<HTMLInputElement>
	) => {
		const nextFile = event.target.files?.[0] || null;
		setSocialPreviewFile(
			isSocialPreviewImageFile(nextFile) ? nextFile : null
		);
		setRemoveSocialPreviewImage(false);
	};

	const hasConfiguredSocialPreview = Boolean(
		siteSettings?.socialPreview?.configured &&
		siteSettings?.socialPreview?.url
	);

	const handleRemoveSocialPreview = () => {
		const hasPendingUpload = Boolean(socialPreviewFile);

		setSocialPreviewFile(null);
		setRemoveSocialPreviewImage(
			!hasPendingUpload && hasConfiguredSocialPreview
		);

		if (socialPreviewInputRef.current) {
			socialPreviewInputRef.current.value = "";
		}
	};

	const canManageSiteSettings =
		siteSettings?.canManageSiteSettings && !isLoadingSiteSettings;
	const hasConfiguredFavicon = hasCustomFavicon;
	const hasFaviconPreview = Boolean(faviconPreviewSource);
	const showRemoveButton =
		Boolean(faviconFile) || (!removeFavicon && hasCustomFavicon);
	const previewSiteName =
		siteName.trim() ||
		PEAKURL_SITE_NAME ||
		siteSettings?.siteName ||
		"PeakURL";
	const chooserLabel =
		hasFaviconPreview || hasConfiguredFavicon
			? __("Replace Favicon")
			: __("Choose Favicon");
	const storedSocialPreviewUrl = hasConfiguredSocialPreview
		? siteSettings?.socialPreview?.url || ""
		: "";
	const socialPreviewImageUrl = removeSocialPreviewImage
		? ""
		: uploadedSocialPreviewUrl || storedSocialPreviewUrl;
	const socialPreviewImageSource = useMemo(
		() => sanitizeImageUrl(socialPreviewImageUrl),
		[socialPreviewImageUrl]
	);
	const showSocialPreviewRemove =
		Boolean(socialPreviewFile) ||
		(!removeSocialPreviewImage && hasConfiguredSocialPreview);
	const socialPreviewChooserLabel =
		socialPreviewImageSource || hasConfiguredSocialPreview
			? __("Replace Preview Image")
			: __("Choose Preview Image");

	return (
		<div className="settings-general">
			<form onSubmit={handleSubmit} className="settings-form">
				<section className="settings-fieldset">
					<h2 className="settings-legend">
						{__("Profile Information")}
					</h2>
					<hr className="settings-separator" />
					<div className="settings-grid">
						<Input
							label={__("First Name")}
							name="firstName"
							value={generalForm.firstName}
							onChange={handleChange}
							required
						/>
						<Input
							label={__("Last Name")}
							name="lastName"
							value={generalForm.lastName}
							onChange={handleChange}
							required
						/>
						<Input
							label={__("Display Name")}
							name="displayName"
							value={generalForm.displayName}
							onChange={handleChange}
							placeholder={`${generalForm.firstName} ${generalForm.lastName}`}
						/>
						<div className="form-field">
							<label
								htmlFor="settings-general-username"
								className="form-field-label settings-general-readonly-label"
							>
								<span>{__("Username")}</span>
								<span className="settings-general-readonly-note">
									{__("Cannot be changed")}
								</span>
							</label>
							<div className="form-field-control">
								<input
									id="settings-general-username"
									type="text"
									dir="ltr"
									value={username || ""}
									disabled
									autoCapitalize="off"
									spellCheck={false}
									className="form-control-base form-field-input form-field-input-no-icon settings-general-readonly-input"
								/>
							</div>
						</div>
						<Input
							label={__("Email Address")}
							type="email"
							name="email"
							valueDirection="ltr"
							autoCapitalize="off"
							spellCheck={false}
							value={generalForm.email}
							onChange={handleChange}
							required
						/>
						<Input
							label={__("Phone Number")}
							type="tel"
							name="phoneNumber"
							value={generalForm.phoneNumber}
							onChange={handleChange}
						/>
						<Input
							label={__("Company")}
							name="company"
							value={generalForm.company}
							onChange={handleChange}
						/>
						<Input
							label={__("Job Title")}
							name="jobTitle"
							value={generalForm.jobTitle}
							onChange={handleChange}
						/>
						<div className="settings-general-bio-field">
							<TextArea
								label={__("Bio")}
								name="bio"
								rows={3}
								className="settings-general-bio-input"
								value={generalForm.bio}
								onChange={handleChange}
							/>
						</div>
					</div>
				</section>
				<section className="settings-fieldset">
					<h2 className="settings-legend">
						{__("Site Configuration")}
					</h2>
					<hr className="settings-separator" />
					<div className="settings-grid">
						<Input
							label={__("Site title")}
							value={siteName}
							onChange={(event) =>
								setSiteName(event.target.value)
							}
							disabled={!canManageSiteSettings || isUpdating}
						/>
						<Input
							label={__("Tagline")}
							value={siteTagline}
							onChange={(event) =>
								setSiteTagline(event.target.value)
							}
							disabled={!canManageSiteSettings || isUpdating}
						/>
						<div className="settings-general-field">
							<label className="settings-section-label">
								{__("Site Language")}
							</label>
							<Select
								value={siteLanguage}
								onChange={setSiteLanguage}
								options={languageOptions}
								disabled={
									isLoadingSiteSettings ||
									!siteSettings?.canManageSiteSettings ||
									isUpdating
								}
								ariaLabel={__("Site language")}
							/>
						</div>
						<div className="settings-general-field">
							<label className="settings-section-label">
								{__("Site Timezone")}
							</label>
							<Select
								value={siteTimezone}
								onChange={setSiteTimezone}
								options={timezoneOptions}
								disabled={
									isLoadingSiteSettings ||
									!siteSettings?.canManageSiteSettings ||
									isUpdating
								}
								ariaLabel={__("Site timezone")}
								optionsClassName="settings-general-timezone-options"
							/>
						</div>
						<div className="settings-general-field">
							<label className="settings-section-label">
								{__("Time Format")}
							</label>
							<Select
								value={siteTimeFormat}
								onChange={setSiteTimeFormat}
								options={timeFormatOptions}
								disabled={
									isLoadingSiteSettings ||
									!siteSettings?.canManageSiteSettings ||
									isUpdating
								}
								ariaLabel={__("Time format")}
							/>
						</div>
						<div className="settings-general-field">
							<label className="settings-section-label">
								{__("Trash Retention Period")}
							</label>
							<Select
								value={String(trashRetentionDays)}
								onChange={(val) =>
									setTrashRetentionDays(Number(val))
								}
								options={trashRetentionOptions}
								disabled={
									isLoadingSiteSettings ||
									!siteSettings?.canManageSiteSettings ||
									isUpdating
								}
								ariaLabel={__("Trash retention period")}
							/>
						</div>
					</div>
				</section>
				<section className="settings-fieldset">
					<h2 className="settings-legend">
						{__("Homepage Configuration")}
					</h2>
					<hr className="settings-separator" />
					<div className="settings-grid">
						<div className="settings-general-field-full">
							<label className="settings-section-label">
								{__("Root URL Behavior")}
							</label>
							<Select
								value={landingPageMode}
								onChange={(val) =>
									setLandingPageMode(
										val as "login" | "url" | "html"
									)
								}
								options={landingPageModeOptions}
								disabled={
									isLoadingSiteSettings ||
									!siteSettings?.canManageSiteSettings ||
									isUpdating
								}
								ariaLabel={__("Root URL Behavior")}
							/>
						</div>
						{landingPageMode === "url" && (
							<div className="settings-general-field-full">
								<Input
									label={__("Custom Redirect URL")}
									value={landingPageUrl}
									onChange={(event) =>
										setLandingPageUrl(event.target.value)
									}
									disabled={
										!canManageSiteSettings || isUpdating
									}
									placeholder="https://example.com"
									type="url"
									valueDirection="ltr"
								/>
								<p className="settings-group-description settings-general-help-text">
									{__(
										"Visitors to the root domain will be redirected to this URL."
									)}
								</p>
							</div>
						)}
						{landingPageMode === "html" && (
							<div className="settings-general-field-full">
								<div className="settings-group-description settings-general-help-text">
									<p>
										{__(
											"To customize your HTML landing page, edit the following file in your installation:"
										)}
									</p>
									<code className="settings-general-code-block">
										{siteSettings?.contentDirectory
											? `${siteSettings.contentDirectory}/landing-page.html`
											: "content/landing-page.html"}
									</code>
									<p className="settings-general-help-text">
										{__(
											"This raw HTML file will be served at the root domain. You can include custom styles, scripts, and branding."
										)}
									</p>
								</div>
							</div>
						)}
					</div>
				</section>
				<section className="settings-fieldset">
					<h2 className="settings-legend">{__("Branding")}</h2>
					<hr className="settings-separator" />
					<div className="settings-general-favicon">
						<div className="settings-general-favicon-header">
							<div className="settings-general-favicon-content">
								<div className="settings-general-favicon-copy">
									<h3 className="settings-general-favicon-title">
										{__("Site Favicon")}
									</h3>
									<p className="settings-group-description">
										{__(
											"Upload a square PNG favicon. PeakURL will use it for browser tabs, Apple touch icons, and the site web manifest."
										)}
									</p>
								</div>
								<div className="settings-general-favicon-field">
									<label
										htmlFor="settings-favicon-upload"
										className="settings-section-label"
									>
										{__("Favicon PNG")}
									</label>
									<input
										ref={fileInputRef}
										id="settings-favicon-upload"
										type="file"
										accept="image/png"
										onChange={handleFaviconChange}
										disabled={
											!canManageSiteSettings || isUpdating
										}
										className="settings-general-favicon-input-native"
									/>
									<div className="settings-general-favicon-picker">
										<Button
											type="button"
											size="sm"
											variant="outline"
											onClick={() =>
												fileInputRef.current?.click()
											}
											disabled={
												!canManageSiteSettings ||
												isUpdating
											}
										>
											{chooserLabel}
										</Button>
										{faviconFile ? (
											<span className="settings-general-favicon-filename">
												{faviconFile.name}
											</span>
										) : null}
									</div>
									<p className="settings-general-favicon-note">
										{sprintf(
											__(
												"Use a square PNG, ideally %s. The minimum supported size is 180 x 180."
											),
											siteSettings?.favicon
												?.recommendedSize || "512x512"
										)}
									</p>
								</div>
							</div>
							<div
								className={cn(
									"settings-general-favicon-preview",
									hasFaviconPreview
										? "settings-general-favicon-preview-filled"
										: "settings-general-favicon-preview-empty-state"
								)}
							>
								{faviconPreviewSource ? (
									<div className="settings-general-favicon-browser">
										{showRemoveButton ? (
											<button
												type="button"
												onClick={handleRemoveFavicon}
												disabled={
													!canManageSiteSettings ||
													isUpdating
												}
												className="settings-general-favicon-remove"
												aria-label={__(
													"Remove Favicon"
												)}
											>
												<Trash2
													aria-hidden="true"
													className="settings-general-favicon-remove-icon"
												/>
											</button>
										) : null}
										<div
											aria-hidden="true"
											className="settings-general-favicon-glow"
										/>
										<div className="settings-general-favicon-browser-body">
											<PreviewImage
												source={faviconPreviewSource}
												alt={__(
													"Current favicon preview"
												)}
												className="settings-general-favicon-app-icon"
											/>
											<div className="settings-general-favicon-browser-window">
												<div className="settings-general-favicon-browser-top">
													<div
														aria-hidden="true"
														className="settings-general-favicon-browser-bar"
													>
														<span className="settings-general-favicon-browser-dot" />
														<span className="settings-general-favicon-browser-dot" />
														<span className="settings-general-favicon-browser-dot" />
													</div>
													<div className="settings-general-favicon-browser-tab">
														<PreviewImage
															source={
																faviconPreviewSource
															}
															alt=""
															aria-hidden="true"
															className="settings-general-favicon-browser-icon"
														/>
														<span
															aria-hidden="true"
															className="settings-general-favicon-browser-title"
														>
															{previewSiteName}
														</span>
														<X
															aria-hidden="true"
															className="settings-general-favicon-browser-close"
														/>
													</div>
												</div>
												<div
													aria-hidden="true"
													className="settings-general-favicon-browser-panel"
												/>
											</div>
										</div>
									</div>
								) : (
									<div className="settings-general-favicon-empty">
										<ImageOff
											aria-hidden="true"
											className="settings-general-favicon-placeholder"
										/>
										<span className="sr-only">
											{__("No favicon configured")}
										</span>
									</div>
								)}
							</div>
						</div>
					</div>
					<div className="settings-general-social-preview">
						<div className="settings-general-social-preview-copy">
							<h3 className="settings-general-social-preview-title">
								{__("Social Preview")}
							</h3>
							<p className="settings-group-description">
								{__(
									"Upload the default image used when short links are shared on social platforms. Individual links can override it."
								)}
							</p>
						</div>
						<div className="settings-general-social-preview-grid">
							<div className="settings-general-social-preview-field">
								<label
									htmlFor="settings-social-preview-upload"
									className="settings-section-label"
								>
									{__("Preview Image")}
								</label>
								<input
									ref={socialPreviewInputRef}
									id="settings-social-preview-upload"
									type="file"
									accept="image/png,image/jpeg,image/webp"
									onChange={handleSocialPreviewChange}
									disabled={
										!canManageSiteSettings || isUpdating
									}
									className="settings-general-favicon-input-native"
								/>
								<div className="settings-general-favicon-picker">
									<Button
										type="button"
										size="sm"
										variant="outline"
										onClick={() =>
											socialPreviewInputRef.current?.click()
										}
										disabled={
											!canManageSiteSettings || isUpdating
										}
									>
										{socialPreviewChooserLabel}
									</Button>
									{socialPreviewFile ? (
										<span className="settings-general-favicon-filename">
											{socialPreviewFile.name}
										</span>
									) : null}
								</div>
								<p className="settings-general-social-preview-note">
									{sprintf(
										__(
											"Use a PNG, JPG, or WebP image, ideally %s, for clean previews on Facebook, X, LinkedIn, and messaging apps."
										),
										siteSettings?.socialPreview
											?.recommendedSize || "1200x630"
									)}
								</p>
							</div>
							<div className="settings-general-social-preview-card">
								{socialPreviewImageSource ? (
									<div className="settings-general-social-preview-media">
										{showSocialPreviewRemove ? (
											<button
												type="button"
												onClick={
													handleRemoveSocialPreview
												}
												disabled={
													!canManageSiteSettings ||
													isUpdating
												}
												className="settings-general-social-preview-remove"
												aria-label={__(
													"Remove Preview Image"
												)}
											>
												<Trash2
													aria-hidden="true"
													className="settings-general-favicon-remove-icon"
												/>
											</button>
										) : null}
										<PreviewImage
											source={socialPreviewImageSource}
											alt={__(
												"Default social preview image"
											)}
											className="settings-general-social-preview-image"
										/>
									</div>
								) : (
									<div className="settings-general-social-preview-empty">
										<ImageOff
											aria-hidden="true"
											className="settings-general-social-preview-icon"
										/>
										<span>
											{__("No default preview image")}
										</span>
									</div>
								)}
							</div>
						</div>
					</div>
				</section>
				<div
					className={cn(
						"settings-general-actions",
						isRtl
							? "settings-general-actions-start"
							: "settings-general-actions-end"
					)}
				>
					<Button size="sm" type="submit" disabled={isUpdating}>
						{isUpdating ? __("Saving...") : __("Save Changes")}
					</Button>
				</div>
			</form>
		</div>
	);
}

export default GeneralTab;
