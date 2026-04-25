import type { PropsWithChildren } from 'react';
import type { NotificationItem, NotificationPayload } from '@/components/ui';

/**
 * Theme tokens supported by the dashboard shell.
 */
export type Theme = 'light' | 'dark';

/**
 * Theme context payload exposed by the theme provider.
 */
export interface ThemeContextValue {
	/** Currently active theme token. */
	theme: Theme;

	/** Toggles between the supported theme values. */
	toggleTheme: () => void;
}

/**
 * Props for the shared theme provider.
 */
export type ThemeProviderProps = PropsWithChildren;

/**
 * Props for the auth initializer wrapper.
 */
export type AuthInitializerProps = PropsWithChildren;

/**
 * Props for the top-level client provider composition.
 */
export type ClientProvidersProps = PropsWithChildren;

/**
 * Props for the notification provider wrapper.
 */
export type NotificationProviderProps = PropsWithChildren;

/**
 * Notification context payload exposed by the notification provider.
 */
export interface NotificationContextValue {
	/** Active notification items shown in the stack. */
	notifications: NotificationItem[];

	/** Enqueues a new notification item. */
	showNotification: (notification: NotificationPayload) => void;

	/** Removes a notification by id. */
	hideNotification: (id: number) => void;

	/** Convenience helper for success notifications. */
	success: (title: string, message?: string) => void;

	/** Convenience helper for error notifications. */
	error: (title: string, message?: string) => void;

	/** Convenience helper for warning notifications. */
	warning: (title: string, message?: string) => void;

	/** Convenience helper for info notifications. */
	info: (title: string, message?: string) => void;
}
