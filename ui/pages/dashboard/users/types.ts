/**
 * Supported modes for the user create/edit dialog.
 */
export type UserDialogMode = "create" | "edit";

/**
 * Canonical user roles supported by the self-hosted dashboard.
 */
export type UserRole = "admin" | "editor";

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
 * Summary user shape returned by the users list API.
 */
export interface UserSummary {
	/** Stable user identifier. */
	id: string;

	/** Optional first name saved for the account. */
	firstName?: string | null;

	/** Optional last name saved for the account. */
	lastName?: string | null;

	/** Username used to sign in to the dashboard. */
	username?: string | null;

	/** Email address associated with the account. */
	email?: string | null;

	/** Current role assigned to the account. */
	role?: UserRole | null;

	/** Account creation timestamp, when available. */
	createdAt?: string | null;
}

/**
 * Mutation payload submitted when creating or updating a user.
 */
export interface UserDialogPayload {
	/** First name to save for the account. */
	firstName: string;

	/** Last name to save for the account. */
	lastName: string;

	/** Username to save for the account. */
	username: string;

	/** Email address to save for the account. */
	email: string;

	/** Role to assign to the account. */
	role: UserRole;

	/** Optional password included for new users or password changes. */
	password?: string;
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
