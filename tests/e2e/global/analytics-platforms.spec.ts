import { test, expect } from "@playwright/test";

import { mockDrawerPages } from "../fixtures/drawer.fixture";

for (const width of [1440, 320]) {
	test(`parser platform names render in overview and link analytics at ${width}px`, async ({
		page,
	}) => {
		await page.setViewportSize({ width, height: 1000 });
		await mockDrawerPages(page, width === 320 ? "rtl" : "ltr");
		if (width === 320)
			await page.addInitScript(() =>
				localStorage.setItem("theme", "dark")
			);
		const metrics = (names: string[]) =>
			names.map((name) => ({ name, count: 12 }));
		const data = {
			totalClicks: 72,
			uniqueClicks: 60,
			uniqueClickRate: 83.3,
			totalLinks: 1,
			devices: metrics([
				"Desktop",
				"Mobile",
				"Tablet",
				"TV",
				"Console",
				"Unknown Device",
			]),
			browsers: metrics([
				"Samsung Internet",
				"Android Browser",
				"Safari",
				"Brave",
				"Internet Explorer",
			]),
			operatingSystems: metrics([
				"iOS",
				"iPadOS",
				"Android",
				"macOS",
				"Windows",
				"Linux",
				"Chrome OS",
				"PlayStation",
				"webOS",
				"KaiOS",
				"Unrecognized OS",
			]),
		};
		await page.route("**/api/v1/analytics?*", (route) =>
			route.fulfill({ json: { data } })
		);
		await page.route("**/api/v1/analytics/recent-clicks?*", (route) =>
			route.fulfill({ json: { data: [] } })
		);
		await page.route("**/api/v1/analytics/activity", (route) =>
			route.fulfill({ json: { data: [] } })
		);
		await page.route("**/api/v1/analytics/url/*/stats?*", (route) =>
			route.fulfill({ json: { data } })
		);
		await page.goto("/dashboard");
		const overview = page.locator(".dashboard-devices");
		const overviewSystems = overview
			.locator(".dashboard-devices-section")
			.filter({
				has: page.getByRole("heading", {
					name: "Top Operating Systems",
				}),
			});
		await expect(
			overviewSystems.locator(".dashboard-devices-section-row")
		).toHaveCount(3);
		const mobileMarks = await overviewSystems
			.locator(".analytics-platform-mark")
			.evaluateAll((nodes) =>
				nodes.map((node) => getComputedStyle(node).maskImage)
			);
		expect(mobileMarks).toHaveLength(3);
		expect(mobileMarks[0]).not.toBe("none");
		expect(mobileMarks[0]).toBe(mobileMarks[1]);
		expect(mobileMarks[2]).not.toBe(mobileMarks[0]);
		await expect(overview.locator(".lucide-tv")).toBeVisible();
		await expect(overview.locator(".lucide-gamepad-2")).toBeVisible();
		await expect(overview.locator(".lucide-circle-help")).toBeVisible();
		await page.goto("/dashboard/links");
		await page.locator(".links-row-action-stats").first().click();
		const drawer = page.locator(".links-drawer-panel");
		await drawer.getByRole("tab", { name: "Traffic Sources" }).click();
		await expect(drawer.locator(".links-device-os-item")).toHaveCount(11);
		await expect(
			drawer.locator(".links-device-list-row .analytics-platform-mark")
		).toHaveCount(5);
		for (const name of ["iOS", "iPadOS", "Android"]) {
			const row = drawer
				.locator(".links-device-os-item")
				.filter({ has: page.getByText(name, { exact: true }) });
			await expect(row.locator(".links-device-os-count")).toHaveText(
				"12"
			);
			await expect(row.locator(".analytics-platform-mark")).not.toHaveCSS(
				"mask-image",
				"none"
			);
		}
		const unknown = drawer
			.locator(".links-device-os-item")
			.filter({ hasText: "Unrecognized OS" });
		await expect(unknown.locator(".lucide-monitor")).toBeVisible();
		const kaiOS = drawer
			.locator(".links-device-os-item")
			.filter({ hasText: "KaiOS" });
		await expect(kaiOS.locator(".lucide-monitor")).toBeVisible();
		await expect(kaiOS.locator(".analytics-platform-mark")).toHaveCount(0);
		await expect(
			drawer.locator(".links-device-types .lucide-tv")
		).toBeVisible();
		await expect(
			drawer.locator(".links-device-types .lucide-gamepad-2")
		).toBeVisible();
		const bounds = await drawer.evaluate((node) => ({
			width: node.clientWidth,
			scrollWidth: node.scrollWidth,
		}));
		expect(bounds.scrollWidth).toBeLessThanOrEqual(bounds.width);
		if (process.env.PEAKURL_UI_PREVIEW) {
			await drawer
				.locator(".links-device-os-list")
				.scrollIntoViewIfNeeded();
			await page.screenshot({
				path: `/tmp/peakurl-mobile-platforms-${width}.png`,
			});
		}
	});
}
