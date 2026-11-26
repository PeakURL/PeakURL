import { Link as LinkIcon, Tag, Scissors } from "lucide-react";

import { Button, Input } from "@/components";
import { __ } from "@/i18n";

import type { MainInputsProps } from "../types";

const MainInputs = ({
	destinationUrl,
	setDestinationUrl,
	alias,
	setAlias,
	isLoading,
}: MainInputsProps) => {
	return (
		<div className="links-form-main-grid">
			<div className="links-form-main-destination">
				<label htmlFor="long-url" className="links-form-field-label">
					{__("Destination URL")}
				</label>
				<Input
					type="url"
					id="long-url"
					valueDirection="ltr"
					value={destinationUrl}
					onChange={(e) => setDestinationUrl(e.target.value)}
					placeholder={__("Enter long link here...")}
					icon={LinkIcon}
					autoCapitalize="off"
					spellCheck={false}
					required
					className="links-form-main-input"
				/>
			</div>

			<div>
				<label htmlFor="alias" className="links-form-field-label">
					{__("Alias (Optional)")}
				</label>
				<Input
					type="text"
					id="alias"
					valueDirection="ltr"
					value={alias}
					onChange={(e) => setAlias(e.target.value)}
					placeholder={__("Alias (optional)")}
					icon={Tag}
					autoCapitalize="off"
					spellCheck={false}
					className="links-form-main-input"
				/>
			</div>

			<Button
				type="submit"
				size="md"
				icon={Scissors}
				loading={isLoading}
				className="links-form-main-button"
			>
				{isLoading ? __("Shortening...") : __("Shorten")}
			</Button>
		</div>
	);
};

export default MainInputs;
