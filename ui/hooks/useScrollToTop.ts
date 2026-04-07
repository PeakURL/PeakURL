import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Scrolls the viewport to the top whenever the active route pathname changes.
 */
export function useScrollToTop(): void {
	const location = useLocation();
	const pathname = location.pathname;

	useEffect(() => {
		window.scrollTo({
			top: 0,
			left: 0,
			behavior: 'smooth',
		});
	}, [pathname]);
}
