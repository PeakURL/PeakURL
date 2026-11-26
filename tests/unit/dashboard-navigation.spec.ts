import { test, expect } from "@playwright/test";

import { getBreadcrumbs } from "../../client/pages/layout/dashboard/Header/getBreadcrumbs";
import {
	getDayPeriodForHour,
	getOverviewGreeting,
	getGreetingName,
	getHourInTimeZone,
} from "../../client/pages/dashboard/lib/getGreeting";

test.describe("Dashboard Breadcrumbs", () => {
	test("returns nested, tool, settings, plugin, and fallback trails", () => {
		expect(getBreadcrumbs({ pathname: "/dashboard" })).toEqual([
			{ label: "PeakURL", to: "/dashboard" },
			{ label: "Overview" },
		]);

		expect(getBreadcrumbs({ pathname: "/dashboard/links" })).toEqual([
			{ label: "PeakURL", to: "/dashboard" },
			{ label: "Links" },
		]);

		expect(
			getBreadcrumbs({ pathname: "/dashboard/tools/import/file" })
		).toEqual([
			{ label: "PeakURL", to: "/dashboard" },
			{ label: "Tools" },
			{ label: "Import" },
		]);

		expect(
			getBreadcrumbs({
				pathname: "/dashboard/settings/security",
			})
		).toEqual([
			{ label: "PeakURL", to: "/dashboard" },
			{ label: "Settings", to: "/dashboard/settings/general" },
			{ label: "Security" },
		]);

		expect(
			getBreadcrumbs({
				pathname: "/dashboard/plugins/page/sample-plugin/reports",
				canManagePlugins: false,
				pluginPages: [
					{
						pluginSlug: "sample-plugin",
						pageId: "reports",
						title: "Reports Overview",
						menuTitle: "Reports",
					},
				],
			})
		).toEqual([
			{ label: "PeakURL", to: "/dashboard" },
			{ label: "Plugins", to: undefined },
			{ label: "Reports" },
		]);

		expect(
			getBreadcrumbs({
				pathname: "/dashboard/plugins/add",
				canManagePlugins: true,
			})
		).toEqual([
			{ label: "PeakURL", to: "/dashboard" },
			{ label: "Plugins", to: "/dashboard/plugins" },
			{ label: "Add New" },
		]);

		expect(
			getBreadcrumbs({ pathname: "/dashboard/unknown-route" })
		).toEqual([
			{ label: "PeakURL", to: "/dashboard" },
			{ label: "Page Not Found" },
		]);
	});
});

test.describe("Overview Greeting", () => {
	test("maps day periods accurately across midnight and boundaries", () => {
		expect(getDayPeriodForHour(5)).toBe("morning");
		expect(getDayPeriodForHour(11)).toBe("morning");
		expect(getDayPeriodForHour(12)).toBe("afternoon");
		expect(getDayPeriodForHour(17)).toBe("afternoon");
		expect(getDayPeriodForHour(18)).toBe("evening");
		expect(getDayPeriodForHour(23)).toBe("evening");
		expect(getDayPeriodForHour(0)).toBe("evening");
		expect(getDayPeriodForHour(4)).toBe("evening");
	});

	test("uses firstName or displayName safely without naive splitting", () => {
		expect(getGreetingName("  Abd  ", "Abd Ur Rehman")).toBe("Abd");
		expect(getGreetingName("", "Abd Ur Rehman")).toBe("Abd Ur Rehman");
		expect(getGreetingName("   ", "   ")).toBeNull();
		expect(getGreetingName(null, null)).toBeNull();
	});

	test("respects configured timezone and handles missing/invalid values", () => {
		// 2026-11-25T08:30:00Z is 08:30 in UTC (morning) and 03:30 in America/New_York (evening)
		const sample = new Date("2026-11-25T08:30:00Z");

		expect(getHourInTimeZone(sample, "UTC")).toBe(8);
		expect(
			getOverviewGreeting({
				firstName: "Amina",
				timeZone: "UTC",
				now: sample,
			})
		).toBe("Good morning, Amina");

		expect(getHourInTimeZone(sample, "America/New_York")).toBe(3);
		expect(
			getOverviewGreeting({
				firstName: "Amina",
				timeZone: "America/New_York",
				now: sample,
			})
		).toBe("Good evening, Amina");

		// Evening without personal name degrades cleanly without trailing punctuation
		expect(
			getOverviewGreeting({
				firstName: null,
				displayName: "",
				timeZone: "America/New_York",
				now: sample,
			})
		).toBe("Good evening");

		// Invalid timezone falls back gracefully instead of throwing
		expect(getHourInTimeZone(sample, "Invalid/Zone")).toBe(
			sample.getHours()
		);
	});
});
