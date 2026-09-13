import { createContext, useContext } from "react";
import type { NotificationContextValue } from "../types";

export const NotificationContext =
	createContext<NotificationContextValue | null>(null);

export function useNotification(): NotificationContextValue {
	const context = useContext(NotificationContext);

	if (!context) {
		throw new Error(
			"useNotification must be used within NotificationProvider"
		);
	}

	return context;
}
