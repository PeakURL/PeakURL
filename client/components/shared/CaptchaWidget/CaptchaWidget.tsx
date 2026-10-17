import {
	forwardRef,
	useImperativeHandle,
	useRef,
	useEffect,
	useState,
} from "react";
import { getPeakURLData } from "@/data";
import { useTheme } from "@/providers/ThemeProvider";

// Flexible Turnstile widgets have a 300px minimum; narrower forms use compact mode.
const TURNSTILE_MIN_FLEXIBLE_WIDTH = 300;

export interface CaptchaWidgetRef {
	/**
	 * Returns the CAPTCHA token, or undefined if CAPTCHA is disabled.
	 * Resolves a promise for reCAPTCHA, or returns the current state for Turnstile.
	 */
	getToken: () => Promise<string | undefined>;
}

declare global {
	interface Window {
		turnstile?: {
			render: (
				container: HTMLElement,
				options: Record<string, unknown>
			) => string | number;
			remove: (widgetId: string | number) => void;
		};
		grecaptcha?: {
			ready: (callback: () => void) => void;
			execute: (
				siteKey: string,
				options: { action: string }
			) => Promise<string>;
		};
	}
}

export const CaptchaWidget = forwardRef<CaptchaWidgetRef>((_, ref) => {
	const { captcha } = getPeakURLData();
	const { theme } = useTheme();
	const containerRef = useRef<HTMLDivElement>(null);
	const [scriptLoaded, setScriptLoaded] = useState(false);
	const [turnstileToken, setTurnstileToken] = useState<string>("");

	useEffect(() => {
		if (!captcha) return;

		let scriptUrl = captcha.scriptUrl;
		if (captcha.provider === "recaptcha") {
			scriptUrl += `?render=${encodeURIComponent(captcha.siteKey)}`;
		}

		if (
			(captcha.provider === "turnstile" && window.turnstile) ||
			(captcha.provider === "recaptcha" && window.grecaptcha)
		) {
			setScriptLoaded(true);
			return;
		}

		const existingScript = document.querySelector<HTMLScriptElement>(
			`script[src="${scriptUrl}"]`
		);
		const script = existingScript ?? document.createElement("script");
		const handleLoad = () => setScriptLoaded(true);
		// An existing script can still be downloading when another widget mounts.
		script.addEventListener("load", handleLoad, { once: true });
		if (!existingScript) {
			script.src = scriptUrl;
			script.async = true;
			script.defer = true;
			document.head.appendChild(script);
		}
		return () => script.removeEventListener("load", handleLoad);
	}, [captcha]);

	useEffect(() => {
		if (
			!scriptLoaded ||
			!captcha ||
			captcha.provider !== "turnstile" ||
			!containerRef.current
		) {
			return;
		}

		if (!window.turnstile) {
			return;
		}

		const container = containerRef.current;
		const turnstile = window.turnstile;
		let widgetId: string | number | undefined;
		let currentSize: "compact" | "flexible" | undefined;
		let widgetRevision = 0;
		const renderWidget = () => {
			const size =
				container.clientWidth < TURNSTILE_MIN_FLEXIBLE_WIDTH
					? "compact"
					: "flexible";
			if (size === currentSize) return;
			const revision = ++widgetRevision;
			if (widgetId !== undefined) turnstile.remove(widgetId);
			currentSize = size;
			setTurnstileToken("");
			const updateToken = (token: string) => {
				if (revision === widgetRevision) setTurnstileToken(token);
			};
			widgetId = turnstile.render(container, {
				sitekey: captcha.siteKey,
				theme,
				size,
				callback: updateToken,
				"error-callback": () => updateToken(""),
				"expired-callback": () => updateToken(""),
			});
		};
		const observer = new ResizeObserver(renderWidget);
		observer.observe(container);
		renderWidget();

		return () => {
			widgetRevision++;
			observer.disconnect();
			if (widgetId !== undefined) turnstile.remove(widgetId);
		};
	}, [scriptLoaded, captcha, theme]);

	useImperativeHandle(ref, () => ({
		getToken: async () => {
			if (!captcha) return undefined;

			if (captcha.provider === "recaptcha") {
				return new Promise<string>((resolve, reject) => {
					if (!window.grecaptcha) {
						reject(
							new Error(
								"reCAPTCHA script not loaded yet. Please try again."
							)
						);
						return;
					}
					window.grecaptcha.ready(() => {
						window.grecaptcha
							?.execute(captcha.siteKey, {
								action: captcha.action || "auth",
							})
							.then(resolve)
							.catch(reject);
					});
				});
			}

			if (captcha.provider === "turnstile") {
				return turnstileToken;
			}

			return undefined;
		},
	}));

	if (!captcha || captcha.provider !== "turnstile") {
		return null;
	}

	return (
		<div className="captcha-widget">
			<div className="captcha-widget-control" ref={containerRef} />
		</div>
	);
});

CaptchaWidget.displayName = "CaptchaWidget";
