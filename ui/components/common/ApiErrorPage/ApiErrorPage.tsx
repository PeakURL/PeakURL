import {
	AlertTriangle,
	Database,
	RefreshCw,
	Route,
	ServerCog,
	ServerOff,
} from 'lucide-react';
import { useRef, useState } from 'react';

import { API_CLIENT_BASE_URL, PEAKURL_DEBUG, PEAKURL_NAME } from '@constants';
import { Button } from '@/components/ui';
import { __, sprintf } from '@/i18n';
import { extractErrorMessage, getErrorStatus } from '@/utils';
import { BrandLockup } from '../BrandLockup';
import type { ApiErrorPageProps } from '../types';

function getStatusText(error: unknown): string {
	const status = getErrorStatus(error);

	return null === status
		? __('No HTTP response')
		: sprintf(__('HTTP %s'), String(status));
}

function isFetchNoise(message: string): boolean {
	const normalized = message.toLowerCase();

	return (
		normalized.includes('failed to fetch') ||
		normalized.includes('load failed') ||
		normalized.includes('networkerror')
	);
}

function getDetailText(error: unknown): string {
	const status = getErrorStatus(error);
	const message = extractErrorMessage(error);

	if (null !== status) {
		return sprintf(
			__(
				'The session check returned HTTP %s before PeakURL could confirm the current user.'
			),
			String(status)
		);
	}

	if (message && !isFetchNoise(message)) {
		return message;
	}

	return __(
		'The browser did not receive a usable response from the backend. The PHP service may be stopped, unreachable, or blocked before it can answer.'
	);
}

function getChecks() {
	return [
		{
			icon: ServerCog,
			title: __('PHP runtime'),
			description: __(
				'Confirm the PHP app service or web server is running.'
			),
		},
		{
			icon: Database,
			title: __('Database'),
			description: __(
				'Check that the database host, credentials, and schema are reachable.'
			),
		},
		{
			icon: Route,
			title: __('API route'),
			description: sprintf(
				__('Make sure requests to %s route to the PeakURL backend.'),
				API_CLIENT_BASE_URL
			),
		},
	];
}

export function ApiErrorPage({
	error,
	onRetry,
	isRetrying = false,
	title = sprintf(__('%s cannot connect to the backend'), PEAKURL_NAME),
	description = __(
		'The dashboard could not complete the session check. Start the PHP service and database, then retry.'
	),
}: ApiErrorPageProps) {
	const [localRetrying, setLocalRetrying] = useState(false);
	const [isFlashing, setIsFlashing] = useState(false);
	const retrying = isRetrying || localRetrying;
	const lastError = useRef(error);
	if (undefined !== error) {
		lastError.current = error;
	}
	const visibleError = error ?? lastError.current;
	const statusText = getStatusText(visibleError);
	const detailText = getDetailText(visibleError);
	const checks = getChecks();
	const handleRetry = async () => {
		if (retrying) {
			return;
		}

		setIsFlashing(true);
		setLocalRetrying(true);

		// Ensure at least one full rotation (800ms per CSS animation)
		const minRotationTime = new Promise((resolve) =>
			setTimeout(resolve, 800)
		);

		try {
			await Promise.all([onRetry(), minRotationTime]);
		} finally {
			setLocalRetrying(false);
			setTimeout(() => setIsFlashing(false), 400);
		}
	};

	return (
		<main id="page-container" className="api-error-page">
			<div className="api-error-container">
				<header className="api-error-header">
					<BrandLockup size="md" />
					<span className="api-error-status-pill">
						{__('Service unavailable')}
					</span>
				</header>

				<div className="api-error-main">
					<div className="api-error-hero">
						<div className="api-error-icon">
							<ServerOff size={32} aria-hidden="true" />
						</div>

						<div className="api-error-copy">
							<p className="api-error-kicker">
								{__('Connection issue')}
							</p>
							<h1
								id="page-heading"
								className="api-error-title"
							>
								{title}
							</h1>
							<p className="api-error-description">
								{description}
							</p>
						</div>

						<div className="api-error-actions">
							<Button
								size="md"
								icon={RefreshCw}
								onClick={handleRetry}
								disabled={retrying}
								className={`api-error-retry ${
									retrying
										? 'api-error-retry-checking'
										: ''
								} ${
									isFlashing
										? 'api-error-retry-flashing'
										: ''
								}`}
							>
								{retrying
									? __('Checking connection')
									: __('Retry connection')}
							</Button>
						</div>

						<p className="api-error-note">
							{__(
								'If the problem continues, ask the site administrator to check the server.'
							)}
						</p>
					</div>

					{PEAKURL_DEBUG ? (
						<div className="api-error-debug">
							<div className="api-error-debug-header">
								<div className="api-error-debug-title-group">
									<div className="api-error-debug-icon">
										<AlertTriangle
											size={16}
											aria-hidden="true"
										/>
									</div>
									<div className="api-error-debug-title">
										{__('Debug details')}
									</div>
								</div>
								<span className="api-error-badge">
									{statusText}
								</span>
							</div>

							<div className="api-error-debug-body">
								<dl className="api-error-details-grid">
									<div className="api-error-detail">
										<dt>{__('API endpoint')}</dt>
										<dd>
											<code>
												{API_CLIENT_BASE_URL}
											</code>
										</dd>
									</div>
									<div className="api-error-detail">
										<dt>{__('What went wrong')}</dt>
										<dd>{detailText}</dd>
									</div>
								</dl>

								<div className="api-error-checks">
									<p className="api-error-checks-title">
										{__('Check these first')}
									</p>
									<ul className="api-error-check-list">
										{checks.map((item) => {
											const Icon = item.icon;

											return (
												<li
													key={item.title}
													className="api-error-check-item"
												>
													<span className="api-error-check-icon-box">
														<Icon
															size={18}
															aria-hidden="true"
														/>
													</span>
													<span className="api-error-check-text">
														<span className="api-error-check-title">
															{item.title}
														</span>
														<span className="api-error-check-description">
															{
																item.description
															}
														</span>
													</span>
												</li>
											);
										})}
									</ul>
								</div>
							</div>
						</div>
					) : null}
				</div>
			</div>
		</main>
	);
}

