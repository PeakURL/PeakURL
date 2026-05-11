/**
 * Request a form submission when the browser supports programmatic submit.
 *
 * @param form - The form element to submit.
 * @return Whether a submit request was dispatched.
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
 * Request submission for the nearest ancestor form associated with an element.
 *
 * Useful for active-element flows such as verification-code inputs that do not
 * receive the submit event directly.
 *
 * @param element - The element used as a starting point for the lookup.
 * @return Whether a submit request was dispatched.
 */
export function requestClosestFormSubmit(
	element: Element | null | undefined
): boolean {
	if (!(element instanceof Element)) {
		return false;
	}

	return requestFormSubmit(element.closest("form"));
}

/**
 * Request submission for the form associated with a native form control.
 *
 * Keeps Enter-key handlers small by delegating the control-to-form lookup.
 *
 * @param control - The form control object (e.g., HTMLInputElement).
 * @return Whether a submit request was dispatched.
 */
export function requestControlFormSubmit(
	control: { form: HTMLFormElement | null } | null | undefined
): boolean {
	return requestFormSubmit(control?.form);
}

/**
 * Write text to the clipboard through the browser clipboard API.
 *
 * @param text - The text to write to the clipboard.
 * @throws Error if the clipboard API is unavailable.
 */
export async function copyToClipboard(text: string): Promise<void> {
	if (!navigator.clipboard?.writeText) {
		throw new Error("clipboard-unavailable");
	}

	await navigator.clipboard.writeText(text);
}

/**
 * Download generated browser content through a temporary object URL.
 *
 * @param content  - The content to download (Blob parts).
 * @param filename - The suggested name for the downloaded file.
 * @param type     - The MIME type of the file.
 */
export function downloadBrowserFile(
	content: BlobPart | BlobPart[],
	filename: string,
	type = "text/plain;charset=utf-8;"
): void {
	const parts = Array.isArray(content) ? content : [content];
	const blob = new Blob(parts, { type });
	const blobUrl = window.URL.createObjectURL(blob);
	const link = document.createElement("a");

	/* Create a temporary link element to trigger the download. */
	link.href = blobUrl;
	link.download = filename;
	document.body.appendChild(link);
	link.click();

	/* Clean up the temporary element and revoked the object URL. */
	document.body.removeChild(link);
	window.URL.revokeObjectURL(blobUrl);
}
