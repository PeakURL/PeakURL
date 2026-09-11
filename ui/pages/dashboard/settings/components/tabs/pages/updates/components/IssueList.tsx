import type { IssueListProps } from "../types";

/**
 * Renders recent updater or database schema findings.
 */
function IssueList({ direction, title, issues }: IssueListProps) {
	return (
		<div dir={direction} className="settings-updates-issues">
			<p className="settings-updates-issues-title">{title}</p>
			<ul className="settings-updates-issues-list">
				{issues.map((issue) => (
					<li key={issue.id || issue.label}>
						<bdi dir="auto">{issue.label}</bdi>
					</li>
				))}
			</ul>
		</div>
	);
}

export default IssueList;
