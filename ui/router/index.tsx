import { useEffect } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';

import { useScrollToTop, useAdminAccess } from '@/hooks';
import {
	AboutPage,
	AppLayout,
	DashboardPage,
	ExportPage,
	ForgotPasswordPage,
	ImportLayout,
	ImportTabPage,
	LinksPage,
	LoginPage,
	NotFoundPage,
	PluginsPage,
	ResetPasswordPage,
	SettingsLayout,
	SettingsTabPage,
	SystemStatusPage,
	UsersPage,
} from '@/pages';
import {
	clearBodyClassNames,
	getBodyClassNames,
	syncBodyClassNames,
} from './bodyClasses';
import { getPageTitle } from './pageTitle';

function RouteEffects() {
	useScrollToTop();
	const location = useLocation();

	useEffect(() => {
		document.title = getPageTitle(location.pathname);
	}, [location.pathname]);

	useEffect(() => {
		syncBodyClassNames(getBodyClassNames(location.pathname));
	}, [location.pathname]);

	useEffect(() => clearBodyClassNames, []);

	return null;
}

function AppLayoutRoute() {
	return (
		<AppLayout>
			<Outlet />
		</AppLayout>
	);
}

function SettingsLayoutRoute() {
	return (
		<SettingsLayout>
			<Outlet />
		</SettingsLayout>
	);
}

function ImportLayoutRoute() {
	return (
		<ImportLayout>
			<ImportTabPage />
		</ImportLayout>
	);
}

function ToolsLayoutRoute() {
	return <Outlet />;
}

function ToolsIndexRoute() {
	const { canManageUsers, isLoading } = useAdminAccess();

	if (isLoading) {
		return null;
	}

	return <Navigate replace to={canManageUsers ? 'import/file' : 'export'} />;
}

function AdminOnlyRoute() {
	const { canManageUsers, isLoading } = useAdminAccess();

	if (isLoading) {
		return null;
	}

	if (!canManageUsers) {
		return <Navigate replace to="/dashboard/links" />;
	}

	return <Outlet />;
}

function AppRouter() {
	return (
		<>
			<RouteEffects />
			<Routes>
				<Route
					path="/"
					element={<Navigate replace to="/dashboard" />}
				/>
				<Route path="/login" element={<LoginPage />} />
				<Route
					path="/forgot-password"
					element={<ForgotPasswordPage />}
				/>
				<Route
					path="/reset-password/:token"
					element={<ResetPasswordPage />}
				/>
				<Route path="/dashboard" element={<AppLayoutRoute />}>
					<Route index element={<DashboardPage />} />
					<Route path="about" element={<AboutPage />} />
					<Route path="links" element={<LinksPage />} />
					<Route path="tools" element={<ToolsLayoutRoute />}>
						<Route index element={<ToolsIndexRoute />} />
						<Route path="export" element={<ExportPage />} />
						<Route element={<AdminOnlyRoute />}>
							<Route
								path="import"
								element={<Navigate replace to="file" />}
							/>
							<Route
								path="import/file"
								element={<ImportLayoutRoute />}
							/>
							<Route
								path="import/api"
								element={<ImportLayoutRoute />}
							/>
							<Route
								path="import/paste"
								element={<ImportLayoutRoute />}
							/>
							<Route
								path="system-status"
								element={<SystemStatusPage />}
							/>
						</Route>
						<Route path="*" element={<NotFoundPage />} />
					</Route>
					<Route element={<AdminOnlyRoute />}>
						<Route path="plugins" element={<PluginsPage />} />
						<Route path="users" element={<UsersPage />} />
					</Route>
					<Route
						path="team"
						element={<Navigate replace to="/dashboard/users" />}
					/>
					<Route path="settings" element={<SettingsLayoutRoute />}>
						<Route
							index
							element={<Navigate replace to="general" />}
						/>
						<Route path=":tab" element={<SettingsTabPage />} />
					</Route>
					<Route path="*" element={<NotFoundPage />} />
				</Route>
				<Route
					path="*"
					element={<Navigate replace to="/dashboard" />}
				/>
			</Routes>
		</>
	);
}

export default AppRouter;
