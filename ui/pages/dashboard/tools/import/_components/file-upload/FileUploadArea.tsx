import { useState } from 'react';
import { Button } from '@/components/ui';
import { CloudUpload } from 'lucide-react';
import { __ } from '@/i18n';
import type {
	FileButtonClickHandler,
	FileDropHandler,
	FileInputChangeHandler,
	FileUploadAreaProps,
} from './types';

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

		event.target.value = '';
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
			event.dataTransfer.dropEffect = 'copy';
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
		<div>
			<div
				className={`rounded-lg border-2 border-dashed p-8 text-center transition-colors ${
					disabled
						? 'cursor-not-allowed border-stroke/70 opacity-60'
						: isDragActive
							? 'cursor-pointer border-accent bg-accent/5'
							: 'cursor-pointer border-stroke hover:border-accent'
				}`}
				onClick={openFilePicker}
				onDragEnter={handleDragEnter}
				onDragOver={handleDragOver}
				onDragLeave={handleDragLeave}
				onDrop={handleDrop}
			>
				<CloudUpload className="mx-auto mb-3 h-8 w-8 text-text-muted" />
				<h3 className="text-base font-medium text-heading mb-1">
					{__('Drop your file here')}
				</h3>
				<p className="text-sm text-text-muted mb-4">
					{__('or click to browse (CSV, JSON, XML)')}
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
					{__('Choose File')}
				</Button>
				<input
					type="file"
					ref={fileInputRef}
					className="hidden"
					accept=".csv,.json,.xml"
					onChange={handleFileInputChange}
				/>
			</div>
			<div className="mt-3 text-xs text-text-muted">
				<p>
					{__(
						'Supported formats: CSV, JSON, XML (max 10MB, up to 10,000 URLs)'
					)}
				</p>
			</div>
		</div>
	);
}

export default FileUploadArea;
