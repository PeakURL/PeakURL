import { X } from "lucide-react";

import { PreviewImage } from "@/components";
import type { ImageSource } from "@/shared/links";

interface FaviconPreviewProps {
	source: ImageSource | null;
	label: string;
	siteName: string;
}

/** Show the effective favicon both on its own and at browser-tab size. */
export function FaviconPreview({
	source,
	label,
	siteName,
}: FaviconPreviewProps) {
	return (
		<>
			{source && (
				<div className="settings-favicon-asset">
					<PreviewImage
						source={source}
						alt={label}
						className="settings-image-thumbnail"
					/>
				</div>
			)}
			<div className="settings-browser-preview" aria-hidden="true">
				{source && (
					<PreviewImage
						source={source}
						alt=""
						className="settings-browser-favicon"
					/>
				)}
				<span className="settings-browser-title">{siteName}</span>
				<X size={13} className="settings-browser-close" />
			</div>
		</>
	);
}
