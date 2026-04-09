import { matchPath } from 'react-router-dom';
import { applyFilters } from '@/utils';

const BODY_CLASS_DATA_ATTRIBUTE = 'peakurlBodyClasses';

interface BodyClassContext {
	pathname: string;
	pageType: 'auth' | 'dashboard' | 'default';
	pageSlug: string;
}

function sanitizeBodyClassName(value: string): string {
	return value
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9-]+/g, '-')
		.replace(/-{2,}/g, '-')
		.replace(/^-+|-+$/g, '');
}

function uniqueBodyClassNames(
	classes: Array<string | false | null | undefined>
): string[] {
	return Array.from(
		new Set(
			classes
				.map((className) =>
					className ? sanitizeBodyClassName(className) : ''
				)
				.filter(Boolean)
		)
	);
}

function getRuntimeBodyClassNames(): string[] {
	if (
		'undefined' === typeof window ||
		!Array.isArray(window.__PEAKURL_BODY_CLASSES__)
	) {
		return [];
	}

	return uniqueBodyClassNames(window.__PEAKURL_BODY_CLASSES__);
}

function getAuthBodyClassNames(pathname: string): string[] {
	if ('/login' === pathname) {
		return ['peakurl-ui', 'public-page', 'auth-page', 'login-page'];
	}

	if ('/forgot-password' === pathname) {
		return [
			'peakurl-ui',
			'public-page',
			'auth-page',
			'auth-page-recovery',
			'forgot-password-page',
		];
	}

	if (matchPath('/reset-password/:token', pathname)) {
		return [
			'peakurl-ui',
			'public-page',
			'auth-page',
			'auth-page-recovery',
			'reset-password-page',
		];
	}

	return [];
}

function getDashboardBodyClassNames(pathname: string): string[] {
	if (!pathname.startsWith('/dashboard')) {
		return [];
	}

	const classes = ['peakurl-ui', 'dashboard-page'];

	if ('/dashboard' === pathname) {
		classes.push('dashboard-home-page');
		return classes;
	}

	if ('/dashboard/about' === pathname) {
		classes.push('dashboard-about-page');
		return classes;
	}

	if ('/dashboard/links' === pathname) {
		classes.push('dashboard-links-page');
		return classes;
	}

	if ('/dashboard/plugins' === pathname) {
		classes.push('dashboard-plugins-page');
		return classes;
	}

	if ('/dashboard/users' === pathname) {
		classes.push('dashboard-users-page');
		return classes;
	}

	const settingsMatch = matchPath('/dashboard/settings/:tab', pathname);

	if (settingsMatch) {
		const tab = sanitizeBodyClassName(
			settingsMatch.params.tab || 'general'
		);
		classes.push('dashboard-settings-page', `dashboard-settings-${tab}`);
		return classes;
	}

	const importMatch = matchPath('/dashboard/tools/import/:tab', pathname);

	if (importMatch) {
		const tab = sanitizeBodyClassName(importMatch.params.tab || 'file');
		classes.push(
			'dashboard-tools-page',
			'dashboard-import-page',
			`dashboard-import-${tab}`
		);
		return classes;
	}

	if ('/dashboard/tools/export' === pathname) {
		classes.push('dashboard-tools-page', 'dashboard-export-page');
		return classes;
	}

	if ('/dashboard/tools/system-status' === pathname) {
		classes.push('dashboard-tools-page', 'dashboard-system-status-page');
		return classes;
	}

	if ('/dashboard/tools' === pathname) {
		classes.push('dashboard-tools-page');
		return classes;
	}

	classes.push('dashboard-route-page');
	return classes;
}

function getPageSlug(classes: string[]): string {
	const pageClass = classes.find((className) => className.endsWith('-page'));
	return pageClass || 'default-page';
}

function getBodyClassContext(
	pathname: string,
	classes: string[]
): BodyClassContext {
	if (classes.includes('auth-page')) {
		return {
			pathname,
			pageType: 'auth',
			pageSlug: getPageSlug(classes),
		};
	}

	if (classes.includes('dashboard-page')) {
		return {
			pathname,
			pageType: 'dashboard',
			pageSlug: getPageSlug(classes),
		};
	}

	return {
		pathname,
		pageType: 'default',
		pageSlug: getPageSlug(classes),
	};
}

function readManagedBodyClasses(): string[] {
	if ('undefined' === typeof document || !document.body) {
		return [];
	}

	return (document.body.dataset[BODY_CLASS_DATA_ATTRIBUTE] || '')
		.split(' ')
		.filter(Boolean);
}

/**
 * Mirrors the role of WordPress `get_body_class()` for route-driven UI pages.
 *
 * The resulting class list is passed through a `body_class` filter so future
 * extensions can add or remove classes from one central place.
 */
export function getBodyClassNames(
	pathname: string,
	extraClasses: string[] = []
): string[] {
	const runtimeClasses = getRuntimeBodyClassNames();
	const routeClasses = [
		...getAuthBodyClassNames(pathname),
		...getDashboardBodyClassNames(pathname),
	];
	const defaultClasses =
		0 === routeClasses.length ? ['peakurl-ui', 'app-page'] : routeClasses;
	const mergedClasses = uniqueBodyClassNames([
		...defaultClasses,
		...runtimeClasses,
		...extraClasses,
	]);
	const context = getBodyClassContext(pathname, mergedClasses);

	return uniqueBodyClassNames(
		applyFilters('body_class', mergedClasses, extraClasses, context)
	);
}

/**
 * Synchronizes route-managed classes on the document body while leaving any
 * unrelated classes untouched.
 */
export function syncBodyClassNames(nextClasses: string[]): void {
	if ('undefined' === typeof document || !document.body) {
		return;
	}

	const previousClasses = readManagedBodyClasses();

	previousClasses.forEach((className) => {
		if (!nextClasses.includes(className)) {
			document.body.classList.remove(className);
		}
	});

	nextClasses.forEach((className) => {
		if (!previousClasses.includes(className)) {
			document.body.classList.add(className);
		}
	});

	if (0 === nextClasses.length) {
		delete document.body.dataset[BODY_CLASS_DATA_ATTRIBUTE];
		return;
	}

	document.body.dataset[BODY_CLASS_DATA_ATTRIBUTE] = nextClasses.join(' ');
}

/**
 * Removes the currently managed route body classes.
 */
export function clearBodyClassNames(): void {
	if ('undefined' === typeof document || !document.body) {
		return;
	}

	readManagedBodyClasses().forEach((className) => {
		document.body.classList.remove(className);
	});

	delete document.body.dataset[BODY_CLASS_DATA_ATTRIBUTE];
}
