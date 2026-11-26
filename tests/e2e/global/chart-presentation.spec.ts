import { expect, test } from "@playwright/test";

import { mockDashboardSession } from "../fixtures/dashboard.fixture";

test("traffic chart retains its canvas through range and chart-type changes", async ({
	page,
}) => {
	await page.setViewportSize({ width: 320, height: 850 });
	await page.emulateMedia({ reducedMotion: "reduce" });
	await page.route("**/api/v1/**", (route) =>
		route.fulfill({ json: { data: {} } })
	);
	await page.route(
		/\/api\/v1\/analytics\/(activity|recent-clicks)(?:\?|$)/,
		(route) => route.fulfill({ json: { data: [] } })
	);
	await mockDashboardSession(page, {
		capabilities: { view_analytics: true },
	});
	let finishRange!: () => void;
	const rangeReady = new Promise<void>((resolve) => {
		finishRange = resolve;
	});
	await page.route(/\/api\/v1\/analytics\?days=/, async (route) => {
		const days = new URL(route.request().url()).searchParams.get("days");
		if (days === "30") await rangeReady;
		const traffic =
			days === "7"
				? { labels: ["2026-11-24"], clicks: [12000], unique: [12000] }
				: days === "30"
					? {
							labels: ["2026-11-23", "2026-11-24", "2026-11-25"],
							clicks: [0, 18000, 1],
							unique: [0, 12000, 1],
						}
					: { labels: ["2026-11-24"], clicks: [0], unique: [0] };
		await route.fulfill({
			json: {
				data: {
					traffic,
					devices: [{ name: "Desktop", count: traffic.clicks[0] }],
				},
			},
		});
	});

	await page.goto("/dashboard");
	const chart = page.locator(".dashboard-traffic");
	const canvas = chart.locator("canvas");
	const seriesTable = chart.getByRole("table", { name: "Traffic Overview" });
	await expect(seriesTable.getByRole("cell", { name: "12,000" })).toHaveCount(
		2
	);
	const originalCanvas = await canvas.elementHandle();
	try {
		await page
			.getByRole("button", { name: "Dashboard time range" })
			.click();
		await page.getByRole("option", { name: "Last 30 days" }).click();
		await expect(
			page.locator(".dashboard-page-traffic-grid")
		).toHaveAttribute("aria-busy", "true");
		await expect(canvas).toBeVisible();
	} finally {
		finishRange();
	}
	await expect(
		seriesTable.getByRole("cell", { name: "18,000" })
	).toBeAttached();
	const bar = chart.getByRole("button", { name: "Bar Chart" });
	await bar.press("Enter");
	await expect(bar).toHaveAttribute("aria-pressed", "true");
	expect(await originalCanvas?.evaluate((node) => node.isConnected)).toBe(
		true
	);
	expect(
		await page.evaluate(() => document.documentElement.scrollWidth)
	).toBe(320);

	await page.getByRole("button", { name: "Dashboard time range" }).click();
	await page.getByRole("option", { name: "Last 90 days" }).click();
	await expect(chart.getByText("No traffic data available")).toBeVisible();
	await expect(canvas).toHaveCount(0);
});

test("dashboard charts repaint with the selected theme and traffic cards align", async ({
	page,
}) => {
	await page.setViewportSize({ width: 1440, height: 920 });
	await page.emulateMedia({ reducedMotion: "reduce" });
	await page.addInitScript(() => localStorage.setItem("theme", "light"));
	await page.route("**/api/v1/**", (route) =>
		route.fulfill({ json: { data: {} } })
	);
	await page.route(
		/\/api\/v1\/analytics\/(activity|recent-clicks)(?:\?|$)/,
		(route) => route.fulfill({ json: { data: [] } })
	);
	await mockDashboardSession(page, {
		capabilities: { view_analytics: true },
	});
	await page.route(/\/api\/v1\/analytics\?days=/, (route) =>
		route.fulfill({
			json: {
				data: {
					traffic: {
						labels: ["2026-11-23", "2026-11-24", "2026-11-25"],
						clicks: [5, 11, 8],
						unique: [2, 4, 3],
					},
					devices: [
						{ name: "Desktop", count: 60 },
						{ name: "Mobile", count: 40 },
					],
				},
			},
		})
	);
	await page.goto("/dashboard");

	const traffic = page.locator(".dashboard-traffic canvas");
	const donut = page.locator(".metric-donut-chart canvas");
	await expect(traffic).toBeVisible();
	await expect(donut).toBeVisible();
	const trafficNode = await traffic.elementHandle();
	const donutNode = await donut.elementHandle();
	const hasSeriesColors = (canvas: HTMLCanvasElement) => {
		const context = canvas.getContext("2d");
		if (!context) return false;
		const palette = getComputedStyle(canvas);
		const colors = ["--chart-primary", "--chart-secondary"].map((token) =>
			palette.getPropertyValue(token).trim()
		);
		const pixels = context.getImageData(
			0,
			0,
			canvas.width,
			canvas.height
		).data;
		return colors.every((color) => {
			const rgb = Number.parseInt(color.slice(1), 16);
			for (let index = 0; index < pixels.length; index += 4) {
				if (
					pixels[index] === rgb >> 16 &&
					pixels[index + 1] === ((rgb >> 8) & 255) &&
					pixels[index + 2] === (rgb & 255) &&
					pixels[index + 3] === 255
				)
					return true;
			}
			return false;
		});
	};

	for (const theme of ["light", "dark", "light"]) {
		if (
			theme !==
			(await page
				.locator("html")
				.evaluate((node) =>
					node.classList.contains("dark") ? "dark" : "light"
				))
		) {
			await page.getByRole("button", { name: "Toggle theme" }).click();
		}
		await expect(page.locator("html")).toHaveCSS("color-scheme", theme);
		await expect.poll(() => traffic.evaluate(hasSeriesColors)).toBe(true);
		await expect.poll(() => donut.evaluate(hasSeriesColors)).toBe(true);
	}
	expect(await trafficNode?.evaluate((node) => node.isConnected)).toBe(true);
	expect(await donutNode?.evaluate((node) => node.isConnected)).toBe(true);
	const chartBounds = await page.locator(".dashboard-traffic").boundingBox();
	const recentBounds = await page
		.locator(".dashboard-recent-clicks")
		.boundingBox();
	expect(recentBounds?.height).toBeCloseTo(chartBounds?.height ?? 0, 0);
});
