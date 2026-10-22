import { KeyRound, Trash2 } from "lucide-react";

import type { ApiKeySummary } from "@/api";
import { __, sprintf } from "@/i18n";
import { formatLocalizedDateTime } from "@/shared/dates";

interface ApiKeyItemProps {
	apiKey: ApiKeySummary;
	isDeleting: boolean;
	onDelete: (apiKey: ApiKeySummary) => void | Promise<void>;
}

/** Saved keys show only their masked token; deleting uses the shared confirmation flow. */
export function ApiKeyItem({ apiKey, isDeleting, onDelete }: ApiKeyItemProps) {
	const label = apiKey.label || __("API Key");
	const deleteLabel = sprintf(__("Delete API key: %s"), label);
	return (
		<li className="settings-api-key-row">
			<div className="settings-api-key-main">
				<span className="settings-api-key-icon" aria-hidden="true">
					<KeyRound size={16} />
				</span>
				<div className="settings-api-key-content">
					<div className="settings-api-key-heading">
						<span className="settings-api-key-title" title={label}>
							{label}
						</span>
					</div>
					<div className="settings-api-key-meta">
						<code className="settings-api-key-value" dir="ltr">
							{apiKey.maskedKey || "••••••••"}
						</code>
						{apiKey.createdAt && (
							<>
								<span
									className="settings-api-key-meta-dot"
									aria-hidden="true"
								>
									·
								</span>
								<time
									className="settings-api-key-date"
									dateTime={apiKey.createdAt}
								>
									{sprintf(
										__("Created %s"),
										formatLocalizedDateTime(
											apiKey.createdAt,
											{
												dateStyle: "medium",
											}
										)
									)}
								</time>
							</>
						)}
					</div>
				</div>
			</div>
			<div className="settings-api-key-actions">
				<button
					type="button"
					className="settings-api-key-delete-button"
					aria-label={deleteLabel}
					title={deleteLabel}
					onClick={() => onDelete(apiKey)}
					disabled={isDeleting}
				>
					<Trash2 size={14} aria-hidden="true" />
					<span className="settings-api-key-delete-text">
						{__("Delete")}
					</span>
				</button>
			</div>
		</li>
	);
}
