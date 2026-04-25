import { useState } from "react";
import { useCreateUrlMutation } from "@/store/slices/api";
import {
	buildShortUrl,
	escUrl,
	getErrorMessage,
	isRelativeUrl,
	isFutureLocalDateTime,
	toIsoFromLocalDateTime,
} from "@/utils";
import { ChevronDown, ChevronUp } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import type { SubmitEvent } from "react";

import Header from "./Header";
import StatusMessages from "./StatusMessages";
import MainInputs from "./MainInputs";
import AdvancedOptions from "./AdvancedOptions";
import type { CreateUrlPayload } from "./types";

const UrlShorteningForm = () => {
	const direction = isDocumentRtl() ? "rtl" : "ltr";
	const [destinationUrl, setDestinationUrl] = useState("");
	const [alias, setAlias] = useState("");
	const [title, setTitle] = useState("");
	const [error, setError] = useState("");
	const [success, setSuccess] = useState("");
	const [showAdvanced, setShowAdvanced] = useState(false);

	// Advanced options state
	const [password, setPassword] = useState("");
	const [expirationDate, setExpirationDate] = useState("");
	const [expirationTime, setExpirationTime] = useState("");
	const [utmSource, setUtmSource] = useState("");
	const [utmMedium, setUtmMedium] = useState("");
	const [utmCampaign, setUtmCampaign] = useState("");
	const [utmTerm, setUtmTerm] = useState("");
	const [utmContent, setUtmContent] = useState("");

	const [createUrl, { isLoading }] = useCreateUrlMutation();

	const buildUrlWithUtm = (url: string) => {
		if (
			!utmSource &&
			!utmMedium &&
			!utmCampaign &&
			!utmTerm &&
			!utmContent
		) {
			return url;
		}

		const urlObj = new URL(url);
		const params = new URLSearchParams(urlObj.search);

		if (utmSource) params.set("utm_source", utmSource);
		if (utmMedium) params.set("utm_medium", utmMedium);
		if (utmCampaign) params.set("utm_campaign", utmCampaign);
		if (utmTerm) params.set("utm_term", utmTerm);
		if (utmContent) params.set("utm_content", utmContent);

		urlObj.search = params.toString();
		return urlObj.toString();
	};

	const handleSubmit = async (e: SubmitEvent<HTMLFormElement>) => {
		e.preventDefault();
		setError("");
		setSuccess("");

		// Validation
		if (!destinationUrl.trim()) {
			setError(__("Please enter a URL"));
			return;
		}

		const normalizedDestinationUrl = escUrl(destinationUrl);

		if (
			!normalizedDestinationUrl ||
			isRelativeUrl(normalizedDestinationUrl)
		) {
			setError(
				__(
					"Please enter a valid URL (must include http:// or https://)"
				)
			);
			return;
		}

		try {
			const urlWithUtm = buildUrlWithUtm(normalizedDestinationUrl);

			const payload: CreateUrlPayload = {
				destinationUrl: urlWithUtm,
				alias: alias.replace(/\s+/g, "") || undefined,
				title: title.trim() || undefined,
			};

			// Add password if provided
			if (password.trim()) {
				payload.password = password.trim();
			}

			// Add expiration if provided
			if (expirationDate) {
				const expDateTime = expirationTime
					? `${expirationDate}T${expirationTime}`
					: `${expirationDate}T23:59:59`;

				if (!isFutureLocalDateTime(expDateTime)) {
					setError(__("Expiration time must be in the future."));
					return;
				}

				payload.expiresAt = toIsoFromLocalDateTime(expDateTime);
			}

			const result = await createUrl(payload).unwrap();
			const shortUrl = result.data
				? buildShortUrl(result.data)
				: payload.destinationUrl;
			setSuccess(
				sprintf(__("Link shortened successfully! %s"), shortUrl)
			);
			// Reset form
			setDestinationUrl("");
			setAlias("");
			setTitle("");
			setPassword("");
			setExpirationDate("");
			setExpirationTime("");
			setUtmSource("");
			setUtmMedium("");
			setUtmCampaign("");
			setUtmTerm("");
			setUtmContent("");
			setShowAdvanced(false);

			// Clear success message after 5 seconds
			setTimeout(() => setSuccess(""), 5000);
		} catch (err) {
			setError(
				getErrorMessage(
					err,
					__("Failed to create short link. Please try again.")
				)
			);
		}
	};

	return (
		<div className="links-form">
			<Header />

			<StatusMessages error={error} success={success} />

			<form onSubmit={handleSubmit} className="links-form-element">
				<MainInputs
					destinationUrl={destinationUrl}
					setDestinationUrl={setDestinationUrl}
					alias={alias}
					setAlias={setAlias}
					isLoading={isLoading}
				/>

				{/* Advanced Options Toggle */}
				<button
					type="button"
					onClick={() => setShowAdvanced(!showAdvanced)}
					dir={direction}
					className="links-form-advanced-toggle"
				>
					<svg
						className="links-form-advanced-toggle-icon"
						fill="none"
						stroke="currentColor"
						viewBox="0 0 24 24"
					>
						<path
							strokeLinecap="round"
							strokeLinejoin="round"
							strokeWidth={2}
							d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"
						/>
					</svg>
					{__("Advanced Options")}
					{showAdvanced ? (
						<ChevronUp className="links-form-advanced-toggle-icon" />
					) : (
						<ChevronDown className="links-form-advanced-toggle-icon" />
					)}
				</button>

				{/* Advanced Options Panel */}
				{showAdvanced && (
					<AdvancedOptions
						title={title}
						setTitle={setTitle}
						password={password}
						setPassword={setPassword}
						expirationDate={expirationDate}
						setExpirationDate={setExpirationDate}
						expirationTime={expirationTime}
						setExpirationTime={setExpirationTime}
						utmSource={utmSource}
						setUtmSource={setUtmSource}
						utmMedium={utmMedium}
						setUtmMedium={setUtmMedium}
						utmCampaign={utmCampaign}
						setUtmCampaign={setUtmCampaign}
						utmTerm={utmTerm}
						setUtmTerm={setUtmTerm}
						utmContent={utmContent}
						setUtmContent={setUtmContent}
					/>
				)}
			</form>
		</div>
	);
};

export default UrlShorteningForm;
