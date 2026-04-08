import { __, sprintf } from '@/i18n';
import type { TableHeaderRowProps } from '../types';

function TableHeaderRow({
	selectedCount = 0,
	onSelectAll,
	onBulkDelete,
}: TableHeaderRowProps) {
	const hasSelection = selectedCount > 0;
	if (hasSelection) {
		return (
			<tr className="logical-text-start bg-accent/5">
				<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide w-[40px]">
					<input
						type="checkbox"
						checked
						onChange={onSelectAll}
						className="rounded border-stroke text-accent focus:ring-accent focus:ring-2"
					/>
				</th>
				<th
					colSpan={5}
					className="px-4 py-3 text-sm font-medium text-accent"
				>
					{sprintf(__('%s selected'), String(selectedCount))}
				</th>
				<th className="logical-text-end px-4 py-3">
					<button
						onClick={onBulkDelete}
						className="ml-auto flex items-center gap-1 text-xs font-medium text-error hover:text-red-700"
					>
						{__('Delete Selected')}
					</button>
				</th>
			</tr>
		);
	}

	return (
		<tr className="logical-text-start">
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide w-[40px]">
				<input
					type="checkbox"
					checked={false}
					onChange={onSelectAll}
					className="rounded border-stroke text-accent focus:ring-accent focus:ring-2"
				/>
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide">
				{__('Link')}
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide">
				{__('Title')}
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide">
				{__('Destination')}
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide text-center">
				{__('Performance')}
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide">
				{__('Created')}
			</th>
			<th
				className="logical-text-end px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide"
			>
				{__('Actions')}
			</th>
		</tr>
	);
}

export default TableHeaderRow;
