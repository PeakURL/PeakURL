import { test, expect } from "@playwright/test";
import type { Locator } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const capabilities = {
	view_links: true,
	manage_site_settings: true,
	manage_api_keys: true,
	manage_integrations: true,
	manage_mcp: true,
	manage_performance: true,
	manage_mail_delivery: true,
	manage_location_data: true,
	manage_updates: true,
};

const tabs = [
	["general", "General"],
	["security", "Security"],
	["api", "API Keys"],
	["integrations", "Integrations"],
	["mcp", "MCP Server"],
	["performance", "Performance"],
	["email", "Email SMTP"],
	["location", "Location Data"],
	["updates", "Updates"],
] as const;

async function horizontalEdges(element: Locator) {
	return element.evaluate((node) => {
		const { left, right } = node.getBoundingClientRect();
		return [Math.round(left), Math.round(right)];
	});
}

for (const direction of ["ltr", "rtl"] as const) {
	test(`Settings navigation keeps the active link and underline in view at 320px (${direction})`, async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities, direction });
		await page.setViewportSize({ width: 320, height: 844 });
		await page.goto("/dashboard/settings/updates");

		const navigation = page.getByRole("navigation", { name: "Settings" });
		const active = navigation.locator('[aria-current="page"]');
		const line = navigation.locator(".settings-navigation-indicator");
		await expect(active).toHaveText("Updates");
		await expect(active).toBeInViewport({ ratio: 1 });
		await expect
			.poll(() => horizontalEdges(line))
			.toEqual(await horizontalEdges(active));
		await expect(navigation.getByRole("link")).toHaveCount(9);
		await expect(navigation.getByRole("tab")).toHaveCount(0);
		expect(
			await navigation.evaluate(
				(nav) => nav.scrollWidth > nav.clientWidth
			)
		).toBe(true);
		expect(
			await page.evaluate(() => document.documentElement.scrollWidth)
		).toBe(320);

		await page.keyboard.press("Tab");
		await navigation.getByRole("link", { name: "General" }).click();
		await expect(active).toHaveText("General");
		await expect(active).toBeInViewport({ ratio: 1 });
		await expect
			.poll(() => horizontalEdges(line))
			.toEqual(await horizontalEdges(active));
		await page.goBack();
		await expect(active).toHaveText("Updates");
		await expect(active).toBeInViewport({ ratio: 1 });
		await page.goForward();
		await expect(active).toHaveText("General");
	});
}

test("Settings destinations expose one localized page heading and preserve direct access and breadcrumbs", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	const navigation = page.getByRole("navigation", { name: "Settings" });
	const breadcrumb = page.getByRole("navigation", { name: "Breadcrumb" });
	for (const [route, title] of tabs) {
		await test.step(title, async () => {
			await page.goto(`/dashboard/settings/${route}`);
			const heading = page.getByRole("heading", { level: 1 });
			await expect(heading).toHaveText(title);
			await expect(
				page.locator(".settings-page-header .page-header-description")
			).not.toBeEmpty();
			await expect(
				breadcrumb.locator('[aria-current="page"]')
			).toHaveText(title);
			const active = navigation.locator('[aria-current="page"]');
			await expect(active).toHaveAttribute(
				"href",
				`/dashboard/settings/${route}`
			);
			if (route === "mcp") await expect(active).toContainText("New");
			const navBox = await navigation.boundingBox();
			const headingBox = await heading.boundingBox();
			expect(navBox!.y + navBox!.height).toBeLessThan(headingBox!.y);
		});
	}
});

test("permission-filtered links and direct navigation use the same access rules", async ({
	page,
}) => {
	await mockDashboardSession(page, {
		role: "editor",
		capabilities: { view_links: true, manage_api_keys: true },
	});
	await page.goto("/dashboard/settings/email");
	await expect(page).toHaveURL(/\/dashboard\/settings\/general$/);
	const navigation = page.getByRole("navigation", { name: "Settings" });
	await expect(navigation.getByRole("link")).toHaveText([
		"General",
		"Security",
		"API Keys",
	]);
	await navigation.getByRole("link", { name: "API Keys" }).click();
	await expect(
		page.getByRole("heading", { name: "API Keys", level: 1 })
	).toBeVisible();
});
