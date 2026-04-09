type FilterCallback<TValue> = (value: TValue, ...args: unknown[]) => TValue;

const filterRegistry = new Map<string, Map<string, FilterCallback<unknown>>>();

/**
 * Registers a frontend filter callback using WordPress-style semantics.
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

	callbacks.set(normalizedNamespace, callback as FilterCallback<unknown>);
	filterRegistry.set(normalizedHookName, callbacks);
}

/**
 * Removes a previously registered frontend filter callback.
 */
export function removeFilter(hookName: string, namespace: string): void {
	const callbacks = filterRegistry.get(hookName.trim());

	if (!callbacks) {
		return;
	}

	callbacks.delete(namespace.trim());

	if (0 === callbacks.size) {
		filterRegistry.delete(hookName.trim());
	}
}

/**
 * Applies frontend filter callbacks in registration order.
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

	for (const callback of callbacks.values()) {
		filteredValue = (callback as FilterCallback<TValue>)(
			filteredValue,
			...args
		);
	}

	return filteredValue;
}
