import { Content } from './_components';
import { Navigate, useParams } from 'react-router-dom';
import { useAdminAccess } from '@/hooks';
import type { SettingsTabId } from './_components/types';

const VALID_SETTINGS_TABS = new Set<SettingsTabId>([
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
	const tab = params.tab as SettingsTabId | undefined;
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

	const restrictedTabs: Partial<Record<SettingsTabId, boolean>> = {
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
		<div className="settings-tab-page">
			<div className="settings-tab-page-panel">
				<Content activeTab={tab || 'general'} />
			</div>
		</div>
	);
}

export default SettingsTabPage;
