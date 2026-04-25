/**
 * Normalizes a CSV header so it can be matched against known import keys.
 */
export const normalizeCsvHeader = (value: unknown = ""): string =>
	String(value)
		.replace(/^\uFEFF/, "")
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9]/g, "");

/**
 * Extracts a short-code or alias value from a public short URL.
 */
export const extractAliasFromShortUrl = (value: unknown = ""): string => {
	const normalizedValue = String(value).trim();

	if (!normalizedValue) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);
		const pathname = url.pathname.replace(/^\/+|\/+$/g, "");
		return pathname
			? decodeURIComponent(pathname.split("/").pop() || "")
			: "";
	} catch {
		const pathname = normalizedValue.replace(/^\/+|\/+$/g, "");
		return pathname ? pathname.split("/").pop() || "" : "";
	}
};

/**
 * Parses CSV text into rows while handling quoted values and escaped quotes.
 */
export const parseCsvRows = (text: string = ""): string[][] => {
	const rows: string[][] = [];
	const source = String(text).replace(/^\uFEFF/, "");
	let row: string[] = [];
	let value = "";
	let inQuotes = false;

	const pushRow = () => {
		row.push(value);

		if (row.some((cell) => String(cell).trim() !== "")) {
			rows.push(row);
		}

		row = [];
		value = "";
	};

	for (let i = 0; i < source.length; i += 1) {
		const char = source[i];

		if ('"' === char) {
			if (inQuotes && source[i + 1] === '"') {
				value += '"';
				i += 1;
			} else {
				inQuotes = !inQuotes;
			}
			continue;
		}

		if ("," === char && !inQuotes) {
			row.push(value);
			value = "";
			continue;
		}

		if (("\n" === char || "\r" === char) && !inQuotes) {
			if ("\r" === char && source[i + 1] === "\n") {
				i += 1;
			}

			pushRow();
			continue;
		}

		value += char;
	}

	if (value.length > 0 || row.length > 0) {
		pushRow();
	}

	return rows;
};

/**
 * Escapes a CSV value when it contains delimiters or newlines.
 */
export const stringifyCsvValue = (value: unknown): string => {
	const normalizedValue = value == null ? "" : String(value);

	if (/[",\r\n]/.test(normalizedValue)) {
		return `"${normalizedValue.replace(/"/g, '""')}"`;
	}

	return normalizedValue;
};

/**
 * Serializes headers and row values into a CSV document string.
 */
export const serializeCsv = (
	headers: Array<unknown> = [],
	rows: Array<Array<unknown>> = []
): string =>
	[headers, ...rows]
		.map((row) => row.map((value) => stringifyCsvValue(value)).join(","))
		.join("\n");
