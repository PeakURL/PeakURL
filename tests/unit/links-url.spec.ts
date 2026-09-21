import { test, expect } from "@playwright/test";
import {
	decodeHtmlEntities,
	getLinkDisplayTitle,
	getLinkHost,
	getShortUrl,
	isRelativeUrl,
	normalizeLinkTitle,
	getLinkExpirationState,
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

	test.describe("getLinkExpirationState", () => {
		const referenceTime = new Date("2026-09-21T12:00:00Z");

		test("1. a link expiring in about one minute is not displayed as 'Expires in 1 hour'", () => {
			const linkExpiringInOneMinute = {
				status: "active",
				expiresAt: "2026-09-21T12:01:00Z",
			};
			const result = getLinkExpirationState(
				linkExpiringInOneMinute,
				referenceTime
			);

			expect(result.isExpired).toBe(false);
			expect(result.relativeTime).toBe("in 1 minute");
			expect(result.relativeTime).not.toContain("hour");
		});

		test("2. a link expiring in several minutes displays the appropriate remaining time", () => {
			const linkIn2Min = {
				status: "active",
				expiresAt: "2026-09-21T12:02:00Z",
			};
			const linkIn17Min = {
				status: "active",
				expiresAt: "2026-09-21T12:17:00Z",
			};
			const linkIn55Sec = {
				status: "active",
				expiresAt: "2026-09-21T12:00:55Z",
			};
			const linkIn59Min = {
				status: "active",
				expiresAt: "2026-09-21T12:59:00Z",
			};

			expect(
				getLinkExpirationState(linkIn55Sec, referenceTime).relativeTime
			).toBe("in 55 seconds");
			expect(
				getLinkExpirationState(linkIn2Min, referenceTime).relativeTime
			).toBe("in 2 minutes");
			expect(
				getLinkExpirationState(linkIn17Min, referenceTime).relativeTime
			).toBe("in 17 minutes");
			expect(
				getLinkExpirationState(linkIn59Min, referenceTime).relativeTime
			).toBe("in 59 minutes");
		});

		test("3. a link expiring in about one hour displays the appropriate hour value", () => {
			const linkInOneHour = {
				status: "active",
				expiresAt: "2026-09-21T13:00:00Z",
			};
			const result = getLinkExpirationState(linkInOneHour, referenceTime);

			expect(result.isExpired).toBe(false);
			expect(result.relativeTime).toBe("in 1 hour");
		});

		test("4. an already-expired link is displayed as expired", () => {
			// Case A: Link explicitly marked expired by backend
			const backendExpiredLink = {
				status: "expired",
				expiresAt: "2026-09-21T11:50:00Z",
			};
			const resA = getLinkExpirationState(
				backendExpiredLink,
				referenceTime
			);
			expect(resA.isExpired).toBe(true);
			expect(resA.relativeTime).toBe("");

			// Case B: Link past its expiration timestamp while status column is still active (prior to cron transition)
			const timestampPastLink = {
				status: "active",
				expiresAt: "2026-09-21T11:59:00Z",
			};
			const resB = getLinkExpirationState(
				timestampPastLink,
				referenceTime
			);
			expect(resB.isExpired).toBe(true);
			expect(resB.relativeTime).toBe("");
		});

		test("5. initial page data and refreshed page data use the same correct expiration logic", () => {
			const linkRecord = {
				id: "link-xyz",
				status: "active",
				expiresAt: "2026-09-21T12:17:00Z",
			};

			// Initial loading evaluation
			const initialEvaluation = getLinkExpirationState(
				linkRecord,
				referenceTime
			);

			// Refreshed payload evaluation (same authoritative backend data)
			const refreshedPayload = { ...linkRecord };
			const refreshedEvaluation = getLinkExpirationState(
				refreshedPayload,
				referenceTime
			);

			expect(initialEvaluation).toEqual(refreshedEvaluation);
			expect(refreshedEvaluation.relativeTime).toBe("in 17 minutes");
			expect(refreshedEvaluation.isExpired).toBe(false);
		});

		test("6. protected/time-limited links retain their existing behavior", () => {
			const protectedTimeLimitedLink = {
				id: "protected-link",
				status: "active",
				hasPassword: true,
				expiresAt: "2026-09-21T12:17:00Z",
			};

			const result = getLinkExpirationState(
				protectedTimeLimitedLink,
				referenceTime
			);

			// Expiration state correctly resolved
			expect(result.isExpired).toBe(false);
			expect(result.relativeTime).toBe("in 17 minutes");
			// Protection state preserved in link record
			expect(protectedTimeLimitedLink.hasPassword).toBe(true);
		});

		test("7. relative expiration text and state remain identical regardless of viewer timezone", () => {
			const link = {
				status: "active",
				expiresAt: "2026-09-21T12:17:00Z",
			};
			const originalGetTimezoneOffset = Date.prototype.getTimezoneOffset;
			try {
				Date.prototype.getTimezoneOffset = () => -540;
				const tokyoState = getLinkExpirationState(link, referenceTime);

				Date.prototype.getTimezoneOffset = () => 240;
				const nyState = getLinkExpirationState(link, referenceTime);

				Date.prototype.getTimezoneOffset = () => 0;
				const utcState = getLinkExpirationState(link, referenceTime);

				expect(tokyoState).toEqual(utcState);
				expect(nyState).toEqual(utcState);
				expect(tokyoState.relativeTime).toBe("in 17 minutes");
				expect(tokyoState.isExpired).toBe(false);
			} finally {
				Date.prototype.getTimezoneOffset = originalGetTimezoneOffset;
			}
		});
	});
});
