import {
	formatLocalizedDateTime,
	formatRelativeTime,
	getActiveLocale,
} from "./dateFormatting";

/**
 * Join class names while dropping falsy entries.
 *
 * @param classes - The class names to join.
 * @return The joined class name string.
 */
export function cn(
	...classes: Array<string | false | null | undefined>
): string {
	return classes.filter(Boolean).join(" ");
}

/**
 * Format a timestamp for dashboard activity feeds.
 *
 * Recent values are shown relatively, while older values use a medium date.
 * Invalid or missing values resolve to an empty string.
 *
 * @param dateString - The raw date value to format.
 * @return The formatted date string.
 */
export function formatDate(
	dateString: string | number | Date | null | undefined
): string {
	if (null == dateString) {
		return "";
	}

	const date = new Date(dateString);
	if (Number.isNaN(date.getTime())) {
		return "";
	}

	const now = new Date();
	const diffDays =
		Math.abs(now.getTime() - date.getTime()) / (1000 * 60 * 60 * 24);

	/* Use relative formatting for dates within the last week. */
	if (diffDays < 7) {
		return formatRelativeTime(date, {
			style: "long",
			numeric: "auto",
		});
	}

	/* Fall back to localized medium date format for older entries. */
	return formatLocalizedDateTime(date, {
		dateStyle: "medium",
	});
}

/**
 * Format large metric counts into compact dashboard-friendly labels.
 *
 * @param num - The number to format.
 * @return The formatted compact string.
 */
export function formatNumber(num: number): string {
	return new Intl.NumberFormat(getActiveLocale(), {
		notation: "compact",
		maximumFractionDigits: 1,
	}).format(num);
}

/**
 * Generate a random six-character alias suggestion.
 *
 * @return A random alphanumeric alias.
 */
export function generateRandomAlias(): string {
	const chars =
		"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
	let result = "";

	/* Build a random string character by character. */
	for (let i = 0; i < 6; i += 1) {
		result += chars.charAt(Math.floor(Math.random() * chars.length));
	}

	return result;
}

export { addFilter, applyFilters, removeFilter } from "./hooks";
export { getFaviconPreviewUrl, getManagedFaviconUrl } from "./favicon";
export { isRelativeUrl, sanitizeImageUrl, sanitizeUrl } from "./url";
export type { ImageSource } from "./url";
export { getShortUrl, getSiteUrl, getLinkHost } from "./linkHelpers";
export { getCountryFlagEmoji } from "./countries";
export {
	formatLinkExportItems,
	downloadLinkExport,
	createLinkExportFile,
	serializeLinkExport,
} from "./linkExport";
export { getLinkDisplayTitle, normalizeLinkTitle } from "./linkTitles";
export {
	extractAliasFromShortUrl,
	normalizeCsvHeader,
	parseCsvRows,
	serializeCsv,
} from "./csv";
export {
	getLocalDateTimeValue,
	getLocalDateValue,
	isFutureLocalDateTime,
	toIsoFromLocalDateTime,
	toLocalDateTimeValue,
} from "./dateTime";
export {
	getInstallRecovery,
	redirectToInstallRecovery,
} from "./installRecovery";
export {
	copyToClipboard,
	downloadBrowserFile,
	requestClosestFormSubmit,
	requestControlFormSubmit,
	requestFormSubmit,
} from "./dom";
export { extractErrorMessage, getErrorMessage, getErrorStatus } from "./errors";
export { getAvatarInitials, getGravatarUrl } from "./avatar";
export {
	getLinkStatsPath,
	getLinksSearchPath,
	findDashboardRouteMatches,
	findDashboardUserMatches,
	getDashboardSearchValueFromLocation,
	getDashboardSearchPath,
} from "./dashboardSearch";
export {
	formatDateOnly,
	formatLocalizedDateTime,
	formatRelativeTime,
	getActiveLocale,
	getActiveTimeZone,
	getZonedDateKey,
} from "./dateFormatting";
export { formatByteSize, formatCount, formatDateTimeValue } from "./formatters";
export { getTimeZoneOptions, normalizeSiteTimeFormat } from "./timezones";
export type { SiteTimeFormat } from "./timezones";

/**
 * Resolve the theme color classes associated with a link tag.
 *
 * @param tag - The tag slug to look up.
 * @return The CSS class names for the tag.
 */
export function getTagColor(tag: string): string {
	const colors: Record<string, string> = {
		marketing:
			"bg-purple-100 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300",
		"social-media":
			"bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300",
		"email-campaign":
			"bg-green-100 dark:bg-green-900/20 text-green-700 dark:text-green-300",
		"product-launch":
			"bg-orange-100 dark:bg-orange-900/20 text-orange-700 dark:text-orange-300",
		internal:
			"bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300",
		personal:
			"bg-pink-100 dark:bg-pink-900/20 text-pink-700 dark:text-pink-300",
	};

	return (
		colors[tag] ||
		"bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300"
	);
}
