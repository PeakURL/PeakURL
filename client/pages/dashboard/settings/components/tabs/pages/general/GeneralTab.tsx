import { UserRound, Settings2, House, Palette } from "lucide-react";
import type { ChangeEvent, SubmitEvent } from "react";
import { useEffect, useMemo, useRef, useState } from "react";

import { PEAKURL_SITE_NAME } from "@/constants";
import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";
import {
	Button,
	Input,
	SelectField,
	TextArea,
	type SelectOption,
} from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { getInstalledLanguageLabel } from "@/i18n/languages";
import { cn } from "@/shared/formatting";
import { getFaviconPreviewUrl, sanitizeImageUrl } from "@/shared/links";
import { getTimeZoneOptions, normalizeSiteTimeFormat } from "./lib";
import type { SiteTimeFormat } from "@/api";
import { ImageUploadField } from "./ImageUploadField";

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
	const [analyticsRetentionDays, setAnalyticsRetentionDays] =
		useState<number>(siteSettings?.analyticsRetentionDays ?? 0);
	const fileInputRef = useRef<HTMLInputElement | null>(null);
	const socialPreviewInputRef = useRef<HTMLInputElement | null>(null);

	const [prevInitialForm, setPrevInitialForm] = useState(initialForm);
	if (
		prevInitialForm.firstName !== initialForm.firstName ||
		prevInitialForm.lastName !== initialForm.lastName ||
		prevInitialForm.displayName !== initialForm.displayName ||
		prevInitialForm.email !== initialForm.email ||
		prevInitialForm.phoneNumber !== initialForm.phoneNumber ||
		prevInitialForm.company !== initialForm.company ||
		prevInitialForm.jobTitle !== initialForm.jobTitle ||
		prevInitialForm.bio !== initialForm.bio
	) {
		setPrevInitialForm(initialForm);
		setGeneralForm(initialForm);
	}

	const [prevSiteSettings, setPrevSiteSettings] = useState(siteSettings);
	if (
		prevSiteSettings?.siteLanguage !== siteSettings?.siteLanguage ||
		prevSiteSettings?.siteTimezone !== siteSettings?.siteTimezone ||
		prevSiteSettings?.siteTimeFormat !== siteSettings?.siteTimeFormat ||
		prevSiteSettings?.siteName !== siteSettings?.siteName ||
		prevSiteSettings?.siteTagline !== siteSettings?.siteTagline ||
		prevSiteSettings?.landingPageMode !== siteSettings?.landingPageMode ||
		prevSiteSettings?.landingPageUrl !== siteSettings?.landingPageUrl ||
		prevSiteSettings?.trashRetentionDays !==
			siteSettings?.trashRetentionDays ||
		prevSiteSettings?.analyticsRetentionDays !==
			siteSettings?.analyticsRetentionDays
	) {
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
		setAnalyticsRetentionDays(siteSettings?.analyticsRetentionDays ?? 0);
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
			analyticsRetentionDays,
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
		{ value: "0", label: __("Indefinite") },
	];
	const analyticsRetentionOptions: SelectOption<string>[] = [
		{ value: "0", label: __("Indefinite (Default)") },
		{ value: "30", label: __("30 days") },
		{ value: "60", label: __("60 days") },
		{ value: "90", label: __("90 days") },
		{ value: "180", label: __("180 days (6 months)") },
		{ value: "365", label: __("365 days (1 year)") },
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
				<section className="settings-section">
					<SectionTitle icon={UserRound}>
						{__("Profile Information")}
					</SectionTitle>

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
				{canManageSiteSettings && (
					<>
						<section className="settings-section">
							<SectionTitle icon={Settings2}>
								{__("Site Configuration")}
							</SectionTitle>

							<div className="settings-grid">
								<Input
									label={__("Site title")}
									value={siteName}
									onChange={(event) =>
										setSiteName(event.target.value)
									}
									disabled={
										!canManageSiteSettings || isUpdating
									}
								/>
								<Input
									label={__("Tagline")}
									value={siteTagline}
									onChange={(event) =>
										setSiteTagline(event.target.value)
									}
									disabled={
										!canManageSiteSettings || isUpdating
									}
								/>
								<SelectField
									label={__("Site Language")}
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
								<SelectField
									label={__("Site Timezone")}
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
								<SelectField
									label={__("Time Format")}
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
								<SelectField
									label={__("Trash Retention Period")}
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
								<SelectField
									label={__(
										"Analytics Click Retention Period"
									)}
									value={String(analyticsRetentionDays)}
									onChange={(val) =>
										setAnalyticsRetentionDays(Number(val))
									}
									options={analyticsRetentionOptions}
									disabled={
										isLoadingSiteSettings ||
										!siteSettings?.canManageSiteSettings ||
										isUpdating
									}
									ariaLabel={__(
										"Analytics click retention period"
									)}
								/>
							</div>
						</section>
						<section className="settings-section">
							<SectionTitle icon={House}>
								{__("Homepage Configuration")}
							</SectionTitle>

							<div className="settings-grid">
								<SelectField
									className="settings-general-field-full"
									label={__("Root URL Behavior")}
									value={landingPageMode}
									onChange={setLandingPageMode}
									options={landingPageModeOptions}
									disabled={
										isLoadingSiteSettings ||
										!siteSettings?.canManageSiteSettings ||
										isUpdating
									}
									ariaLabel={__("Root URL Behavior")}
									helperText={
										landingPageMode === "html"
											? sprintf(
													__(
														"Customize your landing page by editing %s. You can include HTML, styles, and scripts."
													),
													siteSettings?.contentDirectory
														? `${siteSettings.contentDirectory}/landing-page.html`
														: "content/landing-page.html"
												)
											: undefined
									}
								/>
								{landingPageMode === "url" && (
									<div className="settings-general-field-full">
										<Input
											label={__("Custom Redirect URL")}
											value={landingPageUrl}
											onChange={(event) =>
												setLandingPageUrl(
													event.target.value
												)
											}
											disabled={
												!canManageSiteSettings ||
												isUpdating
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
							</div>
						</section>
						<section className="settings-section">
							<div className="settings-section-copy settings-section-heading">
								<SectionTitle icon={Palette}>
									{__("Branding")}
								</SectionTitle>
								<p className="settings-group-description">
									{__(
										"Choose how PeakURL appears in browser tabs and shared links."
									)}
								</p>
							</div>
							<div className="settings-branding-fields">
								<ImageUploadField
									id="settings-favicon-upload"
									siteName={siteName}
									title={__("Site Favicon")}
									description={__(
										"Used in browser tabs, Apple touch icons, and the site web manifest."
									)}
									hint={sprintf(
										__(
											"Square PNG, ideally %s. Minimum 180 × 180 pixels."
										),
										siteSettings?.favicon
											?.recommendedSize || "512 × 512"
									)}
									accept="image/png"
									buttonLabel={chooserLabel}
									removeLabel={__("Remove Favicon")}
									previewLabel={__("Current favicon preview")}
									preview={faviconPreviewSource || null}
									fileName={faviconFile?.name}
									disabled={
										!canManageSiteSettings || isUpdating
									}
									inputRef={fileInputRef}
									onChange={handleFaviconChange}
									onRemove={
										showRemoveButton
											? handleRemoveFavicon
											: undefined
									}
								/>
								<ImageUploadField
									id="settings-social-preview-upload"
									landscape
									title={__("Social Preview")}
									description={__(
										"The default image for shared links. Individual links can override it."
									)}
									hint={sprintf(
										__("PNG, JPG, or WebP, ideally %s."),
										siteSettings?.socialPreview
											?.recommendedSize || "1200 × 630"
									)}
									accept="image/png,image/jpeg,image/webp"
									buttonLabel={socialPreviewChooserLabel}
									removeLabel={__("Remove Preview Image")}
									previewLabel={__(
										"Default social preview image"
									)}
									preview={socialPreviewImageSource || null}
									fileName={socialPreviewFile?.name}
									disabled={
										!canManageSiteSettings || isUpdating
									}
									inputRef={socialPreviewInputRef}
									onChange={handleSocialPreviewChange}
									onRemove={
										showSocialPreviewRemove
											? handleRemoveSocialPreview
											: undefined
									}
								/>
							</div>
						</section>
					</>
				)}
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
