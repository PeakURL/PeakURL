import { cn } from "@/shared/formatting";

import type { UpdateStatusProps, StatusTone } from "../types";

const statusStyles: Record<StatusTone, string> = {
	info: "settings-update-status-info",
	success: "settings-update-status-success",
	error: "settings-update-status-error",
};

/** Presents the release or database state once, beside its relevant actions. */
export default function UpdateStatus({
	direction,
	icon: Icon,
	title,
	description,
	tone = "info",
	prominent = false,
	details,
}: UpdateStatusProps) {
	const Heading = prominent ? "h2" : "h3";
	return (
		<div
			dir={direction}
			className={cn(
				"settings-update-status",
				statusStyles[tone],
				prominent && "settings-update-status-prominent"
			)}
		>
			<span className="settings-update-status-icon" aria-hidden="true">
				<Icon size={prominent ? 28 : 20} />
			</span>
			<div className="settings-update-status-copy">
				<Heading className="settings-update-status-title">
					{title}
				</Heading>
				<p className="settings-update-status-description">
					{description}
				</p>
				{details && (
					<div className="settings-update-status-details">
						{details}
					</div>
				)}
			</div>
		</div>
	);
}
