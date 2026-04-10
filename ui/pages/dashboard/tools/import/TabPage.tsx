import { useState } from 'react';
import { ApiImport, PasteImport, FileUpload } from './_components';
import { useLocation } from 'react-router-dom';
import { __ } from '@/i18n';
import type { ImportStatus, SampleRow } from './_components/types';

function TabPage() {
	const location = useLocation();
	const activeTab =
		location.pathname.split('/').filter(Boolean).pop() || 'file';

	const [importStatus, setImportStatus] = useState<ImportStatus>('idle');
	const importProgress = 0;

	const sampleData: SampleRow[] = [
		{
			url: 'https://example.com/page1',
			alias: 'page1',
			title: __('Product launch'),
		},
		{
			url: 'https://example.com/page2',
			alias: 'page2',
			title: __('Help docs'),
		},
		{
			url: 'https://example.com/page3',
			alias: 'page3',
			title: __('Newsletter'),
		},
	];

	return (
		<div>
			{activeTab === 'file' && (
				<FileUpload
					importStatus={importStatus}
					setImportStatus={setImportStatus}
					importProgress={importProgress}
					sampleData={sampleData}
				/>
			)}

			{activeTab === 'api' && <ApiImport />}

			{activeTab === 'paste' && <PasteImport />}
		</div>
	);
}

export default TabPage;
