import { expect, test } from "@playwright/test";

test("sign-in and verification expose one visible heading and keep password controls accessible", async ({
	page,
}) => {
	await page.route("**/api/v1/users/me", (route) =>
		route.fulfill({ status: 401, json: {} })
	);
	await page.route("**/api/v1/auth/login", (route) =>
		route.fulfill({ json: { data: { requiresTwoFactor: true } } })
	);
	await page.setViewportSize({ width: 320, height: 780 });
	await page.emulateMedia({ colorScheme: "dark", reducedMotion: "reduce" });
	await page.goto("/login");

	const heading = page.getByRole("heading", { level: 1 });
	await expect(heading).toHaveText("Sign in to your account");
	await expect(page.locator("h1")).toHaveCount(1);
	const password = page.getByLabel(/^Password/i);
	await expect(password).toHaveAttribute("autocomplete", "current-password");
	const visibility = page.getByRole("button", { name: "Show password" });
	await visibility.press("Enter");
	await expect(password).toHaveAttribute("type", "text");
	await expect(
		page.getByRole("button", { name: "Hide password" })
	).toBeFocused();
	await page.getByRole("button", { name: "Hide password" }).press("Enter");
	await page.getByLabel("Email or username").fill("reviewer");
	await password.fill("review-password");
	await page.getByRole("button", { name: "Sign in", exact: true }).click();

	await expect(heading).toHaveText("Verify your identity");
	await expect(password).toBeHidden();
	await expect(
		page.locator(".login-page-code-panel input").first()
	).toBeFocused();
	await page.getByRole("button", { name: /Use a backup code/ }).click();
	await expect(page.getByLabel("Backup code")).toBeVisible();
	expect(
		await page.evaluate(() => document.documentElement.scrollWidth)
	).toBe(320);
	await page.getByRole("button", { name: "Back to sign-in" }).click();
	await expect(heading).toHaveText("Sign in to your account");
});

test("password recovery prevents duplicate submissions until the request completes", async ({
	page,
}) => {
	let finishRequest!: () => void;
	const responseReady = new Promise<void>((resolve) => {
		finishRequest = resolve;
	});
	let submits = 0;
	await page.route("**/api/v1/auth/forgot-password", async (route) => {
		submits++;
		await responseReady;
		await route.fulfill({ json: { data: {} } });
	});
	try {
		await page.goto("/forgot-password");
		await expect(page.getByRole("heading", { level: 1 })).toHaveText(
			"Forgot your password?"
		);
		await page.getByLabel("Email or username").fill("review@example.test");
		await page.locator("form").evaluate((form) => {
			(form as HTMLFormElement).requestSubmit();
			(form as HTMLFormElement).requestSubmit();
		});
		const submit = page.getByRole("button", { name: "Send reset link" });
		await expect(submit).toBeDisabled();
		await expect.poll(() => submits).toBe(1);
	} finally {
		finishRequest();
	}
	await expect(page.getByRole("status")).toContainText("Check your inbox");
	expect(submits).toBe(1);
});

test("reset password reports mismatches once and accepts a corrected submission", async ({
	page,
}) => {
	let submitted = 0;
	await page.route("**/api/v1/auth/reset-password/review-token", (route) => {
		if (route.request().method() === "POST") submitted++;
		return route.fulfill({ json: { data: { valid: true } } });
	});
	await page.goto("/reset-password/review-token");
	await expect(page.getByRole("heading", { level: 1 })).toHaveText(
		"Reset your password"
	);
	await page.getByLabel(/^New password/i).fill("review-password-one");
	const confirmation = page.getByLabel(/^Confirm new password/i);
	await confirmation.fill("review-password-two");
	await page
		.getByRole("button", { name: "Reset password", exact: true })
		.click();
	await expect(page.getByRole("alert")).toHaveText("Passwords do not match.");
	expect(submitted).toBe(0);
	await confirmation.fill("review-password-one");
	await page
		.getByRole("button", { name: "Reset password", exact: true })
		.click();
	await expect(page.getByRole("status")).toContainText("Password updated");
	expect(submitted).toBe(1);
});

test("auth pages expose a working theme toggle across login, recovery, and reset routes", async ({
	page,
}) => {
	await page.route("**/api/v1/users/me", (route) =>
		route.fulfill({ status: 401, json: {} })
	);
	await page.route("**/api/v1/auth/reset-password/review-token", (route) =>
		route.fulfill({ json: { data: { valid: true } } })
	);

	for (const routePath of [
		"/login",
		"/forgot-password",
		"/reset-password/review-token",
	]) {
		await page.goto(routePath);
		const toggle = page.getByRole("button", { name: "Toggle theme" });
		await expect(toggle).toBeVisible();
		await expect(page.locator("html")).not.toHaveClass(/\bdark\b/);

		await toggle.click();
		await expect(page.locator("html")).toHaveClass(/\bdark\b/);
		expect(
			await page.evaluate(() => window.localStorage.getItem("theme"))
		).toBe("dark");

		await page.reload();
		await expect(page.locator("html")).toHaveClass(/\bdark\b/);

		await toggle.click();
		await expect(page.locator("html")).not.toHaveClass(/\bdark\b/);
		expect(
			await page.evaluate(() => window.localStorage.getItem("theme"))
		).toBe("light");
	}
});
