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

const IMPORT_TAB_TITLES: Record<string, string> = {
	file: 'Import: File Upload',
	api: 'Import: API',
	paste: 'Import: Paste URLs',
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

	const importMatch = matchPath('/dashboard/tools/import/:tab', pathname);

	if (importMatch) {
		const tab = importMatch.params.tab ?? 'file';

		if (tab in IMPORT_TAB_TITLES) {
			return withSiteTitleSuffix(
				IMPORT_TAB_TITLES[tab] ?? 'Import',
			);
		}
	}

	if ('/dashboard/tools/import' === pathname) {
		return withSiteTitleSuffix('Import');
	}

	if ('/dashboard/tools' === pathname) {
		return withSiteTitleSuffix('Tools');
	}

	if (pathname.startsWith('/dashboard')) {
		return withSiteTitleSuffix('Page Not Found');
	}

	return getSiteTitle();
}
