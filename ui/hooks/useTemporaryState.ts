import {
	useCallback,
	useEffect,
	useRef,
	useState,
	type SetStateAction,
} from "react";

/**
 * Custom hook to manage a state value that automatically resets to a default
 * value after a specified timeout delay.
 */
export function useTemporaryState<T>(initialValue: T) {
	const [value, setValue] = useState<T>(initialValue);
	const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

	const setTemporarily = useCallback(
		(nextValue: SetStateAction<T>, resetTo: T, delayMs: number) => {
			setValue(nextValue);

			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
			}

			timeoutRef.current = setTimeout(() => {
				setValue(resetTo);
				timeoutRef.current = null;
			}, delayMs);
		},
		[]
	);

	useEffect(() => {
		return () => {
			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
			}
		};
	}, []);

	return [value, setTemporarily] as const;
}
