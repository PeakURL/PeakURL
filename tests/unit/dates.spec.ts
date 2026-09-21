import { test, expect } from "@playwright/test";
import {
	formatDateOnly,
	formatLocalizedDateTime,
	formatRelativeTime,
	getActiveLocale,
	getSiteTimeZone,
} from "../../client/shared/dates";
import {
	toIsoFromLocalDateTime,
	toLocalDateTimeValue,
	getLocalDateValue,
	getLocalDateTimeValue,
} from "../../client/pages/dashboard/links/lib";
import {
	formatNumber,
	formatTtlDuration,
} from "../../client/shared/formatting";

test.describe("Date Utilities", () => {
	test("getActiveLocale returns valid locale string", () => {
		const locale = getActiveLocale();
		expect(typeof locale).toBe("string");
		expect(locale.length).toBeGreaterThan(0);
	});

	test("getSiteTimeZone returns valid timezone string", () => {
		const tz = getSiteTimeZone();
		expect(typeof tz).toBe("string");
		expect(tz.length).toBeGreaterThan(0);
	});

	test("formatDateOnly formats YYYY-MM-DD correctly", () => {
		const formatted = formatDateOnly("2026-09-11", {
			dateStyle: "short",
		});
		expect(typeof formatted).toBe("string");
		expect(formatted.length).toBeGreaterThan(0);
	});

	test("formatRelativeTime produces relative time descriptions", () => {
		const now = new Date("2026-09-11T12:00:00Z");
		const past = new Date("2026-09-09T12:00:00Z");
		const future = new Date("2026-09-13T12:00:00Z");

		const relativePast = formatRelativeTime(past, { now });
		expect(relativePast).toContain("2 days ago");

		const relativeFuture = formatRelativeTime(future, { now });
		expect(relativeFuture).toContain("in 2 days");
	});

	test("formatRelativeTime calculates relative time from absolute instant without timezone offset error", () => {
		const now = new Date("2026-09-14T17:00:10Z");
		const eventMomentsAgo = new Date("2026-09-14T17:00:05Z");

		const relative = formatRelativeTime(eventMomentsAgo, { now });
		expect(relative).toMatch(/second/i);
		expect(relative).not.toContain("hour");
	});

	test("formatRelativeTime formats remaining time accurately without premature promotion to 1 hour", () => {
		const now = new Date("2026-09-21T12:00:00Z");

		// Less than one minute: should display exact seconds, not 1 minute or 1 hour
		expect(
			formatRelativeTime(new Date("2026-09-21T12:00:55Z"), { now })
		).toBe("in 55 seconds");

		// Exactly one minute: should display 1 minute, not 1 hour
		expect(
			formatRelativeTime(new Date("2026-09-21T12:01:00Z"), { now })
		).toBe("in 1 minute");

		// Several minutes: should display appropriate minute count
		expect(
			formatRelativeTime(new Date("2026-09-21T12:02:00Z"), { now })
		).toBe("in 2 minutes");
		expect(
			formatRelativeTime(new Date("2026-09-21T12:17:00Z"), { now })
		).toBe("in 17 minutes");

		// Upper minute boundary: 59 minutes should display in 59 minutes, not 1 hour
		expect(
			formatRelativeTime(new Date("2026-09-21T12:59:00Z"), { now })
		).toBe("in 59 minutes");

		// 1 hour boundary: should display 1 hour
		expect(
			formatRelativeTime(new Date("2026-09-21T13:00:00Z"), { now })
		).toBe("in 1 hour");

		// Past timestamps
		expect(
			formatRelativeTime(new Date("2026-09-21T11:59:55Z"), { now })
		).toBe("5 seconds ago");
		expect(
			formatRelativeTime(new Date("2026-09-21T11:59:00Z"), { now })
		).toBe("1 minute ago");
	});

	test("formatLocalizedDateTime handles Date objects and ISO strings", () => {
		const resultFromDate = formatLocalizedDateTime(
			new Date("2026-09-11T15:30:00Z")
		);
		expect(typeof resultFromDate).toBe("string");
		expect(resultFromDate.length).toBeGreaterThan(0);

		const resultFromIso = formatLocalizedDateTime("2026-09-11T15:30:00Z");
		expect(typeof resultFromIso).toBe("string");
		expect(resultFromIso.length).toBeGreaterThan(0);

		expect(formatLocalizedDateTime(null)).toBe("");
		expect(formatLocalizedDateTime(undefined)).toBe("");
	});

	test("formatTtlDuration formats seconds into human readable duration strings", () => {
		expect(formatTtlDuration(604800)).toBe("7 days");
		expect(formatTtlDuration(86400)).toBe("1 day");
		expect(formatTtlDuration(172800)).toBe("2 days");
		expect(formatTtlDuration(3600)).toBe("1 hour");
		expect(formatTtlDuration(21600)).toBe("6 hours");
		expect(formatTtlDuration(60)).toBe("1 minute");
		expect(formatTtlDuration(180)).toBe("3 minutes");
		expect(formatTtlDuration(45)).toBe("45 seconds");
		expect(formatTtlDuration(61)).toBe("61 seconds");
		expect(formatTtlDuration(3661)).toBe("3661 seconds");
		expect(formatTtlDuration(0)).toBe("0 seconds");
		expect(formatTtlDuration(null)).toBe("0 seconds");
		expect(formatTtlDuration(undefined)).toBe("0 seconds");
		expect(formatTtlDuration(-5)).toBe("0 seconds");
		expect(formatTtlDuration("invalid")).toBe("0 seconds");
		expect(formatTtlDuration(null, "N/A")).toBe("N/A");
	});

	test("formatNumber formats normal values as exact localized counts and large values as compact labels", () => {
		// Normal values below 100,000 threshold
		expect(formatNumber(0)).toBe("0");
		expect(formatNumber(18)).toBe("18");
		expect(formatNumber(426)).toBe("426");
		expect(formatNumber(999)).toBe("999");
		expect(formatNumber(1000)).toBe("1,000");
		expect(formatNumber(9842)).toBe("9,842");
		expect(formatNumber(9999)).toBe("9,999");
		expect(formatNumber(10000)).toBe("10,000");
		expect(formatNumber(18400)).toBe("18,400");
		expect(formatNumber(99999)).toBe("99,999");

		// Large values at or above 100,000 threshold
		expect(formatNumber(100000)).toBe("100K");
		expect(formatNumber(999999)).toBe("1M");
		expect(formatNumber(1000000)).toBe("1M");
		expect(formatNumber(1000000000)).toBe("1B");
	});

	test.describe("Site-Timezone Architecture & Expiration Input Correctness", () => {
		const deterministicInstant = "2026-07-15T12:00:00Z";

		test("Case A & B — same UTC instant formats to respective local wall-clock times in Europe/London and America/New_York based on site timezone", () => {
			const londonFormatted = formatLocalizedDateTime(
				deterministicInstant,
				{
					timeZone: "Europe/London",
					dateStyle: "medium",
					timeStyle: "short",
				}
			);
			const nyFormatted = formatLocalizedDateTime(deterministicInstant, {
				timeZone: "America/New_York",
				dateStyle: "medium",
				timeStyle: "short",
			});

			// In summer (BST, UTC+1), 12:00 UTC is 13:00 / 1:00 PM
			expect(londonFormatted).toMatch(/1:00|13:00/);
			// In summer (EDT, UTC-4), 12:00 UTC is 08:00 / 8:00 AM
			expect(nyFormatted).toMatch(/8:00|08:00/);
			// The formatted strings must be distinct because the timezones are different
			expect(londonFormatted).not.toBe(nyFormatted);
		});

		test("Case C — relative time duration is based on absolute instants and remains identical regardless of viewer timezone", () => {
			const now = new Date("2026-07-15T12:00:00Z");
			const expiresAt = new Date("2026-07-15T12:24:00Z");

			const duration = formatRelativeTime(expiresAt, { now });
			expect(duration).toBe("in 24 minutes");

			// Past instant
			const past = new Date("2026-07-15T11:36:00Z");
			expect(formatRelativeTime(past, { now })).toBe("24 minutes ago");
		});

		test("Case D — site-timezone expiration input round-trip preserves exact UTC instant for London and New York", () => {
			// Site in Europe/London (BST, UTC+1 in July): user enters 10:30 local time
			const londonLocal = "2026-07-15T10:30";
			const londonIso = toIsoFromLocalDateTime(
				londonLocal,
				"Europe/London"
			);
			expect(londonIso).toBe("2026-07-15T09:30:00.000Z");

			// Round-trip back to local value in Europe/London
			const londonRoundTrip = toLocalDateTimeValue(
				londonIso,
				"Europe/London"
			);
			expect(londonRoundTrip).toBe(londonLocal);

			// Site in America/New_York (EDT, UTC-4 in July): user enters 10:30 local time
			const nyLocal = "2026-07-15T10:30";
			const nyIso = toIsoFromLocalDateTime(nyLocal, "America/New_York");
			expect(nyIso).toBe("2026-07-15T14:30:00.000Z");

			// Round-trip back to local value in America/New_York
			const nyRoundTrip = toLocalDateTimeValue(nyIso, "America/New_York");
			expect(nyRoundTrip).toBe(nyLocal);
		});

		test("Case E — local input conversion resolves correctly across DST boundaries in configured site timezone", () => {
			// America/New_York DST change in 2026: Sunday, March 8 (clocks move from UTC-5 to UTC-4)
			// Winter (UTC-5): 2026-03-07 10:30 EST -> 15:30 UTC
			const nyWinterLocal = "2026-03-07T10:30";
			const nyWinterIso = toIsoFromLocalDateTime(
				nyWinterLocal,
				"America/New_York"
			);
			expect(nyWinterIso).toBe("2026-03-07T15:30:00.000Z");
			expect(toLocalDateTimeValue(nyWinterIso, "America/New_York")).toBe(
				nyWinterLocal
			);

			// Summer (UTC-4): 2026-03-09 10:30 EDT -> 14:30 UTC
			const nySummerLocal = "2026-03-09T10:30";
			const nySummerIso = toIsoFromLocalDateTime(
				nySummerLocal,
				"America/New_York"
			);
			expect(nySummerIso).toBe("2026-03-09T14:30:00.000Z");
			expect(toLocalDateTimeValue(nySummerIso, "America/New_York")).toBe(
				nySummerLocal
			);

			// Europe/London DST change in 2026: Sunday, March 29 (clocks move from UTC+0 to UTC+1)
			// Winter (UTC+0): 2026-03-28 10:30 GMT -> 10:30 UTC
			const londonWinterLocal = "2026-03-28T10:30";
			const londonWinterIso = toIsoFromLocalDateTime(
				londonWinterLocal,
				"Europe/London"
			);
			expect(londonWinterIso).toBe("2026-03-28T10:30:00.000Z");
			expect(toLocalDateTimeValue(londonWinterIso, "Europe/London")).toBe(
				londonWinterLocal
			);

			// Summer (UTC+1): 2026-03-30 10:30 BST -> 09:30 UTC
			const londonSummerLocal = "2026-03-30T10:30";
			const londonSummerIso = toIsoFromLocalDateTime(
				londonSummerLocal,
				"Europe/London"
			);
			expect(londonSummerIso).toBe("2026-03-30T09:30:00.000Z");
			expect(toLocalDateTimeValue(londonSummerIso, "Europe/London")).toBe(
				londonSummerLocal
			);
		});

		test("Case F — browser timezone offset does not alter resolved UTC instant", () => {
			const originalGetTimezoneOffset = Date.prototype.getTimezoneOffset;
			try {
				// Mock host browser offset as Tokyo (UTC+9 -> -540 min)
				Date.prototype.getTimezoneOffset = () => -540;
				const tokyoResult = toIsoFromLocalDateTime(
					"2026-07-15T10:30",
					"Europe/London"
				);

				// Mock host browser offset as New York (EDT, UTC-4 -> +240 min)
				Date.prototype.getTimezoneOffset = () => 240;
				const nyResult = toIsoFromLocalDateTime(
					"2026-07-15T10:30",
					"Europe/London"
				);

				// Mock host browser offset as UTC (0 min)
				Date.prototype.getTimezoneOffset = () => 0;
				const utcResult = toIsoFromLocalDateTime(
					"2026-07-15T10:30",
					"Europe/London"
				);

				expect(tokyoResult).toBe("2026-07-15T09:30:00.000Z");
				expect(nyResult).toBe("2026-07-15T09:30:00.000Z");
				expect(utcResult).toBe("2026-07-15T09:30:00.000Z");
			} finally {
				Date.prototype.getTimezoneOffset = originalGetTimezoneOffset;
			}
		});
	});
});
