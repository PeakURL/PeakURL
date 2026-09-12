import { test as baseTest, expect, type Page } from "@playwright/test";

export interface TestCredentials {
	identifier: string;
	password: string;
}

export const DEFAULT_ADMIN_CREDENTIALS: TestCredentials = {
	identifier:
		process.env.PEAKURL_TEST_IDENTIFIER ||
		process.env.PEAKURL_E2E_USERNAME ||
		"admin",
	password:
		process.env.PEAKURL_TEST_PASSWORD ||
		process.env.PEAKURL_E2E_PASSWORD ||
		"admin12345",
};

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
	credentials: TestCredentials = DEFAULT_ADMIN_CREDENTIALS
): Promise<void> {
	await page.goto("/login", { waitUntil: "domcontentloaded" });

	const identifierInput = page.getByLabel(
		/email or username|username or email/i
	);
	const passwordInput = page.getByLabel(/^password/i);
	const submitButton = page.getByRole("button", { name: /sign in/i });

	await expect(identifierInput).toBeVisible();
	await identifierInput.fill(credentials.identifier);
	await passwordInput.fill(credentials.password);
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
		await use(DEFAULT_ADMIN_CREDENTIALS);
	},

	authenticatedPage: async ({ page, adminCredentials }, use) => {
		const pageErrors: Error[] = [];
		page.on("pageerror", (err) => pageErrors.push(err));

		// Authenticate via UI flow to establish complete session & state
		await loginViaUi(page, adminCredentials);

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
