import { useState } from "react";
import { RefreshCw } from "lucide-react";

import { cn } from "@/shared/formatting";

interface RefreshButtonProps {
	label: string;
	onRefresh?: () => void | Promise<unknown>;
	isRefreshing?: boolean;
}

/** Keeps refresh feedback visible through a complete turn, including fast responses. */
export function RefreshButton({
	label,
	onRefresh,
	isRefreshing = false,
}: RefreshButtonProps) {
	const [isPending, setIsPending] = useState(false);
	const [isSpinning, setIsSpinning] = useState(isRefreshing);
	const [previousRefreshing, setPreviousRefreshing] = useState(isRefreshing);

	// Query-driven refreshes also start a turn; completion waits for its boundary.
	if (previousRefreshing !== isRefreshing) {
		setPreviousRefreshing(isRefreshing);
		if (isRefreshing) setIsSpinning(true);
	}
	const isBusy = isPending || isRefreshing;
	const isDisabled = isBusy || isSpinning || !onRefresh;

	const handleRefresh = async () => {
		if (isDisabled || !onRefresh) return;
		setIsPending(true);
		setIsSpinning(true);
		try {
			await onRefresh();
		} finally {
			setIsPending(false);
		}
	};

	return (
		<button
			type="button"
			className="page-header-icon-button"
			onClick={handleRefresh}
			disabled={isDisabled}
			aria-label={label}
			aria-busy={isBusy || isSpinning}
			title={label}
		>
			<RefreshCw
				aria-hidden="true"
				className={cn(isSpinning && "refresh-button-icon-spinning")}
				onAnimationIteration={() => {
					if (!isBusy) setIsSpinning(false);
				}}
				// Reduced motion uses one near-instant iteration instead of a rotating loop.
				onAnimationEnd={() => setIsSpinning(false)}
			/>
		</button>
	);
}
