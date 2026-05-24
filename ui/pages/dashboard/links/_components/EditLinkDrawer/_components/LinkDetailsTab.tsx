import {
	Input,
	ReadOnlyValueBlock,
	Select,
	type SelectOption,
} from "@/components";
import { __ } from "@/i18n";
import { getLocalDateTimeValue } from "@/utils";

import type { LinkStatus } from "../../types";

interface LinkDetailsTabProps {
	shortUrl: string;
	destinationUrl: string;
	title: string;
	setTitle: (value: string) => void;
	expiresAt: string;
	setExpiresAt: (value: string) => void;
	status: LinkStatus;
	setStatus: (value: LinkStatus) => void;
	statusOptions: SelectOption<LinkStatus>[];
}

function LinkDetailsTab({
	shortUrl,
	destinationUrl,
	title,
	setTitle,
	expiresAt,
	setExpiresAt,
	status,
	setStatus,
	statusOptions,
}: LinkDetailsTabProps) {
	return (
		<>
			<div className="links-edit-drawer-readonly-grid">
				<div className="links-edit-drawer-readonly-item">
					<span className="links-edit-drawer-readonly-label">
						{__("Short URL")}
					</span>
					<ReadOnlyValueBlock
						value={shortUrl}
						className="links-edit-drawer-readonly-value"
						valueClassName="links-edit-drawer-readonly-text"
					/>
				</div>
				<div className="links-edit-drawer-readonly-item">
					<span className="links-edit-drawer-readonly-label">
						{__("Destination URL")}
					</span>
					<ReadOnlyValueBlock
						value={destinationUrl}
						className="links-edit-drawer-readonly-value"
						monospace={false}
						valueClassName="links-edit-drawer-readonly-text"
					/>
				</div>
			</div>
			<Input
				label={__("Title (Optional)")}
				type="text"
				value={title}
				onChange={(event) => setTitle(event.target.value)}
				placeholder={__("Enter a title for this link")}
				className="form-control-surface-alt form-control-compact form-control-strong-focus"
			/>
			<div className="links-edit-drawer-field-grid">
				<div>
					<label className="links-modal-field-label">
						{__("Expiration Date (Optional)")}
					</label>
					<Input
						type="datetime-local"
						value={expiresAt}
						onChange={(event) => setExpiresAt(event.target.value)}
						min={getLocalDateTimeValue()}
						step="60"
						className="form-control-surface-alt form-control-compact form-control-strong-focus"
					/>
				</div>
				<div>
					<label className="links-modal-field-label">
						{__("Status")}
					</label>
					<Select
						value={status}
						onChange={setStatus}
						options={statusOptions}
						ariaLabel={__("Link status")}
						buttonClassName="form-control-surface-alt form-control-compact"
					/>
				</div>
			</div>
		</>
	);
}

export default LinkDetailsTab;
