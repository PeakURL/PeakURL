import { useState, useCallback, useMemo, type SetStateAction } from "react";

/**
 * Custom hook to manage state scoped to a specific link ID.
 * If the active link ID changes, the state falls back to a specified default value.
 */
export function usePerLinkState<T>(linkId: string, defaultValue: T) {
	const [state, setState] = useState<{
		linkId: string;
		value: T;
	} | null>(null);

	const resolvedValue = useMemo(() => {
		return state?.linkId === linkId ? state.value : defaultValue;
	}, [state, linkId, defaultValue]);

	const setValue = useCallback(
		(nextValue: SetStateAction<T>) => {
			setState((currentState) => {
				const currentVal =
					currentState?.linkId === linkId
						? currentState.value
						: defaultValue;
				const value =
					typeof nextValue === "function"
						? (nextValue as (prev: T) => T)(currentVal)
						: nextValue;

				return { linkId, value };
			});
		},
		[linkId, defaultValue]
	);

	return [resolvedValue, setValue] as const;
}
