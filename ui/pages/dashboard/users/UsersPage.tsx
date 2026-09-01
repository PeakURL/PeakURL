import type { ChangeEvent, SubmitEvent } from "react";
import { useMemo, useState } from "react";
import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
import {
	Clock,
	Pencil,
	Plus,
	ShieldCheck,
	Trash2,
	UserCheck,
	UserRound,
	Users,
	X,
} from "lucide-react";

import { useAdminAccess } from "@/hooks";
import {
	Avatar,
	Button,
	ConfirmDialog,
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
import { __, _n, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import {
	cn,
	formatCount,
	formatDate,
	formatLocalizedDateTime,
	getErrorMessage,
} from "@/utils";

import { UsersOverviewSkeleton, UsersTableSkeletonRows } from "./UsersSkeleton";
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
	displayName: "",
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

function getUserDisplayName(user?: UserSummary | null): string {
	if (!user) {
		return __("User");
	}
	const fullName = `${user.firstName || ""} ${user.lastName || ""}`.trim();
	return (
		user.displayName ||
		fullName ||
		user.username ||
		user.email ||
		__("User")
	);
}

const getInitialFormState = (
	mode: UserDialogMode,
	initialUser?: UserSummary | null
): UserDialogFormState => {
	if ("edit" === mode && initialUser) {
		return {
			firstName: initialUser.firstName ?? "",
			lastName: initialUser.lastName ?? "",
			displayName: initialUser.displayName ?? "",
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
			displayName: form.displayName.trim(),
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
							aria-label={__("Close dialog")}
						>
							<X size={17} />
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
								label={__("Display Name")}
								value={form.displayName}
								onChange={handleChange("displayName")}
								placeholder={`${form.firstName} ${form.lastName}`.trim()}
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
	const accountUser = userData?.data ?? authUser ?? null;
	const currentUserRole: UserSummary["role"] =
		accountUser?.role === "admin"
			? "admin"
			: accountUser?.role === "editor"
				? "editor"
				: undefined;
	const currentUser: UserSummary | null = accountUser
		? {
				id:
					accountUser.id ||
					accountUser._id ||
					accountUser.username ||
					accountUser.email ||
					"current-user",
				firstName: accountUser.firstName,
				lastName: accountUser.lastName,
				displayName: accountUser.displayName,
				username: accountUser.username,
				email: accountUser.email,
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

	const overviewItems = useMemo(
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
				label: __("Editors"),
				value: formatCount(editorCount),
				icon: UserCheck,
				iconTone: "editors",
				note: __("Link creation & editing access"),
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
		[users.length, adminCount, editorCount, newestUser]
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
					userPendingDelete.displayName ||
						`${userPendingDelete.firstName} ${userPendingDelete.lastName}`.trim()
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
				<div className="users-page-hero-copy">
					<div className="users-page-hero-badge">
						<UserRound size={13} />
						<span>{__("User Management")}</span>
					</div>
					<h1 className="users-page-title">{__("Users")}</h1>
					<p className="users-page-summary">
						{__(
							"Manage user accounts and access permissions. Administrators have complete access to system settings, while Editors manage short links."
						)}
					</p>
				</div>
				<Button icon={Plus} onClick={openCreateDialog}>
					{__("Add User")}
				</Button>
			</div>

			<div className="users-page-overview">
				{isUsersLoading ? (
					<UsersOverviewSkeleton />
				) : (
					<div className="users-page-overview-grid">
						{overviewItems.map((item) => {
							const Icon = item.icon;
							return (
								<div
									key={item.key}
									className="users-page-overview-item"
								>
									<div className="users-page-overview-header">
										<div className="users-page-overview-copy">
											<p className="users-page-overview-title">
												{item.label}
											</p>
											<p
												className={cn(
													"users-page-overview-value",
													item.isTextValue &&
														"users-page-overview-value-text"
												)}
											>
												{item.value}
											</p>
										</div>
										<div
											className={cn(
												"users-page-overview-icon",
												`users-page-overview-icon-${item.iconTone}`
											)}
										>
											<Icon className="users-page-overview-icon-glyph" />
										</div>
									</div>
									<p
										className="users-page-overview-note"
										dir="auto"
									>
										{item.note}
									</p>
								</div>
							);
						})}
					</div>
				)}
			</div>

			<div className="users-page-panel">
				<div className="users-page-panel-header">
					<div className="users-page-panel-header-main">
						<h2 className="users-page-panel-title">
							{__("Accounts")}
						</h2>
						{!isUsersLoading && users.length > 0 ? (
							<span className="users-page-panel-count">
								{formatCount(users.length)}
							</span>
						) : null}
					</div>
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
							<Users size={24} />
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
															<span
																dir="auto"
																className="font-semibold text-heading text-sm"
															>
																{user.displayName ||
																	`${user.firstName} ${user.lastName}`.trim() ||
																	user.username}
															</span>
															{user.id ===
															currentUser?.id ? (
																<span className="users-page-self-badge">
																	{__("You")}
																</span>
															) : null}
														</div>
														<div className="users-page-user-meta">
															<span
																className="users-page-user-username"
																dir="ltr"
															>
																@{user.username}
															</span>
															<span className="users-page-user-dot">
																•
															</span>
															<span
																className="users-page-user-email"
																dir="ltr"
															>
																{user.email}
															</span>
														</div>
													</div>
												</div>
											</td>
											<td className="users-page-table-cell">
												<span
													className={cn(
														"users-page-role-badge",
														roleMeta[
															user.role ===
															"admin"
																? "admin"
																: "editor"
														].badge
													)}
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
												<span
													className="users-page-date-primary"
													dir="auto"
												>
													{user.createdAt
														? formatDate(
																user.createdAt
															)
														: __("Unknown")}
												</span>
												{user.createdAt ? (
													<span
														className="users-page-date-exact"
														dir="auto"
													>
														{formatLocalizedDateTime(
															user.createdAt,
															{
																dateStyle:
																	"medium",
															}
														)}
													</span>
												) : null}
											</td>
											<td className="users-page-table-cell-actions">
												<div className="users-page-actions">
													<button
														type="button"
														onClick={() =>
															openEditDialog(user)
														}
														className="users-page-action-button"
														aria-label={sprintf(
															__("Edit %s"),
															getUserDisplayName(
																user
															)
														)}
														title={sprintf(
															__("Edit %s"),
															getUserDisplayName(
																user
															)
														)}
													>
														<Pencil size={14} />
													</button>
													<button
														type="button"
														onClick={() =>
															openDeleteDialog(
																user
															)
														}
														disabled={
															!currentUser?.id ||
															user.id ===
																currentUser?.id ||
															isDeleting
														}
														className={cn(
															"users-page-action-button users-page-action-delete",
															(!currentUser?.id ||
																user.id ===
																	currentUser?.id ||
																isDeleting) &&
																"pointer-events-none cursor-not-allowed opacity-30"
														)}
														aria-label={
															user.id ===
															currentUser?.id
																? __(
																		"Cannot delete your own account"
																	)
																: sprintf(
																		__(
																			"Delete %s"
																		),
																		getUserDisplayName(
																			user
																		)
																	)
														}
														title={
															user.id ===
															currentUser?.id
																? __(
																		"Cannot delete your own account"
																	)
																: sprintf(
																		__(
																			"Delete %s"
																		),
																		getUserDisplayName(
																			user
																		)
																	)
														}
													>
														<Trash2 size={14} />
													</button>
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
								getUserDisplayName(userPendingDelete)
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
