import type { ChangeEvent, RefObject } from "react";
import { ImageOff, Trash2 } from "lucide-react";

import { Input, PreviewImage, TextArea } from "@/components";
import { __ } from "@/i18n";
import { sanitizeImageUrl } from "@/utils";

interface SocialPreviewTabProps {
	fileInputRef: RefObject<HTMLInputElement | null>;
	socialTitle: string;
	setSocialTitle: (value: string) => void;
	socialDescription: string;
	setSocialDescription: (value: string) => void;
	socialImageFile: File | null;
	socialImageUrl: string;
	socialPreviewUrl: string;
	showSocialImageRemove: boolean;
	onSocialImageChange: (event: ChangeEvent<HTMLInputElement>) => void;
	onSocialImageUrlChange: (value: string) => void;
	onRemoveSocialImage: () => void;
}

function SocialPreviewTab({
	fileInputRef,
	socialTitle,
	setSocialTitle,
	socialDescription,
	setSocialDescription,
	socialImageFile,
	socialImageUrl,
	socialPreviewUrl,
	showSocialImageRemove,
	onSocialImageChange,
	onSocialImageUrlChange,
	onRemoveSocialImage,
}: SocialPreviewTabProps) {
	const socialPreviewImageSource = sanitizeImageUrl(socialPreviewUrl);

	return (
		<>
			<div className="links-edit-drawer-social-fields">
				<Input
					label={__("Preview Title")}
					type="text"
					value={socialTitle}
					onChange={(event) => setSocialTitle(event.target.value)}
					placeholder={__("Use link title")}
					className="form-control-surface-alt form-control-compact form-control-strong-focus"
				/>
				<TextArea
					label={__("Preview Description")}
					rows={3}
					value={socialDescription}
					onChange={(event) =>
						setSocialDescription(event.target.value)
					}
					placeholder={__(
						"Short description for Facebook, X, LinkedIn, and messaging apps"
					)}
					className="form-control-surface-alt form-control-compact form-control-strong-focus"
				/>
			</div>
			<div className="links-edit-drawer-image-field">
				<Input
					label={__("External Image URL")}
					type="url"
					value={socialImageUrl}
					onChange={(event) =>
						onSocialImageUrlChange(event.target.value)
					}
					placeholder="https://example.com/image.jpg"
					className="form-control-surface-alt form-control-compact form-control-strong-focus"
				/>
				<p className="text-sm text-muted text-center">{__("or")}</p>
				<label
					htmlFor="links-edit-social-image"
					className="links-modal-field-label"
				>
					{__("Preview Image")}
				</label>
				<input
					ref={fileInputRef}
					id="links-edit-social-image"
					type="file"
					accept="image/png,image/jpeg,image/webp"
					onChange={onSocialImageChange}
					className="links-edit-drawer-file-input"
				/>
				<div className="links-edit-drawer-picker">
					<button
						type="button"
						onClick={() => fileInputRef.current?.click()}
						className="links-edit-drawer-action links-edit-drawer-action-secondary"
					>
						{socialPreviewImageSource
							? __("Replace Preview Image")
							: __("Choose Preview Image")}
					</button>
					{socialImageFile ? (
						<span className="links-edit-drawer-filename">
							{socialImageFile.name}
						</span>
					) : null}
				</div>
				<div className="links-edit-drawer-preview-card">
					{socialPreviewImageSource ? (
						<div className="links-edit-drawer-preview-media">
							{showSocialImageRemove ? (
								<button
									type="button"
									onClick={onRemoveSocialImage}
									className="links-edit-drawer-preview-remove"
									aria-label={__("Remove Preview Image")}
								>
									<Trash2
										aria-hidden="true"
										className="links-edit-drawer-preview-remove-icon"
									/>
								</button>
							) : null}
							<PreviewImage
								source={socialPreviewImageSource}
								alt={__("Social preview image")}
								className="links-edit-drawer-preview-image"
							/>
						</div>
					) : (
						<div className="links-edit-drawer-preview-empty">
							<ImageOff
								aria-hidden="true"
								className="links-edit-drawer-preview-empty-icon"
							/>
							<span>{__("Use default preview image")}</span>
						</div>
					)}
				</div>
			</div>
		</>
	);
}

export default SocialPreviewTab;
