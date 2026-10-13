import { useMemo, useState } from "react";
import { Clock, ShieldCheck, UserCheck, Users } from "lucide-react";

import { useNotification } from "@/components";
import { useAdminAccess } from "@/hooks";
import { __, _n, sprintf } from "@/i18n";
import {
	useCreateUserMutation,
	useDeleteUserMutation,
	useGetAllUsersQuery,
	useGetUserProfileQuery,
	useUpdateUserMutation,
} from "@/state/slices/api";
import { formatCount, formatDate } from "@/shared/formatting";
import { getErrorMessage } from "@/shared/errors";

import type {
	UserDialogMode,
	UserDialogPayload,
	UsersOverviewItem,
	UserSummary,
} from "../types";

export function useUsersManager() {
	const notification = useNotification();
	const { canManageUsers, user: authUser } = useAdminAccess();
	const { data: userData } = useGetUserProfileQuery(undefined);

	const accountUser = userData?.data ?? authUser ?? null;
	const currentUserRole: UserSummary["role"] = accountUser?.role ?? undefined;

	const currentUser: UserSummary | null = accountUser
		? {
				id:
					accountUser.id ||
					accountUser._id ||
					accountUser.username ||
					accountUser.email ||
					"current-user",
				username: accountUser.username,
				firstName: accountUser.firstName,
				lastName: accountUser.lastName,
				displayName: accountUser.displayName,
				email: accountUser.email,
				role: currentUserRole,
				roleName: accountUser.roleName,
			}
		: null;

	const {
		data: usersData,
		isLoading: isUsersLoading,
		error: usersError,
		refetch,
	} = useGetAllUsersQuery(undefined, { skip: !canManageUsers });

	const [createUser, { isLoading: isCreating }] = useCreateUserMutation();
	const [updateUser, { isLoading: isUpdating }] = useUpdateUserMutation();
	const [deleteUser, { isLoading: isDeleting }] = useDeleteUserMutation();

	const [dialogMode, setDialogMode] = useState<UserDialogMode>("create");
	const [activeUser, setActiveUser] = useState<UserSummary | null>(null);
	const [isDialogOpen, setIsDialogOpen] = useState(false);
	const [isRolesDrawerOpen, setIsRolesDrawerOpen] = useState(false);
	const [userPendingDelete, setUserPendingDelete] =
		useState<UserSummary | null>(null);

	const users = useMemo<UserSummary[]>(
		() => usersData?.data || [],
		[usersData]
	);

	const adminCount = useMemo(
		() => users.filter((user) => user.role === "admin").length,
		[users]
	);

	const nonAdminCount = useMemo(
		() => users.filter((user) => user.role !== "admin").length,
		[users]
	);

	const newestUser = useMemo(() => {
		if (!users.length) return null;
		return (
			[...users].sort((a, b) => {
				const dateA = a.createdAt ? new Date(a.createdAt).getTime() : 0;
				const dateB = b.createdAt ? new Date(b.createdAt).getTime() : 0;
				return dateB - dateA;
			})[0] ?? null
		);
	}, [users]);

	const overviewItems: UsersOverviewItem[] = useMemo(
		() => [
			{
				key: "all",
				label: __("Total Users"),
				value: formatCount(users.length),
				icon: Users,
				iconTone: "all",
				note: sprintf(
					_n(
						"%d registered account",
						"%d registered accounts",
						users.length
					),
					users.length
				),
			},
			{
				key: "admins",
				label: __("Administrators"),
				value: formatCount(adminCount),
				icon: ShieldCheck,
				iconTone: "admins",
				note: __("Full site & user management"),
			},
			{
				key: "editors",
				label: __("Editors & Team"),
				value: formatCount(nonAdminCount),
				icon: UserCheck,
				iconTone: "editors",
				note: __("Configured role members"),
			},
			{
				key: "newest",
				label: __("Latest Member"),
				value:
					newestUser?.displayName ||
					(newestUser?.firstName
						? `${newestUser.firstName} ${newestUser.lastName}`.trim()
						: newestUser?.username
							? `@${newestUser.username}`
							: "—"),
				isTextValue: true,
				icon: Clock,
				iconTone: "latest",
				note: newestUser?.createdAt
					? formatDate(newestUser.createdAt)
					: __("No users yet"),
			},
		],
		[users.length, adminCount, nonAdminCount, newestUser]
	);

	const openCreateDialog = () => {
		setDialogMode("create");
		setActiveUser(null);
		setIsDialogOpen(true);
	};

	const openEditDialog = (user: UserSummary) => {
		setDialogMode("edit");
		setActiveUser(user);
		setIsDialogOpen(true);
	};

	const closeDialog = () => {
		setIsDialogOpen(false);
		setActiveUser(null);
	};

	const openDeleteDialog = (user: UserSummary) => {
		if (user.id === currentUser?.id) {
			return;
		}
		setUserPendingDelete(user);
	};

	const closeDeleteDialog = () => {
		setUserPendingDelete(null);
	};

	const handleSaveUser = async (payload: UserDialogPayload) => {
		if ("create" === dialogMode) {
			await createUser(payload).unwrap();
			notification.success(
				__("User created"),
				__("The user account was successfully created.")
			);
			return;
		}

		if (!activeUser?.username) {
			throw new Error(__("Unable to identify the user to update."));
		}

		await updateUser({
			currentUsername: activeUser.username,
			...payload,
		}).unwrap();

		notification.success(
			__("User updated"),
			__("The user account details have been saved.")
		);
	};

	const handleDeleteUser = async () => {
		if (
			isDeleting ||
			!userPendingDelete ||
			!userPendingDelete.username ||
			userPendingDelete.id === currentUser?.id
		) {
			return;
		}

		try {
			await deleteUser(userPendingDelete.username).unwrap();
			notification.success(
				__("User deleted"),
				__("The user account was successfully removed.")
			);
			setUserPendingDelete(null);
		} catch (error) {
			notification.error(
				__("Unable to delete user"),
				getErrorMessage(error, __("Failed to delete user."))
			);
		}
	};

	const openRolesDrawer = () => setIsRolesDrawerOpen(true);
	const closeRolesDrawer = () => setIsRolesDrawerOpen(false);

	return {
		users,
		currentUser,
		canManageUsers,
		isAdmin: currentUser?.role === "admin",
		isUsersLoading,
		usersError,
		refetch,
		overviewItems,
		dialogMode,
		activeUser,
		isDialogOpen,
		isRolesDrawerOpen,
		isSubmitting: isCreating || isUpdating,
		openCreateDialog,
		openEditDialog,
		closeDialog,
		openRolesDrawer,
		closeRolesDrawer,
		handleSaveUser,
		userPendingDelete,
		isDeleting,
		openDeleteDialog,
		closeDeleteDialog,
		handleDeleteUser,
	};
}
