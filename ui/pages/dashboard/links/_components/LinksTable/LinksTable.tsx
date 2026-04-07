import type { ChangeEvent } from 'react';
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { buildShortUrl, copyToClipboard, formatNumber } from '@/utils';
import StatsDrawer from '../StatsDrawer';
import QRCodeModal from '../QRCodeModal';
import EditLinkModal from '../EditLinkModal';
import DeleteLinkModal from '../DeleteLinkModal';
import BulkDeleteModal from '../BulkDeleteModal';
import TableHeaderRow from './parts/TableHeaderRow';
import LinkRow from './parts/LinkRow';
import EmptyState from './parts/EmptyState';
import type { LinkRecord } from '../types';
import type { LinksTableProps } from './types';

const LinksTable = ({ links, statsShortId, statsLink }: LinksTableProps) => {
	const [copiedId, setCopiedId] = useState<string | null>(null);
	const [statsDrawerOpen, setStatsDrawerOpen] = useState(false);
	const [qrModalOpen, setQrModalOpen] = useState(false);
	const [editModalOpen, setEditModalOpen] = useState(false);
	const [deleteModalOpen, setDeleteModalOpen] = useState(false);
	const [bulkDeleteModalOpen, setBulkDeleteModalOpen] = useState(false);
	const [selectedLink, setSelectedLink] = useState<LinkRecord | null>(null);
	const [selectedIds, setSelectedIds] = useState<string[]>([]);
	const [searchParams, setSearchParams] = useSearchParams();

	useEffect(() => {
		if (!statsShortId) return;

		const link =
			links.find(
				(linkItem: LinkRecord) =>
					linkItem.shortCode === statsShortId ||
					linkItem.alias === statsShortId
			) || statsLink;

		if (!link) return;

		setTimeout(() => {
			setSelectedLink(link);
			setStatsDrawerOpen(true);
		}, 0);

		const params = new URLSearchParams(searchParams.toString());
		params.delete('stats');
		setSearchParams(params, { replace: true });
	}, [statsShortId, links, statsLink, searchParams, setSearchParams]);

	const handleCopy = async (link: LinkRecord) => {
		const shortUrl = buildShortUrl(link);
		try {
			await copyToClipboard(shortUrl);
			setCopiedId(link.id);
			setTimeout(() => setCopiedId(null), 2000);
		} catch (err) {
			console.error('Failed to copy:', err);
		}
	};

	const handleOpenStats = (link: LinkRecord) => {
		setSelectedLink(link);
		setStatsDrawerOpen(true);
	};

	const handleDelete = (link: LinkRecord) => {
		setSelectedLink(link);
		setDeleteModalOpen(true);
	};

	const handleEdit = (link: LinkRecord) => {
		setSelectedLink(link);
		setEditModalOpen(true);
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

	if (!links || links.length === 0) {
		return <EmptyState />;
	}

	return (
		<div className="bg-surface rounded-lg border border-(--color-stroke) overflow-hidden">
			<div className="overflow-x-auto overscroll-x-contain">
				<table className="min-w-220 w-full">
					<thead className="bg-surface-alt border-b border-(--color-stroke)">
						<TableHeaderRow
							selectedCount={selectedIds.length}
							onSelectAll={handleSelectAll}
							onBulkDelete={handleBulkDelete}
						/>
					</thead>
					<tbody className="divide-y divide-(--color-stroke)">
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
								onQRCode={handleQRCode}
								formatNumber={formatNumber}
							/>
						))}
					</tbody>
				</table>
			</div>

			<StatsDrawer
				open={statsDrawerOpen}
				setOpen={setStatsDrawerOpen}
				link={selectedLink}
			/>
			<QRCodeModal
				open={qrModalOpen}
				setOpen={setQrModalOpen}
				link={selectedLink}
			/>
			{editModalOpen && selectedLink && (
				<EditLinkModal
					key={selectedLink.id}
					open={editModalOpen}
					setOpen={setEditModalOpen}
					link={selectedLink}
				/>
			)}
			<DeleteLinkModal
				open={deleteModalOpen}
				setOpen={setDeleteModalOpen}
				link={selectedLink}
			/>
			<BulkDeleteModal
				open={bulkDeleteModalOpen}
				setOpen={setBulkDeleteModalOpen}
				selectedIds={selectedIds}
				onSuccess={handleBulkDeleteSuccess}
			/>
		</div>
	);
};

export default LinksTable;
