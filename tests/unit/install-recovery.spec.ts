import { test, expect } from "@playwright/test";
import { getInstallRecovery } from "../../client/shared/install";

test.describe("Install Recovery Utilities", () => {
	test("extracts needs_setup recovery with setupConfigUrl", () => {
		const error = {
			status: 503,
			data: {
				data: {
					recoveryState: "needs_setup",
					setupConfigUrl: "/setup-config.php",
				},
			},
		};

		const recovery = getInstallRecovery(error);
		expect(recovery).toEqual({
			state: "needs_setup",
			url: "/setup-config.php",
		});
	});

	test("extracts needs_install recovery with installUrl", () => {
		const error = {
			status: 503,
			data: {
				data: {
					recoveryState: "needs_install",
					installUrl: "/install.php",
				},
			},
		};

		const recovery = getInstallRecovery(error);
		expect(recovery).toEqual({
			state: "needs_install",
			url: "/install.php",
		});
	});

	test("returns null for non-recovery errors", () => {
		expect(getInstallRecovery(null)).toBe(null);
		expect(
			getInstallRecovery({
				status: 401,
				data: { message: "Unauthorized" },
			})
		).toBe(null);
		expect(
			getInstallRecovery({
				status: 500,
				data: { data: { recoveryState: "unknown_state" } },
			})
		).toBe(null);
	});
});
