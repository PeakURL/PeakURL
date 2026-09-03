import type { ChangeEvent } from "react";
import { useEffect, useState } from "react";
import { useSearchParams } from "react-router";

import { ConfirmDialog, useNotification } from "@/components";
import { useClearUrlsMutation } from "@/store/slices/api";
import { __ } from "@/i18n";
import {
	getShortUrl,
	copyToClipboard,
	formatNumber,
	getErrorMessage,
} from "@/utils";

import StatsDrawer from "../StatsDrawer";
import QRCodeModal from "../QRCodeModal";
import EditLinkDrawer from "../EditLinkDrawer";
import DeleteLinkModal from "../DeleteLinkModal";
import BulkDeleteModal from "../BulkDeleteModal";
import TableHeaderRow from "./parts/TableHeaderRow";
import LinkRow from "./parts/LinkRow";
import EmptyState from "./parts/EmptyState";
import type { LinkRecord } from "../types";
import type { LinksTableProps } from "./types";

const LinksTable = ({
	links,
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
	const notifications = useNotification();
	const [clearUrls, { isLoading: isDeletingAll }] = useClearUrlsMutation();

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

	const handleDeleteAll = async () => {
		if (isDeletingAll) {
			return;
		}

		setDeleteAllModalOpen(false);
		setSelectedIds([]);

		try {
			await clearUrls().unwrap();
			notifications.success(
				__("Links deleted"),
				__("All links have been removed.")
			);
		} catch (err) {
			notifications.error(
				__("Unable to delete links"),
				getErrorMessage(err, __("Failed to delete all links."))
			);
		}
	};

	if (!links || links.length === 0) {
		return <EmptyState />;
	}

	return (
		<div className="links-table">
			<div className="links-table-scroll">
				<table className="links-table-element">
					<thead className="links-table-head">
						<TableHeaderRow
							selectedCount={selectedIds.length}
							onSelectAll={handleSelectAll}
							onBulkDelete={handleBulkDelete}
							onDeleteAll={
								isTrashTab
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
							/>
						))}
					</tbody>
				</table>
			</div>

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
			<ConfirmDialog
				open={deleteAllModalOpen}
				onClose={() => setDeleteAllModalOpen(false)}
				title={__("Delete all links")}
				description={__(
					"Are you sure you want to delete all links? This will move active links to trash."
				)}
				confirmText={__("Delete all links")}
				confirmVariant="danger"
				onConfirm={handleDeleteAll}
				loading={isDeletingAll}
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
