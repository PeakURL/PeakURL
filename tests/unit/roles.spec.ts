import { test, expect } from "@playwright/test";
import {
	isValidRoleId,
	MAX_ROLE_ID_LENGTH,
	resolveUserRoleMeta,
	ROLE_ID_PATTERN,
	suggestRoleId,
} from "../../client/pages/dashboard/users/lib";

test.describe("Role Identifier Validation & Helpers", () => {
	test.describe("ROLE_ID_PATTERN & isValidRoleId", () => {
		test("accepts canonical lowercase snake_case identifiers", () => {
			expect(isValidRoleId("support")).toBe(true);
			expect(isValidRoleId("content_manager")).toBe(true);
			expect(isValidRoleId("analyst2")).toBe(true);
			expect(isValidRoleId("tier_2_support")).toBe(true);
			expect(isValidRoleId("a")).toBe(true);
			expect(isValidRoleId("a_1")).toBe(true);
		});

		test("accepts exactly 32-character valid identifier", () => {
			const id32 = "a" + "_b".repeat(15) + "1"; // 1 + 30 + 1 = 32 chars
			expect(id32.length).toBe(32);
			expect(isValidRoleId(id32)).toBe(true);
		});

		test("rejects identifiers exceeding 32 characters", () => {
			const id33 = "a".repeat(33);
			expect(id33.length).toBe(33);
			expect(isValidRoleId(id33)).toBe(false);
		});

		test("rejects numeric-only and digit-starting identifiers", () => {
			expect(isValidRoleId("0")).toBe(false);
			expect(isValidRoleId("01")).toBe(false);
			expect(isValidRoleId("123")).toBe(false);
			expect(isValidRoleId("2fa_reviewer")).toBe(false);
		});

		test("rejects hyphens, uppercase, spaces, and punctuation", () => {
			expect(isValidRoleId("content-manager")).toBe(false);
			expect(isValidRoleId("Content_Manager")).toBe(false);
			expect(isValidRoleId("content manager")).toBe(false);
			expect(isValidRoleId("cont.ent")).toBe(false);
			expect(isValidRoleId("ana!lyst")).toBe(false);
		});

		test("rejects consecutive, leading, and trailing underscores", () => {
			expect(isValidRoleId("content__manager")).toBe(false);
			expect(isValidRoleId("_editor")).toBe(false);
			expect(isValidRoleId("editor_")).toBe(false);
			expect(isValidRoleId("_")).toBe(false);
		});

		test("rejects empty string and whitespace", () => {
			expect(isValidRoleId("")).toBe(false);
			expect(isValidRoleId("   ")).toBe(false);
			expect(isValidRoleId(" content_manager")).toBe(false);
			expect(isValidRoleId("content_manager ")).toBe(false);
		});

		test("rejects newlines and carriage returns", () => {
			expect(isValidRoleId("admin\n")).toBe(false);
			expect(isValidRoleId("editor\n")).toBe(false);
			expect(isValidRoleId("content_manager\n")).toBe(false);
			expect(isValidRoleId("content_manager\r")).toBe(false);
			expect(isValidRoleId("content_manager\r\n")).toBe(false);
			expect(isValidRoleId("content\nmanager")).toBe(false);
		});
	});

	test.describe("suggestRoleId", () => {
		test("suggests lowercase snake_case from typical display names", () => {
			expect(suggestRoleId("Senior Content Manager")).toBe(
				"senior_content_manager"
			);
			expect(suggestRoleId("Tier 2 Support")).toBe("tier_2_support");
			expect(suggestRoleId("QA Analyst")).toBe("qa_analyst");
			expect(suggestRoleId("Finance & Operations — UK")).toBe(
				"finance_operations_uk"
			);
		});

		test("transliterates Latin diacritics", () => {
			expect(suggestRoleId("Crème Brûlée Chef")).toBe(
				"creme_brulee_chef"
			);
		});

		test("truncates at 32 characters without trailing underscores", () => {
			const longName =
				"A Very Long Role Name That Exceeds Thirty Two Characters";
			const suggested = suggestRoleId(longName);
			expect(suggested.length).toBeLessThanOrEqual(MAX_ROLE_ID_LENGTH);
			expect(suggested.endsWith("_")).toBe(false);
			expect(isValidRoleId(suggested)).toBe(true);
		});

		test("returns empty string when name starts with digits or cannot yield valid identifier", () => {
			expect(suggestRoleId("2FA Security Reviewer")).toBe("");
			expect(suggestRoleId("12345")).toBe("");
			expect(suggestRoleId("---")).toBe("");
			expect(suggestRoleId("")).toBe("");
		});

		test("returns empty string for non-Latin names requiring manual entry", () => {
			expect(suggestRoleId("مدير المحتوى")).toBe("");
			expect(suggestRoleId("コンテンツマネージャー")).toBe("");
		});
	});

	test.describe("resolveUserRoleMeta", () => {
		test("resolves built-in admin role metadata", () => {
			const meta = resolveUserRoleMeta("admin");
			expect(meta.label).toBe("Admin");
			expect(meta.badge).toBe("users-page-role-badge-admin");
		});

		test("resolves built-in editor role metadata", () => {
			const meta = resolveUserRoleMeta("editor");
			expect(meta.label).toBe("Editor");
			expect(meta.badge).toBe("users-page-role-badge-editor");
		});

		test("preserves human-readable display name for custom roles", () => {
			const meta = resolveUserRoleMeta(
				"content_manager",
				"Senior Content & Publishing Manager"
			);
			expect(meta.label).toBe("Senior Content & Publishing Manager");
			expect(meta.badge).toBe("users-page-role-badge-custom");
		});

		test("preserves non-Latin and punctuation display names for custom roles", () => {
			const meta = resolveUserRoleMeta(
				"qa_lead",
				"QA / Support (EMEA & APAC)"
			);
			expect(meta.label).toBe("QA / Support (EMEA & APAC)");
			expect(meta.badge).toBe("users-page-role-badge-custom");
		});

		test("falls back to title-cased slug when custom role has no name", () => {
			const meta = resolveUserRoleMeta("tier_2_support", null);
			expect(meta.label).toBe("Tier 2 Support");
			expect(meta.badge).toBe("users-page-role-badge-custom");
		});
	});
});
