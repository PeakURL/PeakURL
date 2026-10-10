import { test, expect } from "../fixtures/auth.fixture";

test.describe("Webhooks Settings and Management Workflows", () => {
	test("full webhooks lifecycle: create, secret rotation, delivery drawer, edit, and delete", async ({
		authenticatedPage: page,
	}) => {
		// 1. Navigate to Settings -> Integrations tab
		await page.goto("/dashboard/settings/integrations", {
			waitUntil: "commit",
		});

		await expect(
			page.getByRole("heading", { name: /webhooks/i })
		).toBeVisible({ timeout: 25000 });

		// Wait for webhooks query to finish loading
		await expect(
			page.getByText(/loading webhook configuration/i)
		).toHaveCount(0, { timeout: 15000 });

		// 2. Record initial switch state and ensure webhooks switch is enabled
		const toggleSwitch = page.locator(
			"button[role='switch'][aria-labelledby='webhooks-toggle-label']"
		);
		await expect(toggleSwitch).toBeVisible();
		const wasInitiallyChecked =
			(await toggleSwitch.getAttribute("aria-checked")) === "true";

		const testLabel = `E2E Hook ${Date.now().toString(36).slice(-4)}`;
		const updatedLabel = `${testLabel} Updated`;
		let webhookCreated = false;
		let createdWebhookId: string | null = null;

		try {
			// 2. Ensure webhooks switch is enabled and wait for settled attribute
			if (!wasInitiallyChecked) {
				await toggleSwitch.click();
				await expect(toggleSwitch).toHaveAttribute(
					"aria-checked",
					"true",
					{
						timeout: 10000,
					}
				);
			}

			// 3. Open "Add Webhook" drawer
			const addBtn = page
				.getByRole("button", { name: /add webhook/i })
				.first();
			await expect(addBtn).toBeVisible();
			await addBtn.click();

			// Drawer should open
			await expect(
				page.getByRole("heading", { name: /new webhook|add webhook/i })
			).toBeVisible({ timeout: 10000 });

			// 4. Fill form
			const testUrl = "https://93.184.215.14/e2e-webhook";

			const labelInput = page.locator(
				"input[placeholder*='Slack notifications']"
			);
			await labelInput.fill(testLabel);

			const urlInput = page.locator(
				"input[placeholder='https://api.yourdomain.com/webhooks/peakurl']"
			);
			await urlInput.fill(testUrl);

			// Select all events
			const allBtn = page.getByRole("button", { name: /^all$/i });
			if (await allBtn.isVisible()) {
				await allBtn.click();
			}

			// Submit creation
			const createPromise = page.waitForResponse(
				(res) =>
					res.url().includes("/api/v1/webhooks") &&
					res.request().method() === "POST"
			);

			await page.getByRole("button", { name: /create webhook/i }).click();
			const createRes = await createPromise;
			if (createRes.status() === 201) {
				webhookCreated = true;
				try {
					const createData = await createRes.json();
					createdWebhookId =
						createData.id ?? createData.data?.id ?? null;
				} catch {
					// Fallback if parsing response body has an issue
				}
			}
			expect(createRes.status()).toBe(201);

			// Modal showing one-time secret should appear with "I've Stored It" button
			await expect(
				page.getByRole("heading", {
					name: /your webhook signing secret/i,
				})
			).toBeVisible({ timeout: 10000 });
			await expect(
				page.getByText(/this signing secret will not be shown again/i)
			).toBeVisible();

			const storedBtn = page.getByRole("button", {
				name: /i've stored it/i,
			});
			await expect(storedBtn).toBeVisible({ timeout: 10000 });
			await storedBtn.click();
			await expect(
				page.getByRole("heading", {
					name: /your webhook signing secret/i,
				})
			).toBeHidden({ timeout: 5000 });

			// 5. Verify created webhook is in the list
			await expect(page.getByText(testLabel).first()).toBeVisible({
				timeout: 15000,
			});

			let webhookCard = page.locator(".integrations-tab-webhook-card", {
				hasText: testLabel,
			});
			await expect(webhookCard).toBeVisible();

			// 6. Test Secret Rotation (assertion-backed)
			const rotateBtn = webhookCard.getByRole("button", {
				name: /rotate/i,
			});
			await expect(rotateBtn).toBeVisible({ timeout: 5000 });
			await rotateBtn.click();

			// Confirm dialog
			await expect(
				page.getByRole("heading", {
					name: /rotate webhook signing secret/i,
				})
			).toBeVisible({ timeout: 5000 });

			const confirmRotateBtn = page
				.getByRole("button", { name: /rotate secret/i })
				.last();
			await expect(confirmRotateBtn).toBeVisible({ timeout: 5000 });

			const rotatePromise = page.waitForResponse(
				(res) =>
					res.url().includes("/rotate-secret") &&
					res.request().method() === "POST"
			);
			await confirmRotateBtn.click();
			const rotateRes = await rotatePromise;
			expect(rotateRes.status()).toBe(200);
			const rotateData = await rotateRes.json();
			const rotateSecret = rotateData.secret ?? rotateData.data?.secret;
			expect(rotateSecret).toBeTruthy();

			// Verify UI state: modal showing new one-time secret is available
			await expect(
				page.getByRole("heading", {
					name: /your webhook signing secret/i,
				})
			).toBeVisible({ timeout: 10000 });
			await expect(
				page.getByText(/this signing secret will not be shown again/i)
			).toBeVisible();

			// Dismiss rotated secret modal
			const storedAfterRotate = page.getByRole("button", {
				name: /i've stored it/i,
			});
			await expect(storedAfterRotate).toBeVisible({ timeout: 10000 });
			await storedAfterRotate.click();
			await expect(
				page.getByRole("heading", {
					name: /your webhook signing secret/i,
				})
			).toBeHidden({ timeout: 5000 });

			// 7. Test Webhook Edit / Update
			const editBtn = webhookCard.getByRole("button", { name: /edit/i });
			await expect(editBtn).toBeVisible({ timeout: 5000 });
			await editBtn.click();

			await expect(
				page.getByRole("heading", { name: /edit webhook/i })
			).toBeVisible({ timeout: 10000 });

			const editLabelInput = page.locator(
				"input[placeholder*='Slack notifications']"
			);
			await editLabelInput.fill(updatedLabel);

			const updatePromise = page.waitForResponse(
				(res) =>
					res.url().includes("/api/v1/webhooks/") &&
					res.request().method() === "PUT"
			);
			await page.getByRole("button", { name: /save changes/i }).click();
			const updateRes = await updatePromise;
			expect(updateRes.status()).toBe(200);
			const updateData = await updateRes.json();
			const updateLabel = updateData.label ?? updateData.data?.label;
			expect(updateLabel).toBe(updatedLabel);

			await expect(
				page.getByRole("heading", { name: /edit webhook/i })
			).toBeHidden({ timeout: 5000 });

			// Verify updated card in list
			await expect(page.getByText(updatedLabel).first()).toBeVisible({
				timeout: 10000,
			});

			webhookCard = page.locator(".integrations-tab-webhook-card", {
				hasText: updatedLabel,
			});
			await expect(webhookCard).toBeVisible();

			// 8. Open Delivery History Drawer
			const deliveriesBtn = webhookCard.getByRole("button", {
				name: /deliveries/i,
			});
			await expect(deliveriesBtn).toBeVisible({ timeout: 5000 });
			await deliveriesBtn.click();

			// Drawer panel should open with active webhook title
			await expect(
				page.locator(".webhook-drawer-panel").first()
			).toBeVisible({ timeout: 10000 });
			await expect(
				page.locator(".webhook-drawer-title", { hasText: updatedLabel })
			).toBeVisible({ timeout: 10000 });

			// Wait for loading to finish, then assert meaningful content:
			// Either rendered deliveries table or the empty state heading
			await expect(
				page.getByText(/loading delivery history/i)
			).toHaveCount(0, { timeout: 10000 });
			const emptyState = page.getByRole("heading", {
				name: /no delivery attempts yet/i,
			});
			const deliveriesTable = page.locator(".webhook-drawer-table");
			await expect(emptyState.or(deliveriesTable)).toBeVisible({
				timeout: 5000,
			});

			// Close delivery drawer
			const closeDrawerBtn = page
				.locator("button[aria-label='Close drawer']")
				.first();
			await expect(closeDrawerBtn).toBeVisible();
			await closeDrawerBtn.click();
			await expect(page.locator(".webhook-drawer-panel")).toBeHidden({
				timeout: 5000,
			});

			// 9. Delete Webhook (Lifecycle completion)
			const deleteBtn = webhookCard.locator(
				"button[aria-label='Delete this webhook']"
			);
			await expect(deleteBtn).toBeVisible({ timeout: 5000 });
			await deleteBtn.click();

			const confirmDeleteBtn = page
				.getByRole("button", { name: /delete webhook/i })
				.last();
			await expect(confirmDeleteBtn).toBeVisible({ timeout: 5000 });

			const deletePromise = page.waitForResponse(
				(res) =>
					res.url().includes("/api/v1/webhooks/") &&
					res.request().method() === "DELETE"
			);

			await confirmDeleteBtn.click();
			const deleteRes = await deletePromise;
			expect(deleteRes.status()).toBe(200);
			webhookCreated = false;
			createdWebhookId = null;

			// Verify removed from list
			await expect(page.getByText(updatedLabel)).toHaveCount(0);
		} finally {
			// Bounded overlay dismissal via specific controls (avoid broad multi-match loops)
			try {
				// 1. Close drawer if open (delivery or edit/add drawer)
				const closeDrawerBtn = page
					.getByRole("button", { name: /close drawer/i })
					.first();
				if (
					await closeDrawerBtn
						.isVisible({ timeout: 1000 })
						.catch(() => false)
				) {
					await closeDrawerBtn.click().catch(() => {});
				}

				// 2. Dismiss secret modal if open
				const storedBtn = page
					.getByRole("button", { name: /i've stored it/i })
					.first();
				if (
					await storedBtn
						.isVisible({ timeout: 1000 })
						.catch(() => false)
				) {
					await storedBtn.click().catch(() => {});
				}

				// 3. Dismiss any confirm/delete/cancel dialog if open
				const cancelDialogBtn = page
					.getByRole("button", { name: /^cancel$/i })
					.first();
				if (
					await cancelDialogBtn
						.isVisible({ timeout: 1000 })
						.catch(() => false)
				) {
					await cancelDialogBtn.click().catch(() => {});
				}
			} catch (dismissErr) {
				console.warn(
					"Overlay dismissal error during cleanup:",
					dismissErr
				);
			}

			// Clean up created webhook resource
			let cleanupSucceeded = false;
			if (createdWebhookId) {
				try {
					const deleteRes = await page.request.delete(
						`/api/v1/webhooks/${createdWebhookId}`
					);
					if (
						deleteRes.status() === 200 ||
						deleteRes.status() === 404
					) {
						cleanupSucceeded = true;
					} else {
						console.warn(
							`API deletion of webhook ${createdWebhookId} returned HTTP ${deleteRes.status()}`
						);
					}
				} catch (apiErr) {
					console.warn(
						`API deletion failed for webhook ${createdWebhookId}:`,
						apiErr
					);
				}
			}

			// If ID was not captured or deletion didn't succeed, recover ID via API query
			if (!cleanupSucceeded && webhookCreated) {
				try {
					const listRes = await page.request.get("/api/v1/webhooks");
					if (listRes.ok()) {
						const listData = await listRes.json();
						const items = listData.data ?? listData;
						if (Array.isArray(items)) {
							const matched = items.find(
								(item: { id?: string; label?: string }) =>
									item.label === testLabel ||
									item.label === updatedLabel
							);
							if (matched?.id) {
								const delRes = await page.request.delete(
									`/api/v1/webhooks/${matched.id}`
								);
								if (
									delRes.status() === 200 ||
									delRes.status() === 404
								) {
									cleanupSucceeded = true;
								}
							}
						}
					}
				} catch (recoverErr) {
					console.warn(
						"Failed to recover and delete created webhook by label:",
						recoverErr
					);
				}
			}

			// UI fallback if API cleanup didn't succeed and card is visible
			if (!cleanupSucceeded && webhookCreated) {
				try {
					const card = page
						.locator(".integrations-tab-webhook-card", {
							hasText: updatedLabel,
						})
						.or(
							page.locator(".integrations-tab-webhook-card", {
								hasText: testLabel,
							})
						);
					if (
						await card
							.isVisible({ timeout: 1000 })
							.catch(() => false)
					) {
						const delBtn = card
							.locator("button[aria-label='Delete this webhook']")
							.first();
						if (
							await delBtn
								.isVisible({ timeout: 1000 })
								.catch(() => false)
						) {
							await delBtn.click().catch(() => {});
							const confirmBtn = page
								.getByRole("button", {
									name: /delete webhook/i,
								})
								.last();
							if (
								await confirmBtn
									.isVisible({ timeout: 1000 })
									.catch(() => false)
							) {
								await confirmBtn.click().catch(() => {});
							}
						}
					}
				} catch (uiFallbackErr) {
					console.warn(
						"UI fallback deletion encountered error:",
						uiFallbackErr
					);
				}
			}

			// Restore initial webhooks toggle switch state
			if (!wasInitiallyChecked) {
				try {
					const currentChecked =
						(await toggleSwitch.getAttribute("aria-checked")) ===
						"true";
					if (currentChecked) {
						await toggleSwitch.click();
						await expect(toggleSwitch).toHaveAttribute(
							"aria-checked",
							"false",
							{ timeout: 5000 }
						);
					}
				} catch (toggleErr) {
					console.warn(
						"Failed to restore webhooks toggle state:",
						toggleErr
					);
				}
			}
		}
	});
});
