import { test, expect } from "@playwright/test";
import {
	formatDateOnly,
	formatLocalizedDateTime,
	formatRelativeTime,
	getActiveLocale,
	getActiveTimeZone,
} from "../../client/shared/dates";
import { formatTtlDuration } from "../../client/shared/formatting";

test.describe("Date Utilities", () => {
	test("getActiveLocale returns valid locale string", () => {
		const locale = getActiveLocale();
		expect(typeof locale).toBe("string");
		expect(locale.length).toBeGreaterThan(0);
	});

	test("getActiveTimeZone returns valid timezone string", () => {
		const tz = getActiveTimeZone();
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
		expect(formatTtlDuration(45)).toBe("45s");
		expect(formatTtlDuration(0)).toBe("0s");
		expect(formatTtlDuration(null)).toBe("0s");
	});
});
