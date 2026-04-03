// @ts-nocheck
import {
	createContext,
	useCallback,
	useContext,
	useMemo,
	useState,
} from 'react';
import { NotificationContainer } from '@/components/ui';

const NotificationContext = createContext(null);

function buildNotification(type, title, message) {
	return { type, title, message };
}

export function NotificationProvider({ children }) {
	const [notifications, setNotifications] = useState([]);

	const showNotification = useCallback((notification) => {
		setNotifications((previous) => {
			const isDuplicate = previous.some(
				(current) =>
					current.title === notification.title &&
					current.message === notification.message &&
					current.type === notification.type
			);

			if (isDuplicate) {
				return previous;
			}

			return [
				...previous,
				{ ...notification, id: Date.now() + Math.random() },
			];
		});
	}, []);

	const hideNotification = useCallback((id) => {
		setNotifications((previous) =>
			previous.filter((notification) => notification.id !== id)
		);
	}, []);

	const value = useMemo(
		() => ({
			notifications,
			showNotification,
			hideNotification,
			success: (title, message) =>
				showNotification(buildNotification('success', title, message)),
			error: (title, message) =>
				showNotification(buildNotification('error', title, message)),
			warning: (title, message) =>
				showNotification(buildNotification('warning', title, message)),
			info: (title, message) =>
				showNotification(buildNotification('info', title, message)),
		}),
		[notifications, showNotification, hideNotification]
	);

	return (
		<NotificationContext.Provider value={value}>
			{children}
			<NotificationContainer
				notifications={notifications}
				onRemoveNotification={hideNotification}
			/>
		</NotificationContext.Provider>
	);
}

export function useNotification() {
	const context = useContext(NotificationContext);

	if (!context) {
		throw new Error('useNotification must be used within NotificationProvider');
	}

	return context;
}

