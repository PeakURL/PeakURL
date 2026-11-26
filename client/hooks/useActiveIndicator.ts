import type { RefObject } from "react";
import { useGSAP } from "@gsap/react";
import { gsap } from "gsap";

gsap.registerPlugin(useGSAP);

interface ActiveIndicatorOptions {
	duration: number;
	enabled?: boolean;
	selector?: string;
	scroller?: RefObject<HTMLElement | null>;
}

const GEOMETRY_TOLERANCE = 0.25;

/** Keep a decorative indicator aligned in the navigation track's own coordinates. */
export function useActiveIndicator(
	trackRef: RefObject<HTMLElement | null>,
	indicatorRef: RefObject<HTMLElement | null>,
	{
		duration,
		enabled = true,
		selector = '[aria-current="page"]',
		scroller,
	}: ActiveIndicatorOptions
) {
	useGSAP(
		() => {
			const track = trackRef.current;
			const indicator = indicatorRef.current;
			const viewport = scroller?.current;
			if (!enabled || !track || !indicator) return;

			const reducedMotion = window.matchMedia(
				"(prefers-reduced-motion: reduce)"
			);
			let active: HTMLElement | null = null;
			let geometry: number[] | undefined;
			let frame: number | undefined;
			let selectionDirty = true;
			let tween: gsap.core.Tween | undefined;

			const align = () => {
				frame = undefined;
				const selected = selectionDirty
					? track.querySelector<HTMLElement>(selector)
					: active;
				selectionDirty = false;
				const changed = selected !== active;
				if (changed) {
					if (active) resize.unobserve(active);
					if (selected)
						resize.observe(selected, { box: "border-box" });
					active = selected;
				}

				if (!active) {
					tween?.kill();
					geometry = undefined;
					indicator.style.visibility = "";
					return;
				}

				// Read all the geometry before scrolling or changing the indicator.
				const link = active.getBoundingClientRect();
				if (!link.width || !link.height) {
					tween?.kill();
					geometry = undefined;
					indicator.style.visibility = "";
					return;
				}
				const origin = track.getBoundingClientRect();
				const x = link.left - origin.left;
				const y = link.top - origin.top;
				const inset = origin.width - link.width;
				const next = viewport
					? [x, link.width]
					: [x, y, inset, link.height];
				const bounds = viewport?.getBoundingClientRect();
				const left = Math.ceil(bounds?.left ?? 0);
				const right = left + (viewport?.clientWidth ?? 0);
				const overflow = !viewport
					? 0
					: link.left < left
						? Math.floor(link.left - left)
						: Math.max(0, Math.ceil(link.right - right));

				if (overflow)
					viewport?.scrollBy({ left: overflow, behavior: "instant" });
				if (
					geometry &&
					next.every(
						(value, index) =>
							Math.abs(value - (geometry?.[index] ?? 0)) <=
							GEOMETRY_TOLERANCE
					)
				)
					return;

				const remaining = tween?.isActive()
					? tween.duration() - tween.time()
					: 0;
				const time =
					reducedMotion.matches || !geometry
						? 0
						: changed
							? duration
							: remaining;
				const properties = viewport
					? { x, scaleX: link.width }
					: {
							x,
							y,
							"--sidebar-indicator-inset": `${inset}px`,
							height: link.height,
						};

				geometry = next;
				tween?.kill();
				indicator.style.visibility = "visible";
				// Scheduled callbacks own at most one tween. Cleanup kills it and
				// removes the inline styles written by completed animations.
				tween =
					time > 0
						? gsap.to(indicator, {
								...properties,
								duration: time,
								ease: "power2.out",
							})
						: undefined;
				if (!tween) gsap.set(indicator, properties);
			};

			const scheduleAlignment = () => {
				frame ??= requestAnimationFrame(align);
			};

			// The sidebar indicator already inherits the track's inline size.
			const blockSizes = new WeakMap<Element, number>();
			const resize = new ResizeObserver((entries) => {
				if (viewport) {
					scheduleAlignment();
					return;
				}
				for (const entry of entries) {
					const blockSize =
						entry.borderBoxSize[0]?.blockSize ??
						entry.contentRect.height;
					if (blockSizes.get(entry.target) !== blockSize) {
						blockSizes.set(entry.target, blockSize);
						scheduleAlignment();
					}
				}
			});
			resize.observe(track, { box: "border-box" });
			if (viewport) resize.observe(viewport);

			const navigationChanges = new MutationObserver(() => {
				selectionDirty = true;
				scheduleAlignment();
			});
			navigationChanges.observe(document.documentElement, {
				attributeFilter: ["dir"],
			});
			navigationChanges.observe(track, {
				attributeFilter: [
					"aria-current",
					"data-sidebar-indicator-target",
				],
				childList: true,
				characterData: true,
				subtree: true,
			});

			const finishForReducedMotion = () => {
				if (reducedMotion.matches) tween?.progress(1);
			};
			reducedMotion.addEventListener("change", finishForReducedMotion);
			scheduleAlignment();

			return () => {
				if (frame !== undefined) cancelAnimationFrame(frame);
				resize.disconnect();
				navigationChanges.disconnect();
				reducedMotion.removeEventListener(
					"change",
					finishForReducedMotion
				);
				tween?.kill();
				indicator.removeAttribute("style");
			};
		},
		{
			scope: trackRef,
			dependencies: [
				trackRef,
				indicatorRef,
				selector,
				scroller,
				duration,
				enabled,
			],
			revertOnUpdate: true,
		}
	);
}
