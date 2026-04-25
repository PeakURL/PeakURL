import { useState } from "react";
import { Button } from "@/components";
import { CloudUpload } from "lucide-react";
import { __ } from "@/i18n";
import { cn } from "@/utils";
import type {
	FileButtonClickHandler,
	FileDropHandler,
	FileInputChangeHandler,
	FileUploadAreaProps,
} from "./types";

function FileUploadArea({
	fileInputRef,
	onFileSelected,
	disabled = false,
}: FileUploadAreaProps) {
	const [isDragActive, setIsDragActive] = useState(false);

	const openFilePicker = () => {
		if (disabled) {
			return;
		}

		fileInputRef.current?.click();
	};

	const handleFileInputChange: FileInputChangeHandler = (event) => {
		const file = event.target.files?.[0] || null;

		if (file) {
			onFileSelected(file);
		}

		event.target.value = "";
	};

	const preventDefault: FileDropHandler = (event) => {
		event.preventDefault();
		event.stopPropagation();
	};

	const handleDragEnter: FileDropHandler = (event) => {
		preventDefault(event);

		if (!disabled) {
			setIsDragActive(true);
		}
	};

	const handleDragOver: FileDropHandler = (event) => {
		preventDefault(event);

		if (!disabled && event.dataTransfer) {
			event.dataTransfer.dropEffect = "copy";
		}
	};

	const handleDragLeave: FileDropHandler = (event) => {
		preventDefault(event);
		setIsDragActive(false);
	};

	const handleDrop: FileDropHandler = (event) => {
		preventDefault(event);
		setIsDragActive(false);

		if (disabled) {
			return;
		}

		const file = event.dataTransfer?.files?.[0] || null;

		if (file) {
			onFileSelected(file);
		}
	};

	return (
		<div className="import-file-dropzone-wrapper">
			<div
				className={cn(
					"import-file-dropzone",
					disabled && "import-file-dropzone-disabled",
					!disabled && isDragActive && "import-file-dropzone-active",
					!disabled && !isDragActive && "import-file-dropzone-idle"
				)}
				onClick={openFilePicker}
				onDragEnter={handleDragEnter}
				onDragOver={handleDragOver}
				onDragLeave={handleDragLeave}
				onDrop={handleDrop}
			>
				<CloudUpload className="import-file-dropzone-icon" />
				<h3 className="import-file-dropzone-title">
					{__("Drop your file here")}
				</h3>
				<p className="import-file-dropzone-copy">
					{__("or click to browse (CSV, JSON, XML)")}
				</p>
				<Button
					size="sm"
					type="button"
					disabled={disabled}
					onClick={(event: Parameters<FileButtonClickHandler>[0]) => {
						event.stopPropagation();
						openFilePicker();
					}}
				>
					{__("Choose File")}
				</Button>
				<input
					type="file"
					ref={fileInputRef}
					className="hidden"
					accept=".csv,.json,.xml"
					onChange={handleFileInputChange}
				/>
			</div>
			<div className="import-file-dropzone-note">
				<p>
					{__(
						"Supported formats: CSV, JSON, XML (max 10MB, up to 10,000 URLs)"
					)}
				</p>
			</div>
		</div>
	);
}

export default FileUploadArea;
