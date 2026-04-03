// @ts-nocheck
import { useEffect } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { useAdminAccess } from '@/hooks';

import { useScrollToTop } from '@/hooks';
import {
	AppLayout,
	NotFoundPage,
	AboutPage,
	DashboardPage,
	LinksPage,
	PluginsPage,
	UsersPage,
	SettingsLayout,
	SettingsTabPage,
	BulkImportLayout,
	BulkImportTabPage,
	SystemStatusPage,
	ForgotPasswordPage,
	LoginPage,
	ResetPasswordPage,
} from '@/pages';
import { getPageTitle } from './pageTitle';

function RouteEffects() {
	useScrollToTop();
	const location = useLocation();

	useEffect(() => {
		document.title = getPageTitle(location.pathname);
	}, [location.pathname]);

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

function BulkImportLayoutRoute() {
	return (
		<BulkImportLayout>
			<BulkImportTabPage />
		</BulkImportLayout>
	);
}

function ToolsLayoutRoute() {
	return <Outlet />;
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
					<Route element={<AdminOnlyRoute />}>
						<Route path="plugins" element={<PluginsPage />} />
						<Route path="users" element={<UsersPage />} />
						<Route path="tools" element={<ToolsLayoutRoute />}>
							<Route
								index
								element={<Navigate replace to="import/file" />}
							/>
							<Route
								path="import"
								element={<Navigate replace to="file" />}
							/>
							<Route
								path="import/file"
								element={<BulkImportLayoutRoute />}
							/>
							<Route
								path="import/api"
								element={<BulkImportLayoutRoute />}
							/>
							<Route
								path="import/paste"
								element={<BulkImportLayoutRoute />}
							/>
							<Route
								path="system-status"
								element={<SystemStatusPage />}
							/>
							<Route path="*" element={<NotFoundPage />} />
						</Route>
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
