import { DEFAULT_USER_CAPABILITIES } from "@/api";
import { authApi } from "@/state/slices";
import { selectSessionUser } from "@/state/slices/api";

export const useAdminAccess = () => {
	const { useAuthCheckQuery } = authApi;
	const { data, isLoading, isFetching } = useAuthCheckQuery(undefined);
	const user = selectSessionUser(data);
	const capabilities = user?.capabilities || DEFAULT_USER_CAPABILITIES;

	const isAdmin = user?.role === "admin";
	const canManageUsers = isAdmin && Boolean(capabilities.manageUsers);
	const canEmptyTrash = isAdmin && Boolean(capabilities.emptyTrash);
	const canEditLinks = Boolean(capabilities.editLinks);
	const canEditAnyLink = canEditLinks && Boolean(capabilities.editAnyLink);
	const canDeleteLinks = Boolean(capabilities.deleteLinks);
	const canDeleteAnyLink =
		canDeleteLinks && Boolean(capabilities.deleteAnyLink);
	const canManageIntegrations = Boolean(capabilities.manageIntegrations);

	return {
		user,
		capabilities,
		isAdmin,
		canManageUsers,
		canManageSiteSettings: Boolean(capabilities.manageSiteSettings),
		canManageApiKeys: Boolean(capabilities.manageApiKeys),
		canManageIntegrations,
		canManageMailDelivery: Boolean(capabilities.manageMailDelivery),
		canManageLocationData: Boolean(capabilities.manageLocationData),
		canManagePerformance: Boolean(capabilities.managePerformance),
		canManageUpdates: Boolean(capabilities.manageUpdates),
		canViewLinks: Boolean(capabilities.viewLinks),
		canCreateLinks: Boolean(capabilities.createLinks),
		canEditLinks,
		canEditAnyLink,
		canTrashLinks: Boolean(capabilities.trashLinks),
		canDeleteLinks,
		canDeleteAnyLink,
		canEmptyTrash,
		canViewAnalytics: Boolean(capabilities.viewAnalytics),
		isLoading: (isLoading || isFetching) && !user,
	};
};
