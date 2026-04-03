import { __ } from '@/i18n';

function normalizeTerm(value = '') {
	return String(value).trim().toLowerCase().replace(/\s+/g, ' ');
}

function createRouteTarget({
	id,
	href,
	label,
	description,
	terms,
	section = 'pages',
	isAllowed = true,
}) {
	return {
		id,
		href,
		isAllowed,
		label,
		description,
		section,
		terms: terms.map(normalizeTerm).filter(Boolean),
	};
}

function getRouteTargets(capabilities = {}) {
	return [
		createRouteTarget({
			id: 'overview',
			href: '/dashboard',
			label: __('Overview'),
			description: __('Dashboard'),
			section: 'pages',
			terms: [
				__('Dashboard'),
				__('Overview'),
				'dashboard',
				'overview',
				'home',
				'analytics',
			],
		}),
		createRouteTarget({
			id: 'links',
			href: '/dashboard/links',
			label: __('All Links'),
			description: __('Links'),
			section: 'pages',
			terms: [
				__('Links'),
				__('All Links'),
				'links',
				'link',
				'url',
				'urls',
				'short url',
				'short urls',
				'short link',
				'short links',
			],
		}),
		createRouteTarget({
			id: 'about',
			href: '/dashboard/about',
			label: __('About PeakURL'),
			description: __('About'),
			section: 'pages',
			terms: [
				__('About'),
				__('About PeakURL'),
				'about',
				'version',
				'peakurl',
			],
		}),
		createRouteTarget({
			id: 'settings-general',
			href: '/dashboard/settings/general',
			label: __('General Settings'),
			description: __('Settings'),
			section: 'pages',
			terms: [
				__('Settings'),
				__('General'),
				__('General Settings'),
				__('Profile'),
				'settings',
				'general',
				'profile',
				'account',
				'language',
				'site language',
				'site settings',
			],
		}),
		createRouteTarget({
			id: 'settings-security',
			href: '/dashboard/settings/security',
			label: __('Security Settings'),
			description: __('Settings'),
			section: 'pages',
			terms: [
				__('Security'),
				__('Security Settings'),
				'security',
				'password',
				'password reset',
				'sessions',
				'two factor',
				'2fa',
				'backup codes',
			],
		}),
		createRouteTarget(
			{
			id: 'settings-api',
			href: '/dashboard/settings/api',
			label: __('API Keys'),
			description: __('Settings'),
			section: 'pages',
			terms: [
				__('API Keys'),
				'api',
				'api key',
				'api keys',
				'token',
				'tokens',
			],
			isAllowed: Boolean(capabilities.canManageApiKeys),
			}
		),
		createRouteTarget(
			{
			id: 'settings-integrations',
			href: '/dashboard/settings/integrations',
			label: __('Integrations'),
			description: __('Settings'),
			section: 'pages',
			terms: [
				__('Integrations'),
				'integrations',
				'webhook',
				'webhooks',
			],
			isAllowed: Boolean(capabilities.canManageWebhooks),
			}
		),
		createRouteTarget(
			{
			id: 'settings-email',
			href: '/dashboard/settings/email',
			label: __('Email SMTP'),
			description: __('Settings'),
			section: 'pages',
			terms: [
				__('Email Configuration'),
				__('Email SMTP'),
				'email',
				'mail',
				'smtp',
				'mailer',
			],
			isAllowed: Boolean(capabilities.canManageMailDelivery),
			}
		),
		createRouteTarget(
			{
			id: 'settings-location',
			href: '/dashboard/settings/location',
			label: __('Location Data'),
			description: __('Settings'),
			section: 'pages',
			terms: [
				__('Location Data'),
				'location',
				'location data',
				'geoip',
				'maxmind',
				'geolite',
			],
			isAllowed: Boolean(capabilities.canManageLocationData),
			}
		),
		createRouteTarget(
			{
			id: 'settings-updates',
			href: '/dashboard/settings/updates',
			label: __('Updates'),
			description: __('Settings'),
			section: 'pages',
			terms: [
				__('Updates'),
				'updates',
				'update',
				'updater',
				'release',
				'upgrade',
			],
			isAllowed: Boolean(capabilities.canManageUpdates),
			}
		),
		createRouteTarget(
			{
			id: 'users',
			href: '/dashboard/users',
			label: __('Users'),
			description: __('Management'),
			section: 'pages',
			terms: [
				__('Users'),
				'user',
				'users',
				'team',
				'members',
				'admins',
				'editors',
			],
			isAllowed: Boolean(capabilities.canManageUsers),
			}
		),
		createRouteTarget(
			{
			id: 'plugins',
			href: '/dashboard/plugins',
			label: __('Plugins'),
			description: __('Management'),
			section: 'pages',
			terms: [
				__('Plugins'),
				'plugin',
				'plugins',
				'extensions',
			],
			isAllowed: Boolean(capabilities.canManageUsers),
			}
		),
		createRouteTarget(
			{
			id: 'tools-import',
			href: '/dashboard/tools/import/file',
			label: __('Import'),
			description: __('Tools'),
			section: 'tools',
			terms: [
				__('Tools'),
				__('Import'),
				__('Import: File Upload'),
				'tools',
				'import',
				'bulk import',
				'csv',
				'upload',
				'paste import',
				'api import',
			],
			isAllowed: Boolean(capabilities.canManageUsers),
			}
		),
		createRouteTarget(
			{
			id: 'tools-export',
			href: '/dashboard/tools/export',
			label: __('Export'),
			description: __('Tools'),
			section: 'tools',
			terms: [
				__('Tools'),
				__('Export'),
				'tools',
				'export',
				'download',
				'backup',
				'csv export',
				'json export',
				'xml export',
			],
			isAllowed: Boolean(capabilities.canExportLinks),
			}
		),
		createRouteTarget(
			{
			id: 'tools-system-status',
			href: '/dashboard/tools/system-status',
			label: __('System Status'),
			description: __('Tools'),
			section: 'tools',
			terms: [
				__('Tools'),
				__('System Status'),
				'system status',
				'site health',
				'health',
				'server',
				'database',
			],
			isAllowed: Boolean(capabilities.canManageUsers),
			}
		),
	].filter((target) => target.isAllowed);
}

function getTargetScore(query, target) {
	let bestScore = 0;

	target.terms.forEach((term) => {
		if (!term) {
			return;
		}

		if (query === term) {
			bestScore = Math.max(bestScore, 100);
			return;
		}

		if (term.startsWith(query)) {
			bestScore = Math.max(bestScore, 80);
		}

		if (term.includes(query)) {
			bestScore = Math.max(bestScore, 70);
		}

		if (query.includes(term)) {
			bestScore = Math.max(bestScore, 60);
		}

		const queryParts = query.split(' ');
		if (
			queryParts.length > 1 &&
			queryParts.every((part) => term.includes(part))
		) {
			bestScore = Math.max(bestScore, 65);
		}
	});

	return bestScore;
}

export function buildLinksSearchPath(query = '') {
	const value = String(query).trim();

	if (!value) {
		return '/dashboard/links';
	}

	const params = new URLSearchParams();
	params.set('search', value);

	return `/dashboard/links?${params.toString()}`;
}

export function buildLinkStatsPath(shortCode = '') {
	const value = String(shortCode).trim();

	if (!value) {
		return '/dashboard/links';
	}

	const params = new URLSearchParams();
	params.set('stats', value);

	return `/dashboard/links?${params.toString()}`;
}

export function getDashboardSearchValueFromLocation(location) {
	const pathname = location?.pathname?.replace(/\/+$/, '') || '/';

	if ('/dashboard/links' !== pathname) {
		return '';
	}

	const params = new URLSearchParams(location?.search || '');
	return params.get('search') || '';
}

export function findDashboardRouteMatches(
	query,
	capabilities = {},
	limit = 5
) {
	const normalizedQuery = normalizeTerm(query);

	if (!normalizedQuery) {
		return [];
	}

	return getRouteTargets(capabilities)
		.map((target) => ({
			...target,
			score: getTargetScore(normalizedQuery, target),
		}))
		.filter((target) => target.score > 0)
		.sort((left, right) => {
			if (right.score !== left.score) {
				return right.score - left.score;
			}

			return left.label.localeCompare(right.label);
		})
		.slice(0, limit)
		.map(({ score, ...target }) => target);
}

export function findDashboardUserMatches(query, users = [], limit = 5) {
	const normalizedQuery = normalizeTerm(query);

	if (!normalizedQuery) {
		return [];
	}

	return users
		.map((user) => {
			const fullName = [user.firstName, user.lastName]
				.filter(Boolean)
				.join(' ')
				.trim();
			const terms = [
				user.username,
				user.email,
				user.role,
				fullName,
				user.firstName,
				user.lastName,
			]
				.map(normalizeTerm)
				.filter(Boolean);
			const score = terms.reduce(
				(bestScore, term) =>
					Math.max(
						bestScore,
						term === normalizedQuery
							? 100
							: term.startsWith(normalizedQuery)
								? 80
								: term.includes(normalizedQuery)
									? 70
									: normalizedQuery.includes(term)
										? 60
										: 0
					),
				0
			);

			return {
				id: user.id || user.username || user.email,
				title: fullName || user.username || user.email || __('User'),
				description: user.username || user.email || '',
				meta: user.role || '',
				href: '/dashboard/users',
				score,
			};
		})
		.filter((user) => user.score > 0)
		.sort((left, right) => {
			if (right.score !== left.score) {
				return right.score - left.score;
			}

			return left.title.localeCompare(right.title);
		})
		.slice(0, limit)
		.map(({ score, ...user }) => user);
}

export function resolveDashboardSearchPath(query, capabilities = {}) {
	if (!normalizeTerm(query)) {
		return '/dashboard/links';
	}

	const [bestTarget] = findDashboardRouteMatches(query, capabilities, 1);

	if (bestTarget) {
		const bestScore = getTargetScore(normalizeTerm(query), bestTarget);

		if (bestScore >= 70) {
			return bestTarget.href;
		}
	}

	return buildLinksSearchPath(query);
}
