/**
 * Join class names while dropping falsy entries.
 *
 * @param classes - The class names to join.
 * @return The joined class name string.
 */
export function cn(
	...classes: Array<string | false | null | undefined>
): string {
	return classes.filter(Boolean).join(" ");
}
