import { test, expect } from "@playwright/test";
import {
	formatDateOnly,
	formatLocalizedDateTime,
	formatRelativeTime,
	getActiveLocale,
	getActiveTimeZone,
} from "../../ui/shared/dates";

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
});
