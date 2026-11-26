import type { ChangeEvent } from "react";
import { useEffect, useId, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router";
import {
	Button,
	ConfirmDialog,
	SearchInput,
	Skeleton,
	useNotification,
} from "@/components";
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

const columns = [
	"select",
	"link",
	"title",
	"destination",
	"health",
	"performance",
	"created",
	"actions",
] as const;

const LinksTable = ({
	filterContextKey,
	isLoading = false,
	isRefreshing = false,
	hasError = false,
	onRetry,
	links,
	totalCount,
	searchQuery,
	onSearchChange,
	statsShortId,
	statsLink,
	isStatsLookupPending,
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
	const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
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

	const previousFilterContextKeyRef = useRef(filterContextKey);

	useEffect(() => {
		if (previousFilterContextKeyRef.current !== filterContextKey) {
			previousFilterContextKeyRef.current = filterContextKey;
			setSelectedIds([]);
		}
	}, [filterContextKey]);

	useEffect(() => {
		if (!statsShortId) return;

		const link =
			links.find(
				(linkItem: LinkRecord) =>
					linkItem.shortCode === statsShortId ||
					linkItem.alias === statsShortId
			) || statsLink;
		if (!link && isStatsLookupPending) return;

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
		isStatsLookupPending,
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
		if (bulkDeleteOpen && bulkDeleteConfirmation && !isConfirmationValid) {
			queueMicrotask(() => {
				setBulkDeleteConfirmation((current) =>
					current?.id === bulkDeleteConfirmation.id ? null : current
				);
			});
		}
	}, [bulkDeleteOpen, bulkDeleteConfirmation, isConfirmationValid]);

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
		setBulkDeleteOpen(true);
	};

	const closeBulkDelete = () => {
		if (bulkDeleteConfirmation) {
			cancelledSnapshotIdRef.current = bulkDeleteConfirmation.id;
		}
		setBulkDeleteOpen(false);
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

	const titleId = useId();
	const hasLinks = links.length > 0;
	const isSearchActive = Boolean(searchQuery.trim());

	return (
		<section
			className="links-table"
			aria-labelledby={titleId}
			aria-busy={isLoading || isRefreshing}
		>
			<div className="links-table-panel-header">
				<div className="flex items-center gap-2">
					<h2 id={titleId} className="links-table-panel-title">
						{isTrashTab
							? __("Trashed Links")
							: statusFilter === "expired"
								? __("Expired Links")
								: __("All Links")}
					</h2>
					{isLoading ? (
						<Skeleton className="h-5 w-8" aria-hidden="true" />
					) : (
						!hasError && (
							<span className="links-table-panel-badge">
								{formatCount(totalCount)}
							</span>
						)
					)}
				</div>

				<div className="links-table-search">
					<SearchInput
						value={searchQuery}
						onChange={onSearchChange}
						placeholder={__("Search links...")}
						aria-label={__("Search links")}
					/>
				</div>
			</div>

			{hasError ? (
				<div className="links-empty-state">
					<p role="status" className="links-empty-state-title">
						{__("Links could not be loaded.")}
					</p>
					<Button
						variant="secondary"
						size="sm"
						onClick={onRetry}
						loading={isRefreshing}
					>
						{__("Try again")}
					</Button>
				</div>
			) : !hasLinks && !isLoading ? (
				<EmptyState
					isSearchActive={isSearchActive}
					searchQuery={searchQuery}
					isTrashTab={isTrashTab}
					statusFilter={statusFilter}
				/>
			) : (
				<div
					className="links-table-scroll"
					role="region"
					aria-label={__("Link results")}
					// eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- Keyboard users need to scroll wide tables.
					tabIndex={0}
				>
					<table
						className="links-table-element"
						aria-labelledby={titleId}
					>
						<colgroup>
							{columns.map((column) => (
								<col
									key={column}
									className={`links-table-col-${column}`}
								/>
							))}
						</colgroup>
						<thead className="links-table-head">
							<TableHeaderRow
								selectedCount={validSelectedIds.length}
								onSelectAll={
									isLoading ? undefined : handleSelectAll
								}
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
								onSortChange={
									isLoading ? undefined : onSortChange
								}
								canTrashSelected={canTrashAllSelected}
								canViewAnalytics={canViewAnalytics}
								isAdmin={effectiveIsAdmin}
							/>
						</thead>
						<tbody className="links-table-body">
							{isLoading
								? Array.from({ length: 5 }, (_, index) => (
										<tr key={index} aria-hidden="true">
											{columns.map((column) => (
												<td
													key={column}
													className="links-row-cell"
												>
													<Skeleton
														className={`links-table-placeholder links-table-placeholder-${column}`}
														aria-hidden="true"
													/>
												</td>
											))}
										</tr>
									))
								: links.map((link: LinkRecord) => (
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
											onOpenHealthModal={
												handleOpenHealthModal
											}
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
				setOpen={setHealthModalOpen}
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
				setOpen={setDeleteModalOpen}
				link={selectedLink}
				isTrashTab={isTrashTab}
			/>
			<BulkDeleteModal
				open={bulkDeleteOpen && isConfirmationValid}
				setOpen={closeBulkDelete}
				afterLeave={() => {
					if (!bulkDeleteOpen) setBulkDeleteConfirmation(null);
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
		</section>
	);
};

export default LinksTable;
