import { __ } from "@/i18n";

import type {
	UserDialogFormState,
	UserDialogMode,
	UserRole,
	UserRoleMeta,
	UserSummary,
} from "../types";

export const EMPTY_USER_FORM: UserDialogFormState = {
	firstName: "",
	lastName: "",
	displayName: "",
	username: "",
	email: "",
	password: "",
	confirmPassword: "",
	role: "editor",
};

export const getRoleMeta = (): Record<UserRole, UserRoleMeta> => ({
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

export function getUserDisplayName(user?: UserSummary | null): string {
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

export function getInitialFormState(
	mode: UserDialogMode,
	initialUser?: UserSummary | null
): UserDialogFormState {
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

	return EMPTY_USER_FORM;
}
