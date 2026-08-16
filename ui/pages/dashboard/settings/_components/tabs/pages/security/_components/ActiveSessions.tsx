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
		<div className="settings-security-sessions-card">
			<div dir={direction} className="settings-security-sessions-header">
				<div>
					<h2 className="settings-security-card-title">
						{__("Active Sessions")}
					</h2>
					<span className="settings-security-sessions-count">
						{sprintf(
							__("%s active session(s)"),
							String(sessions.length)
						)}
					</span>
				</div>
				{otherActiveSessions.length > 0 ? (
					<Button
						variant="secondary"
						size="sm"
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
		</div>
	);
}

export default ActiveSessions;
