import type { FormEvent } from "react";
import { useState } from "react";
import { Info } from "lucide-react";

import { Button, Input, Modal, TextArea } from "@/components";
import { __ } from "@/i18n";
import { extractErrorMessage } from "@/shared/errors";

import { isValidRoleId, suggestRoleId } from "../../../lib";

export interface CreateRoleDialogProps {
	isOpen: boolean;
	onClose: () => void;
	onSubmit: (payload: {
		id: string;
		name: string;
		description: string;
	}) => Promise<void> | void;
	isSubmitting?: boolean;
}

export function CreateRoleDialog({
	isOpen,
	onClose,
	onSubmit,
	isSubmitting = false,
}: CreateRoleDialogProps) {
	const [name, setName] = useState("");
	const [slug, setSlug] = useState("");
	const [description, setDescription] = useState("");
	const [isCustomSlug, setIsCustomSlug] = useState(false);
	const [error, setError] = useState<string | null>(null);

	const handleNameChange = (val: string) => {
		setName(val);
		if (!isCustomSlug) {
			setSlug(suggestRoleId(val));
		}
	};

	const handleSlugChange = (val: string) => {
		setIsCustomSlug(true);
		setSlug(val);
	};

	const handleSubmit = async (e: FormEvent) => {
		e.preventDefault();
		setError(null);

		const cleanName = name.trim();
		const cleanSlug = slug;
		const cleanDesc = description.trim();

		if (!cleanName) {
			setError(__("Role name is required."));
			return;
		}

		if (cleanName.length > 100) {
			setError(__("Role name must be between 1 and 100 characters."));
			return;
		}

		if (!cleanSlug || !isValidRoleId(cleanSlug)) {
			setError(
				__(
					"Role identifier must be 1–32 characters, start with a lowercase letter, and contain only lowercase letters, digits, and single underscores."
				)
			);
			return;
		}

		if (cleanSlug === "admin" || cleanSlug === "editor") {
			setError(__("The role identifier is reserved."));
			return;
		}

		if (cleanDesc.length > 500) {
			setError(__("Role description must not exceed 500 characters."));
			return;
		}

		try {
			await onSubmit({
				id: cleanSlug,
				name: cleanName,
				description: cleanDesc,
			});
			setName("");
			setSlug("");
			setDescription("");
			setIsCustomSlug(false);
			setError(null);
			onClose();
		} catch (err: unknown) {
			setError(
				extractErrorMessage(err) || __("Failed to create custom role.")
			);
		}
	};

	return (
		<Modal
			isOpen={isOpen}
			onClose={isSubmitting ? () => {} : onClose}
			title={__("Create Custom Role")}
			size="md"
		>
			<form onSubmit={handleSubmit} className="space-y-4">
				<div className="rounded-lg border border-accent/20 bg-accent/5 p-3 text-start flex items-start gap-2 text-xs text-text-muted leading-relaxed">
					<Info size={16} className="text-accent shrink-0 mt-0.5" />
					<span>
						{__(
							"The new role will clone the current Editor capability snapshot. You can customize its permissions independently, and future changes to Editor will not affect this role."
						)}
					</span>
				</div>

				{error && (
					<div className="rounded-lg border border-rose-500/20 bg-rose-500/10 p-3 text-start text-xs text-rose-600 dark:text-rose-400">
						{error}
					</div>
				)}

				<Input
					label={__("Role Display Name")}
					value={name}
					onChange={(e) => handleNameChange(e.target.value)}
					placeholder={__("e.g. Content Manager")}
					required
					disabled={isSubmitting}
				/>

				<Input
					label={__("Role Identifier")}
					value={slug}
					onChange={(e) => handleSlugChange(e.target.value)}
					placeholder={__("e.g. content_manager")}
					helperText={__(
						"Unique machine key up to 32 characters. Lowercase letters, numbers, and underscores (e.g. content_manager)."
					)}
					required
					disabled={isSubmitting}
				/>

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
						disabled={isSubmitting || !name.trim() || !slug.trim()}
					>
						{isSubmitting ? __("Creating...") : __("Create Role")}
					</Button>
				</div>
			</form>
		</Modal>
	);
}
