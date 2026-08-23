import { useCallback, useMemo, useState } from "react";

import type { NotificationItem, NotificationPayload } from "@/components/ui";
import { NotificationContainer } from "@/components/ui";

import type { NotificationProviderProps } from "../types";
import { NotificationContext } from "./context";

function createNotification(
	type: NotificationPayload["type"],
	title: string,
	message?: string
): NotificationPayload {
	return { type, title, message };
}

export function NotificationProvider({ children }: NotificationProviderProps) {
	const [notifications, setNotifications] = useState<NotificationItem[]>([]);

	const showNotification = useCallback(
		(notification: NotificationPayload) => {
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
		},
		[]
	);

	const hideNotification = useCallback((id: number) => {
		setNotifications((previous) =>
			previous.filter((notification) => notification.id !== id)
		);
	}, []);

	const value = useMemo(
		() => ({
			notifications,
			showNotification,
			hideNotification,
			success: (title: string, message?: string) =>
				showNotification(createNotification("success", title, message)),
			error: (title: string, message?: string) =>
				showNotification(createNotification("error", title, message)),
			warning: (title: string, message?: string) =>
				showNotification(createNotification("warning", title, message)),
			info: (title: string, message?: string) =>
				showNotification(createNotification("info", title, message)),
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
