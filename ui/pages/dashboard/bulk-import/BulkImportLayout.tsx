// @ts-nocheck
'use client';
import { Header, Tabs } from './_components';
import { useLocation } from 'react-router-dom';
import { ClipboardPaste, CodeXml, FileUp } from 'lucide-react';

function BulkImportLayout({ children }) {
	const location = useLocation();
	const activeTab = location.pathname.split('/').filter(Boolean).pop() || 'file';

	const tabs = [
		{ id: 'file', name: 'File Upload', icon: FileUp },
		{ id: 'api', name: 'API Import', icon: CodeXml },
		{ id: 'paste', name: 'Paste URLs', icon: ClipboardPaste },
	];

	return (
		<div className="space-y-5">
			<Header />

			<Tabs tabs={tabs} activeTab={activeTab} />

			{children}
		</div>
	);
}

export default BulkImportLayout;
