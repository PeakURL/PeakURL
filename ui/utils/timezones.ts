import type { SelectOption } from "@/components";
import { getActiveLocale } from "./dateFormatting";

/**
 * Supported time display formats (12 or 24 hour).
 */
export type SiteTimeFormat = "12" | "24";

const DEFAULT_TIMEZONE = "UTC";

/**
 * Hardcoded fallback list for environments without Intl.supportedValuesOf.
 */
const FALLBACK_TIMEZONES = [
	"UTC",
	"Africa/Cairo",
	"America/Chicago",
	"America/Los_Angeles",
	"America/New_York",
	"America/Toronto",
	"Asia/Dubai",
	"Asia/Karachi",
	"Asia/Kolkata",
	"Asia/Riyadh",
	"Asia/Singapore",
	"Asia/Tokyo",
	"Australia/Sydney",
	"Europe/Berlin",
	"Europe/London",
	"Europe/Paris",
];

/**
 * Return the list of IANA time zones supported by the current environment.
 *
 * @return The list of time zone identifiers.
 */
function getSupportedTimeZoneValues(): string[] {
	const supportedValuesOf = (
		Intl as typeof Intl & {
			supportedValuesOf?: (key: "timeZone") => string[];
		}
	).supportedValuesOf;

	/* Use modern Intl APIs to resolve available zones if supported. */
	if (typeof supportedValuesOf === "function") {
		try {
			return supportedValuesOf("timeZone");
		} catch {
			return FALLBACK_TIMEZONES;
		}
	}

	return FALLBACK_TIMEZONES;
}

/**
 * Generate a localized offset label (e.g., GMT+5) for a time zone.
 *
 * @param timeZone - The IANA time zone identifier.
 * @param locale   - The locale to use for formatting.
 * @return The formatted offset label.
 */
function getTimeZoneOffsetLabel(timeZone: string, locale: string): string {
	try {
		const formatter = new Intl.DateTimeFormat(locale, {
			timeZone,
			hour: "2-digit",
			minute: "2-digit",
			timeZoneName: "shortOffset",
		});

		/* Extract the timezone name part from the formatted parts. */
		const timeZoneName = formatter
			.formatToParts(new Date())
			.find((part) => part.type === "timeZoneName")?.value;

		return timeZoneName || timeZone;
	} catch {
		return timeZone;
	}
}

/**
 * Format a user-friendly label for a time zone selection option.
 *
 * @param timeZone - The IANA time zone identifier.
 * @param locale   - The locale to use for formatting.
 * @return The descriptive label.
 */
function formatTimeZoneLabel(timeZone: string, locale: string): string {
	const name = timeZone.replace(/_/g, " ");
	const offset = getTimeZoneOffsetLabel(timeZone, locale);

	return `${name} (${offset})`;
}

/**
 * Resolve the full list of time zone options for dashboard settings.
 *
 * @return The list of selection options.
 */
export function getTimeZoneOptions(): SelectOption<string>[] {
	const locale = getActiveLocale();

	/* Combine, deduplicate, and sort available time zones. */
	const timeZones = Array.from(
		new Set([DEFAULT_TIMEZONE, ...getSupportedTimeZoneValues()])
	).sort((a, b) => a.localeCompare(b));

	return timeZones.map((timeZone) => ({
		value: timeZone,
		label: formatTimeZoneLabel(timeZone, locale),
	}));
}

/**
 * Normalize a raw string into a valid SiteTimeFormat.
 *
 * @param value - The raw format value.
 * @return The normalized time format.
 */
export function normalizeSiteTimeFormat(value?: string | null): SiteTimeFormat {
	return value === "24" ? "24" : "12";
}
