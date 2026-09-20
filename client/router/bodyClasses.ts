import { matchPath } from "react-router";

import { applyFilters } from "@/shared/extensibility";

import { isValidImportTab, isValidSettingsTab } from "./tabs";

const BODY_CLASS_DATA_ATTRIBUTE = "peakurlBodyClasses";

interface BodyClassContext {
	pathname: string;
	pageType: "auth" | "dashboard" | "default";
	pageSlug: string;
}

function sanitizeBodyClassName(value: string): string {
	return value
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9-]+/g, "-")
		.replace(/-{2,}/g, "-")
		.replace(/^-+|-+$/g, "");
}

function uniqueBodyClassNames(
	classes: Array<string | false | null | undefined>
): string[] {
	return Array.from(
		new Set(
			classes
				.map((className) =>
					className ? sanitizeBodyClassName(className) : ""
				)
				.filter(Boolean)
		)
	);
}

function getAuthBodyClassNames(pathname: string): string[] {
	if ("/login" === pathname) {
		return ["page-auth", "page-login"];
	}

	if ("/forgot-password" === pathname) {
		return ["page-auth", "page-forgot-password"];
	}

	if (matchPath("/reset-password/:token", pathname)) {
		return ["page-auth", "page-reset-password"];
	}

	return [];
}

function getDashboardBodyClassNames(pathname: string): string[] {
	if (!pathname.startsWith("/dashboard")) {
		return [];
	}

	const classes = ["page-dashboard"];

	if ("/dashboard" === pathname) {
		classes.push("page-dashboard-home");
		return classes;
	}

	if ("/dashboard/about" === pathname) {
		classes.push("page-dashboard-about");
		return classes;
	}

	if ("/dashboard/activity" === pathname) {
		classes.push("page-dashboard-activity");
		return classes;
	}

	if ("/dashboard/links" === pathname) {
		classes.push("page-dashboard-links");
		return classes;
	}

	if ("/dashboard/plugins" === pathname) {
		classes.push("page-dashboard-plugins");
		return classes;
	}

	if ("/dashboard/users" === pathname) {
		classes.push("page-dashboard-users");
		return classes;
	}

	if ("/dashboard/settings" === pathname) {
		classes.push(
			"page-dashboard-settings",
			"page-dashboard-settings-general"
		);
		return classes;
	}

	const settingsMatch = matchPath("/dashboard/settings/:tab", pathname);

	if (settingsMatch) {
		const tab = sanitizeBodyClassName(
			settingsMatch.params.tab || "general"
		);
		if (isValidSettingsTab(tab)) {
			classes.push(
				"page-dashboard-settings",
				`page-dashboard-settings-${tab}`
			);
			return classes;
		}
	}

	const importMatch = matchPath("/dashboard/tools/import/:tab", pathname);

	if (importMatch) {
		const tab = sanitizeBodyClassName(importMatch.params.tab || "file");
		if (isValidImportTab(tab)) {
			classes.push(
				"page-dashboard-tools",
				"page-dashboard-import",
				`page-dashboard-import-${tab}`
			);
			return classes;
		}
	}

	if ("/dashboard/tools/export" === pathname) {
		classes.push("page-dashboard-tools", "page-dashboard-export");
		return classes;
	}

	if ("/dashboard/tools/scheduled-jobs" === pathname) {
		classes.push("page-dashboard-tools", "page-dashboard-scheduled-jobs");
		return classes;
	}

	if ("/dashboard/tools/system-status" === pathname) {
		classes.push("page-dashboard-tools", "page-dashboard-system-status");
		return classes;
	}

	if ("/dashboard/tools" === pathname) {
		classes.push("page-dashboard-tools");
		return classes;
	}

	classes.push("page-not-found");
	return classes;
}

function getPageSlug(classes: string[]): string {
	const specificPageClass = [...classes]
		.reverse()
		.find(
			(className) =>
				className.startsWith("page-") &&
				!["page-auth", "page-dashboard"].includes(className)
		);
	return specificPageClass || "page-default";
}

function getBodyClassContext(
	pathname: string,
	classes: string[]
): BodyClassContext {
	if (classes.includes("page-auth")) {
		return {
			pathname,
			pageType: "auth",
			pageSlug: getPageSlug(classes),
		};
	}

	if (classes.includes("page-dashboard")) {
		return {
			pathname,
			pageType: "dashboard",
			pageSlug: getPageSlug(classes),
		};
	}

	return {
		pathname,
		pageType: "default",
		pageSlug: getPageSlug(classes),
	};
}

function readManagedBodyClasses(): string[] {
	if ("undefined" === typeof document || !document.body) {
		return [];
	}

	return (document.body.dataset[BODY_CLASS_DATA_ATTRIBUTE] || "")
		.split(" ")
		.filter(Boolean);
}

/**
 * Mirrors the role of WordPress `get_body_class()` for page-driven UI.
 *
 * The resulting class list is passed through a `body_class` filter so future
 * extensions can add or remove classes from one central place.
 */
export function getBodyClassNames(
	pathname: string,
	extraClasses: string[] = []
): string[] {
	const pageClasses = [
		...getAuthBodyClassNames(pathname),
		...getDashboardBodyClassNames(pathname),
	];
	const defaultClasses =
		0 === pageClasses.length ? ["page-not-found"] : pageClasses;
	const mergedClasses = uniqueBodyClassNames([
		...defaultClasses,
		...extraClasses,
	]);
	const context = getBodyClassContext(pathname, mergedClasses);

	return uniqueBodyClassNames(
		applyFilters("body_class", mergedClasses, extraClasses, context)
	);
}

/**
 * Applies page-managed classes on the document body while leaving any
 * unrelated classes untouched.
 */
export function applyBodyClassNames(nextClasses: string[]): void {
	if ("undefined" === typeof document || !document.body) {
		return;
	}

	const previousClasses = readManagedBodyClasses();

	previousClasses.forEach((className) => {
		if (!nextClasses.includes(className)) {
			document.body.classList.remove(className);
		}
	});

	nextClasses.forEach((className) => {
		if (!previousClasses.includes(className)) {
			document.body.classList.add(className);
		}
	});

	if (0 === nextClasses.length) {
		delete document.body.dataset[BODY_CLASS_DATA_ATTRIBUTE];
		return;
	}

	document.body.dataset[BODY_CLASS_DATA_ATTRIBUTE] = nextClasses.join(" ");
}

/**
 * Removes the currently managed page body classes.
 */
export function clearBodyClassNames(): void {
	if ("undefined" === typeof document || !document.body) {
		return;
	}

	readManagedBodyClasses().forEach((className) => {
		document.body.classList.remove(className);
	});

	delete document.body.dataset[BODY_CLASS_DATA_ATTRIBUTE];
}
