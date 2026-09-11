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
		isUsersLoading,
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
