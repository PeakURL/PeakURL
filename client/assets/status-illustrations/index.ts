import illustrations from "../../../server/services/status-illustrations/manifest.json" with { type: "json" };

/** Semantic artwork names shared by React and PHP recovery pages. */
export type StatusIllustrationName = keyof typeof illustrations;

/** Resolve bundled artwork from the canonical manifest without remote requests. */
export function getStatusIllustrationUrl(name: StatusIllustrationName): string {
	const filename = illustrations[name];
	if (!filename) {
		throw new Error(`Missing bundled status illustration: ${name}`);
	}

	return new URL(
		`../../../server/services/status-illustrations/${filename}`,
		import.meta.url
	).href;
}
