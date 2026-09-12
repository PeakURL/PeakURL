/**
 * Visual tone used by dashboard admin notices.
 */
export type NoticeTone = "error" | "warning" | "success" | "info";

/**
 * Optional action link attached to a dashboard notice.
 */
export interface NoticeActionLink {
	label?: string | null;
	url?: string | null;
}

/**
 * Single admin notice returned for the dashboard layout.
 */
export interface AdminNoticeItem {
	id?: string | null;
	type?: NoticeTone | null;
	title?: string | null;
	message?: string | null;
	action?: NoticeActionLink | null;
}

/**
 * Endpoint response returned by the admin notices route.
 */
export interface AdminNoticesResponse {
	data?: {
		items?: AdminNoticeItem[];
	};
}
