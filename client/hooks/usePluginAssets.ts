import { useEffect } from "react";
import type { PluginAssetDescriptor } from "@/api/types/plugins";

declare global {
	interface Window {
		PeakURLPlugins?: Record<
			string,
			{
				mount?: (context: {
					pluginSlug: string;
					pageId?: string;
				}) => void | (() => void);
				unmount?: (context: {
					pluginSlug: string;
					pageId?: string;
				}) => void;
				[key: string]: unknown;
			}
		>;
	}
}

/**
 * Global cache of script URLs that have already executed in this browser session.
 *
 * In web standards, removing a `<script>` node does not undo already-executed
 * JavaScript or remove objects attached to `window`. Tracking executed scripts
 * prevents duplicate execution when users navigate back to plugin views.
 */
const executedScriptUrls = new Set<string>();

export interface PluginAssetContext {
	readonly pluginSlug?: string;
	readonly pageId?: string;
}

/**
 * Dynamically attach scoped plugin stylesheets and scripts with precise lifecycle control.
 *
 * Lifecycle contract:
 * - Stylesheets (`<link rel="stylesheet">`) are attached on mount and cleaned up on unmount
 *   to prevent plugin CSS rules from leaking into other dashboard views.
 * - Scripts (`<script>`) are attached once and execute in the browser window context.
 *   Subsequent visits to the same plugin view reuse already-executed modules without duplicate re-evaluation.
 * - If a plugin defines a lifecycle hook on `window.PeakURLPlugins[slug]`:
 *   - `mount(context)` is invoked on mount (or when the script finishes loading).
 *   - The returned cleanup function (or `unmount(context)`) is invoked on unmount.
 * - Assets with missing URLs or unrecognized shapes are safely ignored.
 *
 * @param assets Registered asset descriptors to load.
 * @param context Optional lifecycle context containing pluginSlug and pageId.
 */
export const usePluginAssets = (
	assets?: readonly PluginAssetDescriptor[],
	context?: PluginAssetContext
): void => {
	const pluginSlug = context?.pluginSlug;
	const pageId = context?.pageId;

	useEffect(() => {
		if (!assets || assets.length === 0) {
			return;
		}

		const attachedLinks: HTMLLinkElement[] = [];
		let cleanupFn: (() => void) | void;

		const triggerMount = () => {
			if (!pluginSlug || typeof window === "undefined") {
				return;
			}
			const hook = window.PeakURLPlugins?.[pluginSlug];
			if (typeof hook?.mount === "function") {
				try {
					cleanupFn = hook.mount({ pluginSlug, pageId });
				} catch (err) {
					console.error(
						`[PeakURL Plugins] Error in mount hook for plugin "${pluginSlug}":`,
						err
					);
				}
			}
		};

		assets.forEach((asset) => {
			if (!asset.url) {
				return;
			}

			if (asset.type === "style") {
				const selector = `link[data-plugin-asset="${CSS.escape(asset.url)}"]`;
				let link = document.querySelector(
					selector
				) as HTMLLinkElement | null;

				if (!link) {
					link = document.createElement("link");
					link.rel = "stylesheet";
					link.href = asset.url;
					link.setAttribute("data-plugin-asset", asset.url);
					document.head.appendChild(link);
				}

				attachedLinks.push(link);
			} else if (asset.type === "script") {
				if (executedScriptUrls.has(asset.url)) {
					// Script already executed, trigger mount directly
					triggerMount();
					return;
				}

				const selector = `script[data-plugin-asset="${CSS.escape(asset.url)}"]`;
				const existing = document.querySelector(selector);
				if (existing) {
					executedScriptUrls.add(asset.url);
					triggerMount();
					return;
				}

				const script = document.createElement("script");
				script.src = asset.url;
				script.async = true;
				script.setAttribute("data-plugin-asset", asset.url);
				script.onload = () => {
					executedScriptUrls.add(asset.url);
					triggerMount();
				};
				script.onerror = () => {
					console.error(
						`[PeakURL Plugins] Failed to load plugin script: ${asset.url}`
					);
				};
				document.head.appendChild(script);
			}
		});

		return () => {
			// Invoke registered unmount / cleanup handler for the plugin.
			if (typeof cleanupFn === "function") {
				try {
					cleanupFn();
				} catch (err) {
					console.error(
						`[PeakURL Plugins] Error in unmount cleanup for plugin "${pluginSlug}":`,
						err
					);
				}
			} else if (pluginSlug && typeof window !== "undefined") {
				const hook = window.PeakURLPlugins?.[pluginSlug];
				if (typeof hook?.unmount === "function") {
					try {
						hook.unmount({ pluginSlug, pageId });
					} catch (err) {
						console.error(
							`[PeakURL Plugins] Error in unmount hook for plugin "${pluginSlug}":`,
							err
						);
					}
				}
			}

			// Unload scoped stylesheets on page departure to prevent CSS leakage.
			attachedLinks.forEach((link) => {
				if (link.parentNode) {
					link.parentNode.removeChild(link);
				}
			});
		};
	}, [assets, pluginSlug, pageId]);
};
