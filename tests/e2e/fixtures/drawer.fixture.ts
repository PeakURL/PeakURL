import type { Page } from "@playwright/test";

import { mockDashboardSession } from "./dashboard.fixture";

/** Populated, isolated drawer data: every API request stays inside the preview. */
export async function mockDrawerPages(
	page: Page,
	direction: "ltr" | "rtl" = "ltr"
) {
	await page.route("**/api/v1/**", (route) =>
		route.fulfill({ status: 503, json: { message: "Preview unavailable" } })
	);
	await mockDashboardSession(page, {
		direction,
		capabilities: {
			view_links: true,
			edit_links: true,
			view_analytics: true,
			manage_users: true,
			manage_integrations: true,
			manage_updates: true,
			manage_site_settings: true,
			manage_api_keys: true,
			manage_performance: true,
			manage_mail_delivery: true,
			manage_location_data: true,
		},
	});
	await page.route("**/api/v1/users", (route) =>
		route.fulfill({ json: { data: [] } })
	);
	await page.route("**/api/v1/roles", (route) =>
		route.fulfill({
			json: {
				data: [
					{
						id: "editor",
						name: "Editor",
						description: "Manage links and analytics",
						is_builtin: true,
						is_customized: false,
						capabilities: Array.from({ length: 12 }, (_, i) => ({
							id: `preview_${i}`,
							label: `Preview capability ${i + 1}`,
							description: "Access to this part of PeakURL.",
							group: "links",
							group_label: "Links",
							enabled: true,
							default: true,
							protected: false,
						})),
					},
				],
			},
		})
	);
	await page.route("**/api/v1/urls?*", (route) =>
		route.fulfill({
			json: {
				data: {
					items: [
						{
							id: "preview-link",
							userId: "dashboard-preview",
							alias: "launch",
							shortCode: "launch",
							title: "PeakURL launch",
							destinationUrl: "https://example.com/launch",
							status: "active",
							clicks: 24,
							createdAt: "2026-10-01T12:00:00Z",
						},
					],
					meta: { totalItems: 1, totalPages: 1, page: 1, limit: 25 },
				},
			},
		})
	);
	await page.route("**/api/v1/system/general", (route) =>
		route.fulfill({
			json: {
				data: {
					canManageSiteSettings: true,
					webhooksEnabled: true,
					siteName: "PeakURL",
					siteTimezone: "UTC",
				},
			},
		})
	);
	await page.route("**/api/v1/system/captcha", (route) =>
		route.fulfill({
			json: {
				data: {
					enabled: false,
					configured: false,
					canManageFromDashboard: true,
				},
			},
		})
	);
	await page.route("**/api/v1/webhooks", (route) =>
		route.fulfill({
			json: {
				data: [
					{
						id: "preview-webhook",
						label: "Release notifications",
						url: "https://example.com/peakurl-notifications",
						isActive: true,
						verifySsl: true,
						events: ["url.created"],
						createdAt: "2026-10-01T12:00:00Z",
						secretHint: "preview••••1234",
						health: {
							total24h: 0,
							failed24h: 0,
							lastStatus: "pending",
						},
					},
				],
			},
		})
	);
	await page.route("**/api/v1/webhooks/events", (route) =>
		route.fulfill({
			json: {
				data: Array.from({ length: 12 }, (_, i) => ({
					id: `url.event${i}`,
					label: `Link event ${i + 1}`,
					description: "A link event is recorded.",
					group: "links",
				})),
			},
		})
	);
	await page.route(
		"**/api/v1/webhooks/preview-webhook/deliveries?*",
		(route) =>
			route.fulfill({
				json: {
					data: {
						items: [],
						meta: { page: 1, perPage: 15, total: 0, totalPages: 0 },
					},
				},
			})
	);
	await page.route("**/api/v1/system/cron", (route) =>
		route.fulfill({
			json: {
				data: {
					retention_days: 30,
					timezone: "UTC",
					jobs_count: 1,
					jobs: [
						{
							id: "link-health",
							title: "Link health monitoring",
							interval_seconds: 3600,
							recommended_interval_seconds: 3600,
							is_enabled: true,
							status: "idle",
							retention_days: null,
							retention_is_customized: false,
							effective_retention_days: 30,
							attempts: 0,
							recent_runs: Array.from({ length: 12 }, (_, i) => ({
								id: `run-${i}`,
								status: "success",
								attempt: 1,
								started_at: "2026-10-15T09:00:00Z",
								finished_at: "2026-10-15T09:00:01Z",
								duration_ms: 1000,
								output_summary: "Checked link destinations.",
							})),
						},
					],
				},
			},
		})
	);
}

export const drawerScenarios = [
	{
		name: "edit",
		path: "/dashboard/links",
		trigger: ".links-row-action-edit",
		panel: ".links-edit-drawer-panel",
		close: ".links-edit-drawer-close",
	},
	{
		name: "analytics",
		path: "/dashboard/links",
		trigger: ".links-row-action-stats",
		panel: ".links-drawer-panel",
		close: ".links-drawer-close",
	},
	{
		name: "roles",
		path: "/dashboard/users",
		trigger: ".users-page-hero-actions button:first-child",
		panel: ".users-roles-drawer-panel",
		close: ".users-roles-drawer-close",
	},
	{
		name: "webhook",
		path: "/dashboard/settings/integrations",
		trigger: "button:has-text('Add Webhook')",
		panel: ".webhook-form-drawer-panel",
		close: ".webhook-drawer-header-btn",
	},
	{
		name: "deliveries",
		path: "/dashboard/settings/integrations",
		trigger: "button:has-text('Deliveries')",
		panel: ".webhook-drawer-panel",
		close: "button[aria-label='Close drawer']",
	},
	{
		name: "history",
		path: "/dashboard/tools/scheduled-jobs",
		trigger: "button[title='View Execution History']",
		panel: ".scheduled-jobs-drawer-panel",
		close: ".scheduled-jobs-drawer-close",
	},
	{
		name: "schedules",
		path: "/dashboard/tools/scheduled-jobs",
		trigger: "button:has-text('Manage Schedules')",
		panel: ".scheduled-jobs-manage-drawer-panel",
		close: ".scheduled-jobs-drawer-close",
	},
];
