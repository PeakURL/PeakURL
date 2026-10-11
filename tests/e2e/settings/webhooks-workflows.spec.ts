import { test, expect, generateTestId } from "../fixtures/auth.fixture";

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

	test("delivery drawer retention draft synchronization: adopts 90 and 0, genuine edit survives refresh, save error/success", async ({
		authenticatedPage: page,
	}) => {
		const testWebhookLabel = generateTestId("drawer-sync");
		let createdWebhookId: string | null = null;

		try {
			// 1. Create a test webhook via API
			const createRes = await page.request.post("/api/v1/webhooks", {
				data: {
					label: testWebhookLabel,
					url: "https://93.184.216.34/sync-webhook",
					events: ["link.created"],
					deliveryRetentionDays: 30,
				},
			});
			expect(createRes.status()).toBe(201);
			const createData = await createRes.json();
			createdWebhookId = createData.id ?? createData.data?.id ?? null;
			expect(createdWebhookId).toBeTruthy();

			// 2. Navigate to Settings -> Integrations
			await page.goto("/dashboard/settings/integrations", {
				waitUntil: "commit",
			});
			await expect(
				page.getByRole("heading", { name: /webhooks/i })
			).toBeVisible({ timeout: 25000 });
			await expect(
				page.getByText(/loading webhook configuration/i)
			).toHaveCount(0, { timeout: 15000 });

			// 3. Locate webhook card and click Deliveries
			const card = page.locator(".integrations-tab-webhook-card", {
				hasText: testWebhookLabel,
			});
			await expect(card).toBeVisible({ timeout: 15000 });
			await card.getByRole("button", { name: /deliveries/i }).click();

			// 4. Drawer opens with baseline 30 days
			const drawer = page.locator(".webhook-drawer-panel");
			await expect(drawer).toBeVisible({ timeout: 10000 });

			const retentionSelect = drawer.locator(
				"button[aria-label='History Retention']"
			);
			await expect(retentionSelect).toContainText("30 days (Default)");
			await expect(
				drawer.getByRole("button", { name: "Save" })
			).toBeHidden();

			// 5. Test delayed pre-save GET: hold request in-flight, perform Save 7, release old GET, verify 7 is protected
			let releasePreSaveGet: (() => void) | null = null;
			let delayNextGet = true;
			let simulatedRetention = 30;

			const isWebhookListRequest = (urlStr: string, method: string) => {
				if (method !== "GET") return false;
				try {
					const urlObj = new URL(urlStr);
					return (
						urlObj.pathname === "/api/v1/webhooks" ||
						urlObj.pathname === "/api/v1/webhooks/"
					);
				} catch {
					return (
						urlStr.includes("/api/v1/webhooks") &&
						!urlStr.includes("/deliveries") &&
						!urlStr.includes("/events")
					);
				}
			};

			await page.route("**/api/v1/webhooks*", async (route) => {
				if (
					!isWebhookListRequest(
						route.request().url(),
						route.request().method()
					)
				) {
					return route.continue();
				}
				if (delayNextGet) {
					delayNextGet = false;
					// Capture stale response data when the request starts
					const response = await route.fetch();
					const json = await response.json();
					const rawItems = json.data ?? json;
					const items = Array.isArray(rawItems)
						? rawItems.map(
								(wh: {
									id?: string;
									[key: string]: unknown;
								}) =>
									wh.id === createdWebhookId
										? {
												...wh,
												deliveryRetentionDays:
													simulatedRetention,
												delivery_retention_days:
													simulatedRetention,
											}
										: wh
							)
						: rawItems;
					const staleBody =
						json.data !== undefined
							? { ...json, data: items }
							: items;

					await new Promise<void>((resolve) => {
						releasePreSaveGet = resolve;
					});

					await route.fulfill({
						response,
						json: staleBody,
					});
					return;
				}
				const response = await route.fetch();
				const json = await response.json();
				const rawItems = json.data ?? json;
				const items = Array.isArray(rawItems)
					? rawItems.map(
							(wh: { id?: string; [key: string]: unknown }) =>
								wh.id === createdWebhookId
									? {
											...wh,
											deliveryRetentionDays:
												simulatedRetention,
											delivery_retention_days:
												simulatedRetention,
										}
									: wh
						)
					: rawItems;
				await route.fulfill({
					response,
					json:
						json.data !== undefined
							? { ...json, data: items }
							: items,
				});
			});

			// Initiate pre-save GET and hold it in-flight
			const preSaveReqPromise = page.waitForRequest((req) =>
				isWebhookListRequest(req.url(), req.method())
			);
			await page.evaluate(() => window.dispatchEvent(new Event("focus")));
			await preSaveReqPromise;

			// While pre-save GET is in-flight, user selects 7 days and saves
			await retentionSelect.click();
			await page.getByRole("option", { name: "7 days" }).click();
			const saveButton = drawer.getByRole("button", { name: "Save" });
			await expect(saveButton).toBeVisible();

			const putPromise = page.waitForResponse(
				(res) =>
					res.url().includes("/api/v1/webhooks/") &&
					res.request().method() === "PUT"
			);
			await saveButton.click();
			const putRes = await putPromise;
			expect(putRes.status()).toBe(200);

			await expect(
				page.getByText(/Delivery retention period updated/i)
			).toBeVisible({ timeout: 10000 });
			await expect(saveButton).toBeHidden();
			await expect(retentionSelect).toContainText("7 days");

			// Release the delayed pre-save GET (returns 30); verify confirmed 7 is protected
			const delayedGetPromise = page.waitForResponse((res) =>
				isWebhookListRequest(res.url(), res.request().method())
			);
			releasePreSaveGet?.();
			await delayedGetPromise;

			await expect(retentionSelect).toContainText("7 days");
			await expect(saveButton).toBeHidden();

			// 6. Newer authoritative GET returning 90 without an intervening GET returning 7
			simulatedRetention = 90;
			const freshGet90 = page.waitForResponse((res) =>
				isWebhookListRequest(res.url(), res.request().method())
			);
			await page.evaluate(() => window.dispatchEvent(new Event("focus")));
			await freshGet90;
			await expect(retentionSelect).toContainText("90 days", {
				timeout: 10000,
			});
			await expect(saveButton).toBeHidden();

			// 7. Equivalent transition to 0: untouched draft adopts 0
			simulatedRetention = 0;
			const freshGet0 = page.waitForResponse((res) =>
				isWebhookListRequest(res.url(), res.request().method())
			);
			await page.evaluate(() => window.dispatchEvent(new Event("focus")));
			await freshGet0;
			await expect(retentionSelect).toContainText(
				"Keep indefinitely (Disabled)",
				{ timeout: 10000 }
			);
			await expect(saveButton).toBeHidden();

			// 8. Genuine local edit: user selects 7 days, survives refresh
			await retentionSelect.click();
			await page.getByRole("option", { name: "7 days" }).click();
			await expect(retentionSelect).toContainText("7 days");
			await expect(saveButton).toBeVisible();

			const freshGetSurvive = page.waitForResponse((res) =>
				isWebhookListRequest(res.url(), res.request().method())
			);
			await page.evaluate(() => window.dispatchEvent(new Event("focus")));
			await freshGetSurvive;
			await expect(retentionSelect).toContainText("7 days");
			await expect(saveButton).toBeVisible();

			// 9. Failed Save preserves draft and shows error notification
			await page.route(
				`**/api/v1/webhooks/${createdWebhookId}`,
				async (route) => {
					if (route.request().method() === "PUT") {
						await route.fulfill({
							status: 500,
							json: {
								message: "Simulated internal server error",
							},
						});
					} else {
						await route.continue();
					}
				}
			);

			await saveButton.click();
			await expect(
				page.getByText(
					/Simulated internal server error|Failed to update delivery retention/i
				)
			).toBeVisible({ timeout: 5000 });
			await expect(retentionSelect).toContainText("7 days");
			await expect(saveButton).toBeVisible();

			// 10. Successful Save retry: confirmed value and clean UI
			await page.unroute(`**/api/v1/webhooks/${createdWebhookId}`);
			await saveButton.click();
			simulatedRetention = 7;
			await expect(
				page.getByText(/Delivery retention period updated/i)
			).toBeVisible({ timeout: 10000 });
			await expect(saveButton).toBeHidden();
			await expect(retentionSelect).toContainText("7 days");
		} finally {
			await page.unrouteAll({ behavior: "ignoreErrors" });
			if (createdWebhookId) {
				await page.request
					.delete(`/api/v1/webhooks/${createdWebhookId}`)
					.catch(() => {});
			}
		}
	});

	test("delivery drawer: individual delivery deletion, page rollback, active state protection, and 409 error", async ({
		authenticatedPage: page,
	}) => {
		const testWebhookLabel = generateTestId("drawer-del");
		let createdWebhookId: string | null = null;

		try {
			const createRes = await page.request.post("/api/v1/webhooks", {
				data: {
					label: testWebhookLabel,
					url: "https://93.184.216.34/del-webhook",
					events: ["link.created"],
					deliveryRetentionDays: 30,
				},
			});
			expect(createRes.status()).toBe(201);
			const createData = await createRes.json();
			createdWebhookId = createData.id ?? createData.data?.id ?? null;
			expect(createdWebhookId).toBeTruthy();

			const makeDel = (id: string, status: string, resp = 200) => ({
				id,
				webhookId: createdWebhookId,
				eventId: `evt_${id}`,
				event: "link.created",
				status,
				attempts: 1,
				maxAttempts: 3,
				nextAttemptAt: null,
				lastAttemptAt: null,
				completedAt:
					status === "pending" ? null : new Date().toISOString(),
				durationMs: 45,
				responseCode: resp || null,
				lastError: resp === 500 ? "Server Error" : null,
				createdAt: new Date().toISOString(),
			});

			let totalDeliveries = 16;
			const page1Items = [
				makeDel("del_item_active", "pending", 0),
				...Array.from({ length: 14 }, (_, i) =>
					makeDel(`del_p1_${i + 1}`, "delivered", 200)
				),
			];
			const page2Item = makeDel("del_item_p2_last", "failed", 500);

			await page.route(
				`**/api/v1/webhooks/${createdWebhookId}/deliveries*`,
				async (route) => {
					if (route.request().method() === "GET") {
						const url = new URL(route.request().url());
						const reqPage = Number(
							url.searchParams.get("page") || "1"
						);
						const isP2 = reqPage === 2 && totalDeliveries > 15;
						await route.fulfill({
							status: 200,
							json: {
								success: true,
								data: {
									items: isP2 ? [page2Item] : page1Items,
									meta: {
										page: isP2 ? 2 : 1,
										perPage: 15,
										total: totalDeliveries,
										totalPages:
											totalDeliveries > 15 ? 2 : 1,
									},
								},
							},
						});
					} else {
						await route.continue();
					}
				}
			);

			await page.goto("/dashboard/settings/integrations", {
				waitUntil: "commit",
			});
			await expect(
				page.getByRole("heading", { name: /webhooks/i })
			).toBeVisible({ timeout: 25000 });
			const card = page.locator(".integrations-tab-webhook-card", {
				hasText: testWebhookLabel,
			});
			await expect(card).toBeVisible({ timeout: 15000 });
			await card.getByRole("button", { name: /deliveries/i }).click();

			const drawer = page.locator(".webhook-drawer-panel");
			await expect(drawer).toBeVisible({ timeout: 10000 });
			await expect(drawer.getByText("del_item_active")).toBeVisible({
				timeout: 10000,
			});

			// Active delivery deletion is disabled
			const activeDeleteBtn = drawer
				.locator(
					"button[aria-label='Active deliveries cannot be deleted until completed.']"
				)
				.first();
			await expect(activeDeleteBtn).toBeVisible();
			await expect(activeDeleteBtn).toBeDisabled();

			// Navigate to Page 2
			const nextBtn = drawer.getByRole("button", { name: /next/i });
			await expect(nextBtn).toBeEnabled();
			await nextBtn.click();
			await expect(drawer.getByText("del_item_p2_last")).toBeVisible({
				timeout: 10000,
			});

			// Cancel preserves row
			const deleteBtn = drawer
				.locator("button[aria-label='Delete delivery record']")
				.first();
			await deleteBtn.click();
			await expect(
				page.getByRole("heading", { name: /Delete Delivery Record/i })
			).toBeVisible();
			await page.getByRole("button", { name: "Cancel" }).click();
			await expect(
				page.getByRole("heading", { name: /Delete Delivery Record/i })
			).toBeHidden();
			await expect(drawer.getByText("del_item_p2_last")).toBeVisible();

			// 409 conflict handling
			await page.route(
				`**/api/v1/webhooks/${createdWebhookId}/deliveries/del_item_p2_last`,
				async (route) => {
					if (route.request().method() === "DELETE") {
						await route.fulfill({
							status: 409,
							json: {
								message:
									"Active webhook deliveries cannot be deleted.",
							},
						});
					} else {
						await route.continue();
					}
				}
			);

			await deleteBtn.click();
			await page.getByRole("button", { name: "Delete Delivery" }).click();
			await expect(
				page.getByText(/Active webhook deliveries cannot be deleted/i)
			).toBeVisible({ timeout: 5000 });
			await expect(drawer.getByText("del_item_p2_last")).toBeVisible();
			await page.getByRole("button", { name: "Cancel" }).click();
			await expect(
				page.getByRole("heading", { name: /Delete Delivery Record/i })
			).toBeHidden();

			// Successful deletion rolls back to Page 1
			await page.route(
				`**/api/v1/webhooks/${createdWebhookId}/deliveries/del_item_p2_last`,
				async (route) => {
					if (route.request().method() === "DELETE") {
						totalDeliveries = 15;
						await route.fulfill({
							status: 200,
							json: { success: true, data: { deleted: true } },
						});
					} else {
						await route.continue();
					}
				}
			);

			await deleteBtn.click();
			await page.getByRole("button", { name: "Delete Delivery" }).click();
			await expect(
				page.getByText(/Delivery record deleted/i)
			).toBeVisible({ timeout: 10000 });

			await expect(drawer.getByText("del_item_active")).toBeVisible({
				timeout: 10000,
			});
			await expect(drawer.getByText("del_item_p2_last")).toHaveCount(0);
		} finally {
			await page.unrouteAll({ behavior: "ignoreErrors" });
			if (createdWebhookId) {
				await page.request
					.delete(`/api/v1/webhooks/${createdWebhookId}`)
					.catch(() => {});
			}
		}
	});

	test("delivery drawer: clear history confirmation, cancellation, active record preservation, and page preservation across refetch", async ({
		authenticatedPage: page,
	}) => {
		const testWebhookLabel = generateTestId("drawer-clr");
		let createdWebhookId: string | null = null;

		try {
			const createRes = await page.request.post("/api/v1/webhooks", {
				data: {
					label: testWebhookLabel,
					url: "https://93.184.216.34/clear-webhook",
					events: ["link.created"],
					deliveryRetentionDays: 30,
				},
			});
			expect(createRes.status()).toBe(201);
			const createData = await createRes.json();
			createdWebhookId = createData.id ?? createData.data?.id ?? null;
			expect(createdWebhookId).toBeTruthy();

			const makeItem = (id: string, status: string) => ({
				id,
				webhookId: createdWebhookId,
				eventId: `evt_${id}`,
				event: "link.created",
				status,
				attempts: 1,
				maxAttempts: 3,
				nextAttemptAt: null,
				lastAttemptAt: null,
				completedAt:
					status === "pending" ? null : new Date().toISOString(),
				durationMs: 40,
				responseCode: 200,
				lastError: null,
				createdAt: new Date().toISOString(),
			});

			const activeItem = makeItem("del_clr_active", "pending");
			const terminalItems = Array.from({ length: 16 }, (_, i) =>
				makeItem(`del_clr_term_${i + 1}`, "delivered")
			);

			let isCleared = false;

			await page.route(
				`**/api/v1/webhooks/${createdWebhookId}/deliveries*`,
				async (route) => {
					if (route.request().method() === "GET") {
						const url = new URL(route.request().url());
						const reqPage = Number(
							url.searchParams.get("page") || "1"
						);

						if (isCleared) {
							await route.fulfill({
								status: 200,
								json: {
									success: true,
									data: {
										items: [activeItem],
										meta: {
											page: 1,
											perPage: 15,
											total: 1,
											totalPages: 1,
										},
									},
								},
							});
						} else {
							const allItems = [activeItem, ...terminalItems];
							const start = (reqPage - 1) * 15;
							await route.fulfill({
								status: 200,
								json: {
									success: true,
									data: {
										items: allItems.slice(
											start,
											start + 15
										),
										meta: {
											page: reqPage,
											perPage: 15,
											total: allItems.length,
											totalPages: 2,
										},
									},
								},
							});
						}
					} else {
						await route.continue();
					}
				}
			);

			await page.goto("/dashboard/settings/integrations", {
				waitUntil: "commit",
			});
			await expect(
				page.getByRole("heading", { name: /webhooks/i })
			).toBeVisible({ timeout: 25000 });
			const card = page.locator(".integrations-tab-webhook-card", {
				hasText: testWebhookLabel,
			});
			await expect(card).toBeVisible({ timeout: 15000 });
			await card.getByRole("button", { name: /deliveries/i }).click();

			const drawer = page.locator(".webhook-drawer-panel");
			await expect(drawer).toBeVisible({ timeout: 10000 });

			// Go to Page 2
			const nextBtn = drawer.getByRole("button", { name: /next/i });
			await expect(nextBtn).toBeEnabled();
			await nextBtn.click();
			await expect(
				drawer.getByText("Showing page 2 of 2 (17 total)")
			).toBeVisible({ timeout: 10000 });

			// Background refetch does NOT reset user's delivery page
			await page.evaluate(() => window.dispatchEvent(new Event("focus")));
			await expect(
				drawer.getByText("Showing page 2 of 2 (17 total)")
			).toBeVisible({ timeout: 5000 });

			// Cancel preserves records
			const clearBtn = drawer.getByRole("button", {
				name: /clear history/i,
			});
			await clearBtn.click();
			await expect(
				page.getByRole("heading", { name: /Clear Completed History/i })
			).toBeVisible();
			await page.getByRole("button", { name: "Cancel" }).click();
			await expect(
				page.getByRole("heading", { name: /Clear Completed History/i })
			).toBeHidden();
			await expect(
				drawer.getByText("Showing page 2 of 2 (17 total)")
			).toBeVisible();

			// 409 conflict on clear history
			await page.route(
				`**/api/v1/webhooks/${createdWebhookId}/deliveries`,
				async (route) => {
					if (route.request().method() === "DELETE") {
						await route.fulfill({
							status: 409,
							json: {
								message:
									"Active webhook deliveries cannot be deleted.",
							},
						});
					} else {
						await route.continue();
					}
				}
			);

			await clearBtn.click();
			await page
				.getByRole("button", { name: "Clear Completed History" })
				.click();
			await expect(
				page.getByText(/Active webhook deliveries cannot be deleted/i)
			).toBeVisible({ timeout: 5000 });
			await page.getByRole("button", { name: "Cancel" }).click();
			await expect(
				page.getByRole("heading", { name: /Clear Completed History/i })
			).toBeHidden();

			// Successful clear preserves active item and resets to page 1
			await page.route(
				`**/api/v1/webhooks/${createdWebhookId}/deliveries`,
				async (route) => {
					if (route.request().method() === "DELETE") {
						isCleared = true;
						await route.fulfill({
							status: 200,
							json: { success: true, data: { deletedCount: 16 } },
						});
					} else {
						await route.continue();
					}
				}
			);

			await clearBtn.click();
			await page
				.getByRole("button", { name: "Clear Completed History" })
				.click();
			await expect(
				page.getByText(/Cleared 16 completed delivery records/i)
			).toBeVisible({ timeout: 10000 });

			await expect(drawer.getByText("del_clr_active")).toBeVisible({
				timeout: 10000,
			});
			await expect(
				drawer.getByText("del_clr_term_1", { exact: true })
			).toHaveCount(0);
		} finally {
			await page.unrouteAll({ behavior: "ignoreErrors" });
			if (createdWebhookId) {
				await page.request
					.delete(`/api/v1/webhooks/${createdWebhookId}`)
					.catch(() => {});
			}
		}
	});
});
