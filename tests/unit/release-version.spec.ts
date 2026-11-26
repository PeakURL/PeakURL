import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

test("release readme and canonical PeakURL version agree on 1.9.0", () => {
	const version = readFileSync(
		new URL("../../.version", import.meta.url),
		"utf8"
	).trim();
	const readme = readFileSync(
		new URL("../../readme.html", import.meta.url),
		"utf8"
	);
	expect(version).toBe("1.9.0");
	expect(readme.match(/class="version-badge">v([^<]+)</)?.[1]).toBe(version);
});
