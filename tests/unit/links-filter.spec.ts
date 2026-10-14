import { test, expect } from "@playwright/test";
import {
	resolveEffectiveSortBy,
	getSafeSortByTarget,
	shouldSyncSortStorage,
	validateStoredSortBy,
	VALID_SORT_OPTIONS,
} from "../../client/pages/dashboard/links/hooks";
import type { LinksSortBy } from "../../client/pages/dashboard/links/types";

test.describe("Links Filter Sort & Storage RBAC Logic", () => {
	test.describe("VALID_SORT_OPTIONS & validateStoredSortBy", () => {
		test("validates and allows standard sort keys", () => {
			for (const opt of VALID_SORT_OPTIONS) {
				expect(validateStoredSortBy(opt)).toBe(opt);
			}
		});

		test("preserves stored analytics sort keys during initial storage reading without premature mutation", () => {
			// Storage reading must faithfully return stored value so role-resolution can decide
			expect(validateStoredSortBy("clicks")).toBe("clicks");
			expect(validateStoredSortBy("uniqueClicks")).toBe("uniqueClicks");
		});

		test("falls back to createdAt for unknown or null values", () => {
			expect(validateStoredSortBy(null)).toBe("createdAt");
			expect(validateStoredSortBy("")).toBe("createdAt");
			expect(validateStoredSortBy("invalid_sort")).toBe("createdAt");
			expect(validateStoredSortBy("unknown")).toBe("createdAt");
		});
	});

	test.describe("1. Admin with stored clicks/uniqueClicks", () => {
		test("Admin retains clicks sort after role resolution", () => {
			const effective = resolveEffectiveSortBy("clicks", true);
			expect(effective).toBe("clicks");
		});

		test("Admin retains uniqueClicks sort after role resolution", () => {
			const effective = resolveEffectiveSortBy("uniqueClicks", true);
			expect(effective).toBe("uniqueClicks");
		});

		test("Admin retains other standard sort keys", () => {
			expect(resolveEffectiveSortBy("createdAt", true)).toBe("createdAt");
			expect(resolveEffectiveSortBy("updatedAt", true)).toBe("updatedAt");
			expect(resolveEffectiveSortBy("title", true)).toBe("title");
			expect(resolveEffectiveSortBy("alias", true)).toBe("alias");
			expect(resolveEffectiveSortBy("health", true)).toBe("health");
		});

		test("Admin does not trigger storage synchronization / overwrite of click sorts", () => {
			expect(shouldSyncSortStorage("clicks", true, false)).toBe(false);
			expect(shouldSyncSortStorage("uniqueClicks", true, false)).toBe(
				false
			);
		});
	});

	test.describe("2. Non-admin with stale stored click preference", () => {
		test("Non-admin effective sort synchronously falls back to createdAt for clicks", () => {
			const effective = resolveEffectiveSortBy("clicks", false);
			expect(effective).toBe("createdAt");
		});

		test("Non-admin effective sort synchronously falls back to createdAt for uniqueClicks", () => {
			const effective = resolveEffectiveSortBy("uniqueClicks", false);
			expect(effective).toBe("createdAt");
		});

		test("Non-admin retains standard non-analytics sort keys untouched", () => {
			expect(resolveEffectiveSortBy("createdAt", false)).toBe(
				"createdAt"
			);
			expect(resolveEffectiveSortBy("updatedAt", false)).toBe(
				"updatedAt"
			);
			expect(resolveEffectiveSortBy("title", false)).toBe("title");
			expect(resolveEffectiveSortBy("alias", false)).toBe("alias");
			expect(resolveEffectiveSortBy("health", false)).toBe("health");
		});

		test("Non-admin triggers storage synchronization only after auth is resolved", () => {
			// Once auth is confirmed as non-admin, stale storage should be synced to fallback
			expect(shouldSyncSortStorage("clicks", false, false)).toBe(true);
			expect(shouldSyncSortStorage("uniqueClicks", false, false)).toBe(
				true
			);
			// Non-analytics sorts do not require storage reset
			expect(shouldSyncSortStorage("createdAt", false, false)).toBe(
				false
			);
			expect(shouldSyncSortStorage("title", false, false)).toBe(false);
		});
	});

	test.describe("3. Admin -> Non-admin capability/session transition", () => {
		test("Session transition from Admin to non-admin immediately switches effective sort to createdAt", () => {
			const storedSort: LinksSortBy = "clicks";

			// While admin:
			const adminSort = resolveEffectiveSortBy(storedSort, true);
			expect(adminSort).toBe("clicks");

			// Transition happens: isAdmin becomes false on next render
			const nonAdminSort = resolveEffectiveSortBy(storedSort, false);
			expect(nonAdminSort).toBe("createdAt");

			// Query arguments constructed with nonAdminSort will never issue sortBy=clicks
			expect(nonAdminSort).not.toBe("clicks");
			expect(nonAdminSort).not.toBe("uniqueClicks");
		});

		test("Transition handles uniqueClicks identically", () => {
			const storedSort: LinksSortBy = "uniqueClicks";
			expect(resolveEffectiveSortBy(storedSort, true)).toBe(
				"uniqueClicks"
			);
			expect(resolveEffectiveSortBy(storedSort, false)).toBe("createdAt");
		});
	});

	test.describe("4. setSortBy setter guards", () => {
		test("Non-admin calling setSortBy('clicks') is safely rejected to createdAt", () => {
			const target = getSafeSortByTarget("clicks", false);
			expect(target).toBe("createdAt");
		});

		test("Non-admin calling setSortBy('uniqueClicks') is safely rejected to createdAt", () => {
			const target = getSafeSortByTarget("uniqueClicks", false);
			expect(target).toBe("createdAt");
		});

		test("Non-admin calling setSortBy with valid non-analytics sort succeeds", () => {
			expect(getSafeSortByTarget("title", false)).toBe("title");
			expect(getSafeSortByTarget("alias", false)).toBe("alias");
			expect(getSafeSortByTarget("updatedAt", false)).toBe("updatedAt");
			expect(getSafeSortByTarget("health", false)).toBe("health");
		});

		test("Admin calling setSortBy('clicks') or ('uniqueClicks') succeeds", () => {
			expect(getSafeSortByTarget("clicks", true)).toBe("clicks");
			expect(getSafeSortByTarget("uniqueClicks", true)).toBe(
				"uniqueClicks"
			);
		});
	});

	test.describe("5. Unresolved authentication and render purity", () => {
		test("Storage is NEVER overwritten while auth is loading (preserves Admin preferences)", () => {
			// Even though isAdmin is false while loading, isAuthLoading=true blocks storage rewrite
			expect(shouldSyncSortStorage("clicks", false, true)).toBe(false);
			expect(shouldSyncSortStorage("uniqueClicks", false, true)).toBe(
				false
			);
		});

		test("Initial render while auth is loading derives permission-safe fallback without touching storage", () => {
			// During initial loading render, isAdmin=false:
			const effectiveWhileLoading = resolveEffectiveSortBy(
				"clicks",
				false
			);
			// Request will not leak clicks sort
			expect(effectiveWhileLoading).toBe("createdAt");
			// Storage write is deferred until auth resolves
			expect(shouldSyncSortStorage("clicks", false, true)).toBe(false);

			// If auth resolves to Admin:
			const effectiveWhenAdmin = resolveEffectiveSortBy("clicks", true);
			expect(effectiveWhenAdmin).toBe("clicks");
			expect(shouldSyncSortStorage("clicks", true, false)).toBe(false);

			// If auth resolves to Non-admin:
			const effectiveWhenEditor = resolveEffectiveSortBy("clicks", false);
			expect(effectiveWhenEditor).toBe("createdAt");
			expect(shouldSyncSortStorage("clicks", false, false)).toBe(true);
		});
	});
});
