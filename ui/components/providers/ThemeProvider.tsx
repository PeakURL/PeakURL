// @ts-nocheck
'use client';
import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useSyncExternalStore,
} from 'react';

const ThemeContext = createContext();
const DEFAULT_THEME = 'light';
const THEME_STORAGE_KEY = 'theme';
const THEME_CHANGE_EVENT = 'peakurl-theme-change';

const getStoredTheme = () => {
	if (typeof window === 'undefined') {
		return DEFAULT_THEME;
	}

	return window.localStorage.getItem(THEME_STORAGE_KEY) || DEFAULT_THEME;
};

const subscribeToTheme = (callback) => {
	if (typeof window === 'undefined') {
		return () => {};
	}

	const handleThemeChange = () => callback();
	const handleStorage = (event) => {
		if (event.key && event.key !== THEME_STORAGE_KEY) {
			return;
		}

		callback();
	};

	window.addEventListener(THEME_CHANGE_EVENT, handleThemeChange);
	window.addEventListener('storage', handleStorage);

	return () => {
		window.removeEventListener(THEME_CHANGE_EVENT, handleThemeChange);
		window.removeEventListener('storage', handleStorage);
	};
};

const setStoredTheme = (theme) => {
	if (typeof window === 'undefined') {
		return;
	}

	window.localStorage.setItem(THEME_STORAGE_KEY, theme);
	window.dispatchEvent(new Event(THEME_CHANGE_EVENT));
};

/**
 * ThemeProvider Component
 * Manages the application theme (light/dark) using Context API.
 * Persists theme preference to localStorage.
 * @param {Object} props
 * @param {React.ReactNode} props.children - Application content
 */
export function ThemeProvider({ children }) {
	const theme = useSyncExternalStore(
		subscribeToTheme,
		getStoredTheme,
		() => DEFAULT_THEME
	);

	// Theme Effect
	// Applies the theme class to the HTML root element.
	useEffect(() => {
		const root = window.document.documentElement;
		root.classList.toggle('dark', theme === 'dark');
	}, [theme]);

	// Toggle Handler
	const toggleTheme = useCallback(() => {
		setStoredTheme(theme === 'light' ? 'dark' : 'light');
	}, [theme]);

	return (
		<ThemeContext.Provider value={{ theme, toggleTheme }}>
			{children}
		</ThemeContext.Provider>
	);
}

/**
 * Custom hook to access theme context
 * @returns {Object} { theme, toggleTheme }
 * @throws {Error} If used outside of ThemeProvider
 */
export function useTheme() {
	const context = useContext(ThemeContext);
	if (context === undefined) {
		throw new Error('useTheme must be used within a ThemeProvider');
	}
	return context;
}
