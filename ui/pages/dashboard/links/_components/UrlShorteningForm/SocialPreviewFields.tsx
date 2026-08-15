import { useRef, type ChangeEvent } from "react";
import { Image, ImageOff, Trash2, Type } from "lucide-react";

import { Button, Input, TextArea } from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

import type { SocialPreviewFieldsProps } from "../types";

const SocialPreviewFields = ({
	socialTitle,
	setSocialTitle,
	socialDescription,
	setSocialDescription,
	socialImageFile,
	setSocialImageFile,
	socialImagePreviewUrl,
	socialImageUrl,
	setSocialImageUrl,
}: SocialPreviewFieldsProps) => {
	const direction = isDocumentRtl() ? "rtl" : "ltr";
	const fileInputRef = useRef<HTMLInputElement | null>(null);

	const handleImageChange = (event: ChangeEvent<HTMLInputElement>) => {
		const nextFile = event.target.files?.[0] || null;
		const isSupportedImage =
			nextFile &&
			["image/png", "image/jpeg", "image/webp"].includes(nextFile.type) &&
			/\.(png|jpe?g|webp)$/i.test(nextFile.name);

		setSocialImageFile(isSupportedImage ? nextFile : null);

		if (isSupportedImage) {
			setSocialImageUrl("");
		}
	};

	const handleClearImage = () => {
		setSocialImageFile(null);

		if (fileInputRef.current) {
			fileInputRef.current.value = "";
		}
	};

	return (
		<div className="links-form-social-preview">
			<div className="links-form-social-preview-grid">
				<div className="flex flex-col gap-4">
					<div className="space-y-1">
						<h3
							dir={direction}
							className="links-form-section-label links-form-section-label-content mb-0"
						>
							<Image className="links-form-section-label-icon" />
							{__("Social Preview")}
						</h3>
						<p className="links-form-social-preview-help">
							{__(
								"Customize the Open Graph preview shown when this short link is shared."
							)}
						</p>
					</div>

					<Input
						label={__("Preview Title")}
						type="text"
						value={socialTitle}
						onChange={(event) => setSocialTitle(event.target.value)}
						placeholder={__("Use link title")}
						icon={Type}
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
					/>
				</div>

				<div className="flex flex-col">
					<div
						className="hidden md:block h-[58px]"
						aria-hidden="true"
					/>
					<div className="flex flex-col gap-2">
						<Input
							label={__("Image from URL")}
							type="url"
							value={socialImageUrl}
							onChange={(event) =>
								setSocialImageUrl(event.target.value)
							}
							placeholder="https://example.com/image.jpg"
						/>
						<div className="links-form-separator">
							<div className="links-form-separator-line"></div>
							<span className="links-form-separator-text">
								{__("or")}
							</span>
							<div className="links-form-separator-line"></div>
						</div>
						<label
							htmlFor="links-social-preview-image"
							className="links-form-section-label !mb-0"
						>
							{__("Preview Image")}
						</label>

						<div className="links-form-social-preview-card mt-0 shadow-sm">
							{socialImagePreviewUrl ? (
								<div className="links-form-social-preview-media">
									<button
										type="button"
										onClick={handleClearImage}
										className="links-form-social-preview-remove"
										aria-label={__("Remove Preview Image")}
									>
										<Trash2
											aria-hidden="true"
											className="links-form-social-preview-remove-icon"
										/>
									</button>
									<img
										src={socialImagePreviewUrl}
										alt={__("Social preview image")}
										className="links-form-social-preview-image"
									/>
								</div>
							) : (
								<div className="links-form-social-preview-empty">
									<ImageOff
										aria-hidden="true"
										className="links-form-social-preview-empty-icon"
									/>
									<span>
										{__("Use default preview image")}
									</span>
								</div>
							)}
						</div>

						<input
							ref={fileInputRef}
							id="links-social-preview-image"
							type="file"
							accept="image/png,image/jpeg,image/webp"
							onChange={handleImageChange}
							className="sr-only"
						/>
						<div className="flex items-center gap-3">
							<Button
								type="button"
								size="sm"
								variant="outline"
								onClick={() => fileInputRef.current?.click()}
							>
								{socialImagePreviewUrl
									? __("Replace Image")
									: __("Upload Image")}
							</Button>
							{socialImageFile ? (
								<span className="text-sm font-medium text-heading truncate max-w-[200px]">
									{socialImageFile.name}
								</span>
							) : null}
						</div>
					</div>
				</div>
			</div>
		</div>
	);
};

export default SocialPreviewFields;
