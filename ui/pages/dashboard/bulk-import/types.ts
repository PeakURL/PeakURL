import type { ReactNode } from 'react';

/**
 * Props for the bulk import layout shell.
 *
 * Wraps the tabbed import surface and renders the active import tool inside it.
 */
export interface BulkImportLayoutProps {
	/** Content rendered for the currently selected import tab. */
	children: ReactNode;
}
