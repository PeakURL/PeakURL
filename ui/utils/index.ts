/**
 * Shared dashboard utility exports.
 *
 * Feature code should import common helpers from this barrel while the actual
 * implementations stay grouped in focused utility modules.
 */

export { cn } from "./classNames";
export { addFilter, applyFilters, removeFilter } from "./hooks";
export { getFaviconPreviewUrl, getManagedFaviconUrl } from "./favicon";
export {
	addGeneratorTag,
	applyDocumentFavicon,
	setDocumentLocale,
} from "./document";
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
	getSearchShortcutLabel,
	isMacPlatform,
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
export {
	formatByteSize,
	formatCount,
	formatDate,
	formatDateTimeValue,
	formatNumber,
} from "./formatters";
export { getTimeZoneOptions, normalizeSiteTimeFormat } from "./timezones";
export type { SiteTimeFormat } from "./timezones";
