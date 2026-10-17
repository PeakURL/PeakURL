/* Runs before paint; setup stays usable when storage or timezone detection is unavailable. */
(() => {
	const documentRoot = document.documentElement;
	const systemTheme = window.matchMedia("(prefers-color-scheme: dark)");
	let theme = systemTheme.matches ? "dark" : "light";
	try {
		const storedTheme = localStorage.getItem("theme");
		if (storedTheme === "light" || storedTheme === "dark")
			theme = storedTheme;
	} catch {}
	documentRoot.dataset.statusTheme = theme;

	document.addEventListener(
		"DOMContentLoaded",
		() => {
			const toggle = document.getElementById("installer-theme-toggle");
			const updateToggle = () =>
				toggle?.setAttribute("aria-pressed", String(theme === "dark"));
			updateToggle();
			toggle?.addEventListener("click", () => {
				theme = theme === "dark" ? "light" : "dark";
				documentRoot.dataset.statusTheme = theme;
				updateToggle();
				try {
					localStorage.setItem("theme", theme);
				} catch {}
			});
			try {
				const input = document.getElementById("browser_timezone");
				if (input && !input.value)
					input.value =
						Intl.DateTimeFormat().resolvedOptions().timeZone;
			} catch {}
		},
		{ once: true }
	);
})();
