import { formatDistanceToNow, isValid, parseISO } from "date-fns";

import { __, _n, sprintf } from "@/i18n";
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

export function formatNextRun(isoString: string | null | undefined): {
	text: string;
	isDue: boolean;
} {
	if (!isoString) {
		return { text: "—", isDue: false };
	}

	try {
		const date = parseISO(isoString);
		if (!isValid(date)) {
			return { text: "—", isDue: false };
		}
		const now = new Date();
		if (date.getTime() <= now.getTime()) {
			return { text: __("Due now"), isDue: true };
		}
		return {
			text: formatDistanceToNow(date, { addSuffix: true }),
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
		const date = parseISO(job.lastRunAt);
		if (!isValid(date)) {
			return { text: __("Never") };
		}
		const text = formatDistanceToNow(date, { addSuffix: true });
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
