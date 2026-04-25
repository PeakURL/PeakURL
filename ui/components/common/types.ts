import type { To } from 'react-router-dom';

/**
 * Supported size presets for the shared logo component.
 */
export type LogoSize = 'xs' | 'sm' | 'md' | 'lg';

/**
 * Props for the standalone logo mark.
 */
export interface LogoProps {
	/** Visual size preset applied to the logo. */
	size?: LogoSize;

	/** Additional utility classes for the outer wrapper. */
	className?: string;
}

/**
 * Supported size presets for the combined icon-and-wordmark lockup.
 */
export type BrandLockupSize = 'sm' | 'md' | 'lg';

/**
 * Supported color treatments for the brand lockup.
 */
export type BrandLockupTone = 'light' | 'dark';

/**
 * Props for the brand lockup component.
 */
export interface BrandLockupProps {
	/** Visual size preset applied to the lockup. */
	size?: BrandLockupSize;

	/** Color treatment used for the icon and wordmark. */
	tone?: BrandLockupTone;

	/** Optional route destination when the lockup should render as a link. */
	to?: To;

	/** Additional utility classes for the outer wrapper. */
	className?: string;

	/** Additional utility classes for the text node. */
	textClassName?: string;
}

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
