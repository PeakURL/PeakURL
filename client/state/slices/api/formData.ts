/**
 * Shared request-body helpers for multipart dashboard API requests.
 *
 * Keep file-upload payload handling here so endpoint slices do not each build
 * `FormData` slightly differently.
 */

type FormDataValue = Blob | string | number | boolean | null | undefined;

/**
 * Create a multipart request body from defined field values.
 *
 * Empty strings are preserved because settings forms may intentionally clear a
 * saved value. `null` and `undefined` are skipped so optional fields do not
 * reach PHP as the literal strings "null" or "undefined".
 *
 * @param fields - Field names and values to place in the request body.
 * @return The populated multipart request body.
 */
export function createFormData(
	fields: Record<string, FormDataValue>
): FormData {
	const formData = new FormData();

	Object.entries(fields).forEach(([name, value]) => {
		if (null === value || undefined === value) {
			return;
		}

		if ("undefined" !== typeof Blob && value instanceof Blob) {
			formData.append(name, value);
			return;
		}

		formData.append(name, String(value));
	});

	return formData;
}
