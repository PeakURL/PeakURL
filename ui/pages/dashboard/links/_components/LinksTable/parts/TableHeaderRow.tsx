import { __, sprintf } from "@/i18n";
import type { TableHeaderRowProps } from "../types";

function TableHeaderRow({
	selectedCount = 0,
	onSelectAll,
	onBulkDelete,
}: TableHeaderRowProps) {
	const hasSelection = selectedCount > 0;
	if (hasSelection) {
		return (
			<tr className="links-table-header-row links-table-header-row-selected">
				<th className="links-table-header-cell links-table-header-cell-select">
					<input
						type="checkbox"
						checked
						onChange={onSelectAll}
						className="links-checkbox"
					/>
				</th>
				<th colSpan={5} className="links-table-header-cell-count">
					{sprintf(__("%s selected"), String(selectedCount))}
				</th>
				<th className="links-table-header-cell-actions">
					<button
						onClick={onBulkDelete}
						className="links-table-header-delete"
					>
						{__("Delete Selected")}
					</button>
				</th>
			</tr>
		);
	}

	return (
		<tr className="links-table-header-row">
			<th className="links-table-header-cell links-table-header-cell-select">
				<input
					type="checkbox"
					checked={false}
					onChange={onSelectAll}
					className="links-checkbox"
				/>
			</th>
			<th className="links-table-header-cell">{__("Link")}</th>
			<th className="links-table-header-cell">{__("Title")}</th>
			<th className="links-table-header-cell">{__("Destination")}</th>
			<th className="links-table-header-cell links-table-header-cell-performance">
				{__("Performance")}
			</th>
			<th className="links-table-header-cell">{__("Created")}</th>
			<th className="links-table-header-cell links-table-header-cell-actions">
				{__("Actions")}
			</th>
		</tr>
	);
}

export default TableHeaderRow;
