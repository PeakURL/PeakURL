import { KeyRound, Trash2, CalendarDays, ShieldCheck } from "lucide-react";

import type { ApiKeySummary } from "@/api";
import { __, sprintf } from "@/i18n";
import { formatLocalizedDateTime } from "@/shared/dates";

interface ApiKeyItemProps {
	apiKey: ApiKeySummary;
	isDeleting: boolean;
	onDelete: (apiKey: ApiKeySummary) => void | Promise<void>;
}

/** Saved keys show only their masked token; deleting still uses the shared confirmation flow. */
export function ApiKeyItem({ apiKey, isDeleting, onDelete }: ApiKeyItemProps) {
	const label = apiKey.label || __("API Key");
	const deleteLabel = sprintf(__("Delete API key: %s"), label);
	return (
		<li className="settings-api-key-row">
			<div className="settings-api-key-identity">
				<span className="settings-api-key-icon" aria-hidden="true">
					<KeyRound size={20} />
				</span>
				<div className="settings-api-key-details">
					<div className="settings-api-key-title-row">
						<h3 className="settings-api-key-title">{label}</h3>
						<span className="settings-api-key-status">
							<ShieldCheck size={12} aria-hidden="true" />
							{__("Active")}
						</span>
					</div>
					<p className="settings-api-key-meta">
						<CalendarDays size={13} aria-hidden="true" />
						<span>
							{__("Created:")}{" "}
							{apiKey.createdAt ? (
								<time dateTime={apiKey.createdAt}>
									{formatLocalizedDateTime(apiKey.createdAt, {
										dateStyle: "medium",
									})}
								</time>
							) : (
								__("Unknown")
							)}
						</span>
					</p>
				</div>
			</div>
			<div className="settings-api-key-token">
				<p className="settings-api-key-token-label">
					{__("Masked key")}
				</p>
				<code className="settings-api-key-value" dir="ltr">
					{apiKey.maskedKey || "••••••••"}
				</code>
			</div>
			<button
				type="button"
				className="settings-api-key-delete-button"
				aria-label={deleteLabel}
				title={deleteLabel}
				onClick={() => onDelete(apiKey)}
				disabled={isDeleting}
			>
				<Trash2 size={17} aria-hidden="true" />
			</button>
		</li>
	);
}
