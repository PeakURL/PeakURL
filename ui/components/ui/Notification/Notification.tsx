import { useState, useEffect, useCallback, useRef } from 'react';
import type { LucideIcon } from 'lucide-react';
import { X, CheckCircle, AlertCircle, Info, AlertTriangle } from 'lucide-react';
import { isDocumentRtl } from '@/i18n/direction';
import { cn } from '@/utils';
import type {
	NotificationContainerProps,
	NotificationProps,
	NotificationType,
} from '../types';
export type {
	NotificationContainerProps,
	NotificationItem,
	NotificationPayload,
	NotificationProps,
	NotificationType,
} from '../types';

// Notification types with solid colors
const notificationTypes: Record<
	NotificationType,
	{
		icon: LucideIcon;
		className: string;
	}
> = {
	success: {
		icon: CheckCircle,
		className: 'notification-card-success',
	},
	error: {
		icon: AlertCircle,
		className: 'notification-card-error',
	},
	warning: {
		icon: AlertTriangle,
		className: 'notification-card-warning',
	},
	info: {
		icon: Info,
		className: 'notification-card-info',
	},
};

/**
 * Notification Component
 * A single notification with different types, auto-close, and progress bar.
 * @param {Object} props
 * @param {string} props.id - Unique identifier for the notification
 * @param {('success'|'error'|'warning'|'info')} [props.type='info'] - Notification type
 * @param {string} props.title - Notification title
 * @param {string} props.message - Notification message
 * @param {number} [props.duration=5000] - Duration in ms to show the notification
 * @param {Function} props.onClose - Callback when notification is closed
 * @param {string} [props.className=''] - Additional class names
 */
export function Notification({
	id,
	type = 'info',
	title,
	message,
	duration = 5000,
	onClose,
	className = '',
}: NotificationProps) {
	const [isPaused, setIsPaused] = useState(false);
	const [isExiting, setIsExiting] = useState(false);
	const isRtl = isDocumentRtl();

	// Timer refs
	const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
	const startTimeRef = useRef(0);
	const remainingTimeRef = useRef(duration);

	const config = notificationTypes[type];
	const IconComponent = config.icon;

	const handleClose = useCallback(() => {
		setIsExiting(true);
		// Small delay for exit animation
		setTimeout(() => {
			onClose?.(id);
		}, 300);
	}, [id, onClose]);

	const startTimer = useCallback(() => {
		// Clear any existing timer
		if (timerRef.current) {
			clearTimeout(timerRef.current);
		}

		timerRef.current = setTimeout(() => {
			handleClose();
		}, remainingTimeRef.current);

		startTimeRef.current = Date.now();
	}, [handleClose]);

	const pauseTimer = useCallback(() => {
		if (timerRef.current) {
			clearTimeout(timerRef.current);
			timerRef.current = null;
		}

		const elapsed = Date.now() - startTimeRef.current;
		remainingTimeRef.current = Math.max(
			0,
			remainingTimeRef.current - elapsed
		);
	}, []);

	useEffect(() => {
		remainingTimeRef.current = duration;
	}, [duration]);

	useEffect(() => {
		if (duration > 0 && !isPaused) {
			startTimer();
		}

		return () => {
			if (timerRef.current) {
				clearTimeout(timerRef.current);
			}
		};
	}, [duration, isPaused, startTimer]);

	const handleMouseEnter = () => {
		setIsPaused(true);
		pauseTimer();
	};

	const handleMouseLeave = () => {
		setIsPaused(false);
		// Effect will restart timer
	};

	if (!config) return null;

	return (
		<div
			className={cn(
				'notification-card',
				config.className,
				isExiting
					? isRtl
						? 'notification-card-exiting-rtl'
						: 'notification-card-exiting-ltr'
					: isRtl
						? 'notification-card-entering-rtl'
						: 'notification-card-entering-ltr',
				className
			)}
			onMouseEnter={handleMouseEnter}
			onMouseLeave={handleMouseLeave}
		>
			<div className="notification-content">
				<IconComponent className="notification-icon" />

				<div className="notification-body">
					{title && (
						<h4 className="notification-title">
							{title}
						</h4>
					)}
					{message && (
						<p className="notification-message">
							{message}
						</p>
					)}
				</div>

				<button
					type="button"
					onClick={handleClose}
					className="notification-close"
				>
					<X className="notification-close-icon" />
				</button>
			</div>

			{duration > 0 && (
				<div className="notification-progress">
					<div
						className={cn(
							'notification-progress-bar',
							isRtl
								? 'notification-progress-rtl'
								: 'notification-progress-ltr'
						)}
						style={{
							width: '100%',
							animation: `progress ${duration}ms linear forwards`,
							animationPlayState: isPaused ? 'paused' : 'running',
						}}
					/>
				</div>
			)}
		</div>
	);
}

/**
 * NotificationContainer Component
 * Renders a list of notifications in a fixed position on the screen.
 * @param {Object} props
 * @param {Array} props.notifications - Array of notification objects to display
 * @param {Function} props.onRemoveNotification - Callback to remove a notification by its ID
 */
export function NotificationContainer({
	notifications = [],
	onRemoveNotification,
}: NotificationContainerProps) {
	return (
		<div className="notification-container">
			{/* pointer-events-none on container to let clicks pass through, but pointer-events-auto on notifications */}
			{notifications.map((notification) => (
				<div key={notification.id} className="notification-item">
					<Notification
						{...notification}
						onClose={onRemoveNotification}
					/>
				</div>
			))}
		</div>
	);
}
