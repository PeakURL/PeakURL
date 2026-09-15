import crypto from "node:crypto";
import { test, expect } from "../fixtures/auth.fixture";

function base32Decode(base32: string): Uint8Array {
	const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
	const cleaned = base32.toUpperCase().replace(/[^A-Z2-7]/g, "");
	let bits = 0;
	let value = 0;
	const bytes: number[] = [];
	for (let i = 0; i < cleaned.length; i++) {
		const idx = alphabet.indexOf(cleaned[i]);
		if (idx === -1) continue;
		value = (value << 5) | idx;
		bits += 5;
		if (bits >= 8) {
			bytes.push((value >>> (bits - 8)) & 0xff);
			bits -= 8;
		}
	}
	return new Uint8Array(bytes);
}

function calculateTotp(secret: string, offset = 0): string {
	const key = base32Decode(secret);
	const timeSlice = Math.floor(Date.now() / 1000 / 30) + offset;
	const timeBuffer = Buffer.alloc(8);
	timeBuffer.writeBigInt64BE(BigInt(timeSlice));
	const hmac = crypto
		.createHmac("sha1", Buffer.from(key))
		.update(timeBuffer)
		.digest();
	const offsetIdx = hmac[hmac.length - 1] & 0x0f;
	const code =
		((hmac[offsetIdx] & 0x7f) << 24) |
		((hmac[offsetIdx + 1] & 0xff) << 16) |
		((hmac[offsetIdx + 2] & 0xff) << 8) |
		(hmac[offsetIdx + 3] & 0xff);
	return String(code % 1000000).padStart(6, "0");
}

test.describe("Two-Factor Authentication Lifecycle Browser Journeys", () => {
	test("complete 2FA lifecycle: setup with QR, TOTP login, backup-code login, replay rejection, and disable", async ({
		authenticatedPage: page,
		adminCredentials,
	}) => {
		// 1. Navigate to Settings -> Security
		await page.goto("/dashboard/settings/security", {
			waitUntil: "commit",
		});
		await expect(
			page.getByRole("heading", { name: /two-factor authentication/i })
		).toBeVisible({ timeout: 25000 });

		// Initial state is disabled
		await expect(
			page.locator(".settings-security-two-factor-status")
		).toContainText(/2FA is disabled/i);

		// 2. Start 2FA Setup
		const startSetupBtn = page.getByRole("button", {
			name: /set up (two-factor|2fa)/i,
		});
		await expect(startSetupBtn).toBeVisible();
		await startSetupBtn.click();

		// Setup panel renders QR code and secret
		const setupPanel = page.locator(".settings-security-two-factor-setup");
		await expect(setupPanel).toBeVisible({ timeout: 15000 });
		await expect(
			page.locator(".settings-security-two-factor-qr")
		).toBeVisible();

		const secretElement = page
			.locator(".settings-security-two-factor-secret-value")
			.first();
		await expect(secretElement).toBeVisible();
		const secretText = (await secretElement.innerText()).trim();
		expect(secretText.length).toBeGreaterThanOrEqual(16);

		// 3. Enter valid TOTP code to verify & enable
		const totpCode = calculateTotp(secretText);
		const codeInput = page.getByPlaceholder("123456");
		await expect(codeInput).toBeVisible();
		await codeInput.fill(totpCode);

		const verifyBtn = page.getByRole("button", {
			name: /verify & enable/i,
		});
		await verifyBtn.click();

		// 4. Backup codes appear and 2FA is now enabled
		const backupCodesGrid = page.locator(".settings-security-backup-codes");
		await expect(backupCodesGrid).toBeVisible({ timeout: 15000 });

		const backupCodeItems = page.locator(".settings-security-backup-code");
		await expect(backupCodeItems).toHaveCount(8);
		const backupCodes = await backupCodeItems.allInnerTexts();
		expect(backupCodes.length).toBe(8);
		const firstBackupCode = backupCodes[0].trim();
		expect(firstBackupCode).toMatch(/^[A-F0-9]{4}-[A-F0-9]{4}$/);

		await expect(
			page.locator(".settings-security-two-factor-status")
		).toContainText(/2FA is enabled/i);

		// 5. Logout
		const userTrigger = page.locator(".dashboard-header-user-trigger");
		await userTrigger.click();
		const logoutButton = page.getByRole("menuitem", {
			name: /logout|sign out/i,
		});
		await logoutButton.click();
		await page.waitForURL("**/login", { timeout: 15000 });

		// 6. Login with password -> triggers 2FA challenge
		await page
			.getByLabel(/email or username|username or email/i)
			.fill(adminCredentials.identifier);
		await page.getByLabel(/^password/i).fill(adminCredentials.password);
		await page.getByRole("button", { name: /sign in/i }).click();

		// 2FA challenge panel is visible
		const twoFactorPanel = page.locator(".login-page-two-factor");
		await expect(twoFactorPanel).toBeVisible({ timeout: 15000 });

		// 7. Complete login with valid TOTP
		const freshTotpCode = calculateTotp(secretText);
		const digitInputs = page.locator(".login-page-code-panel input");
		await expect(digitInputs.first()).toBeVisible({ timeout: 10000 });
		for (let i = 0; i < 6; i++) {
			await digitInputs.nth(i).fill(freshTotpCode[i]);
		}
		await page.getByRole("button", { name: /verify & continue/i }).click();

		await page.waitForURL("**/dashboard", { timeout: 15000 });
		await expect(
			page.getByRole("heading", { name: /dashboard/i, level: 1 })
		).toBeVisible();

		// 8. Logout again
		await page.locator(".dashboard-header-user-trigger").click();
		await page.getByRole("menuitem", { name: /logout|sign out/i }).click();
		await page.waitForURL("**/login", { timeout: 15000 });

		// 9. Login with password -> switch to backup code mode
		await page
			.getByLabel(/email or username|username or email/i)
			.fill(adminCredentials.identifier);
		await page.getByLabel(/^password/i).fill(adminCredentials.password);
		await page.getByRole("button", { name: /sign in/i }).click();
		await expect(twoFactorPanel).toBeVisible({ timeout: 15000 });

		const useBackupBtn = page.getByRole("button", {
			name: /lost your device|use a backup code/i,
		});
		await useBackupBtn.click();

		const backupInput = page.getByPlaceholder(/xxxx-xxxx-xxxx/i);
		await expect(backupInput).toBeVisible();
		await backupInput.fill(firstBackupCode);
		await page
			.getByRole("button", { name: /verify & continue|sign in/i })
			.click();

		await page.waitForURL("**/dashboard", { timeout: 15000 });
		await expect(
			page.getByRole("heading", { name: /dashboard/i, level: 1 })
		).toBeVisible();

		// 10. Logout again
		await page.locator(".dashboard-header-user-trigger").click();
		await page.getByRole("menuitem", { name: /logout|sign out/i }).click();
		await page.waitForURL("**/login", { timeout: 15000 });

		// 11. Attempt to reuse the SAME backup code -> must be rejected
		await page
			.getByLabel(/email or username|username or email/i)
			.fill(adminCredentials.identifier);
		await page.getByLabel(/^password/i).fill(adminCredentials.password);
		await page.getByRole("button", { name: /sign in/i }).click();
		await expect(twoFactorPanel).toBeVisible({ timeout: 15000 });

		await page
			.getByRole("button", {
				name: /lost your device|use a backup code/i,
			})
			.click();
		await page.getByPlaceholder(/xxxx-xxxx-xxxx/i).fill(firstBackupCode);
		await page
			.getByRole("button", { name: /verify & continue|sign in/i })
			.click();

		// Error feedback displayed for already-consumed code
		const errorAlert = page.locator(
			".login-page-alert, [role='alert'], .notification, .login-error-message"
		);
		await expect(errorAlert).toBeVisible({ timeout: 10000 });
		await expect(errorAlert).toContainText(/invalid/i);

		// 12. Recover with TOTP and login to disable 2FA
		await page
			.getByRole("button", { name: /use authenticator code instead/i })
			.click();
		const recoveryTotp = calculateTotp(secretText);
		const recoveryInputs = page.locator(".login-page-code-panel input");
		await expect(recoveryInputs.first()).toBeVisible({ timeout: 10000 });
		for (let i = 0; i < 6; i++) {
			await recoveryInputs.nth(i).fill(recoveryTotp[i]);
		}
		await page
			.getByRole("button", { name: /verify & continue|sign in/i })
			.click();

		await page.waitForURL("**/dashboard", { timeout: 15000 });

		// 13. Disable 2FA from Security settings
		await page.goto("/dashboard/settings/security", {
			waitUntil: "commit",
		});
		await expect(
			page.getByRole("heading", { name: /two-factor authentication/i })
		).toBeVisible({ timeout: 25000 });

		const disableBtn = page.getByRole("button", { name: /^disable$/i });
		await expect(disableBtn).toBeVisible();
		await disableBtn.click();

		// Password confirmation modal
		const confirmModal = page.locator(".confirm-dialog-panel");
		await expect(confirmModal).toBeVisible({ timeout: 15000 });
		const modalPasswordInput = confirmModal.getByLabel(/current password/i);
		await modalPasswordInput.fill(adminCredentials.password);
		const confirmActionBtn = confirmModal.getByRole("button", {
			name: /disable|confirm|continue/i,
		});
		await confirmActionBtn.click();

		// Verify 2FA status returns to disabled
		await expect(
			page.locator(".settings-security-two-factor-status")
		).toContainText(/2FA is disabled/i, { timeout: 15000 });
	});
});
