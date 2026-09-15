import { test, expect } from "@playwright/test";
import {
	API_ROUTES,
	DEFAULT_USER_CAPABILITIES,
	buildApiRouteWithQuery,
	createApiQueryParams,
	getApiRequestUrl,
	mapApiCapabilities,
	mapApiUser,
	mapUserCapabilitiesToApi,
} from "../../client/api";
import type {
	ApiProfileUser,
	ApiUserCapabilities,
	ProfileUser,
} from "../../client/api";
import { selectSessionUser } from "../../client/state/slices/api";
import {
	extractErrorMessage,
	getErrorMessage,
	getErrorStatus,
} from "../../client/shared/errors";
import { findDashboardRouteMatches } from "../../client/pages/layout/dashboard/Search/lib/searchMatching";
import { isValidImportTab, isValidSettingsTab } from "../../client/router/tabs";
import { getBodyClassNames } from "../../client/router/bodyClasses";
import { getPageTitle } from "../../client/router/pageTitle";

test.describe("Frontend API Client Contracts", () => {
	test.describe("API Route Construction & Parameter Encoding", () => {
		test("constructs static routes across all domains accurately", () => {
			// URLs / Links
			expect(API_ROUTES.urls.index).toBe("urls");
			expect(API_ROUTES.urls.bulk).toBe("urls/bulk");
			expect(API_ROUTES.urls.bulkRestore).toBe("urls/restore");
			expect(API_ROUTES.urls.trash).toBe("urls/trash");
			expect(API_ROUTES.urls.export).toBe("urls/export");

			// Auth
			expect(API_ROUTES.auth.login).toBe("auth/login");
			expect(API_ROUTES.auth.logout).toBe("auth/logout");
			expect(API_ROUTES.auth.register).toBe("auth/register");
			expect(API_ROUTES.auth.forgotPassword).toBe("auth/forgot-password");
			expect(API_ROUTES.auth.twoFactorSetup).toBe(
				"auth/security/two-factor/setup"
			);
			expect(API_ROUTES.auth.twoFactorVerify).toBe(
				"auth/security/two-factor/verify"
			);
			expect(API_ROUTES.auth.twoFactorDisable).toBe(
				"auth/security/two-factor/disable"
			);
			expect(API_ROUTES.auth.twoFactorBackupCodes).toBe(
				"auth/security/two-factor/backup-codes"
			);
			expect(API_ROUTES.auth.securityBackupCodesDownload).toBe(
				"auth/security/backup-codes/download"
			);
			expect(API_ROUTES.auth.securitySessions).toBe(
				"auth/security/sessions"
			);

			// Users
			expect(API_ROUTES.users.index).toBe("users");
			expect(API_ROUTES.users.me).toBe("users/me");

			// System / Settings
			expect(API_ROUTES.system.general).toBe("system/general");
			expect(API_ROUTES.system.cache).toBe("system/cache");
			expect(API_ROUTES.system.cacheClear).toBe("system/cache/clear");
			expect(API_ROUTES.system.captcha).toBe("system/captcha");
			expect(API_ROUTES.system.geoip).toBe("system/geoip");
			expect(API_ROUTES.system.geoipDownload).toBe(
				"system/geoip/download"
			);
			expect(API_ROUTES.system.mail).toBe("system/mail");
			expect(API_ROUTES.system.mailTest).toBe("system/mail/test");
			expect(API_ROUTES.system.notices).toBe("system/notices");
			expect(API_ROUTES.system.status).toBe("system/status");
			expect(API_ROUTES.system.update).toBe("system/update");
			expect(API_ROUTES.system.updateCheck).toBe("system/update/check");
			expect(API_ROUTES.system.updateApply).toBe("system/update/apply");
			expect(API_ROUTES.system.updateReinstall).toBe(
				"system/update/reinstall"
			);
			expect(API_ROUTES.system.updateDatabase).toBe(
				"system/update/database"
			);

			// Analytics
			expect(API_ROUTES.analytics.index).toBe("analytics");
			expect(API_ROUTES.analytics.activity).toBe("analytics/activity");
			expect(API_ROUTES.analytics.activityBulk).toBe(
				"analytics/activity/bulk"
			);
			expect(API_ROUTES.analytics.activityHistory).toBe(
				"analytics/activity/history"
			);
			expect(API_ROUTES.analytics.recentClicks).toBe(
				"analytics/recent-clicks"
			);

			// Webhooks
			expect(API_ROUTES.webhooks.index).toBe("webhooks");
			expect(API_ROUTES.webhooks.test).toBe("webhooks/test");
		});

		test("safely encodes dynamic path parameters and URL characters", () => {
			expect(API_ROUTES.urls.byId("link_123")).toBe("urls/link_123");
			expect(API_ROUTES.urls.byId("link/with/slashes")).toBe(
				"urls/link%2Fwith%2Fslashes"
			);
			expect(API_ROUTES.urls.byId("code with spaces")).toBe(
				"urls/code%20with%20spaces"
			);

			expect(API_ROUTES.urls.restore("link_456")).toBe(
				"urls/link_456/restore"
			);
			expect(API_ROUTES.urls.restore("id?query=bad")).toBe(
				"urls/id%3Fquery%3Dbad/restore"
			);

			expect(API_ROUTES.users.byUsername("john_doe")).toBe(
				"users/john_doe"
			);
			expect(API_ROUTES.users.byUsername("admin@peakurl.dev")).toBe(
				"users/admin%40peakurl.dev"
			);

			expect(API_ROUTES.auth.resetPassword("reset_token_xyz")).toBe(
				"auth/reset-password/reset_token_xyz"
			);
			expect(API_ROUTES.auth.securitySession("sess_abc")).toBe(
				"auth/security/sessions/sess_abc"
			);
			expect(API_ROUTES.auth.apiKeyById("key_789")).toBe(
				"auth/api-key/key_789"
			);

			expect(API_ROUTES.analytics.linkStats("url_55")).toBe(
				"analytics/url/url_55/stats"
			);
			expect(API_ROUTES.analytics.linkLocation("url_55")).toBe(
				"analytics/url/url_55/location"
			);
			expect(API_ROUTES.analytics.activityById("act_11")).toBe(
				"analytics/activity/act_11"
			);
			expect(API_ROUTES.analytics.restoreActivityLink("act_11")).toBe(
				"analytics/activity/act_11/restore"
			);

			expect(API_ROUTES.webhooks.byId("whk_99")).toBe("webhooks/whk_99");
			expect(API_ROUTES.webhooks.testById("whk_99")).toBe(
				"webhooks/whk_99/test"
			);
		});
	});

	test.describe("Query Parameter Serialization & Route Composition", () => {
		test("createApiQueryParams formats numbers, strings, and booleans while omitting null/undefined", () => {
			const params = createApiQueryParams({
				page: 2,
				limit: 50,
				search: "test query",
				active: true,
				deleted: false,
				missing: undefined,
				nullish: null,
			});

			expect(params.get("page")).toBe("2");
			expect(params.get("limit")).toBe("50");
			expect(params.get("search")).toBe("test query");
			expect(params.get("active")).toBe("true");
			expect(params.get("deleted")).toBe("false");
			expect(params.has("missing")).toBe(false);
			expect(params.has("nullish")).toBe(false);
		});

		test("buildApiRouteWithQuery handles paths with and without query parameters", () => {
			const withoutQuery = buildApiRouteWithQuery("urls");
			expect(withoutQuery).toBe("urls");

			const emptyParams = new URLSearchParams();
			const withEmptyParams = buildApiRouteWithQuery("urls", emptyParams);
			expect(withEmptyParams).toBe("urls");

			const populatedParams = createApiQueryParams({
				page: 1,
				limit: 25,
				search: "hello world",
			});
			const withQuery = buildApiRouteWithQuery("urls", populatedParams);
			expect(withQuery).toBe("urls?page=1&limit=25&search=hello+world");
		});

		test("getApiRequestUrl prepends client base URL without redundant slashes", () => {
			const url = getApiRequestUrl("urls/trash");
			expect(url).toContain("/api/v1/urls/trash");
			expect(url).not.toContain("//urls");

			const urlWithLeadingSlash = getApiRequestUrl("/system/general");
			expect(urlWithLeadingSlash).toContain("/api/v1/system/general");
			expect(urlWithLeadingSlash).not.toContain("//system");
		});
	});

	test.describe("Error Parsing & HTTP Status Extraction", () => {
		test("extractErrorMessage extracts messages across varied backend error shapes", () => {
			// Standard ApiException structure: { status: 403, data: { message: "..." } }
			expect(
				extractErrorMessage({
					status: 403,
					data: {
						message: "You do not have permission to delete links.",
					},
				})
			).toBe("You do not have permission to delete links.");

			// Fetch error string property
			expect(
				extractErrorMessage({ error: "Network connection lost" })
			).toBe("Network connection lost");

			// Native JS Error
			expect(extractErrorMessage(new Error("Disk quota exceeded"))).toBe(
				"Disk quota exceeded"
			);

			// Fallback string for unknown or null shapes
			expect(getErrorMessage(null, "Fallback message")).toBe(
				"Fallback message"
			);
			expect(getErrorMessage({}, "Fallback message")).toBe(
				"Fallback message"
			);
		});

		test("getErrorStatus extracts status code cleanly", () => {
			expect(getErrorStatus({ status: 401 })).toBe(401);
			expect(getErrorStatus({ status: 403 })).toBe(403);
			expect(getErrorStatus({ status: 404 })).toBe(404);
			expect(getErrorStatus({ status: 422 })).toBe(422);
			expect(getErrorStatus({ status: 500 })).toBe(500);

			expect(getErrorStatus({ status: "FETCH_ERROR" })).toBe(null);
			expect(getErrorStatus(null)).toBe(null);
		});
	});

	test.describe("Destructive Workflow Route Alignments", () => {
		test("confirms destructive routes map to exact expected backend paths", () => {
			// Delete All Links: DELETE /api/v1/urls
			expect(API_ROUTES.urls.index).toBe("urls");

			// Empty Trash: DELETE /api/v1/urls/trash
			expect(API_ROUTES.urls.trash).toBe("urls/trash");

			// Bulk Delete: DELETE /api/v1/urls/bulk
			expect(API_ROUTES.urls.bulk).toBe("urls/bulk");

			// Bulk Restore: POST /api/v1/urls/restore
			expect(API_ROUTES.urls.bulkRestore).toBe("urls/restore");
		});
	});

	test.describe("API Boundary & Type Consistency", () => {
		test("mapApiCapabilities maps snake_case wire payload to camelCase domain model", () => {
			const rawCapabilities: ApiUserCapabilities = {
				manage_users: true,
				manage_site_settings: true,
				manage_mail_delivery: false,
				manage_location_data: true,
				manage_performance: true,
				manage_updates: true,
				manage_profile: true,
				manage_api_keys: true,
				manage_webhooks: false,
				view_links: true,
				edit_links: true,
				trash_links: true,
				delete_links: true,
				empty_trash: true,
				view_analytics: true,
				create_links: true,
			};

			const domainCapabilities = mapApiCapabilities(rawCapabilities);

			expect(domainCapabilities).toEqual({
				manageUsers: true,
				manageSiteSettings: true,
				manageMailDelivery: false,
				manageLocationData: true,
				managePerformance: true,
				manageUpdates: true,
				manageProfile: true,
				manageApiKeys: true,
				manageWebhooks: false,
				viewLinks: true,
				editLinks: true,
				trashLinks: true,
				deleteLinks: true,
				emptyTrash: true,
				viewAnalytics: true,
				createLinks: true,
			});
		});

		test("mapApiCapabilities maps managePerformance directly without phantom fallback", () => {
			const withoutPerformance: ApiUserCapabilities = {
				manage_site_settings: true,
				manage_performance: null,
			};

			expect(
				mapApiCapabilities(withoutPerformance).managePerformance
			).toBe(false);
			expect(
				mapApiCapabilities({ manage_performance: false })
					.managePerformance
			).toBe(false);
			expect(
				mapApiCapabilities({ manage_performance: true })
					.managePerformance
			).toBe(true);
		});

		test("mapApiCapabilities defaults safely for nullish or empty inputs", () => {
			expect(mapApiCapabilities(null)).toEqual(DEFAULT_USER_CAPABILITIES);
			expect(mapApiCapabilities(undefined)).toEqual(
				DEFAULT_USER_CAPABILITIES
			);
			expect(mapApiCapabilities({})).toEqual(DEFAULT_USER_CAPABILITIES);
		});

		test("mapUserCapabilitiesToApi serializes camelCase domain model to snake_case wire payload", () => {
			const serialized = mapUserCapabilitiesToApi({
				manageUsers: true,
				manageSiteSettings: false,
				manageMailDelivery: true,
				viewLinks: true,
				editLinks: false,
				trashLinks: true,
				deleteLinks: false,
				emptyTrash: false,
			});

			expect(serialized).toEqual({
				manage_users: true,
				manage_site_settings: false,
				manage_mail_delivery: true,
				view_links: true,
				edit_links: false,
				trash_links: true,
				delete_links: false,
				empty_trash: false,
			});
		});

		test("mapApiUser normalizes user profile capabilities at the boundary", () => {
			const rawUser: ApiProfileUser = {
				id: "user_42",
				username: "editor_user",
				email: "editor@example.com",
				role: "editor",
				capabilities: {
					manage_users: false,
					view_links: true,
					edit_links: true,
					trash_links: true,
					delete_links: false,
					empty_trash: false,
					view_analytics: true,
					create_links: true,
				},
			};

			const normalizedUser = mapApiUser(rawUser);

			expect(normalizedUser).not.toBeNull();
			expect(normalizedUser?.id).toBe("user_42");
			expect(normalizedUser?.capabilities?.viewLinks).toBe(true);
			expect(normalizedUser?.capabilities?.editLinks).toBe(true);
			expect(normalizedUser?.capabilities?.trashLinks).toBe(true);
			expect(normalizedUser?.capabilities?.deleteLinks).toBe(false);
			expect(normalizedUser?.capabilities?.emptyTrash).toBe(false);
			expect(normalizedUser?.capabilities?.manageUsers).toBe(false);
		});

		test("mapApiUser safely handles nullish or omitted capabilities", () => {
			expect(mapApiUser(null)).toBeNull();
			expect(mapApiUser(undefined)).toBeNull();

			const userWithoutCaps: ApiProfileUser = {
				id: "user_43",
				username: "guest_user",
				role: "editor",
			};

			const normalized = mapApiUser(userWithoutCaps);
			expect(normalized?.capabilities).toEqual(DEFAULT_USER_CAPABILITIES);
		});

		test("selectSessionUser resolves normalized user across data/user response wrappers", () => {
			const normalizedUser: ProfileUser = {
				id: "user_100",
				username: "admin_user",
				role: "admin",
				capabilities: {
					...DEFAULT_USER_CAPABILITIES,
					manageUsers: true,
					emptyTrash: true,
				},
			};

			const fromData = selectSessionUser({ data: normalizedUser });
			expect(fromData?.id).toBe("user_100");
			expect(fromData?.capabilities?.manageUsers).toBe(true);
			expect(fromData?.capabilities?.emptyTrash).toBe(true);
			expect(fromData?.capabilities?.viewLinks).toBe(false);

			const fromUserWrapper = selectSessionUser({ user: normalizedUser });
			expect(fromUserWrapper?.id).toBe("user_100");
			expect(fromUserWrapper?.capabilities?.manageUsers).toBe(true);

			expect(selectSessionUser(null)).toBeNull();
			expect(selectSessionUser({})).toBeNull();
		});

		test("confirms single transformation point without duplicate mapping", () => {
			const rawWireUser: ApiProfileUser = {
				id: "user_200",
				username: "site_admin",
				role: "admin",
				capabilities: {
					manage_users: true,
					manage_site_settings: true,
					view_links: true,
				},
			};

			// Step 1: Normalization at the RTK Query boundary (transformResponse)
			const normalizedUser = mapApiUser(rawWireUser);
			expect(normalizedUser).not.toBeNull();

			// Step 2: Pure selector resolution from store state without secondary conversion
			const selected = selectSessionUser({ data: normalizedUser });
			expect(selected).toBe(normalizedUser);
			expect(selected?.capabilities?.manageUsers).toBe(true);
			expect(selected?.capabilities?.manageSiteSettings).toBe(true);
			expect(selected?.capabilities?.viewLinks).toBe(true);
		});

		test("filters search targets based on user capabilities", () => {
			const editorCapabilities = {
				canManageUsers: false,
				canManageApiKeys: false,
				canManageWebhooks: false,
			};

			const adminCapabilities = {
				canManageUsers: true,
				canManageApiKeys: true,
				canManageWebhooks: true,
			};

			const editorActivityMatches = findDashboardRouteMatches(
				"activity",
				editorCapabilities
			);
			expect(editorActivityMatches.some((m) => m.id === "activity")).toBe(
				false
			);

			const adminActivityMatches = findDashboardRouteMatches(
				"activity",
				adminCapabilities
			);
			expect(adminActivityMatches.some((m) => m.id === "activity")).toBe(
				true
			);

			const editorScheduledMatches = findDashboardRouteMatches(
				"scheduled",
				editorCapabilities
			);
			expect(
				editorScheduledMatches.some(
					(m) => m.id === "tools-scheduled-jobs"
				)
			).toBe(false);

			const adminScheduledMatches = findDashboardRouteMatches(
				"scheduled",
				adminCapabilities
			);
			expect(
				adminScheduledMatches.some(
					(m) => m.id === "tools-scheduled-jobs"
				)
			).toBe(true);
		});

		test("validates settings tabs accurately", () => {
			expect(isValidSettingsTab("general")).toBe(true);
			expect(isValidSettingsTab("security")).toBe(true);
			expect(isValidSettingsTab("api")).toBe(true);
			expect(isValidSettingsTab("integrations")).toBe(true);
			expect(isValidSettingsTab("performance")).toBe(true);
			expect(isValidSettingsTab("email")).toBe(true);
			expect(isValidSettingsTab("location")).toBe(true);
			expect(isValidSettingsTab("updates")).toBe(true);

			expect(isValidSettingsTab("invalid")).toBe(false);
			expect(isValidSettingsTab("")).toBe(false);
			expect(isValidSettingsTab(undefined)).toBe(false);
		});

		test("validates import tabs accurately", () => {
			expect(isValidImportTab("file")).toBe(true);
			expect(isValidImportTab("api")).toBe(true);
			expect(isValidImportTab("paste")).toBe(true);

			expect(isValidImportTab("csv")).toBe(false);
			expect(isValidImportTab("")).toBe(false);
			expect(isValidImportTab(undefined)).toBe(false);
		});

		test("body classes and page titles honor tab validation", () => {
			const validClasses = getBodyClassNames(
				"/dashboard/settings/general"
			);
			expect(validClasses).toContain("dashboard-settings-general");

			const invalidClasses = getBodyClassNames(
				"/dashboard/settings/invalid-tab"
			);
			expect(invalidClasses).not.toContain(
				"dashboard-settings-invalid-tab"
			);

			const validTitle = getPageTitle("/dashboard/settings/general");
			expect(validTitle).toContain("General Settings");

			const invalidTitle = getPageTitle(
				"/dashboard/settings/invalid-tab"
			);
			expect(invalidTitle).toBe("Page Not Found • PeakURL");

			const validImportClasses = getBodyClassNames(
				"/dashboard/tools/import/file"
			);
			expect(validImportClasses).toContain("dashboard-import-file");

			const invalidImportClasses = getBodyClassNames(
				"/dashboard/tools/import/invalid"
			);
			expect(invalidImportClasses).not.toContain(
				"dashboard-import-invalid"
			);
		});
	});
});
