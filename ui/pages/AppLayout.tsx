import { ProtectedRoute } from '@/components';
import { DashboardLayout } from './layout';
import type { AppLayoutProps } from './types';

const AppLayout = ({ children }: AppLayoutProps) => {
	return (
		<ProtectedRoute>
			<DashboardLayout>{children}</DashboardLayout>
		</ProtectedRoute>
	);
};

export default AppLayout;
