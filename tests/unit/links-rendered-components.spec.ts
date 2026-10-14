import { test, expect } from "@playwright/test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";

import LinkRow from "../../client/pages/dashboard/links/components/LinksTable/parts/LinkRow";
import TableHeaderRow from "../../client/pages/dashboard/links/components/LinksTable/parts/TableHeaderRow";
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
	overrides: Partial<LinkRecord> = {}
): LinkRecord {
	return {
		id,
		userId,
		shortCode: `sc_${id}`,
		alias: `alias_${id}`,
		shortUrl: `https://peakurl.dev/alias_${id}`,
		title: `Test Link ${id}`,
		destinationUrl: `https://example.com/${id}`,
		status: "active",
		hasPassword: false,
		expiresAt: null,
		utmSource: null,
		utmMedium: null,
		utmCampaign: null,
		utmTerm: null,
		utmContent: null,
		createdAt: "2026-10-14T10:00:00Z",
		updatedAt: "2026-10-14T10:00:00Z",
		clicks: 42,
		uniqueClicks: 17,
		socialPreview: {
			title: "",
			description: "",
			imageUrl: "",
			externalImageUrl: null,
		},
		...overrides,
	};
}

const noop = () => {};
const formatNumber = (n: number) => String(n);

test.describe("Rendered Component Interaction & Permission Gating Tests", () => {
	const adminUser = { id: "user_admin" };
	const editorUser1 = { id: "user_editor_1" };
	const editorUser2 = { id: "user_editor_2" };

	const ownedLink = createMockLink("link_owned", editorUser1.id);
	const otherLink = createMockLink("link_other", editorUser2.id);
	const trashedOwnedLink = createMockLink("link_trashed", editorUser1.id, {
		status: "trashed",
	});

	test.describe("1. LinkRow rendered markup by role and capability", () => {
		test("Administrator sees all controls (Edit, Delete, Analytics) on any link", () => {
			const html = renderToStaticMarkup(
				React.createElement(LinkRow, {
					link: otherLink,
					selected: false,
					onSelectRow: noop,
					onCopy: noop,
					copiedId: null,
					onOpenStats: noop,
					onEdit: noop,
					onDelete: noop,
					onQRCode: noop,
					formatNumber,
					canDeleteLinks: true,
					canDeleteAnyLink: true,
					canTrashLinks: true,
					canEditLinks: true,
					canEditAnyLink: true,
					canViewAnalytics: true,
					isAdmin: true,
					currentUserId: adminUser.id,
				})
			);

			// Checkbox is rendered
			expect(html).toContain('type="checkbox"');
			// Edit button rendered
			expect(html).toContain("links-row-action-edit");
			// Delete button rendered
			expect(html).toContain("links-row-action-delete");
			// Analytics button rendered
			expect(html).toContain("links-row-action-stats");
			// Performance block with numeric metrics rendered
			expect(html).toContain("42");
			expect(html).toContain("17");
			expect(html).toContain("Clicks");
			expect(html).toContain("Visitors");
		});

		test("Non-admin Owner with view_analytics sees Edit, Delete, Analytics on own link", () => {
			const html = renderToStaticMarkup(
				React.createElement(LinkRow, {
					link: ownedLink,
					selected: false,
					onSelectRow: noop,
					onCopy: noop,
					copiedId: null,
					onOpenStats: noop,
					onEdit: noop,
					onDelete: noop,
					onQRCode: noop,
					formatNumber,
					canDeleteLinks: true,
					canTrashLinks: true,
					canEditLinks: true,
					canViewAnalytics: true,
					isAdmin: false,
					currentUserId: editorUser1.id,
				})
			);

			expect(html).toContain("links-row-action-edit");
			expect(html).toContain("links-row-action-delete");
			expect(html).toContain("links-row-action-stats");
			expect(html).toContain("42");
			expect(html).toContain("17");
		});

		test("Non-admin Owner with view_analytics=false has Analytics button and metrics suppressed", () => {
			const html = renderToStaticMarkup(
				React.createElement(LinkRow, {
					link: ownedLink,
					selected: false,
					onSelectRow: noop,
					onCopy: noop,
					copiedId: null,
					onOpenStats: noop,
					onEdit: noop,
					onDelete: noop,
					onQRCode: noop,
					formatNumber,
					canDeleteLinks: true,
					canTrashLinks: true,
					canEditLinks: true,
					canViewAnalytics: false,
					isAdmin: false,
					currentUserId: editorUser1.id,
				})
			);

			expect(html).toContain("links-row-action-edit");
			expect(html).toContain("links-row-action-delete");
			// Analytics button must NOT be rendered
			expect(html).not.toContain("links-row-action-stats");
			// Metrics block must show dash placeholder, not numbers
			expect(html).toContain("—");
		});

		test("Non-admin Editor on another account's link without cross permissions has all actions suppressed", () => {
			const html = renderToStaticMarkup(
				React.createElement(LinkRow, {
					link: otherLink,
					selected: false,
					onSelectRow: noop,
					onCopy: noop,
					copiedId: null,
					onOpenStats: noop,
					onEdit: noop,
					onDelete: noop,
					onQRCode: noop,
					formatNumber,
					canDeleteLinks: true,
					canDeleteAnyLink: false,
					canTrashLinks: true,
					canEditLinks: true,
					canEditAnyLink: false,
					canViewAnalytics: true, // Has capability for own links, but doesn't own this one
					isAdmin: false,
					currentUserId: editorUser1.id,
				})
			);

			// Edit button suppressed
			expect(html).not.toContain("links-row-action-edit");
			// Delete button suppressed
			expect(html).not.toContain("links-row-action-delete");
			// Analytics button suppressed
			expect(html).not.toContain("links-row-action-stats");
			// QR code is still accessible for public links
			expect(html).toContain("links-row-action-qr");
		});

		test("Non-admin Editor on another account's link with edit_any_link=true sees Edit but not Delete or Stats", () => {
			const html = renderToStaticMarkup(
				React.createElement(LinkRow, {
					link: otherLink,
					selected: false,
					onSelectRow: noop,
					onCopy: noop,
					copiedId: null,
					onOpenStats: noop,
					onEdit: noop,
					onDelete: noop,
					onQRCode: noop,
					formatNumber,
					canDeleteLinks: true,
					canDeleteAnyLink: false,
					canTrashLinks: true,
					canEditLinks: true,
					canEditAnyLink: true,
					canViewAnalytics: true,
					isAdmin: false,
					currentUserId: editorUser1.id,
				})
			);

			expect(html).toContain("links-row-action-edit");
			expect(html).not.toContain("links-row-action-delete");
			expect(html).not.toContain("links-row-action-stats");
		});

		test("Non-admin Editor on another account's link with delete_any_link=true sees Delete but not Edit or Stats", () => {
			const html = renderToStaticMarkup(
				React.createElement(LinkRow, {
					link: otherLink,
					selected: false,
					onSelectRow: noop,
					onCopy: noop,
					copiedId: null,
					onOpenStats: noop,
					onEdit: noop,
					onDelete: noop,
					onQRCode: noop,
					formatNumber,
					canDeleteLinks: true,
					canDeleteAnyLink: true,
					canTrashLinks: true,
					canEditLinks: true,
					canEditAnyLink: false,
					canViewAnalytics: true,
					isAdmin: false,
					currentUserId: editorUser1.id,
				})
			);

			expect(html).toContain("links-row-action-delete");
			expect(html).not.toContain("links-row-action-edit");
			expect(html).not.toContain("links-row-action-stats");
		});

		test("Trashed link renders Restore button and Delete Permanently button for authorized owner", () => {
			const html = renderToStaticMarkup(
				React.createElement(LinkRow, {
					link: trashedOwnedLink,
					selected: false,
					onSelectRow: noop,
					onCopy: noop,
					copiedId: null,
					onOpenStats: noop,
					onEdit: noop,
					onDelete: noop,
					onRestore: noop,
					onQRCode: noop,
					formatNumber,
					isTrashTab: true,
					canDeleteLinks: true,
					canEditLinks: true,
					canTrashLinks: true,
					isAdmin: false,
					currentUserId: editorUser1.id,
				})
			);

			expect(html).toContain("links-row-action-restore");
			expect(html).toContain("links-row-action-delete");
			expect(html).not.toContain("links-row-action-edit");
		});
	});

	test.describe("2. TableHeaderRow rendered markup by selection state", () => {
		test("Renders column headers with sort buttons when selection count is 0", () => {
			const html = renderToStaticMarkup(
				React.createElement(TableHeaderRow, {
					selectedCount: 0,
					onSelectAll: noop,
					sortBy: "createdAt",
					sortOrder: "desc",
					onSortChange: noop,
				})
			);

			expect(html).not.toContain("links-table-header-row-selected");
			expect(html).toContain("links-table-header-sort-btn");
			expect(html).not.toContain("selected");
		});

		test("Renders selection count and bulk delete button when onBulkDelete is passed", () => {
			const html = renderToStaticMarkup(
				React.createElement(TableHeaderRow, {
					selectedCount: 2,
					onSelectAll: noop,
					onBulkDelete: noop,
					canTrashSelected: true,
				})
			);

			expect(html).toContain("links-table-header-row-selected");
			expect(html).toContain("2 selected");
			expect(html).toContain("links-table-header-delete-selected");
			expect(html).toContain("Delete selected");
		});

		test("Renders Delete permanently button when canTrashSelected is false", () => {
			const html = renderToStaticMarkup(
				React.createElement(TableHeaderRow, {
					selectedCount: 2,
					onSelectAll: noop,
					onBulkDelete: noop,
					canTrashSelected: false,
				})
			);

			expect(html).toContain("links-table-header-row-selected");
			expect(html).toContain("2 selected");
			expect(html).toContain("Delete permanently");
		});

		test("Suppresses bulk delete button when onBulkDelete is undefined (unauthorized or incomplete selection)", () => {
			const html = renderToStaticMarkup(
				React.createElement(TableHeaderRow, {
					selectedCount: 2,
					onSelectAll: noop,
					onBulkDelete: undefined,
					canTrashSelected: false,
				})
			);

			expect(html).toContain("links-table-header-row-selected");
			expect(html).toContain("2 selected");
			expect(html).not.toContain("links-table-header-delete-selected");
		});

		test("Renders bulk restore button on trash tab when onBulkRestore is passed", () => {
			const html = renderToStaticMarkup(
				React.createElement(TableHeaderRow, {
					selectedCount: 3,
					onSelectAll: noop,
					onBulkRestore: noop,
					isTrashTab: true,
				})
			);

			expect(html).toContain("links-table-header-row-selected");
			expect(html).toContain("3 selected");
			expect(html).toContain("Restore selected");
		});
	});

	test.describe("3. Selection reset boundary and background refetch gating", () => {
		test("Filter/page/search/user context changes generate unique keys that reset mounted state", () => {
			const baseContext = {
				currentPage: 1,
				statusFilter: "all",
				isTrashTab: false,
				searchQuery: "",
				sortBy: "createdAt",
				sortOrder: "desc",
				userId: editorUser1.id,
			};

			const key1 = computeFilterContextKey(baseContext);

			// Page change resets
			expect(
				computeFilterContextKey({ ...baseContext, currentPage: 2 })
			).not.toBe(key1);
			// Search change resets
			expect(
				computeFilterContextKey({ ...baseContext, searchQuery: "new" })
			).not.toBe(key1);
			// Status change resets
			expect(
				computeFilterContextKey({
					...baseContext,
					statusFilter: "trashed",
					isTrashTab: true,
				})
			).not.toBe(key1);
			// User switch resets
			expect(
				computeFilterContextKey({
					...baseContext,
					userId: editorUser2.id,
				})
			).not.toBe(key1);
		});

		test("Background refetch removing a selected item immediately disallows bulk mutation", () => {
			const link1 = createMockLink("link_1", editorUser1.id);
			const link2 = createMockLink("link_2", editorUser1.id);

			// Initially both selected: complete selection authorized
			const stateInitial = getBulkSelectionState({
				selectedIds: ["link_1", "link_2"],
				links: [link1, link2],
				user: editorUser1,
				canTrashLinks: true,
			});
			expect(stateInitial.hasCompleteSelection).toBe(true);
			expect(stateInitial.canBulkDelete).toBe(true);

			// Background refetch occurs: link2 was deleted on server, visible links is now [link1]
			const pruned = pruneStaleSelectedIds(["link_1", "link_2"], [link1]);
			expect(pruned).toEqual(["link_1"]);

			// If raw selectedIds was passed without pruning: hasCompleteSelection is immediately false
			const stateUnpruned = getBulkSelectionState({
				selectedIds: ["link_1", "link_2"],
				links: [link1],
				user: editorUser1,
				canTrashLinks: true,
			});
			expect(stateUnpruned.hasCompleteSelection).toBe(false);
			expect(stateUnpruned.canBulkDelete).toBe(false);
		});
	});

	test.describe("4. Sequential confirmation snapshot lifecycle and submission guard simulation", () => {
		test("A+B selected -> refresh to A only -> confirmation invalidated, no mutation call, no A-only substitution", () => {
			const linkA = createMockLink("link_A", editorUser1.id);
			const linkB = createMockLink("link_B", editorUser1.id);
			const links = [linkA, linkB];

			// 1. User selects A+B
			const selectedIds = ["link_A", "link_B"];
			const selectionState = getBulkSelectionState({
				selectedIds,
				links,
				user: editorUser1,
				canTrashLinks: true,
				canDeleteLinks: true,
			});
			expect(selectionState.hasCompleteSelection).toBe(true);

			// 2. User opens bulk delete modal: snapshot created
			const snapshot = createBulkDeleteSnapshot({
				id: 1,
				selectedIds,
				links,
				isTrashTab: false,
				canTrash: selectionState.canTrashAllSelected,
				canPermanentlyDelete:
					selectionState.canPermanentlyDeleteAllSelected,
			});
			expect(snapshot).not.toBeNull();
			expect(snapshot?.selectedIds).toEqual(["link_A", "link_B"]);

			// 3. Background refresh drops B from links prop
			const refreshedLinks = [linkA];

			// Validation of snapshot against refreshed links:
			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: refreshedLinks,
				currentSelectedIds: selectedIds,
				isTrashTab: false,
				user: editorUser1,
				canTrashLinks: true,
				canDeleteLinks: true,
			});

			// Invariant 2: No subset substitution! B is missing, so confirmation is invalidated
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("item_missing");

			// Invariant 5: Submission guard refuses action if confirmation snapshot is invalid
			let mutationCalled = false;
			const attemptSubmit = (snap: typeof snapshot) => {
				const check = validateBulkDeleteSnapshot({
					snapshot: snap,
					currentLinks: refreshedLinks,
					currentSelectedIds: selectedIds,
					isTrashTab: false,
					user: editorUser1,
					canTrashLinks: true,
					canDeleteLinks: true,
				});
				if (!check.isValid) {
					throw new Error(
						"Bulk delete confirmation is no longer valid."
					);
				}
				mutationCalled = true;
			};

			expect(() => attemptSubmit(snapshot)).toThrow(
				"Bulk delete confirmation is no longer valid."
			);
			expect(mutationCalled).toBe(false);

			// Invariant 3: B reappears on next fetch, but since confirmation was cancelled (cleared to null),
			// modal remains closed and cannot execute without a fresh user click
			const activeSnapshotAfterDismissal = null;
			const checkAfterReappear = validateBulkDeleteSnapshot({
				snapshot: activeSnapshotAfterDismissal,
				currentLinks: [linkA, linkB],
				currentSelectedIds: selectedIds,
				isTrashTab: false,
				user: editorUser1,
				canTrashLinks: true,
				canDeleteLinks: true,
			});
			expect(checkAfterReappear.isValid).toBe(false);
			expect(checkAfterReappear.reason).toBe("no_snapshot");
		});

		test("Active modal + capability revocation invalidates confirmation and blocks submission", () => {
			const linkA = createMockLink("link_A", editorUser1.id);
			const snapshot = createBulkDeleteSnapshot({
				id: 2,
				selectedIds: ["link_A"],
				links: [linkA],
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: false,
			});
			expect(snapshot?.canTrash).toBe(true);

			// User's canTrashLinks revoked in background
			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: [linkA],
				currentSelectedIds: ["link_A"],
				isTrashTab: false,
				user: editorUser1,
				canTrashLinks: false, // Revoked
				canDeleteLinks: false,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("trash_permission_revoked");
		});

		test("Active modal + link status changed to trashed invalidates confirmation", () => {
			const linkA = createMockLink("link_A", editorUser1.id);
			const snapshot = createBulkDeleteSnapshot({
				id: 3,
				selectedIds: ["link_A"],
				links: [linkA],
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: false,
			});

			const updatedLinkA = { ...linkA, status: "trashed" as const };
			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: [updatedLinkA],
				currentSelectedIds: ["link_A"],
				isTrashTab: false,
				user: editorUser1,
				canTrashLinks: true,
				canDeleteLinks: false,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("link_metadata_changed");
		});

		test("Active modal + link ownership changed invalidates confirmation", () => {
			const linkA = createMockLink("link_A", editorUser1.id);
			const snapshot = createBulkDeleteSnapshot({
				id: 4,
				selectedIds: ["link_A"],
				links: [linkA],
				isTrashTab: false,
				canTrash: true,
				canPermanentlyDelete: false,
			});

			const transferredLinkA = { ...linkA, userId: "other_user_99" };
			const validation = validateBulkDeleteSnapshot({
				snapshot,
				currentLinks: [transferredLinkA],
				currentSelectedIds: ["link_A"],
				isTrashTab: false,
				user: editorUser1,
				canTrashLinks: true,
				canDeleteLinks: false,
			});
			expect(validation.isValid).toBe(false);
			expect(validation.reason).toBe("link_metadata_changed");
		});
	});
});
