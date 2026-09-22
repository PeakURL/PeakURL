import { __, _n, sprintf } from "@/i18n";
import { formatRelativeTime } from "@/shared/dates";
import type { CronJob } from "@/api";

export function formatInterval(seconds: number): string {
	if (seconds <= 0) {
		return __("Manual");
	}
	if (60 === seconds) {
		return __("Every minute");
	}
	if (seconds < 3600) {
		const mins = Math.round(seconds / 60);
		return sprintf(
			/* translators: %d is interval in minutes */
			_n("Every %d minute", "Every %d minutes", mins),
			mins
		);
	}
	if (3600 === seconds) {
		return __("Hourly");
	}
	if (seconds < 86400) {
		const hours = Math.round(seconds / 3600);
		if (12 === hours) {
			return __("Every 12 hours");
		}
		return sprintf(
			/* translators: %d is interval in hours */
			_n("Every %d hour", "Every %d hours", hours),
			hours
		);
	}
	if (86400 === seconds) {
		return __("Daily");
	}
	if (604800 === seconds) {
		return __("Weekly");
	}
	const days = Math.round(seconds / 86400);
	return sprintf(
		/* translators: %d is interval in days */
		_n("Every %d day", "Every %d days", days),
		days
	);
}

export function formatSchedule(
	seconds: number,
	preferredRunTime?: string | null
): string {
	const base = formatInterval(seconds);
	if (preferredRunTime && seconds >= 86400) {
		return sprintf(
			/* translators: 1: interval recurrence, 2: preferred time of day */
			__("%1$s at %2$s"),
			base,
			preferredRunTime
		);
	}
	return base;
}

export function formatNextRun(isoString: string | null | undefined): {
	text: string;
	isDue: boolean;
} {
	if (!isoString) {
		return { text: "—", isDue: false };
	}

	try {
		const date = new Date(isoString);
		if (Number.isNaN(date.getTime())) {
			return { text: "—", isDue: false };
		}
		const now = new Date();
		if (date.getTime() <= now.getTime()) {
			return { text: __("Due now"), isDue: true };
		}
		return {
			text: formatRelativeTime(date),
			isDue: false,
		};
	} catch {
		return { text: "—", isDue: false };
	}
}

export function formatLastRun(job: CronJob): { text: string; sub?: string } {
	if (!job.lastRunAt) {
		return { text: __("Never") };
	}

	try {
		const date = new Date(job.lastRunAt);
		if (Number.isNaN(date.getTime())) {
			return { text: __("Never") };
		}
		const text = formatRelativeTime(date);
		const latestRun = job.recentRuns[0];
		const duration =
			undefined !== latestRun?.durationMs && null !== latestRun.durationMs
				? `${latestRun.durationMs}ms`
				: undefined;
		return { text, sub: duration };
	} catch {
		return { text: __("Never") };
	}
}

/**
 * Register built-in job titles for gettext string extraction.
 */
export const BUILTIN_JOB_TITLES: readonly string[] = [
	__("Session Cleanup"),
	__("GeoIP Database Refresh"),
	__("Expired Links Processing"),
	__("Cache Cleanup"),
	__("Analytics & Trash Retention"),
	__("PeakURL Version Check"),
	__("Webhook Delivery & Health"),
	__("Import & Export Scratch Cleanup"),
	__("Link Destination Health Check"),
];

export function formatJobOutputSummary(
	outputSummary: string | null | undefined
): string {
	if (!outputSummary) return "";
	const summary = outputSummary.trim();
	if (!summary) return "";

	return (
		formatSessionCleanupSummary(summary) ||
		formatGeoipSummary(summary) ||
		formatExpiredLinksSummary(summary) ||
		formatCacheCleanupSummary(summary) ||
		formatRetentionSummary(summary) ||
		formatVersionCheckSummary(summary) ||
		formatWebhookSummary(summary) ||
		formatImportExportSummary(summary) ||
		formatHealthCheckSummary(summary) ||
		__(summary)
	);
}

function formatSessionCleanupSummary(summary: string): string | null {
	const match = summary.match(
		/^Pruned\s+(\d+)\s+expired\s+or\s+revoked\s+session(?:\(s\)|s)?\.$/i
	);
	if (match && match[1]) {
		const count = parseInt(match[1], 10);
		return sprintf(
			/* translators: %d is the number of deleted sessions */
			_n(
				"Pruned %d expired or revoked session.",
				"Pruned %d expired or revoked sessions.",
				count
			),
			count
		);
	}
	return null;
}

function formatGeoipSummary(summary: string): string | null {
	if (/^Location\s+data\s+is\s+not\s+configured$/i.test(summary)) {
		return __("Location data is not configured");
	}
	if (/^GeoIP\s+database\s+is\s+already\s+up\s+to\s+date$/i.test(summary)) {
		return __("GeoIP database is already up to date");
	}
	const match = summary.match(
		/^GeoLite2\s+City\s+database\s+refreshed\s+at\s+(.+)$/i
	);
	if (match && match[1]) {
		return sprintf(
			/* translators: %s is the path where database was saved */
			__("GeoLite2 City database refreshed at %s"),
			match[1]
		);
	}
	return null;
}

function formatExpiredLinksSummary(summary: string): string | null {
	if (/^No\s+expired\s+links\s+due\s+for\s+processing\.$/i.test(summary)) {
		return __("No expired links due for processing.");
	}
	const match = summary.match(
		/^Processed\s+(\d+)\s+expired\s+link(?:\(s\)|s)?\.$/i
	);
	if (match && match[1]) {
		const count = parseInt(match[1], 10);
		return sprintf(
			/* translators: %d is the number of processed links */
			_n(
				"Processed %d expired link.",
				"Processed %d expired links.",
				count
			),
			count
		);
	}
	return null;
}

function formatCacheCleanupSummary(summary: string): string | null {
	const driverMatch = summary.match(
		/^Cache\s+driver\s+"([^"]+)"\s+manages\s+expiration\s+automatically;\s+no\s+filesystem\s+sweep\s+required\.$/i
	);
	if (driverMatch && driverMatch[1]) {
		return sprintf(
			/* translators: %s is the cache driver name */
			__(
				'Cache driver "%s" manages expiration automatically; no filesystem sweep required.'
			),
			driverMatch[1]
		);
	}
	const match = summary.match(
		/^Purged\s+(\d+)\s+expired\s+file\s+cache\s+item(?:\(s\)|s)?\.$/i
	);
	if (match && match[1]) {
		const count = parseInt(match[1], 10);
		return sprintf(
			/* translators: %d is the number of purged cache files */
			_n(
				"Purged %d expired file cache item.",
				"Purged %d expired file cache items.",
				count
			),
			count
		);
	}
	return null;
}

function formatRetentionSummary(summary: string): string | null {
	const match = summary.match(
		/^Retention\s+enforced:\s+(\d+)\s+trashed\s+link(?:\(s\)|s)?\s+purged,\s+(\d+)\s+old\s+click(?:\(s\)|s)?\s+purged\.$/i
	);
	if (match && match[1] && match[2]) {
		const links = parseInt(match[1], 10);
		const clicks = parseInt(match[2], 10);

		// we need to translate the two counts individually per TODO.md:
		// "Because there are two independent quantities, design the formatter so both noun groups can be rendered correctly."

		const linksPart = sprintf(
			_n("%d trashed link purged", "%d trashed links purged", links),
			links
		);
		const clicksPart = sprintf(
			_n("%d old click purged", "%d old clicks purged", clicks),
			clicks
		);

		return sprintf(
			/* translators: 1: formatted trashed links phrase, 2: formatted old clicks phrase */
			__("Retention enforced: %1$s, %2$s."),
			linksPart,
			clicksPart
		);
	}
	return null;
}

function formatVersionCheckSummary(summary: string): string | null {
	const match = summary.match(
		/^Version\s+check\s+complete:\s*remote\s+release\s+(.+?)\s+found\.$/i
	);
	if (match && match[1]) {
		return sprintf(
			/* translators: %s is the remote version string */
			__("Version check complete: remote release %s found."),
			match[1]
		);
	}
	return null;
}

function formatWebhookSummary(summary: string): string | null {
	if (/^No\s+pending\s+webhook\s+deliveries\.$/i.test(summary)) {
		return __("No pending webhook deliveries.");
	}
	const match = summary.match(
		/^Processed\s+(\d+)\s+pending\s+webhook\s+deliver(?:y(?:\(ies\))?|ies)\s*\((\d+)\s+delivered,\s*(\d+)\s+queued\s+for\s+retry,\s*(\d+)\s+failed\)\.$/i
	);
	if (match && match[1] && match[2] && match[3] && match[4]) {
		const total = parseInt(match[1], 10);
		const delivered = parseInt(match[2], 10);
		const retried = parseInt(match[3], 10);
		const failed = parseInt(match[4], 10);
		return sprintf(
			/* translators: 1: total deliveries processed, 2: delivered count, 3: queued for retry, 4: failed count */
			_n(
				"Processed %1$d pending webhook delivery (%2$d delivered, %3$d queued for retry, %4$d failed).",
				"Processed %1$d pending webhook deliveries (%2$d delivered, %3$d queued for retry, %4$d failed).",
				total
			),
			total,
			delivered,
			retried,
			failed
		);
	}
	return null;
}

function formatImportExportSummary(summary: string): string | null {
	const match = summary.match(
		/^Cleaned\s+(\d+)\s+stale\s+import\/export\s+temporary\s+file(?:\(s\)|s)?\.$/i
	);
	if (match && match[1]) {
		const count = parseInt(match[1], 10);
		return sprintf(
			/* translators: %d is the count of cleaned scratch files */
			_n(
				"Cleaned %d stale import/export temporary file.",
				"Cleaned %d stale import/export temporary files.",
				count
			),
			count
		);
	}
	return null;
}

function formatHealthCheckSummary(summary: string): string | null {
	if (
		/^No\s+active\s+links\s+available\s+for\s+health\s+check\.$/i.test(
			summary
		)
	) {
		return __("No active links available for health check.");
	}
	const match = summary.match(
		/^Health\s+check\s+completed\s+for\s+(\d+)\s+link(?:\(s\)|s)?:\s*(\d+)\s+healthy,\s*(\d+)\s+unreachable,\s*(\d+)\s+blocked\s+by\s+SSRF\s+filter\.$/i
	);
	if (match && match[1] && match[2] && match[3] && match[4]) {
		const total = parseInt(match[1], 10);
		const healthy = parseInt(match[2], 10);
		const unreachable = parseInt(match[3], 10);
		const blocked = parseInt(match[4], 10);
		return sprintf(
			/* translators: 1: total links checked, 2: healthy count, 3: unreachable count, 4: blocked by SSRF filter */
			_n(
				"Health check completed for %1$d link: %2$d healthy, %3$d unreachable, %4$d blocked by SSRF filter.",
				"Health check completed for %1$d links: %2$d healthy, %3$d unreachable, %4$d blocked by SSRF filter.",
				total
			),
			total,
			healthy,
			unreachable,
			blocked
		);
	}
	return null;
}

/**
 * Format and localize background task error messages.
 *
 * @param errorMessage Raw error message.
 * @return Localized error message string.
 */
export function formatJobErrorMessage(
	errorMessage: string | null | undefined
): string {
	if (!errorMessage) {
		return "";
	}

	const error = errorMessage.trim();
	if (!error) {
		return "";
	}

	const geoipFailMatch = error.match(/^GeoIP\s+update\s+failed:\s*(.+)$/i);
	if (geoipFailMatch && geoipFailMatch[1]) {
		return sprintf(
			/* translators: %s is the error message */
			__("GeoIP update failed: %s"),
			geoipFailMatch[1]
		);
	}

	const versionFailMatch = error.match(/^Version\s+check\s+failed:\s*(.+)$/i);
	if (versionFailMatch && versionFailMatch[1]) {
		return sprintf(
			/* translators: %s is the error message */
			__("Version check failed: %s"),
			versionFailMatch[1]
		);
	}

	return __(error);
}
