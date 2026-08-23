import { useState, useCallback } from "react";
import { Link } from "react-router";
import { AlertCircle, CheckCircle2, Info, TriangleAlert } from "lucide-react";

import { useGetAdminNoticesQuery } from "@/store/slices/api";
import { isDocumentRtl } from "@/i18n/direction";
import { cn, isRelativeUrl, sanitizeUrl } from "@/utils";

import type { AdminNoticeItem, NoticeActionProps, NoticeTone } from "../types";
import UpdateDetailsModal from "./UpdateDetailsModal";

const NOTICE_STYLES = {
	error: {
		containerClassName: "dashboard-notice-error",
		actionClassName: "dashboard-notice-action-error",
		icon: AlertCircle,
	},
	warning: {
		containerClassName: "dashboard-notice-warning",
		actionClassName: "dashboard-notice-action-warning",
		icon: TriangleAlert,
	},
	success: {
		containerClassName: "dashboard-notice-success",
		actionClassName: "dashboard-notice-action-success",
		icon: CheckCircle2,
	},
	info: {
		containerClassName: "dashboard-notice-info",
		actionClassName: "dashboard-notice-action-info",
		icon: Info,
	},
};

function isNoticeTone(value: unknown): value is NoticeTone {
	return (
		value === "error" ||
		value === "warning" ||
		value === "success" ||
		value === "info"
	);
}

function NoticeAction({ action, actionClassName }: NoticeActionProps) {
	const url = sanitizeUrl(action?.url);

	if (!action?.label || !url) {
		return null;
	}

	const actionClasses = cn("dashboard-notice-action", actionClassName);

	if (isRelativeUrl(url)) {
		return (
			<Link to={url} className={actionClasses}>
				{action.label}
			</Link>
		);
	}

	return (
		<a
			href={url}
			className={actionClasses}
			target="_blank"
			rel="noopener noreferrer"
		>
			{action.label}
		</a>
	);
}

export const AdminNotices = () => {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const { data } = useGetAdminNoticesQuery(undefined);
	const notices = data?.data?.items ?? [];
	const [updateModalOpen, setUpdateModalOpen] = useState(false);

	const handleNoticeClick = useCallback(
		(e: React.MouseEvent<HTMLDivElement>) => {
			const target = e.target as HTMLElement;
			// Handle legacy <a> tags from older translations or cached responses
			const anchor = target.closest("a");
			if (
				anchor &&
				anchor.href &&
				anchor.href.includes("peakurl.org/release-notes")
			) {
				e.preventDefault();
				setUpdateModalOpen(true);
				return;
			}

			// Handle the new <button> element
			const button = target.closest("button.js-update-details-trigger");
			if (button) {
				e.preventDefault();
				setUpdateModalOpen(true);
			}
		},
		[]
	);

	if (!notices.length) {
		return null;
	}

	return (
		<>
			<div className="dashboard-notices">
				{notices.map((notice: AdminNoticeItem, index: number) => {
					const toneKey = isNoticeTone(notice?.type)
						? notice.type
						: "info";
					const tone = NOTICE_STYLES[toneKey];
					const Icon = tone.icon;

					const noticeKey = notice?.id ?? `notice-${index}`;

					return (
						<div
							key={noticeKey}
							className={cn(
								"dashboard-notice",
								tone.containerClassName
							)}
						>
							<div
								dir={direction}
								className="dashboard-notice-layout"
							>
								<div className="dashboard-notice-content">
									<div className="dashboard-notice-icon">
										<Icon size={18} />
									</div>
									<div
										role="presentation"
										className="dashboard-notice-copy"
										onClick={handleNoticeClick}
										onKeyDown={(event) => {
											if (
												"Enter" === event.key ||
												" " === event.key
											) {
												const target =
													event.target as HTMLElement;
												if (
													target.closest("a") ||
													target.closest(
														"button.js-update-details-trigger"
													)
												) {
													event.preventDefault();
													setUpdateModalOpen(true);
												}
											}
										}}
									>
										{notice?.title ? (
											<p className="dashboard-notice-title">
												{notice.title}
											</p>
										) : null}
										{notice?.message ? (
											<div
												className="dashboard-notice-message"
												dangerouslySetInnerHTML={{
													__html: notice.message,
												}}
											/>
										) : null}
									</div>
								</div>

								<NoticeAction
									action={notice?.action}
									actionClassName={tone.actionClassName}
								/>
							</div>
						</div>
					);
				})}
			</div>

			<UpdateDetailsModal
				open={updateModalOpen}
				setOpen={setUpdateModalOpen}
			/>
		</>
	);
};

export default AdminNotices;
