import type { SelectOption } from "@/components";
import { getActiveLocale } from "./dateFormatting";

export type SiteTimeFormat = "12" | "24";

const DEFAULT_TIMEZONE = "UTC";
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

function getSupportedTimeZoneValues(): string[] {
	const supportedValuesOf = (
		Intl as typeof Intl & {
			supportedValuesOf?: (key: "timeZone") => string[];
		}
	).supportedValuesOf;

	if (typeof supportedValuesOf === "function") {
		try {
			return supportedValuesOf("timeZone");
		} catch {
			return FALLBACK_TIMEZONES;
		}
	}

	return FALLBACK_TIMEZONES;
}

function getTimeZoneOffsetLabel(timeZone: string, locale: string): string {
	try {
		const formatter = new Intl.DateTimeFormat(locale, {
			timeZone,
			hour: "2-digit",
			minute: "2-digit",
			timeZoneName: "shortOffset",
		});
		const timeZoneName = formatter
			.formatToParts(new Date())
			.find((part) => part.type === "timeZoneName")?.value;

		return timeZoneName || timeZone;
	} catch {
		return timeZone;
	}
}

function formatTimeZoneLabel(timeZone: string, locale: string): string {
	const name = timeZone.replace(/_/g, " ");
	const offset = getTimeZoneOffsetLabel(timeZone, locale);

	return `${name} (${offset})`;
}

export function getTimeZoneOptions(): SelectOption<string>[] {
	const locale = getActiveLocale();
	const timeZones = Array.from(
		new Set([DEFAULT_TIMEZONE, ...getSupportedTimeZoneValues()])
	).sort((left, right) => left.localeCompare(right));

	return timeZones.map((timeZone) => ({
		value: timeZone,
		label: formatTimeZoneLabel(timeZone, locale),
	}));
}

export function normalizeSiteTimeFormat(value?: string | null): SiteTimeFormat {
	return value === "24" ? "24" : "12";
}
