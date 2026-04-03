// @ts-nocheck
import { Button } from '@/components/ui';
import { CloudUpload } from 'lucide-react';
import { __ } from '@/i18n';

function FileUploadArea({ fileInputRef, handleFileSelect }) {
	return (
		<div>
			<div
				className="border-2 border-dashed border-stroke rounded-lg p-8 text-center hover:border-accent transition-colors cursor-pointer"
				onClick={() => fileInputRef.current?.click()}
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
					onClick={(e) => {
						e.stopPropagation();
						fileInputRef.current?.click();
					}}
				>
					{__('Choose File')}
				</Button>
				<input
					type="file"
					ref={fileInputRef}
					className="hidden"
					accept=".csv,.json,.xml"
					onChange={handleFileSelect}
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
