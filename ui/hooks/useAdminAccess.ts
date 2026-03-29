// @ts-nocheck
import { useGetUserProfileQuery } from '@/store/slices/api/user';

export const useAdminAccess = () => {
	const { data, isLoading, isFetching } = useGetUserProfileQuery();
	const user = data?.data ?? null;
	const rawCapabilities = user?.capabilities || {};
	const capabilities = {
		manageUsers: Boolean(rawCapabilities.manage_users),
		manageMailDelivery: Boolean(rawCapabilities.manage_mail_delivery),
		manageLocationData: Boolean(rawCapabilities.manage_location_data),
		manageUpdates: Boolean(rawCapabilities.manage_updates),
		manageProfile: Boolean(rawCapabilities.manage_profile),
		manageApiKeys: Boolean(rawCapabilities.manage_api_keys),
		manageWebhooks: Boolean(rawCapabilities.manage_webhooks),
		viewAllLinks: Boolean(rawCapabilities.view_all_links),
		viewOwnLinks: Boolean(rawCapabilities.view_own_links),
		viewSiteAnalytics: Boolean(rawCapabilities.view_site_analytics),
		viewOwnAnalytics: Boolean(rawCapabilities.view_own_analytics),
		createLinks: Boolean(rawCapabilities.create_links),
	};

	return {
		user,
		capabilities,
		isAdmin: Boolean(capabilities.manageUsers || user?.role === 'admin'),
		canManageUsers: Boolean(capabilities.manageUsers),
		canManageApiKeys: Boolean(capabilities.manageApiKeys),
		canManageWebhooks: Boolean(capabilities.manageWebhooks),
		canManageMailDelivery: Boolean(capabilities.manageMailDelivery),
		canManageLocationData: Boolean(capabilities.manageLocationData),
		canManageUpdates: Boolean(capabilities.manageUpdates),
		isLoading: isLoading || isFetching,
	};
};
