import { matchPath } from 'react-router-dom';
import { PEAKURL_SITE_NAME } from '@/constants';
import { __ } from '@/i18n';

const DEFAULT_SITE_TITLE = 'PeakURL';

function getSiteTitle(): string {
	const siteTitle = PEAKURL_SITE_NAME.trim();
	return '' !== siteTitle ? siteTitle : DEFAULT_SITE_TITLE;
}

function withSiteTitleSuffix(value: string): string {
	return `${value} • ${getSiteTitle()}`;
}

function getSettingsTabTitle(tab: string): string {
	switch (tab) {
		case 'general':
			return __('General Settings');
		case 'security':
			return __('Security Settings');
		case 'api':
			return __('API Keys');
		case 'integrations':
			return __('Integrations');
		case 'email':
			return __('Email Configuration');
		case 'location':
			return __('Location Data');
		case 'updates':
			return __('Updates');
		default:
			return __('Settings');
	}
}

function getImportTabTitle(tab: string): string {
	switch (tab) {
		case 'file':
			return __('Import: File Upload');
		case 'api':
			return __('Import: API');
		case 'paste':
			return __('Import: Paste URLs');
		default:
			return __('Import');
	}
}

export function getPageTitle(pathname: string): string {
	if ('/login' === pathname) {
		return withSiteTitleSuffix(__('Login'));
	}

	if ('/forgot-password' === pathname) {
		return withSiteTitleSuffix(__('Forgot Password'));
	}

	if (pathname.startsWith('/reset-password/')) {
		return withSiteTitleSuffix(__('Reset Password'));
	}

	if ('/' === pathname || '/dashboard' === pathname) {
		return withSiteTitleSuffix(__('Dashboard'));
	}

	if ('/dashboard/about' === pathname) {
		return withSiteTitleSuffix(__('About'));
	}

	if ('/dashboard/links' === pathname) {
		return withSiteTitleSuffix(__('Links'));
	}

	if ('/dashboard/plugins' === pathname) {
		return withSiteTitleSuffix(__('Plugins'));
	}

	if ('/dashboard/users' === pathname) {
		return withSiteTitleSuffix(__('Users'));
	}

	const settingsMatch = matchPath('/dashboard/settings/:tab', pathname);

	if (settingsMatch) {
		const tab = settingsMatch.params.tab ?? 'general';
		return withSiteTitleSuffix(getSettingsTabTitle(tab));
	}

	if ('/dashboard/settings' === pathname) {
		return withSiteTitleSuffix(getSettingsTabTitle('general'));
	}

	const importMatch = matchPath('/dashboard/tools/import/:tab', pathname);

	if (importMatch) {
		const tab = importMatch.params.tab ?? 'file';
		return withSiteTitleSuffix(getImportTabTitle(tab));
	}

	if ('/dashboard/tools/import' === pathname) {
		return withSiteTitleSuffix(__('Import'));
	}

	if ('/dashboard/tools/export' === pathname) {
		return withSiteTitleSuffix(__('Export'));
	}

	if ('/dashboard/tools/system-status' === pathname) {
		return withSiteTitleSuffix(__('System Status'));
	}

	if ('/dashboard/tools' === pathname) {
		return withSiteTitleSuffix(__('Tools'));
	}

	if (pathname.startsWith('/dashboard')) {
		return withSiteTitleSuffix(__('Page Not Found'));
	}

	return getSiteTitle();
}
