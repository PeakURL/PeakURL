import type { ChangeEvent } from "react";
import { useEffect, useState } from "react";
import { useSearchParams } from "react-router";
import { Search, X } from "lucide-react";

import { ConfirmDialog } from "@/components";
import { __ } from "@/i18n";
import { useAdminAccess } from "@/hooks";
import { copyToClipboard } from "@/shared/browser";
import { formatCount, formatNumber } from "@/shared/formatting";
import { getShortUrl } from "@/shared/links";

import StatsDrawer from "../StatsDrawer";
import QRCodeModal from "../QRCodeModal";
import EditLinkDrawer from "../EditLinkDrawer";
import DeleteLinkModal from "../DeleteLinkModal";
import BulkDeleteModal from "../BulkDeleteModal";
import DeleteAllModal from "../DeleteAllModal";
import TableHeaderRow from "./parts/TableHeaderRow";
import LinkRow from "./parts/LinkRow";
import EmptyState from "./parts/EmptyState";
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
	clickRange,
	customClickRange,
	isTrashTab = false,
	trashedCount = 0,
	onRestore,
	onBulkRestore,
	onEmptyTrash,
}: LinksTableProps) => {
	const [copiedId, setCopiedId] = useState<string | null>(null);
	const [statsDrawerOpen, setStatsDrawerOpen] = useState(false);
	const [qrModalOpen, setQrModalOpen] = useState(false);
	const [editDrawerOpen, setEditDrawerOpen] = useState(false);
	const [deleteModalOpen, setDeleteModalOpen] = useState(false);
	const [bulkDeleteModalOpen, setBulkDeleteModalOpen] = useState(false);
	const [deleteAllModalOpen, setDeleteAllModalOpen] = useState(false);
	const [emptyTrashModalOpen, setEmptyTrashModalOpen] = useState(false);
	const [selectedLink, setSelectedLink] = useState<LinkRecord | null>(null);
	const [selectedIds, setSelectedIds] = useState<string[]>([]);
	const [searchParams, setSearchParams] = useSearchParams();
	const { canDeleteLinks, canTrashLinks, user } = useAdminAccess();

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
		setSelectedLink(link);
		setStatsDrawerOpen(true);
	};

	const handleDelete = (link: LinkRecord) => {
		setSelectedLink(link);
		setDeleteModalOpen(true);
	};

	const handleEdit = (link: LinkRecord) => {
		setSelectedLink(link);
		setEditDrawerOpen(true);
	};

	const handleQRCode = (link: LinkRecord) => {
		setSelectedLink(link);
		setQrModalOpen(true);
	};

	const handleSelectAll = (e: ChangeEvent<HTMLInputElement>) => {
		if (e.target.checked) {
			setSelectedIds(links.map((link: LinkRecord) => link.id));
		} else {
			setSelectedIds([]);
		}
	};

	const handleSelectRow = (id: string) => {
		setSelectedIds((prev) =>
			prev.includes(id)
				? prev.filter((item) => item !== id)
				: [...prev, id]
		);
	};

	const handleBulkDelete = () => {
		setBulkDeleteModalOpen(true);
	};

	const handleBulkDeleteSuccess = () => {
		setSelectedIds([]);
	};

	const handleBulkRestoreAction = async () => {
		if (selectedIds.length === 0) return;
		try {
			if (onBulkRestore) {
				await onBulkRestore(selectedIds);
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
						{isTrashTab ? __("Trashed Links") : __("All Links")}
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
							className="w-full rounded-lg border border-stroke bg-surface ps-9 pe-8 py-1.5 text-xs text-heading placeholder:text-text-muted/60 transition-colors focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
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
				/>
			) : (
				<div className="links-table-scroll">
					<table className="links-table-element">
						<thead className="links-table-head">
							<TableHeaderRow
								selectedCount={selectedIds.length}
								onSelectAll={handleSelectAll}
								onBulkDelete={
									(
										isTrashTab
											? canDeleteLinks
											: canTrashLinks || canDeleteLinks
									)
										? handleBulkDelete
										: undefined
								}
								onDeleteAll={
									isTrashTab ||
									(!canTrashLinks && !canDeleteLinks)
										? undefined
										: () => setDeleteAllModalOpen(true)
								}
								onBulkRestore={handleBulkRestoreAction}
								onEmptyTrash={
									isTrashTab && onEmptyTrash
										? () => setEmptyTrashModalOpen(true)
										: undefined
								}
								isTrashTab={isTrashTab}
								trashedCount={trashedCount}
								sortBy={sortBy}
								canDeleteLinks={canDeleteLinks}
								canTrashLinks={canTrashLinks}
							/>
						</thead>
						<tbody className="links-table-body">
							{links.map((link: LinkRecord) => (
								<LinkRow
									key={link.id}
									link={link}
									selected={selectedIds.includes(link.id)}
									onSelectRow={handleSelectRow}
									onCopy={handleCopy}
									copiedId={copiedId}
									onOpenStats={handleOpenStats}
									onEdit={handleEdit}
									onDelete={handleDelete}
									onRestore={onRestore}
									onQRCode={handleQRCode}
									formatNumber={formatNumber}
									isTrashTab={isTrashTab}
									sortBy={sortBy}
									canDeleteLinks={canDeleteLinks}
									canTrashLinks={canTrashLinks}
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
				open={bulkDeleteModalOpen && selectedIds.length > 0}
				setOpen={setBulkDeleteModalOpen}
				selectedIds={selectedIds}
				isTrashTab={isTrashTab}
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
