import { useLayoutEffect } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Scrolls the viewport to the top whenever the active route pathname changes.
 */
export function useScrollToTop(): void {
	const location = useLocation();
	const pathname = location.pathname;

	useLayoutEffect(() => {
		const resetScrollPosition = () => {
			window.scrollTo({
				top: 0,
				left: 0,
				behavior: 'auto',
			});

			document.documentElement.scrollTop = 0;
			document.body.scrollTop = 0;

			document
				.querySelectorAll<HTMLElement>(
					'.dashboard-layout-main, #page-container'
				)
				.forEach((element) => {
					element.scrollTop = 0;
				});
		};

		resetScrollPosition();

		const animationFrame = window.requestAnimationFrame(resetScrollPosition);
		const timeoutId = window.setTimeout(resetScrollPosition, 80);

		return () => {
			window.cancelAnimationFrame(animationFrame);
			window.clearTimeout(timeoutId);
		};
	}, [pathname]);
}
