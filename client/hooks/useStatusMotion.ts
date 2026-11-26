import type { RefObject } from "react";
import { useGSAP } from "@gsap/react";
import { gsap } from "gsap";

gsap.registerPlugin(useGSAP);

const REDUCED_MOTION_QUERY = "(prefers-reduced-motion: reduce)";

export interface RevealGroupConfig {
	selector: string;
	from?: gsap.TweenVars;
	to?: gsap.TweenVars;
}

const DEFAULT_REVEAL_FROM: gsap.TweenVars = { y: 10, opacity: 0 };

/** Animate staged entrance groups inside a scoped container while honoring reduced motion. */
export function useRevealSequence(
	scopeRef: RefObject<HTMLElement | null>,
	groups: RevealGroupConfig[],
	dependency?: unknown
) {
	useGSAP(
		() => {
			const scope = scopeRef.current;
			const reducedMotion = window.matchMedia(REDUCED_MOTION_QUERY);
			if (!scope || reducedMotion.matches) return;

			const animations: gsap.core.Animation[] = [];
			for (const group of groups) {
				const targets = scope.querySelectorAll<HTMLElement>(
					group.selector
				);
				if (!targets.length) continue;

				animations.push(
					gsap.fromTo(
						targets,
						{ ...DEFAULT_REVEAL_FROM, ...group.from },
						{
							y: 0,
							opacity: 1,
							ease: "power2.out",
							clearProps: "transform,opacity",
							...group.to,
						}
					)
				);
			}

			const settleOnReducedMotion = () => {
				if (!reducedMotion.matches) return;
				animations.forEach((animation) => animation.progress(1));
			};
			reducedMotion.addEventListener("change", settleOnReducedMotion);
			return () => {
				reducedMotion.removeEventListener(
					"change",
					settleOnReducedMotion
				);
			};
		},
		{
			scope: scopeRef,
			dependencies: dependency === undefined ? [] : [dependency],
			revertOnUpdate: dependency !== undefined,
		}
	);
}

/** Animate an illustration element with a crisp entrance settle and continuous subtle float. */
export function useFloatingArtwork(
	scopeRef: RefObject<HTMLElement | null>,
	selector: string,
	dependency?: unknown
) {
	useGSAP(
		() => {
			const scope = scopeRef.current;
			const reducedMotion = window.matchMedia(REDUCED_MOTION_QUERY);
			if (!scope || reducedMotion.matches) return;

			const artwork = scope.querySelector<HTMLElement>(selector);
			if (!artwork) return;

			const timeline = gsap.timeline();
			timeline
				.fromTo(
					artwork,
					{ y: 18, scale: 0.92, opacity: 0 },
					{
						y: 0,
						scale: 1,
						opacity: 1,
						duration: 0.55,
						delay: 0.08,
						ease: "back.out(1.4)",
						clearProps: "scale,opacity",
					}
				)
				.to(artwork, {
					y: -8,
					duration: 2.6,
					repeat: -1,
					yoyo: true,
					ease: "sine.inOut",
				});

			const stopOnReducedMotion = () => {
				if (!reducedMotion.matches) return;
				timeline.kill();
				gsap.set(artwork, { clearProps: "transform,opacity,scale" });
			};
			reducedMotion.addEventListener("change", stopOnReducedMotion);
			return () => {
				reducedMotion.removeEventListener(
					"change",
					stopOnReducedMotion
				);
			};
		},
		{
			scope: scopeRef,
			dependencies: dependency === undefined ? [] : [dependency],
			revertOnUpdate: dependency !== undefined,
		}
	);
}
