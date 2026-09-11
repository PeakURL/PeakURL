import {
	Link2,
	MousePointerClick,
	PencilLine,
	RotateCcw,
	Shield,
	Trash2,
	UserMinus,
	UserPen,
	UserPlus,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { formatLocalizedDateTime } from "@/shared/dates";
import { decodeHtmlEntities, normalizeLinkTitle } from "@/shared/links";

import type {
	ActivityCategory,
	ActivityPerson,
	RecentActivity,
} from "../types";

export const MAX_VISIBLE_PAGES = 5;

export function normalizeActivityCategory(
	value: string | null
): ActivityCategory {
	if ("links" === value || "users" === value) {
		return value;
	}

	return "all";
}

export function getVisiblePages(
	currentPage: number,
	totalPages: number
): number[] {
	const safeTotalPages = Math.max(1, totalPages);
	const safeCurrentPage = Math.min(Math.max(1, currentPage), safeTotalPages);
	const halfWindow = Math.floor(MAX_VISIBLE_PAGES / 2);
	const startPageInitial = Math.max(1, safeCurrentPage - halfWindow);
	const endPage = Math.min(
		safeTotalPages,
		startPageInitial + MAX_VISIBLE_PAGES - 1
	);
	const startPage = Math.max(1, endPage - MAX_VISIBLE_PAGES + 1);

	return Array.from(
		{ length: endPage - startPage + 1 },
		(_, index) => startPage + index
	);
}

export function getActivityPersonName(
	person?: ActivityPerson | null
): string | null {
	if (!person) {
		return null;
	}

	if (person.displayName) {
		return decodeHtmlEntities(person.displayName);
	}

	const fullName = [person.firstName, person.lastName]
		.filter(Boolean)
		.join(" ")
		.trim();

	return decodeHtmlEntities(
		fullName || person.username || person.email || null
	);
}

export function getRoleLabel(role?: string | null): string {
	if ("admin" === role) {
		return __("Admin");
	}

	if ("editor" === role) {
		return __("Editor");
	}

	return __("User");
}

export function getActivityLinkDisplayName(
	link?: RecentActivity["link"]
): string {
	const title = normalizeLinkTitle(link?.title);
	if (title) {
		return title;
	}
	const slug = link?.alias || link?.shortCode;
	if (slug) {
		return slug.startsWith("/") ? slug : `/${slug}`;
	}
	return __("Unknown");
}

export function getActivityMessage(activity: RecentActivity): string {
	const linkName = getActivityLinkDisplayName(activity.link);
	const userName = getActivityPersonName(activity.user) || __("Unknown user");

	let message = "";
	switch (activity.type) {
		case "link_created":
			message = sprintf(__('Created new link "%s"'), linkName);
			break;
		case "link_updated":
			message = sprintf(__('Updated link "%s"'), linkName);
			break;
		case "link_deleted":
			message = sprintf(__('Permanently deleted link "%s"'), linkName);
			break;
		case "link_trashed":
			message = sprintf(__('Moved link "%s" to trash'), linkName);
			break;
		case "link_restored":
			message = sprintf(__('Restored link "%s"'), linkName);
			break;
		case "trash_emptied": {
			const count = activity.count;
			if (typeof count === "number" && count > 0) {
				message =
					count === 1
						? __("Permanently deleted 1 link from trash")
						: sprintf(
								__("Permanently deleted %s links from trash"),
								String(count)
							);
			} else {
				message = activity.message || __("Emptied links from trash");
			}
			break;
		}
		case "user_created":
			message = sprintf(__('Created user "%s"'), userName);
			break;
		case "user_updated":
			message = sprintf(__('Updated user "%s"'), userName);
			break;
		case "user_deleted":
			message = sprintf(__('Deleted user "%s"'), userName);
			break;
		case "click": {
			const location = activity.location
				? sprintf(
						__("from %s"),
						activity.location.city ||
							activity.location.country ||
							__("Unknown")
					)
				: "";

			message = location
				? sprintf(__('Link "%1$s" was clicked %2$s'), [
						linkName,
						location,
					])
				: sprintf(__('Link "%s" was clicked'), linkName);
			break;
		}
		default:
			message = activity.message || __("Unknown activity");
			break;
	}

	return decodeHtmlEntities(message);
}

export interface ActivityVisual {
	icon: LucideIcon;
	tone: "neutral" | "success" | "info" | "danger" | "user";
}

export function getActivityVisual(type?: string | null): ActivityVisual {
	switch (type) {
		case "link_created":
			return { icon: Link2, tone: "success" };
		case "link_updated":
			return { icon: PencilLine, tone: "info" };
		case "link_deleted":
		case "link_trashed":
		case "trash_emptied":
			return { icon: Trash2, tone: "danger" };
		case "link_restored":
			return { icon: RotateCcw, tone: "success" };
		case "user_created":
			return { icon: UserPlus, tone: "user" };
		case "user_updated":
			return { icon: UserPen, tone: "info" };
		case "user_deleted":
			return { icon: UserMinus, tone: "danger" };
		case "click":
			return { icon: MousePointerClick, tone: "info" };
		default:
			return { icon: Shield, tone: "neutral" };
	}
}

export function formatExactTimestamp(timestamp?: string | null): string {
	if (!timestamp) {
		return "";
	}

	const date = new Date(timestamp);

	if (Number.isNaN(date.getTime())) {
		return "";
	}

	return formatLocalizedDateTime(date, {
		dateStyle: "medium",
		timeStyle: "medium",
	});
}
