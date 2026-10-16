import { test, expect, type Page } from "@playwright/test";
import { mockDrawerPages } from "../fixtures/drawer.fixture";

const targets = [
	{
		path: "/dashboard",
		label: "Refresh dashboard data",
		api: "**/api/v1/analytics?*",
		data: {
			totalClicks: 0,
			totalLinks: 0,
			uniqueClicks: 0,
			uniqueClickRate: 0,
		},
	},
	{
		path: "/dashboard/links",
		label: "Refresh links",
		api: "**/api/v1/urls?*",
		data: { items: [], meta: { totalItems: 0, totalPages: 1 } },
	},
	{
		path: "/dashboard/activity",
		label: "Refresh activity history",
		api: "**/api/v1/analytics/activity/history?*",
		data: { items: [], meta: { totalItems: 0, totalPages: 1 } },
	},
] as const;

async function mockRefreshPage(page: Page, target: (typeof targets)[number]) {
	await mockDrawerPages(page);
	await page.route("**/api/v1/analytics/activity", (route) =>
		route.fulfill({ json: { data: [] } })
	);
	await page.route("**/api/v1/analytics/recent-clicks?*", (route) =>
		route.fulfill({ json: { data: [] } })
	);
	const control = {
		requests: 0,
		failed: false,
		responseReady: Promise.resolve(),
	};
	await page.route(target.api, async (route) => {
		control.requests++;
		await control.responseReady;
		await route.fulfill({
			status: control.failed ? 503 : 200,
			json: { data: target.data },
		});
	});
	await page.goto(target.path);
	const button = page.getByRole("button", {
		name: target.label,
		exact: true,
	});
	await expect(button).toBeEnabled();
	await button.evaluate((element) => {
		element.dataset.completedTurns = "0";
		element.addEventListener("animationiteration", (event) => {
			if (
				!(event instanceof AnimationEvent) ||
				event.animationName !== "refresh-button-turn"
			)
				return;
			element.dataset.completedTurns = String(
				Number(element.dataset.completedTurns) + 1
			);
			element.dataset.elapsedTurns = String(event.elapsedTime);
		});
	});
	return { button, control };
}

for (const target of targets) {
	test(`${target.label} completes full turns for fast, slow and failed requests`, async ({
		page,
	}) => {
		const { button, control } = await mockRefreshPage(page, target);
		await button.click();
		await expect(button).toBeDisabled();
		await expect(button).toHaveAttribute("aria-busy", "true");
		await expect(button).toBeEnabled();
		await expect(button).toHaveAttribute("data-completed-turns", "1");
		await expect(button).toHaveAttribute("data-elapsed-turns", "0.8");
		await expect(button.locator("svg")).toHaveCSS("animation-name", "none");

		let releaseResponse = () => {};
		control.responseReady = new Promise<void>((resolve) => {
			releaseResponse = resolve;
		});
		await button.evaluate((element) => {
			element.dataset.completedTurns = "0";
		});
		const previousRequests = control.requests;
		await button.click();
		await expect(button).toHaveAttribute("data-completed-turns", "1");
		await expect(button).toBeDisabled();
		const activeRequests = control.requests;
		expect(activeRequests).toBeGreaterThan(previousRequests);
		// A native second click must remain inert while the request is pending.
		await button.evaluate((element) => element.click());
		expect(control.requests).toBe(activeRequests);
		releaseResponse();
		await expect(button).toBeEnabled();
		const elapsed = Number(await button.getAttribute("data-elapsed-turns"));
		expect(elapsed).toBeGreaterThanOrEqual(1.6);
		expect(elapsed / 0.8).toBeCloseTo(Math.round(elapsed / 0.8), 5);

		control.failed = true;
		await button.click();
		await expect(button).toBeEnabled();
		await expect(button).toHaveAttribute("aria-busy", "false");
	});
}

test("refresh feedback honors reduced motion without leaving controls disabled", async ({
	page,
}) => {
	await page.emulateMedia({ reducedMotion: "reduce" });
	for (const target of targets) {
		const { button, control } = await mockRefreshPage(page, target);
		const previousRequests = control.requests;
		await button.click();
		await expect
			.poll(() => control.requests)
			.toBeGreaterThan(previousRequests);
		await expect(button).toBeEnabled();
		await expect(button.locator("svg")).toHaveCSS("animation-name", "none");
	}
});
