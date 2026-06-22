import { __ } from "@/i18n";
import type {
	DashboardSearchCapabilities,
	DashboardSearchLocationLike,
	DashboardSearchRouteMatch,
	DashboardSearchRouteTarget,
	DashboardSearchSection,
	DashboardSearchUserLike,
	DashboardSearchUserMatch,
} from "./types";

/**
 * Input for creating a route search target.
 */
interface CreateRouteTargetInput {
	id: string;
	href: string;
	label: string;
	description: string;
	terms: string[];
	section?: DashboardSearchSection;
	isAllowed?: boolean;
}

/**
 * A route search target with an associated search score.
 */
interface ScoredRouteTarget extends DashboardSearchRouteTarget {
	score: number;
}

/**
 * Normalize a search term for consistent comparison.
 *
 * @param value - The raw search term.
 * @return The normalized search term.
 */
function normalizeTerm(value: unknown = ""): string {
	return String(value).trim().toLowerCase().replace(/\s+/g, " ");
}

/**
 * Create a route target object for the search index.
 *
 * @param input - The target configuration.
 * @return The initialized route target.
 */
function createRouteTarget({
	id,
	href,
	label,
	description,
	terms,
	section = "pages",
	isAllowed = true,
}: CreateRouteTargetInput): DashboardSearchRouteTarget {
	return {
		id,
		href,
		isAllowed,
		label,
		description,
		section,
		terms: terms.map(normalizeTerm).filter(Boolean),
	};
}

/**
 * Generate the list of searchable dashboard route targets.
 *
 * @param capabilities - The current user's capabilities.
 * @return The filtered list of allowed route targets.
 */
function getRouteTargets(
	capabilities: DashboardSearchCapabilities = {}
): DashboardSearchRouteTarget[] {
	return [
		createRouteTarget({
			id: "overview",
			href: "/dashboard",
			label: __("Overview"),
			description: __("Dashboard"),
			section: "pages",
			terms: [
				__("Dashboard"),
				__("Overview"),
				"dashboard",
				"overview",
				"home",
				"analytics",
			],
		}),
		createRouteTarget({
			id: "links",
			href: "/dashboard/links",
			label: __("All Links"),
			description: __("Links"),
			section: "pages",
			terms: [
				__("Links"),
				__("All Links"),
				"links",
				"link",
				"url",
				"urls",
				"short url",
				"short urls",
				"short link",
				"short links",
			],
		}),
		createRouteTarget({
			id: "activity",
			href: "/dashboard/activity",
			label: __("Activity"),
			description: __("Dashboard"),
			section: "pages",
			terms: [
				__("Activity"),
				__("Recent Activity"),
				"activity",
				"recent activity",
				"audit log",
				"history",
				"events",
			],
		}),
		createRouteTarget({
			id: "about",
			href: "/dashboard/about",
			label: __("About PeakURL"),
			description: __("About"),
			section: "pages",
			terms: [
				__("About"),
				__("About PeakURL"),
				"about",
				"version",
				"peakurl",
			],
		}),
		createRouteTarget({
			id: "settings-general",
			href: "/dashboard/settings/general",
			label: __("General Settings"),
			description: __("Settings"),
			section: "pages",
			terms: [
				__("Settings"),
				__("General"),
				__("General Settings"),
				__("Profile"),
				"settings",
				"general",
				"profile",
				"account",
				"language",
				"site language",
				"site settings",
			],
		}),
		createRouteTarget({
			id: "settings-security",
			href: "/dashboard/settings/security",
			label: __("Security Settings"),
			description: __("Settings"),
			section: "pages",
			terms: [
				__("Security"),
				__("Security Settings"),
				"security",
				"password",
				"password reset",
				"sessions",
				"two factor",
				"2fa",
				"backup codes",
			],
		}),
		createRouteTarget({
			id: "settings-api",
			href: "/dashboard/settings/api",
			label: __("API Keys"),
			description: __("Settings"),
			section: "pages",
			terms: [
				__("API Keys"),
				"api",
				"api key",
				"api keys",
				"token",
				"tokens",
			],
			isAllowed: Boolean(capabilities.canManageApiKeys),
		}),
		createRouteTarget({
			id: "settings-integrations",
			href: "/dashboard/settings/integrations",
			label: __("Integrations"),
			description: __("Settings"),
			section: "pages",
			terms: [__("Integrations"), "integrations", "webhook", "webhooks"],
			isAllowed: Boolean(capabilities.canManageWebhooks),
		}),
		createRouteTarget({
			id: "settings-email",
			href: "/dashboard/settings/email",
			label: __("Email SMTP"),
			description: __("Settings"),
			section: "pages",
			terms: [
				__("Email Configuration"),
				__("Email SMTP"),
				"email",
				"mail",
				"smtp",
				"mailer",
			],
			isAllowed: Boolean(capabilities.canManageMailDelivery),
		}),
		createRouteTarget({
			id: "settings-location",
			href: "/dashboard/settings/location",
			label: __("Location Data"),
			description: __("Settings"),
			section: "pages",
			terms: [
				__("Location Data"),
				"location",
				"location data",
				"geoip",
				"maxmind",
				"geolite",
			],
			isAllowed: Boolean(capabilities.canManageLocationData),
		}),
		createRouteTarget({
			id: "settings-updates",
			href: "/dashboard/settings/updates",
			label: __("Updates"),
			description: __("Settings"),
			section: "pages",
			terms: [
				__("Updates"),
				"updates",
				"update",
				"updater",
				"release",
				"upgrade",
			],
			isAllowed: Boolean(capabilities.canManageUpdates),
		}),
		createRouteTarget({
			id: "users",
			href: "/dashboard/users",
			label: __("Users"),
			description: __("Management"),
			section: "pages",
			terms: [
				__("Users"),
				"user",
				"users",
				"team",
				"members",
				"admins",
				"editors",
			],
			isAllowed: Boolean(capabilities.canManageUsers),
		}),
		createRouteTarget({
			id: "plugins",
			href: "/dashboard/plugins",
			label: __("Plugins"),
			description: __("Management"),
			section: "pages",
			terms: [__("Plugins"), "plugin", "plugins", "extensions"],
			isAllowed: Boolean(capabilities.canManagePlugins),
		}),
		createRouteTarget({
			id: "tools-import",
			href: "/dashboard/tools/import/file",
			label: __("Import"),
			description: __("Tools"),
			section: "tools",
			terms: [
				__("Tools"),
				__("Import"),
				__("Import: File Upload"),
				"tools",
				"import",
				"bulk import",
				"csv",
				"upload",
				"paste import",
				"api import",
			],
			isAllowed: Boolean(capabilities.canImportLinks),
		}),
		createRouteTarget({
			id: "tools-export",
			href: "/dashboard/tools/export",
			label: __("Export"),
			description: __("Tools"),
			section: "tools",
			terms: [
				__("Tools"),
				__("Export"),
				"tools",
				"export",
				"download",
				"backup",
				"csv export",
				"json export",
				"xml export",
			],
			isAllowed: Boolean(capabilities.canExportLinks),
		}),
		createRouteTarget({
			id: "tools-system-status",
			href: "/dashboard/tools/system-status",
			label: __("System Status"),
			description: __("Tools"),
			section: "tools",
			terms: [
				__("Tools"),
				__("System Status"),
				"system status",
				"site health",
				"health",
				"server",
				"database",
			],
			isAllowed: Boolean(capabilities.canViewSystemStatus),
		}),
	].filter((target) => target.isAllowed);
}

/**
 * Calculate a search relevance score for a route target.
 *
 * @param query  - The normalized search query.
 * @param target - The route target to score.
 * @return The calculated score (0-100).
 */
function getTargetScore(
	query: string,
	target: DashboardSearchRouteTarget
): number {
	let bestScore = 0;

	target.terms.forEach((term) => {
		if (!term) {
			return;
		}

		/* Exact match provides the highest score. */
		if (query === term) {
			bestScore = Math.max(bestScore, 100);
			return;
		}

		/* Prefix matches are scored highly. */
		if (term.startsWith(query)) {
			bestScore = Math.max(bestScore, 80);
		}

		/* Partial inclusion matches are scored moderately. */
		if (term.includes(query)) {
			bestScore = Math.max(bestScore, 70);
		}

		/* Reverse inclusion matches are scored lower. */
		if (query.includes(term)) {
			bestScore = Math.max(bestScore, 60);
		}

		/* Multi-part matches (all query words present in term) are scored moderately. */
		const queryParts = query.split(" ");
		if (
			queryParts.length > 1 &&
			queryParts.every((part) => term.includes(part))
		) {
			bestScore = Math.max(bestScore, 65);
		}
	});

	return bestScore;
}

/**
 * Calculate a search relevance score for a user match.
 *
 * @param query - The normalized search query.
 * @param terms - The search terms associated with the user.
 * @return The calculated score (0-100).
 */
function getUserScore(query: string, terms: string[]): number {
	return terms.reduce((bestScore, term) => {
		if (term === query) {
			return Math.max(bestScore, 100);
		}

		if (term.startsWith(query)) {
			return Math.max(bestScore, 80);
		}

		if (term.includes(query)) {
			return Math.max(bestScore, 70);
		}

		if (query.includes(term)) {
			return Math.max(bestScore, 60);
		}

		return bestScore;
	}, 0);
}

/**
 * Return the links page path, preserving the dashboard search query param.
 *
 * @param query - The search query.
 * @return The dashboard links path with search param.
 */
export function getLinksSearchPath(query: string = ""): string {
	const value = String(query).trim();

	if (!value) {
		return "/dashboard/links";
	}

	const params = new URLSearchParams();
	params.set("search", value);

	return `/dashboard/links?${params.toString()}`;
}

/**
 * Return a links page path that opens the stats drawer for a short code.
 *
 * @param shortCode - The short code or alias.
 * @return The dashboard links path with stats param.
 */
export function getLinkStatsPath(shortCode: string = ""): string {
	const value = String(shortCode).trim();

	if (!value) {
		return "/dashboard/links";
	}

	const params = new URLSearchParams();
	params.set("stats", value);

	return `/dashboard/links?${params.toString()}`;
}

/**
 * Read the active dashboard search value from the current router location.
 *
 * @param location - The current router location-like object.
 * @return The active search query, or an empty string.
 */
export function getDashboardSearchValueFromLocation(
	location?: DashboardSearchLocationLike | null
): string {
	const pathname = location?.pathname?.replace(/\/+$/, "") || "/";

	/* Search values are only read from the main links directory. */
	if ("/dashboard/links" !== pathname) {
		return "";
	}

	const params = new URLSearchParams(location?.search || "");
	return params.get("search") || "";
}

/**
 * Find the best matching dashboard routes for a search query.
 *
 * @param query        - The search query.
 * @param capabilities - The current user's capabilities.
 * @param limit        - The maximum number of matches to return.
 * @return The ranked list of route matches.
 */
export function findDashboardRouteMatches(
	query: string,
	capabilities: DashboardSearchCapabilities = {},
	limit: number = 5
): DashboardSearchRouteMatch[] {
	const normalizedQuery = normalizeTerm(query);

	if (!normalizedQuery) {
		return [];
	}

	return getRouteTargets(capabilities)
		.map<ScoredRouteTarget>((target) => ({
			...target,
			score: getTargetScore(normalizedQuery, target),
		}))
		.filter((target) => target.score > 0)
		.sort((a, b) => {
			/* Sort by score descending, then by label ascending. */
			if (b.score !== a.score) {
				return b.score - a.score;
			}

			return a.label.localeCompare(b.label);
		})
		.slice(0, limit)
		.map((target) => ({
			id: target.id,
			href: target.href,
			label: target.label,
			description: target.description,
			section: target.section,
		}));
}

/**
 * Find matching dashboard users for the search palette.
 *
 * @param query - The search query.
 * @param users - The list of users to search against.
 * @param limit - The maximum number of matches to return.
 * @return The ranked list of user matches.
 */
export function findDashboardUserMatches(
	query: string,
	users: Array<DashboardSearchUserLike> = [],
	limit: number = 5
): DashboardSearchUserMatch[] {
	const normalizedQuery = normalizeTerm(query);

	if (!normalizedQuery) {
		return [];
	}

	return users
		.map((user) => {
			const fullName =
				user.displayName ||
				[user.firstName, user.lastName]
					.filter(Boolean)
					.join(" ")
					.trim();

			/* Compile search terms from various user fields. */
			const terms = [
				user.username,
				user.email,
				user.role,
				user.displayName,
				fullName,
				user.firstName,
				user.lastName,
			]
				.map(normalizeTerm)
				.filter(Boolean);

			const score = getUserScore(normalizedQuery, terms);

			return {
				id: user.id || user.username || user.email || "user",
				title: fullName || user.username || user.email || __("User"),
				description: user.username || user.email || "",
				meta: user.role || "",
				href: "/dashboard/users",
				score,
			};
		})
		.filter((user) => user.score > 0)
		.sort((a, b) => {
			if (b.score !== a.score) {
				return b.score - a.score;
			}

			return a.title.localeCompare(b.title);
		})
		.slice(0, limit)
		.map(({ score, ...user }) => user);
}

/**
 * Resolve the best destination for a dashboard search submission.
 *
 * @param query        - The search query.
 * @param capabilities - The current user's capabilities.
 * @return The resolved destination path.
 */
export function getDashboardSearchPath(
	query: string,
	capabilities: DashboardSearchCapabilities = {}
): string {
	const normalizedQuery = normalizeTerm(query);

	if (!normalizedQuery) {
		return "/dashboard/links";
	}

	/* Look for the best-matching internal route target. */
	const [bestTarget] = getRouteTargets(capabilities)
		.map<ScoredRouteTarget>((target) => ({
			...target,
			score: getTargetScore(normalizedQuery, target),
		}))
		.filter((target) => target.score > 0)
		.sort((a, b) => b.score - a.score);

	/* If a route target matches strongly, redirect to it directly. */
	if (bestTarget && bestTarget.score >= 70) {
		return bestTarget.href;
	}

	/* Fall back to a general links search. */
	return getLinksSearchPath(query);
}
