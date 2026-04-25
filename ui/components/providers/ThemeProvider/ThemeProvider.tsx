import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useSyncExternalStore,
} from "react";
import type { Theme, ThemeContextValue, ThemeProviderProps } from "../types";

const DEFAULT_THEME: Theme = "light";
const THEME_STORAGE_KEY = "theme";
const THEME_CHANGE_EVENT = "peakurl-theme-change";

const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);

const getStoredTheme = (): Theme => {
	if ("undefined" === typeof window) {
		return DEFAULT_THEME;
	}

	const storedTheme = window.localStorage.getItem(THEME_STORAGE_KEY);

	return "dark" === storedTheme ? "dark" : DEFAULT_THEME;
};

const subscribeToTheme = (callback: () => void) => {
	if ("undefined" === typeof window) {
		return () => {};
	}

	const handleThemeChange = () => callback();
	const handleStorage = (event: StorageEvent) => {
		if (event.key && event.key !== THEME_STORAGE_KEY) {
			return;
		}

		callback();
	};

	window.addEventListener(THEME_CHANGE_EVENT, handleThemeChange);
	window.addEventListener("storage", handleStorage);

	return () => {
		window.removeEventListener(THEME_CHANGE_EVENT, handleThemeChange);
		window.removeEventListener("storage", handleStorage);
	};
};

const setStoredTheme = (theme: Theme) => {
	if ("undefined" === typeof window) {
		return;
	}

	window.localStorage.setItem(THEME_STORAGE_KEY, theme);
	window.dispatchEvent(new Event(THEME_CHANGE_EVENT));
};

/**
 * ThemeProvider manages the light/dark theme and persists it to local storage.
 *
 * @param props Provider props
 * @param props.children Application content
 */
export function ThemeProvider({ children }: ThemeProviderProps) {
	const theme = useSyncExternalStore(
		subscribeToTheme,
		getStoredTheme,
		() => DEFAULT_THEME
	);

	useEffect(() => {
		const root = window.document.documentElement;
		root.classList.toggle("dark", "dark" === theme);
	}, [theme]);

	const toggleTheme = useCallback(() => {
		setStoredTheme("light" === theme ? "dark" : "light");
	}, [theme]);

	return (
		<ThemeContext.Provider value={{ theme, toggleTheme }}>
			{children}
		</ThemeContext.Provider>
	);
}

/**
 * useTheme returns the active theme plus a toggle handler.
 */
export function useTheme(): ThemeContextValue {
	const context = useContext(ThemeContext);

	if (!context) {
		throw new Error("useTheme must be used within a ThemeProvider");
	}

	return context;
}
