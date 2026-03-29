// @ts-nocheck
'use client';
import { useScrollToTop } from '@/hooks';

/**
 * Page transition wrapper component with smooth scroll to top
 * Simple wrapper that handles scroll behavior on route change
 */
export const PageTransition = ({ children }) => {
	useScrollToTop();

	return <>{children}</>;
};
