'use client';
import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Hook that scrolls to top smoothly when route changes
 */
export const useScrollToTop = () => {
	const location = useLocation();
	const pathname = location.pathname;

	useEffect(() => {
		window.scrollTo({
			top: 0,
			left: 0,
			behavior: 'smooth',
		});
	}, [pathname]);
};
