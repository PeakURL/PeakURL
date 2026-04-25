export type LinkStatus = "active" | "inactive" | "expired";
export type LinksSortBy = "createdAt" | "clicks" | "alias";
export type LinksSortOrder = "asc" | "desc";

/**
 * Base link record consumed by the links dashboard surfaces.
 */
export interface LinkRecord {
	id: string;
	destinationUrl: string;
	alias?: string | null;
	shortCode?: string | null;
	shortUrl?: string | null;
	title?: string | null;
	domain?: string | { domain?: string; name?: string } | null;
	status?: LinkStatus | null;
	clicks?: number | null;
	uniqueClicks?: number | null;
	createdAt?: string | null;
	expiresAt?: string | null;
	hasPassword?: boolean;
}

/**
 * Pagination metadata returned by the links list endpoint.
 */
export interface LinksMeta {
	page: number;
	limit: number;
	totalItems: number;
	totalPages: number;
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

export interface AdvancedOptionsProps
	extends SecurityFieldsProps, ExpirationFieldsProps, UTMFieldsProps {}

/**
 * Props for the links page header.
 */
export interface LinksHeaderProps {
	onRefresh: () => Promise<void> | void;
	isRefreshing?: boolean;
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
 * Link payload required by the edit modal.
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
}

/**
 * Props for the edit-link modal.
 */
export interface EditLinkModalProps {
	open: boolean;
	setOpen: (open: boolean) => void;
	link: EditableLink | null;
}

/**
 * Mutation payload used to update an existing short link.
 */
export interface UpdateUrlPayload {
	id: string;
	title?: string;
	status: LinkStatus;
	expiresAt: string | null;
	clearPassword?: boolean;
	password?: string;
}
