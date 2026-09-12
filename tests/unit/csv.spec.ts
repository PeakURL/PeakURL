import { test, expect } from "@playwright/test";
import {
	extractAliasFromShortUrl,
	normalizeCsvHeader,
	parseCsvRows,
	serializeCsv,
	stringifyCsvValue,
} from "../../ui/shared/csv";

test.describe("CSV Utilities", () => {
	test.describe("normalizeCsvHeader", () => {
		test("strips BOM, trims whitespace, lowercases, and removes non-alphanumerics", () => {
			expect(normalizeCsvHeader("\uFEFF Destination URL ")).toBe(
				"destinationurl"
			);
			expect(normalizeCsvHeader("Short-Code / Alias")).toBe(
				"shortcodealias"
			);
			expect(normalizeCsvHeader("Title (optional)")).toBe(
				"titleoptional"
			);
		});
	});

	test.describe("extractAliasFromShortUrl", () => {
		test("extracts slug from full short URL", () => {
			expect(
				extractAliasFromShortUrl("https://peakurl.dev/my-promo")
			).toBe("my-promo");
			expect(
				extractAliasFromShortUrl("https://example.com/sub/deep-link")
			).toBe("deep-link");
		});

		test("extracts slug from relative path", () => {
			expect(extractAliasFromShortUrl("/promo-2026")).toBe("promo-2026");
		});

		test("returns empty string for blank input", () => {
			expect(extractAliasFromShortUrl("")).toBe("");
			expect(extractAliasFromShortUrl("   ")).toBe("");
		});
	});

	test.describe("parseCsvRows", () => {
		test("parses standard comma-delimited rows", () => {
			const csv = "url,alias,title\nhttps://google.com,google,Google Search\nhttps://github.com,github,GitHub";
			const rows = parseCsvRows(csv);
			expect(rows).toEqual([
				["url", "alias", "title"],
				["https://google.com", "google", "Google Search"],
				["https://github.com", "github", "GitHub"],
			]);
		});

		test("handles quoted values with commas and escaped quotes", () => {
			const csv = 'url,title\n"https://site.com","Hello, World"\n"https://site2.com","Quoted ""Double"" Title"';
			const rows = parseCsvRows(csv);
			expect(rows).toEqual([
				["url", "title"],
				["https://site.com", "Hello, World"],
				["https://site2.com", 'Quoted "Double" Title'],
			]);
		});

		test("handles CRLF and ignores BOM", () => {
			const csv = "\uFEFFheader1,header2\r\nval1,val2\r\n";
			const rows = parseCsvRows(csv);
			expect(rows).toEqual([
				["header1", "header2"],
				["val1", "val2"],
			]);
		});
	});

	test.describe("serializeCsv & stringifyCsvValue", () => {
		test("escapes values containing commas and quotes", () => {
			expect(stringifyCsvValue('Hello, "World"')).toBe(
				'"Hello, ""World"""'
			);
			expect(stringifyCsvValue("Plain Value")).toBe("Plain Value");
		});

		test("serializes headers and rows into CSV string", () => {
			const headers = ["url", "title"];
			const rows = [
				["https://a.com", "Site A"],
				["https://b.com", "Site, B"],
			];
			const serialized = serializeCsv(headers, rows);
			expect(serialized).toBe(
				'url,title\nhttps://a.com,Site A\nhttps://b.com,"Site, B"'
			);
		});
	});
});
