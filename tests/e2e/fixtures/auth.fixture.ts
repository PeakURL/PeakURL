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
	await submitButton.click();

	await page.waitForURL("**/dashboard", { timeout: 15000 });
}

export interface AuthFixtures {
	authenticatedPage: Page;
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

		// Explicitly verify the authenticated user has the administrator role
		const meResponse = await page.request.get("/api/v1/users/me");
		expect(meResponse.ok()).toBe(true);
		const meJson = await meResponse.json();
		if (meJson?.data?.role !== "admin") {
			throw new Error(
				`Authenticated test user '${meJson?.data?.username}' has role '${meJson?.data?.role}', expected 'admin'.`
			);
		}

		await use(page);

		// Verify no uncaught runtime errors occurred during the test
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
