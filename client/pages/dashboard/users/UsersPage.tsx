import { ShieldCheck } from "lucide-react";

import { __ } from "@/i18n";

import {
	DeleteUserModal,
	UserDialog,
	UsersOverview,
	UsersOverviewSkeleton,
	UsersTable,
} from "./components";
import { useUsersManager } from "./hooks";

function UsersPage() {
	const {
		users,
		currentUser,
		canManageUsers,
		isUsersLoading,
		usersError,
		overviewItems,
		dialogMode,
		activeUser,
		isDialogOpen,
		isSubmitting,
		openCreateDialog,
		openEditDialog,
		closeDialog,
		handleSaveUser,
		userPendingDelete,
		isDeleting,
		openDeleteDialog,
		closeDeleteDialog,
		handleDeleteUser,
	} = useUsersManager();

	if (!canManageUsers) {
		return (
			<div className="users-page-access-state">
				<div className="users-page-access-icon">
					<ShieldCheck size={28} />
				</div>
				<h2 className="users-page-access-title">
					{__("Admin access required")}
				</h2>
				<p className="users-page-access-summary">
					{__(
						"Only admin accounts can manage other users and their roles."
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="users-page">
			{isUsersLoading && !users.length ? (
				<UsersOverviewSkeleton />
			) : (
				<UsersOverview
					items={overviewItems}
					onAddUserClick={openCreateDialog}
				/>
			)}

			<UsersTable
				users={users}
				currentUser={currentUser}
				onEditUser={openEditDialog}
				onDeleteUser={openDeleteDialog}
				isDeleting={isDeleting}
				isLoading={isUsersLoading}
				usersError={usersError}
			/>

			<UserDialog
				open={isDialogOpen}
				mode={dialogMode}
				currentUser={currentUser}
				initialUser={activeUser}
				onClose={closeDialog}
				onSubmit={handleSaveUser}
				isSubmitting={isSubmitting}
			/>

			<DeleteUserModal
				user={userPendingDelete}
				currentUser={currentUser}
				onClose={closeDeleteDialog}
				onConfirm={handleDeleteUser}
				isDeleting={isDeleting}
			/>
		</div>
	);
}

export default UsersPage;
