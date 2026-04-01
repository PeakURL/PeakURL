// @ts-nocheck
'use client';

import { useMemo, useState } from 'react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import {
	Pencil,
	Plus,
	ShieldCheck,
	Trash2,
	UserRound,
	Users,
	X,
} from 'lucide-react';
import { useNotification } from '@/components';
import { useAdminAccess } from '@/hooks';
import { Button, ConfirmDialog, IconButton, Input } from '@/components/ui';
import {
	useCreateUserMutation,
	useDeleteUserMutation,
	useGetAllUsersQuery,
	useGetUserProfileQuery,
	useUpdateUserMutation,
} from '@/store/slices/api/user';

const EMPTY_FORM = {
	firstName: '',
	lastName: '',
	username: '',
	email: '',
	password: '',
	role: 'editor',
};

const ROLE_META = {
	admin: {
		label: 'Admin',
		description: 'Can manage users, settings, and all links.',
		badge: 'bg-red-500/10 text-red-700 dark:text-red-300 border border-red-500/20',
	},
	editor: {
		label: 'Editor',
		description: 'Can create, edit, and delete site links without admin access.',
		badge: 'bg-blue-500/10 text-blue-700 dark:text-blue-300 border border-blue-500/20',
	},
};

const getInitialFormState = (mode, initialUser) => {
	if ('edit' === mode && initialUser) {
		return {
			firstName: initialUser.firstName ?? '',
			lastName: initialUser.lastName ?? '',
			username: initialUser.username ?? '',
			email: initialUser.email ?? '',
			password: '',
			role: initialUser.role ?? 'editor',
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
}) {
	const [form, setForm] = useState(() =>
		getInitialFormState(mode, initialUser)
	);
	const [formError, setFormError] = useState( '' );

	const handleChange = ( key ) => ( event ) => {
		setForm( ( previous ) => ( {
			...previous,
			[ key ]: event.target.value,
		} ) );
	};

	const handleSubmit = async ( event ) => {
		event.preventDefault();
		setFormError( '' );

		const payload = {
			firstName: form.firstName.trim(),
			lastName: form.lastName.trim(),
			username: form.username.trim(),
			email: form.email.trim(),
			role: form.role,
		};

		if ( 'create' === mode || '' !== form.password.trim() ) {
			payload.password = form.password;
		}

		try {
			await onSubmit( payload );
			onClose();
		} catch ( error ) {
			setFormError( error?.data?.message || 'Unable to save the user.' );
		}
	};

	const isEditingSelf =
		'edit' === mode && initialUser?.id === currentUser?.id;

	return (
		<Dialog open={open} onClose={onClose} className="relative z-50">
			<div className="fixed inset-0 bg-black/40" aria-hidden="true" />
			<div className="fixed inset-0 flex items-center justify-center p-4">
				<DialogPanel className="w-full max-w-2xl rounded-2xl border border-stroke bg-surface shadow-2xl">
					<div className="flex items-start justify-between border-b border-stroke px-6 py-5">
						<div>
							<DialogTitle className="text-lg font-semibold text-heading">
								{'create' === mode ? 'Add User' : 'Edit User'}
							</DialogTitle>
							<p className="mt-1 text-sm text-text-muted">
								{'create' === mode
									? 'Create a new account with admin or editor access.'
									: 'Update the account details and role for this user.'}
							</p>
						</div>
						<button
							type="button"
							onClick={onClose}
							className="rounded-lg p-2 text-text-muted transition-colors hover:bg-surface-alt hover:text-heading"
						>
							<X size={18} />
						</button>
					</div>

					<form
						onSubmit={handleSubmit}
						className="space-y-5 px-6 py-6"
					>
						{formError && (
							<div className="rounded-lg border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300">
								{formError}
							</div>
						)}

						<div className="grid gap-4 sm:grid-cols-2">
							<Input
								label="First Name"
								value={form.firstName}
								onChange={handleChange('firstName')}
								required
							/>
							<Input
								label="Last Name"
								value={form.lastName}
								onChange={handleChange('lastName')}
								required
							/>
						</div>

						<div className="grid gap-4 sm:grid-cols-2">
							<Input
								label="Username"
								value={form.username}
								onChange={handleChange('username')}
								required
							/>
							<Input
								label="Email"
								type="email"
								value={form.email}
								onChange={handleChange('email')}
								required
							/>
						</div>

						<div className="grid gap-4 sm:grid-cols-2">
							<Input
								label={
									'create' === mode
										? 'Password'
										: 'New Password'
								}
								type="password"
								value={form.password}
								onChange={handleChange('password')}
								required={'create' === mode}
								helperText={
									'create' === mode
										? 'Use at least 8 characters.'
										: 'Leave blank to keep the current password.'
								}
							/>
							<div className="space-y-2">
								<label className="block text-sm font-semibold text-heading">
									Role
								</label>
								<select
									value={form.role}
									onChange={handleChange('role')}
									disabled={isEditingSelf}
									className="w-full rounded-md border border-stroke bg-surface px-4 py-2 text-heading outline-none transition-all focus:border-accent focus:ring-2 focus:ring-accent disabled:cursor-not-allowed disabled:opacity-60"
								>
									<option value="admin">Admin</option>
									<option value="editor">Editor</option>
								</select>
								<p className="text-xs text-text-muted">
									{ROLE_META[form.role]?.description}
									{isEditingSelf
										? ' Your own role is locked here.'
										: ''}
								</p>
							</div>
						</div>

						<div className="flex justify-end gap-3 border-t border-stroke pt-4">
							<Button
								type="button"
								variant="secondary"
								onClick={onClose}
							>
								Cancel
							</Button>
							<Button type="submit" loading={isSubmitting}>
								{'create' === mode
									? 'Create User'
									: 'Save Changes'}
							</Button>
						</div>
					</form>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

function UsersPage() {
	const { data: userData, isLoading: isProfileLoading } =
		useGetUserProfileQuery();
	const currentUser = userData?.data;
	const { canManageUsers } = useAdminAccess();
	const {
		data: usersData,
		isLoading: isUsersLoading,
		error: usersError,
	} = useGetAllUsersQuery( undefined, { skip: ! canManageUsers } );
	const [ createUser, { isLoading: isCreating } ] = useCreateUserMutation();
	const [ updateUser, { isLoading: isUpdating } ] = useUpdateUserMutation();
	const [ deleteUser, { isLoading: isDeleting } ] = useDeleteUserMutation();
	const [ dialogMode, setDialogMode ] = useState( 'create' );
	const [ activeUser, setActiveUser ] = useState( null );
	const [ isDialogOpen, setIsDialogOpen ] = useState( false );
	const [ userPendingDelete, setUserPendingDelete ] = useState( null );
	const notification = useNotification();

	const users = useMemo(
		() => usersData?.data ?? [],
		[ usersData?.data ],
	);
	const adminCount = useMemo(
		() => users.filter( ( user ) => user.role === 'admin' ).length,
		[ users ],
	);
	const editorCount = useMemo(
		() => users.filter( ( user ) => user.role === 'editor' ).length,
		[ users ],
	);

	const openCreateDialog = () => {
		setDialogMode( 'create' );
		setActiveUser( null );
		setIsDialogOpen( true );
	};

	const openEditDialog = ( user ) => {
		setDialogMode( 'edit' );
		setActiveUser( user );
		setIsDialogOpen( true );
	};

	const openDeleteDialog = ( user ) => {
		if ( user.id === currentUser?.id ) {
			return;
		}

		setUserPendingDelete( user );
	};

	const handleDelete = async () => {
		if ( ! userPendingDelete || userPendingDelete.id === currentUser?.id ) {
			return;
		}

		try {
			await deleteUser( userPendingDelete.username ).unwrap();
			notification.success(
				'User deleted',
				`${ userPendingDelete.firstName } ${ userPendingDelete.lastName } was removed successfully.`,
			);
			setUserPendingDelete( null );
		} catch ( error ) {
			notification.error(
				'Delete failed',
				error?.data?.message || 'Unable to delete this user right now.',
			);
		}
	};

	const handleSubmitUser = async ( payload ) => {
		if ( 'create' === dialogMode ) {
			return createUser( payload ).unwrap();
		}

		return updateUser( {
			currentUsername: activeUser.username,
			...payload,
		} ).unwrap();
	};

	if ( isProfileLoading ) {
		return (
			<div className="rounded-lg border border-stroke bg-surface p-10 text-center">
				<div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-accent" />
			</div>
		);
	}

	if ( ! canManageUsers ) {
		return (
			<div className="rounded-xl border border-stroke bg-surface p-10 text-center">
				<div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500/10 text-amber-600">
					<ShieldCheck size={28} />
				</div>
				<h2 className="text-xl font-semibold text-heading">
					Admin access required
				</h2>
				<p className="mt-2 text-sm text-text-muted">
					Only admin accounts can manage other users and their roles.
				</p>
			</div>
		);
	}

	return (
		<div className="space-y-5">
			<div className="flex flex-col gap-4 rounded-xl border border-stroke bg-surface px-6 py-5 sm:flex-row sm:items-end sm:justify-between">
				<div>
					<div className="mb-3 inline-flex items-center gap-2 rounded-full bg-accent/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-accent">
						<UserRound size={14} />
						User Access
					</div>
					<h1 className="text-2xl font-semibold text-heading">
						Users
					</h1>
					<p className="mt-2 max-w-2xl text-sm text-text-muted">
						Manage the people who can access this installation.
						Admins control site-wide settings. Editors can create
						and maintain links.
					</p>
				</div>
				<Button icon={Plus} onClick={openCreateDialog}>
					Add User
				</Button>
			</div>

			<div className="grid gap-4 md:grid-cols-3">
				<div className="rounded-xl border border-stroke bg-surface p-5">
					<p className="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">
						Total Users
					</p>
					<p className="mt-3 text-3xl font-semibold text-heading">
						{ users.length }
					</p>
				</div>
				<div className="rounded-xl border border-stroke bg-surface p-5">
					<p className="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">
						Admins
					</p>
					<p className="mt-3 text-3xl font-semibold text-heading">
						{ adminCount }
					</p>
				</div>
				<div className="rounded-xl border border-stroke bg-surface p-5">
					<p className="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">
						Editors
					</p>
					<p className="mt-3 text-3xl font-semibold text-heading">
						{ editorCount }
					</p>
				</div>
			</div>

			<div className="overflow-hidden rounded-xl border border-stroke bg-surface">
				<div className="border-b border-stroke px-6 py-4">
					<h2 className="text-sm font-semibold uppercase tracking-[0.16em] text-text-muted">
						Accounts
					</h2>
				</div>

				{ isUsersLoading ? (
					<div className="p-10 text-center">
						<div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-accent" />
					</div>
				) : usersError ? (
					<div className="p-6 text-sm text-red-600 dark:text-red-300">
						{ usersError?.data?.message || 'Unable to load users.' }
					</div>
				) : users.length === 0 ? (
					<div className="p-10 text-center">
						<div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-surface-alt text-text-muted">
							<Users size={28} />
						</div>
						<h3 className="text-lg font-semibold text-heading">
							No users yet
						</h3>
						<p className="mt-2 text-sm text-text-muted">
							Add an admin or editor account to start sharing access.
						</p>
					</div>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-stroke">
							<thead className="bg-surface-alt">
								<tr className="text-left text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">
									<th className="px-6 py-4">User</th>
									<th className="px-6 py-4">Role</th>
									<th className="px-6 py-4">Created</th>
									<th className="px-6 py-4 text-right">Actions</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-stroke">
								{ users.map( ( user ) => (
									<tr key={ user.id } className="hover:bg-surface-alt/60">
										<td className="px-6 py-4">
											<div className="flex items-center gap-3">
												<div className="flex h-11 w-11 items-center justify-center rounded-full bg-accent/10 font-semibold text-accent">
													{ `${ user.firstName?.[ 0 ] || '' }${ user.lastName?.[ 0 ] || '' }` || 'U' }
												</div>
												<div>
													<div className="font-medium text-heading">
														{ user.firstName } { user.lastName }
													</div>
													<div className="text-sm text-text-muted">
														{ user.email }
													</div>
													<div className="text-xs text-text-muted">
														@{ user.username }
													</div>
												</div>
											</div>
										</td>
										<td className="px-6 py-4">
											<span
												className={ `inline-flex rounded-full px-3 py-1 text-xs font-semibold ${ ROLE_META[ user.role ]?.badge || ROLE_META.editor.badge }` }
											>
												{ ROLE_META[ user.role ]?.label || 'Editor' }
											</span>
										</td>
										<td className="px-6 py-4 text-sm text-text-muted">
											{ new Date( user.createdAt ).toLocaleDateString() }
										</td>
										<td className="px-6 py-4">
											<div className="flex justify-end gap-2">
												<IconButton
													icon={Pencil}
													variant="outline"
													size="sm"
													aria-label={`Edit ${ user.firstName } ${ user.lastName }`}
													title={`Edit ${ user.firstName } ${ user.lastName }`}
													onClick={() => openEditDialog( user )}
												/>
												<IconButton
													icon={Trash2}
													variant="outline"
													size="sm"
													aria-label={`Delete ${ user.firstName } ${ user.lastName }`}
													title={`Delete ${ user.firstName } ${ user.lastName }`}
													className="text-red-600 hover:border-red-500 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10"
													disabled={
														user.id === currentUser?.id || isDeleting
													}
													onClick={() => openDeleteDialog( user )}
												/>
											</div>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</div>

			<UserDialog
				key={`${dialogMode}-${activeUser?.id ?? 'new'}-${isDialogOpen ? 'open' : 'closed'}`}
				open={isDialogOpen}
				mode={dialogMode}
				currentUser={currentUser}
				initialUser={activeUser}
				onClose={() => setIsDialogOpen( false )}
				onSubmit={handleSubmitUser}
				isSubmitting={isCreating || isUpdating}
			/>
			<ConfirmDialog
				open={ Boolean( userPendingDelete ) }
				onClose={() => setUserPendingDelete( null )}
				title="Delete User"
				description={
					userPendingDelete
						? `Delete ${ userPendingDelete.firstName } ${ userPendingDelete.lastName }? This will revoke their sessions, remove their API keys, and permanently delete their account.`
						: ''
				}
				confirmText="Delete User"
				confirmVariant="danger"
				onConfirm={handleDelete}
				loading={isDeleting}
			/>
			<notification.NotificationContainer />
		</div>
	);
}

export default UsersPage;
