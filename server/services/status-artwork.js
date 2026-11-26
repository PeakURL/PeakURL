/* Standalone documents share the dashboard theme preference. */
(() => {
	const root = document.documentElement;
	const darkScheme = window.matchMedia("(prefers-color-scheme: dark)");

	try {
		const theme = localStorage.getItem("theme");
		if (
			root.dataset.statusTheme === "auto" &&
			(theme === "light" || theme === "dark")
		) {
			root.dataset.statusTheme = theme;
		}
	} catch {}

	const resolveTheme = () => {
		const explicit = root.dataset.statusTheme;
		if (explicit === "light" || explicit === "dark") {
			return explicit;
		}
		return darkScheme.matches ? "dark" : "light";
	};

	/* Keep interactive toggles and embedded illustrations synced with document theme. */
	document.addEventListener(
		"DOMContentLoaded",
		() => {
			const syncToggles = () => {
				const isDark = resolveTheme() === "dark";
				for (const toggle of document.querySelectorAll(
					"[data-theme-toggle]"
				)) {
					toggle.setAttribute("aria-pressed", String(isDark));
				}
			};
			syncToggles();

			for (const toggle of document.querySelectorAll(
				"[data-theme-toggle]"
			)) {
				toggle.addEventListener("click", () => {
					const nextTheme =
						resolveTheme() === "dark" ? "light" : "dark";
					root.dataset.statusTheme = nextTheme;
					syncToggles();
					try {
						localStorage.setItem("theme", nextTheme);
					} catch {}
				});
			}

			darkScheme.addEventListener?.("change", syncToggles);

			const artwork = document.querySelector(
				"img[data-status-illustration]"
			);
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
})();
