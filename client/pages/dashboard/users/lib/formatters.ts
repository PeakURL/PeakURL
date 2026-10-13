import { __ } from "@/i18n";

import type {
	UserDialogFormState,
	UserDialogMode,
	UserRoleMeta,
	UserSummary,
} from "../types";

export const ROLE_ID_PATTERN = /^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/;
export const MAX_ROLE_ID_LENGTH = 32;

/**
 * Validates that a role identifier conforms to the strict canonical snake_case contract.
 */
export function isValidRoleId(id: string): boolean {
	if (!id || id.length > MAX_ROLE_ID_LENGTH) {
		return false;
	}
	return ROLE_ID_PATTERN.test(id);
}

/**
 * Suggests a canonical snake_case role identifier from a human-readable display name.
 */
export function suggestRoleId(name: string): string {
	const normalized = name
		.normalize("NFKD")
		.replace(/[\u0300-\u036f]/g, "")
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, "_")
		.replace(/^_+|_+$/g, "")
		.replace(/_+/g, "_");

	if (!normalized || !/^[a-z]/.test(normalized)) {
		return "";
	}

	return normalized.slice(0, MAX_ROLE_ID_LENGTH).replace(/_+$/g, "");
}

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

export const getRoleMeta = (): Record<string, UserRoleMeta> => ({
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

export function resolveUserRoleMeta(
	roleSlug?: string | null,
	roleName?: string | null
): UserRoleMeta {
	const slug = roleSlug || "editor";
	if (slug === "admin") {
		return {
			label: __("Admin"),
			description: __("Can manage users, settings, and all links."),
			badge: "users-page-role-badge-admin",
		};
	}
	if (slug === "editor") {
		return {
			label: __("Editor"),
			description: __(
				"Can create, edit, and delete site links without admin access."
			),
			badge: "users-page-role-badge-editor",
		};
	}

	const label =
		roleName ||
		slug
			.split(/[_-]+/)
			.map((part) => part.charAt(0).toUpperCase() + part.slice(1))
			.join(" ");

	return {
		label,
		description: __("Custom role with configured capabilities."),
		badge: "users-page-role-badge-custom",
	};
}

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
