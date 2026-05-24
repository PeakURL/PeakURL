import type { ReactNode } from "react";

/**
 * Primitive values supported by dashboard option controls.
 */
export type SelectValue = string | number;

/**
 * Shared option shape used by select-like dashboard controls.
 *
 * Utilities can return this data shape without depending on component barrels,
 * while UI components can still render rich React labels when needed.
 */
export interface SelectOption<T extends SelectValue = SelectValue> {
	/** Label rendered for the option. */
	label: ReactNode;

	/** Submitted option value. */
	value: T;

	/** Whether the option is visible but unavailable. */
	disabled?: boolean;
}
