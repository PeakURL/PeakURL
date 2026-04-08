import { useState, useEffect, useCallback, useRef } from 'react';
import type { LucideIcon } from 'lucide-react';
import { X, CheckCircle, AlertCircle, Info, AlertTriangle } from 'lucide-react';
import { isDocumentRtl } from '@/i18n/direction';
import type {
	NotificationContainerProps,
	NotificationProps,
	NotificationType,
} from './types';
export type {
	NotificationContainerProps,
	NotificationItem,
	NotificationPayload,
	NotificationProps,
	NotificationType,
} from './types';

// Notification types with solid colors
const notificationTypes: Record<
	NotificationType,
	{
		icon: LucideIcon;
		bgColor: string;
		textColor: string;
		borderColor: string;
	}
> = {
	success: {
		icon: CheckCircle,
		bgColor: 'bg-emerald-600',
		textColor: 'text-white',
		borderColor: 'border-emerald-500',
	},
	error: {
		icon: AlertCircle,
		bgColor: 'bg-red-600',
		textColor: 'text-white',
		borderColor: 'border-red-500',
	},
	warning: {
		icon: AlertTriangle,
		bgColor: 'bg-amber-500',
		textColor: 'text-white',
		borderColor: 'border-amber-400',
	},
	info: {
		icon: Info,
		bgColor: 'bg-blue-600',
		textColor: 'text-white',
		borderColor: 'border-blue-500',
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
			className={`
                relative overflow-hidden rounded-2xl shadow-xl border
                ${config.bgColor} ${config.textColor} ${config.borderColor}
				transform transition-all duration-500 ease-out
                ${
					isExiting
						? `${
								isRtl
									? '-translate-x-full'
									: 'translate-x-full'
							} opacity-0 scale-95`
						: `translate-x-0 opacity-100 scale-100 ${
								isRtl
									? 'animate-slide-in-right'
									: 'animate-slide-in-left'
							}`
				}
                ${className}
            `}
			onMouseEnter={handleMouseEnter}
			onMouseLeave={handleMouseLeave}
		>
			<div className="relative p-4 flex items-start gap-3">
				<IconComponent className="w-6 h-6 mt-0.5 shrink-0" />

				<div className="flex-1 min-w-0">
					{title && (
						<h4 className="font-semibold text-sm mb-1 truncate">
							{title}
						</h4>
					)}
					{message && (
						<p className="text-sm opacity-90 leading-relaxed">
							{message}
						</p>
					)}
				</div>

				<button
					type="button"
					onClick={handleClose}
					className="shrink-0 p-1 rounded-lg hover:bg-white/20 transition-colors duration-200"
				>
					<X className="w-4 h-4" />
				</button>
			</div>

			{duration > 0 && (
				<div className="absolute inset-x-0 bottom-0 h-1 bg-black/10">
					<div
						className={`h-full bg-white/40 ${
							isRtl ? 'origin-right' : 'origin-left'
						}`}
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
		<div
			className="logical-inset-inline-end-4 fixed top-4 z-50 w-full max-w-sm space-y-3 pointer-events-none"
		>
			{/* pointer-events-none on container to let clicks pass through, but pointer-events-auto on notifications */}
			{notifications.map((notification) => (
				<div key={notification.id} className="pointer-events-auto">
					<Notification
						{...notification}
						onClose={onRemoveNotification}
					/>
				</div>
			))}
		</div>
	);
}
