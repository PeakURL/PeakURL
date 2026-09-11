import { AlertCircle, CheckCircle2 } from "lucide-react";

import { Button } from "@/components";
import { __ } from "@/i18n";
import { formatRelativeTime } from "@/utils";

import type { DatabaseSchemaProps } from "../types";
import InlineNotice from "./InlineNotice";
import IssueList from "./IssueList";
import MetricGrid from "./MetricGrid";
import SectionHeader from "./SectionHeader";
import UpdateSection from "./UpdateSection";

/**
 * Renders database schema status, repair controls, and recent findings.
 */
function DatabaseSchema({
	direction,
	databaseStatus,
	databaseState,
	visibleDatabaseIssues,
	isLoading,
	isChecking,
	isApplying,
	isRepairing,
	onRepair,
}: DatabaseSchemaProps) {
	const noticeIcon =
		databaseStatus?.lastError || databaseStatus?.upgradeRequired
			? AlertCircle
			: CheckCircle2;
	const showDatabaseDetails =
		visibleDatabaseIssues.length > 0 || Boolean(databaseStatus?.lastError);

	return (
		<UpdateSection>
			<SectionHeader
				direction={direction}
				title={__("Database Schema")}
				description={__(
					"PeakURL can repair missing tables, columns, indexes, and legacy leftovers so the current release runs against the expected schema."
				)}
				badge={databaseState}
				primaryAction={
					<Button
						variant={
							databaseStatus?.upgradeRequired
								? "primary"
								: "outline"
						}
						size="sm"
						className="settings-updates-repair-button"
						onClick={onRepair}
						loading={isRepairing}
						disabled={isLoading || isChecking || isApplying}
					>
						{__("Run Database Upgrade")}
					</Button>
				}
			/>

			<MetricGrid
				direction={direction}
				items={[
					{
						label: __("Recorded Schema"),
						value: String(
							databaseStatus?.currentVersion ?? __("Unknown")
						),
						valueDirection: "ltr",
					},
					{
						label: __("Required Schema"),
						value: String(
							databaseStatus?.targetVersion ?? __("Unknown")
						),
						valueDirection: "ltr",
					},
					{
						label: __("Last Database Upgrade"),
						value: databaseStatus?.lastUpgradedAt
							? formatRelativeTime(databaseStatus.lastUpgradedAt)
							: __("Never"),
						valueDirection: "ltr",
					},
				]}
			/>

			<div className="settings-updates-block">
				<InlineNotice
					direction={direction}
					icon={noticeIcon}
					title={databaseState.title}
					description={databaseState.description}
					tone={databaseState.tone}
				/>
			</div>

			{showDatabaseDetails ? (
				<div className="settings-updates-divider">
					{databaseStatus?.lastError ? (
						<div className="settings-updates-error-card">
							<p className="settings-updates-error-title">
								{__("Last database error")}
							</p>
							<p className="settings-updates-error-text">
								{databaseStatus.lastError}
							</p>
						</div>
					) : null}
					{visibleDatabaseIssues.length > 0 ? (
						<IssueList
							direction={direction}
							title={__("Recent database findings")}
							issues={visibleDatabaseIssues}
						/>
					) : null}
				</div>
			) : null}
		</UpdateSection>
	);
}

export default DatabaseSchema;
