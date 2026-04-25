import type { ReactNode } from "react";

/**
 * Props for the import layout shell.
 *
 * Wraps the tabbed import surface and renders the active import tool inside it.
 */
export interface ImportLayoutProps {
	/** Content rendered for the currently selected import tab. */
	children: ReactNode;
}
