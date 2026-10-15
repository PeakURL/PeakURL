import type { To } from "react-router";

/** The logo uses the same artwork with an optional name and navigation target. */
export type LogoSize = "sm" | "md" | "lg" | "xl";
export type LogoTone = "light" | "dark";

type LogoAppearance = {
	size?: LogoSize;
	tone?: LogoTone;
	/** Hide the wordmark when the adjacent heading already names PeakURL. */
	showName?: boolean;
	className?: string;
};

/** Internal and external destinations are mutually exclusive. */
export type LogoProps = LogoAppearance &
	(
		| { to: To; href?: never }
		| { href: string; to?: never }
		| { to?: never; href?: never }
	);

/**
 * Props for the page shown when the UI cannot reach the PHP API.
 */
export interface ApiErrorPageProps {
	/** Raw RTK Query error object used to explain the failed request. */
	error?: unknown;

	/** Retries the failed session/API connectivity check. */
	onRetry: () => Promise<unknown> | void;

	/** Whether a retry request is currently in progress. */
	isRetrying?: boolean;

	/** Optional title override for the failure state. */
	title?: string;

	/** Optional supporting copy override for the failure state. */
	description?: string;
}
