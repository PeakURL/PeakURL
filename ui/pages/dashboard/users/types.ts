import type { UserDialogPayload, UserRole, UserSummary } from "@/api";

export type { UserDialogPayload, UserRole, UserSummary } from "@/api";

/**
 * Supported modes for the user create/edit dialog.
 */
export type UserDialogMode = "create" | "edit";

/**
 * Editable form state for the user dialog.
 *
 * Stores both profile fields and the temporary password confirmation values
 * needed while creating or updating a user account.
 */
export interface UserDialogFormState {
	/** User first name entered in the dialog. */
	firstName: string;

	/** User last name entered in the dialog. */
	lastName: string;

	/** Unique dashboard username. */
	username: string;

	/** Account email address. */
	email: string;

	/** Password entered for create or password reset flows. */
	password: string;

	/** Confirmation value used to validate the password field. */
	confirmPassword: string;

	/** Role selected for the user account. */
	role: UserRole;
}

/**
 * Role presentation metadata used by the dialog UI.
 */
export interface UserRoleMeta {
	/** Short role label rendered in the UI. */
	label: string;

	/** Supporting description shown below the label. */
	description: string;

	/** Badge utility classes applied to the rendered pill. */
	badge: string;
}

/**
 * Props for the reusable user create/edit dialog.
 */
export interface UserDialogProps {
	/** Whether the dialog is currently visible. */
	open: boolean;

	/** Active dialog mode. */
	mode: UserDialogMode;

	/** Currently signed-in user, when available. */
	currentUser?: UserSummary | null;

	/** Existing user being edited, when applicable. */
	initialUser?: UserSummary | null;

	/** Closes the dialog without saving. */
	onClose: () => void;

	/** Persists the submitted user payload. */
	onSubmit: (payload: UserDialogPayload) => Promise<unknown> | unknown;

	/** Indicates whether the save action is still in flight. */
	isSubmitting: boolean;
}
