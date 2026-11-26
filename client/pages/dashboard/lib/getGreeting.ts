import { sprintf, __ } from "@/i18n";
import { getSiteTimeZone } from "@/shared/dates";

export type DayPeriod = "morning" | "afternoon" | "evening";

export interface OverviewGreetingInput {
	firstName?: string | null;
	displayName?: string | null;
	timeZone?: string | null;
	now?: Date;
}

const MORNING_START_HOUR = 5;
const AFTERNOON_START_HOUR = 12;
const EVENING_START_HOUR = 18;

/**
 * Return the local 0-23 hour in a given IANA time zone, falling back to browser time.
 */
export function getHourInTimeZone(
	now: Date = new Date(),
	timeZone?: string | null
): number {
	const candidate = timeZone?.trim() || getSiteTimeZone();

	if (candidate) {
		try {
			const hourPart = new Intl.DateTimeFormat("en-US", {
				timeZone: candidate,
				hour: "numeric",
				hourCycle: "h23",
			})
				.formatToParts(now)
				.find((part) => part.type === "hour")?.value;

			if (hourPart !== undefined) {
				const parsed = Number.parseInt(hourPart, 10);
				if (Number.isInteger(parsed) && parsed >= 0) {
					return parsed % 24;
				}
			}
		} catch {
			// Fall back to the browser's local hour when the timezone string is invalid.
		}
	}

	return now.getHours();
}

/**
 * Map a 0-23 hour to morning (05-11), afternoon (12-17), or evening (18-04).
 */
export function getDayPeriodForHour(hour: number): DayPeriod {
	const normalized = ((Math.trunc(hour) % 24) + 24) % 24;

	if (normalized >= MORNING_START_HOUR && normalized < AFTERNOON_START_HOUR) {
		return "morning";
	}

	if (normalized >= AFTERNOON_START_HOUR && normalized < EVENING_START_HOUR) {
		return "afternoon";
	}

	return "evening";
}

/**
 * Return the personal greeting name without naively splitting multi-part names.
 */
export function getGreetingName(
	firstName?: string | null,
	displayName?: string | null
): string | null {
	const normalizedFirst = firstName?.trim();
	if (normalizedFirst) {
		return normalizedFirst;
	}

	const normalizedDisplay = displayName?.trim();
	if (normalizedDisplay) {
		return normalizedDisplay;
	}

	return null;
}

/**
 * Return a localized, time-of-day overview greeting for the authenticated user.
 */
export function getOverviewGreeting({
	firstName,
	displayName,
	timeZone,
	now = new Date(),
}: OverviewGreetingInput = {}): string {
	const hour = getHourInTimeZone(now, timeZone);
	const period = getDayPeriodForHour(hour);
	const name = getGreetingName(firstName, displayName);

	if (name) {
		switch (period) {
			case "morning":
				/* translators: %s: user's first name or display name */
				return sprintf(__("Good morning, %s"), name);
			case "afternoon":
				/* translators: %s: user's first name or display name */
				return sprintf(__("Good afternoon, %s"), name);
			case "evening":
				/* translators: %s: user's first name or display name */
				return sprintf(__("Good evening, %s"), name);
		}
	}

	switch (period) {
		case "morning":
			return __("Good morning");
		case "afternoon":
			return __("Good afternoon");
		case "evening":
			return __("Good evening");
	}
}
