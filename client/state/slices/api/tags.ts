/**
 * Shared RTK Query cache tag definitions.
 *
 * Provides targeted, reusable cache tags shared across multiple API slices
 * to prevent broad cache churn while ensuring cache freshness.
 */

import type { ApiTagType } from "./types";

export type TagItem = ApiTagType | { type: ApiTagType; id: string | number };

export const URL_LIST_TAG = { type: "Urls" as const, id: "LIST" };
export const USER_LIST_TAG = "Users" as const;
export const ANALYTICS_TAG = "Analytics" as const;

/**
 * Tags invalidated upon successful user deletion.
 *
 * User deletion cascades ownership of existing links to the acting administrator,
 * requiring targeted refreshes for the users list, URLs list, and dashboard analytics
 * without invalidating every individual URL detail cache entry.
 */
export const USER_DELETION_TAGS: readonly TagItem[] = [
	USER_LIST_TAG,
	URL_LIST_TAG,
	ANALYTICS_TAG,
] as const;
