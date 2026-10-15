import { useMemo, useState } from "react";
import { DialogBackdrop, DialogPanel } from "@headlessui/react";
import { ShieldAlert } from "lucide-react";

import type { RoleCapability, RoleDefinition } from "@/api";
import {
	DrawerDialog,
	Button,
	ConfirmDialog,
	LoadingSpinner,
	useNotification,
} from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { getErrorMessage } from "@/shared/errors";
import {
	useCreateRoleMutation,
	useDeleteRoleMutation,
	useGetRolesQuery,
	useResetRoleCapabilitiesMutation,
	useUpdateRoleCapabilitiesMutation,
} from "@/state/slices/api";

import {
	CapabilityGroupCard,
	CreateRoleDialog,
	DrawerFooter,
	EditRoleDialog,
	RoleMetadataCard,
	RolesHeader,
	RolesTabs,
} from "./components";

export interface ManageRolesDrawerProps {
	isOpen: boolean;
	onClose: () => void;
}

const GROUP_ORDER = [
	"user_access",
	"site_integrations",
	"links",
	"analytics",
] as const;

export function ManageRolesDrawer({ isOpen, onClose }: ManageRolesDrawerProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const notification = useNotification();

	const {
		data: roles = [],
		isLoading: isRolesLoading,
		error: rolesError,
		refetch: refetchRoles,
	} = useGetRolesQuery(undefined, { skip: !isOpen });

	const [updateRole, { isLoading: isUpdating }] =
		useUpdateRoleCapabilitiesMutation();
	const [resetRole, { isLoading: isResetting }] =
		useResetRoleCapabilitiesMutation();
	const [createRole, { isLoading: isCreating }] = useCreateRoleMutation();
	const [deleteRole, { isLoading: isDeleting }] = useDeleteRoleMutation();

	const isSaving = isUpdating || isResetting || isCreating || isDeleting;

	// Redesign: Administrator is an immutable superuser.
	// Only Editor and independent custom roles are configurable in this drawer.
	const configurableRoles = useMemo(() => {
		return roles.filter(
			(r) => r.isConfigurable !== false && r.id !== "admin"
		);
	}, [roles]);

	const [selectedRoleId, setSelectedRoleId] = useState<string>("editor");
	const [roleDrafts, setRoleDrafts] = useState<
		Record<string, Record<string, boolean>>
	>({});
	const [confirmResetOpen, setConfirmResetOpen] = useState(false);
	const [confirmDiscardOpen, setConfirmDiscardOpen] = useState(false);
	const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false);
	const [createDialogOpen, setCreateDialogOpen] = useState(false);
	const [editDialogOpen, setEditDialogOpen] = useState(false);

	const activeRole: RoleDefinition | undefined = useMemo(() => {
		if (!configurableRoles.length) return undefined;
		return (
			configurableRoles.find((r) => r.id === selectedRoleId) ||
			configurableRoles.find((r) => r.id === "editor") ||
			configurableRoles[0]
		);
	}, [configurableRoles, selectedRoleId]);

	const activeDraft = useMemo<Record<string, boolean>>(() => {
		if (!activeRole) return {};
		const base: Record<string, boolean> = {};
		for (const cap of activeRole.capabilities) {
			base[cap.id] = cap.applicable !== false ? cap.enabled : false;
		}
		const drafts = roleDrafts[activeRole.id] || {};
		return { ...base, ...drafts };
	}, [activeRole, roleDrafts]);

	// Dirty tracking for currently selected active role
	const isDirty = useMemo(() => {
		if (!activeRole) return false;
		const drafts = roleDrafts[activeRole.id];
		if (!drafts) return false;
		return activeRole.capabilities.some(
			(cap) =>
				cap.applicable !== false &&
				!cap.protected &&
				drafts[cap.id] !== undefined &&
				drafts[cap.id] !== cap.enabled
		);
	}, [activeRole, roleDrafts]);

	// Global dirty tracking across all configurable roles for close confirmation
	const hasAnyUnsavedChanges = useMemo(() => {
		for (const role of configurableRoles) {
			const drafts = roleDrafts[role.id];
			if (drafts) {
				const isRoleDirty = role.capabilities.some(
					(cap) =>
						cap.applicable !== false &&
						!cap.protected &&
						drafts[cap.id] !== undefined &&
						drafts[cap.id] !== cap.enabled
				);
				if (isRoleDirty) {
					return true;
				}
			}
		}
		return false;
	}, [configurableRoles, roleDrafts]);

	const handleToggleCapability = (capId: string, nextValue: boolean) => {
		if (!activeRole) return;
		setRoleDrafts((prev) => ({
			...prev,
			[activeRole.id]: {
				...(prev[activeRole.id] || {}),
				[capId]: nextValue,
			},
		}));
	};

	const handleSaveChanges = async () => {
		if (!activeRole || !isDirty || isSaving) return;

		const targetRoleId = activeRole.id;
		const targetRoleName = activeRole.name;

		const changedCapabilities: Record<string, boolean> = {};
		for (const cap of activeRole.capabilities) {
			if (cap.applicable === false || cap.protected) continue;
			const draftVal = roleDrafts[targetRoleId]?.[cap.id];
			if (draftVal !== undefined && draftVal !== cap.enabled) {
				changedCapabilities[cap.id] = draftVal;
			}
		}

		if (Object.keys(changedCapabilities).length === 0) return;

		try {
			await updateRole({
				role: targetRoleId,
				capabilities: changedCapabilities,
			}).unwrap();

			setRoleDrafts((prev) => {
				const next = { ...prev };
				delete next[targetRoleId];
				return next;
			});

			notification.success(
				__("Role capabilities updated"),
				sprintf(
					/* translators: %s is the role display name */
					__("Capabilities for %s have been saved successfully."),
					targetRoleName
				)
			);
		} catch (error) {
			notification.error(
				__("Failed to save capabilities"),
				getErrorMessage(
					error,
					__("An error occurred while updating role capabilities.")
				)
			);
		}
	};

	const handleConfirmReset = async () => {
		if (!activeRole || isSaving) return;

		const targetRoleId = activeRole.id;
		const targetRoleName = activeRole.name;
		const isBuiltin = activeRole.isBuiltin;

		try {
			await resetRole({ role: targetRoleId }).unwrap();

			setRoleDrafts((prev) => {
				const next = { ...prev };
				delete next[targetRoleId];
				return next;
			});

			setConfirmResetOpen(false);

			notification.success(
				isBuiltin
					? __("Role reset to defaults")
					: __("Role reset to snapshot"),
				sprintf(
					/* translators: %s is the role display name */
					isBuiltin
						? __(
								"Capabilities for %s have been restored to shipped defaults."
							)
						: __(
								"Capabilities for %s have been restored to its creation snapshot."
							),
					targetRoleName
				)
			);
		} catch (error) {
			notification.error(
				__("Failed to reset role"),
				getErrorMessage(
					error,
					__("An error occurred while resetting role capabilities.")
				)
			);
		}
	};

	const handleCreateRole = async (payload: {
		id: string;
		name: string;
		description: string;
	}) => {
		try {
			await createRole(payload).unwrap();
			setSelectedRoleId(payload.id);
			setCreateDialogOpen(false);
			notification.success(
				__("Custom role created"),
				sprintf(
					/* translators: %s is role display name */
					__(
						"Custom role '%s' created from current Editor permissions."
					),
					payload.name
				)
			);
		} catch (error) {
			notification.error(
				__("Failed to create role"),
				getErrorMessage(error, __("Could not create custom role."))
			);
			throw error;
		}
	};

	const handleEditRole = async (payload: {
		role: string;
		name: string;
		description: string;
	}) => {
		try {
			await updateRole({
				role: payload.role,
				name: payload.name,
				description: payload.description,
			}).unwrap();
			setEditDialogOpen(false);
			notification.success(
				__("Role updated"),
				sprintf(
					/* translators: %s is role display name */
					__("Custom role '%s' details updated successfully."),
					payload.name
				)
			);
		} catch (error) {
			notification.error(
				__("Failed to update role"),
				getErrorMessage(error, __("Could not update role details."))
			);
			throw error;
		}
	};

	const handleConfirmDelete = async () => {
		if (!activeRole || activeRole.isBuiltin || isSaving) return;

		if (activeRole.userCount > 0) {
			notification.error(
				__("Cannot delete role"),
				sprintf(
					/* translators: %d is user count */
					__(
						"Cannot delete role: %d user(s) currently have this role assigned. Reassign all users first."
					),
					activeRole.userCount
				)
			);
			setConfirmDeleteOpen(false);
			return;
		}

		const deletedName = activeRole.name;
		const deletedId = activeRole.id;

		try {
			await deleteRole({ role: deletedId }).unwrap();

			setRoleDrafts((prev) => {
				const next = { ...prev };
				delete next[deletedId];
				return next;
			});

			setSelectedRoleId("editor");
			setConfirmDeleteOpen(false);

			notification.success(
				__("Role deleted"),
				sprintf(
					/* translators: %s is the role display name */
					__("Custom role '%s' has been deleted successfully."),
					deletedName
				)
			);
		} catch (error) {
			notification.error(
				__("Failed to delete role"),
				getErrorMessage(error, __("Could not delete custom role."))
			);
		}
	};

	const handleAttemptClose = () => {
		if (isSaving) return;
		if (hasAnyUnsavedChanges) {
			setConfirmDiscardOpen(true);
		} else {
			setRoleDrafts({});
			onClose();
		}
	};

	const handleConfirmDiscard = () => {
		setRoleDrafts({});
		setConfirmDiscardOpen(false);
		onClose();
	};

	const groupedCapabilities = useMemo(() => {
		if (!activeRole) return [];

		const groupsMap = new Map<string, RoleCapability[]>();
		for (const cap of activeRole.capabilities) {
			const groupKey = cap.group || "other";
			if (!groupsMap.has(groupKey)) {
				groupsMap.set(groupKey, []);
			}
			groupsMap.get(groupKey)!.push(cap);
		}

		const sortedGroups: {
			key: string;
			label: string;
			capabilities: RoleCapability[];
		}[] = [];

		for (const key of GROUP_ORDER) {
			if (groupsMap.has(key)) {
				const caps = groupsMap.get(key)!;
				sortedGroups.push({
					key,
					label: caps[0]?.groupLabel || key,
					capabilities: caps,
				});
				groupsMap.delete(key);
			}
		}

		for (const [key, caps] of groupsMap.entries()) {
			sortedGroups.push({
				key,
				label: caps[0]?.groupLabel || key,
				capabilities: caps,
			});
		}

		return sortedGroups;
	}, [activeRole]);

	return (
		<>
			<DrawerDialog open={isOpen} onClose={handleAttemptClose}>
				<DialogBackdrop transition className="dialog-backdrop" />

				<div className="fixed inset-0 overflow-hidden">
					<div
						className={`users-roles-drawer-layout ${
							isRtl
								? "users-roles-drawer-layout-rtl"
								: "users-roles-drawer-layout-ltr"
						}`}
					>
						<DialogPanel
							dir={direction}
							transition
							className={`drawer-panel drawer-transition users-roles-drawer-panel ${
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							}`}
						>
							{/* Drawer Header */}
							<RolesHeader
								onClose={handleAttemptClose}
								disabled={isSaving}
							/>

							{/* Drawer Body */}
							<div className="drawer-content users-roles-drawer-content">
								{isRolesLoading ? (
									<div className="flex flex-col items-center justify-center py-16 space-y-3">
										<LoadingSpinner size="lg" />
										<p className="text-xs text-text-muted">
											{__("Loading role definitions...")}
										</p>
									</div>
								) : rolesError ? (
									<div className="rounded-xl border border-rose-500/20 bg-rose-500/10 p-4 text-start space-y-2">
										<div className="flex items-center gap-2 text-sm font-semibold text-rose-600 dark:text-rose-400">
											<ShieldAlert size={16} />
											<span>
												{__("Error Loading Roles")}
											</span>
										</div>
										<p className="text-xs text-rose-600/90 dark:text-rose-300">
											{getErrorMessage(
												rolesError,
												__(
													"Could not load role definitions."
												)
											)}
										</p>
										<Button
											size="xs"
											variant="outline"
											onClick={() => refetchRoles()}
										>
											{__("Retry")}
										</Button>
									</div>
								) : (
									<div className="space-y-5">
										{/* Role Selection Tabs */}
										<RolesTabs
											roles={configurableRoles}
											selectedRoleId={
												activeRole?.id ?? "editor"
											}
											onSelectRole={setSelectedRoleId}
											roleDrafts={roleDrafts}
											onCreateRoleClick={() =>
												setCreateDialogOpen(true)
											}
											disabled={isSaving}
										/>

										{/* Active Role Meta Card */}
										{activeRole ? (
											<RoleMetadataCard
												role={activeRole}
												onEditRoleClick={() =>
													setEditDialogOpen(true)
												}
												onDeleteRoleClick={() =>
													setConfirmDeleteOpen(true)
												}
												disabled={isSaving}
											/>
										) : null}

										{/* Grouped Capabilities Matrix */}
										<div className="space-y-5">
											{groupedCapabilities.map(
												(group) => (
													<CapabilityGroupCard
														key={group.key}
														groupKey={group.key}
														groupLabel={group.label}
														capabilities={
															group.capabilities
														}
														draftValues={
															activeDraft
														}
														onToggleCapability={
															handleToggleCapability
														}
														disabled={isSaving}
													/>
												)
											)}
										</div>
									</div>
								)}
							</div>

							{/* Drawer Footer */}
							<DrawerFooter
								activeRole={activeRole}
								isDirty={isDirty}
								isSaving={isSaving}
								onResetClick={() => setConfirmResetOpen(true)}
								onCancelClick={handleAttemptClose}
								onSaveClick={handleSaveChanges}
							/>
						</DialogPanel>
					</div>
				</div>
			</DrawerDialog>

			{/* Create Role Modal */}
			<CreateRoleDialog
				isOpen={createDialogOpen}
				onClose={() => setCreateDialogOpen(false)}
				onSubmit={handleCreateRole}
				isSubmitting={isCreating}
			/>

			{/* Edit Role Modal */}
			<EditRoleDialog
				isOpen={editDialogOpen}
				role={activeRole}
				onClose={() => setEditDialogOpen(false)}
				onSubmit={handleEditRole}
				isSubmitting={isUpdating}
			/>

			{/* Confirm Reset Dialog */}
			<ConfirmDialog
				open={confirmResetOpen}
				title={
					activeRole
						? sprintf(
								/* translators: %s is the role display name */
								__("Reset %s capabilities?"),
								activeRole.name
							)
						: __("Reset role capabilities?")
				}
				description={
					activeRole
						? activeRole.isBuiltin
							? sprintf(
									/* translators: %s is the role display name */
									__(
										"This will restore the shipped default permissions for the %s role. Any custom capabilities assigned to this role will be reverted. Other roles will remain unaffected."
									),
									activeRole.name
								)
							: sprintf(
									/* translators: %s is the role display name */
									__(
										"This will restore the creation-time snapshot for the %s role. Any capability modifications made since this role was created will be reverted."
									),
									activeRole.name
								)
						: __(
								"This will restore default capabilities for the selected role."
							)
				}
				confirmText={
					activeRole?.isBuiltin
						? __("Reset to Defaults")
						: __("Reset to Snapshot")
				}
				cancelText={__("Cancel")}
				confirmVariant="danger"
				onConfirm={handleConfirmReset}
				onClose={() => setConfirmResetOpen(false)}
			/>

			{/* Confirm Delete Dialog */}
			<ConfirmDialog
				open={confirmDeleteOpen}
				title={
					activeRole
						? sprintf(
								/* translators: %s is role name */
								__("Delete %s role?"),
								activeRole.name
							)
						: __("Delete role?")
				}
				description={
					activeRole
						? sprintf(
								/* translators: %s is role name */
								__(
									"Are you sure you want to delete the custom role '%s'? This action cannot be undone."
								),
								activeRole.name
							)
						: __(
								"Are you sure you want to delete this custom role?"
							)
				}
				confirmText={__("Delete Role")}
				cancelText={__("Cancel")}
				confirmVariant="danger"
				onConfirm={handleConfirmDelete}
				onClose={() => setConfirmDeleteOpen(false)}
			/>

			{/* Confirm Discard Dialog */}
			<ConfirmDialog
				open={confirmDiscardOpen}
				title={__("Discard unsaved changes?")}
				description={__(
					"You have unsaved changes to role capabilities. If you close now, your modifications will be discarded."
				)}
				confirmText={__("Discard changes")}
				cancelText={__("Keep editing")}
				confirmVariant="danger"
				onConfirm={handleConfirmDiscard}
				onClose={() => setConfirmDiscardOpen(false)}
			/>
		</>
	);
}
