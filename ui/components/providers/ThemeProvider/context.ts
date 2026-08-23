import { createContext, useContext } from "react";
import type { ThemeContextValue } from "../types";

export const ThemeContext = createContext<ThemeContextValue | undefined>(
	undefined
);

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
