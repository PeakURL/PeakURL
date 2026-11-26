/* Standalone documents share the dashboard theme preference. */
try {
	const root = document.documentElement;
	const theme = localStorage.getItem("theme");
	if (
		root.dataset.statusTheme === "auto" &&
		(theme === "light" || theme === "dark")
	) {
		root.dataset.statusTheme = theme;
	}
} catch {}

/* Let the embedded illustration follow an explicit theme in the document. */
document.addEventListener(
	"DOMContentLoaded",
	() => {
		const root = document.documentElement;
		const artwork = document.querySelector("img[data-status-illustration]");
		if (!artwork) return;

		const originalSource = artwork.getAttribute("src");
		const prefix = "data:image/svg+xml;base64,";
		if (!originalSource?.startsWith(prefix)) return;
		const originalSvg = atob(originalSource.slice(prefix.length));
		const applyTheme = () => {
			const theme = root.dataset.statusTheme;
			const media =
				theme === "dark"
					? "@media all"
					: theme === "light"
						? "@media not all"
						: "";
			artwork.src = media
				? prefix +
					btoa(
						originalSvg.replace(
							"@media(prefers-color-scheme:dark)",
							media
						)
					)
				: originalSource;
		};
		applyTheme();
		new MutationObserver(applyTheme).observe(root, {
			attributes: true,
			attributeFilter: ["data-status-theme"],
		});
	},
	{ once: true }
);
