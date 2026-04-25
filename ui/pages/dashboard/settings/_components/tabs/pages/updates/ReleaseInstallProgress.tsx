import { CheckCircle2, Clock3, LoaderCircle } from "lucide-react";
import { getDocumentDirection } from "@/i18n/direction";
import { cn } from "@/utils";
import type { ReleaseInstallProgressState } from "../types";

interface ReleaseInstallProgressProps {
	progress: ReleaseInstallProgressState;
	compact?: boolean;
}

const stepStateStyles = {
	complete: "settings-release-progress-badge-complete",
	current: "settings-release-progress-badge-current",
	upcoming: "settings-release-progress-badge-upcoming",
} as const;

const stepTextStyles = {
	complete: "settings-release-progress-label-complete",
	current: "settings-release-progress-label-current",
	upcoming: "settings-release-progress-label-upcoming",
} as const;

function ReleaseInstallProgress({
	progress,
	compact = false,
}: ReleaseInstallProgressProps) {
	const direction = getDocumentDirection();

	return (
		<div
			dir={direction}
			className={cn(
				"settings-release-progress",
				compact
					? "settings-release-progress-compact"
					: "settings-release-progress-default"
			)}
		>
			<div
				className={cn(
					"settings-release-progress-header",
					compact
						? "settings-release-progress-header-compact"
						: "settings-release-progress-header-default"
				)}
			>
				<p className="settings-release-progress-title">
					{progress.title}
				</p>
				<p className="settings-release-progress-description">
					{progress.description}
				</p>
			</div>

			<ol
				className={cn(
					"settings-release-progress-list",
					compact
						? "settings-release-progress-list-compact"
						: "settings-release-progress-list-default"
				)}
			>
				{progress.steps.map((step) => {
					const Icon =
						"complete" === step.state
							? CheckCircle2
							: "current" === step.state
								? LoaderCircle
								: Clock3;

					return (
						<li
							key={step.id}
							className="settings-release-progress-item"
						>
							<span
								className={cn(
									"settings-release-progress-badge",
									stepStateStyles[step.state]
								)}
							>
								<Icon
									size={16}
									className={cn(
										"settings-release-progress-icon",
										"current" === step.state
											? "settings-release-progress-icon-spin"
											: ""
									)}
								/>
							</span>
							<span
								className={cn(
									"settings-release-progress-label",
									stepTextStyles[step.state]
								)}
							>
								{step.label}
							</span>
						</li>
					);
				})}
			</ol>
		</div>
	);
}

export default ReleaseInstallProgress;
