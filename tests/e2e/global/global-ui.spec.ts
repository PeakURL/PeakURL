import { test, expect } from "../fixtures/auth.fixture";

test.describe("Global Layout, Responsive & Theme Journeys", () => {
	test("theme toggle switches between light and dark mode seamlessly", async ({
		authenticatedPage: page,
	}) => {
		const themeToggle = page.locator("button[aria-label='Toggle theme']");
		await expect(themeToggle).toBeVisible();

		// Initially light or system mode; toggle to dark
		await themeToggle.click();
		await expect(page.locator("html")).toHaveClass(/dark/);
		await expect(
			page.getByRole("heading", { name: /^dashboard$/i })
		).toBeVisible();

		// Toggle back to light
		await themeToggle.click();
		await expect(page.locator("html")).not.toHaveClass(/dark/);
		await expect(
			page.getByRole("heading", { name: /^dashboard$/i })
		).toBeVisible();
	});

	test("responsive mobile viewport: menu drawer opens and navigates without layout overflow", async ({
		authenticatedPage: page,
	}) => {
		// Set mobile viewport
		await page.setViewportSize({ width: 375, height: 667 });

		// Mobile hamburger menu button should be visible
		const mobileMenuBtn = page.locator("button.dashboard-header-menu-button, button[aria-label='Open menu']");
		await expect(mobileMenuBtn).toBeVisible();

		// Open mobile sidebar
		await mobileMenuBtn.click();
		const sidebar = page.locator(".dashboard-sidebar");
		await expect(sidebar).toHaveClass(/dashboard-sidebar-open/);

		// Navigation links inside mobile drawer are visible
		await expect(page.locator("a[href='/dashboard/links']").first()).toBeVisible();

		// Close mobile sidebar
		const closeSidebarBtn = page.locator("button.dashboard-sidebar-close");
		await closeSidebarBtn.click();
		await expect(sidebar).not.toHaveClass(/dashboard-sidebar-open/);

		// Verify no horizontal overflow in mobile viewport
		const hasOverflow = await page.evaluate(() => {
			return document.documentElement.scrollWidth > document.documentElement.clientWidth + 5;
		});
		expect(hasOverflow).toBe(false);
	});

	test("global search input accepts query and dismisses on escape", async ({
		authenticatedPage: page,
	}) => {
		const searchInput = page.locator(".dashboard-search-input, input[placeholder*='Search' i]");
		await expect(searchInput).toBeVisible();

		// Focus and type search query
		await searchInput.click();
		await searchInput.fill("links");

		// Search results popup opens
		const resultsPopup = page.locator(".dashboard-search-panel");
		await expect(resultsPopup).toBeVisible({ timeout: 10000 });

		// Press Escape to dismiss
		await page.keyboard.press("Escape");
		await expect(resultsPopup).not.toBeVisible();
	});
});
