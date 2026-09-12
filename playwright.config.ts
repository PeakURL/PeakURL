import { defineConfig, devices } from "@playwright/test";

/**
 * PeakURL Playwright Test Configuration.
 *
 * Configured for deterministic local dev and CI execution without hardcoded credentials.
 */
export default defineConfig({
	testDir: "./tests",
	fullyParallel: true,
	forbidOnly: Boolean(process.env.CI),
	retries: process.env.CI ? 2 : 0,
	workers: process.env.CI ? 1 : undefined,
	reporter: process.env.CI ? [["list"], ["html", { open: "never" }]] : "list",
	use: {
		baseURL: process.env.PEAKURL_TEST_URL || "http://localhost:5173",
		trace: "on-first-retry",
		screenshot: "only-on-failure",
		video: "retain-on-failure",
	},
	projects: [
		{
			name: "unit",
			testMatch: /tests\/unit\/.*\.spec\.ts/,
		},
		{
			name: "chromium",
			testMatch: /tests\/e2e\/.*\.spec\.ts/,
			use: { ...devices["Desktop Chrome"] },
		},
	],
});
