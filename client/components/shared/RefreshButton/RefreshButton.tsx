import { useEffect, useRef, useState } from "react";
import { RefreshCw } from "lucide-react";

import { cn } from "@/shared/formatting";

export interface RefreshButtonProps {
	label: string;
	onRefresh?: () => void | Promise<unknown>;
	isRefreshing?: boolean;
	className?: string;
	iconClassName?: string;
	size?: "default" | "sm";
	iconSize?: number;
}

/** Keeps refresh feedback visible through a complete turn, including fast responses. */
export function RefreshButton({
	label,
	onRefresh,
	isRefreshing = false,
	className,
	iconClassName,
	size = "default",
	iconSize,
}: RefreshButtonProps) {
	const [isPending, setIsPending] = useState(false);
	const [isSpinning, setIsSpinning] = useState(isRefreshing);
	const [previousRefreshing, setPreviousRefreshing] = useState(isRefreshing);

	// Query-driven refreshes also start a turn; completion waits for its boundary.
	if (previousRefreshing !== isRefreshing) {
		setPreviousRefreshing(isRefreshing);
		if (isRefreshing) {
			setIsSpinning(true);
		}
	}
	const isBusy = isPending || isRefreshing;
	const isBusyRef = useRef(isBusy);

	useEffect(() => {
		isBusyRef.current = isBusy;
	}, [isBusy]);

	const isDisabled = isBusy || isSpinning || !onRefresh;

	useEffect(() => {
		if (isSpinning && !isBusy) {
			const timer = window.setTimeout(() => {
				setIsSpinning(false);
			}, 1200);
			return () => window.clearTimeout(timer);
		}
	}, [isSpinning, isBusy]);

	const handleRefresh = async () => {
		if (isDisabled || !onRefresh) return;
		setIsPending(true);
		setIsSpinning(true);
		try {
			await onRefresh();
		} catch {
			// Parent queries or handlers manage user feedback
		} finally {
			setIsPending(false);
		}
	};

	const baseButtonClass =
		size === "sm" ? "drawer-icon-button" : "page-header-icon-button";
	const resolvedIconSize = iconSize ?? (size === "sm" ? 16 : 20);

	return (
		<button
			type="button"
			className={cn(baseButtonClass, className)}
			onClick={handleRefresh}
			disabled={isDisabled}
			aria-label={label}
			aria-busy={isBusy || isSpinning}
			title={label}
		>
			<RefreshCw
				size={resolvedIconSize}
				aria-hidden="true"
				className={cn(
					isSpinning && "refresh-button-icon-spinning",
					iconClassName
				)}
				onAnimationIteration={() => {
					if (!isBusyRef.current) {
						setIsSpinning(false);
					}
				}}
				// Reduced motion uses one near-instant iteration instead of a rotating loop.
				onAnimationEnd={() => setIsSpinning(false)}
			/>
		</button>
	);
}
