import type {
	ChangeEvent,
	Dispatch,
	DragEvent,
	MouseEvent,
	RefObject,
	SetStateAction,
} from "react";
import type { ImportStatus, SampleRow } from "../types";

/**
 * Single import record parsed from a file before it is sent to the API.
 */
export interface ImportRecord {
	/** Destination URL that should receive the short link. */
	destinationUrl: string;

	/** Optional requested alias or short code. */
	alias?: string;

	/** Optional password protection value. */
	password?: string;

	/** Optional expiration timestamp string. */
	expiresAt?: string;

	/** Optional display title for the link. */
	title?: string;
}

/**
 * Props for the file-upload import experience.
 */
export interface FileUploadProps {
	/** Current import lifecycle state. */
	importStatus: ImportStatus;

	/** Updates the import lifecycle state. */
	setImportStatus: Dispatch<SetStateAction<ImportStatus>>;

	/** Upload progress percentage shown during transfer. */
	importProgress: number;

	/** Sample rows displayed when no results are available yet. */
	sampleData: SampleRow[];
}

/**
 * Props for the drag-and-drop upload area.
 */
export interface FileUploadAreaProps {
	/** Hidden file input used to trigger the system picker. */
	fileInputRef: RefObject<HTMLInputElement | null>;

	/** Called once a file has been selected or dropped. */
	onFileSelected: (file: File) => void;

	/** Disables drag, drop, and file selection interactions. */
	disabled?: boolean;
}

/**
 * Supported downloadable example file formats.
 */
export type SampleFormat = "csv" | "json" | "xml";

/**
 * Props for the file-processing status panel.
 */
export interface ProcessingStatusProps {
	/** Current import status being displayed. */
	status: ImportStatus;

	/** Optional upload progress percentage. */
	progress?: number;
}

/**
 * Props for the sample-data preview table.
 */
export interface SampleDataProps {
	/** Example rows rendered for the selected import format. */
	sampleData: SampleRow[];
}

/**
 * Change handler used by the file input element.
 */
export type FileInputChangeHandler = (
	event: ChangeEvent<HTMLInputElement>
) => void;

/**
 * Drag handler used by the dropzone surface.
 */
export type FileDropHandler = (event: DragEvent<HTMLDivElement>) => void;

/**
 * Click handler used by the upload button.
 */
export type FileButtonClickHandler = (
	event: MouseEvent<HTMLButtonElement>
) => void;
