import type { LucideIcon } from 'lucide-react';

/**
 * Represents the current state of an import process.
 *
 * Tracks whether the import UI is idle, uploading data, processing rows,
 * or displaying the completed result set.
 */
export type ImportStatus = 'idle' | 'uploading' | 'processing' | 'completed';

/**
 * Result of processing a single imported item.
 */
export interface ImportResult {
	/** Original URL provided for import */
	url: string;

	/** Custom alias or short code for the URL */
	alias: string;

	/** Result status of the import operation */
	status: 'success' | 'error';

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
	id: 'file' | 'api' | 'paste';

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
	activeTab: ImportTab['id'];
}

/**
 * Request item built from pasted text before it is sent to the API.
 */
export interface PasteImportRequestItem {
	/** Destination URL parsed from the pasted line. */
	destinationUrl: string;

	/** Optional alias parsed from the same line. */
	alias?: string;
}

/**
 * Successful item returned by the paste-import mutation.
 */
export interface BulkCreateSuccessItem {
	/** Destination URL created successfully. */
	destinationUrl: string;

	/** Alias saved for the new short link, when present. */
	alias?: string;

	/** Generated short code returned by the API. */
	shortCode?: string;

	/** Canonical short URL returned by the API, when available. */
	shortUrl?: string;
}

/**
 * Failed item returned by the paste-import mutation.
 */
export interface BulkCreateErrorItem {
	/** Destination URL that failed to import. */
	destinationUrl: string;

	/** Alias associated with the failed row, when provided. */
	alias?: string;

	/** Human-readable API error message for the failed row. */
	error?: string;
}

/**
 * Response wrapper returned by the paste-import mutation.
 */
export interface BulkCreateResponse {
	/** Response payload containing success and failure collections. */
	data?: {
		/** Successfully created short-link records. */
		results?: BulkCreateSuccessItem[];

		/** Failed records returned by the API. */
		errors?: BulkCreateErrorItem[];
	};
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
