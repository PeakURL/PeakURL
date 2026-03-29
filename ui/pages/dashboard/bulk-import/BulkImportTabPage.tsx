// @ts-nocheck
'use client';
import { useState } from 'react';
import { ApiImport, PasteImport, FileUpload } from './_components';
import { useParams } from 'react-router-dom';

function BulkImportTabPage() {
	const params = useParams();
	const activeTab = params.tab;

	const [importStatus, setImportStatus] = useState('idle'); // idle, uploading, processing, completed
	const [importProgress, setImportProgress] = useState(0);

	const sampleData = [
		{ url: 'https://example.com/page1', alias: 'page1' },
		{
			url: 'https://example.com/page2',
			alias: 'page2',
		},
		{ url: 'https://example.com/page3', alias: 'page3' },
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

export default BulkImportTabPage;
