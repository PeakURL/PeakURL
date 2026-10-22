/* Inlined by the public link controller with an escaped, public-only config. */
(function (config) {
	const SCRIPT_TIMEOUT_MS = 20000;
	const VERIFICATION_TIMEOUT_MS = 60000;
	const SUBMISSION_TIMEOUT_MS = 20000;
	let script = null;
	let scriptTimer = null;
	let verificationTimer = null;
	let submissionTimer = null;
	let widgetId = null;
	let attempt = 0;
	let active = false;
	let submitted = false;
	let requested = config.autoVerify;

	const element = (id) => document.getElementById(id);
	const providerReady = () =>
		config.provider === "turnstile"
			? typeof window.turnstile?.render === "function"
			: typeof window.grecaptcha?.execute === "function";

	function setStatus(
		message,
		state,
		visible = config.provider !== "turnstile" ||
			state === "warning" ||
			state === "error"
	) {
		const panel = element("captcha-status-panel");
		const needsRetry = state === "warning" || state === "error";
		element("captcha-status-text").textContent = message;
		panel.className = `captcha-status-panel captcha-status-panel-${state}`;
		panel.hidden = !visible;
		element("captcha-retry-button").hidden = !needsRetry;
		element("captcha-form").setAttribute(
			"aria-busy",
			String(state === "pending")
		);
		if (needsRetry) element("captcha-widget")?.setAttribute("hidden", "");
	}

	function fail(message) {
		if (submitted) return;
		attempt += 1;
		active = false;
		requested = false;
		clearTimeout(scriptTimer);
		clearTimeout(verificationTimer);
		element("captcha-token").value = "";
		setStatus(message, "warning");
	}

	function waitForVerification(currentAttempt) {
		clearTimeout(verificationTimer);
		verificationTimer = setTimeout(() => {
			if (active && currentAttempt === attempt)
				fail(config.messages.slow);
		}, VERIFICATION_TIMEOUT_MS);
	}

	function verified(token, currentAttempt) {
		if (!active || submitted || currentAttempt !== attempt) return;
		if (!token) {
			fail(config.messages.error);
			return;
		}
		clearTimeout(verificationTimer);
		active = false;
		submitted = true;
		element("captcha-token").value = token;
		setStatus(config.messages.verified, "success");
		try {
			// Submit the callback token directly, without waiting for a provider-owned field or animation.
			HTMLFormElement.prototype.submit.call(element("captcha-form"));
			submissionTimer = setTimeout(() => {
				submitted = false;
				fail(config.messages.continueError);
			}, SUBMISSION_TIMEOUT_MS);
		} catch {
			submitted = false;
			fail(config.messages.continueError);
		}
	}

	function startVerification() {
		if (active || submitted) return;
		requested = true;
		if (!providerReady()) {
			loadProvider();
			return;
		}
		clearTimeout(scriptTimer);
		clearTimeout(submissionTimer);
		requested = false;
		active = true;
		const currentAttempt = ++attempt;
		element("captcha-token").value = "";
		setStatus(config.messages.running, "pending");
		waitForVerification(currentAttempt);

		if (config.provider === "recaptcha") {
			try {
				window.grecaptcha.ready(() => {
					if (!active || currentAttempt !== attempt) return;
					Promise.resolve()
						.then(() =>
							window.grecaptcha.execute(config.siteKey, {
								action: config.action,
							})
						)
						.then(
							(token) => verified(token, currentAttempt),
							() => {
								if (currentAttempt === attempt)
									fail(config.messages.error);
							}
						);
				});
			} catch {
				fail(config.messages.error);
			}
			return;
		}

		try {
			if (widgetId !== null) window.turnstile.remove(widgetId);
			const widget = element("captcha-widget");
			widget.hidden = false;
			widgetId = window.turnstile.render("#captcha-turnstile", {
				sitekey: config.siteKey,
				theme:
					document.documentElement.dataset.statusTheme === "auto"
						? "auto"
						: document.documentElement.dataset.statusTheme,
				language: "auto",
				size: widget.clientWidth < 300 ? "compact" : "normal",
				execution: "execute",
				retry: "never",
				"refresh-expired": "manual",
				"refresh-timeout": "manual",
				"response-field": false,
				callback: (token) => verified(token, currentAttempt),
				"expired-callback": () => {
					if (currentAttempt === attempt)
						fail(config.messages.expired);
				},
				"error-callback": () => {
					if (currentAttempt === attempt) fail(config.messages.error);
					return true;
				},
				"timeout-callback": () => {
					if (currentAttempt === attempt) fail(config.messages.slow);
				},
				"unsupported-callback": () => {
					if (currentAttempt === attempt)
						fail(config.messages.unsupported);
				},
				// Allow time for a visitor to complete an interactive challenge.
				"before-interactive-callback": () => {
					if (currentAttempt === attempt)
						clearTimeout(verificationTimer);
				},
				"after-interactive-callback": () => {
					if (active && currentAttempt === attempt)
						waitForVerification(currentAttempt);
				},
			});
			if (!active || currentAttempt !== attempt) return;
			setStatus(config.messages.running, "pending", false);
			window.turnstile.execute(widgetId);
		} catch {
			fail(config.messages.error);
		}
	}

	function onProviderReady() {
		if (!providerReady()) return;
		clearTimeout(scriptTimer);
		if (requested) startVerification();
	}

	function loadProvider() {
		if (providerReady()) {
			onProviderReady();
			return;
		}
		if (scriptTimer !== null && script?.isConnected) return;
		if (!config.scriptUrl) {
			fail(config.messages.loadError);
			return;
		}
		script?.remove();
		setStatus(config.messages.loading, "pending");
		script = document.createElement("script");
		script.async = true;
		script.src = config.scriptUrl;
		const currentScript = script;
		script.addEventListener("load", () => {
			if (script === currentScript) onProviderReady();
		});
		script.addEventListener("error", () => {
			if (script !== currentScript) return;
			scriptTimer = null;
			fail(config.messages.loadError);
		});
		scriptTimer = setTimeout(() => {
			if (script !== currentScript) return;
			scriptTimer = null;
			fail(config.messages.loadError);
		}, SCRIPT_TIMEOUT_MS);
		document.head.appendChild(script);
	}

	window.PeakURLRenderCaptcha = onProviderReady;
	function initialize() {
		element("captcha-retry-button").addEventListener("click", () => {
			clearTimeout(scriptTimer);
			scriptTimer = null;
			startVerification();
		});
		if (config.autoVerify) startVerification();
		else setStatus(config.messages.initial, "error");
	}
	if (document.readyState === "loading")
		document.addEventListener("DOMContentLoaded", initialize, {
			once: true,
		});
	else initialize();
})(__PEAKURL_CAPTCHA_CONFIG__);
