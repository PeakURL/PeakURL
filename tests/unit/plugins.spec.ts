import { test, expect } from "@playwright/test";
import { API_ROUTES } from "../../client/api";
import { resolvePluginActionInvalidatedTags } from "../../client/state/slices/api/system";
import type {
	InstalledPlugin,
	InstalledPluginsPayload,
	TabId,
} from "../../client/pages/dashboard/plugins/types";

test.describe("Installed Plugins Contract & API Route Tests", () => {
	test("API_ROUTES defines the canonical plugin lifecycle endpoints", () => {
		expect(API_ROUTES.system.plugins).toBe("system/plugins");
		expect(API_ROUTES.system.pluginsActivate).toBe(
			"system/plugins/activate"
		);
		expect(API_ROUTES.system.pluginsDeactivate).toBe(
			"system/plugins/deactivate"
		);
		expect(API_ROUTES.system.pluginsDelete).toBe("system/plugins/delete");
		expect(API_ROUTES.system.pluginsUpload).toBe("system/plugins/upload");
	});

	test("InstalledPlugin contract validates required metadata fields and active state", () => {
		const sampleRegularPlugin: InstalledPlugin = {
			id: "example-plugin",
			type: "regular",
			typeLabel: "Regular",
			name: "Example Plugin",
			version: "1.0.0",
			requires: "1.8.0",
			testedUpTo: "1.8.0",
			php: "8.3",
			license: "MIT",
			valid: true,
			compatible: true,
			active: false,
			errors: [],
			warnings: [],
			pluginUri: "https://example.com/plugin",
			description: "Example plugin description.",
			author: "Example Author",
			authorUri: "https://example.com/author",
			documentation: "https://example.com/plugin/docs",
		};

		expect(sampleRegularPlugin.id).toBe("example-plugin");
		expect(sampleRegularPlugin.type).toBe("regular");
		expect(sampleRegularPlugin.valid).toBe(true);
		expect(sampleRegularPlugin.compatible).toBe(true);
		expect(sampleRegularPlugin.active).toBe(false);
		expect(sampleRegularPlugin.errors).toEqual([]);

		const sampleMustUsePlugin: InstalledPlugin = {
			id: "system-integration",
			type: "must_use",
			typeLabel: "Must-Use",
			name: "System Integration",
			version: "1.0.0",
			requires: "1.8.0",
			testedUpTo: "1.8.0",
			php: "8.3",
			license: "MIT",
			valid: true,
			compatible: true,
			active: true,
			errors: [],
			warnings: [],
		};

		expect(sampleMustUsePlugin.id).toBe("system-integration");
		expect(sampleMustUsePlugin.type).toBe("must_use");
		expect(sampleMustUsePlugin.typeLabel).toBe("Must-Use");
		expect(sampleMustUsePlugin.active).toBe(true);
	});

	test("InstalledPluginsPayload groups items into regular and mustUse arrays", () => {
		const payload: InstalledPluginsPayload = {
			items: [
				{
					id: "example-plugin",
					type: "regular",
					typeLabel: "Regular",
					name: "Example Plugin",
					version: "1.0.0",
					requires: "1.8.0",
					testedUpTo: "1.8.0",
					php: "8.3",
					license: "MIT",
					valid: true,
					compatible: true,
					active: false,
					errors: [],
					warnings: [],
				},
				{
					id: "system-integration",
					type: "must_use",
					typeLabel: "Must-Use",
					name: "System Integration",
					version: "1.0.0",
					requires: "1.8.0",
					testedUpTo: "1.8.0",
					php: "8.3",
					license: "MIT",
					valid: true,
					compatible: true,
					active: true,
					errors: [],
					warnings: [],
				},
			],
			regular: [
				{
					id: "example-plugin",
					type: "regular",
					typeLabel: "Regular",
					name: "Example Plugin",
					version: "1.0.0",
					requires: "1.8.0",
					testedUpTo: "1.8.0",
					php: "8.3",
					license: "MIT",
					valid: true,
					compatible: true,
					active: false,
					errors: [],
					warnings: [],
				},
			],
			mustUse: [
				{
					id: "system-integration",
					type: "must_use",
					typeLabel: "Must-Use",
					name: "System Integration",
					version: "1.0.0",
					requires: "1.8.0",
					testedUpTo: "1.8.0",
					php: "8.3",
					license: "MIT",
					valid: true,
					compatible: true,
					active: true,
					errors: [],
					warnings: [],
				},
			],
			total: 2,
		};

		expect(payload.total).toBe(2);
		expect(payload.regular.length).toBe(1);
		expect(payload.mustUse.length).toBe(1);
		expect(payload.regular[0].id).toBe("example-plugin");
		expect(payload.mustUse[0].id).toBe("system-integration");
	});

	test("InstalledPluginsPayload includes dynamic uploadLimit contract", () => {
		const payload: InstalledPluginsPayload = {
			items: [],
			regular: [],
			mustUse: [],
			total: 0,
			uploadLimit: {
				bytes: 2097152,
				formatted: "2 MB",
			},
		};

		expect(payload.uploadLimit).toBeDefined();
		expect(payload.uploadLimit?.bytes).toBe(2097152);
		expect(payload.uploadLimit?.formatted).toBe("2 MB");
	});

	test("Dashboard TabId supports only installed and must_use", () => {
		const validTabs: TabId[] = ["installed", "must_use"];
		expect(validTabs).toHaveLength(2);
	});

	test("API_ROUTES defines extension registry, pages, settings, actions, and asset endpoints", () => {
		expect(API_ROUTES.system.pluginExtensions).toBe(
			"system/plugins/extensions"
		);
		expect(API_ROUTES.system.pluginPage("social-card", "settings")).toBe(
			"system/plugins/social-card/page/settings"
		);
		expect(API_ROUTES.system.pluginSettings("social-card")).toBe(
			"system/plugins/social-card/settings"
		);
		expect(
			API_ROUTES.system.pluginAction("social-card", "generate_cache")
		).toBe("system/plugins/social-card/actions/generate_cache");
		expect(API_ROUTES.system.pluginAsset("social-card", "admin.css")).toBe(
			"system/plugins/social-card/assets/admin.css"
		);
	});

	test("dispatchPluginAction tag invalidation selectively refreshes Analytics and Urls via explicit invalidations", () => {
		// 1. General plugin action without explicit invalidations only invalidates Plugins tag
		const generalTags = resolvePluginActionInvalidatedTags();
		expect(generalTags).toEqual(["Plugins"]);

		// 2. Action with empty invalidations list only invalidates Plugins tag
		const emptyTags = resolvePluginActionInvalidatedTags([]);
		expect(emptyTags).toEqual(["Plugins"]);

		// 3. Action declaring Analytics and Urls invalidates Plugins, Analytics, and Urls LIST
		const historyResetTags = resolvePluginActionInvalidatedTags([
			"Analytics",
			"Urls",
		]);
		expect(historyResetTags).toContain("Plugins");
		expect(historyResetTags).toContain("Analytics");
		expect(historyResetTags).toContainEqual({ type: "Urls", id: "LIST" });
		expect(historyResetTags).toHaveLength(3);

		// 4. Action with explicit target URL in payload also invalidates the targeted URL cache entry
		const targetedResetTags = resolvePluginActionInvalidatedTags(
			["Analytics", "Urls"],
			{ url_id: 42 }
		);
		expect(targetedResetTags).toContain("Plugins");
		expect(targetedResetTags).toContain("Analytics");
		expect(targetedResetTags).toContainEqual({ type: "Urls", id: "LIST" });
		expect(targetedResetTags).toContainEqual({ type: "Urls", id: "42" });
		expect(targetedResetTags).toHaveLength(4);

		// 5. Target URL supports camelCase urlId when Urls invalidation is declared
		const camelTargetedTags = resolvePluginActionInvalidatedTags(
			["Analytics", "Urls"],
			{ urlId: 101 }
		);
		expect(camelTargetedTags).toContain("Plugins");
		expect(camelTargetedTags).toContain("Analytics");
		expect(camelTargetedTags).toContainEqual({ type: "Urls", id: "LIST" });
		expect(camelTargetedTags).toContainEqual({ type: "Urls", id: "101" });
		expect(camelTargetedTags).toHaveLength(4);
	});
});
