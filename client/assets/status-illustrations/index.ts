import illustrations from "../../../server/services/status-illustrations/manifest.json";

/** Semantic artwork names shared by React and PHP recovery pages. */
export type StatusIllustrationName = keyof typeof illustrations;

const assetUrls = import.meta.glob<string>(
	"/server/services/status-illustrations/*.svg",
	{ eager: true, query: "?url&no-inline", import: "default" }
);

/** Resolve bundled artwork from the canonical manifest without remote requests. */
export function getStatusIllustrationUrl(name: StatusIllustrationName): string {
	const url =
		assetUrls[
			`/server/services/status-illustrations/${illustrations[name]}`
		];
	if (!url) throw new Error(`Missing bundled status illustration: ${name}`);
	return url;
}
