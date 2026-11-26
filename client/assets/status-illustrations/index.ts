import illustrations from "../../../server/services/status-illustrations/manifest.json" with { type: "json" };

const artwork = import.meta.glob<string>(
	"../../../server/services/status-illustrations/*.svg",
	{ eager: true, query: "?raw", import: "default" }
);

/** Semantic artwork names shared by React and PHP recovery pages. */
export type StatusIllustrationName = keyof typeof illustrations;

/** Resolve bundled artwork from the canonical manifest without remote requests. */
export function getStatusIllustrationUrl(
	name: StatusIllustrationName,
	theme: "light" | "dark"
): string {
	const filename = illustrations[name];
	const svg =
		artwork[`../../../server/services/status-illustrations/${filename}`];
	if (!svg) {
		throw new Error(`Missing bundled status illustration: ${name}`);
	}

	// Image documents can use the OS preference independently of the dashboard.
	const themed = svg.replace(
		"@media(prefers-color-scheme:dark)",
		theme === "dark" ? "@media all" : "@media not all"
	);
	return `data:image/svg+xml,${encodeURIComponent(themed)}`;
}
