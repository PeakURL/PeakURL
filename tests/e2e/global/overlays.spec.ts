import { test, expect, type Page } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const capabilities = { manage_users: true, view_links: true };

test.beforeEach(async ({ page }) => {
	await mockDashboardSession(page, { capabilities });
	await page.route("**/api/v1/users", (route) =>
		route.fulfill({ json: { data: [] } })
	);
	await page.route("**/api/v1/roles", (route) =>
		route.fulfill({ json: { data: [] } })
	);
});

/** Sample actual transition phases before Headless UI removes their attributes. */
async function drawerMotion(
	page: Page,
	phase: "enter" | "leave",
	trigger: string
) {
	await expect(page.locator(trigger)).toBeVisible();
	return page.evaluate(
		({ phase, trigger }) =>
			new Promise<string>((resolve, reject) => {
				const button =
					document.querySelector<HTMLButtonElement>(trigger);
				if (!button)
					return reject(new Error("Drawer control is missing"));
				const startedAt = performance.now();
				button.click();
				const sample = () => {
					const panel = document.querySelector(
						`.users-roles-drawer-panel[data-${phase}]`
					);
					if (panel)
						return resolve(
							getComputedStyle(panel).transitionTimingFunction
						);
					if (performance.now() - startedAt > 2000)
						return reject(
							new Error(`Drawer never entered its ${phase} phase`)
						);
					requestAnimationFrame(sample);
				};
				requestAnimationFrame(sample);
			}),
		{ phase, trigger }
	);
}

test("search, user dialogs, and role drawers share a sharp dim overlay", async ({
	page,
}) => {
	await page.goto("/dashboard/users");
	await page
		.getByRole("button", { name: "Search the dashboard", exact: true })
		.click();
	const backdrop = page.locator(".dialog-backdrop");
	await expect(backdrop).toHaveCSS("backdrop-filter", "none");
	await expect(backdrop).toHaveCSS("background-color", "rgba(0, 0, 0, 0.3)");
	await page.keyboard.press("Escape");
	await expect(backdrop).toHaveCount(0);
	await page.getByRole("button", { name: "Add User", exact: true }).click();
	await expect(
		page.getByRole("heading", { name: "Add User", exact: true })
	).toBeVisible();
	await expect(backdrop).toHaveCSS("backdrop-filter", "none");
	await expect(backdrop).toHaveCSS("background-color", "rgba(0, 0, 0, 0.3)");
	await page
		.getByRole("button", { name: "Close dialog", exact: true })
		.click();
	await page
		.getByRole("button", {
			name: "Manage Roles & Capabilities",
			exact: true,
		})
		.click();
	await expect(page.locator(".users-roles-drawer-panel")).toBeVisible();
	await expect(backdrop).toHaveCSS("backdrop-filter", "none");
	await expect(backdrop).toHaveCSS("background-color", "rgba(0, 0, 0, 0.3)");
});

for (const direction of ["ltr", "rtl"] as const) {
	test(`drawers ease in, ease out, and honor reduced motion in ${direction}`, async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities, direction });
		await page.goto("/dashboard/users");
		expect(
			await drawerMotion(
				page,
				"enter",
				".users-page-hero-actions button:first-child"
			)
		).toBe("cubic-bezier(0.4, 0, 1, 1)");
		await expect(
			page.locator(".users-roles-drawer-panel")
		).not.toHaveAttribute("data-enter");
		expect(
			await drawerMotion(page, "leave", ".users-roles-drawer-close")
		).toBe("cubic-bezier(0, 0, 0.2, 1)");
		await expect(page.locator(".users-roles-drawer-panel")).toHaveCount(0);
		await page.emulateMedia({ reducedMotion: "reduce" });
		await page
			.getByRole("button", {
				name: "Manage Roles & Capabilities",
				exact: true,
			})
			.click();
		await expect(page.locator(".users-roles-drawer-panel")).toHaveCSS(
			"transition-property",
			"none"
		);
	});
}

test("the mobile sidebar uses the same overlay and easing directions", async ({
	page,
}) => {
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto("/dashboard/about");
	await page.getByRole("button", { name: "Open menu", exact: true }).click();
	await expect(page.locator(".dashboard-sidebar")).toHaveCSS(
		"transition-timing-function",
		"cubic-bezier(0.4, 0, 1, 1)"
	);
	await expect(page.locator(".dashboard-sidebar-overlay")).toHaveCSS(
		"backdrop-filter",
		"none"
	);
	await expect(page.locator(".dashboard-sidebar-overlay")).toHaveCSS(
		"background-color",
		"rgba(0, 0, 0, 0.3)"
	);
	await page.locator(".dashboard-sidebar-close").click();
	await expect(page.locator(".dashboard-sidebar")).toHaveCSS(
		"transition-timing-function",
		"cubic-bezier(0, 0, 0.2, 1)"
	);
});
