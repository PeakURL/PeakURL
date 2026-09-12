import { Button } from "@/components";
import { __, sprintf } from "@/i18n";
import type { SecuritySession } from "../../types";
import SessionItem from "./SessionItem";
import type { ActiveSessionsProps } from "../types";

/**
 * Renders active browser sessions and the bulk sign-out action.
 */
function ActiveSessions({
	direction,
	sessions,
	otherActiveSessions,
	isLoading,
	isRevokingOthers,
	revokingId,
	onRequestRevokeOthers,
	onRevokeSession,
}: ActiveSessionsProps) {
	return (
		<section className="settings-fieldset">
			<h2 className="settings-legend">{__("Active Sessions")}</h2>
			<hr className="settings-separator" />
			<div dir={direction} className="settings-security-sessions-header">
				<p className="settings-group-description mb-0! mt-0!">
					{sessions.length === 1
						? __("1 active session")
						: sprintf(
								__("%s active sessions"),
								String(sessions.length)
							)}
				</p>
				{otherActiveSessions.length > 0 ? (
					<Button
						variant="secondary"
						size="sm"
						className="w-full sm:w-auto"
						onClick={onRequestRevokeOthers}
						loading={isRevokingOthers}
					>
						{__("End all other sessions")}
					</Button>
				) : null}
			</div>
			<div className="settings-security-sessions-list">
				{isLoading ? (
					<div className="settings-security-sessions-empty">
						{__("Loading security data...")}
					</div>
				) : 0 === sessions.length ? (
					<div className="settings-security-sessions-empty">
						{__("No active sessions found.")}
					</div>
				) : (
					sessions.map((session: SecuritySession) => (
						<SessionItem
							key={session.id}
							direction={direction}
							session={session}
							revokingId={revokingId}
							onRevokeSession={onRevokeSession}
						/>
					))
				)}
			</div>
		</section>
	);
}

export default ActiveSessions;
