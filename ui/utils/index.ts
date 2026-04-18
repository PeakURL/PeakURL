import { formatLocalizedDateTime, formatRelativeTime } from './dateFormatting';

/**
 * Joins class names while dropping falsy entries.
 */
export function cn(
	...classes: Array<string | false | null | undefined>
): string {
	return classes.filter(Boolean).join(' ');
}

/**
 * Formats a timestamp for dashboard activity feeds.
 *
 * Recent values are shown relatively, while older values use a medium date.
 * Invalid or missing values resolve to an empty string.
 */
export function formatDate(
	dateString: string | number | Date | null | undefined
): string {
	if (null == dateString) {
		return '';
	}

	const date = new Date(dateString);
	if (Number.isNaN(date.getTime())) {
		return '';
	}

	const now = new Date();
	const diffDays =
		Math.abs(now.getTime() - date.getTime()) / (1000 * 60 * 60 * 24);

	if (diffDays < 7) {
		return formatRelativeTime(date, {
			style: 'long',
			numeric: 'auto',
		});
	}

	return formatLocalizedDateTime(date, {
		dateStyle: 'medium',
	});
}

/**
 * Formats large metric counts into compact dashboard-friendly labels.
 */
export function formatNumber(num: number): string {
	if (num >= 1000000) {
		return (num / 1000000).toFixed(1) + 'M';
	}
	if (num >= 1000) {
		return (num / 1000).toFixed(1) + 'K';
	}
	return num.toString();
}

/**
 * Generates a random six-character alias suggestion.
 */
export function generateRandomAlias(): string {
	const chars =
		'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	let result = '';
	for (let i = 0; i < 6; i += 1) {
		result += chars.charAt(Math.floor(Math.random() * chars.length));
	}
	return result;
}

export { addFilter, applyFilters, removeFilter } from './hooks';
export {
	buildFaviconPreviewUrl,
	buildManagedFaviconUrl,
} from './favicon';
export {
	escUrl,
	isRelativeUrl,
} from './url';
export { buildShortUrl, getSiteUrl, resolveLinkHost } from './linkHelpers';
export {
	buildLinkExportItems,
	downloadLinkExport,
	getLinkExportFile,
	serializeLinkExport,
} from './linkExport';
export { getLinkDisplayTitle, normalizeLinkTitle } from './linkTitles';
export {
	extractAliasFromShortUrl,
	normalizeCsvHeader,
	parseCsvRows,
	serializeCsv,
} from './csv';
export {
	getLocalDateTimeValue,
	getLocalDateValue,
	isFutureLocalDateTime,
	toIsoFromLocalDateTime,
	toLocalDateTimeValue,
} from './dateTime';
export {
	getInstallRecovery,
	redirectToInstallRecovery,
} from './installRecovery';
export {
	copyToClipboard,
	requestClosestFormSubmit,
	requestControlFormSubmit,
	requestFormSubmit,
} from './dom';
export { extractErrorMessage, getErrorMessage, getErrorStatus } from './errors';
export { getAvatarInitials, getGravatarUrl } from './avatar';
export {
	buildLinkStatsPath,
	buildLinksSearchPath,
	findDashboardRouteMatches,
	findDashboardUserMatches,
	getDashboardSearchValueFromLocation,
	resolveDashboardSearchPath,
} from './dashboardSearch';
export { formatLocalizedDateTime, formatRelativeTime } from './dateFormatting';
export { formatByteSize, formatCount, formatDateTimeValue } from './formatters';

/**
 * Resolves the theme color classes associated with a link tag.
 */
export function getTagColor(tag: string): string {
	const colors: Record<string, string> = {
		marketing:
			'bg-purple-100 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300',
		'social-media':
			'bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300',
		'email-campaign':
			'bg-green-100 dark:bg-green-900/20 text-green-700 dark:text-green-300',
		'product-launch':
			'bg-orange-100 dark:bg-orange-900/20 text-orange-700 dark:text-orange-300',
		internal:
			'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300',
		personal:
			'bg-pink-100 dark:bg-pink-900/20 text-pink-700 dark:text-pink-300',
	};
	return (
		colors[tag] ||
		'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'
	);
}
