import { useLayoutEffect } from "react";
import type { RefObject } from "react";

const MAX_SETTINGS_SECTION_HEADINGS = 2;
const ENTRANCE_DURATION_MS = 320;
const ENTRANCE_STAGGER_MS = 40;

/** Animate route content while keeping layout containers and overlays in place. */
export function usePageTransition(
	contentRef: RefObject<HTMLElement | null>,
	pathname: string
) {
	useLayoutEffect(() => {
		const content = contentRef.current;
		const reducedMotion = window.matchMedia(
			"(prefers-reduced-motion: reduce)"
		);
		if (!content || reducedMotion.matches) return;

		const heading = content.querySelector(".page-header-text");
		const overviewSections = content.querySelectorAll(
			":scope > .dashboard-page > :is(.dashboard-page-traffic-grid, .dashboard-page-summary-grid)"
		);
		const settingsHeadings = content.querySelectorAll(
			":scope > .settings-layout > .settings-tab-page .settings-section-title"
		);
		// Transform only heading copy and overview sections. Route wrappers can
		// also contain sticky controls and fixed overlays.
		const targets = [
			...(heading ? [heading] : []),
			...overviewSections,
			...Array.from(settingsHeadings).slice(
				0,
				MAX_SETTINGS_SECTION_HEADINGS
			),
		];
		if (!targets.length) return;

		// Explicit keyframes let the browser run the entrance without reading
		// the computed transform or keeping a JavaScript tween on each target.
		const animations = targets.map((target, index) =>
			target.animate(
				[
					{ opacity: 0.88, transform: "translateY(7px)" },
					{ opacity: 1, transform: "translateY(0)" },
				],
				{
					duration: ENTRANCE_DURATION_MS,
					delay: index * ENTRANCE_STAGGER_MS,
					easing: "cubic-bezier(0.2, 0.75, 0.25, 1)",
					fill: "backwards",
				}
			)
		);
		const cancelAnimations = () =>
			animations.forEach((animation) => animation.cancel());
		const handleMotionPreference = () => {
			if (reducedMotion.matches) cancelAnimations();
		};
		reducedMotion.addEventListener("change", handleMotionPreference);
		return () => {
			cancelAnimations();
			reducedMotion.removeEventListener("change", handleMotionPreference);
		};
	}, [contentRef, pathname]);
}
