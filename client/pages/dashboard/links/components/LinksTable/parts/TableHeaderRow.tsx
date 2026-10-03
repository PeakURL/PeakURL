import { RotateCcw, Trash2 } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { cn } from "@/shared/formatting";

import type { LinksSortBy, LinksSortOrder } from "../../types";
import type { TableHeaderRowProps } from "../types";

interface SortableHeaderButtonProps {
	label: string;
	targetSort: LinksSortBy;
	activeSort?: LinksSortBy;
	sortOrder?: LinksSortOrder;
	onSort?: (sortBy: LinksSortBy, sortOrder: LinksSortOrder) => void;
	className?: string;
	align?: "start" | "center";
}

function WordPressSortIndicator({
	isActive,
	sortOrder,
}: {
	isActive: boolean;
	sortOrder: LinksSortOrder;
}) {
	const isAsc = isActive && sortOrder === "asc";
	const isDesc = isActive && sortOrder === "desc";

	return (
		<span className="links-table-sort-indicator" aria-hidden="true">
			<svg
				width="8"
				height="12"
				viewBox="0 0 8 12"
				fill="none"
				xmlns="http://www.w3.org/2000/svg"
				className="block"
			>
				{/* Top triangle (ASC) */}
				<path
					d="M4 1L7.5 5H0.5L4 1Z"
					fill="currentColor"
					className={
						isAsc
							? "links-table-sort-triangle-active"
							: "links-table-sort-triangle-inactive"
					}
				/>
				{/* Bottom triangle (DESC) */}
				<path
					d="M4 11L0.5 7H7.5L4 11Z"
					fill="currentColor"
					className={
						isDesc
							? "links-table-sort-triangle-active"
							: "links-table-sort-triangle-inactive"
					}
				/>
			</svg>
		</span>
	);
}

function SortableHeaderButton({
	label,
	targetSort,
	activeSort,
	sortOrder = "desc",
	onSort,
	className = "",
	align = "start",
}: SortableHeaderButtonProps) {
	const isActive =
		targetSort === "createdAt"
			? activeSort === "createdAt" || activeSort === "updatedAt"
			: activeSort === targetSort;

	const handleClick = () => {
		if (!onSort) return;
		if (isActive) {
			const nextOrder = sortOrder === "asc" ? "desc" : "asc";
			const nextSort =
				targetSort === "createdAt" && activeSort === "updatedAt"
					? "updatedAt"
					: targetSort;
			onSort(nextSort, nextOrder);
		} else {
			const defaultOrder =
				targetSort === "title" || targetSort === "health"
					? "asc"
					: "desc";
			onSort(targetSort, defaultOrder);
		}
	};

	return (
		<button
			type="button"
			onClick={handleClick}
			className={cn(
				"links-table-header-sort-btn",
				align === "center" ? "justify-center" : "justify-start",
				className
			)}
		>
			<span>{label}</span>
			<WordPressSortIndicator isActive={isActive} sortOrder={sortOrder} />
		</button>
	);
}

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
	sortOrder = "desc",
	onSortChange,
	canDeleteLinks,
	canTrashLinks,
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
				<th colSpan={7} className="links-table-header-cell-actions">
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
								{canDeleteLinks && onBulkDelete && (
									<button
										type="button"
										onClick={onBulkDelete}
										className="links-table-header-delete-selected"
									>
										<Trash2 size={13} />
										<span>{__("Delete permanently")}</span>
									</button>
								)}
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
								{(canTrashLinks || canDeleteLinks) &&
									onBulkDelete && (
										<button
											type="button"
											onClick={onBulkDelete}
											className="links-table-header-delete-selected"
										>
											<Trash2 size={13} />
											<span>
												{!canTrashLinks &&
												canDeleteLinks
													? __("Delete permanently")
													: __("Delete selected")}
											</span>
										</button>
									)}
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
					aria-label={__("Select all links")}
				/>
			</th>
			<th className="links-table-header-cell">{__("Link")}</th>
			<th className="links-table-header-cell">
				<SortableHeaderButton
					label={__("Title")}
					targetSort="title"
					activeSort={sortBy}
					sortOrder={sortOrder}
					onSort={onSortChange}
				/>
			</th>
			<th className="links-table-header-cell">{__("Destination")}</th>
			<th className="links-table-header-cell links-table-header-cell-health">
				<SortableHeaderButton
					label={__("Health")}
					targetSort="health"
					activeSort={sortBy}
					sortOrder={sortOrder}
					onSort={onSortChange}
				/>
			</th>
			<th className="links-table-header-cell links-table-header-cell-performance">
				<SortableHeaderButton
					label={__("Performance")}
					targetSort="clicks"
					activeSort={sortBy}
					sortOrder={sortOrder}
					onSort={onSortChange}
					align="center"
					className="w-full"
				/>
			</th>
			<th className="links-table-header-cell links-table-header-cell-created">
				<SortableHeaderButton
					label={
						"updatedAt" === sortBy ? __("Modified") : __("Created")
					}
					targetSort="createdAt"
					activeSort={sortBy}
					sortOrder={sortOrder}
					onSort={onSortChange}
					align="center"
					className="w-full"
				/>
			</th>
			<th className="links-table-header-cell links-table-header-cell-actions">
				{__("Actions")}
			</th>
		</tr>
	);
}

export default TableHeaderRow;
