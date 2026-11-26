import { useSyncExternalStore } from "react";

const mediaQuery = "(prefers-reduced-motion: reduce)";

function subscribe(onChange: () => void) {
	const preference = window.matchMedia(mediaQuery);
	preference.addEventListener("change", onChange);
	return () => preference.removeEventListener("change", onChange);
}

/** Track runtime preference changes for canvas charts. */
export function useReducedMotion() {
	return useSyncExternalStore(
		subscribe,
		() => window.matchMedia(mediaQuery).matches,
		() => true
	);
}
