import { Globe, X } from "lucide-react";

import { PreviewImage } from "@/components";
import type { ImageSource } from "@/shared/links";

interface FaviconPreviewProps {
	source: ImageSource | null;
	label: string;
	siteName: string;
}

/** Shows the selected favicon at its asset size and in a browser tab. */
export function FaviconPreview({
	source,
	label,
	siteName,
}: FaviconPreviewProps) {
	return (
		<>
			<div className="settings-favicon-asset">
				{source ? (
					<PreviewImage
						source={source}
						alt={label}
						className="settings-image-thumbnail"
					/>
				) : (
					<Globe size={30} aria-hidden="true" />
				)}
			</div>
			<div className="settings-browser-preview" aria-hidden="true">
				<div className="settings-browser-toolbar">
					<div className="settings-browser-controls">
						<span />
						<span />
						<span />
					</div>
					<div className="settings-browser-tab">
						{source ? (
							<PreviewImage
								source={source}
								alt=""
								className="settings-browser-favicon"
							/>
						) : (
							<Globe size={16} />
						)}
						<span className="settings-browser-title">
							{siteName}
						</span>
						<X size={14} className="settings-browser-close" />
					</div>
				</div>
			</div>
		</>
	);
}
