export const normalizeCsvHeader = (value = '') =>
	String(value)
		.replace(/^\uFEFF/, '')
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9]/g, '');

export const extractAliasFromShortUrl = (value = '') => {
	const normalizedValue = String(value).trim();

	if (!normalizedValue) {
		return '';
	}

	try {
		const url = new URL(normalizedValue);
		const pathname = url.pathname.replace(/^\/+|\/+$/g, '');
		return pathname ? decodeURIComponent(pathname.split('/').pop()) : '';
	} catch {
		const pathname = normalizedValue.replace(/^\/+|\/+$/g, '');
		return pathname ? pathname.split('/').pop() : '';
	}
};

export const parseCsvRows = (text = '') => {
	const rows = [];
	const source = String(text).replace(/^\uFEFF/, '');
	let row = [];
	let value = '';
	let inQuotes = false;

	const pushRow = () => {
		row.push(value);

		if (row.some((cell) => String(cell).trim() !== '')) {
			rows.push(row);
		}

		row = [];
		value = '';
	};

	for (let i = 0; i < source.length; i++) {
		const char = source[i];

		if (char === '"') {
			if (inQuotes && source[i + 1] === '"') {
				value += '"';
				i++;
			} else {
				inQuotes = !inQuotes;
			}
			continue;
		}

		if (char === ',' && !inQuotes) {
			row.push(value);
			value = '';
			continue;
		}

		if ((char === '\n' || char === '\r') && !inQuotes) {
			if (char === '\r' && source[i + 1] === '\n') {
				i++;
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

export const stringifyCsvValue = (value) => {
	const normalizedValue = value == null ? '' : String(value);

	if (/[",\r\n]/.test(normalizedValue)) {
		return `"${normalizedValue.replace(/"/g, '""')}"`;
	}

	return normalizedValue;
};

export const serializeCsv = (headers = [], rows = []) =>
	[headers, ...rows]
		.map((row) => row.map((value) => stringifyCsvValue(value)).join(','))
		.join('\n');
