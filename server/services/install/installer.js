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

	// Lock the submit button until navigation, then restore it after Back.
	const submitSelector =
		'form[method="post"] button.btn-primary[type="submit"]';
	document.addEventListener("submit", (event) => {
		if (!(event.target instanceof HTMLFormElement)) return;
		const submit = event.target.querySelector(
			'button.btn-primary[type="submit"]'
		);
		if (!(submit instanceof HTMLButtonElement)) return;
		if (submit.disabled) {
			event.preventDefault();
			return;
		}
		submit.disabled = true;
		event.target.setAttribute("aria-busy", "true");
	});
	window.addEventListener("pageshow", () => {
		for (const submit of document.querySelectorAll(submitSelector)) {
			submit.disabled = false;
			submit.form?.removeAttribute("aria-busy");
		}
	});

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
