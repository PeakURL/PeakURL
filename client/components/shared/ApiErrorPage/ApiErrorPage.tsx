import { Database, RefreshCw, Route, ServerCog } from "lucide-react";
import { useState } from "react";

import { API_CLIENT_BASE_URL, PEAKURL_DEBUG } from "@constants";
import { Button } from "@/components/ui";
import { __, sprintf } from "@/i18n";
import { extractErrorMessage, getErrorStatus } from "@/shared/errors";

import { StatusLayout } from "../StatusLayout";
import type { ApiErrorPageProps } from "../types";

// Keep retry feedback visible long enough to avoid a flicker on fast failures.
const RETRY_FEEDBACK_MS = 800;

function getStatusText(error: unknown): string {
	const status = getErrorStatus(error);

	return null === status
		? __("No HTTP response")
		: sprintf(__("HTTP %s"), String(status));
}

function isFetchNoise(message: string): boolean {
	const normalized = message.toLowerCase();

	return (
		normalized.includes("failed to fetch") ||
		normalized.includes("load failed") ||
		normalized.includes("networkerror")
	);
}

function getDetailText(error: unknown): string {
	const status = getErrorStatus(error);
	const message = extractErrorMessage(error);

	if (null !== status) {
		return sprintf(
			__(
				"The session check returned HTTP %s before PeakURL could confirm the current user."
			),
			String(status)
		);
	}

	if (message && !isFetchNoise(message)) {
		return message;
	}

	return __(
		"The browser did not receive a usable response from the backend. The PHP service may be stopped, unreachable, or blocked before it can answer."
	);
}

function getChecks() {
	return [
		{
			icon: ServerCog,
			title: __("PHP runtime"),
			description: __(
				"Confirm the PHP app service or web server is running."
			),
		},
		{
			icon: Database,
			title: __("Database"),
			description: __(
				"Check that the database host, credentials, and schema are reachable."
			),
		},
		{
			icon: Route,
			title: __("API route"),
			description: sprintf(
				__("Make sure requests to %s route to the PeakURL backend."),
				API_CLIENT_BASE_URL
			),
		},
	];
}

export function ApiErrorPage({
	error,
	onRetry,
	isRetrying = false,
	title = __("Unable to connect to the backend"),
	description = __(
		"PeakURL couldn’t complete the connection to your server. Please try again."
	),
}: ApiErrorPageProps) {
	const [localRetrying, setLocalRetrying] = useState(false);
	const retrying = isRetrying || localRetrying;
	const [prevError, setPrevError] = useState(error);
	const [lastError, setLastError] = useState(error);

	if (error !== undefined && error !== prevError) {
		setPrevError(error);
		setLastError(error);
	}

	const visibleError = error ?? lastError;
	const statusText = getStatusText(visibleError);
	const detailText = getDetailText(visibleError);
	const checks = getChecks();
	const handleRetry = async () => {
		if (retrying) {
			return;
		}

		setLocalRetrying(true);

		// Match the spinner cycle while the session check retries.
		const minRotationTime = new Promise((resolve) =>
			setTimeout(resolve, RETRY_FEEDBACK_MS)
		);

		try {
			await Promise.all([onRetry(), minRotationTime]);
		} finally {
			setLocalRetrying(false);
		}
	};

	return (
		<StatusLayout
			title={title}
			description={description}
			label={__("Backend connection error")}
			note={__(
				"If the problem persists, contact your site administrator."
			)}
			actions={
				<Button
					size="md"
					icon={RefreshCw}
					onClick={handleRetry}
					disabled={retrying}
					className={`api-error-retry ${retrying ? "api-error-retry-checking" : ""}`}
				>
					{retrying
						? __("Checking connection")
						: __("Retry connection")}
				</Button>
			}
		>
			{PEAKURL_DEBUG ? (
				<section
					className="api-error-debug"
					aria-labelledby="connection-details-heading"
				>
					<header className="api-error-debug-header">
						<h2
							id="connection-details-heading"
							className="api-error-debug-title-group"
						>
							{__("Connection details")}
						</h2>
						<span className="api-error-debug-status">
							{statusText}
						</span>
					</header>

					<div className="api-error-debug-body">
						<dl className="api-error-details-grid">
							<div className="api-error-detail">
								<dt>{__("API endpoint")}</dt>
								<dd>
									<code>{API_CLIENT_BASE_URL}</code>
								</dd>
							</div>
							<div className="api-error-detail">
								<dt>{__("What went wrong")}</dt>
								<dd>{detailText}</dd>
							</div>
						</dl>

						<div className="api-error-checks">
							<p className="api-error-checks-title">
								{__("Check these first")}
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
													{item.description}
												</span>
											</span>
										</li>
									);
								})}
							</ul>
						</div>
					</div>
				</section>
			) : null}
		</StatusLayout>
	);
}
