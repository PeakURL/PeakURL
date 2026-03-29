// @ts-nocheck
'use client';
import { Content } from './_components';
import { Navigate, useParams } from 'react-router-dom';
import { useAdminAccess } from '@/hooks';

const VALID_SETTINGS_TABS = new Set([
	'general',
	'security',
	'api',
	'integrations',
	'email',
	'location',
	'updates',
]);

function SettingsTabPage() {
	const params = useParams();
	const tab = params.tab;
	const {
		canManageApiKeys,
		canManageWebhooks,
		canManageMailDelivery,
		canManageLocationData,
		canManageUpdates,
		isLoading,
	} = useAdminAccess();

	if (tab && !VALID_SETTINGS_TABS.has(tab)) {
		return <Navigate replace to="/dashboard/settings/general" />;
	}

	const restrictedTabs = {
		api: canManageApiKeys,
		integrations: canManageWebhooks,
		email: canManageMailDelivery,
		location: canManageLocationData,
		updates: canManageUpdates,
	};

	if (tab && tab in restrictedTabs && !isLoading && !restrictedTabs[tab]) {
		return <Navigate replace to="/dashboard/settings/general" />;
	}

	return (
		<div className="lg:col-span-3 flex flex-col h-full">
			<div className="flex-1 rounded-lg border border-stroke bg-surface-alt p-5">
				<Content activeTab={tab} />
			</div>
		</div>
	);
}

export default SettingsTabPage;
