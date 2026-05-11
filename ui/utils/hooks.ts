/**
 * Type definition for filter callbacks.
 */
type FilterCallback<TValue> = (value: TValue, ...args: unknown[]) => TValue;

/**
 * Global registry for frontend filter hooks.
 */
const filterRegistry = new Map<string, Map<string, FilterCallback<unknown>>>();

/**
 * Register a frontend filter callback using WordPress-style semantics.
 *
 * @param hookName  - The name of the hook to attach to.
 * @param namespace - A unique namespace for the callback.
 * @param callback  - The function to execute when the filter is applied.
 */
export function addFilter<TValue>(
	hookName: string,
	namespace: string,
	callback: FilterCallback<TValue>
): void {
	const normalizedHookName = hookName.trim();
	const normalizedNamespace = namespace.trim();

	if (!normalizedHookName || !normalizedNamespace) {
		return;
	}

	const callbacks =
		filterRegistry.get(normalizedHookName) ||
		new Map<string, FilterCallback<unknown>>();

	/*
	 * Store the callback in a namespace-keyed map to allow for
	 * precise removal later.
	 */
	callbacks.set(normalizedNamespace, callback as FilterCallback<unknown>);
	filterRegistry.set(normalizedHookName, callbacks);
}

/**
 * Remove a previously registered frontend filter callback.
 *
 * @param hookName  - The name of the hook.
 * @param namespace - The namespace of the callback to remove.
 */
export function removeFilter(hookName: string, namespace: string): void {
	const callbacks = filterRegistry.get(hookName.trim());

	if (!callbacks) {
		return;
	}

	callbacks.delete(namespace.trim());

	/* Clean up the hook entry if no callbacks remain. */
	if (0 === callbacks.size) {
		filterRegistry.delete(hookName.trim());
	}
}

/**
 * Apply frontend filter callbacks in registration order.
 *
 * @param hookName - The name of the hook to apply.
 * @param value    - The initial value to filter.
 * @param args     - Additional arguments passed to each callback.
 * @return The final filtered value.
 */
export function applyFilters<TValue>(
	hookName: string,
	value: TValue,
	...args: unknown[]
): TValue {
	const callbacks = filterRegistry.get(hookName.trim());

	if (!callbacks || 0 === callbacks.size) {
		return value;
	}

	let filteredValue = value;

	/*
	 * Sequentially pass the value through each registered callback,
	 * where each result becomes the input for the next function.
	 */
	for (const callback of callbacks.values()) {
		filteredValue = (callback as FilterCallback<TValue>)(
			filteredValue,
			...args
		);
	}

	return filteredValue;
}
