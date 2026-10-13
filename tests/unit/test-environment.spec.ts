import { test, expect } from "@playwright/test";
import {
	APPROVED_TEST_HOSTNAMES,
	isSafeTestHostname,
	isExplicitlyAuthorizedTestInstance,
	assertSafeDisposableTestInstance,
	captureRoleBaseline,
	restoreAndVerifyRoleBaseline,
	type RoleBaseline,
} from "../e2e/fixtures/test-environment";

test.describe("Test Environment & Hostname Safety Utilities", () => {
	test.describe("isSafeTestHostname", () => {
		test("accepts exact approved loopback and local test hostnames", () => {
			expect(isSafeTestHostname("http://localhost:5173")).toBe(true);
			expect(isSafeTestHostname("http://localhost:8000/dashboard")).toBe(
				true
			);
			expect(isSafeTestHostname("http://127.0.0.1:3307")).toBe(true);
			expect(isSafeTestHostname("http://127.0.0.1:8000/api/v1")).toBe(
				true
			);
			expect(isSafeTestHostname("http://[::1]:8000")).toBe(true);
			expect(isSafeTestHostname("https://peakurl.test")).toBe(true);
			expect(
				isSafeTestHostname("https://peakurl.test/dashboard/users")
			).toBe(true);
			expect(isSafeTestHostname("https://peakurl.dev")).toBe(true);
			expect(isSafeTestHostname("https://peakurl.dev/api/v1/roles")).toBe(
				true
			);
		});

		test("accepts root-relative paths defaulting to localhost", () => {
			expect(isSafeTestHostname("/dashboard/users")).toBe(true);
			expect(isSafeTestHostname("/api/v1/roles")).toBe(true);
		});

		test("rejects external hostnames with localhost in query string or path", () => {
			expect(isSafeTestHostname("https://evil.com/localhost")).toBe(
				false
			);
			expect(
				isSafeTestHostname("https://evil.com?target=localhost")
			).toBe(false);
			expect(isSafeTestHostname("https://evil.com#localhost")).toBe(
				false
			);
			expect(isSafeTestHostname("https://evil.com/peakurl.test")).toBe(
				false
			);
			expect(
				isSafeTestHostname("https://evil.com?host=peakurl.dev")
			).toBe(false);
		});

		test("rejects lookalike domains and subdomains of approved test hosts", () => {
			expect(isSafeTestHostname("https://not-peakurl.dev")).toBe(false);
			expect(isSafeTestHostname("https://peakurl.dev.attacker.com")).toBe(
				false
			);
			expect(isSafeTestHostname("https://peakurl.test.evil.org")).toBe(
				false
			);
			expect(isSafeTestHostname("https://127.0.0.1.nip.io")).toBe(false);
			expect(isSafeTestHostname("https://subdomain.peakurl.dev")).toBe(
				false
			);
			expect(isSafeTestHostname("https://api.peakurl.dev")).toBe(false);
		});

		test("rejects malformed, empty, or non-string inputs", () => {
			expect(isSafeTestHostname("")).toBe(false);
			expect(isSafeTestHostname("   ")).toBe(false);
			expect(isSafeTestHostname("not-a-valid-url-scheme://")).toBe(false);
			expect(isSafeTestHostname(null as any)).toBe(false);
			expect(isSafeTestHostname(undefined as any)).toBe(false);
		});
	});

	test.describe("isExplicitlyAuthorizedTestInstance", () => {
		test("recognizes explicit disposable test authorization flags", () => {
			expect(
				isExplicitlyAuthorizedTestInstance({
					PEAKURL_TEST_DISPOSABLE_DB: "1",
				})
			).toBe(true);
			expect(
				isExplicitlyAuthorizedTestInstance({
					PEAKURL_TEST_DISPOSABLE_DB: "true",
				})
			).toBe(true);
			expect(
				isExplicitlyAuthorizedTestInstance({ PEAKURL_TEST_ENV: "1" })
			).toBe(true);
			expect(
				isExplicitlyAuthorizedTestInstance({ PEAKURL_TEST_ENV: "true" })
			).toBe(true);
			expect(
				isExplicitlyAuthorizedTestInstance({
					PEAKURL_ALLOW_ROLE_MUTATION: "1",
				})
			).toBe(true);
		});

		test("fails closed when authorization flag is missing or disabled", () => {
			expect(isExplicitlyAuthorizedTestInstance({})).toBe(false);
			expect(
				isExplicitlyAuthorizedTestInstance({
					PEAKURL_TEST_DISPOSABLE_DB: "0",
				})
			).toBe(false);
			expect(
				isExplicitlyAuthorizedTestInstance({
					PEAKURL_TEST_DISPOSABLE_DB: "false",
				})
			).toBe(false);
			expect(
				isExplicitlyAuthorizedTestInstance({ UNRELATED_VAR: "1" })
			).toBe(false);
		});
	});

	test.describe("assertSafeDisposableTestInstance", () => {
		test("passes when host is approved and environment is authorized", () => {
			expect(() => {
				assertSafeDisposableTestInstance("https://peakurl.test", {
					PEAKURL_TEST_DISPOSABLE_DB: "1",
				});
			}).not.toThrow();
		});

		test("throws descriptive error when host is not approved", () => {
			expect(() => {
				assertSafeDisposableTestInstance("https://evil.com/localhost", {
					PEAKURL_TEST_DISPOSABLE_DB: "1",
				});
			}).toThrow(
				/Refusing to execute mutating test against non-approved host 'evil.com'/
			);
		});

		test("throws descriptive error when disposable authorization is missing", () => {
			expect(() => {
				assertSafeDisposableTestInstance("https://peakurl.test", {});
			}).toThrow(
				/Refusing to execute mutating role tests without explicit disposable instance authorization/
			);
		});
	});

	test.describe("Role Baseline Capture & Verified Restoration", () => {
		const mockDefaultEditorRole = {
			id: "editor",
			name: "Editor",
			is_customized: false,
			capabilities: [
				{
					id: "create_links",
					enabled: true,
					applicable: true,
					protected: false,
				},
				{
					id: "delete_links",
					enabled: false,
					applicable: true,
					protected: false,
				},
				{
					id: "manage_webhooks",
					enabled: false,
					applicable: true,
					protected: false,
				},
				{
					id: "manage_users",
					enabled: false,
					applicable: false,
					protected: true,
				},
				{
					id: "empty_trash",
					enabled: false,
					applicable: false,
					protected: true,
				},
			],
		};

		const mockCustomizedEditorRole = {
			id: "editor",
			name: "Editor",
			is_customized: true,
			capabilities: [
				{
					id: "create_links",
					enabled: true,
					applicable: true,
					protected: false,
				},
				{
					id: "delete_links",
					enabled: true,
					applicable: true,
					protected: false,
				},
				{
					id: "manage_webhooks",
					enabled: false,
					applicable: true,
					protected: false,
				},
				{
					id: "manage_users",
					enabled: false,
					applicable: false,
					protected: true,
				},
				{
					id: "empty_trash",
					enabled: false,
					applicable: false,
					protected: true,
				},
			],
		};

		function createMockClient(options: {
			get?: (url: string) => {
				ok: boolean;
				status: number;
				text: string;
				data?: any;
			};
			put?: (
				url: string,
				body?: any
			) => { ok: boolean; status: number; text: string; data?: any };
			post?: (
				url: string,
				body?: any
			) => { ok: boolean; status: number; text: string; data?: any };
		}) {
			return {
				get: async (url: string) => {
					const res = options.get
						? options.get(url)
						: { ok: true, status: 200, text: "{}", data: {} };
					return {
						ok: () => res.ok,
						status: () => res.status,
						text: async () => res.text,
						json: async () => res.data ?? JSON.parse(res.text),
					};
				},
				put: async (url: string, body?: any) => {
					const res = options.put
						? options.put(url, body)
						: { ok: true, status: 200, text: "{}", data: {} };
					return {
						ok: () => res.ok,
						status: () => res.status,
						text: async () => res.text,
						json: async () => res.data ?? JSON.parse(res.text),
					};
				},
				post: async (url: string, body?: any) => {
					const res = options.post
						? options.post(url, body)
						: { ok: true, status: 200, text: "{}", data: {} };
					return {
						ok: () => res.ok,
						status: () => res.status,
						text: async () => res.text,
						json: async () => res.data ?? JSON.parse(res.text),
					};
				},
			};
		}

		test.describe("captureRoleBaseline", () => {
			test("captures default role baseline and excludes non-applicable or protected capabilities", async () => {
				const client = createMockClient({
					get: () => ({
						ok: true,
						status: 200,
						text: "",
						data: { data: [mockDefaultEditorRole] },
					}),
				});

				const baseline = await captureRoleBaseline(client, "editor");
				expect(baseline.roleId).toBe("editor");
				expect(baseline.isCustomized).toBe(false);
				expect(baseline.capabilities).toEqual({
					create_links: true,
					delete_links: false,
					manage_webhooks: false,
				});
				expect(baseline.capabilities["manage_users"]).toBeUndefined();
				expect(baseline.capabilities["empty_trash"]).toBeUndefined();
				expect(baseline.rawDefinition).toBeDefined();
			});

			test("captures pre-customized role baseline", async () => {
				const client = createMockClient({
					get: () => ({
						ok: true,
						status: 200,
						text: "",
						data: { data: [mockCustomizedEditorRole] },
					}),
				});

				const baseline = await captureRoleBaseline(client, "editor");
				expect(baseline.isCustomized).toBe(true);
				expect(baseline.capabilities["delete_links"]).toBe(true);
			});

			test("throws descriptive error when GET /api/v1/roles fails with HTTP error", async () => {
				const client = createMockClient({
					get: () => ({
						ok: false,
						status: 500,
						text: "Internal Server Error",
					}),
				});

				await expect(
					captureRoleBaseline(client, "editor")
				).rejects.toThrow(
					/Failed to capture role baseline for 'editor': HTTP 500 - Internal Server Error/
				);
			});

			test("throws descriptive error when requested role is not found", async () => {
				const client = createMockClient({
					get: () => ({
						ok: true,
						status: 200,
						text: "",
						data: { data: [] },
					}),
				});

				await expect(
					captureRoleBaseline(client, "editor")
				).rejects.toThrow(
					/Role 'editor' was not found in GET \/api\/v1\/roles response/
				);
			});
		});

		test.describe("restoreAndVerifyRoleBaseline", () => {
			test("resets uncustomized role via POST reset endpoint and verifies state", async () => {
				let postUrl = "";
				let getCount = 0;
				let putCalled = false;

				const client = createMockClient({
					post: (url) => {
						postUrl = url;
						return {
							ok: true,
							status: 200,
							text: '{"success":true}',
						};
					},
					put: () => {
						putCalled = true;
						return { ok: true, status: 200, text: "{}" };
					},
					get: () => {
						getCount++;
						return {
							ok: true,
							status: 200,
							text: "",
							data: { data: [mockDefaultEditorRole] },
						};
					},
				});

				const baseline: RoleBaseline = {
					roleId: "editor",
					isCustomized: false,
					capabilities: {
						create_links: true,
						delete_links: false,
						manage_webhooks: false,
					},
					rawDefinition: mockDefaultEditorRole,
				};

				await restoreAndVerifyRoleBaseline(client, baseline);

				expect(postUrl).toBe("/api/v1/roles/editor/reset");
				expect(putCalled).toBe(false);
				expect(getCount).toBe(1);
			});

			test("restores customized role via PUT endpoint with baseline capabilities and verifies state", async () => {
				let putUrl = "";
				let putBody: any = null;
				let postCalled = false;

				const client = createMockClient({
					put: (url, body) => {
						putUrl = url;
						putBody = body;
						return {
							ok: true,
							status: 200,
							text: '{"success":true}',
						};
					},
					post: () => {
						postCalled = true;
						return { ok: true, status: 200, text: "{}" };
					},
					get: () => ({
						ok: true,
						status: 200,
						text: "",
						data: { data: [mockCustomizedEditorRole] },
					}),
				});

				const baseline: RoleBaseline = {
					roleId: "editor",
					isCustomized: true,
					capabilities: {
						create_links: true,
						delete_links: true,
						manage_webhooks: false,
					},
					rawDefinition: mockCustomizedEditorRole,
				};

				await restoreAndVerifyRoleBaseline(client, baseline);

				expect(putUrl).toBe("/api/v1/roles/editor");
				expect(putBody).toEqual({
					data: {
						capabilities: {
							create_links: true,
							delete_links: true,
							manage_webhooks: false,
						},
					},
				});
				expect(postCalled).toBe(false);
			});

			test("throws descriptive error when POST reset fails with HTTP status and body", async () => {
				const client = createMockClient({
					post: () => ({
						ok: false,
						status: 500,
						text: "Database lock timeout",
					}),
				});

				const baseline: RoleBaseline = {
					roleId: "editor",
					isCustomized: false,
					capabilities: { create_links: true },
					rawDefinition: {},
				};

				await expect(
					restoreAndVerifyRoleBaseline(client, baseline)
				).rejects.toThrow(
					/Failed to reset role 'editor' to defaults: HTTP 500 - Database lock timeout/
				);
			});

			test("throws descriptive error when PUT restore fails with HTTP status and body", async () => {
				const client = createMockClient({
					put: () => ({
						ok: false,
						status: 403,
						text: "Forbidden action",
					}),
				});

				const baseline: RoleBaseline = {
					roleId: "editor",
					isCustomized: true,
					capabilities: { create_links: true },
					rawDefinition: {},
				};

				await expect(
					restoreAndVerifyRoleBaseline(client, baseline)
				).rejects.toThrow(
					/Failed to restore customized role 'editor': HTTP 403 - Forbidden action/
				);
			});

			test("throws descriptive error when verification re-fetch fails", async () => {
				const client = createMockClient({
					post: () => ({ ok: true, status: 200, text: "{}" }),
					get: () => ({
						ok: false,
						status: 502,
						text: "Bad Gateway",
					}),
				});

				const baseline: RoleBaseline = {
					roleId: "editor",
					isCustomized: false,
					capabilities: { create_links: true },
					rawDefinition: {},
				};

				await expect(
					restoreAndVerifyRoleBaseline(client, baseline)
				).rejects.toThrow(
					/Failed to refetch roles to verify restoration of 'editor': HTTP 502 - Bad Gateway/
				);
			});

			test("throws descriptive error when re-fetched is_customized flag mismatches baseline", async () => {
				const client = createMockClient({
					post: () => ({ ok: true, status: 200, text: "{}" }),
					get: () => ({
						ok: true,
						status: 200,
						text: "",
						data: {
							data: [
								{
									...mockDefaultEditorRole,
									is_customized: true,
								},
							],
						},
					}),
				});

				const baseline: RoleBaseline = {
					roleId: "editor",
					isCustomized: false,
					capabilities: {
						create_links: true,
						delete_links: false,
						manage_webhooks: false,
					},
					rawDefinition: {},
				};

				await expect(
					restoreAndVerifyRoleBaseline(client, baseline)
				).rejects.toThrow(
					/Role restoration verification failed for 'editor': expected is_customized=false, found true/
				);
			});

			test("throws descriptive error when re-fetched capability value mismatches baseline", async () => {
				const client = createMockClient({
					post: () => ({ ok: true, status: 200, text: "{}" }),
					get: () => ({
						ok: true,
						status: 200,
						text: "",
						data: {
							data: [
								{
									...mockDefaultEditorRole,
									capabilities: [
										{
											id: "create_links",
											enabled: true,
											applicable: true,
											protected: false,
										},
										{
											id: "delete_links",
											enabled: true,
											applicable: true,
											protected: false,
										}, // mismatch!
										{
											id: "manage_webhooks",
											enabled: false,
											applicable: true,
											protected: false,
										},
									],
								},
							],
						},
					}),
				});

				const baseline: RoleBaseline = {
					roleId: "editor",
					isCustomized: false,
					capabilities: {
						create_links: true,
						delete_links: false,
						manage_webhooks: false,
					},
					rawDefinition: {},
				};

				await expect(
					restoreAndVerifyRoleBaseline(client, baseline)
				).rejects.toThrow(
					/capability 'delete_links' expected enabled=false but found enabled=true/
				);
			});
		});

		test.describe("Guaranteed Teardown & Failure Diagnostics", () => {
			test("demonstrates verified restoration executes when test fails after fixture mutation", async () => {
				let serverRoleState = JSON.parse(
					JSON.stringify(mockDefaultEditorRole)
				);

				const statefulClient = {
					get: async () => ({
						ok: () => true,
						status: () => 200,
						text: async () =>
							JSON.stringify({ data: [serverRoleState] }),
						json: async () => ({ data: [serverRoleState] }),
					}),
					put: async (_url: string, body?: any) => {
						serverRoleState = {
							...serverRoleState,
							is_customized: true,
							capabilities: serverRoleState.capabilities.map(
								(c: any) =>
									body?.data?.capabilities?.[c.id] !==
									undefined
										? {
												...c,
												enabled:
													body.data.capabilities[
														c.id
													],
											}
										: c
							),
						};
						return {
							ok: () => true,
							status: () => 200,
							text: async () => '{"success":true}',
							json: async () => ({ success: true }),
						};
					},
					post: async () => {
						serverRoleState = JSON.parse(
							JSON.stringify(mockDefaultEditorRole)
						);
						return {
							ok: () => true,
							status: () => 200,
							text: async () => '{"success":true}',
							json: async () => ({ success: true }),
						};
					},
				};

				// 1. Establish pre-test baseline
				const baseline = await captureRoleBaseline(
					statefulClient,
					"editor"
				);
				expect(baseline.isCustomized).toBe(false);

				let testError: unknown = null;
				try {
					// 2. Perform fixture mutation
					await statefulClient.put("/api/v1/roles/editor", {
						data: { capabilities: { delete_links: true } },
					});
					expect(serverRoleState.is_customized).toBe(true);

					// 3. Simulate failure midway through test
					throw new Error("Mid-test fixture assertion failed!");
				} catch (err) {
					testError = err;
				} finally {
					// 4. Guaranteed cleanup restores original baseline
					await restoreAndVerifyRoleBaseline(
						statefulClient,
						baseline
					);
				}

				// Verify original state is restored on server
				expect(serverRoleState.is_customized).toBe(false);
				expect(
					serverRoleState.capabilities.find(
						(c: any) => c.id === "delete_links"
					).enabled
				).toBe(false);
				expect((testError as Error).message).toBe(
					"Mid-test fixture assertion failed!"
				);
			});

			test("surfaces combined diagnostic when both test and cleanup fail", async () => {
				const client = createMockClient({
					post: () => ({
						ok: false,
						status: 500,
						text: "Cleanup database timeout",
					}),
				});

				const baseline: RoleBaseline = {
					roleId: "editor",
					isCustomized: false,
					capabilities: { create_links: true },
					rawDefinition: {},
				};

				let testError: unknown = null;
				let thrownError: unknown = null;

				try {
					try {
						throw new Error("Primary test assertion failed");
					} catch (err) {
						testError = err;
					} finally {
						try {
							await restoreAndVerifyRoleBaseline(
								client,
								baseline
							);
						} catch (cleanupErr) {
							if (testError) {
								const origMsg =
									testError instanceof Error
										? testError.stack || testError.message
										: String(testError);
								const cleanMsg =
									cleanupErr instanceof Error
										? cleanupErr.stack || cleanupErr.message
										: String(cleanupErr);
								throw new Error(
									`Test failed:\n${origMsg}\n\nAdditionally, baseline restoration failed:\n${cleanMsg}`
								);
							}
							throw cleanupErr;
						}
						if (testError) {
							throw testError;
						}
					}
				} catch (err) {
					thrownError = err;
				}

				expect(thrownError).toBeInstanceOf(Error);
				const msg = (thrownError as Error).message;
				expect(msg).toContain("Primary test assertion failed");
				expect(msg).toContain(
					"Additionally, baseline restoration failed"
				);
				expect(msg).toContain("Cleanup database timeout");
			});
		});
	});
});
