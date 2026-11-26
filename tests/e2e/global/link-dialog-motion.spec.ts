import { expect, test } from "@playwright/test";

import { mockDashboardSession } from "../fixtures/dashboard.fixture";

for (const reducedMotion of ["no-preference", "reduce"] as const) {
	test(`link prompts use dashboard dialog motion with ${reducedMotion}`, async ({
		page,
	}) => {
		await page.setViewportSize({
			width: reducedMotion === "reduce" ? 320 : 1440,
			height: 800,
		});
		await page.emulateMedia({ reducedMotion });
		await mockDashboardSession(page, {
			capabilities: {
				view_links: true,
				trash_links: true,
				delete_links: true,
			},
		});
		await page.route("**/api/v1/urls?*", (route) =>
			route.fulfill({
				json: {
					data: {
						items: ["Reference", "Second Link"].map(
							(title, index) => ({
								id: `preview-${index}`,
								userId: "dashboard-preview",
								status: "active",
								title,
								destinationUrl: `https://example.com/articles/${index}`,
								shortUrl: `https://example.com/ref${index}`,
							})
						),
						meta: { totalItems: 2, totalPages: 1 },
					},
				},
			})
		);
		await page.addInitScript(() => {
			document.addEventListener("transitionend", (event) => {
				if (
					!(event.target instanceof HTMLElement) ||
					event.propertyName !== "opacity"
				)
					return;
				if (!event.target.matches(".links-modal-panel")) return;
				const count = Number(
					document.documentElement.dataset.linkDialogTransitions ??
						"0"
				);
				document.documentElement.dataset.linkDialogTransitions = String(
					count + 1
				);
			});
		});
		let mutations = 0;
		page.on("request", (request) => {
			if (
				request.url().includes("/api/v1/urls") &&
				request.method() === "DELETE"
			)
				mutations++;
		});
		const panel = page.locator(".links-modal-panel");
		const transitions = page.locator("html");
		async function expectOpening(count: number) {
			await expect(panel).toBeVisible();
			await expect(panel).toHaveCSS(
				"transition-property",
				reducedMotion === "reduce" ? "none" : "opacity, scale"
			);
			if (reducedMotion === "no-preference") {
				await expect(transitions).toHaveAttribute(
					"data-link-dialog-transitions",
					String(count)
				);
			}
		}
		await page.goto("/dashboard/links");
		const row = page.getByRole("row", { name: /Reference/ });
		const qrButton = row.getByRole("button", {
			name: "QR Code",
			exact: true,
		});
		await qrButton.click();
		await expectOpening(1);
		const qr = page
			.getByRole("dialog", { name: "QR Code" })
			.getByRole("img", { name: "QR Code" });
		await expect(qr).toBeVisible();
		const firstCode = await qr.getAttribute("src");
		const qrBounds = await qr.boundingBox();
		expect(qrBounds?.width).toBeCloseTo(qrBounds?.height ?? 0, 0);
		const qrActionBounds = await panel.evaluate((element) => {
			const bounds = element.getBoundingClientRect();
			const actions = [
				...element.querySelectorAll(".links-qr-modal-actions button"),
			];
			return actions.map((action) => {
				const button = action.getBoundingClientRect();
				return (
					button.width > 0 &&
					button.left >= bounds.left &&
					button.right <= bounds.right
				);
			});
		});
		expect(qrActionBounds).toEqual([true, true]);
		await page.keyboard.press("Escape");
		await expect(panel).toHaveCount(0);
		await expect(qrButton).toBeFocused();
		const secondQrButton = page
			.getByRole("row", { name: /Second Link/ })
			.getByRole("button", { name: "QR Code", exact: true });
		await secondQrButton.click();
		await expectOpening(3);
		await expect(qr).toHaveAttribute("src", /^data:image\/png/);
		expect(await qr.getAttribute("src")).not.toBe(firstCode);
		await page.getByRole("button", { name: "Close", exact: true }).click();
		await expect(panel).toHaveCount(0);

		const deleteButton = row.getByRole("button", {
			name: "Delete",
			exact: true,
		});
		await deleteButton.click();
		await expectOpening(5);
		const deleteDialog = page.getByRole("dialog", {
			name: "Move to Trash",
		});
		await expect(
			deleteDialog.getByText("https://example.com/ref0", { exact: true })
		).toBeVisible();
		await deleteDialog
			.getByRole("button", { name: "Cancel", exact: true })
			.click();
		await expect(panel).toHaveCount(0);
		await expect(deleteButton).toBeFocused();

		await row.getByRole("checkbox").check();
		await page
			.getByRole("button", { name: "Delete selected", exact: true })
			.click();
		await expectOpening(7);
		const bulk = page.getByRole("dialog", { name: "Move Links to Trash" });
		await bulk.getByRole("button", { name: "Cancel", exact: true }).click();
		await expect(panel).toHaveCount(0);
		await expect(row.getByRole("checkbox")).toBeChecked();
		expect(mutations).toBe(0);
		if (reducedMotion === "reduce") {
			await expect(transitions).not.toHaveAttribute(
				"data-link-dialog-transitions"
			);
		}
	});
}
