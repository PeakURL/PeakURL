import type { ImgHTMLAttributes } from "react";
import type { ImageSource } from "@/utils";

interface PreviewImageProps
	extends Omit<ImgHTMLAttributes<HTMLImageElement>, "src"> {
	source: ImageSource;
}

/**
 * Renders dashboard preview images from sources approved by `sanitizeImageUrl`.
 */
export function PreviewImage({ source, ...imageProps }: PreviewImageProps) {
	// codeql[js/xss-through-dom] `source` is a branded ImageSource produced by sanitizeImageUrl().
	return <img {...imageProps} src={source} />;
}
