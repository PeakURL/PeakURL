// @ts-nocheck
'use client';
import { Header, Sidebar } from './_components';
import { useParams } from 'react-router-dom';
import { useAdminAccess } from '@/hooks';

function SettingsLayout({ children }) {
	const params = useParams();
	const activeTab = params.tab || 'general';
	const {
		canManageApiKeys,
		canManageWebhooks,
		canManageMailDelivery,
		canManageLocationData,
		canManageUpdates,
	} = useAdminAccess();

	let tabs = [
		{ id: 'general', name: 'General', icon: 'settings' },
		{ id: 'security', name: 'Security', icon: 'shield' },
	];

	if (canManageApiKeys) {
		tabs.push({ id: 'api', name: 'API Keys', icon: 'key' });
	}

	if (canManageWebhooks) {
		tabs.push({ id: 'integrations', name: 'Integrations', icon: 'plug' });
	}

	if (canManageMailDelivery) {
		tabs.push({ id: 'email', name: 'Email SMTP', icon: 'mail' });
	}

	if (canManageLocationData) {
		tabs.push({ id: 'location', name: 'Location Data', icon: 'mapPin' });
	}

	if (canManageUpdates) {
		tabs.push({ id: 'updates', name: 'Updates', icon: 'download' });
	}

	return (
		<div className="flex flex-1 flex-col gap-5">
			<Header />

			<div className="grid flex-1 grid-cols-1 gap-5 lg:grid-cols-4">
				<Sidebar
					tabs={tabs}
					activeTab={activeTab}
					// Sidebar needs update to use Link or we pass a custom component/logic
				/>

				{children}
			</div>
		</div>
	);
}

export default SettingsLayout;
