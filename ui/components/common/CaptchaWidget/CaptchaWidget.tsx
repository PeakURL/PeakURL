import {
	forwardRef,
	useImperativeHandle,
	useRef,
	useEffect,
	useState,
} from "react";
import { getPeakURLData } from "@/data";
import { useTheme } from "@/components/providers";

export interface CaptchaWidgetRef {
	/**
	 * Returns the CAPTCHA token, or undefined if CAPTCHA is disabled.
	 * Resolves a promise for reCAPTCHA, or returns the current state for Turnstile.
	 */
	getToken: () => Promise<string | undefined>;
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

		if (document.querySelector(`script[src="${scriptUrl}"]`)) {
			setScriptLoaded(true);
			return;
		}

		const script = document.createElement("script");
		script.src = scriptUrl;
		script.async = true;
		script.defer = true;
		script.onload = () => setScriptLoaded(true);
		document.head.appendChild(script);
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

		// @ts-ignore
		if (!window.turnstile) {
			return;
		}

		// @ts-ignore
		const widgetId = window.turnstile.render(containerRef.current, {
			sitekey: captcha.siteKey,
			theme: theme,
			callback: (token: string) => setTurnstileToken(token),
			"error-callback": () => setTurnstileToken(""),
			"expired-callback": () => setTurnstileToken(""),
		});

		return () => {
			// @ts-ignore
			if (window.turnstile) {
				// @ts-ignore
				window.turnstile.remove(widgetId);
			}
		};
	}, [scriptLoaded, captcha, theme]);

	useImperativeHandle(ref, () => ({
		getToken: async () => {
			if (!captcha) return undefined;

			if (captcha.provider === "recaptcha") {
				return new Promise<string>((resolve, reject) => {
					// @ts-ignore
					if (!window.grecaptcha) {
						reject(
							new Error(
								"reCAPTCHA script not loaded yet. Please try again."
							)
						);
						return;
					}
					// @ts-ignore
					window.grecaptcha.ready(() => {
						// @ts-ignore
						window.grecaptcha
							.execute(captcha.siteKey, {
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
		<div
			className="captcha-widget"
			style={{
				marginBottom: "1.5rem",
				minHeight: "65px",
				display: "flex",
				justifyContent: "center",
			}}
		>
			<div ref={containerRef} />
		</div>
	);
});

CaptchaWidget.displayName = "CaptchaWidget";
