import { useLayoutEffect } from "react";
import { useLocation } from "react-router";

/**
 * Scrolls the viewport to the top whenever the active route pathname changes.
 */
export function useScrollToTop(): void {
	const { pathname } = useLocation();

	useLayoutEffect(() => {
		window.scrollTo({ top: 0, left: 0, behavior: "instant" });
	}, [pathname]);
}
