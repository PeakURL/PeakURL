import { useEffect, useRef, useState } from "react";
import { PEAKURL_BASENAME } from "@constants";
import type { ReleaseAction } from "../../types";
import {
	createCompletedReleaseInstallProgress,
	createReleaseInstallProgress,
	findReleaseInstallStageIndex,
	getReleaseInstallStageOrder,
	RELEASE_INSTALL_REDIRECT_DELAY_MS,
} from "./releaseProgress";
import type {
	ReleaseInstallProgressState,
	ReleaseInstallStage,
} from "./types";

/**
 * State and controls for the release install confirmation and progress dialog.
 */
interface UseReleaseInstallProgress {
	pendingReleaseAction: ReleaseAction | null;
	setPendingReleaseAction: (action: ReleaseAction | null) => void;
	activeReleaseInstallAction: ReleaseAction | null;
	releaseInstallProgress: ReleaseInstallProgressState | null;
	startReleaseInstallProgress: (action: ReleaseAction) => void;
	startReleaseInstallCompletion: (
		action: ReleaseAction,
		appliedVersion?: string | null,
		onReachFinishingStage?: (() => void) | null
	) => void;
	resetReleaseInstallProgress: () => void;
	closePendingReleaseAction: () => void;
}

const releaseInstallStageOrder = getReleaseInstallStageOrder();

/**
 * Manages release-install progress state and timed transitions.
 */
function useReleaseInstallProgress(): UseReleaseInstallProgress {
	const [pendingReleaseAction, setPendingReleaseAction] =
		useState<ReleaseAction | null>(null);
	const [activeReleaseInstallAction, setActiveReleaseInstallAction] =
		useState<ReleaseAction | null>(null);
	const [releaseInstallProgress, setReleaseInstallProgress] =
		useState<ReleaseInstallProgressState | null>(null);
	const releaseInstallProgressTimerIds = useRef<number[]>([]);
	const releaseInstallProgressStateRef =
		useRef<ReleaseInstallProgressState | null>(null);
	const releaseInstallRedirectTimerId = useRef<number | null>(null);

	const setReleaseInstallProgressState = (
		progress: ReleaseInstallProgressState | null
	) => {
		releaseInstallProgressStateRef.current = progress;
		setReleaseInstallProgress(progress);
	};

	const clearReleaseInstallProgressTimers = () => {
		releaseInstallProgressTimerIds.current.forEach((timerId) => {
			window.clearTimeout(timerId);
		});
		releaseInstallProgressTimerIds.current = [];
	};

	const clearReleaseInstallRedirectTimer = () => {
		if (null !== releaseInstallRedirectTimerId.current) {
			window.clearTimeout(releaseInstallRedirectTimerId.current);
			releaseInstallRedirectTimerId.current = null;
		}
	};

	const resetReleaseInstallProgress = () => {
		clearReleaseInstallProgressTimers();
		clearReleaseInstallRedirectTimer();
		setPendingReleaseAction(null);
		setActiveReleaseInstallAction(null);
		setReleaseInstallProgressState(null);
	};

	const closePendingReleaseAction = () => {
		if (activeReleaseInstallAction) {
			return;
		}

		setPendingReleaseAction(null);
		setReleaseInstallProgressState(null);
	};

	const startReleaseInstallProgress = (action: ReleaseAction) => {
		clearReleaseInstallProgressTimers();
		clearReleaseInstallRedirectTimer();
		setActiveReleaseInstallAction(action);
		setReleaseInstallProgressState(
			createReleaseInstallProgress(action, "preparing")
		);

		// Timed transitions keep the progress dialog moving during PHP work.
		const stageTransitions: Array<{
			afterMs: number;
			stage: ReleaseInstallStage;
		}> = [
			{ afterMs: 700, stage: "downloading" },
			{ afterMs: 1900, stage: "installing" },
			{ afterMs: 3600, stage: "finishing" },
		];

		releaseInstallProgressTimerIds.current = stageTransitions.map(
			({ afterMs, stage }) =>
				window.setTimeout(() => {
					setReleaseInstallProgressState(
						createReleaseInstallProgress(action, stage)
					);
				}, afterMs)
		);
	};

	const startReleaseInstallCompletion = (
		action: ReleaseAction,
		appliedVersion?: string | null,
		onReachFinishingStage?: (() => void) | null
	) => {
		const activeStageIndex = findReleaseInstallStageIndex(
			releaseInstallProgressStateRef.current
		);
		const remainingStageSequence = releaseInstallStageOrder.slice(
			activeStageIndex + 1
		);
		const completionTransitionCount = remainingStageSequence.length + 1;
		const completionSegmentDuration =
			RELEASE_INSTALL_REDIRECT_DELAY_MS /
			(completionTransitionCount + 1);
		const finishingStageOffset =
			remainingStageSequence.indexOf("finishing");
		const finishingStageDelay =
			-1 === finishingStageOffset
				? 0
				: completionSegmentDuration * (finishingStageOffset + 1);

		clearReleaseInstallProgressTimers();
		clearReleaseInstallRedirectTimer();

		releaseInstallProgressTimerIds.current = [
			...remainingStageSequence.map((stage, index) =>
				window.setTimeout(
					() => {
						setReleaseInstallProgressState(
							createReleaseInstallProgress(action, stage)
						);
					},
					completionSegmentDuration * (index + 1)
				)
			),
			...(onReachFinishingStage && finishingStageDelay > 0
				? [
						window.setTimeout(() => {
							onReachFinishingStage();
						}, finishingStageDelay),
					]
				: []),
			window.setTimeout(() => {
				setReleaseInstallProgressState(
					createCompletedReleaseInstallProgress(
						action,
						appliedVersion
					)
				);
			}, completionSegmentDuration * completionTransitionCount),
		];

		if (onReachFinishingStage && 0 === finishingStageDelay) {
			onReachFinishingStage();
		}

		releaseInstallRedirectTimerId.current = window.setTimeout(() => {
			setPendingReleaseAction(null);
			setActiveReleaseInstallAction(null);
			setReleaseInstallProgressState(null);
			window.location.assign(
				`${PEAKURL_BASENAME || ""}/dashboard/about?source=${action === "reinstall" ? "reinstall" : "update"}`
			);
		}, RELEASE_INSTALL_REDIRECT_DELAY_MS);
	};

	useEffect(() => {
		return () => {
			clearReleaseInstallProgressTimers();
			clearReleaseInstallRedirectTimer();
		};
	}, []);

	return {
		pendingReleaseAction,
		setPendingReleaseAction,
		activeReleaseInstallAction,
		releaseInstallProgress,
		startReleaseInstallProgress,
		startReleaseInstallCompletion,
		resetReleaseInstallProgress,
		closePendingReleaseAction,
	};
}

export default useReleaseInstallProgress;
