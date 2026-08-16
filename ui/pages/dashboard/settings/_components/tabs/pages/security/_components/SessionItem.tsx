import { Monitor, Smartphone, Tablet } from "lucide-react";

import { Button } from "@/components";
import { __ } from "@/i18n";
import { cn, formatDateTimeValue, getCountryFlagEmoji } from "@/utils";

import { getSessionLocationLabel } from "../helpers";
import type { SessionItemProps } from "../types";

/**
 * Renders one active or ended session row.
 */
function SessionItem({
	direction,
	session,
	revokingId,
	onRevokeSession,
}: SessionItemProps) {
	const locationLabel = getSessionLocationLabel(session);
	const countryFlag = getCountryFlagEmoji(session.location?.countryCode);
	const Icon =
		session.device === "Mobile"
			? Smartphone
			: session.device === "Tablet"
				? Tablet
				: Monitor;

	return (
		<div
			dir={direction}
			className={cn(
				"settings-security-session-row",
				session.revokedAt ? "settings-security-session-row-ended" : ""
			)}
		>
			<div className="settings-security-session-meta">
				<div className="settings-security-session-icon-panel">
					<Icon
						size={18}
						className="settings-security-session-icon"
					/>
				</div>
				<div className="settings-security-session-copy">
					<p className="settings-security-session-title">
						{session.browser || __("Browser")} •{" "}
						{session.os || __("Unknown OS")}
					</p>
					<div className="settings-security-session-details">
						<span className="settings-security-session-detail settings-security-session-detail-location">
							<span
								className="settings-security-session-detail-flag"
								aria-hidden="true"
							>
								{countryFlag}
							</span>
							<span>{locationLabel}</span>
						</span>
						<span className="settings-security-session-detail">
							{session.ipAddress || __("Unknown IP")}
						</span>
						<span className="settings-security-session-detail">
							{__("Last active")}{" "}
							{formatDateTimeValue(
								session.lastActiveAt,
								__("Unknown")
							)}
						</span>
					</div>
				</div>
			</div>
			<div className="settings-security-session-actions">
				{session.isCurrent ? (
					<span className="settings-security-session-badge-current">
						{__("Current")}
					</span>
				) : null}
				{session.revokedAt ? (
					<span className="settings-security-session-badge-ended">
						{__("Ended")}
					</span>
				) : session.isCurrent ? null : (
					<Button
						variant="secondary"
						size="sm"
						onClick={() =>
							onRevokeSession(session.id, session.isCurrent)
						}
						loading={revokingId === session.id}
					>
						{__("End session")}
					</Button>
				)}
			</div>
		</div>
	);
}

export default SessionItem;
