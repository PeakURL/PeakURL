import { __, sprintf } from "@/i18n";
import { cn } from "@/shared/formatting";
import type { JobStatusBadgeProps } from "../types";

export function JobStatusBadge({
	status,
	attempts = 0,
	maxAttempts = 3,
	className,
}: JobStatusBadgeProps) {
	const normalized = status.toLowerCase();

	if ("running" === normalized) {
		return (
			<span
				className={cn(
					"scheduled-jobs-badge scheduled-jobs-badge-running",
					className
				)}
			>
				<span className="scheduled-jobs-badge-dot scheduled-jobs-badge-dot-running animate-pulse" />
				<span>{__("Running")}</span>
			</span>
		);
	}

	if ("failed" === normalized) {
		const isRetrying = attempts > 0 && attempts < maxAttempts;

		if (isRetrying) {
			return (
				<span
					className={cn(
						"scheduled-jobs-badge scheduled-jobs-badge-retrying",
						className
					)}
				>
					<span className="scheduled-jobs-badge-dot scheduled-jobs-badge-dot-retrying" />
					<span>
						{sprintf(
							/* translators: 1: current attempt, 2: max attempts */
							__("Retrying (%1$d/%2$d)"),
							attempts,
							maxAttempts
						)}
					</span>
				</span>
			);
		}

		return (
			<span
				className={cn(
					"scheduled-jobs-badge scheduled-jobs-badge-failed",
					className
				)}
			>
				<span className="scheduled-jobs-badge-dot scheduled-jobs-badge-dot-failed" />
				<span>{__("Failed")}</span>
			</span>
		);
	}

	if ("success" === normalized) {
		return (
			<span
				className={cn(
					"scheduled-jobs-badge scheduled-jobs-badge-success",
					className
				)}
			>
				<span className="scheduled-jobs-badge-dot scheduled-jobs-badge-dot-success" />
				<span>{__("Success")}</span>
			</span>
		);
	}

	if ("skipped" === normalized) {
		return (
			<span
				className={cn(
					"scheduled-jobs-badge scheduled-jobs-badge-skipped",
					className
				)}
			>
				<span className="scheduled-jobs-badge-dot scheduled-jobs-badge-dot-skipped" />
				<span>{__("Skipped")}</span>
			</span>
		);
	}

	// Default idle / scheduled status
	return (
		<span
			className={cn(
				"scheduled-jobs-badge scheduled-jobs-badge-scheduled",
				className
			)}
		>
			<span className="scheduled-jobs-badge-dot scheduled-jobs-badge-dot-scheduled" />
			<span>{__("Scheduled")}</span>
		</span>
	);
}

export default JobStatusBadge;
