import type { ChangeEvent, SubmitEvent } from "react";
import { useState } from "react";
import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
import { X } from "lucide-react";

import { Button, Input, Select, type SelectOption } from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { getErrorMessage } from "@/utils";

import { getInitialFormState, getRoleMeta } from "../../lib";
import type {
	UserDialogFormState,
	UserDialogPayload,
	UserDialogProps,
	UserRole,
} from "../../types";

export function UserDialog({
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
