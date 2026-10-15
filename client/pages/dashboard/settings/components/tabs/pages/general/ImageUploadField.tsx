import type { ChangeEventHandler, RefObject } from "react";
import { Image, Upload, Trash2 } from "lucide-react";

import { Button, PreviewImage } from "@/components";
import type { ImageSource } from "@/shared/links";

import { FaviconPreview } from "./FaviconPreview";

interface ImageUploadFieldProps {
	id: string;
	title: string;
	description: string;
	hint: string;
	accept: string;
	buttonLabel: string;
	removeLabel: string;
	previewLabel: string;
	preview: ImageSource | null;
	landscape?: boolean;
	siteName?: string;
	fileName?: string;
	disabled: boolean;
	inputRef: RefObject<HTMLInputElement | null>;
	onChange: ChangeEventHandler<HTMLInputElement>;
	onRemove?: () => void;
}

/** Image picker with a responsive preview; file validation and saving belong to General settings. */
export function ImageUploadField({
	id,
	title,
	description,
	hint,
	accept,
	buttonLabel,
	removeLabel,
	previewLabel,
	preview,
	landscape = false,
	siteName = "PeakURL",
	fileName,
	disabled,
	inputRef,
	onChange,
	onRemove,
}: ImageUploadFieldProps) {
	return (
		<div
			className={
				landscape
					? "settings-image-upload settings-image-upload-landscape"
					: "settings-image-upload"
			}
		>
			<div className="settings-image-content">
				<h3 className="settings-image-title">{title}</h3>
				<p className="settings-group-description">{description}</p>
			</div>
			<div className="settings-image-preview">
				{landscape ? (
					preview ? (
						<PreviewImage
							source={preview}
							alt={previewLabel}
							className="settings-image-thumbnail"
						/>
					) : (
						<Image size={32} aria-hidden="true" />
					)
				) : (
					<FaviconPreview
						source={preview}
						label={previewLabel}
						siteName={siteName}
					/>
				)}
				{onRemove && (
					<button
						type="button"
						className="settings-image-remove"
						onClick={onRemove}
						disabled={disabled}
						aria-label={removeLabel}
						title={removeLabel}
					>
						<Trash2 size={18} aria-hidden="true" />
					</button>
				)}
			</div>
			<div className="settings-image-content">
				<input
					ref={inputRef}
					id={id}
					aria-label={title}
					type="file"
					accept={accept}
					onChange={onChange}
					disabled={disabled}
					className="settings-image-input"
					aria-describedby={`${id}-hint`}
				/>
				<div className="settings-image-actions">
					<Button
						type="button"
						size="sm"
						variant="secondary"
						icon={Upload}
						onClick={() => inputRef.current?.click()}
						disabled={disabled}
					>
						{buttonLabel}
					</Button>
					{fileName && (
						<span className="settings-image-filename">
							{fileName}
						</span>
					)}
				</div>
				<p id={`${id}-hint`} className="form-field-helper">
					{hint}
				</p>
			</div>
		</div>
	);
}
