import { matchPath } from 'react-router-dom';
import { PEAKURL_SITE_NAME } from '@/constants';

const DEFAULT_SITE_TITLE = 'PeakURL';

const SETTINGS_TAB_TITLES: Record<string, string> = {
	general: 'General Settings',
	security: 'Security Settings',
	api: 'API Keys',
	integrations: 'Integrations',
	email: 'Email Configuration',
	location: 'Location Data',
	updates: 'Updates',
};

const API_DOCS_TAB_TITLES: Record<string, string> = {
	authentication: 'API Docs: Authentication',
	links: 'API Docs: Links',
	analytics: 'API Docs: Analytics',
	'qr-codes': 'API Docs: QR Codes',
	webhooks: 'API Docs: Webhooks',
};

const BULK_IMPORT_TAB_TITLES: Record<string, string> = {
	file: 'Bulk Import: File Upload',
	api: 'Bulk Import: API Import',
	paste: 'Bulk Import: Paste URLs',
};

function getSiteTitle(): string {
	const siteTitle = PEAKURL_SITE_NAME.trim();
	return '' !== siteTitle ? siteTitle : DEFAULT_SITE_TITLE;
}

function withSiteTitleSuffix(value: string): string {
	return `${value} • ${getSiteTitle()}`;
}

export function getPageTitle(pathname: string): string {
	if ('/login' === pathname) {
		return withSiteTitleSuffix('Login');
	}

	if ('/forgot-password' === pathname) {
		return withSiteTitleSuffix('Forgot Password');
	}

	if (pathname.startsWith('/reset-password/')) {
		return withSiteTitleSuffix('Reset Password');
	}

	if ('/' === pathname || '/dashboard' === pathname) {
		return withSiteTitleSuffix('Dashboard');
	}

	if ('/dashboard/about' === pathname) {
		return withSiteTitleSuffix('About');
	}

	if ('/dashboard/links' === pathname) {
		return withSiteTitleSuffix('Links');
	}

	if ('/dashboard/plugins' === pathname) {
		return withSiteTitleSuffix('Plugins');
	}

	if ('/dashboard/users' === pathname) {
		return withSiteTitleSuffix('Users');
	}

	const settingsMatch = matchPath('/dashboard/settings/:tab', pathname);

	if (settingsMatch) {
		const tab = settingsMatch.params.tab ?? 'general';
		return withSiteTitleSuffix(
			SETTINGS_TAB_TITLES[tab] ?? 'Settings',
		);
	}

	if ('/dashboard/settings' === pathname) {
		return withSiteTitleSuffix('Settings');
	}

	const apiDocsMatch = matchPath('/dashboard/api-docs/:tab', pathname);

	if (apiDocsMatch) {
		const tab = apiDocsMatch.params.tab ?? 'authentication';
		return withSiteTitleSuffix(
			API_DOCS_TAB_TITLES[tab] ?? 'API Docs',
		);
	}

	if ('/dashboard/api-docs' === pathname) {
		return withSiteTitleSuffix('API Docs');
	}

	const bulkImportMatch = matchPath('/dashboard/bulk-import/:tab', pathname);

	if (bulkImportMatch) {
		const tab = bulkImportMatch.params.tab ?? 'file';
		return withSiteTitleSuffix(
			BULK_IMPORT_TAB_TITLES[tab] ?? 'Bulk Import',
		);
	}

	if ('/dashboard/bulk-import' === pathname) {
		return withSiteTitleSuffix('Bulk Import');
	}

	if (pathname.startsWith('/dashboard')) {
		return withSiteTitleSuffix('Page Not Found');
	}

	return getSiteTitle();
}
