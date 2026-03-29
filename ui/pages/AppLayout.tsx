// @ts-nocheck
import { ProtectedRoute } from '@/components';
import { DashboardLayout } from './layout';

const AppLayout = ({ children }) => {
	return (
		<ProtectedRoute>
			<DashboardLayout>{children}</DashboardLayout>
		</ProtectedRoute>
	);
};

export default AppLayout;
