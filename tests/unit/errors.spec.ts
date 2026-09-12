import { test, expect } from "@playwright/test";
import {
	extractErrorMessage,
	getErrorMessage,
	getErrorStatus,
	getStringRecordValue,
	isObjectRecord,
} from "../../ui/shared/errors";

test.describe("Error & Record Utilities", () => {
	test.describe("isObjectRecord & getStringRecordValue", () => {
		test("identifies non-null objects correctly", () => {
			expect(isObjectRecord({ key: "val" })).toBe(true);
			expect(isObjectRecord(null)).toBe(false);
			expect(isObjectRecord(undefined)).toBe(false);
			expect(isObjectRecord("string")).toBe(false);
			expect(isObjectRecord(123)).toBe(false);
		});

		test("reads non-empty string properties safely", () => {
			expect(getStringRecordValue({ key: "hello" }, "key")).toBe("hello");
			expect(getStringRecordValue({ key: "" }, "key")).toBe(null);
			expect(getStringRecordValue({ key: 123 }, "key")).toBe(null);
			expect(getStringRecordValue({}, "missing")).toBe(null);
		});
	});

	test.describe("extractErrorMessage & getErrorMessage", () => {
		test("extracts message from structured API response", () => {
			const error = {
				status: 400,
				data: { message: "Invalid URL format" },
			};
			expect(extractErrorMessage(error)).toBe("Invalid URL format");
		});

		test("extracts error string from simple error objects", () => {
			const error = { error: "Network timeout" };
			expect(extractErrorMessage(error)).toBe("Network timeout");
		});

		test("extracts message from standard Error instances", () => {
			const error = new Error("Runtime exception");
			expect(extractErrorMessage(error)).toBe("Runtime exception");
		});

		test("uses fallback string when no message is found", () => {
			expect(getErrorMessage(null, "An unexpected error occurred")).toBe(
				"An unexpected error occurred"
			);
			expect(getErrorMessage({}, "Default fallback")).toBe(
				"Default fallback"
			);
		});
	});

	test.describe("getErrorStatus", () => {
		test("extracts numeric status code from FetchBaseQueryError", () => {
			expect(getErrorStatus({ status: 401, data: {} })).toBe(401);
			expect(getErrorStatus({ status: 500, data: {} })).toBe(500);
		});

		test("returns null for non-numeric statuses or other errors", () => {
			expect(getErrorStatus({ status: "FETCH_ERROR" })).toBe(null);
			expect(getErrorStatus(new Error("Network failed"))).toBe(null);
		});
	});
});
