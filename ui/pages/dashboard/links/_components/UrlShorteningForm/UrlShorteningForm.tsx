// @ts-nocheck
import { useState } from 'react';
import { useCreateUrlMutation } from '@/store/slices/api/urls';
import {
	buildShortUrl,
	getDefaultShortUrlOrigin,
	isFutureLocalDateTime,
	toIsoFromLocalDateTime,
} from '@/utils';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { __, sprintf } from '@/i18n';

import Header from './Header';
import StatusMessages from './StatusMessages';
import MainInputs from './MainInputs';
import AdvancedOptions from './AdvancedOptions';

const UrlShorteningForm = () => {
	const [destinationUrl, setDestinationUrl] = useState('');
	const [alias, setAlias] = useState('');
	const [title, setTitle] = useState('');
	const [error, setError] = useState('');
	const [success, setSuccess] = useState('');
	const [showAdvanced, setShowAdvanced] = useState(false);

	// Advanced options state
	const [password, setPassword] = useState('');
	const [expirationDate, setExpirationDate] = useState('');
	const [expirationTime, setExpirationTime] = useState('');
	const [utmSource, setUtmSource] = useState('');
	const [utmMedium, setUtmMedium] = useState('');
	const [utmCampaign, setUtmCampaign] = useState('');
	const [utmTerm, setUtmTerm] = useState('');
	const [utmContent, setUtmContent] = useState('');

	const [createUrl, { isLoading }] = useCreateUrlMutation();

	const validateUrl = (url) => {
		try {
			new URL(url);
			return true;
		} catch {
			return false;
		}
	};

	const buildUrlWithUtm = (url) => {
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

		if (utmSource) params.set('utm_source', utmSource);
		if (utmMedium) params.set('utm_medium', utmMedium);
		if (utmCampaign) params.set('utm_campaign', utmCampaign);
		if (utmTerm) params.set('utm_term', utmTerm);
		if (utmContent) params.set('utm_content', utmContent);

		urlObj.search = params.toString();
		return urlObj.toString();
	};

	const handleSubmit = async (e) => {
		e.preventDefault();
		setError('');
		setSuccess('');

		// Validation
		if (!destinationUrl.trim()) {
			setError(__('Please enter a URL'));
			return;
		}

		if (!validateUrl(destinationUrl)) {
			setError(__('Please enter a valid URL (must include http:// or https://)'));
			return;
		}

		try {
			const urlWithUtm = buildUrlWithUtm(destinationUrl.trim());

			const payload = {
				destinationUrl: urlWithUtm,
				alias: alias.replace(/\s+/g, '') || undefined,
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
					setError(__('Expiration time must be in the future.'));
					return;
				}

				payload.expiresAt = toIsoFromLocalDateTime(expDateTime);
			}

			const result = await createUrl(payload).unwrap();
			const shortUrlOrigin = getDefaultShortUrlOrigin(
				typeof window !== 'undefined' ? window.location.origin : ''
			);
			const shortUrl = buildShortUrl(result.data, shortUrlOrigin);
			setSuccess(
				sprintf(__('Link shortened successfully! %s'), shortUrl)
			);
			// Reset form
			setDestinationUrl('');
			setAlias('');
			setTitle('');
			setPassword('');
			setExpirationDate('');
			setExpirationTime('');
			setUtmSource('');
			setUtmMedium('');
			setUtmCampaign('');
			setUtmTerm('');
			setUtmContent('');
			setShowAdvanced(false);

			// Clear success message after 5 seconds
			setTimeout(() => setSuccess(''), 5000);
		} catch (err) {
			setError(
				err?.data?.message ||
					__('Failed to create short link. Please try again.')
			);
		}
	};

	return (
		<div className="bg-surface rounded-xl border border-stroke shadow-sm p-6">
			<Header />

			<StatusMessages error={error} success={success} />

			<form onSubmit={handleSubmit}>
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
					className="mt-5 inline-flex items-center gap-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors"
				>
					<svg
						className="w-4 h-4"
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
					{__('Advanced Options')}
					{showAdvanced ? (
						<ChevronUp className="w-4 h-4" />
					) : (
						<ChevronDown className="w-4 h-4" />
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
