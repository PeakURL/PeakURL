import {
	QrCode,
	BarChart3,
	Pencil,
	Trash2,
	Copy,
	Check,
	Lock,
	Clock,
	Link2,
} from 'lucide-react';
import { isPast } from 'date-fns';
import { __ } from '@/i18n';
import { formatRelativeTime, getLinkDisplayTitle } from '@/utils';
import type { LinkRowProps } from '../types';

function LinkRow({
	link,
	selected,
	onSelectRow,
	onCopy,
	copiedId,
	onOpenStats,
	onEdit,
	onDelete,
	onQRCode,
	formatNumber,
}: LinkRowProps) {
	const statusLabel =
		'active' === link.status
			? __('Active')
			: 'inactive' === link.status
				? __('Inactive')
				: 'expired' === link.status
					? __('Expired')
					: __('Unknown');
	const statusColorClass =
		'active' === link.status
			? 'text-success'
			: 'expired' === link.status
				? 'text-error'
				: 'text-text-muted';
	const statusDotClass =
		'active' === link.status
			? 'bg-success'
			: 'expired' === link.status
				? 'bg-error'
				: 'bg-stroke';
	const expiresAtDate = link.expiresAt ? new Date(link.expiresAt) : null;
	const isExpiredLink = expiresAtDate ? isPast(expiresAtDate) : false;
	const expirationRelativeTime = expiresAtDate
		? formatRelativeTime(expiresAtDate, {
				style: 'long',
				numeric: 'always',
			})
		: '';

	return (
		<tr
			className={`links-row group ${
				selected ? 'links-row-selected' : ''
			}`}
		>
			<td className="links-row-cell">
				<input
					type="checkbox"
					checked={selected}
					onChange={() => onSelectRow(link.id)}
					className="links-checkbox"
				/>
			</td>
			<td className="links-row-cell">
				<div className="links-row-link">
					<div className="links-row-link-icon">
						<Link2 className="h-3 w-3 text-accent" />
					</div>
					<div className="min-w-0">
						<div className="flex items-center gap-1.5">
							<code
								className="preserve-ltr-value text-sm font-mono font-semibold text-accent"
							>
								/{link.alias || link.shortCode}
							</code>
							<button
								onClick={() => onCopy(link)}
								className="links-row-link-copy"
								title={
									copiedId === link.id
										? __('Copied!')
										: __('Copy')
								}
							>
								{copiedId === link.id ? (
									<Check size={12} className="text-success" />
								) : (
									<Copy size={12} />
								)}
							</button>
						</div>
					</div>
				</div>
			</td>
			<td className="links-row-cell">
				<div className="links-row-title">
					{getLinkDisplayTitle(link.title, __('Untitled Link'))}
				</div>
			</td>
			<td className="links-row-cell">
				<div className="links-row-destination">
					<div
						className="links-row-destination-value preserve-ltr-value"
						title={link.destinationUrl}
					>
						{link.destinationUrl}
					</div>
					<div className="links-row-meta">
						{link.hasPassword && (
							<div key="password" className="flex items-center gap-1.5">
								<span className="links-row-badge links-row-badge-warning">
									<Lock size={10} />
									{__('Protected')}
								</span>
							</div>
						)}
						{link.expiresAt && (
							<span
								key="expires"
								className={`links-row-badge ${
									isExpiredLink
										? 'links-row-badge-error'
										: 'links-row-badge-info'
								}`}
							>
								<Clock size={10} />
								<span className="links-row-badge-copy" dir="auto">
									{isExpiredLink ? __('Expired') : __('Expires')}{' '}
									<bdi className="links-row-badge-time">
										{expirationRelativeTime}
									</bdi>
								</span>
							</span>
						)}
					</div>
				</div>
			</td>
			<td className="links-row-cell">
				<div className="links-row-performance">
					<div className="links-row-performance-block">
						<div className="links-row-performance-value">
							{formatNumber(link.clicks || 0)}
						</div>
						<div className="links-row-performance-label">
							{__('Total')}
						</div>
					</div>
					<div className="links-row-performance-divider"></div>
					<div className="links-row-performance-block">
						<div className="links-row-performance-value-accent">
							{formatNumber(link.uniqueClicks || 0)}
						</div>
						<div className="links-row-performance-label">
							{__('Unique')}
						</div>
					</div>
				</div>
			</td>
			<td className="links-row-cell">
				<div className="links-row-created">
					{link.createdAt
						? formatRelativeTime(new Date(link.createdAt), {
								style: 'compact',
								numeric: 'always',
							})
						: __('Unknown')}
				</div>
				<div className="links-row-status">
					<span
						className={`links-row-status-dot ${statusDotClass}`}
					></span>
					<span
						className={`links-row-status-label ${statusColorClass}`}
					>
						{statusLabel}
					</span>
				</div>
			</td>
			<td className="links-row-cell">
				<div className="links-row-actions">
					<button
						onClick={() => onQRCode(link)}
						className="links-row-action links-row-action-qr"
						title={__('QR Code')}
					>
						<QrCode size={14} />
					</button>
					<button
						onClick={() => onOpenStats(link)}
						className="links-row-action links-row-action-stats"
						title={__('Analytics')}
					>
						<BarChart3 size={14} />
					</button>
					<button
						onClick={() => onEdit(link)}
						className="links-row-action links-row-action-edit"
						title={__('Edit')}
					>
						<Pencil size={14} />
					</button>
					<button
						onClick={() => onDelete(link)}
						className="links-row-action links-row-action-delete"
						title={__('Delete')}
					>
						<Trash2 size={14} />
					</button>
				</div>
			</td>
		</tr>
	);
}

export default LinkRow;
