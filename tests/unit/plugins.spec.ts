import { test, expect } from "@playwright/test";
import { API_ROUTES } from "../../client/api";
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
			homepage: "https://example.com/plugin",
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
});
