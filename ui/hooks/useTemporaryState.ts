import {
	useCallback,
	useEffect,
	useRef,
	useState,
	type SetStateAction,
} from "react";

/**
 * Custom hook to manage a state value that automatically resets to a specified
 * default value after a timeout delay.
 */
export function useTemporaryState<T>(initialValue: T = null as unknown as T) {
	const [value, setValue] = useState<T>(initialValue);
	const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

	const setTemporarily = useCallback(
		(nextValue: SetStateAction<T>, delayMs: number) => {
			setValue(nextValue);

			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
			}

			timeoutRef.current = setTimeout(() => {
				setValue(initialValue);
				timeoutRef.current = null;
			}, delayMs);
		},
		[initialValue]
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
