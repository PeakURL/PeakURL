import { expect, test } from "@playwright/test";

import { mockDashboardSession } from "../fixtures/dashboard.fixture";

for (const layout of [
	{ width: 1440, direction: "ltr", theme: "light", motion: "no-preference" },
	{ width: 320, direction: "rtl", theme: "dark", motion: "reduce" },
] as const) {
	test(`branded notifications fit and dismiss at ${layout.width}px in ${layout.direction.toUpperCase()}`, async ({
		page,
	}) => {
		await page.setViewportSize({ width: layout.width, height: 820 });
		await page.emulateMedia({ reducedMotion: layout.motion });
		await page.addInitScript(
			(theme) => localStorage.setItem("theme", theme),
			layout.theme
		);
		await mockDashboardSession(page, {
			direction: layout.direction,
			capabilities: { view_links: true, manage_mail_delivery: true },
		});
		await page.route("**/api/v1/system/mail", (route) =>
			route.fulfill({
				json: {
					data: {
						configured: true,
						canManageFromDashboard: true,
						canSendTestEmail: true,
						driver: "smtp",
						smtpHost: "smtp.example.com",
						smtpPort: 587,
						smtpEncryption: "tls",
					},
				},
			})
		);
		const errorMessage = `Could not send test mail to ${"long-recipient".repeat(12)}@example.com`;
		let sends = 0;
		await page.route("**/api/v1/system/mail/test", (route) =>
			++sends === 1
				? route.fulfill({
						status: 502,
						json: { message: errorMessage },
					})
				: route.fulfill({
						json: { data: { recipient: "preview@example.com" } },
					})
		);
		await page.goto("/dashboard/settings/email");
		const send = page.getByRole("button", { name: "Send Test Email" });
		await expect(send).toBeEnabled();
		await page.evaluate(() => {
			const animatedOpacity = new MutationObserver((records) => {
				for (const { target, oldValue } of records) {
					if (
						!(target instanceof HTMLElement) ||
						!target.matches(".notification-card")
					)
						continue;
					if (oldValue?.match(/opacity: 0/)) {
						document.documentElement.dataset.notificationMotion =
							"played";
					}
				}
			});
			animatedOpacity.observe(document.body, {
				subtree: true,
				attributeFilter: ["style"],
				attributeOldValue: true,
			});
		});
		await page.clock.install();
		await send.click();
		await page.mouse.move(1, 800);
		const card = page.locator(".notification-card");
		const error = card.filter({ hasText: "Test email failed" });
		await expect(error.locator(".notification-message")).toHaveText(
			errorMessage
		);
		await expect(error).toHaveCSS(
			"background-color",
			layout.theme === "dark" ? "rgb(28, 38, 36)" : "rgb(255, 255, 255)"
		);
		await expect(error.locator(".notification-icon")).toHaveCSS(
			"color",
			layout.theme === "dark" ? "rgb(252, 165, 165)" : "rgb(185, 28, 28)"
		);
		await expect(
			page.locator('.notification-announcement[aria-live="assertive"]')
		).toContainText(errorMessage);
		const layoutBounds = await error.evaluate((element) => {
			const box = element.getBoundingClientRect();
			const body = element.querySelector(".notification-body")!;
			return {
				fits:
					box.left > 0 &&
					box.right < innerWidth &&
					body.scrollWidth <= body.clientWidth,
				trailingGap:
					getComputedStyle(element).direction === "rtl"
						? box.left
						: innerWidth - box.right,
			};
		});
		expect(layoutBounds.fits).toBe(true);
		expect(layoutBounds.trailingGap).toBeLessThanOrEqual(20);
		if (layout.motion === "reduce") {
			await expect(error.locator(".notification-progress")).toBeHidden();
			await expect(page.locator("html")).not.toHaveAttribute(
				"data-notification-motion"
			);
		} else {
			await expect(page.locator("html")).toHaveAttribute(
				"data-notification-motion",
				"played"
			);
			await expect(error).toHaveCSS("opacity", "1");
		}

		const dismiss = error.getByRole("button", {
			name: "Dismiss notification",
		});
		await dismiss.focus();
		await page.clock.runFor(5000);
		await expect(dismiss).toBeFocused();
		await dismiss.press("Enter");
		await page.clock.runFor(350);
		await expect(card).toHaveCount(0);

		await send.click();
		await page.mouse.move(1, 800);
		const success = card.filter({ hasText: "Test email sent" });
		await expect(success.locator(".notification-icon")).toHaveCSS(
			"color",
			layout.theme === "dark" ? "rgb(131, 220, 204)" : "rgb(18, 110, 104)"
		);
		await expect(
			page.locator('.notification-announcement[aria-live="polite"]')
		).toContainText("preview@example.com");
		await page.clock.runFor(5000);
		await expect(card).toHaveCount(0);
		expect(sends).toBe(2);
	});
}
