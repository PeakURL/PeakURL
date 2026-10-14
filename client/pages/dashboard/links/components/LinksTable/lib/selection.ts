import type { LinkRecord } from "../../types";

export interface FilterContextParams {
	currentPage?: number;
	statusFilter?: string;
	isTrashTab?: boolean;
	searchQuery?: string;
	sortBy?: string;
	sortOrder?: string;
	clickRange?: string;
	customClickRange?: { from?: string; to?: string };
	userId?: string | null;
}

/**
 * Compute the composite context key used to reset selection when filters/page/search/account change.
 */
export function computeFilterContextKey(params: FilterContextParams): string {
	return `${params.currentPage ?? 1}:${params.statusFilter ?? ""}:${params.isTrashTab ? "trash" : "links"}:${params.searchQuery ?? ""}:${params.sortBy ?? ""}:${params.sortOrder ?? ""}:${params.clickRange ?? ""}:${params.customClickRange?.from ?? ""}:${params.customClickRange?.to ?? ""}:${params.userId ?? ""}`;
}

/**
 * Prune selected IDs that are no longer present in the visible links list.
 */
export function pruneStaleSelectedIds(
	selectedIds: string[],
	visibleLinks: LinkRecord[]
): string[] {
	const visibleIdSet = new Set(visibleLinks.map((link) => link.id));
	return selectedIds.filter((id) => visibleIdSet.has(id));
}

export interface BulkSelectionInput {
	selectedIds: string[];
	links: LinkRecord[];
	isTrashTab?: boolean;
	user?: { id?: string | null } | null;
	effectiveIsAdmin?: boolean;
	canTrashLinks?: boolean;
	canDeleteLinks?: boolean;
	canDeleteAnyLink?: boolean;
	canEditLinks?: boolean;
}

export interface BulkSelectionState {
	selectedLinks: LinkRecord[];
	hasCompleteSelection: boolean;
	canTrashAllSelected: boolean;
	canPermanentlyDeleteAllSelected: boolean;
	canRestoreAllSelected: boolean;
	canBulkDelete: boolean;
}

/**
 * Derive selection validity and destructive bulk-action authorization from selection and role permissions.
 */
export function getBulkSelectionState(
	input: BulkSelectionInput
): BulkSelectionState {
	const {
		selectedIds,
		links,
		isTrashTab = false,
		user,
		effectiveIsAdmin = false,
		canTrashLinks = false,
		canDeleteLinks = false,
		canDeleteAnyLink = false,
		canEditLinks = false,
	} = input;

	const visibleIdSet = new Set(links.map((link) => link.id));
	const selectedLinks = links.filter((link) => selectedIds.includes(link.id));

	// Selection is complete only if non-empty, all selected IDs exist in visible links,
	// and no stale/partial IDs are selected.
	const hasCompleteSelection =
		selectedIds.length > 0 &&
		selectedLinks.length === selectedIds.length &&
		selectedIds.every((id) => visibleIdSet.has(id));

	const canTrashAllSelected =
		hasCompleteSelection &&
		!isTrashTab &&
		Boolean(canTrashLinks) &&
		selectedLinks.every(
			(link) =>
				effectiveIsAdmin ||
				(Boolean(user?.id) && link.userId === user?.id)
		);

	const canPermanentlyDeleteAllSelected =
		hasCompleteSelection &&
		Boolean(canDeleteLinks) &&
		selectedLinks.every(
			(link) =>
				effectiveIsAdmin ||
				(Boolean(user?.id) && link.userId === user?.id) ||
				Boolean(canDeleteAnyLink)
		);

	const canRestoreAllSelected =
		hasCompleteSelection &&
		Boolean(isTrashTab) &&
		Boolean(canEditLinks) &&
		selectedLinks.every(
			(link) =>
				effectiveIsAdmin ||
				(Boolean(user?.id) && link.userId === user?.id)
		);

	const canBulkDelete = isTrashTab
		? canPermanentlyDeleteAllSelected
		: canTrashAllSelected || canPermanentlyDeleteAllSelected;

	return {
		selectedLinks,
		hasCompleteSelection,
		canTrashAllSelected,
		canPermanentlyDeleteAllSelected,
		canRestoreAllSelected,
		canBulkDelete,
	};
}

export interface TargetLinkSnapshot {
	id: string;
	userId?: string | null;
	status?: string | null;
}

export interface BulkDeleteConfirmationSnapshot {
	id: number;
	selectedIds: string[];
	isTrashTab: boolean;
	targetLinks: TargetLinkSnapshot[];
	canTrash: boolean;
	canPermanentlyDelete: boolean;
	isPermanentOnly: boolean;
}

export interface CreateBulkDeleteSnapshotParams {
	id: number;
	selectedIds: string[];
	links: LinkRecord[];
	isTrashTab: boolean;
	canTrash: boolean;
	canPermanentlyDelete: boolean;
}

/**
 * Capture an explicit, immutable confirmation snapshot of selected links and intended operation.
 */
export function createBulkDeleteSnapshot(
	params: CreateBulkDeleteSnapshotParams
): BulkDeleteConfirmationSnapshot | null {
	const {
		id,
		selectedIds,
		links,
		isTrashTab,
		canTrash,
		canPermanentlyDelete,
	} = params;

	if (!selectedIds || selectedIds.length === 0) {
		return null;
	}

	const targetLinks: TargetLinkSnapshot[] = [];
	for (const selectedId of selectedIds) {
		const found = links.find((link) => link.id === selectedId);
		if (!found) {
			return null;
		}
		targetLinks.push({
			id: found.id,
			userId: found.userId,
			status: found.status,
		});
	}

	const isPermanentOnly = isTrashTab || (!canTrash && canPermanentlyDelete);

	return {
		id,
		selectedIds: [...selectedIds],
		isTrashTab,
		targetLinks,
		canTrash,
		canPermanentlyDelete,
		isPermanentOnly,
	};
}

export interface ValidateBulkDeleteSnapshotParams {
	snapshot: BulkDeleteConfirmationSnapshot | null;
	currentLinks: LinkRecord[];
	currentSelectedIds: string[];
	isTrashTab: boolean;
	user?: { id?: string | null } | null;
	effectiveIsAdmin?: boolean;
	canTrashLinks?: boolean;
	canDeleteLinks?: boolean;
	canDeleteAnyLink?: boolean;
}

export interface SnapshotValidationResult {
	isValid: boolean;
	reason?: string;
}

/**
 * Validate an open confirmation snapshot against current links, selection, and permissions.
 * Invalidates and dismisses on missing items, changed selection, status/owner changes, or permission revocation.
 */
export function validateBulkDeleteSnapshot(
	params: ValidateBulkDeleteSnapshotParams
): SnapshotValidationResult {
	const {
		snapshot,
		currentLinks,
		currentSelectedIds,
		isTrashTab,
		user,
		effectiveIsAdmin = false,
		canTrashLinks = false,
		canDeleteLinks = false,
		canDeleteAnyLink = false,
	} = params;

	if (!snapshot) {
		return { isValid: false, reason: "no_snapshot" };
	}

	// Invalidate if tab changed
	if (snapshot.isTrashTab !== isTrashTab) {
		return { isValid: false, reason: "tab_changed" };
	}

	// Invalidate if active selection changed (no subset substitution or extra items)
	if (
		currentSelectedIds.length !== snapshot.selectedIds.length ||
		!snapshot.selectedIds.every((id) => currentSelectedIds.includes(id))
	) {
		return { isValid: false, reason: "selection_changed" };
	}

	// Verify all target links still exist and have unchanged metadata
	const currentLinkMap = new Map<string, LinkRecord>();
	for (const link of currentLinks) {
		currentLinkMap.set(link.id, link);
	}

	for (const target of snapshot.targetLinks) {
		const current = currentLinkMap.get(target.id);
		if (!current) {
			return { isValid: false, reason: "item_missing" };
		}
		if (
			current.userId !== target.userId ||
			current.status !== target.status
		) {
			return { isValid: false, reason: "link_metadata_changed" };
		}
	}

	// Verify authorization over the entire unpruned selection
	const currentTargetLinks = snapshot.selectedIds
		.map((id) => currentLinkMap.get(id))
		.filter((l): l is LinkRecord => Boolean(l));

	if (currentTargetLinks.length !== snapshot.selectedIds.length) {
		return { isValid: false, reason: "incomplete_links" };
	}

	const nowCanTrash =
		!isTrashTab &&
		Boolean(canTrashLinks) &&
		currentTargetLinks.every(
			(link) =>
				effectiveIsAdmin ||
				(Boolean(user?.id) && link.userId === user?.id)
		);

	const nowCanPermanentlyDelete =
		Boolean(canDeleteLinks) &&
		currentTargetLinks.every(
			(link) =>
				effectiveIsAdmin ||
				(Boolean(user?.id) && link.userId === user?.id) ||
				Boolean(canDeleteAnyLink)
		);

	if (snapshot.isPermanentOnly) {
		if (!nowCanPermanentlyDelete) {
			return { isValid: false, reason: "permanent_delete_revoked" };
		}
	} else {
		// Normal tab with trash mode intended
		if (snapshot.canTrash && !nowCanTrash) {
			return { isValid: false, reason: "trash_permission_revoked" };
		}
		if (
			snapshot.canPermanentlyDelete &&
			!nowCanPermanentlyDelete &&
			!nowCanTrash
		) {
			return { isValid: false, reason: "all_delete_permissions_revoked" };
		}
	}

	return { isValid: true };
}
