/**
 * Explicit API boundary transformation adapters.
 *
 * Provides bidirectional mappings between the backend PHP HTTP JSON API
 * contract (snake_case) and internal TypeScript / React application models (camelCase).
 */

import type {
	ApiProfileUser,
	ApiUserCapabilities,
	ProfileUser,
	UserCapabilities,
} from "./types/users";

/**
 * Default internal user capability flags with all permissions disabled.
 */
export const DEFAULT_USER_CAPABILITIES: Readonly<UserCapabilities> =
	Object.freeze({
		manageUsers: false,
		manageSiteSettings: false,
		manageMailDelivery: false,
		manageLocationData: false,
		managePerformance: false,
		manageUpdates: false,
		manageProfile: false,
		manageApiKeys: false,
		manageWebhooks: false,
		viewLinks: false,
		editLinks: false,
		trashLinks: false,
		deleteLinks: false,
		emptyTrash: false,
		viewAnalytics: false,
		createLinks: false,
	});

/**
 * Map raw wire capability flags from the API response into the camelCase domain model.
 *
 * @param raw - Raw snake_case capability flags returned by the backend.
 * @return Normalized UserCapabilities object.
 */
export function mapApiCapabilities(
	raw?: ApiUserCapabilities | null
): UserCapabilities {
	if (!raw) {
		return { ...DEFAULT_USER_CAPABILITIES };
	}

	return {
		manageUsers: Boolean(raw.manage_users),
		manageSiteSettings: Boolean(raw.manage_site_settings),
		manageMailDelivery: Boolean(raw.manage_mail_delivery),
		manageLocationData: Boolean(raw.manage_location_data),
		managePerformance: Boolean(raw.manage_performance),
		manageUpdates: Boolean(raw.manage_updates),
		manageProfile: Boolean(raw.manage_profile),
		manageApiKeys: Boolean(raw.manage_api_keys),
		manageWebhooks: Boolean(raw.manage_webhooks),
		viewLinks: Boolean(raw.view_links),
		editLinks: Boolean(raw.edit_links),
		trashLinks: Boolean(raw.trash_links),
		deleteLinks: Boolean(raw.delete_links),
		emptyTrash: Boolean(raw.empty_trash),
		viewAnalytics: Boolean(raw.view_analytics),
		createLinks: Boolean(raw.create_links),
	};
}

/**
 * Map internal camelCase capability flags to the snake_case API wire payload.
 *
 * @param capabilities - Internal capability flags.
 * @return Raw ApiUserCapabilities payload for the wire contract.
 */
export function mapUserCapabilitiesToApi(
	capabilities: Partial<UserCapabilities>
): ApiUserCapabilities {
	const result: ApiUserCapabilities = {};

	if (undefined !== capabilities.manageUsers) {
		result.manage_users = Boolean(capabilities.manageUsers);
	}
	if (undefined !== capabilities.manageSiteSettings) {
		result.manage_site_settings = Boolean(capabilities.manageSiteSettings);
	}
	if (undefined !== capabilities.manageMailDelivery) {
		result.manage_mail_delivery = Boolean(capabilities.manageMailDelivery);
	}
	if (undefined !== capabilities.manageLocationData) {
		result.manage_location_data = Boolean(capabilities.manageLocationData);
	}
	if (undefined !== capabilities.managePerformance) {
		result.manage_performance = Boolean(capabilities.managePerformance);
	}
	if (undefined !== capabilities.manageUpdates) {
		result.manage_updates = Boolean(capabilities.manageUpdates);
	}
	if (undefined !== capabilities.manageProfile) {
		result.manage_profile = Boolean(capabilities.manageProfile);
	}
	if (undefined !== capabilities.manageApiKeys) {
		result.manage_api_keys = Boolean(capabilities.manageApiKeys);
	}
	if (undefined !== capabilities.manageWebhooks) {
		result.manage_webhooks = Boolean(capabilities.manageWebhooks);
	}
	if (undefined !== capabilities.viewLinks) {
		result.view_links = Boolean(capabilities.viewLinks);
	}
	if (undefined !== capabilities.editLinks) {
		result.edit_links = Boolean(capabilities.editLinks);
	}
	if (undefined !== capabilities.trashLinks) {
		result.trash_links = Boolean(capabilities.trashLinks);
	}
	if (undefined !== capabilities.deleteLinks) {
		result.delete_links = Boolean(capabilities.deleteLinks);
	}
	if (undefined !== capabilities.emptyTrash) {
		result.empty_trash = Boolean(capabilities.emptyTrash);
	}
	if (undefined !== capabilities.viewAnalytics) {
		result.view_analytics = Boolean(capabilities.viewAnalytics);
	}
	if (undefined !== capabilities.createLinks) {
		result.create_links = Boolean(capabilities.createLinks);
	}

	return result;
}

/**
 * Normalize an API user profile into the internal application domain model.
 *
 * @param user - Raw user payload from the API wire contract.
 * @return Fully normalized ProfileUser or null.
 */
export function mapApiUser(user?: ApiProfileUser | null): ProfileUser | null {
	if (!user) {
		return null;
	}

	return {
		...user,
		capabilities: mapApiCapabilities(user.capabilities),
	};
}
