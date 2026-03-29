// @ts-nocheck
import {
	QrCode,
	BarChart3,
	Pencil,
	Trash2,
	Copy,
	Check,
	Lock,
	Clock,
	Eye,
	EyeOff,
	Link2,
} from 'lucide-react';
import { isPast } from 'date-fns';

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
	revealedPasswords,
	togglePassword,
	formatDistanceToNow,
	formatNumber,
}) {
	return (
		<tr
			className={`hover:bg-surface-alt/50 transition-colors group ${
				selected ? 'bg-accent/5' : ''
			}`}
		>
			<td className="px-4 py-3">
				<input
					type="checkbox"
					checked={selected}
					onChange={() => onSelectRow(link.id)}
					className="rounded border-stroke text-accent focus:ring-accent focus:ring-2"
				/>
			</td>
			<td className="px-4 py-3">
				<div className="flex items-center gap-2">
					<div className="w-8 h-8 rounded-lg bg-accent/10 flex items-center justify-center shrink-0">
						<Link2 className="h-3 w-3 text-accent" />
					</div>
					<div className="min-w-0">
						<div className="flex items-center gap-1.5">
							<code className="font-mono font-semibold text-accent text-sm">
								/{link.alias || link.shortCode}
							</code>
							<button
								onClick={() => onCopy(link)}
								className="opacity-0 group-hover:opacity-100 text-text-muted hover:text-accent transition-all"
								title={
									copiedId === link.id ? 'Copied!' : 'Copy'
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
			<td className="px-4 py-3">
				<div className="text-sm text-heading font-medium truncate max-w-[150px]">
					{link.title || 'Untitled'}
				</div>
			</td>
			<td className="px-4 py-3">
				<div className="max-w-xs">
					<div
						className="text-sm text-text-muted truncate"
						title={link.destinationUrl}
					>
						{link.destinationUrl}
					</div>
					<div className="flex flex-col items-start gap-1 mt-1">
						{link.password && (
							<div
								key="password"
								className="flex items-center gap-1.5"
							>
								<span className="inline-flex items-center gap-1 text-[10px] font-medium text-warning bg-warning/10 px-1.5 py-0.5 rounded">
									<Lock size={10} />
									Protected
								</span>
								<div className="flex items-center gap-1">
									<code className="text-[10px] text-text-text-muted">
										{revealedPasswords[link.id]
											? link.password
											: '••••••••'}
									</code>
									<button
										onClick={() => togglePassword(link.id)}
										className="text-text-muted hover:text-heading transition-colors"
									>
										{revealedPasswords[link.id] ? (
											<EyeOff size={10} />
										) : (
											<Eye size={10} />
										)}
									</button>
								</div>
							</div>
						)}
						{link.expiresAt && (
							<span
								key="expires"
								className={`inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded ${
									isPast(new Date(link.expiresAt))
										? 'text-error bg-error/10'
										: 'text-info bg-info/10'
								}`}
							>
								<Clock size={10} />
								{isPast(new Date(link.expiresAt))
									? 'Expired ' +
										formatDistanceToNow(
											new Date(link.expiresAt),
											{
												addSuffix: true,
											}
										)
									: 'Expires ' +
										formatDistanceToNow(
											new Date(link.expiresAt),
											{
												addSuffix: true,
											}
										)}
							</span>
						)}
					</div>
				</div>
			</td>
			<td className="px-4 py-3">
				<div className="flex items-center justify-center gap-3">
					<div className="text-center">
						<div className="text-base font-bold text-heading">
							{formatNumber(link.clicks || 0)}
						</div>
						<div className="text-[10px] text-text-muted">Total</div>
					</div>
					<div className="w-px h-8 bg-stroke"></div>
					<div className="text-center">
						<div className="text-base font-bold text-accent">
							{formatNumber(link.uniqueClicks || 0)}
						</div>
						<div className="text-[10px] text-text-muted">
							Unique
						</div>
					</div>
				</div>
			</td>
			<td className="px-4 py-3">
				<div className="text-sm text-text-muted">
					{link.createdAt
						? formatDistanceToNow(new Date(link.createdAt), {
								addSuffix: true,
							})
						: 'Unknown'}
				</div>
				<div className="flex items-center gap-1.5 mt-0.5">
					<span
						className={`w-1.5 h-1.5 rounded-full ${
							link.status === 'active'
								? 'bg-success'
								: 'bg-stroke'
						}`}
					></span>
					<span
						className={`text-xs font-medium capitalize ${
							link.status === 'active'
								? 'text-success'
								: 'text-text-muted'
						}`}
					>
						{link.status}
					</span>
				</div>
			</td>
			<td className="px-4 py-3">
				<div className="flex items-center justify-end gap-1">
					<button
						onClick={() => onQRCode(link)}
						className="w-7 h-7 flex items-center justify-center text-text-muted hover:text-accent hover:bg-accent/10 rounded-lg transition-all"
						title="QR Code"
					>
						<QrCode size={14} />
					</button>
					<button
						onClick={() => onOpenStats(link)}
						className="w-7 h-7 flex items-center justify-center text-text-muted hover:text-info hover:bg-info/10 rounded-lg transition-all"
						title="Analytics"
					>
						<BarChart3 size={14} />
					</button>
					<button
						onClick={() => onEdit(link)}
						className="w-7 h-7 flex items-center justify-center text-text-muted hover:text-warning hover:bg-warning/10 rounded-lg transition-all"
						title="Edit"
					>
						<Pencil size={14} />
					</button>
					<button
						onClick={() => onDelete(link)}
						className="w-7 h-7 flex items-center justify-center text-text-muted hover:text-error hover:bg-error/10 rounded-lg transition-all"
						title="Delete"
					>
						<Trash2 size={14} />
					</button>
				</div>
			</td>
		</tr>
	);
}

export default LinkRow;
