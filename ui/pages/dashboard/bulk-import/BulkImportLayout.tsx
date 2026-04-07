import { Header, Tabs } from './_components';
import { useLocation } from 'react-router-dom';
import { ClipboardPaste, CodeXml, FileUp } from 'lucide-react';
import { __ } from '@/i18n';
import type { BulkImportTab } from './_components/types';
import type { BulkImportLayoutProps } from './types';

function BulkImportLayout({ children }: BulkImportLayoutProps) {
	const location = useLocation();
	const activeTab =
		(location.pathname.split('/').filter(Boolean).pop() as
			| BulkImportTab['id']
			| undefined) || 'file';

	const tabs: BulkImportTab[] = [
		{ id: 'file', name: __('File Upload'), icon: FileUp },
		{ id: 'api', name: __('API Import'), icon: CodeXml },
		{ id: 'paste', name: __('Paste URLs'), icon: ClipboardPaste },
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
