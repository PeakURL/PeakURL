// @ts-nocheck
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { formatDistanceToNow } from 'date-fns';
import { buildShortUrl, formatNumber, getDefaultShortUrlOrigin } from '@/utils';
import StatsDrawer from '../StatsDrawer';
import QRCodeModal from '../QRCodeModal';
import EditLinkModal from '../EditLinkModal';
import DeleteLinkModal from '../DeleteLinkModal';
import BulkDeleteModal from '../BulkDeleteModal';
import TableHeaderRow from './parts/TableHeaderRow';
import LinkRow from './parts/LinkRow';
import EmptyState from './parts/EmptyState';

const LinksTable = ({ links, statsShortId, statsLink }) => {
	const [copiedId, setCopiedId] = useState(null);
	const [statsDrawerOpen, setStatsDrawerOpen] = useState(false);
	const [qrModalOpen, setQrModalOpen] = useState(false);
	const [editModalOpen, setEditModalOpen] = useState(false);
	const [deleteModalOpen, setDeleteModalOpen] = useState(false);
	const [bulkDeleteModalOpen, setBulkDeleteModalOpen] = useState(false);
	const [selectedLink, setSelectedLink] = useState(null);
	const [selectedIds, setSelectedIds] = useState([]);
	const [revealedPasswords, setRevealedPasswords] = useState({});
	const [searchParams, setSearchParams] = useSearchParams();

	useEffect(() => {
		if (!statsShortId) return;

		const link =
			links.find(
				(l) => l.shortCode === statsShortId || l.alias === statsShortId
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

	const handleCopy = async (link) => {
		const shortUrl = buildShortUrl(
			link,
			getDefaultShortUrlOrigin(
				typeof window !== 'undefined' ? window.location.origin : ''
			)
		);
		try {
			await navigator.clipboard.writeText(shortUrl);
			setCopiedId(link.id);
			setTimeout(() => setCopiedId(null), 2000);
		} catch (err) {
			console.error('Failed to copy:', err);
		}
	};

	const handleOpenStats = (link) => {
		setSelectedLink(link);
		setStatsDrawerOpen(true);
	};

	const handleDelete = (link) => {
		setSelectedLink(link);
		setDeleteModalOpen(true);
	};

	const handleEdit = (link) => {
		setSelectedLink(link);
		setEditModalOpen(true);
	};

	const handleQRCode = (link) => {
		setSelectedLink(link);
		setQrModalOpen(true);
	};

	const togglePassword = (id) => {
		setRevealedPasswords((prev) => ({
			...prev,
			[id]: !prev[id],
		}));
	};

	const handleSelectAll = (e) => {
		if (e.target.checked) {
			setSelectedIds(links.map((link) => link.id));
		} else {
			setSelectedIds([]);
		}
	};

	const handleSelectRow = (id) => {
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
			<div className="overflow-x-auto">
				<table className="w-full">
					<thead className="bg-surface-alt border-b border-(--color-stroke)">
						<TableHeaderRow
							selectedCount={selectedIds.length}
							onSelectAll={handleSelectAll}
							onBulkDelete={handleBulkDelete}
						/>
					</thead>
					<tbody className="divide-y divide-(--color-stroke)">
						{links.map((link) => (
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
								revealedPasswords={revealedPasswords}
								togglePassword={togglePassword}
								formatDistanceToNow={formatDistanceToNow}
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
