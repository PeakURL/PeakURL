import { test, expect } from "@playwright/test";
import {
	extractAliasFromShortUrl,
	normalizeCsvHeader,
	parseCsvRows,
	serializeCsv,
	stringifyCsvValue,
} from "../../client/shared/csv";

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
			const csv =
				"url,alias,title\nhttps://google.com,google,Google Search\nhttps://github.com,github,GitHub";
			const rows = parseCsvRows(csv);
			expect(rows).toEqual([
				["url", "alias", "title"],
				["https://google.com", "google", "Google Search"],
				["https://github.com", "github", "GitHub"],
			]);
		});

		test("handles quoted values with commas and escaped quotes", () => {
			const csv =
				'url,title\n"https://site.com","Hello, World"\n"https://site2.com","Quoted ""Double"" Title"';
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

		test("serializes null and undefined cell values as empty strings without fabrication", () => {
			expect(stringifyCsvValue(null)).toBe("");
			expect(stringifyCsvValue(undefined)).toBe("");
			const headers = ["clicks", "uniqueClicks"];
			const rows = [[null, null]];
			expect(serializeCsv(headers, rows)).toBe("clicks,uniqueClicks\n,");
		});
	});

	test.describe("Link Export Formatting & Redaction", () => {
		test("preserves null clicks and uniqueClicks without fabricating zero", async () => {
			const { formatLinkExportItems, serializeLinkExport } =
				await import("../../client/pages/dashboard/tools/export/lib/linkExport");

			const redactedLink = {
				id: "l1",
				userId: "user_a",
				destinationUrl: "https://example.com/target",
				shortCode: "redacted1",
				alias: "redacted-alias",
				title: "Sensitive Link",
				status: "active",
				clicks: null,
				uniqueClicks: null,
				createdAt: "2026-10-14T10:00:00Z",
			};

			const items = formatLinkExportItems([redactedLink]);
			expect(items).toHaveLength(1);
			expect(items[0]?.clicks).toBeNull();
			expect(items[0]?.uniqueClicks).toBeNull();

			// CSV serialization
			const csv = serializeLinkExport("csv", items);
			const csvLines = csv.trim().split("\n");
			expect(csvLines[0]).toContain("clicks,uniqueClicks");
			// Check that clicks and uniqueClicks columns are blank, not "0"
			expect(csvLines[1]).toContain(",,2026-10-14T10:00:00Z");

			// JSON serialization
			const json = serializeLinkExport("json", items);
			const parsed = JSON.parse(json);
			expect(parsed[0].clicks).toBeNull();
			expect(parsed[0].uniqueClicks).toBeNull();

			// XML serialization
			const xml = serializeLinkExport("xml", items);
			expect(xml).toContain("<clicks></clicks>");
			expect(xml).toContain("<uniqueClicks></uniqueClicks>");
		});
	});
});
