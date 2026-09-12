import { RotateCcw, Trash2 } from "lucide-react";
import { __, sprintf } from "@/i18n";

import type { TableHeaderRowProps } from "../types";

function TableHeaderRow({
	selectedCount = 0,
	onSelectAll,
	onBulkDelete,
	onDeleteAll,
	onBulkRestore,
	onEmptyTrash,
	isTrashTab = false,
	trashedCount = 0,
	sortBy,
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
						aria-label={__("Deselect all links")}
					/>
				</th>
				<th colSpan={6} className="links-table-header-cell-actions">
					<div className="links-table-header-actions-group">
						<span className="links-table-selection-count">
							{sprintf(__("%s selected"), String(selectedCount))}
						</span>
						{isTrashTab ? (
							<>
								{onBulkRestore && (
									<button
										type="button"
										onClick={onBulkRestore}
										className="links-table-header-delete-selected text-primary-600 hover:text-primary-700"
									>
										<RotateCcw size={13} />
										<span>{__("Restore selected")}</span>
									</button>
								)}
								<button
									type="button"
									onClick={onBulkDelete}
									className="links-table-header-delete-selected"
								>
									<Trash2 size={13} />
									<span>{__("Delete permanently")}</span>
								</button>
								{onEmptyTrash && trashedCount > 0 && (
									<button
										type="button"
										onClick={onEmptyTrash}
										className="links-table-header-delete-all"
									>
										<Trash2 size={13} />
										<span>{__("Empty trash")}</span>
									</button>
								)}
							</>
						) : (
							<>
								<button
									type="button"
									onClick={onBulkDelete}
									className="links-table-header-delete-selected"
								>
									<Trash2 size={13} />
									<span>{__("Delete selected")}</span>
								</button>
								{onDeleteAll && (
									<button
										type="button"
										onClick={onDeleteAll}
										className="links-table-header-delete-all"
									>
										<Trash2 size={13} />
										<span>{__("Delete all")}</span>
									</button>
								)}
							</>
						)}
					</div>
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
			<th className="links-table-header-cell">
				{"updatedAt" === sortBy ? __("Modified") : __("Created")}
			</th>
			<th className="links-table-header-cell links-table-header-cell-actions">
				{__("Actions")}
			</th>
		</tr>
	);
}

export default TableHeaderRow;
