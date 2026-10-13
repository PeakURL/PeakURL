/**
 * Role and capability configuration wire and domain contracts.
 *
 * Provides typed definitions for HTTP wire responses (snake_case) and
 * internal TypeScript / React application domain models (camelCase).
 */

/**
 * Raw role capability item from the API wire contract.
 */
export interface ApiRoleCapability {
	id: string;
	label: string;
	description: string;
	group: string;
	group_label: string;
	enabled: boolean;
	default: boolean;
	protected: boolean;
	protected_reason?: string | null;
	applicable?: boolean;
	applicable_reason?: string | null;
}

/**
 * Raw role definition object from the API wire contract.
 */
export interface ApiRoleDefinition {
	id: string;
	name: string;
	description: string;
	is_builtin?: boolean;
	is_configurable?: boolean;
	is_customized: boolean;
	capabilities: ApiRoleCapability[];
	user_count?: number;
}

/**
 * Domain model representing a capability within a role.
 */
export interface RoleCapability {
	id: string;
	label: string;
	description: string;
	group: string;
	groupLabel: string;
	enabled: boolean;
	default: boolean;
	protected: boolean;
	protectedReason: string | null;
	applicable: boolean;
	applicableReason: string | null;
}

/**
 * Domain model representing a role and its configured capabilities.
 */
export interface RoleDefinition {
	id: string;
	name: string;
	description: string;
	isBuiltin: boolean;
	isConfigurable: boolean;
	isCustomized: boolean;
	capabilities: RoleCapability[];
	userCount: number;
}

/**
 * Request payload for creating a custom role.
 */
export interface CreateRolePayload {
	id: string;
	name: string;
	description?: string;
}

/**
 * Request payload for updating capabilities and/or metadata on a role.
 */
export interface UpdateRoleCapabilitiesPayload {
	role: string;
	name?: string;
	description?: string;
	capabilities?: Record<string, boolean>;
}

/**
 * Request payload for resetting a role's capabilities to defaults.
 */
export interface ResetRoleCapabilitiesPayload {
	role: string;
}

/**
 * Request payload for deleting a custom role.
 */
export interface DeleteRolePayload {
	role: string;
}
