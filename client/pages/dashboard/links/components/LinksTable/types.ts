import type { ChangeEvent } from "react";
import type {
	LinkRecord,
	LinksCustomDateRange,
	LinksDateRange,
	LinksSortBy,
} from "../types";

/**
 * Props for the links table container component.
 */
export interface LinksTableProps {
	links: LinkRecord[];
	statsShortId: string | null;
	statsLink: LinkRecord | null;
	sortBy?: LinksSortBy;
	clickRange?: LinksDateRange;
	customClickRange?: LinksCustomDateRange;
	isTrashTab?: boolean;
	trashedCount?: number;
	onRestore?: (link: LinkRecord) => Promise<void> | void;
	onBulkRestore?: (ids: string[]) => Promise<void> | void;
	onEmptyTrash?: () => Promise<void> | void;
}

/**
 * Props for a single rendered link row.
 */
export interface LinkRowProps {
	link: LinkRecord;
	selected: boolean;
	onSelectRow: (id: string) => void;
	onCopy: (link: LinkRecord) => Promise<void> | void;
	copiedId: string | null;
	onOpenStats: (link: LinkRecord) => void;
	onEdit: (link: LinkRecord) => void;
	onDelete: (link: LinkRecord) => void;
	onRestore?: (link: LinkRecord) => void;
	onQRCode: (link: LinkRecord) => void;
	formatNumber: (value: number) => string;
	isTrashTab?: boolean;
	sortBy?: LinksSortBy;
}

/**
 * Props for the links table header row.
 */
export interface TableHeaderRowProps {
	selectedCount?: number;
	onSelectAll: (event: ChangeEvent<HTMLInputElement>) => void;
	onBulkDelete: () => void;
	onDeleteAll?: () => void;
	onBulkRestore?: () => void;
	onEmptyTrash?: () => void;
	isTrashTab?: boolean;
	trashedCount?: number;
	sortBy?: LinksSortBy;
}
