import { useRef } from "react";
import { createPortal } from "react-dom";
import { useGSAP } from "@gsap/react";
import { gsap } from "gsap";
import { AlertCircle, AlertTriangle, CheckCircle, Info, X } from "lucide-react";

import { __ } from "@/i18n";
import { cn } from "@/shared/formatting";

import type {
	NotificationContainerProps,
	NotificationProps,
	NotificationType,
} from "../types";

export type {
	NotificationContainerProps,
	NotificationItem,
	NotificationPayload,
	NotificationProps,
	NotificationType,
} from "../types";

gsap.registerPlugin(useGSAP);

const notificationIcons = {
	success: CheckCircle,
	error: AlertCircle,
	warning: AlertTriangle,
	info: Info,
} satisfies Record<NotificationType, typeof Info>;

const DEFAULT_NOTIFICATION_DURATION = 4500;
const REDUCED_MOTION = "(prefers-reduced-motion: reduce)";

/** A dismissible notification that pauses while the user interacts with it. */
export function Notification({
	id,
	type = "info",
	title,
	message,
	duration = DEFAULT_NOTIFICATION_DURATION,
	onClose,
	className = "",
}: NotificationProps) {
	const cardRef = useRef<HTMLDivElement>(null);
	const progressRef = useRef<HTMLDivElement>(null);
	const expiryRef = useRef<gsap.core.Animation | null>(null);
	const dismissRef = useRef<() => void>(undefined);
	const hovering = useRef(false);
	const focused = useRef(false);
	const Icon = notificationIcons[type];

	useGSAP(
		(_context, contextSafe) => {
			const card = cardRef.current;
			if (!card || !contextSafe) return;
			const reducedMotion = window.matchMedia(REDUCED_MOTION);
			let entrance: gsap.core.Tween | undefined;
			let exit: gsap.core.Tween | undefined;
			let dismissing = false;
			const remove = () => onClose?.(id);
			const dismiss = contextSafe(() => {
				if (dismissing) return;
				dismissing = true;
				expiryRef.current?.kill();
				entrance?.kill();
				card.inert = true;
				if (reducedMotion.matches) {
					remove();
					return;
				}
				exit = gsap.to(card, {
					y: -6,
					opacity: 0,
					duration: 0.18,
					ease: "power2.in",
					onComplete: remove,
				});
			});

			dismissRef.current = dismiss;
			if (!reducedMotion.matches) {
				entrance = gsap.fromTo(
					card,
					{ y: -10, opacity: 0 },
					{
						y: 0,
						opacity: 1,
						duration: 0.26,
						ease: "power2.out",
						clearProps: "transform,opacity",
					}
				);
			}

			if (duration > 0) {
				const seconds = duration / 1000;
				const progress = progressRef.current;
				expiryRef.current =
					progress && !reducedMotion.matches
						? gsap.fromTo(
								progress,
								{ scaleX: 1 },
								{
									scaleX: 0,
									duration: seconds,
									ease: "none",
									onComplete: dismiss,
								}
							)
						: gsap.delayedCall(seconds, dismiss);
				expiryRef.current.paused(hovering.current || focused.current);
			}

			const settleMotion = () => {
				if (!reducedMotion.matches) return;
				if (exit) exit.progress(1);
				else entrance?.progress(1);
			};
			reducedMotion.addEventListener("change", settleMotion);
			return () => {
				reducedMotion.removeEventListener("change", settleMotion);
				card.inert = false;
				expiryRef.current = null;
				dismissRef.current = undefined;
			};
		},
		{
			scope: cardRef,
			dependencies: [duration, id, onClose],
			revertOnUpdate: true,
		}
	);

	const updateExpiry = () => {
		expiryRef.current?.paused(hovering.current || focused.current);
	};

	return (
		<div
			ref={cardRef}
			className={cn(
				"notification-card",
				`notification-card-${type}`,
				className
			)}
			onPointerEnter={(event) => {
				if (event.pointerType === "touch") return;
				hovering.current = true;
				updateExpiry();
			}}
			onPointerLeave={() => {
				hovering.current = false;
				updateExpiry();
			}}
			onFocus={() => {
				focused.current = true;
				updateExpiry();
			}}
			onBlur={(event) => {
				if (event.currentTarget.contains(event.relatedTarget)) return;
				focused.current = false;
				updateExpiry();
			}}
		>
			<div className="notification-content">
				<div className="notification-icon" aria-hidden="true">
					<Icon size={18} />
				</div>
				<div className="notification-body">
					{title && <p className="notification-title">{title}</p>}
					{message && (
						<p className="notification-message">{message}</p>
					)}
				</div>
				<button
					type="button"
					onClick={() => dismissRef.current?.()}
					className="notification-close"
					aria-label={__("Dismiss notification")}
				>
					<X size={16} aria-hidden="true" />
				</button>
			</div>
			{duration > 0 && (
				<div className="notification-progress" aria-hidden="true">
					<div
						ref={progressRef}
						className="notification-progress-bar"
					/>
				</div>
			)}
		</div>
	);
}

/** Portals the toast stack and announces new notifications without moving focus. */
export function NotificationContainer({
	notifications = [],
	onRemoveNotification,
}: NotificationContainerProps) {
	if (typeof document === "undefined") return null;

	const announce = (urgent: boolean) =>
		notifications
			.filter(({ type }) => (type === "error") === urgent)
			.map(({ id, title, message }) => (
				<div key={id}>
					{title}
					{message && ` ${message}`}
				</div>
			));

	return createPortal(
		<>
			<div
				className="notification-announcement sr-only"
				aria-live="polite"
				aria-atomic="false"
				aria-relevant="additions"
			>
				{announce(false)}
			</div>
			<div
				className="notification-announcement sr-only"
				aria-live="assertive"
				aria-atomic="false"
				aria-relevant="additions"
			>
				{announce(true)}
			</div>
			{notifications.length > 0 && (
				<div className="notification-container">
					{notifications.map((notification) => (
						<Notification
							key={notification.id}
							{...notification}
							onClose={onRemoveNotification}
						/>
					))}
				</div>
			)}
		</>,
		document.body
	);
}
