// @ts-nocheck
'use client';
import { Header, Sidebar } from './_components';
import { useParams } from 'react-router-dom';

function ApiDocsLayout({ children }) {
	const params = useParams();
	const activeSection = params.tab || 'authentication';

	const sections = [
		{ id: 'authentication', name: 'Authentication', icon: 'key' },
		{ id: 'links', name: 'Links', icon: 'link' },
		{ id: 'analytics', name: 'Analytics', icon: 'chart-bar' },
		{ id: 'qr-codes', name: 'QR Codes', icon: 'qrcode' },
		{ id: 'webhooks', name: 'Webhooks', icon: 'webhook' },
	];

	return (
		<div>
			<Header />

			<div className="grid grid-cols-1 lg:grid-cols-4 gap-5">
				<Sidebar sections={sections} activeSection={activeSection} />

				{children}
			</div>
		</div>
	);
}

export default ApiDocsLayout;
