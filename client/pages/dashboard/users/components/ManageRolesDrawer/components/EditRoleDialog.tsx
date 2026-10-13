import type { FormEvent } from "react";
import { useState } from "react";

import type { RoleDefinition } from "@/api";
import { Button, Input, Modal, TextArea } from "@/components";
import { __, sprintf } from "@/i18n";
import { extractErrorMessage } from "@/shared/errors";

export interface EditRoleDialogProps {
	isOpen: boolean;
	role?: RoleDefinition;
	onClose: () => void;
	onSubmit: (payload: {
		role: string;
		name: string;
		description: string;
	}) => Promise<void> | void;
	isSubmitting?: boolean;
}

export function EditRoleDialog({
	isOpen,
	role,
	onClose,
	onSubmit,
	isSubmitting = false,
}: EditRoleDialogProps) {
	const [name, setName] = useState(() => role?.name || "");
	const [description, setDescription] = useState(
		() => role?.description || ""
	);
	const [error, setError] = useState<string | null>(null);

	const [prevSyncKey, setPrevSyncKey] = useState(() =>
		isOpen && role ? `${role.id}-${role.name}-${role.description}` : ""
	);

	const currentSyncKey =
		isOpen && role ? `${role.id}-${role.name}-${role.description}` : "";

	if (currentSyncKey !== prevSyncKey) {
		setPrevSyncKey(currentSyncKey);
		if (isOpen && role) {
			setName(role.name || "");
			setDescription(role.description || "");
			setError(null);
		}
	}

	const handleSubmit = async (e: FormEvent) => {
		e.preventDefault();
		if (!role) return;
		setError(null);

		const cleanName = name.trim();
		const cleanDesc = description.trim();

		if (!cleanName) {
			setError(__("Role name is required."));
			return;
		}

		try {
			await onSubmit({
				role: role.id,
				name: cleanName,
				description: cleanDesc,
			});
			onClose();
		} catch (err: unknown) {
			setError(
				extractErrorMessage(err) || __("Failed to update role details.")
			);
		}
	};

	return (
		<Modal
			isOpen={isOpen}
			onClose={isSubmitting ? () => {} : onClose}
			title={
				role
					? sprintf(
							/* translators: %s is role name */
							__("Edit %s"),
							role.name
						)
					: __("Edit Role")
			}
			size="md"
		>
			<form onSubmit={handleSubmit} className="space-y-4">
				{error && (
					<div className="rounded-lg border border-rose-500/20 bg-rose-500/10 p-3 text-start text-xs text-rose-600 dark:text-rose-400">
						{error}
					</div>
				)}

				<Input
					label={__("Role Display Name")}
					value={name}
					onChange={(e) => setName(e.target.value)}
					required
					disabled={isSubmitting}
				/>

				<div className="form-field">
					<label className="form-field-label settings-general-readonly-label">
						<span>{__("Role Identifier")}</span>
						<span className="settings-general-readonly-note">
							{__("Cannot be changed")}
						</span>
					</label>
					<div className="form-field-control">
						<input
							type="text"
							value={role?.id || ""}
							disabled
							className="form-control-base form-field-input form-field-input-no-icon settings-general-readonly-input"
						/>
					</div>
				</div>

				<TextArea
					label={__("Description")}
					value={description}
					onChange={(e) => setDescription(e.target.value)}
					placeholder={__(
						"Optional summary of what this role is for..."
					)}
					rows={3}
					disabled={isSubmitting}
				/>

				<div className="flex items-center justify-end gap-2.5 pt-2 border-t border-stroke">
					<Button
						type="button"
						variant="secondary"
						size="sm"
						onClick={onClose}
						disabled={isSubmitting}
					>
						{__("Cancel")}
					</Button>
					<Button
						type="submit"
						size="sm"
						disabled={isSubmitting || !name.trim()}
					>
						{isSubmitting ? __("Saving...") : __("Save Changes")}
					</Button>
				</div>
			</form>
		</Modal>
	);
}
