import type { LucideIcon } from "lucide-react";

export type {
	BulkCreateErrorItem,
	BulkCreateResponse,
	BulkCreateSuccessItem,
	ImportRecord,
	PasteImportRequestItem,
} from "@/api";

/**
 * Represents the current state of an import process.
 *
 * Tracks whether the import UI is idle, uploading data, processing rows,
 * or displaying the completed result set.
 */
export type ImportStatus = "idle" | "uploading" | "processing" | "completed";

/**
 * Result of processing a single imported item.
 */
export interface ImportResult {
	/** Original URL provided for import */
	url: string;

	/** Custom alias or short code for the URL */
	alias: string;

	/** Result status of the import operation */
	status: "success" | "error";

	/** Generated short URL (available on success) */
	shortUrl?: string;

	/** Error message (available if status is "error") */
	error?: string;
}

/**
 * Represents a sample row used for previewing or validating import data.
 */
export interface SampleRow {
	/** URL to be shortened */
	url: string;

	/** Desired alias for the short link */
	alias: string;

	/** Optional title or label for the link */
	title: string;
}

/**
 * Represents a tab in the import interface.
 */
export interface ImportTab {
	/** Unique identifier for the tab */
	id: "file" | "api" | "paste";

	/** Display name shown in the UI */
	name: string;

	/** Icon component associated with the tab */
	icon: LucideIcon;
}

/**
 * Props for the import tab switcher.
 */
export interface TabsProps {
	/** Available import tabs rendered in the switcher. */
	tabs: ImportTab[];

	/** Identifier for the currently selected tab. */
	activeTab: ImportTab["id"];
}

/**
 * Props for the import summary card shown after processing completes.
 */
export interface ImportSummaryProps {
	/** Result rows created by the completed import. */
	results: ImportResult[];

	/** Resets the import surface back to its initial state. */
	onReset: () => void;
}

/**
 * Props for the detailed import results list.
 */
export interface ImportDetailsProps {
	/** Result rows rendered in the detailed summary. */
	results: ImportResult[];
}
