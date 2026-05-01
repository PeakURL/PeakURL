import { cn } from "@/utils";
import type { InlineNoticeProps, StatusTone } from "../types";

const noticeStyles: Record<StatusTone, string> = {
	info: "settings-updates-notice-info",
	success: "settings-updates-notice-success",
	error: "settings-updates-notice-error",
};

/**
 * Renders a contextual notice for update and schema status messages.
 */
function InlineNotice({
	direction,
	icon: Icon,
	title,
	description,
	tone = "info",
}: InlineNoticeProps) {
	return (
		<div className={cn("settings-updates-notice", noticeStyles[tone])}>
			<div dir={direction} className="settings-updates-notice-layout">
				<Icon size={18} className="settings-updates-notice-icon" />
				<div className="settings-updates-notice-content">
					<p className="settings-updates-notice-title">{title}</p>
					<p className="settings-updates-notice-text">
						{description}
					</p>
				</div>
			</div>
		</div>
	);
}

export default InlineNotice;
