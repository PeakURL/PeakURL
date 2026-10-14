import { test, expect } from "@playwright/test";
import {
	computeFilterContextKey,
	pruneStaleSelectedIds,
	getBulkSelectionState,
	createBulkDeleteSnapshot,
	validateBulkDeleteSnapshot,
} from "../../client/pages/dashboard/links/components/LinksTable/lib";
import type { LinkRecord } from "../../client/pages/dashboard/links/types";

function createMockLink(
	id: string,
	userId: string,
	status: "active" | "trashed" | "expired" = "active"
): LinkRecord {
	return {
		id,
		userId,
		shortCode: `sc_${id}`,
		alias: `alias_${id}`,
		shortUrl: `https://peakurl.dev/alias_${id}`,
		title: `Title ${id}`,
		destinationUrl: `https://example.com/${id}`,
		status,
		hasPassword: false,
		expiresAt: null,
		utmSource: null,
		utmMedium: null,
		utmCampaign: null,
		utmTerm: null,
		utmContent: null,
		createdAt: "2026-10-14T10:00:00Z",
		updatedAt: "2026-10-14T10:00:00Z",
		clicks: 10,
		uniqueClicks: 5,
		socialPreview: {
			title: "",
			description: "",
			imageUrl: "",
			externalImageUrl: null,
		},
	};
}

test.describe("LinksTable Selection & Destructive Action Gating", () => {
	const linkOwned1 = createMockLink("link_owned_1", "user_1");
	const linkOwned2 = createMockLink("link_owned_2", "user_1");
	const linkOther1 = createMockLink("link_other_1", "user_2");
	const linkOther2 = createMockLink("link_other_2", "user_3");

	test.describe("1. Context changes reset selection", () => {
		const baseParams = {
			currentPage: 1,
			statusFilter: "all",
			isTrashTab: false,
			searchQuery: "",
			sortBy: "createdAt",
			sortOrder: "desc",
			clickRange: "all",
			customClickRange: { from: "2026-10-01", to: "2026-10-14" },
			userId: "user_1",
		};

		test("page change produces different context key", () => {
			const key1 = computeFilterContextKey(baseParams);
			const key2 = computeFilterContextKey({
				...baseParams,
				currentPage: 2,
			});
			expect(key1).not.toBe(key2);
		});

		test("search query change produces different context key", () => {
			const key1 = computeFilterContextKey(baseParams);
			const key2 = computeFilterContextKey({
				...baseParams,
				searchQuery: "promo",
			});
			expect(key1).not.toBe(key2);
		});

		test("status filter change produces different context key", () => {
			const key1 = computeFilterContextKey(baseParams);
			const key2 = computeFilterContextKey({
				...baseParams,
				statusFilter: "expired",
			});
			expect(key1).not.toBe(key2);
		});

		test("trash tab toggle produces different context key", () => {
			const key1 = computeFilterContextKey(baseParams);
			const key2 = computeFilterContextKey({
				...baseParams,
				isTrashTab: true,
			});
			expect(key1).not.toBe(key2);
		});

		test("sort change produces different context key", () => {
			const key1 = computeFilterContextKey(baseParams);
			const key2 = computeFilterContextKey({
				...baseParams,
				sortBy: "title",
			});
			expect(key1).not.toBe(key2);
		});

		test("sort order change produces different context key", () => {
			const key1 = computeFilterContextKey(baseParams);
			const key2 = computeFilterContextKey({
				...baseParams,
				sortOrder: "asc",
			});
			expect(key1).not.toBe(key2);
		});

		test("date range change produces different context key", () => {
			const key1 = computeFilterContextKey(baseParams);
			const key2 = computeFilterContextKey({
				...baseParams,
				clickRange: "7d",
			});
			expect(key1).not.toBe(key2);
		});

		test("user account ID change produces different context key", () => {
			const key1 = computeFilterContextKey(baseParams);
			const key2 = computeFilterContextKey({
				...baseParams,
				userId: "user_switch",
			});
			expect(key1).not.toBe(key2);
		});
	});

	test.describe("2. Selection pruning & stale ID handling", () => {
		test("prunes IDs that are no longer in visible links after background refetch", () => {
			const selectedIds = [
				"link_owned_1",
				"link_owned_2",
				"link_deleted",
			];
			const visibleLinks = [linkOwned1, linkOwned2]; // link_deleted is gone
			const pruned = pruneStaleSelectedIds(selectedIds, visibleLinks);

			expect(pruned).toEqual(["link_owned_1", "link_owned_2"]);
			expect(pruned).not.toContain("link_deleted");
		});

		test("returns empty array if all selected links disappeared", () => {
			const selectedIds = ["stale_1", "stale_2"];
			const visibleLinks = [linkOwned1];
			const pruned = pruneStaleSelectedIds(selectedIds, visibleLinks);
			expect(pruned).toEqual([]);
		});
	});

	test.describe("3. Partial/absent selection disallows destructive mutations", () => {
		test("hasCompleteSelection is FALSE when selectedIds contains an absent ID", () => {
			const links = [linkOwned1];
			// selectedIds has linkOwned1 AND an absent ID
			const state = getBulkSelectionState({
				selectedIds: ["link_owned_1", "absent_id"],
				links,
				user: { id: "user_1" },
				effectiveIsAdmin: true,
				canTrashLinks: true,
				canDeleteLinks: true,
			});

			expect(state.hasCompleteSelection).toBe(false);
			expect(state.canTrashAllSelected).toBe(false);
			expect(state.canPermanentlyDeleteAllSelected).toBe(false);
			expect(state.canBulkDelete).toBe(false);
		});

		test("empty selection disallows all bulk actions", () => {
			const state = getBulkSelectionState({
				selectedIds: [],
				links: [linkOwned1, linkOwned2],
				user: { id: "user_1" },
				effectiveIsAdmin: true,
				canTrashLinks: true,
				canDeleteLinks: true,
			});

			expect(state.hasCompleteSelection).toBe(false);
			expect(state.canTrashAllSelected).toBe(false);
			expect(state.canPermanentlyDeleteAllSelected).toBe(false);
			expect(state.canBulkDelete).toBe(false);
		});
	});

	test.describe("4. Role permissions & ownership for bulk operations", () => {
		test("Non-admin with trash_links=true may bulk-trash owned active links", () => {
			const state = getBulkSelectionState({
				selectedIds: ["link_owned_1", "link_owned_2"],
				links: [linkOwned1, linkOwned2],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: false,
			});

			expect(state.hasCompleteSelection).toBe(true);
			expect(state.canTrashAllSelected).toBe(true);
			expect(state.canPermanentlyDeleteAllSelected).toBe(false);
			expect(state.canBulkDelete).toBe(true);
		});

		test("Non-admin cannot bulk-trash if any selected link belongs to another user", () => {
			const state = getBulkSelectionState({
				selectedIds: ["link_owned_1", "link_other_1"],
				links: [linkOwned1, linkOther1],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: true,
				canDeleteAnyLink: true, // even with delete_any_link, cannot trash other's link!
			});

			expect(state.hasCompleteSelection).toBe(true);
			expect(state.canTrashAllSelected).toBe(false);
		});

		test("Non-admin with delete_links=true and trash_links=false cannot bulk-trash owned links but can permanently delete them", () => {
			const state = getBulkSelectionState({
				selectedIds: ["link_owned_1"],
				links: [linkOwned1],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: false,
				canDeleteLinks: true,
			});

			expect(state.canTrashAllSelected).toBe(false);
			expect(state.canPermanentlyDeleteAllSelected).toBe(true);
			expect(state.canBulkDelete).toBe(true); // can bulk delete directly via permanent delete
		});

		test("Non-admin with delete_any_link=true may permanently delete other user's links but cannot trash them", () => {
			const state = getBulkSelectionState({
				selectedIds: ["link_other_1", "link_other_2"],
				links: [linkOther1, linkOther2],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: true,
				canDeleteAnyLink: true,
			});

			// Cannot trash others' links:
			expect(state.canTrashAllSelected).toBe(false);
			// Can permanently delete others' links:
			expect(state.canPermanentlyDeleteAllSelected).toBe(true);
			expect(state.canBulkDelete).toBe(true);
		});

		test("Admin has full bulk trash and bulk delete access regardless of ownership", () => {
			const state = getBulkSelectionState({
				selectedIds: ["link_owned_1", "link_other_1"],
				links: [linkOwned1, linkOther1],
				isTrashTab: false,
				user: { id: "admin_user" },
				effectiveIsAdmin: true,
				canTrashLinks: true,
				canDeleteLinks: true,
			});

			expect(state.canTrashAllSelected).toBe(true);
			expect(state.canPermanentlyDeleteAllSelected).toBe(true);
			expect(state.canBulkDelete).toBe(true);
		});
	});

	test.describe("5. Trashed tab restore and permanent delete gating", () => {
		const trashedOwned1 = createMockLink("trashed_1", "user_1", "trashed");
		const trashedOther1 = createMockLink("trashed_2", "user_2", "trashed");

		test("Non-admin with edit_links=true can restore owned trashed links", () => {
			const state = getBulkSelectionState({
				selectedIds: ["trashed_1"],
				links: [trashedOwned1],
				isTrashTab: true,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canEditLinks: true,
			});

			expect(state.canRestoreAllSelected).toBe(true);
		});

		test("Non-admin cannot restore if selection contains another user's trashed link", () => {
			const state = getBulkSelectionState({
				selectedIds: ["trashed_1", "trashed_2"],
				links: [trashedOwned1, trashedOther1],
				isTrashTab: true,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canEditLinks: true,
			});

			expect(state.canRestoreAllSelected).toBe(false);
		});

		test("Non-admin without edit_links=true cannot restore even owned trashed links", () => {
			const state = getBulkSelectionState({
				selectedIds: ["trashed_1"],
				links: [trashedOwned1],
				isTrashTab: true,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canEditLinks: false,
			});

			expect(state.canRestoreAllSelected).toBe(false);
		});

		test("Admin can restore mixed ownership trashed links", () => {
			const state = getBulkSelectionState({
				selectedIds: ["trashed_1", "trashed_2"],
				links: [trashedOwned1, trashedOther1],
				isTrashTab: true,
				user: { id: "admin_user" },
				effectiveIsAdmin: true,
				canEditLinks: true,
			});

			expect(state.canRestoreAllSelected).toBe(true);
		});

		test("In trash tab, canBulkDelete strictly requires permanent delete permission", () => {
			const stateWithoutDelete = getBulkSelectionState({
				selectedIds: ["trashed_1"],
				links: [trashedOwned1],
				isTrashTab: true,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: false,
			});
			// Cannot delete permanently, and in trash tab you cannot "trash"
			expect(stateWithoutDelete.canBulkDelete).toBe(false);

			const stateWithDelete = getBulkSelectionState({
				selectedIds: ["trashed_1"],
				links: [trashedOwned1],
				isTrashTab: true,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: false,
				canDeleteLinks: true,
			});
			expect(stateWithDelete.canBulkDelete).toBe(true);
		});
	});

	test.describe("6. Bulk-delete confirmation snapshot invariants and validation", () => {
		const linkA = createMockLink("link_A", "user_1");
		const linkB = createMockLink("link_B", "user_1");
		const linksAB = [linkA, linkB];

		test("createBulkDeleteSnapshot captures exact snapshot and returns null on empty/invalid", () => {
			expect(
				createBulkDeleteSnapshot({
					id: 1,
					selectedIds: [],
					links: linksAB,
					isTrashTab: false,
					canTrash: true,
					canPermanentlyDelete: true,
				})
			).toBeNull();

			expect(
				createBulkDeleteSnapshot({
					id: 1,
					selectedIds: ["non_existent"],
					links: linksAB,
					isTrashTab: false,
					canTrash: true,
					canPermanentlyDelete: true,
				})
			).toBeNull();

			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds: ["link_A", "link_B"],
				links: linksAB,
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: true,
			});
			expect(snapshot).not.toBeNull();
			expect(snapshot?.selectedIds).toEqual(["link_A", "link_B"]);
			expect(snapshot?.targetLinks).toEqual([
				{ id: "link_A", userId: "user_1", status: "active" },
				{ id: "link_B", userId: "user_1", status: "active" },
			]);
			expect(snapshot?.canTrash).toBe(true);
			expect(snapshot?.isPermanentOnly).toBe(false);
		});

		test("Exact A+B unchanged through harmless refetch remains valid", () => {
			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds: ["link_A", "link_B"],
				links: linksAB,
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: true,
			});

			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: [{ ...linkA }, { ...linkB }],
				currentSelectedIds: ["link_A", "link_B"],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: true,
			});
			expect(validation.isValid).toBe(true);
		});

		test("No subset substitution: disappearance of B invalidates and dismisses confirmation (A is not substituted)", () => {
			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds: ["link_A", "link_B"],
				links: linksAB,
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: true,
			});

			// Refresh leaves only A
			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: [linkA],
				currentSelectedIds: ["link_A", "link_B"],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: true,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("item_missing");
		});

		test("Selection change while modal is open invalidates confirmation", () => {
			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds: ["link_A", "link_B"],
				links: linksAB,
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: true,
			});

			// User deselected B in background
			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: linksAB,
				currentSelectedIds: ["link_A"],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: true,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("selection_changed");
		});

		test("Ownership change of target record invalidates confirmation", () => {
			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds: ["link_A", "link_B"],
				links: linksAB,
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: true,
			});

			const modifiedLinkB = { ...linkB, userId: "user_other" };
			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: [linkA, modifiedLinkB],
				currentSelectedIds: ["link_A", "link_B"],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: true,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("link_metadata_changed");
		});

		test("Status change of target record invalidates confirmation", () => {
			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds: ["link_A", "link_B"],
				links: linksAB,
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: true,
			});

			const trashedLinkB = { ...linkB, status: "trashed" as const };
			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: [linkA, trashedLinkB],
				currentSelectedIds: ["link_A", "link_B"],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: true,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("link_metadata_changed");
		});

		test("Tab change invalidates confirmation", () => {
			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds: ["link_A", "link_B"],
				links: linksAB,
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: true,
			});

			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: linksAB,
				currentSelectedIds: ["link_A", "link_B"],
				isTrashTab: true,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: true,
				canDeleteLinks: true,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("tab_changed");
		});

		test("Revocation of canTrashLinks in active tab invalidates and does NOT silently switch to permanent delete", () => {
			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds: ["link_A", "link_B"],
				links: linksAB,
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: true,
			});

			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: linksAB,
				currentSelectedIds: ["link_A", "link_B"],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canTrashLinks: false, // revoked!
				canDeleteLinks: true,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("trash_permission_revoked");
		});

		test("delete_any_link strictly requires parent delete_links capability", () => {
			const otherUserLink = createMockLink("link_other", "user_2");
			const state = getBulkSelectionState({
				selectedIds: ["link_other"],
				links: [otherUserLink],
				isTrashTab: false,
				user: { id: "user_1" },
				effectiveIsAdmin: false,
				canDeleteLinks: false, // parent capability false!
				canDeleteAnyLink: true, // child capability true
			});
			expect(state.canPermanentlyDeleteAllSelected).toBe(false);
		});
	});
});
