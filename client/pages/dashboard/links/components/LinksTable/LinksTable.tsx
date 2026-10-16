import type { ChangeEvent } from "react";
import { useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router";
import { Search, X } from "lucide-react";

import { ConfirmDialog, useNotification } from "@/components";
import { __ } from "@/i18n";
import { useAdminAccess } from "@/hooks";
import { copyToClipboard } from "@/shared/browser";
import { getErrorMessage } from "@/shared/errors";
import { formatCount, formatNumber } from "@/shared/formatting";
import { getShortUrl } from "@/shared/links";
import {
	useBulkDeleteUrlMutation,
	useCheckLinkHealthMutation,
} from "@/state/slices/api";

import StatsDrawer from "../StatsDrawer";
import QRCodeModal from "../QRCodeModal";
import EditLinkDrawer from "../EditLinkDrawer";
import DeleteLinkModal from "../DeleteLinkModal";
import BulkDeleteModal from "../BulkDeleteModal";
import DeleteAllModal from "../DeleteAllModal";
import HealthDetailModal from "../HealthDetailModal";
import TableHeaderRow from "./parts/TableHeaderRow";
import LinkRow from "./parts/LinkRow";
import EmptyState from "./parts/EmptyState";
import {
	createBulkDeleteSnapshot,
	getBulkSelectionState,
	pruneStaleSelectedIds,
	validateBulkDeleteSnapshot,
	type BulkDeleteConfirmationSnapshot,
} from "./lib";
import type { LinkRecord } from "../types";
import type { LinksTableProps } from "./types";

const LinksTable = ({
	links,
	totalCount,
	searchQuery,
	onSearchChange,
	statsShortId,
	statsLink,
	sortBy,
	sortOrder,
	onSortChange,
	statusFilter,
	clickRange,
	customClickRange,
	isTrashTab = false,
	trashedCount = 0,
	onRestore,
	onBulkRestore,
	onEmptyTrash,
	isAdmin: isAdminProp,
}: LinksTableProps) => {
	const [copiedId, setCopiedId] = useState<string | null>(null);
	const [statsDrawerOpen, setStatsDrawerOpen] = useState(false);
	const [qrModalOpen, setQrModalOpen] = useState(false);
	const [editDrawerOpen, setEditDrawerOpen] = useState(false);
	const [deleteModalOpen, setDeleteModalOpen] = useState(false);
	const [bulkDeleteConfirmation, setBulkDeleteConfirmation] =
		useState<BulkDeleteConfirmationSnapshot | null>(null);
	const snapshotCounterRef = useRef<number>(0);
	const cancelledSnapshotIdRef = useRef<number | null>(null);
	const [deleteAllModalOpen, setDeleteAllModalOpen] = useState(false);
	const [emptyTrashModalOpen, setEmptyTrashModalOpen] = useState(false);
	const [healthModalOpen, setHealthModalOpen] = useState(false);
	const [healthModalLinkId, setHealthModalLinkId] = useState<string | null>(
		null
	);
	const [checkingHealthId, setCheckingHealthId] = useState<string | null>(
		null
	);
	const [selectedLink, setSelectedLink] = useState<LinkRecord | null>(null);
	const [selectedIds, setSelectedIds] = useState<string[]>([]);
	const [searchParams, setSearchParams] = useSearchParams();
	const {
		canDeleteLinks,
		canDeleteAnyLink,
		canTrashLinks,
		canEditLinks,
		canEditAnyLink,
		canViewAnalytics,
		isAdmin,
		user,
	} = useAdminAccess();
	const effectiveIsAdmin = Boolean(isAdminProp ?? isAdmin);
	const [checkLinkHealth] = useCheckLinkHealthMutation();
	const [bulkDeleteUrl] = useBulkDeleteUrlMutation();
	const notification = useNotification();

	const healthModalLink = healthModalLinkId
		? (links.find(
				(linkItem: LinkRecord) => linkItem.id === healthModalLinkId
			) ?? null)
		: null;

	useEffect(() => {
		if (!statsShortId) return;

		const link =
			links.find(
				(linkItem: LinkRecord) =>
					linkItem.shortCode === statsShortId ||
					linkItem.alias === statsShortId
			) || statsLink;

		const params = new URLSearchParams(searchParams.toString());
		params.delete("stats");
		setSearchParams(params, { replace: true });

		if (!link || link.status === "trashed" || isTrashTab) return;
		if (!canViewAnalytics) return;
		const isOwner = Boolean(user?.id && link.userId === user.id);
		if (!effectiveIsAdmin && !isOwner) return;

		setTimeout(() => {
			setSelectedLink(link);
			setStatsDrawerOpen(true);
		}, 0);
	}, [
		statsShortId,
		links,
		statsLink,
		isTrashTab,
		searchParams,
		setSearchParams,
		canViewAnalytics,
		effectiveIsAdmin,
		user?.id,
	]);

	const handleCopy = async (link: LinkRecord) => {
		const shortUrl = getShortUrl(link);
		try {
			await copyToClipboard(shortUrl);
			setCopiedId(link.id);
			setTimeout(() => setCopiedId(null), 2000);
		} catch (err) {
			console.error("Failed to copy:", err);
		}
	};

	const handleOpenStats = (link: LinkRecord) => {
		if (link.status === "trashed" || isTrashTab) return;
		if (!canViewAnalytics) return;
		const isOwner = Boolean(user?.id && link.userId === user.id);
		if (!effectiveIsAdmin && !isOwner) return;
		setSelectedLink(link);
		setStatsDrawerOpen(true);
	};

	const handleDelete = (link: LinkRecord) => {
		const isOwner = Boolean(user?.id && link.userId === user.id);
		if (isTrashTab) {
			if (!canDeleteLinks) return;
			if (!effectiveIsAdmin && !isOwner && !canDeleteAnyLink) return;
		} else {
			const canTrashThis = canTrashLinks && (isOwner || effectiveIsAdmin);
			const canPermDeleteThis =
				canDeleteLinks &&
				(isOwner || canDeleteAnyLink || effectiveIsAdmin);
			if (!canTrashThis && !canPermDeleteThis) return;
		}
		setSelectedLink(link);
		setDeleteModalOpen(true);
	};

	const handleEdit = (link: LinkRecord) => {
		if (link.status === "trashed" || isTrashTab) return;
		if (!canEditLinks) return;
		const isOwner = Boolean(user?.id && link.userId === user.id);
		if (!effectiveIsAdmin && !isOwner && !canEditAnyLink) return;
		setSelectedLink(link);
		setEditDrawerOpen(true);
	};

	const handleQRCode = (link: LinkRecord) => {
		setSelectedLink(link);
		setQrModalOpen(true);
	};

	const handleCheckHealth = async (id: string) => {
		if (checkingHealthId) return;
		setCheckingHealthId(id);
		try {
			await checkLinkHealth(id).unwrap();
			notification.success(__("Health check completed."));
		} catch (err) {
			notification.error(
				getErrorMessage(err, __("Failed to check link health."))
			);
		} finally {
			setCheckingHealthId(null);
		}
	};

	// Derive active valid selections strictly from currently visible links (safe across refetches without render-phase mutation)
	const validSelectedIds = useMemo(
		() => pruneStaleSelectedIds(selectedIds, links),
		[selectedIds, links]
	);

	const handleOpenHealthModal = (link: LinkRecord) => {
		setHealthModalLinkId(link.id);
		setHealthModalOpen(true);
	};

	const handleSelectAll = (e: ChangeEvent<HTMLInputElement>) => {
		if (e.target.checked) {
			setSelectedIds(links.map((link: LinkRecord) => link.id));
		} else {
			setSelectedIds([]);
		}
	};

	const handleSelectRow = (id: string) => {
		setSelectedIds((prev) => {
			const currentValid = prev.filter((prevId) =>
				links.some((l) => l.id === prevId)
			);
			return currentValid.includes(id)
				? currentValid.filter((item) => item !== id)
				: [...currentValid, id];
		});
	};

	const {
		hasCompleteSelection,
		canTrashAllSelected,
		canPermanentlyDeleteAllSelected,
		canRestoreAllSelected,
		canBulkDelete,
	} = getBulkSelectionState({
		selectedIds: validSelectedIds,
		links,
		isTrashTab,
		user,
		effectiveIsAdmin,
		canTrashLinks,
		canDeleteLinks,
		canDeleteAnyLink,
		canEditLinks,
	});

	// Synchronously validate active bulk-delete confirmation snapshot against current links, selection, and permissions
	const isConfirmationValid = useMemo(() => {
		if (!bulkDeleteConfirmation) return false;
		return validateBulkDeleteSnapshot({
			snapshot: bulkDeleteConfirmation,
			currentLinks: links,
			currentSelectedIds: selectedIds,
			isTrashTab,
			user,
			effectiveIsAdmin,
			canTrashLinks,
			canDeleteLinks,
			canDeleteAnyLink,
		}).isValid;
	}, [
		bulkDeleteConfirmation,
		links,
		selectedIds,
		isTrashTab,
		user,
		effectiveIsAdmin,
		canTrashLinks,
		canDeleteLinks,
		canDeleteAnyLink,
	]);

	// Asynchronously clear invalidated confirmation state so no state mutation happens during render
	useEffect(() => {
		if (bulkDeleteConfirmation && !isConfirmationValid) {
			queueMicrotask(() => {
				setBulkDeleteConfirmation(null);
			});
		}
	}, [bulkDeleteConfirmation, isConfirmationValid]);

	const handleBulkDelete = () => {
		if (!hasCompleteSelection || !canBulkDelete) return;
		const nextId = ++snapshotCounterRef.current;
		const snapshot = createBulkDeleteSnapshot({
			id: nextId,
			selectedIds: validSelectedIds,
			links,
			isTrashTab,
			canTrash: canTrashAllSelected,
			canPermanentlyDelete: canPermanentlyDeleteAllSelected,
		});
		if (!snapshot) return;
		cancelledSnapshotIdRef.current = null;
		setBulkDeleteConfirmation(snapshot);
	};

	const handleBulkDeleteConfirm = async (
		ids: string[],
		permanent: boolean
	) => {
		if (
			!bulkDeleteConfirmation ||
			cancelledSnapshotIdRef.current === bulkDeleteConfirmation.id
		) {
			throw new Error(
				__("Bulk delete confirmation is invalid or expired.")
			);
		}

		const validation = validateBulkDeleteSnapshot({
			snapshot: bulkDeleteConfirmation,
			currentLinks: links,
			currentSelectedIds: selectedIds,
			isTrashTab,
			user,
			effectiveIsAdmin,
			canTrashLinks,
			canDeleteLinks,
			canDeleteAnyLink,
		});

		if (!validation.isValid) {
			cancelledSnapshotIdRef.current = bulkDeleteConfirmation.id;
			setBulkDeleteConfirmation(null);
			throw new Error(__("Bulk delete confirmation is no longer valid."));
		}

		if (permanent) {
			if (!bulkDeleteConfirmation.canPermanentlyDelete) {
				throw new Error(
					__(
						"Permanent deletion is not authorized for the selected links."
					)
				);
			}
			await bulkDeleteUrl({
				ids: [...bulkDeleteConfirmation.selectedIds],
				force: true,
			}).unwrap();
		} else {
			if (!bulkDeleteConfirmation.canTrash) {
				throw new Error(
					__(
						"Moving to trash is not authorized for the selected links."
					)
				);
			}
			await bulkDeleteUrl([
				...bulkDeleteConfirmation.selectedIds,
			]).unwrap();
		}
	};

	const handleBulkDeleteSuccess = () => {
		setSelectedIds([]);
		if (bulkDeleteConfirmation) {
			cancelledSnapshotIdRef.current = bulkDeleteConfirmation.id;
		}
		setBulkDeleteConfirmation(null);
	};

	const handleBulkRestoreAction = async () => {
		if (!hasCompleteSelection || !canRestoreAllSelected) return;
		try {
			if (onBulkRestore) {
				await onBulkRestore(validSelectedIds);
			}
			setSelectedIds([]);
		} catch {}
	};

	const hasLinks = links.length > 0;
	const isSearchActive = Boolean(searchQuery.trim());
	const displayCount = totalCount;

	return (
		<div className="links-table">
			<div className="links-table-panel-header">
				<div className="flex items-center gap-2">
					<h2 className="links-table-panel-title">
						{isTrashTab
							? __("Trashed Links")
							: statusFilter === "expired"
								? __("Expired Links")
								: __("All Links")}
					</h2>
					<span className="links-table-panel-badge">
						{formatCount(displayCount)}
					</span>
				</div>

				<div className="w-full sm:w-64">
					<div className="relative">
						<Search
							size={14}
							className="pointer-events-none absolute inset-s-3 top-1/2 -translate-y-1/2 text-text-muted"
						/>
						<input
							type="text"
							value={searchQuery}
							onChange={(e) => onSearchChange(e.target.value)}
							placeholder={__("Search links...")}
							className="form-control-base links-table-search-input"
						/>
						{searchQuery ? (
							<button
								type="button"
								onClick={() => onSearchChange("")}
								className="absolute inset-e-2.5 top-1/2 -translate-y-1/2 text-text-muted transition-colors hover:text-heading"
								aria-label={__("Clear search")}
							>
								<X size={13} />
							</button>
						) : null}
					</div>
				</div>
			</div>

			{!hasLinks ? (
				<EmptyState
					isSearchActive={isSearchActive}
					searchQuery={searchQuery}
					isTrashTab={isTrashTab}
					statusFilter={statusFilter}
				/>
			) : (
				<div className="links-table-scroll">
					<table className="links-table-element">
						<thead className="links-table-head">
							<TableHeaderRow
								selectedCount={validSelectedIds.length}
								onSelectAll={handleSelectAll}
								onBulkDelete={
									canBulkDelete ? handleBulkDelete : undefined
								}
								onDeleteAll={
									isTrashTab ||
									(!canTrashLinks && !canDeleteLinks)
										? undefined
										: () => setDeleteAllModalOpen(true)
								}
								onBulkRestore={
									canRestoreAllSelected && onBulkRestore
										? handleBulkRestoreAction
										: undefined
								}
								onEmptyTrash={
									isTrashTab && onEmptyTrash
										? () => setEmptyTrashModalOpen(true)
										: undefined
								}
								isTrashTab={isTrashTab}
								trashedCount={trashedCount}
								sortBy={sortBy}
								sortOrder={sortOrder}
								onSortChange={onSortChange}
								canTrashSelected={canTrashAllSelected}
								canViewAnalytics={canViewAnalytics}
								isAdmin={effectiveIsAdmin}
							/>
						</thead>
						<tbody className="links-table-body">
							{links.map((link: LinkRecord) => (
								<LinkRow
									key={link.id}
									link={link}
									selected={validSelectedIds.includes(
										link.id
									)}
									onSelectRow={handleSelectRow}
									onCopy={handleCopy}
									copiedId={copiedId}
									onOpenStats={handleOpenStats}
									onOpenHealthModal={handleOpenHealthModal}
									onCheckHealth={handleCheckHealth}
									isCheckingHealth={
										checkingHealthId === link.id
									}
									isAnyCheckingHealth={Boolean(
										checkingHealthId
									)}
									onEdit={handleEdit}
									onDelete={handleDelete}
									onRestore={onRestore}
									onQRCode={handleQRCode}
									formatNumber={formatNumber}
									isTrashTab={isTrashTab}
									sortBy={sortBy}
									canDeleteLinks={canDeleteLinks}
									canDeleteAnyLink={canDeleteAnyLink}
									canTrashLinks={canTrashLinks}
									canEditLinks={canEditLinks}
									canEditAnyLink={canEditAnyLink}
									canViewAnalytics={canViewAnalytics}
									isAdmin={effectiveIsAdmin}
									currentUserId={user?.id}
								/>
							))}
						</tbody>
					</table>
				</div>
			)}

			<StatsDrawer
				open={statsDrawerOpen}
				setOpen={setStatsDrawerOpen}
				link={selectedLink}
				pageClickRange={clickRange}
				pageCustomClickRange={customClickRange}
			/>
			<QRCodeModal
				open={qrModalOpen}
				setOpen={setQrModalOpen}
				link={selectedLink}
			/>
			<HealthDetailModal
				open={healthModalOpen && Boolean(healthModalLink)}
				setOpen={(isOpen) => {
					setHealthModalOpen(isOpen);
					if (!isOpen) {
						setHealthModalLinkId(null);
					}
				}}
				link={healthModalLink}
				onCheckNow={handleCheckHealth}
				isChecking={checkingHealthId === healthModalLink?.id}
			/>
			<EditLinkDrawer
				open={editDrawerOpen}
				setOpen={setEditDrawerOpen}
				link={selectedLink}
			/>
			<DeleteLinkModal
				open={deleteModalOpen && Boolean(selectedLink)}
				setOpen={(isOpen) => {
					setDeleteModalOpen(isOpen);
					if (!isOpen) {
						setSelectedLink(null);
					}
				}}
				link={selectedLink}
				isTrashTab={isTrashTab}
			/>
			<BulkDeleteModal
				open={Boolean(bulkDeleteConfirmation && isConfirmationValid)}
				setOpen={(isOpen) => {
					if (!isOpen) {
						if (bulkDeleteConfirmation) {
							cancelledSnapshotIdRef.current =
								bulkDeleteConfirmation.id;
						}
						setBulkDeleteConfirmation(null);
					}
				}}
				selectedIds={
					bulkDeleteConfirmation
						? bulkDeleteConfirmation.selectedIds
						: []
				}
				isTrashTab={isTrashTab}
				canTrash={
					bulkDeleteConfirmation
						? bulkDeleteConfirmation.canTrash
						: false
				}
				canPermanentlyDelete={
					bulkDeleteConfirmation
						? bulkDeleteConfirmation.canPermanentlyDelete
						: false
				}
				onConfirm={handleBulkDeleteConfirm}
				onSuccess={handleBulkDeleteSuccess}
			/>
			<DeleteAllModal
				open={deleteAllModalOpen && (canTrashLinks || canDeleteLinks)}
				setOpen={setDeleteAllModalOpen}
				onSuccess={() => setSelectedIds([])}
			/>
			<ConfirmDialog
				open={emptyTrashModalOpen}
				onClose={() => setEmptyTrashModalOpen(false)}
				title={__("Empty Trash")}
				description={__(
					"Are you sure you want to permanently delete all links in the trash? This action cannot be undone."
				)}
				confirmText={__("Empty Trash")}
				confirmVariant="danger"
				onConfirm={async () => {
					setEmptyTrashModalOpen(false);
					if (onEmptyTrash) {
						await onEmptyTrash();
					}
				}}
			/>
		</div>
	);
};

export default LinksTable;
