import type { EditableLink } from "../../types";

export const getEditLinkDrawerKey = (link: EditableLink) =>
	[
		link.id,
		link.title || "",
		link.status || "",
		link.expiresAt || "",
		link.destinationUrl || "",
		link.hasPassword ? "1" : "0",
		link.socialPreview?.title || "",
		link.socialPreview?.description || "",
		link.socialPreview?.imageUrl || "",
	].join("|");

export const isSocialPreviewImageFile = (file: File | null): file is File =>
	Boolean(
		file &&
		["image/png", "image/jpeg", "image/webp"].includes(file.type) &&
		/\.(png|jpe?g|webp)$/i.test(file.name)
	);
