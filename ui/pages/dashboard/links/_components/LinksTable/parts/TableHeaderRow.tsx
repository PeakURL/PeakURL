// @ts-nocheck
function TableHeaderRow({ selectedCount = 0, onSelectAll, onBulkDelete }) {
	const hasSelection = selectedCount > 0;
	if (hasSelection) {
		return (
			<tr className="text-left bg-accent/5">
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
					{selectedCount} selected
				</th>
				<th className="px-4 py-3 text-right">
					<button
						onClick={onBulkDelete}
						className="text-error hover:text-red-700 font-medium text-xs flex items-center justify-end gap-1 ml-auto"
					>
						Delete Selected
					</button>
				</th>
			</tr>
		);
	}

	return (
		<tr className="text-left">
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide w-[40px]">
				<input
					type="checkbox"
					checked={false}
					onChange={onSelectAll}
					className="rounded border-stroke text-accent focus:ring-accent focus:ring-2"
				/>
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide">
				Link
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide">
				Title
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide">
				Destination
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide text-center">
				Performance
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide">
				Created
			</th>
			<th className="px-4 py-3 text-xs font-semibold text-text-muted uppercase tracking-wide text-right">
				Actions
			</th>
		</tr>
	);
}

export default TableHeaderRow;
