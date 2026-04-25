import type { ChangeEvent, SubmitEvent } from "react";
import { useMemo, useState } from "react";
import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
import {
	Pencil,
	Plus,
	ShieldCheck,
	Trash2,
	UserRound,
	Users,
	X,
} from "lucide-react";
import { useAdminAccess } from "@/hooks";
import {
	Avatar,
	Button,
	ConfirmDialog,
	IconButton,
	Input,
	Select,
	type SelectOption,
	useNotification,
} from "@/components";
import {
	useCreateUserMutation,
	useDeleteUserMutation,
	useGetAllUsersQuery,
	useGetUserProfileQuery,
	useUpdateUserMutation,
} from "@/store/slices/api";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { formatLocalizedDateTime, getErrorMessage } from "@/utils";
import { UsersTableSkeletonRows } from "./UsersSkeleton";
import type {
	UserDialogFormState,
	UserDialogMode,
	UserDialogPayload,
	UserDialogProps,
	UserRole,
	UserRoleMeta,
	UserSummary,
} from "./types";

const EMPTY_FORM: UserDialogFormState = {
	firstName: "",
	lastName: "",
	username: "",
	email: "",
	password: "",
	confirmPassword: "",
	role: "editor",
};

const getRoleMeta = (): Record<UserRole, UserRoleMeta> => ({
	admin: {
		label: __("Admin"),
		description: __("Can manage users, settings, and all links."),
		badge: "users-page-role-badge-admin",
	},
	editor: {
		label: __("Editor"),
		description: __(
			"Can create, edit, and delete site links without admin access."
		),
		badge: "users-page-role-badge-editor",
	},
});

const getInitialFormState = (
	mode: UserDialogMode,
	initialUser?: UserSummary | null
): UserDialogFormState => {
	if ("edit" === mode && initialUser) {
		return {
			firstName: initialUser.firstName ?? "",
			lastName: initialUser.lastName ?? "",
			username: initialUser.username ?? "",
			email: initialUser.email ?? "",
			password: "",
			confirmPassword: "",
			role: initialUser.role ?? "editor",
		};
	}

	return EMPTY_FORM;
};

function UserDialog({
	open,
	mode,
	currentUser,
	initialUser,
	onClose,
	onSubmit,
	isSubmitting,
}: UserDialogProps) {
	const isRtl = isDocumentRtl();
	const roleMeta = getRoleMeta();
	const roleOptions: SelectOption<UserRole>[] = [
		{ value: "admin", label: __("Admin") },
		{ value: "editor", label: __("Editor") },
	];
	const [form, setForm] = useState<UserDialogFormState>(() =>
		getInitialFormState(mode, initialUser)
	);
	const [formError, setFormError] = useState("");

	const handleChange =
		(key: keyof UserDialogFormState) =>
		(event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
			setForm((previous) => ({
				...previous,
				[key]: event.target.value,
			}));
		};

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		setFormError("");

		const payload: UserDialogPayload = {
			firstName: form.firstName.trim(),
			lastName: form.lastName.trim(),
			username: form.username.trim(),
			email: form.email.trim(),
			role: form.role as UserRole,
		};

		if ("create" === mode || "" !== form.password.trim()) {
			if (form.password.length < 8) {
				setFormError(__("Use at least 8 characters."));
				return;
			}

			if (form.password !== form.confirmPassword) {
				setFormError(__("Passwords do not match."));
				return;
			}

			payload.password = form.password;
		}

		try {
			await onSubmit(payload);
			onClose();
		} catch (error) {
			setFormError(
				getErrorMessage(error, __("Unable to save the user."))
			);
		}
	};

	const isEditingSelf =
		"edit" === mode && initialUser?.id === currentUser?.id;

	return (
		<Dialog open={open} onClose={onClose} className="users-page-dialog">
			<div className="users-page-dialog-backdrop" aria-hidden="true" />
			<div className="users-page-dialog-wrapper">
				<DialogPanel className="users-page-dialog-panel">
					<div className="users-page-dialog-header">
						<div>
							<DialogTitle className="users-page-dialog-title">
								{"create" === mode
									? __("Add User")
									: __("Edit User")}
							</DialogTitle>
							<p className="users-page-dialog-summary">
								{"create" === mode
									? __(
											"Create a new account with admin or editor access."
										)
									: __(
											"Update the account details and role for this user."
										)}
							</p>
						</div>
						<button
							type="button"
							onClick={onClose}
							className="users-page-dialog-close"
						>
							<X size={18} />
						</button>
					</div>

					<form
						onSubmit={handleSubmit}
						className="users-page-dialog-form"
					>
						{formError && (
							<div className="users-page-dialog-error">
								{formError}
							</div>
						)}

						<div className="users-page-dialog-grid">
							<Input
								label={__("First Name")}
								value={form.firstName}
								onChange={handleChange("firstName")}
								required
							/>
							<Input
								label={__("Last Name")}
								value={form.lastName}
								onChange={handleChange("lastName")}
								required
							/>
						</div>

						<div className="users-page-dialog-grid">
							<Input
								label={__("Username")}
								valueDirection="ltr"
								autoCapitalize="off"
								spellCheck={false}
								value={form.username}
								onChange={handleChange("username")}
								required
							/>
							<Input
								label={__("Email")}
								type="email"
								value={form.email}
								onChange={handleChange("email")}
								required
							/>
						</div>

						<div className="users-page-dialog-grid">
							<Input
								label={
									"create" === mode
										? __("Password")
										: __("New Password")
								}
								type="password"
								value={form.password}
								onChange={handleChange("password")}
								required={"create" === mode}
								autoComplete="new-password"
								helperText={
									"create" === mode
										? __("Use at least 8 characters.")
										: __(
												"Leave blank to keep the current password."
											)
								}
							/>
							<Input
								label={
									"create" === mode
										? __("Confirm Password")
										: __("Confirm New Password")
								}
								type="password"
								value={form.confirmPassword}
								onChange={handleChange("confirmPassword")}
								required={
									"create" === mode ||
									"" !== form.password.trim()
								}
								autoComplete="new-password"
								helperText={
									"create" === mode
										? __(
												"Re-enter the password to confirm it."
											)
										: __(
												"Re-enter the new password to confirm it."
											)
								}
							/>
						</div>

						<div className="users-page-dialog-grid">
							<div className="users-page-dialog-field">
								<label className="users-page-dialog-label">
									{__("Role")}
								</label>
								<Select
									value={form.role}
									onChange={(value) =>
										setForm((previous) => ({
											...previous,
											role: value,
										}))
									}
									options={roleOptions}
									disabled={isEditingSelf}
									ariaLabel={__("User role")}
								/>
								<p className="users-page-dialog-help">
									{roleMeta[form.role]?.description}
									{isEditingSelf
										? ` ${__("Your own role is locked here.")}`
										: ""}
								</p>
							</div>
						</div>

						<div
							className={`users-page-dialog-actions ${
								isRtl
									? "users-page-dialog-actions-start"
									: "users-page-dialog-actions-end"
							}`}
						>
							<Button
								type="button"
								variant="secondary"
								onClick={onClose}
							>
								{__("Cancel")}
							</Button>
							<Button type="submit" loading={isSubmitting}>
								{"create" === mode
									? __("Create User")
									: __("Save Changes")}
							</Button>
						</div>
					</form>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

function UsersPage() {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const roleMeta = getRoleMeta();
	const { data: userData } = useGetUserProfileQuery(undefined);
	const { canManageUsers, user: authUser } = useAdminAccess();
	const resolvedCurrentUser = userData?.data ?? authUser ?? null;
	const currentUserRole: UserSummary["role"] =
		resolvedCurrentUser?.role === "admin"
			? "admin"
			: resolvedCurrentUser?.role === "editor"
				? "editor"
				: undefined;
	const currentUser: UserSummary | null = resolvedCurrentUser
		? {
				id:
					resolvedCurrentUser.id ||
					resolvedCurrentUser._id ||
					resolvedCurrentUser.username ||
					resolvedCurrentUser.email ||
					"current-user",
				firstName: resolvedCurrentUser.firstName,
				lastName: resolvedCurrentUser.lastName,
				username: resolvedCurrentUser.username,
				email: resolvedCurrentUser.email,
				role: currentUserRole,
			}
		: null;
	const {
		data: usersData,
		isLoading: isUsersLoading,
		error: usersError,
	} = useGetAllUsersQuery(undefined, { skip: !canManageUsers });
	const [createUser, { isLoading: isCreating }] = useCreateUserMutation();
	const [updateUser, { isLoading: isUpdating }] = useUpdateUserMutation();
	const [deleteUser, { isLoading: isDeleting }] = useDeleteUserMutation();
	const [dialogMode, setDialogMode] = useState<UserDialogMode>("create");
	const [activeUser, setActiveUser] = useState<UserSummary | null>(null);
	const [isDialogOpen, setIsDialogOpen] = useState(false);
	const [userPendingDelete, setUserPendingDelete] =
		useState<UserSummary | null>(null);
	const notification = useNotification();

	const users = useMemo<UserSummary[]>(
		() => usersData?.data || [],
		[usersData]
	);
	const adminCount = useMemo(
		() => users.filter((user) => user.role === "admin").length,
		[users]
	);
	const editorCount = useMemo(
		() => users.filter((user) => user.role === "editor").length,
		[users]
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

	const openDeleteDialog = (user: UserSummary) => {
		if (user.id === currentUser?.id) {
			return;
		}

		setUserPendingDelete(user);
	};

	const handleDelete = async () => {
		if (
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
				sprintf(
					__("%s was removed successfully."),
					`${userPendingDelete.firstName} ${userPendingDelete.lastName}`
				)
			);
			setUserPendingDelete(null);
		} catch (error) {
			notification.error(
				__("Delete failed"),
				getErrorMessage(
					error,
					__("Unable to delete this user right now.")
				)
			);
		}
	};

	const handleSubmitUser = async (payload: UserDialogPayload) => {
		if ("create" === dialogMode) {
			return createUser(payload).unwrap();
		}

		return updateUser({
			currentUsername: activeUser?.username ?? undefined,
			...payload,
		}).unwrap();
	};

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
			<div className="users-page-hero">
				<div>
					<div className="users-page-hero-badge">
						<UserRound size={14} />
						{__("User Access")}
					</div>
					<h1 className="users-page-hero-title">{__("Users")}</h1>
					<p className="users-page-hero-summary">
						{__(
							"Manage the people who can access this installation. Admins control site-wide settings. Editors can create and maintain links."
						)}
					</p>
				</div>
				<Button icon={Plus} onClick={openCreateDialog}>
					{__("Add User")}
				</Button>
			</div>

			<div className="users-page-stats">
				<div className="users-page-stat-card">
					<p className="users-page-stat-label">{__("Total Users")}</p>
					<p className="users-page-stat-value">
						{isUsersLoading ? "..." : users.length}
					</p>
				</div>
				<div className="users-page-stat-card">
					<p className="users-page-stat-label">{__("Admins")}</p>
					<p className="users-page-stat-value">
						{isUsersLoading ? "..." : adminCount}
					</p>
				</div>
				<div className="users-page-stat-card">
					<p className="users-page-stat-label">{__("Editors")}</p>
					<p className="users-page-stat-value">
						{isUsersLoading ? "..." : editorCount}
					</p>
				</div>
			</div>

			<div className="users-page-panel">
				<div className="users-page-panel-header">
					<h2 className="users-page-panel-title">{__("Accounts")}</h2>
				</div>

				{usersError ? (
					<div className="users-page-panel-error">
						{getErrorMessage(
							usersError,
							__("Unable to load users.")
						)}
					</div>
				) : !isUsersLoading && users.length === 0 ? (
					<div className="users-page-panel-state">
						<div className="users-page-empty-icon">
							<Users size={28} />
						</div>
						<h3 className="users-page-empty-title">
							{__("No users yet")}
						</h3>
						<p className="users-page-empty-summary">
							{__(
								"Add an admin or editor account to start sharing access."
							)}
						</p>
					</div>
				) : (
					<div className="users-page-table-scroll">
						<table className="users-page-table">
							<thead className="users-page-table-head">
								<tr className="users-page-table-head-row">
									<th className="users-page-table-heading">
										{__("User")}
									</th>
									<th className="users-page-table-heading">
										{__("Role")}
									</th>
									<th className="users-page-table-heading">
										{__("Created")}
									</th>
									<th className="users-page-table-heading-end">
										{__("Actions")}
									</th>
								</tr>
							</thead>
							<tbody className="users-page-table-body">
								{isUsersLoading ? (
									<UsersTableSkeletonRows />
								) : (
									users.map((user) => (
										<tr
											key={user.id}
											className="users-page-table-row"
										>
											<td className="users-page-table-cell">
												<div
													dir={direction}
													className="users-page-user"
												>
													<Avatar
														size="md"
														email={user.email}
														firstName={
															user.firstName
														}
														lastName={user.lastName}
														fallbackName={
															user.username ||
															__("User")
														}
														className="users-page-avatar"
													/>
													<div className="users-page-user-copy">
														<div className="users-page-user-name">
															<bdi dir="auto">
																{user.firstName}{" "}
																{user.lastName}
															</bdi>
														</div>
														<div className="users-page-user-email">
															<bdi className="preserve-ltr-value inline-block">
																{user.email}
															</bdi>
														</div>
														<div className="users-page-user-username">
															<bdi className="preserve-ltr-value inline-block">
																@{user.username}
															</bdi>
														</div>
													</div>
												</div>
											</td>
											<td className="users-page-table-cell">
												<span
													className={`users-page-role-badge ${
														roleMeta[
															user.role ===
															"admin"
																? "admin"
																: "editor"
														].badge
													}`}
												>
													{
														roleMeta[
															user.role ===
															"admin"
																? "admin"
																: "editor"
														].label
													}
												</span>
											</td>
											<td className="users-page-table-cell-meta">
												{user.createdAt
													? formatLocalizedDateTime(
															user.createdAt,
															{
																dateStyle:
																	"medium",
															}
														)
													: __("Unknown")}
											</td>
											<td className="users-page-table-cell-actions">
												<div className="users-page-actions">
													<IconButton
														icon={Pencil}
														variant="outline"
														size="sm"
														aria-label={sprintf(
															__("Edit %s"),
															`${user.firstName} ${user.lastName}`
														)}
														title={sprintf(
															__("Edit %s"),
															`${user.firstName} ${user.lastName}`
														)}
														onClick={() =>
															openEditDialog(user)
														}
													/>
													<IconButton
														icon={Trash2}
														variant="outline"
														size="sm"
														aria-label={sprintf(
															__("Delete %s"),
															`${user.firstName} ${user.lastName}`
														)}
														title={sprintf(
															__("Delete %s"),
															`${user.firstName} ${user.lastName}`
														)}
														className="users-page-action-delete"
														disabled={
															!currentUser?.id ||
															user.id ===
																currentUser?.id ||
															isDeleting
														}
														onClick={() =>
															openDeleteDialog(
																user
															)
														}
													/>
												</div>
											</td>
										</tr>
									))
								)}
							</tbody>
						</table>
					</div>
				)}
			</div>

			<UserDialog
				key={`${dialogMode}-${activeUser?.id ?? "new"}-${isDialogOpen ? "open" : "closed"}`}
				open={isDialogOpen}
				mode={dialogMode}
				currentUser={currentUser}
				initialUser={activeUser}
				onClose={() => setIsDialogOpen(false)}
				onSubmit={handleSubmitUser}
				isSubmitting={isCreating || isUpdating}
			/>
			<ConfirmDialog
				open={Boolean(userPendingDelete)}
				onClose={() => setUserPendingDelete(null)}
				title={__("Delete User")}
				description={
					userPendingDelete
						? sprintf(
								__(
									"Delete %s? This will revoke their sessions, remove their API keys, and permanently delete their account."
								),
								`${userPendingDelete.firstName} ${userPendingDelete.lastName}`
							)
						: ""
				}
				confirmText={__("Delete User")}
				confirmVariant="danger"
				onConfirm={handleDelete}
				loading={isDeleting}
			/>
		</div>
	);
}

export default UsersPage;
