/**
 * Requests a form submission when the browser supports programmatic submit.
 *
 * Returns `true` when a submit request was dispatched.
 */
export function requestFormSubmit(
	form: HTMLFormElement | null | undefined
): boolean {
	if (!(form instanceof HTMLFormElement)) {
		return false;
	}

	form.requestSubmit();
	return true;
}

/**
 * Requests submission for the nearest ancestor form associated with an element.
 *
 * Useful for active-element flows such as verification-code inputs that do not
 * receive the submit event directly.
 */
export function requestClosestFormSubmit(
	element: Element | null | undefined
): boolean {
	if (!(element instanceof Element)) {
		return false;
	}

	return requestFormSubmit(element.closest('form'));
}

/**
 * Requests submission for the form associated with a native form control.
 *
 * Keeps Enter-key handlers small by delegating the control-to-form lookup.
 */
export function requestControlFormSubmit(
	control: { form: HTMLFormElement | null } | null | undefined
): boolean {
	return requestFormSubmit(control?.form);
}

/**
 * Writes text to the clipboard through the browser clipboard API.
 *
 * Throws when the clipboard API is unavailable so callers can surface a
 * user-friendly error message.
 */
export async function copyToClipboard(text: string): Promise<void> {
	if (!navigator.clipboard?.writeText) {
		throw new Error('clipboard-unavailable');
	}

	await navigator.clipboard.writeText(text);
}
