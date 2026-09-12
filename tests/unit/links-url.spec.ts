import { test, expect } from "@playwright/test";
import {
	decodeHtmlEntities,
	getLinkDisplayTitle,
	getLinkHost,
	getShortUrl,
	isRelativeUrl,
	normalizeLinkTitle,
	sanitizeImageUrl,
	sanitizeUrl,
} from "../../client/shared/links";

test.describe("Links & URL Utilities", () => {
	test.describe("sanitizeUrl", () => {
		test("allows valid http and https URLs", () => {
			expect(sanitizeUrl("https://example.com")).toBe(
				"https://example.com/"
			);
			expect(sanitizeUrl("http://example.com/page?query=1")).toBe(
				"http://example.com/page?query=1"
			);
		});

		test("allows valid root-relative paths", () => {
			expect(sanitizeUrl("/dashboard/links")).toBe("/dashboard/links");
			expect(sanitizeUrl("/login")).toBe("/login");
		});

		test("rejects dangerous protocols and protocol-relative URLs", () => {
			expect(sanitizeUrl("javascript:alert(1)")).toBe("");
			expect(sanitizeUrl("data:text/html,test")).toBe("");
			expect(sanitizeUrl("vbscript:test")).toBe("");
			expect(sanitizeUrl("//malicious.com")).toBe("");
		});

		test("handles empty and nullish values safely", () => {
			expect(sanitizeUrl("")).toBe("");
			expect(sanitizeUrl(null)).toBe("");
			expect(sanitizeUrl(undefined)).toBe("");
		});
	});

	test.describe("isRelativeUrl", () => {
		test("identifies root-relative URLs", () => {
			expect(isRelativeUrl("/dashboard")).toBe(true);
			expect(isRelativeUrl("/settings/general")).toBe(true);
		});

		test("rejects protocol-relative and absolute URLs", () => {
			expect(isRelativeUrl("//example.com")).toBe(false);
			expect(isRelativeUrl("https://example.com")).toBe(false);
			expect(isRelativeUrl("relative/path")).toBe(false);
		});
	});

	test.describe("sanitizeImageUrl", () => {
		test("allows valid web image URLs and trusted origins", () => {
			expect(
				sanitizeImageUrl("https://images.unsplash.com/photo-1")
			).toBe("https://images.unsplash.com/photo-1");
			expect(sanitizeImageUrl("/favicon.png")).toBe("/favicon.png");
		});

		test("rejects javascript and protocol-relative image URLs", () => {
			expect(sanitizeImageUrl("javascript:alert(1)")).toBe("");
			expect(sanitizeImageUrl("//evil.com/pic.jpg")).toBe("");
		});
	});

	test.describe("linkTitles & decoding", () => {
		test("decodes named, decimal, and hex HTML entities", () => {
			expect(decodeHtmlEntities("Tom &amp; Jerry")).toBe("Tom & Jerry");
			expect(decodeHtmlEntities("&quot;Hello&#039;s World&quot;")).toBe(
				'"Hello\'s World"'
			);
			expect(decodeHtmlEntities("&#8212;")).toBe("—");
			expect(decodeHtmlEntities("&#x2014;")).toBe("—");
		});

		test("normalizes link titles by decoding and trimming", () => {
			expect(normalizeLinkTitle("  &lt;My Link&gt;  ")).toBe("<My Link>");
			expect(getLinkDisplayTitle("", "Fallback")).toBe("Fallback");
			expect(getLinkDisplayTitle("Title", "Fallback")).toBe("Title");
		});
	});

	test.describe("getShortUrl & getLinkHost", () => {
		test("prefers canonical shortUrl when present", () => {
			expect(getShortUrl({ shortUrl: "https://peakurl.dev/abc" })).toBe(
				"https://peakurl.dev/abc"
			);
		});

		test("constructs shortUrl from alias or shortCode", () => {
			const linkWithAlias = { alias: "my-custom-link" };
			expect(getShortUrl(linkWithAlias)).toContain("my-custom-link");

			const linkWithCode = { shortCode: "xyz123" };
			expect(getShortUrl(linkWithCode)).toContain("xyz123");
		});

		test("resolves custom domain host from domain record", () => {
			expect(getLinkHost({ domain: "custom.link.com" })).toBe(
				"custom.link.com"
			);
			expect(getLinkHost({ domain: { domain: "short.io" } })).toBe(
				"short.io"
			);
			expect(getLinkHost({ domain: null })).toBe("");
		});
	});
});
