import { useMemo, useState } from "react";
import { Dialog, DialogBackdrop, DialogPanel } from "@headlessui/react";
import { ShieldAlert } from "lucide-react";

import type { RoleCapability, RoleDefinition } from "@/api";
import {
	Button,
	ConfirmDialog,
	LoadingSpinner,
	useNotification,
} from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { getErrorMessage } from "@/shared/errors";
import {
	useGetRolesQuery,
	useResetRoleCapabilitiesMutation,
	useUpdateRoleCapabilitiesMutation,
} from "@/state/slices/api";

import {
	CapabilityGroupCard,
	DrawerFooter,
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

	const isSaving = isUpdating || isResetting;

	const [selectedRoleId, setSelectedRoleId] = useState<string>("admin");
	const [roleDrafts, setRoleDrafts] = useState<
		Record<string, Record<string, boolean>>
	>({});
	const [confirmResetOpen, setConfirmResetOpen] = useState(false);
	const [confirmDiscardOpen, setConfirmDiscardOpen] = useState(false);

	const activeRole: RoleDefinition | undefined = useMemo(() => {
		if (!roles.length) return undefined;
		return roles.find((r) => r.id === selectedRoleId) || roles[0];
	}, [roles, selectedRoleId]);

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

	// Global dirty tracking across all roles for close confirmation
	const hasAnyUnsavedChanges = useMemo(() => {
		for (const role of roles) {
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
	}, [roles, roleDrafts]);

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

		// Submit only changed, applicable, editable capability keys as partial/delta payload
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

			// Clear drafts for this role now that saved server state matches
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

		try {
			await resetRole({ role: targetRoleId }).unwrap();

			// Clear local drafts for this role
			setRoleDrafts((prev) => {
				const next = { ...prev };
				delete next[targetRoleId];
				return next;
			});

			setConfirmResetOpen(false);

			notification.success(
				__("Role reset to defaults"),
				sprintf(
					/* translators: %s is the role display name */
					__(
						"Capabilities for %s have been restored to shipped defaults."
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

	// Consistent close handler routed through unsaved change guard
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

	// Group capabilities by functional category
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

		// Sort by canonical order
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

		// Append any remaining unknown groups
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
			<Dialog
				open={isOpen}
				onClose={handleAttemptClose}
				className="relative z-50"
			>
				<DialogBackdrop
					transition
					className="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity duration-300 ease-out data-closed:opacity-0"
				/>

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
							className={`users-roles-drawer-panel ${
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
							<div className="users-roles-drawer-content">
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
											roles={roles}
											selectedRoleId={
												activeRole?.id ?? "admin"
											}
											onSelectRole={setSelectedRoleId}
											roleDrafts={roleDrafts}
											disabled={isSaving}
										/>

										{/* Active Role Meta Card */}
										{activeRole ? (
											<RoleMetadataCard
												role={activeRole}
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
			</Dialog>

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
						? sprintf(
								/* translators: %s is the role display name */
								__(
									"This will restore the shipped default permissions for the %s role. Any custom capabilities assigned to this role will be reverted. Other roles will remain unaffected."
								),
								activeRole.name
							)
						: __(
								"This will restore the shipped default permissions for the selected role."
							)
				}
				confirmText={__("Reset to Defaults")}
				cancelText={__("Cancel")}
				confirmVariant="danger"
				onConfirm={handleConfirmReset}
				onClose={() => setConfirmResetOpen(false)}
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
