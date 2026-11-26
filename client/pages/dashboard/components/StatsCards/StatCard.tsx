import type { ReactNode } from "react";

import { Skeleton } from "@/components";

interface StatCardProps {
	label: string;
	value: string;
	valueTitle?: string;
	change?: {
		text: string;
		tone: "neutral" | "positive" | "negative";
	};
	note: ReactNode;
	noteTitle?: string;
	isLoading?: boolean;
}

/** Compact metric presentation shared by Overview and Links. */
export function StatCard({
	label,
	value,
	valueTitle,
	change,
	note,
	noteTitle,
	isLoading = false,
}: StatCardProps) {
	return (
		<div className="dashboard-stats-card">
			<dt className="dashboard-stats-card-title">{label}</dt>
			<dd className="dashboard-stats-card-value" title={valueTitle}>
				{isLoading ? (
					<Skeleton
						className="dashboard-stats-value-placeholder"
						aria-hidden="true"
					/>
				) : (
					value
				)}
			</dd>
			<dd className="dashboard-stats-card-change" title={noteTitle}>
				{isLoading ? (
					<Skeleton
						className="dashboard-stats-change-placeholder"
						aria-hidden="true"
					/>
				) : (
					<>
						{change && (
							<span
								className={`dashboard-stats-change dashboard-stats-change-${change.tone}`}
							>
								{change.text}
							</span>
						)}
						<span className="dashboard-stats-change-note">
							{note}
						</span>
					</>
				)}
			</dd>
		</div>
	);
}
