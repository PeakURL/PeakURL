import { test as baseTest, expect, type Page } from "@playwright/test";

export interface TestCredentials {
	identifier: string;
	password: string;
}

/**
 * Resolve test administrator password in a fail-closed manner.
 * Plaintext passwords must not be committed to source control.
 */
function getRequiredTestPassword(): string {
	const password =
		process.env.PEAKURL_TEST_PASSWORD || process.env.PEAKURL_E2E_PASSWORD;

	if (!password) {
		throw new Error(
			"PEAKURL_TEST_PASSWORD environment variable is required for authenticated E2E tests"
		);
	}

	return password;
}

/**
 * Return default administrator test credentials, resolving password dynamically.
 */
export function getDefaultAdminCredentials(): TestCredentials {
	return {
		identifier:
			process.env.PEAKURL_TEST_IDENTIFIER ||
			process.env.PEAKURL_E2E_USERNAME ||
			"admin",
		password: getRequiredTestPassword(),
	};
}

/**
 * Generates a unique test identifier to isolate test-created resources.
 */
export function generateTestId(prefix = "e2e"): string {
	const timestamp = Date.now().toString(36);
	const random = Math.random().toString(36).substring(2, 7);
	return `${prefix}-${timestamp}-${random}`;
}

/**
 * Perform a UI-based login on the given page.
 */
export async function loginViaUi(
	page: Page,
	credentials?: TestCredentials
): Promise<void> {
	const creds = credentials || getDefaultAdminCredentials();
	await page.goto("/login", { waitUntil: "domcontentloaded" });

	const identifierInput = page.getByLabel(
		/email or username|username or email/i
	);
	const passwordInput = page.getByLabel(/^password/i);
	const submitButton = page.getByRole("button", { name: /sign in/i });

	await expect(identifierInput).toBeVisible();
	await identifierInput.fill(creds.identifier);
	await passwordInput.fill(creds.password);

	const loginResponsePromise = page.waitForResponse(
		(res) =>
			res.url().includes("/api/v1/auth/login") &&
			res.request().method() === "POST"
	);

	await submitButton.click();
	const loginRes = await loginResponsePromise;
	expect([200, 204]).toContain(loginRes.status());

	await page.waitForURL("**/dashboard", { timeout: 15000 });
	await expect(page.locator(".dashboard-header")).toBeVisible();
}

export const DEFAULT_EDITOR_CREDENTIALS: TestCredentials = {
	identifier: "test_editor",
	password: "EditorPassword123!",
};

/**
 * Ensure a deterministic Editor account exists for role-boundary tests.
 */
export async function ensureEditorUser(
	page: Page,
	playwrightInstance?: any
): Promise<void> {
	const adminCreds = getDefaultAdminCredentials();
	const isolatedContext = playwrightInstance
		? await playwrightInstance.request.newContext({
				baseURL: process.env.PEAKURL_TEST_URL || "https://peakurl.dev",
				ignoreHTTPSErrors: true,
			})
		: null;

	const requestTarget = isolatedContext || page.request;

	// Login as admin via API request
	const loginRes = await requestTarget.post("/api/v1/auth/login", {
		data: {
			identifier: adminCreds.identifier,
			password: adminCreds.password,
		},
	});
	expect([200, 204]).toContain(loginRes.status());

	// Provision editor user if not exists
	await requestTarget.post("/api/v1/users", {
		data: {
			firstName: "Test",
			lastName: "Editor",
			email: "test_editor@example.com",
			username: DEFAULT_EDITOR_CREDENTIALS.identifier,
			password: DEFAULT_EDITOR_CREDENTIALS.password,
			role: "editor",
		},
	});

	if (isolatedContext) {
		await isolatedContext.dispose();
	}
}

export interface AuthFixtures {
	authenticatedPage: Page;
	authenticatedEditorPage: Page;
	adminCredentials: TestCredentials;
	pageErrors: Error[];
}

export const test = baseTest.extend<AuthFixtures>({
	adminCredentials: async ({}, use) => {
		await use(getDefaultAdminCredentials());
	},

	authenticatedPage: async ({ page, adminCredentials }, use) => {
		const pageErrors: Error[] = [];
		page.on("pageerror", (err) => pageErrors.push(err));

		// Authenticate via UI flow to establish complete session & state
		await loginViaUi(page, adminCredentials);

		// Explicitly verify the authenticated user has the administrator role in the browser session
		const meResponse = await page.evaluate(async () => {
			const res = await fetch("/api/v1/users/me", {
				credentials: "include",
			});
			return { ok: res.ok, status: res.status, data: await res.json() };
		});
		expect(meResponse.ok).toBe(true);
		if (meResponse.data?.data?.role !== "admin") {
			throw new Error(
				`Authenticated test user '${meResponse.data?.data?.username}' has role '${meResponse.data?.data?.role}', expected 'admin'.`
			);
		}

		await use(page);

		// Verify no uncaught runtime errors occurred during the test
		if (pageErrors.length > 0) {
			console.error("Uncaught page errors in test:", pageErrors);
		}
		expect(pageErrors).toEqual([]);
	},

	authenticatedEditorPage: async ({ page, playwright }, use) => {
		const pageErrors: Error[] = [];
		page.on("pageerror", (err) => pageErrors.push(err));

		// Ensure editor user exists
		await ensureEditorUser(page, playwright);

		// Authenticate as editor via UI flow
		await loginViaUi(page, DEFAULT_EDITOR_CREDENTIALS);

		// Explicitly verify the authenticated user has the editor role in the browser session
		const meResponse = await page.evaluate(async () => {
			const res = await fetch("/api/v1/users/me", {
				credentials: "include",
			});
			return { ok: res.ok, status: res.status, data: await res.json() };
		});
		expect(meResponse.ok).toBe(true);
		if (meResponse.data?.data?.role !== "editor") {
			throw new Error(
				`Authenticated test user '${meResponse.data?.data?.username}' has role '${meResponse.data?.data?.role}', expected 'editor'.`
			);
		}

		await use(page);

		if (pageErrors.length > 0) {
			console.error("Uncaught page errors in test:", pageErrors);
		}
		expect(pageErrors).toEqual([]);
	},

	pageErrors: async ({ page }, use) => {
		const pageErrors: Error[] = [];
		page.on("pageerror", (err) => pageErrors.push(err));
		await use(pageErrors);
	},
});

export { expect };
