import {
	test,
	expect,
	ensureEditorUser,
	loginViaUi,
	DEFAULT_EDITOR_CREDENTIALS,
} from "../fixtures/auth.fixture";

test.describe("Scheduled Jobs Admin Journeys", () => {
	test("system status page displays background jobs operational summary", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/tools/system-status", {
			waitUntil: "commit",
		});

		// System Status page heading
		await expect(
			page.getByRole("heading", { name: /system status/i })
		).toBeVisible({ timeout: 25000 });

		// Background Jobs summary card
		const bgJobsCard = page.locator(
			".background-jobs-summary-card:not(.animate-pulse)"
		);
		await expect(
			bgJobsCard.getByRole("heading", { name: /background jobs/i })
		).toBeVisible({ timeout: 25000 });

		// Metric items in summary card
		await expect(bgJobsCard.getByText(/scheduler/i).first()).toBeVisible();
		await expect(bgJobsCard.getByText(/scheduled/i).first()).toBeVisible();
		await expect(bgJobsCard.getByText(/last run/i).first()).toBeVisible();
		await expect(bgJobsCard.getByText(/next due/i).first()).toBeVisible();

		// Navigation link to Scheduled Jobs
		const manageLink = bgJobsCard.getByRole("link", {
			name: /manage scheduled jobs/i,
		});
		await expect(manageLink).toBeVisible();
		await manageLink.click();

		await page.waitForURL("**/dashboard/tools/scheduled-jobs", {
			timeout: 15000,
		});
		await expect(
			page.getByRole("heading", { name: /^scheduled jobs$/i })
		).toBeVisible();
	});

	test("scheduled jobs page loads, displays registered jobs and KPI summary cards", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/tools/scheduled-jobs", {
			waitUntil: "commit",
		});

		// Page Heading & Hero Badge
		await expect(
			page.getByRole("heading", { name: /^scheduled jobs$/i, level: 1 })
		).toBeVisible({ timeout: 25000 });
		await expect(
			page.getByText(/system automation/i).first()
		).toBeVisible();

		// KPI Cards
		await expect(page.getByText(/total jobs/i).first()).toBeVisible();
		await expect(
			page.getByText(/active recurring tasks/i).first()
		).toBeVisible();

		// Action buttons
		const refreshButton = page.getByRole("button", { name: /^refresh$/i });
		await expect(refreshButton).toBeVisible();

		const runDueButton = page.getByRole("button", {
			name: /run due jobs/i,
		});
		await expect(runDueButton).toBeVisible();

		// Jobs table & registered jobs
		const jobsTable = page.locator(".scheduled-jobs-table");
		await expect(jobsTable).toBeVisible();

		// Verify table column headings
		await expect(
			jobsTable.getByRole("columnheader", { name: /job/i })
		).toBeVisible();
		await expect(
			jobsTable.getByRole("columnheader", { name: /status/i })
		).toBeVisible();
		await expect(
			jobsTable.getByRole("columnheader", { name: /schedule/i })
		).toBeVisible();
		await expect(
			jobsTable.getByRole("columnheader", { name: /last run/i })
		).toBeVisible();
		await expect(
			jobsTable.getByRole("columnheader", { name: /next run/i })
		).toBeVisible();
		await expect(
			jobsTable.getByRole("columnheader", { name: /actions/i })
		).toBeVisible();

		// Check presence of at least one built-in job
		await expect(
			page
				.getByText(/session cleanup|version check|geoip update/i)
				.first()
		).toBeVisible();
	});

	test("inspecting execution history opens modal with recent run logs", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/tools/scheduled-jobs", {
			waitUntil: "commit",
		});
		await expect(
			page.getByRole("heading", { name: /^scheduled jobs$/i })
		).toBeVisible({ timeout: 25000 });

		// Click the first History action button
		const historyButtons = page.getByRole("button", { name: /^history$/i });
		await expect(historyButtons.first()).toBeVisible();
		await historyButtons.first().click();

		// Modal should open with title containing Execution History
		const modalHeading = page.getByRole("heading", {
			name: /execution history/i,
		});
		await expect(modalHeading).toBeVisible({ timeout: 10000 });

		// Modal should display Job ID metadata
		await expect(page.getByText(/job id/i)).toBeVisible();
		await expect(page.getByText(/recent execution logs/i)).toBeVisible();

		// Close modal via Escape
		await page.keyboard.press("Escape");
		await expect(modalHeading).not.toBeVisible();
	});

	test("run due jobs confirmation dialog opens and can be safely cancelled", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/tools/scheduled-jobs", {
			waitUntil: "commit",
		});
		await expect(
			page.getByRole("heading", { name: /^scheduled jobs$/i, level: 1 })
		).toBeVisible({ timeout: 25000 });

		// Click Run Due Jobs
		const runDueBtn = page.getByRole("button", { name: /run due jobs/i });
		await runDueBtn.click();

		// Modal opens
		const modal = page.locator(".scheduled-jobs-confirm-modal");
		await expect(modal).toBeVisible();
		await expect(
			page.getByText(/execute currently due background jobs\?/i)
		).toBeVisible();

		// Cancel button closes modal without mutation
		const cancelBtn = page.getByRole("button", { name: /^cancel$/i });
		await cancelBtn.click();
		await expect(modal).not.toBeVisible();
	});

	test("unauthorized user is blocked by UI gate and backend rejects direct API calls", async ({
		page,
		playwright,
	}) => {
		// Provision editor user
		await ensureEditorUser(page, playwright);

		// Login as Editor
		await loginViaUi(page, DEFAULT_EDITOR_CREDENTIALS);

		// Try navigating to /dashboard/tools/scheduled-jobs
		await page.goto("/dashboard/tools/scheduled-jobs", {
			waitUntil: "commit",
		});

		// Since scheduled-jobs is admin-only, AdminOnlyRoute redirects Editor to /dashboard/links
		await page.waitForURL("**/dashboard/links", { timeout: 15000 });
		await expect(
			page.getByRole("heading", {
				name: /^links$/i,
				level: 1,
			})
		).toBeVisible();

		// Direct API access: GET /api/v1/system/cron should return 403
		const getCronRes = await page.request.get("/api/v1/system/cron");
		expect(getCronRes.status()).toBe(403);

		// Direct API access: POST /api/v1/system/cron/run should return 403
		const postCronRes = await page.request.post("/api/v1/system/cron/run");
		expect(postCronRes.status()).toBe(403);
	});

	test("manage schedules and retention drawer opens and displays configuration options", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/tools/scheduled-jobs", {
			waitUntil: "commit",
		});
		await expect(
			page.getByRole("heading", { name: /^scheduled jobs$/i, level: 1 })
		).toBeVisible({ timeout: 25000 });

		// Click Manage Schedules button in hero actions
		const manageBtn = page.getByRole("button", {
			name: /manage schedules/i,
		});
		await expect(manageBtn).toBeVisible();
		await manageBtn.click();

		// Drawer heading
		const drawerHeading = page.getByRole("heading", {
			name: /manage schedules & retention/i,
		});
		await expect(drawerHeading).toBeVisible({ timeout: 10000 });

		// Sections
		await expect(
			page.getByRole("heading", {
				name: /execution history retention/i,
			})
		).toBeVisible();
		await expect(
			page.getByRole("heading", {
				name: /registered background tasks/i,
			})
		).toBeVisible();

		// Save retention button
		await expect(
			page.getByRole("button", { name: /save retention/i })
		).toBeVisible();

		// Close drawer
		const closeBtn = page.getByRole("button", { name: /^close$/i });
		await closeBtn.click();
		await expect(drawerHeading).not.toBeVisible();
	});
});
