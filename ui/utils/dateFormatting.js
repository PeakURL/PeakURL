const SECOND_MS = 1000;
const MINUTE_MS = 60 * SECOND_MS;
const HOUR_MS = 60 * MINUTE_MS;
const DAY_MS = 24 * HOUR_MS;
const WEEK_MS = 7 * DAY_MS;
const MONTH_MS = 30 * DAY_MS;
const YEAR_MS = 365 * DAY_MS;
const DEFAULT_LOCALE = 'en-US';

function toDate(value) {
	if (value instanceof Date) {
		return Number.isNaN(value.getTime()) ? null : new Date(value.getTime());
	}

	if ('string' === typeof value || 'number' === typeof value) {
		const date = new Date(value);
		return Number.isNaN(date.getTime()) ? null : date;
	}

	return null;
}

function getActiveLocale() {
	if (
		'undefined' !== typeof window &&
		'string' === typeof window.__PEAKURL_LOCALE__ &&
		window.__PEAKURL_LOCALE__
	) {
		return window.__PEAKURL_LOCALE__.replace(/_/g, '-');
	}

	if (
		'undefined' !== typeof document &&
		'string' === typeof document.documentElement?.lang &&
		document.documentElement.lang
	) {
		return document.documentElement.lang;
	}

	return DEFAULT_LOCALE;
}

function resolveRelativeUnit(targetDate, nowDate) {
	const deltaMs = targetDate.getTime() - nowDate.getTime();
	const absoluteDeltaMs = Math.abs(deltaMs);

	if (absoluteDeltaMs < 45 * SECOND_MS) {
		return {
			unit: 'second',
			value: Math.round(deltaMs / SECOND_MS),
		};
	}

	if (absoluteDeltaMs < 45 * MINUTE_MS) {
		return {
			unit: 'minute',
			value: Math.round(deltaMs / MINUTE_MS),
		};
	}

	if (absoluteDeltaMs < 22 * HOUR_MS) {
		return {
			unit: 'hour',
			value: Math.round(deltaMs / HOUR_MS),
		};
	}

	if (absoluteDeltaMs < 6 * DAY_MS) {
		return {
			unit: 'day',
			value: Math.round(deltaMs / DAY_MS),
		};
	}

	if (absoluteDeltaMs < 4 * WEEK_MS) {
		return {
			unit: 'week',
			value: Math.round(deltaMs / WEEK_MS),
		};
	}

	if (absoluteDeltaMs < 11 * MONTH_MS) {
		return {
			unit: 'month',
			value: Math.round(deltaMs / MONTH_MS),
		};
	}

	return {
		unit: 'year',
		value: Math.round(deltaMs / YEAR_MS),
	};
}

function formatRelativeTimeFallback(value, unit, style) {
	if (0 === value) {
		return 'now';
	}

	const absoluteValue = Math.abs(value);
	const compactUnitMap = {
		second: 's',
		minute: 'm',
		hour: 'h',
		day: 'd',
		week: 'w',
		month: 'mo',
		year: 'y',
	};
	const token =
		'compact' === style
			? `${absoluteValue}${compactUnitMap[unit] || ''}`
			: `${absoluteValue} ${unit}${1 === absoluteValue ? '' : 's'}`;

	return value < 0 ? `${token} ago` : `in ${token}`;
}

export function formatRelativeTime(value, options = {}) {
	const {
		style = 'long',
		numeric = 'always',
		now = new Date(),
	} = options;
	const targetDate = toDate(value);
	const nowDate = toDate(now) || new Date();

	if (!targetDate) {
		return '';
	}

	const { unit, value: relativeValue } = resolveRelativeUnit(
		targetDate,
		nowDate
	);

	if (
		'undefined' !== typeof Intl &&
		'function' === typeof Intl.RelativeTimeFormat
	) {
		try {
			return new Intl.RelativeTimeFormat(getActiveLocale(), {
				numeric,
				style: 'compact' === style ? 'narrow' : 'long',
			}).format(relativeValue, unit);
		} catch {
			return formatRelativeTimeFallback(relativeValue, unit, style);
		}
	}

	return formatRelativeTimeFallback(relativeValue, unit, style);
}

export function formatLocalizedDateTime(value, options = {}) {
	const targetDate = toDate(value);

	if (!targetDate) {
		return '';
	}

	try {
		return new Intl.DateTimeFormat(getActiveLocale(), options).format(
			targetDate
		);
	} catch {
		return targetDate.toLocaleString();
	}
}
