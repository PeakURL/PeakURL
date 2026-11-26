import { expect, test } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const capabilities = {
	view_links: true,
	manage_users: true,
	manage_site_settings: true,
};

test("wide General settings keep a 60:40 content area and remain contained on phones", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	await page.route("**/api/v1/system/general", (route) =>
		route.fulfill({
			json: {
				data: {
					canManageSiteSettings: true,
					siteName: "Documentation workspace",
				},
			},
		})
	);
	await page.setViewportSize({ width: 2560, height: 1000 });
	await page.goto("/dashboard/settings/general");
	const form = page.locator(".settings-general-form");
	await expect(form).toHaveAttribute("aria-busy", "false");
	await expect(form.getByRole("heading", { name: "Branding" })).toBeVisible();
	const sizes = await form.evaluate((element) => {
		const profile = element
			.querySelector(".settings-general-profile")!
			.getBoundingClientRect();
		const branding = element
			.querySelector(".settings-general-branding")!
			.getBoundingClientRect();
		const sidebar = element
			.querySelector(".settings-general-column")!
			.getBoundingClientRect();
		const first = element
			.querySelector<HTMLInputElement>('[name="firstName"]')!
			.getBoundingClientRect();
		const last = element
			.querySelector<HTMLInputElement>('[name="lastName"]')!
			.getBoundingClientRect();
		return {
			form: element.getBoundingClientRect().width,
			profile: profile.width,
			sidebar: sidebar.width,
			gap: sidebar.x - profile.right,
			verticalGap: branding.top - profile.bottom,
			firstTop: first.top,
			lastTop: last.top,
		};
	});
	expect(sizes.form).toBeLessThanOrEqual(1120);
	expect(sizes.profile / sizes.sidebar).toBeCloseTo(1.5, 1);
	expect(sizes.gap).toBeGreaterThanOrEqual(16);
	expect(sizes.gap).toBeLessThanOrEqual(24);
	expect(sizes.verticalGap).toBeGreaterThanOrEqual(16);
	expect(sizes.verticalGap).toBeLessThanOrEqual(24);
	expect(sizes.firstTop).toBe(sizes.lastTop);

	await page.getByRole("button", { name: "Site timezone" }).click();
	const listbox = page.locator(".select-options");
	await expect(listbox).toBeVisible();
	await expect(listbox).toHaveCSS("scrollbar-width", "thin");
	await expect(listbox).toHaveCSS(
		"scrollbar-color",
		"rgb(18, 110, 104) rgb(230, 239, 237)"
	);
	await page.keyboard.press("Escape");

	await page.setViewportSize({ width: 320, height: 850 });
	await expect
		.poll(() => page.evaluate(() => document.documentElement.scrollWidth))
		.toBe(320);
	const settingsNav = page.locator(".settings-navigation");
	await expect(settingsNav).toHaveCSS("scrollbar-width", "thin");
	await expect(settingsNav).toHaveCSS(
		"scrollbar-color",
		"rgb(18, 110, 104) rgb(230, 239, 237)"
	);
	await expect(form.getByLabel("First Name")).toBeVisible();
	await expect(form.getByLabel("Username")).toBeDisabled();
});

test("System Status keeps its header, system checks and Info navigation while diagnostics load", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	let release = () => {};
	const ready = new Promise<void>((resolve) => {
		release = resolve;
	});
	await page.route("**/api/v1/system/status", async (route) => {
		await ready;
		await route.fulfill({
			json: {
				data: {
					summary: { overall: "ok" },
					checks: [],
					database: { serverType: "MySQL", version: "8.4" },
				},
			},
		});
	});
	await page.goto("/dashboard/tools/system-status");
	const title = page.getByRole("heading", {
		name: "System Status",
		level: 1,
	});
	try {
		await expect(title).toBeVisible();
		await expect(
			page.getByText("Database Engine", { exact: true })
		).toBeVisible();
		const banner = page.locator(".system-status-page-banner");
		const checkCard = page
			.locator(".system-status-page-subsystem-card")
			.first();
		const surface = (locator: typeof banner) =>
			locator.evaluate((element) => {
				const style = getComputedStyle(element);
				return [style.borderTopColor, style.backgroundColor];
			});
		await expect(
			banner.getByRole("heading", { name: "Site Health", exact: true })
		).toBeVisible();
		const matchesCard = async () => {
			const [bannerPalette, cardPalette] = await Promise.all([
				surface(banner),
				surface(checkCard),
			]);
			return bannerPalette.every(
				(colour, index) => colour === cardPalette[index]
			);
		};
		await expect.poll(matchesCard).toBe(true);
		const initial = await surface(banner);
		await page.getByRole("button", { name: "Toggle theme" }).click();
		await expect.poll(() => surface(banner)).not.toEqual(initial);
		await expect.poll(matchesCard).toBe(true);
		await expect(
			page.getByRole("heading", { name: "System Checks" })
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: "Site Health: Good" })
		).toHaveCount(0);
		await page.getByRole("button", { name: "Info", exact: true }).click();
		await expect(
			page.getByRole("heading", { name: "System Information" })
		).toBeVisible();
		await expect(
			page.getByRole("button", { name: "Copy site info to clipboard" })
		).toBeDisabled();
	} finally {
		release();
	}
	await expect(title).toBeVisible();
	await expect(
		page.getByRole("button", { name: "Copy site info to clipboard" })
	).toBeEnabled();
});

test("search can reopen during its exit without losing focus or leaving transitions behind", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	await page.goto("/dashboard/about");
	const trigger = page.getByRole("button", {
		name: "Search the dashboard",
		exact: true,
	});
	await trigger.click();
	const panel = page.locator(".dashboard-search-panel");
	const input = page.getByRole("combobox", {
		name: "Search links, people, and pages",
	});
	await expect(input).toBeFocused();
	const reopenedDuringExit = await panel.evaluate(async (element) => {
		await new Promise<void>((resolve) =>
			requestAnimationFrame(() => requestAnimationFrame(() => resolve()))
		);
		await Promise.allSettled(
			element.getAnimations().map((animation) => animation.finished)
		);
		return new Promise<boolean>((resolve) => {
			const controller = new AbortController();
			const timeout = window.setTimeout(() => {
				controller.abort();
				resolve(false);
			}, 2000);
			element.addEventListener(
				"transitionrun",
				(event) => {
					if (
						!(event instanceof TransitionEvent) ||
						event.target !== element ||
						event.propertyName !== "opacity"
					)
						return;
					controller.abort();
					window.clearTimeout(timeout);
					window.dispatchEvent(
						new KeyboardEvent("keydown", {
							key: "k",
							ctrlKey: true,
						})
					);
					resolve(true);
				},
				{ signal: controller.signal }
			);
			element
				.querySelector<HTMLButtonElement>(".dashboard-search-close")
				?.click();
		});
	});
	expect(reopenedDuringExit).toBe(true);
	await expect(input).toBeFocused();
	await input.fill("Overview");
	await expect(
		page.getByRole("option", { name: "Overview Dashboard", exact: true })
	).toBeVisible();
	await expect
		.poll(() => panel.evaluate((element) => element.getAnimations().length))
		.toBe(0);
	await expect(panel).toHaveCSS("opacity", "1");
	await page.keyboard.press("Escape");
	await expect(panel).toHaveCount(0);
	await expect(trigger).toBeFocused();

	await page.emulateMedia({ reducedMotion: "reduce" });
	await trigger.click();
	await expect(input).toBeFocused();
	await expect(panel).toHaveCSS("transition-property", "none");
	await page.keyboard.press("Escape");
	await expect(panel).toHaveCount(0);
	await expect(trigger).toBeFocused();
});

test("login artwork follows the application theme with an opposite OS preference", async ({
	page,
}) => {
	await page.emulateMedia({ colorScheme: "dark" });
	await page.addInitScript(() => {
		if (!localStorage.getItem("theme")) {
			localStorage.setItem("theme", "light");
		}
	});
	await page.route("**/api/v1/users/me", (route) =>
		route.fulfill({ status: 401, json: { message: "Unauthenticated" } })
	);
	await page.goto("/login");
	const artwork = page.locator('img[data-page-illustration="sign-in"]');
	const readColours = () =>
		artwork.evaluate(async (node) => {
			const image = node as HTMLImageElement;
			await image.decode();
			const canvas = document.createElement("canvas");
			canvas.width = image.naturalWidth;
			canvas.height = image.naturalHeight;
			const context = canvas.getContext("2d")!;
			context.drawImage(image, 0, 0);
			const pixels = context.getImageData(
				0,
				0,
				canvas.width,
				canvas.height
			).data;
			const result = new Set<string>();
			for (let i = 0; i < pixels.length; i += 4) {
				if (pixels[i + 3] === 255)
					result.add(
						[pixels[i], pixels[i + 1], pixels[i + 2]].join(",")
					);
			}
			return [...result];
		});
	await expect.poll(readColours).toContain("18,110,104");
	await page.emulateMedia({ colorScheme: "light" });
	await page.evaluate(() => localStorage.setItem("theme", "dark"));
	await page.reload();
	await expect.poll(readColours).toContain("131,220,204");
});

for (const direction of ["ltr", "rtl"] as const) {
	test(`submenu selection places the Jade accent on its guide in ${direction}`, async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities, direction });
		await page.setViewportSize({ width: 1440, height: 900 });
		await page.goto("/dashboard/tools/import/file");
		const selected = page.locator(
			".dashboard-sidebar-submenu-link[aria-current=page]"
		);
		await expect(selected).toHaveText("Import");
		const accent = () =>
			selected.evaluate((link) => {
				const indicator = document.querySelector(
					".dashboard-sidebar-indicator"
				)!;
				const guide = link.closest(".dashboard-sidebar-submenu-list")!;
				const direction = getComputedStyle(indicator).direction;
				const marker = indicator.getBoundingClientRect();
				const list = guide.getBoundingClientRect();
				const style = getComputedStyle(indicator, "::before");
				const width = parseFloat(style.width);
				const inset = parseFloat(style.insetInlineStart);
				const center =
					direction === "rtl"
						? marker.right - inset - width / 2
						: marker.left + inset + width / 2;
				const lineCenter =
					direction === "rtl" ? list.right - 0.5 : list.left + 0.5;
				return Math.max(
					Math.abs(center - lineCenter),
					Math.abs(marker.top - link.getBoundingClientRect().top)
				);
			});
		await expect.poll(accent).toBeLessThan(1);
		await page
			.getByRole("link", { name: "System Status", exact: true })
			.click();
		await expect(selected).toHaveText("System Status");
		await expect.poll(accent).toBeLessThan(1);
	});
}
