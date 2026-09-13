import { DEFAULT_USER_CAPABILITIES } from "@/api";
import { authApi } from "@/state/slices";
import { selectSessionUser } from "@/state/slices/api";

export const useAdminAccess = () => {
	const { useAuthCheckQuery } = authApi;
	const { data, isLoading, isFetching } = useAuthCheckQuery(undefined);
	const user = selectSessionUser(data);
	const capabilities = user?.capabilities || DEFAULT_USER_CAPABILITIES;

	return {
		user,
		capabilities,
		isAdmin: Boolean(capabilities.manageUsers || user?.role === "admin"),
		canManageUsers: Boolean(capabilities.manageUsers),
		canManageSiteSettings: Boolean(capabilities.manageSiteSettings),
		canManageApiKeys: Boolean(capabilities.manageApiKeys),
		canManageWebhooks: Boolean(capabilities.manageWebhooks),
		canManageMailDelivery: Boolean(capabilities.manageMailDelivery),
		canManageLocationData: Boolean(capabilities.manageLocationData),
		canManagePerformance: Boolean(capabilities.managePerformance),
		canManageUpdates: Boolean(capabilities.manageUpdates),
		isLoading: isLoading || isFetching,
	};
};
