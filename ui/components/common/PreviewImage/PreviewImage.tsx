import type { CSSProperties, HTMLAttributes } from "react";
import type { ImageSource } from "@/utils";

interface PreviewImageProps
	extends Omit<HTMLAttributes<HTMLSpanElement>, "children"> {
	source: ImageSource;
	alt?: string;
}

const cssUrlEscapePattern = /["\\\n\r\f]/g;

const cssUrlEscapes: Record<string, string> = {
	'"': '\\"',
	"\\": "\\\\",
	"\n": "\\A ",
	"\r": "\\D ",
	"\f": "\\C ",
};

function getPreviewImageStyle(
	source: ImageSource,
	style: CSSProperties | undefined
): CSSProperties {
	return {
		...style,
		"--preview-image-url": `url("${source.replace(
			cssUrlEscapePattern,
			(match) => cssUrlEscapes[match]
		)}")`,
	} as CSSProperties;
}

/**
 * Renders dashboard preview images from sources approved by `sanitizeImageUrl`.
 */
export function PreviewImage({
	source,
	alt,
	style,
	"aria-hidden": ariaHidden,
	"aria-label": ariaLabel,
	...imageProps
}: PreviewImageProps) {
	const isHidden = true === ariaHidden || "true" === ariaHidden;
	const isDecorative = "" === alt || isHidden;

	return (
		<span
			{...imageProps}
			role={isDecorative ? undefined : "img"}
			aria-hidden={isDecorative ? true : ariaHidden}
			aria-label={isDecorative ? undefined : ariaLabel || alt}
			style={getPreviewImageStyle(source, style)}
		/>
	);
}
