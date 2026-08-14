import type { LinkStatus, LinksSortBy, LinksSortOrder } from "@/api";

export type {
	LinkRecord,
	LinkStatus,
	LinksMeta,
	LinksSortBy,
	LinksSortOrder,
	UpdateUrlPayload,
} from "@/api";

export type LinksDateRange = "all" | "24h" | "7d" | "30d" | "custom";

/**
 * Date-only custom range used by links analytics controls.
 */
export interface LinksCustomDateRange {
	/** Inclusive start date in YYYY-MM-DD format. */
	from: string;

	/** Inclusive end date in YYYY-MM-DD format. */
	to: string;
}

/**
 * Props for inline success and error banners in the URL form.
 */
export interface StatusMessagesProps {
	error?: string;
	success?: string;
}

/**
 * Props for the main destination and alias inputs.
 */
export interface MainInputsProps {
	destinationUrl: string;
	setDestinationUrl: (value: string) => void;
	alias: string;
	setAlias: (value: string) => void;
	isLoading: boolean;
}

/**
 * Props for password and title fields in the advanced URL form.
 */
export interface SecurityFieldsProps {
	title: string;
	setTitle: (value: string) => void;
	password: string;
	setPassword: (value: string) => void;
}

/**
 * Props for expiration inputs in the advanced URL form.
 */
export interface ExpirationFieldsProps {
	expirationDate: string;
	setExpirationDate: (value: string) => void;
	expirationTime: string;
	setExpirationTime: (value: string) => void;
}

/**
 * Props for UTM parameter fields in the advanced URL form.
 */
export interface UTMFieldsProps {
	utmSource: string;
	setUtmSource: (value: string) => void;
	utmMedium: string;
	setUtmMedium: (value: string) => void;
	utmCampaign: string;
	setUtmCampaign: (value: string) => void;
	utmTerm: string;
	setUtmTerm: (value: string) => void;
	utmContent: string;
	setUtmContent: (value: string) => void;
}

/**
 * Props for social preview fields in the advanced URL form.
 */
export interface SocialPreviewFieldsProps {
	socialTitle: string;
	setSocialTitle: (value: string) => void;
	socialDescription: string;
	setSocialDescription: (value: string) => void;
	socialImageFile: File | null;
	setSocialImageFile: (value: File | null) => void;
	socialImagePreviewUrl: string;
	socialImageUrl: string;
	setSocialImageUrl: (value: string) => void;
}

export interface AdvancedOptionsProps
	extends
		SecurityFieldsProps,
		ExpirationFieldsProps,
		UTMFieldsProps,
		SocialPreviewFieldsProps {}

/**
 * Props for the links page header.
 */
export interface LinksHeaderProps {
	onRefresh: () => Promise<void> | void;
	isRefreshing?: boolean;
	clickRange: LinksDateRange;
	customClickRange: LinksCustomDateRange;
	onClickRangeChange: (range: LinksDateRange) => void;
	onCustomClickRangeChange: (range: LinksCustomDateRange) => void;
}

/**
 * Props for the page-level pagination control.
 */
export interface PaginationProps {
	currentPage: number;
	totalPages: number;
	onPageChange: (page: number) => void;
	startItem: number;
	endItem: number;
	totalItems: number;
}

/**
 * Props for the links table footer controls.
 */
export interface TableFooterProps {
	totalLinks?: number;
	totalClicks?: number;
	sortBy?: LinksSortBy;
	setSortBy: (value: LinksSortBy) => void;
	sortOrder?: LinksSortOrder;
	setSortOrder: (value: LinksSortOrder) => void;
	limit?: number;
	setLimit: (value: number) => void;
}

/**
 * Props for the bulk delete confirmation modal.
 */
export interface BulkDeleteModalProps {
	open: boolean;
	setOpen: (open: boolean) => void;
	selectedIds: string[];
	onSuccess?: () => void;
}

/**
 * Link payload required by the single-delete confirmation modal.
 */
export interface DeletableLink {
	id: string;
	destinationUrl: string;
	alias?: string | null;
	shortCode?: string | null;
	shortUrl?: string | null;
	title?: string | null;
	domain?: unknown;
	clicks?: number | null;
	uniqueClicks?: number | null;
}

/**
 * Props for the single-delete confirmation modal.
 */
export interface DeleteLinkModalProps {
	open: boolean;
	setOpen: (open: boolean) => void;
	link: DeletableLink | null;
}

/**
 * Link payload required to generate a QR code modal preview.
 */
export interface QRCodeLink {
	alias?: string | null;
	shortCode?: string | null;
	shortUrl?: string | null;
	destinationUrl: string;
	title?: string | null;
	domain?: unknown;
}

/**
 * Props for the QR code modal.
 */
export interface QRCodeModalProps {
	open: boolean;
	setOpen: (open: boolean) => void;
	link: QRCodeLink | null;
}

/**
 * Link payload required by the edit drawer.
 */
export interface EditableLink {
	id: string;
	title?: string | null;
	status?: LinkStatus | null;
	expiresAt?: string | null;
	hasPassword?: boolean;
	destinationUrl: string;
	shortUrl?: string | null;
	alias?: string | null;
	shortCode?: string | null;
	domain?: string | { domain?: string; name?: string } | null;
	socialPreview?: {
		title?: string | null;
		description?: string | null;
		imageUrl?: string | null;
		externalImageUrl?: string | null;
	} | null;
}

/**
 * Props for the edit-link drawer.
 */
export interface EditLinkDrawerProps {
	open: boolean;
	setOpen: (open: boolean) => void;
	link: EditableLink | null;
}
