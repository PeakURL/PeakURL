import type { ChangeEvent, SubmitEvent } from "react";
import { useEffect, useMemo, useRef, useState } from "react";
import { ImageOff, Trash2, X } from "lucide-react";
import { PEAKURL_SITE_NAME } from "@constants";
import {
	Button,
	Input,
	Select,
	TextArea,
	type SelectOption,
} from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { getInstalledLanguageLabel } from "@/i18n/languages";
import {
	buildFaviconPreviewUrl,
	cn,
	getTimeZoneOptions,
	normalizeSiteTimeFormat,
	type SiteTimeFormat,
} from "@/utils";
import type { GeneralFormState } from "../../types";
import type { GeneralTabProps } from "../types";

const isPngFaviconFile = (file: File | null): file is File =>
	Boolean(file && "image/png" === file.type && /\.png$/i.test(file.name));

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
	const [generalForm, setGeneralForm] =
		useState<GeneralFormState>(initialForm);
	const [siteName, setSiteName] = useState(
		siteSettings?.siteName || PEAKURL_SITE_NAME || "PeakURL"
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
	const [uploadedPreviewUrl, setUploadedPreviewUrl] = useState("");
	const fileInputRef = useRef<HTMLInputElement | null>(null);

	useEffect(() => {
		setGeneralForm(initialForm);
	}, [initialForm]);

	useEffect(() => {
		setSiteLanguage(siteSettings?.siteLanguage || "en_US");
	}, [siteSettings?.siteLanguage]);

	useEffect(() => {
		setSiteTimezone(siteSettings?.siteTimezone || "UTC");
	}, [siteSettings?.siteTimezone]);

	useEffect(() => {
		setSiteTimeFormat(
			normalizeSiteTimeFormat(siteSettings?.siteTimeFormat)
		);
	}, [siteSettings?.siteTimeFormat]);

	useEffect(() => {
		setSiteName(siteSettings?.siteName || PEAKURL_SITE_NAME || "PeakURL");
	}, [siteSettings?.siteName]);

	useEffect(() => {
		setFaviconFile(null);
		setRemoveFavicon(false);

		if (fileInputRef.current) {
			fileInputRef.current.value = "";
		}
	}, [siteSettings?.favicon?.updatedAt]);

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
			siteLanguage,
			siteTimezone,
			siteTimeFormat,
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
	const hasCustomFavicon = Boolean(siteSettings?.favicon?.isCustom);
	const storedPreviewUrl = useMemo(
		() =>
			hasCustomFavicon
				? buildFaviconPreviewUrl(siteSettings?.favicon?.updatedAt)
				: "",
		[hasCustomFavicon, siteSettings?.favicon?.updatedAt]
	);
	const previewUrl = useMemo(() => {
		if (removeFavicon) {
			return "";
		}

		return uploadedPreviewUrl || storedPreviewUrl;
	}, [removeFavicon, storedPreviewUrl, uploadedPreviewUrl]);

	useEffect(() => {
		if (!isPngFaviconFile(faviconFile)) {
			setUploadedPreviewUrl("");
			return;
		}

		const nextPreviewUrl = URL.createObjectURL(faviconFile);
		setUploadedPreviewUrl(nextPreviewUrl);

		return () => {
			URL.revokeObjectURL(nextPreviewUrl);
		};
	}, [faviconFile]);

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

	const canManageSiteSettings =
		siteSettings?.canManageSiteSettings && !isLoadingSiteSettings;
	const hasConfiguredFavicon = hasCustomFavicon;
	const showPreview = Boolean(previewUrl);
	const showRemoveButton =
		Boolean(faviconFile) || (!removeFavicon && hasCustomFavicon);
	const previewSiteName =
		siteName.trim() ||
		PEAKURL_SITE_NAME ||
		siteSettings?.siteName ||
		"PeakURL";
	const chooserLabel =
		showPreview || hasConfiguredFavicon
			? __("Replace Favicon")
			: __("Choose Favicon");

	return (
		<div className="settings-general">
			<form onSubmit={handleSubmit} className="settings-general-form">
				<h2 className="settings-general-title">
					{__("Profile Information")}
				</h2>
				<div className="settings-general-grid">
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
					<Input
						label={__("Site title")}
						value={siteName}
						onChange={(event) => setSiteName(event.target.value)}
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
				<div className="settings-general-favicon">
					<div className="settings-general-favicon-header">
						<div className="settings-general-favicon-content">
							<div className="settings-general-favicon-copy">
								<h3 className="settings-general-favicon-title">
									{__("Site Favicon")}
								</h3>
								<p className="settings-general-favicon-summary">
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
											!canManageSiteSettings || isUpdating
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
								showPreview
									? "settings-general-favicon-preview-filled"
									: "settings-general-favicon-preview-empty-state"
							)}
						>
							{showPreview ? (
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
											aria-label={__("Remove Favicon")}
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
										<img
											src={previewUrl}
											alt={__("Current favicon preview")}
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
													<img
														src={previewUrl}
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
