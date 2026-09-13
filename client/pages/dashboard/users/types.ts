import type { LucideIcon } from "lucide-react";
import type { UserDialogPayload, UserRole, UserSummary } from "@/api";

export type { UserDialogPayload, UserRole, UserSummary } from "@/api";

/**
 * Supported modes for the user create/edit dialog.
 */
export type UserDialogMode = "create" | "edit";

/**
 * Editable form state for the user dialog.
 */
export interface UserDialogFormState {
	firstName: string;
	lastName: string;
	displayName: string;
	username: string;
	email: string;
	password: string;
	confirmPassword: string;
	role: UserRole;
}

/**
 * Role presentation metadata used by the dialog UI.
 */
export interface UserRoleMeta {
	label: string;
	description: string;
	badge: string;
}

export interface UserDialogProps {
	open: boolean;
	mode: UserDialogMode;
	currentUser?: UserSummary | null;
	initialUser?: UserSummary | null;
	onClose: () => void;
	onSubmit: (payload: UserDialogPayload) => Promise<unknown> | unknown;
	isSubmitting: boolean;
}

export interface UsersOverviewItem {
	key: string;
	label: string;
	value: string;
	icon: LucideIcon;
	iconTone: "all" | "admins" | "editors" | "latest";
	note: string;
	isTextValue?: boolean;
}

export interface UsersOverviewProps {
	items: UsersOverviewItem[];
	onAddUserClick: () => void;
}

export interface UsersTableProps {
	users: UserSummary[];
	currentUser: UserSummary | null;
	onEditUser: (user: UserSummary) => void;
	onDeleteUser: (user: UserSummary) => void;
	isDeleting: boolean;
	isLoading?: boolean;
	usersError?: unknown;
}

export interface UserRowProps {
	user: UserSummary;
	currentUser: UserSummary | null;
	onEdit: (user: UserSummary) => void;
	onDelete: (user: UserSummary) => void;
	isDeleting: boolean;
}

export interface DeleteUserModalProps {
	user: UserSummary | null;
	currentUser: UserSummary | null;
	onClose: () => void;
	onConfirm: () => void;
	isDeleting: boolean;
}
