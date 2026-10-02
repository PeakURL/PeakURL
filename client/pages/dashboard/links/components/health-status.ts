import type { LinkHealthStatus } from "@/api";
import { __ } from "@/i18n";

export interface HealthStatusDisplay {
	label: string;
	dotClass: string;
	textClass: string;
}

/**
 * Returns consistent, localized presentation properties for link health statuses.
 * Safely falls back to neutral representations for null or unknown values.
 */
export function getHealthStatusDisplay(
	status?: LinkHealthStatus | string | null
): HealthStatusDisplay {
	if (!status) {
		return {
			label: __("Not checked"),
			dotClass: "bg-stroke",
			textClass: "text-text-muted",
		};
	}

	switch (status) {
		case "healthy":
			return {
				label: __("Healthy"),
				dotClass: "bg-success",
				textClass: "text-success",
			};
		case "slow":
			return {
				label: __("Slow"),
				dotClass: "bg-warning",
				textClass: "text-warning",
			};
		case "unreachable":
			return {
				label: __("Unreachable"),
				dotClass: "bg-error",
				textClass: "text-error",
			};
		case "dns_error":
			return {
				label: __("DNS Error"),
				dotClass: "bg-error",
				textClass: "text-error",
			};
		case "tls_error":
			return {
				label: __("TLS Error"),
				dotClass: "bg-error",
				textClass: "text-error",
			};
		case "timeout":
			return {
				label: __("Timeout"),
				dotClass: "bg-error",
				textClass: "text-error",
			};
		case "http_error":
			return {
				label: __("HTTP Error"),
				dotClass: "bg-error",
				textClass: "text-error",
			};
		case "redirect_loop":
			return {
				label: __("Redirect Loop"),
				dotClass: "bg-error",
				textClass: "text-error",
			};
		case "ssrf_blocked":
			return {
				label: __("Blocked"),
				dotClass: "bg-error",
				textClass: "text-error",
			};
		default:
			return {
				label: __("Unknown"),
				dotClass: "bg-stroke",
				textClass: "text-text-muted",
			};
	}
}

/**
 * Format milliseconds into human-readable duration string (e.g. "120ms" or "1.5s").
 */
export function formatHealthDuration(
	milliseconds?: number | null,
	fractionDigits = 1
): string | null {
	if (
		milliseconds === null ||
		milliseconds === undefined ||
		milliseconds <= 0
	) {
		return null;
	}

	if (milliseconds < 1000) {
		return `${milliseconds}ms`;
	}

	return `${(milliseconds / 1000).toFixed(fractionDigits)}s`;
}
