import { cn } from "@/utils";
import type { StatusBadgeProps, StatusTone } from "../types";

const statusBadgeStyles: Record<StatusTone, string> = {
	info: "settings-updates-status-badge-info",
	success: "settings-updates-status-badge-success",
	error: "settings-updates-status-badge-error",
};

/**
 * Renders the compact state badge used by update-management sections.
 */
function StatusBadge({ tone = "info", label }: StatusBadgeProps) {
	return (
		<span
			className={cn(
				"settings-updates-status-badge",
				statusBadgeStyles[tone]
			)}
		>
			{label}
		</span>
	);
}

export default StatusBadge;
