/**
 * Normalize a CSV header so it can be matched against known import keys.
 *
 * @param value - The raw header value.
 * @return The normalized header string.
 */
export const normalizeCsvHeader = (value: unknown = ""): string =>
	String(value)
		.replace(/^\uFEFF/, "")
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9]/g, "");

/**
 * Extract a short-code or alias value from a public short URL.
 *
 * @param value - The URL or path to parse.
 * @return The extracted alias.
 */
export const extractAliasFromShortUrl = (value: unknown = ""): string => {
	const normalizedValue = String(value).trim();

	if (!normalizedValue) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);
		const pathname = url.pathname.replace(/^\/+|\/+$/g, "");

		/* Extract the last segment of the path as the alias. */
		return pathname
			? decodeURIComponent(pathname.split("/").pop() || "")
			: "";
	} catch {
		/* Fall back to manual path splitting if URL parsing fails. */
		const pathname = normalizedValue.replace(/^\/+|\/+$/g, "");
		return pathname ? pathname.split("/").pop() || "" : "";
	}
};

/**
 * Parse CSV text into rows while handling quoted values and escaped quotes.
 *
 * @param text - The raw CSV content.
 * @return An array of string arrays representing the CSV rows.
 */
export const parseCsvRows = (text: string = ""): string[][] => {
	const rows: string[][] = [];
	const source = String(text).replace(/^\uFEFF/, "");
	let row: string[] = [];
	let value = "";
	let inQuotes = false;

	const pushRow = () => {
		row.push(value);

		/* Only push rows that have at least one non-empty cell. */
		if (row.some((cell) => String(cell).trim() !== "")) {
			rows.push(row);
		}

		row = [];
		value = "";
	};

	/* Iterate through the source text to identify cells and rows. */
	for (let i = 0; i < source.length; i += 1) {
		const char = source[i];

		/* Handle double-quote escaping and toggle quoted-state. */
		if ('"' === char) {
			if (inQuotes && source[i + 1] === '"') {
				value += '"';
				i += 1;
			} else {
				inQuotes = !inQuotes;
			}
			continue;
		}

		/* Treat commas as delimiters when outside of quotes. */
		if ("," === char && !inQuotes) {
			row.push(value);
			value = "";
			continue;
		}

		/* Handle line breaks as row delimiters when outside of quotes. */
		if (("\n" === char || "\r" === char) && !inQuotes) {
			if ("\r" === char && source[i + 1] === "\n") {
				i += 1;
			}

			pushRow();
			continue;
		}

		value += char;
	}

	/* Push the final row if it hasn't been added yet. */
	if (value.length > 0 || row.length > 0) {
		pushRow();
	}

	return rows;
};

/**
 * Escape a CSV value when it contains delimiters or newlines.
 *
 * @param value - The raw cell value.
 * @return The CSV-safe escaped string.
 */
export const stringifyCsvValue = (value: unknown): string => {
	const normalizedValue =
		null === value || undefined === value ? "" : String(value);

	/* Wrap in quotes and escape internal quotes if special characters are present. */
	if (/[",\r\n]/.test(normalizedValue)) {
		return `"${normalizedValue.replace(/"/g, '""')}"`;
	}

	return normalizedValue;
};

/**
 * Serialize headers and row values into a CSV document string.
 *
 * @param headers - The CSV header row.
 * @param rows    - The data rows to include.
 * @return The final CSV document string.
 */
export const serializeCsv = (
	headers: Array<unknown> = [],
	rows: Array<Array<unknown>> = []
): string =>
	[headers, ...rows]
		.map((row) => row.map((value) => stringifyCsvValue(value)).join(","))
		.join("\n");
