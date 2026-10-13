/**
 * Test Environment Isolation and Hostname Verification.
 *
 * Ensures tests never mutate configuration on non-test or non-disposable environments.
 */

export const APPROVED_TEST_HOSTNAMES = new Set([
	"localhost",
	"127.0.0.1",
	"::1",
	"[::1]",
	"peakurl.test",
	"peakurl.dev",
]);

/**
 * Validates that a given URL string has an exact approved test hostname.
 * Rejects substring matches, protocol-relative lookalikes, and URLs where 'localhost'
 * or other test hosts appear only in the pathname or query string.
 */
export function isSafeTestHostname(targetUrl: string): boolean {
	if (!targetUrl || typeof targetUrl !== "string") {
		return false;
	}

	try {
		const parsed = targetUrl.startsWith("/")
			? new URL(targetUrl, "http://localhost")
			: new URL(targetUrl);

		const hostname = parsed.hostname.toLowerCase();
		return APPROVED_TEST_HOSTNAMES.has(hostname);
	} catch {
		return false;
	}
}

/**
 * Validates that the runtime environment is explicitly designated as a disposable test instance.
 * Hostname matching alone does not establish safety for destructive or state-mutating operations.
 */
export function isExplicitlyAuthorizedTestInstance(
	env: Record<string, string | undefined> = process.env
): boolean {
	return (
		env.PEAKURL_TEST_DISPOSABLE_DB === "1" ||
		env.PEAKURL_TEST_DISPOSABLE_DB === "true" ||
		env.PEAKURL_TEST_ENV === "1" ||
		env.PEAKURL_TEST_ENV === "true" ||
		env.PEAKURL_ALLOW_ROLE_MUTATION === "1" ||
		env.PEAKURL_ALLOW_ROLE_MUTATION === "true"
	);
}

/**
 * Asserts that both the target URL hostname is approved AND the environment is explicitly
 * authorized for disposable mutations. Fails closed with a descriptive error.
 */
export function assertSafeDisposableTestInstance(
	targetUrl: string,
	env: Record<string, string | undefined> = process.env
): void {
	if (!isSafeTestHostname(targetUrl)) {
		let parsedHost = "invalid-url";
		try {
			parsedHost = new URL(targetUrl).hostname;
		} catch {
			// ignore
		}
		throw new Error(
			`Refusing to execute mutating test against non-approved host '${parsedHost}'. Approved hosts: ${Array.from(APPROVED_TEST_HOSTNAMES).join(", ")}`
		);
	}

	if (!isExplicitlyAuthorizedTestInstance(env)) {
		throw new Error(
			"Refusing to execute mutating role tests without explicit disposable instance authorization (PEAKURL_TEST_DISPOSABLE_DB=1 or PEAKURL_TEST_ENV=1)."
		);
	}
}

export interface RoleBaseline {
	roleId: string;
	isCustomized: boolean;
	capabilities: Record<string, boolean>;
	rawDefinition: Record<string, any>;
}

export interface RoleBaselineRequestClient {
	get(
		url: string,
		options?: any
	): Promise<{
		ok(): boolean;
		status(): number;
		text(): Promise<string>;
		json(): Promise<any>;
	}>;
	put(
		url: string,
		options?: any
	): Promise<{
		ok(): boolean;
		status(): number;
		text(): Promise<string>;
		json(): Promise<any>;
	}>;
	post(
		url: string,
		options?: any
	): Promise<{
		ok(): boolean;
		status(): number;
		text(): Promise<string>;
		json(): Promise<any>;
	}>;
}

/**
 * Captures the current configuration and customization baseline for a specified role.
 * Validates that the role and required capability structures exist.
 * Only collects applicable and non-protected capabilities as restorable entries.
 */
export async function captureRoleBaseline(
	request: RoleBaselineRequestClient,
	roleId = "editor"
): Promise<RoleBaseline> {
	const res = await request.get("/api/v1/roles");
	if (!res.ok()) {
		const text = await res.text();
		throw new Error(
			`Failed to capture role baseline for '${roleId}': HTTP ${res.status()} - ${text}`
		);
	}

	const body = await res.json();
	const roleDef = (body.data || []).find((r: any) => r.id === roleId);
	if (!roleDef) {
		throw new Error(
			`Role '${roleId}' was not found in GET /api/v1/roles response.`
		);
	}

	const capabilities: Record<string, boolean> = {};
	for (const cap of roleDef.capabilities || []) {
		if (cap.applicable === true && cap.protected !== true) {
			capabilities[cap.id] = Boolean(cap.enabled);
		}
	}

	return {
		roleId,
		isCustomized: Boolean(roleDef.is_customized),
		capabilities,
		rawDefinition: roleDef,
	};
}

/**
 * Restores a role to its captured baseline state and verifies the restored state via the server API.
 * If baseline was customized, issues PUT /api/v1/roles/:role with baseline capabilities.
 * If baseline was default, issues POST /api/v1/roles/:role/reset.
 * Re-fetches GET /api/v1/roles and asserts that is_customized and every applicable capability matches.
 * Throws a descriptive diagnostic error on any HTTP failure or state mismatch.
 */
export async function restoreAndVerifyRoleBaseline(
	request: RoleBaselineRequestClient,
	baseline: RoleBaseline
): Promise<void> {
	if (!baseline || !baseline.roleId) {
		throw new Error(
			"Invalid baseline provided to restoreAndVerifyRoleBaseline."
		);
	}

	const encodedRole = encodeURIComponent(baseline.roleId);

	if (baseline.isCustomized) {
		const restoreRes = await request.put(`/api/v1/roles/${encodedRole}`, {
			data: { capabilities: baseline.capabilities },
		});
		if (!restoreRes.ok()) {
			const text = await restoreRes.text();
			throw new Error(
				`Failed to restore customized role '${baseline.roleId}': HTTP ${restoreRes.status()} - ${text}`
			);
		}
	} else {
		const resetRes = await request.post(
			`/api/v1/roles/${encodedRole}/reset`
		);
		if (!resetRes.ok()) {
			const text = await resetRes.text();
			throw new Error(
				`Failed to reset role '${baseline.roleId}' to defaults: HTTP ${resetRes.status()} - ${text}`
			);
		}
	}

	// Re-fetch and verify restored state on server
	const verifyRes = await request.get("/api/v1/roles");
	if (!verifyRes.ok()) {
		const text = await verifyRes.text();
		throw new Error(
			`Failed to refetch roles to verify restoration of '${baseline.roleId}': HTTP ${verifyRes.status()} - ${text}`
		);
	}

	const verifyBody = await verifyRes.json();
	const verifiedRole = (verifyBody.data || []).find(
		(r: any) => r.id === baseline.roleId
	);
	if (!verifiedRole) {
		throw new Error(
			`Role '${baseline.roleId}' was not found during restoration verification.`
		);
	}

	const actualIsCustomized = Boolean(verifiedRole.is_customized);
	if (actualIsCustomized !== baseline.isCustomized) {
		throw new Error(
			`Role restoration verification failed for '${baseline.roleId}': expected is_customized=${baseline.isCustomized}, found ${actualIsCustomized}`
		);
	}

	const actualCapsMap: Record<string, boolean> = {};
	for (const cap of verifiedRole.capabilities || []) {
		if (cap.applicable === true && cap.protected !== true) {
			actualCapsMap[cap.id] = Boolean(cap.enabled);
		}
	}

	const mismatches: string[] = [];
	for (const [capId, expectedVal] of Object.entries(baseline.capabilities)) {
		const actualVal = actualCapsMap[capId];
		if (actualVal === undefined) {
			mismatches.push(`capability '${capId}' missing from verified role`);
		} else if (actualVal !== expectedVal) {
			mismatches.push(
				`capability '${capId}' expected enabled=${expectedVal} but found enabled=${actualVal}`
			);
		}
	}

	for (const capId of Object.keys(actualCapsMap)) {
		if (baseline.capabilities[capId] === undefined) {
			mismatches.push(
				`unexpected capability '${capId}' found on restored role`
			);
		}
	}

	if (mismatches.length > 0) {
		throw new Error(
			`Role restoration verification failed for '${baseline.roleId}':\n` +
				mismatches.join("\n")
		);
	}
}
